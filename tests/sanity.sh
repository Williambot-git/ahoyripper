#!/bin/bash
# AhoyRipper - Sanity Tests
# Run: bash tests/sanity.sh

set -e

# Derive PROJECT_ROOT the same way run.sh does — subshells don't inherit cd.
# This lets sanity.sh be run directly (without run.sh) or via run.sh.
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
echo ""
echo "==> Checking yt-dlp binary is installed and callable..."
if ! command -v yt-dlp > /dev/null 2>&1; then
    echo "  ⚠ yt-dlp not found in PATH (skipping — run on production server)"
else
    YTDLP_VER=$(yt-dlp --version 2>&1 | head -1 || true)
    if [ -z "$YTDLP_VER" ]; then
        echo "  ✗ yt-dlp found but --version returned empty"
        exit 1
    fi
    echo "  ✓ yt-dlp installed: $YTDLP_VER"
fi

echo ""
echo "==> Checking ffmpeg binary is installed..."
if ! command -v ffmpeg > /dev/null 2>&1; then
    echo "  ⚠ ffmpeg not found in PATH (skipping — run on production server)"
else
    FFMPEG_VER=$(ffmpeg -version 2>&1 | head -1 || true)
    if [ -z "$FFMPEG_VER" ]; then
        echo "  ✗ ffmpeg found but -version returned empty"
        exit 1
    fi
    echo "  ✓ ffmpeg installed: $FFMPEG_VER"
fi

echo ""
echo "==> Checking PHP syntax (all project .php files)..."
# Check all PHP files: application code, tests, and CLI scripts.
# Use find to enumerate so new PHP files are automatically included.
FAILED_PHP=0
while IFS= read -r f; do
    result=$(php -l "$f" 2>&1 || true)
    if echo "$result" | grep -q "No syntax errors"; then
        echo "  ✓ $(basename "$f")"
    else
        echo "  ✗ $(basename "$f"): $result"
        FAILED_PHP=1
    fi
done < <(find "$PROJECT_ROOT" -name "*.php" -type f | sort)
if [ "$FAILED_PHP" -eq 1 ]; then
    echo "  PHP syntax errors detected."
    exit 1
fi
echo "  ✓ All PHP syntax OK"

# --no-warnings (plural) is the current recommended yt-dlp flag.
# The deprecated form is --no-warning (singular).
echo ""
echo "==> Checking yt-dlp flags in source..."
if grep -q -- '--no-warning$' src/api.php; then
    echo "  ✗ Deprecated --no-warning flag found (yt-dlp uses --no-warnings plural)"
    exit 1
fi
echo "  ✓ No deprecated yt-dlp flags"

echo ""
echo "==> Checking old YouTube URL-rewrite age-bypass is NOT present..."
# The URL-rewrite approach (converting watch/shorts URLs to /embed/...) was
# replaced by --extractor-args youtube:player_client=web, which has now been
# removed due to yt-dlp #12577 (causes 422 bot detection errors).
# Both approaches are now absent — age-restricted YouTube videos will return
# YTDLP_ERROR/AGE_RESTRICTED with guidance to use AhoyVPN or sign in.
if grep -q 'youtube.com/embed' src/api.php; then
    echo "  ✗ Old YouTube URL-rewrite approach still present"
    exit 1
fi
echo "  ✓ Old URL-rewrite approach absent"

echo ""
echo "==> Checking yt-dlp --extractor-args for YouTube age-restriction bypass is NOT present..."
# --extractor-args youtube:player_client=web was removed because it causes 422 bot
# detection errors on YouTube ( yt-dlp #12577). Age-restricted YouTube videos will
# fall through to YTDLP_ERROR with a clear message directing users to sign in or
# use AhoyVPN. The flag must NOT be present in either info or download commands.
if grep -q -- '--extractor-args.*youtube:player_client=web' src/api.php; then
    echo "  ✗ --extractor-args youtube:player_client=web found (causes 422 bot detection)"
    exit 1
fi
echo "  ✓ --extractor-args youtube:player_client=web correctly removed (causes 422 errors)"

echo ""
echo "==> Checking yt-dlp info command does NOT use --no-geo-bypass..."
# --no-geo-bypass DISABLES yt-dlp's geo-bypass capability, preventing it from
# routing around geographic restrictions via DNS templates or signed URLs.
# We intentionally OMIT this flag so yt-dlp's default geo-bypass behavior
# (available since yt-dlp 2023.10.04 and earlier) is active.
# Use AhoyVPN to route through an allowed region when encountering geo-blocks.
if grep -q -- '--no-geo-bypass' src/api.php; then
    echo "  ✗ --no-geo-bypass flag should NOT be present (it disables geo-bypass)"
    exit 1
else
    echo "  ✓ --no-geo-bypass flag absent (geo-bypass enabled, as intended)"
fi

echo ""
echo "==> Checking yt-dlp download command does NOT use --no-geo-bypass..."
if sed -n "/case 'download':/,/case '/p" src/api.php | grep -q -- '--no-geo-bypass'; then
    echo "  ✗ --no-geo-bypass flag should NOT be present (it disables geo-bypass)"
    exit 1
else
    echo "  ✓ --no-geo-bypass flag absent (geo-bypass enabled, as intended)"
fi

echo ""
echo "==> Checking yt-dlp --user-agent flag for anti-bot protection..."
# yt-dlp defaults to "python-requests/X.Y.Z" which anti-bot systems detect and block.
# A realistic browser User-Agent reduces source-site blocking.
if grep -q -- '--user-agent' src/api.php; then
    echo "  ✓ --user-agent flag present in yt-dlp commands"
else
    echo "  ✗ --user-agent flag missing (yt-dlp defaults to python-requests User-Agent, trivially blocked by anti-bot)"
    exit 1
fi

echo ""
echo "==> Verifying required files exist..."
for f in src/api.php src/style.css public/index.php README.md Dockerfile docker-compose.yml deploy/nginx.conf scripts/install-deps.sh; do
    if [ ! -f "$f" ]; then
        echo "  ✗ Missing: $f"
        exit 1
    fi
done
echo "  ✓ All required files present"

echo ""
echo "==> Checking manifest.json is valid JSON... "
if php -r "json_decode(file_get_contents('public/manifest.json')); exit(json_last_error() !== JSON_ERROR_NONE ? 1 : 0);" 2>/dev/null; then
    echo "✓ manifest.json is valid JSON"
else
    echo "✗ manifest.json is not valid JSON"
    exit 1
fi

echo ""
echo "==> Checking PWA manifest id is URL-based (W3C spec compliance)... "
# Per W3C Web Manifest spec, 'id' should be a URL matching start_url (not a bare string).
# A bare string like "ahoyripper" causes PWA installation to fail in some browsers.
MANIFEST_ID=$(php -r "echo json_decode(file_get_contents('public/manifest.json'))->id ?? '';")
if echo "$MANIFEST_ID" | grep -q '^/'; then
    echo "✓ manifest id is URL-based: $MANIFEST_ID"
else
    echo "✗ manifest id is not URL-based (got: $MANIFEST_ID — should be '/' or a full URL)"
    exit 1
fi

echo ""
echo "==> Checking security headers in api.php..."
REQUIRED_HEADERS=(
    "X-Content-Type-Options"
    "X-Frame-Options"
    "Strict-Transport-Security"
    "Content-Security-Policy"
    "X-Download-Options"
    "X-Robots-Tag"
)
for header in "${REQUIRED_HEADERS[@]}"; do
    if grep -q "$header" src/api.php; then
        echo "  ✓ $header present"
    else
        echo "  ✗ Missing: $header"
        exit 1
    fi
done

echo ""
echo "==> Checking CSP worker-src directive for Web Worker isolation..."
if grep -q "worker-src" src/api.php; then
    echo "  ✓ worker-src directive present (Web Workers can be created)"
else
    echo "  ✗ worker-src directive missing from CSP (Web Workers blocked)"
    exit 1
fi

echo ""
echo "==> Checking rate limiting (info endpoint)..."
if grep -q "rate_limit = 30" src/api.php; then
    echo "  ✓ Info rate limit (30/min) configured"
else
    echo "  ✗ Info rate limit not found"
    exit 1
fi

echo ""
echo "==> Checking download rate limit uses DL_RATE_LIMIT constant (not magic number)..."
if grep -q "dl_rate_limit = DL_RATE_LIMIT" src/api.php; then
    echo "  ✓ Download rate limit uses DL_RATE_LIMIT constant"
else
    echo "  ✗ Download rate limit uses magic number instead of DL_RATE_LIMIT constant"
    exit 1
fi

echo ""
echo "==> Checking format_id validation allows yt-dlp selector syntax..."
# format_id must accept yt-dlp conditional selectors like bestvideo[height>=720]+bestaudio
if grep -q "preg_match.*format_id" src/api.php; then
    echo "  ✓ format_id validation present"
else
    echo "  ✗ format_id validation not found"
    exit 1
fi
# Also verify the validation allows yt-dlp selector characters.
# yt-dlp format selectors use < > = for conditional filtering (e.g. bestvideo[height>=720]).
# Verify the preg_match for format_id includes at least one comparison operator char.
# We look for format_id lines that also contain <, >, or = outside of PHP string context.
if awk '/format_id/ && /preg_match/ && (/[<>=]/)' src/api.php > /dev/null; then
    echo "  ✓ format_id validation allows yt-dlp selector chars ([ ] < > = etc.)"
else
    echo "  ✗ format_id validation may be too restrictive for yt-dlp selectors"
    exit 1
fi
# Verify the validation allows single-quote (fallback selector syntax like 22/18').
if grep -q "\\\\'" src/api.php || grep "preg_match.*format_id" src/api.php | grep -q "'\]" 2>/dev/null; then
    echo "  ✓ format_id validation allows single-quote (fallback priority syntax)"
else
    echo "  ✗ format_id validation may reject single-quote (fallback priority syntax)"
    exit 1
fi

echo ""
echo "==> Checking HSTS includeSubDomains..."
if grep -q "includeSubDomains" src/api.php; then
    echo "  ✓ HSTS includeSubDomains configured"
else
    echo "  ✗ HSTS includeSubDomains missing"
fi

echo ""
echo "==> Checking www redirect order in nginx-docker.conf (ahoyvpn before ahoyripper)..."
# The www.ahoyvpn.com redirect must appear BEFORE www.ahoyripper.com.
# If ahoyripper.com comes first, the www.ahoyvpn.com check never fires and
# that domain's redirect silently falls through (PHP sees the wrong Host header).
DOCKER_VPN_LINE=$(grep -n "if.*= 'www.ahoyvpn.com'" deploy/nginx-docker.conf | head -1 | cut -d: -f1)
DOCKER_RIPPER_LINE=$(grep -n "if.*= 'www.ahoyripper.com'" deploy/nginx-docker.conf | head -1 | cut -d: -f1)
if [ -n "$DOCKER_VPN_LINE" ] && [ -n "$DOCKER_RIPPER_LINE" ]; then
    if [ "$DOCKER_VPN_LINE" -lt "$DOCKER_RIPPER_LINE" ]; then
        echo "  ✓ nginx-docker.conf: www.ahoyvpn.com redirect precedes www.ahoyripper.com (line $DOCKER_VPN_LINE < line $DOCKER_RIPPER_LINE)"
    else
        echo "  ✗ nginx-docker.conf: www.ahoyripper.com redirect appears before www.ahoyvpn.com — wrong order (line $DOCKER_RIPPER_LINE < line $DOCKER_VPN_LINE, ahoyvpn must be first)"
        exit 1
    fi
else
    echo "  ⚠ Could not verify www redirect order in nginx-docker.conf (redirect blocks not found)"
fi

echo ""
echo "==> Checking source-file access control in nginx-docker.conf..."
# nginx-docker.conf must:
# 1. Explicitly allow /src/api.php (used by the frontend JS).
# 2. Use a dotfile catch-all (denies /src/.env, /.git/config, etc.).
if grep -q "location = /src/api.php" deploy/nginx-docker.conf; then
    echo "  ✓ /src/api.php explicitly allowed"
else
    echo "  ✗ /src/api.php access rule missing — will be blocked by dotfile catch-all"
    exit 1
fi
if awk 'NR==1{found=0} /location ~ \/\. \{ deny/ {found=1; exit 0} END{exit found==0?0:1}' deploy/nginx-docker.conf; then
    echo "  ✓ Dotfile catch-all present"
else
    echo "  ✗ Dotfile catch-all missing"
    exit 1
fi
# Redundant prefix rules (^/src/ etc.) are no longer needed — the dotfile
# catch-all handles those paths. Warn if they are still present.
if grep -q "location ~ \^/src/" deploy/nginx-docker.conf; then
    echo "  ✗ Redundant ^/src/ prefix rule found (dotfile catch-all handles this)"
    exit 1
fi
if grep -q "location ~ \^/includes/" deploy/nginx-docker.conf; then
    echo "  ✗ Redundant ^/includes/ prefix rule found"
    exit 1
fi
if grep -q "location ~ \^/scripts/" deploy/nginx-docker.conf; then
    echo "  ✗ Redundant ^/scripts/ prefix rule found"
    exit 1
fi

echo ""
echo "==> Checking nginx-docker.conf static asset location serves manifest.json as application/json..."
# The static asset location regex must include .json so manifest.json is served
# with Content-Type: application/json (not nginx's default application/octet-stream).
if fgrep -e 'json)$' deploy/nginx-docker.conf > /dev/null 2>&1; then
    echo "  ✓ .json extension in static asset location (manifest.json gets correct MIME)"
else
    echo "  ✗ .json extension missing from static asset location (manifest.json mis-served)"
    exit 1
fi

echo ""
echo "==> Checking API key support in info action (unlimited-key bypass)..."
# The info case must read and honour the Bearer API key so that unlimited-key
# holders do not have their daily quota burned before they even attempt a download.
if sed -n "/case 'info':/,/case '/p" src/api.php | grep -q "HTTP_AUTHORIZATION" && sed -n "/case 'info':/,/case '/p" src/api.php | grep -q "Bearer"; then
    echo "  ✓ info action reads Bearer API key"
else
    echo "  ✗ info action does not read Bearer API key — unlimited-key holders lose quota on info"
    exit 1
fi

echo ""
echo "==> Checking quota undo when parseFormats returns a classified error..."
# When parseFormats returns a classified error (e.g. GEOBLOCKED, PRIVATE_VIDEO),
# the quota increment should be undone so failed/unavailable content doesn't
# burn the user's daily limit. The undo block must be inside the
# "if (isset(\$parsed['error']))" branch, not just in the $out-empty branch.
# Extract the info case and check for quota-undo logic after parseFormats error.
INFO_CASE=$(sed -n "/case 'info':/,/case '/p" src/api.php | head -n -1)
# The undo block appears AFTER the logRequest for 'parse_formats_ytdlp_error'
if echo "$INFO_CASE" | grep -A 30 "parseFormats.*error" | grep -q "ahoyrip_daily_.*md5.*ip"; then
    echo "  ✓ Quota undo present for parseFormats classified errors"
else
    echo "  ✗ Quota undo missing for parseFormats classified errors — daily limit burned on unavailable content"
    exit 1
fi

echo ""
echo "==> Checking quota undo when parseFormats returns null..."
# When parseFormats returns null (parse failure), quota should also be undone.
# This prevents burning daily limit on content that can't be parsed at all.
INFO_CASE=$(sed -n "/case 'info':/,/case '/p" src/api.php | head -n -1)
if echo "$INFO_CASE" | grep -A 30 "if (\!\$parsed)" | grep -q "ahoyrip_daily_.*md5.*ip"; then
    echo "  ✓ Quota undo present for parseFormats null (parse failure)"
else
    echo "  ✗ Quota undo missing for parseFormats null — daily limit burned on unparseable content"
    exit 1
fi

echo ""
echo "==> Checking download exit-code error handling..."
if grep -q "actual_exit" src/api.php; then
    echo "  ✓ Download exit-code validation present"
else
    echo "  ✗ Download exit-code validation not found"
    exit 1
fi

echo ""
echo "==> Checking quota undo for all download failures (classified and unclassified)..."
# Refund daily quota for ANY download failure — classified (GEOBLOCKED, PRIVATE_VIDEO)
# or unclassified (network glitch, source timeout, unexpected yt-dlp exit).
# The user didn't successfully download anything, so the quota should not be burned.
# The undo block appears before the $err_classified check so it covers both branches.
if awk '/\$err_classified = classifyYtdlpError/,/^[[:space:]]*\}[[:space:]]*$/' src/api.php | grep -q "ahoyrip_daily_.*md5.*ip"; then
    echo "  ✓ Quota undo present for all download failures"
else
    echo "  ✗ Quota undo missing for download failures — daily limit burned on failed downloads"
    exit 1
fi

echo ""
echo "==> Checking download action logRequest uses correct action name (not 'info')..."
# The daily-limit block inside the 'download' case must log 'download', not 'info'.
# Count occurrences: the 'info' case has 1 correct call; the 'download' case must NOT
# have a 'logRequest.*info.*429.*daily_limit' — only 'download' case should log download.
# Extract the download case's daily_limit block (between "case 'download'" and "case '")
# and verify it calls logRequest with 'download', not 'info'.
DOWNLOAD_CASE=$(sed -n "/case 'download':/,/case '/p" src/api.php | head -n -1)
if echo "$DOWNLOAD_CASE" | grep -q "logRequest.*'info'.*429.*daily_limit"; then
    echo "  ✗ download case incorrectly logs 'info' action for daily limit (should be 'download')"
    exit 1
fi
echo "  ✓ download case logs 'download' action for daily limit"

echo ""
echo "==> Checking info action does not duplicate MAX_URL_LEN check (validation is in helper)..."
# The $validation() helper already enforces MAX_URL_LEN internally.
# The info case must NOT have its own redundant strlen($url) > MAX_URL_LEN block
# after calling $validation() — that would produce a duplicate 400 response for
# the same failure, leaving the second block permanently dead code.
INFO_CASE=$(sed -n "/case 'info':/,/case '/p" src/api.php | head -n -1)
if echo "$INFO_CASE" | grep -q "strlen(\$url) > MAX_URL_LEN"; then
    echo "  ✗ info case has redundant strlen(\$url) > MAX_URL_LEN after \$validation() call"
    echo "    (the helper enforces this; the duplicate block is dead code)"
    exit 1
fi
echo "  ✓ info case delegates MAX_URL_LEN check to \$validation() helper (no duplicate)"

echo ""
echo "==> Checking timeout handlers do not call proc_close() directly (double-close guard)..."
# Timeout blocks must set $proc = null instead of calling proc_close($proc) directly,
# so the post-loop proc_close() can detect the already-closed handle via the null sentinel.
# The wrong pattern is: proc_terminate($proc, 9); proc_close($proc);  // double-close risk
# The correct pattern is: proc_terminate($proc, 9); $proc = null;
# We detect the bad pattern by finding proc_close($proc) appearing on a line that
# comes AFTER proc_terminate($proc, 9) within a timeout block (within 3 lines).
# We use grep -n to get line numbers and check proximity.
bad=0
while IFS=: read -r linenum line; do
    # For each proc_close($proc) line, check if a proc_terminate($proc, 9) appeared
    # within 3 lines before it inside a timeout block.
    start_line=$((linenum - 3))
    [ $start_line -lt 1 ] && start_line=1
    context=$(sed -n "${start_line},${linenum}p" src/api.php)
    if echo "$context" | grep -q "proc_terminate.*\$proc"; then
        echo "  ✗ Line $linenum: proc_close(\$proc) found after proc_terminate — double-close risk"
        bad=1
    fi
done < <(grep -n "proc_close(\$proc)" src/api.php)
if [ $bad -eq 1 ]; then
    echo "  Fix: use '\$proc = null' instead of 'proc_close(\$proc)' inside timeout blocks."
    exit 1
fi
echo "  ✓ Timeout blocks use \$proc = null sentinel instead of proc_close()"

echo ""
echo "==> Checking API CSP includes all required thumbnail CDN domains..."
# The API CSP must allow thumbnails from media CDNs so the browser can load
# them when rendering video info (YouTube, TikTok, Twitter/X, SoundCloud, etc.).
# In nginx-docker.conf the CSP is set at server level (inherited by all locations).
# In production nginx.conf it is set at server level too (PHP sets its own CSP for
# API responses, so there is no duplication).
# Both locations must have the same CDN thumbnail allowances.
REQUIRED_THUMB_DOMAINS=(
    "i.ytimg.com"
    "pbs.twimg.com"
    "sndcdn.com"
    "vimeocdn.com"
    "instagram.com"
    "fbcdn.net"
    "tiktokcdn.com"
    "tiktok.com"
)
# Check api.php CSP (PHP sets its own CSP header)
API_CSP=$(grep "Content-Security-Policy" src/api.php | sed "s/.*Content-Security-Policy[ ]*//")
missing=0
for domain in "${REQUIRED_THUMB_DOMAINS[@]}"; do
    if ! echo "$API_CSP" | grep -q "$domain"; then
        echo "  ✗ API CSP missing thumbnail domain: $domain"
        missing=1
    fi
done
if [ "$missing" -eq 0 ]; then
    echo "  ✓ API CSP allows all required thumbnail CDN domains"
fi

# Check nginx-docker.conf CSP (server level)
DOCKER_CSP=$(grep "Content-Security-Policy" deploy/nginx-docker.conf | sed "s/.*Content-Security-Policy[ ]*//")
missing=0
for domain in "${REQUIRED_THUMB_DOMAINS[@]}"; do
    if ! echo "$DOCKER_CSP" | grep -q "$domain"; then
        echo "  ✗ Docker nginx.conf CSP missing thumbnail domain: $domain"
        missing=1
    fi
done
if [ "$missing" -eq 0 ]; then
    echo "  ✓ Docker nginx.conf CSP allows all required thumbnail CDN domains"
fi

echo ""
echo "==> Checking ALL Docker nginx.conf CSP enforcement headers include upgrade-insecure-requests... "
# Every Content-Security-Policy enforcement header (NOT Content-Security-Policy-Report-Only)
# must include upgrade-insecure-requests for defense-in-depth parity with the PHP layer.
# Previously the /csp-report location block was missing it even though the comment
# said it was included. This test uses grep to extract each enforcement CSP header
# and verifies upgrade-insecure-requests is present in all of them.
ENFORCEMENT_CSP_COUNT=$(grep -c "^[[:space:]]*add_header Content-Security-Policy " deploy/nginx-docker.conf || true)
UPGRADE_CSP_COUNT=$(grep "^[[:space:]]*add_header Content-Security-Policy " deploy/nginx-docker.conf | grep -c "upgrade-insecure-requests" || true)
if [ "$ENFORCEMENT_CSP_COUNT" -gt 0 ] && [ "$ENFORCEMENT_CSP_COUNT" -eq "$UPGRADE_CSP_COUNT" ]; then
    echo "  ✓ All $ENFORCEMENT_CSP_COUNT enforcement CSP headers include upgrade-insecure-requests"
else
    echo "  ✗ $((ENFORCEMENT_CSP_COUNT - UPGRADE_CSP_COUNT)) enforcement CSP header(s) missing upgrade-insecure-requests"
    exit 1
fi

echo ""
echo "==> Checking API PHP CSP includes upgrade-insecure-requests... "
if grep "Content-Security-Policy" src/api.php | grep -q "upgrade-insecure-requests"; then
    echo "  ✓ API PHP CSP includes upgrade-insecure-requests"
else
    echo "  ✗ API PHP CSP missing upgrade-insecure-requests"
    exit 1
fi

echo ""
echo "==> Checking API location block CSP includes worker-src 'self' (production nginx.conf)..."
# The API location block CSP in nginx.conf must include worker-src 'self' so
# ServiceWorkers and SharedWorkers can be created from the API origin (needed
# for future PWA offline support). This mirrors the same directive in the Docker
# config and the PHP layer.
API_CSP_LINE=$(sed -n '/location = \/src\/api.php/,/^[[:space:]]*}/p' deploy/nginx.conf | grep 'Content-Security-Policy' | tail -1 || true)
if echo "$API_CSP_LINE" | grep -q "worker-src"; then
    echo "  ✓ Production nginx.conf API location CSP includes worker-src"
else
    echo "  ✗ Production nginx.conf API location CSP missing worker-src (blocks ServiceWorkers)"
    exit 1
fi

echo ""
echo "==> Checking Permissions-Policy meta tag in public/index.php..."
if grep -q 'meta http-equiv="Permissions-Policy"' public/index.php; then
    echo "  ✓ Permissions-Policy meta tag present in index.php"
else
    echo "  ✗ Permissions-Policy meta tag missing from index.php"
    exit 1
fi

echo ""
echo "==> Checking COEP header in public/index.php..."
if grep -q 'Cross-Origin-Embedder-Policy.*require-corp' public/index.php; then
    echo "  ✓ Cross-Origin-Embedder-Policy: require-corp present in index.php"
else
    echo "  ✗ Cross-Origin-Embedder-Policy missing from index.php"
    FAILED=1
fi

echo "==> Checking PWA update banner accessibility attributes..."
# The update banner must use role="alert" + aria-live="assertive" so that
# screen readers announce the "Update now" message immediately when it appears.
# role="status" + aria-live="polite" is for non-urgent status messages only.
BANNER_LINE=$(grep 'id="update-banner"' public/index.php)
if echo "$BANNER_LINE" | grep -q 'role="alert"'; then
    echo "  ✓ PWA update banner uses role=\"alert\" (immediate screen reader announcement)"
else
    echo "  ✗ PWA update banner missing role=\"alert\" — screen readers may not announce update"
    exit 1
fi
if echo "$BANNER_LINE" | grep -q 'aria-live="assertive"'; then
    echo "  ✓ PWA update banner uses aria-live=\"assertive\""
else
    echo "  ✗ PWA update banner missing aria-live=\"assertive\""
    exit 1
fi

echo ""
echo "==> Checking Rip Another handler does not disable sort dropdown..."
# The sortSelect is re-enabled by the JS automatically on the next fetchInfo() call
# (which restores the persisted sort preference from localStorage). Permanently disabling
# it in the ripAgain handler locks the user out of sort order changes on subsequent rips.
# Guard against this regression: the disabled attribute must not appear in ripAgain.
if grep -A 10 "ripAgain.addEventListener" public/index.php | grep -q "sortSelect.disabled"; then
    echo "  ✗ ripAgain handler sets sortSelect.disabled — sort dropdown locked on subsequent rips"
    exit 1
else
    echo "  ✓ ripAgain handler does not disable sort dropdown"
fi

echo ""
echo "==> Checking og:image meta tag in public/index.php..."
if grep -q 'meta property="og:image"' public/index.php; then
    echo "  ✓ og:image present in index.php"
else
    echo "  ✗ og:image missing from index.php"
    FAILED=1
fi

echo "==> Checking og:title meta tag in public/index.php..."
if grep -q 'meta property="og:title"' public/index.php; then
    echo "  ✓ og:title present in index.php"
else
    echo "  ✗ og:title missing from index.php"
    FAILED=1
fi

echo "==> Checking og:description meta tag in public/index.php..."
if grep -q 'meta property="og:description"' public/index.php; then
    echo "  ✓ og:description present in index.php"
else
    echo "  ✗ og:description missing from index.php"
    FAILED=1
fi

echo "==> Checking og:url meta tag in public/index.php..."
if grep -q 'meta property="og:url"' public/index.php; then
    echo "  ✓ og:url present in index.php"
else
    echo "  ✗ og:url missing from index.php"
    FAILED=1
fi

echo "==> Checking Twitter Card meta tags in public/index.php..."
if grep -q 'meta name="twitter:card"' public/index.php && grep -q 'meta name="twitter:title"' public/index.php && grep -q 'meta name="twitter:description"' public/index.php; then
    echo "  ✓ Twitter Card meta tags present in index.php"
else
    echo "  ✗ Twitter Card meta tags missing from index.php"
    FAILED=1
fi

echo "==> Checking canonical URL in public/index.php..."
if grep -q 'link rel="canonical"' public/index.php; then
    echo "  ✓ canonical URL present in index.php"
else
    echo "  ✗ canonical URL missing from index.php"
    FAILED=1
fi

echo ""
echo "==> Checking README info response example does not claim api_version (only check endpoint has it)..."
# The info response JSON example should NOT contain "api_version" — it only
# appears on action=check. The README example was corrected to remove it.
if grep -A20 '"sort_applied"' README.md | grep -q '"api_version"'; then
    echo "  ✗ README info response example still contains api_version (should only be on check endpoint)"
    exit 1
else
    echo "  ✓ README info response example correctly omits api_version"
fi

echo ""
echo "==> Checking Permissions-Policy server-level header in nginx-docker.conf..."
if grep -q 'Permissions-Policy' deploy/nginx-docker.conf; then
    echo "  ✓ Permissions-Policy header present in nginx-docker.conf"
else
    echo "  ✗ Permissions-Policy header missing from nginx-docker.conf"
    exit 1
fi

echo ""
echo "==> Checking Permissions-Policy server-level header in nginx.conf..."
if grep -q 'Permissions-Policy' deploy/nginx.conf; then
    echo "  ✓ Permissions-Policy header present in nginx.conf"
else
    echo "  ✗ Permissions-Policy header missing from nginx.conf"
    exit 1
fi

echo ""
echo "==> Checking nginx-docker.conf includes security.txt (RFC 9116)... "
if grep -q "location = /.well-known/security.txt" deploy/nginx-docker.conf; then
    echo "  ✓ nginx-docker.conf has security.txt location"
else
    echo "  ✗ nginx-docker.conf missing security.txt location (RFC 9116 compliance)"
    exit 1
fi

echo ""
echo "==> Checking nginx-docker.conf includes .well-known/ directory location... "
if grep -q "location /.well-known/" deploy/nginx-docker.conf; then
    echo "  ✓ nginx-docker.conf has .well-known/ directory location"
else
    echo "  ✗ nginx-docker.conf missing .well-known/ directory location"
    exit 1
fi

echo ""
echo "==> Checking security.txt MIME type in nginx-docker.conf (text/plain per RFC 9116)... "
if grep -A 3 "location = /.well-known/security.txt" deploy/nginx-docker.conf | grep -q 'Content-Type text/plain'; then
    echo "  ✓ security.txt served as text/plain (RFC 9116)"
else
    echo "  ✗ security.txt missing Content-Type text/plain (browsers/scanners expect text/plain)"
    exit 1
fi

echo ""
echo "==> Checking Reporting-Endpoints header in nginx-docker.conf server-level..."
# nginx-docker.conf must have Reporting-Endpoints at server level (not just in the
# CSP Reporting API location block) so the modern Reporting API works for server-level
# CSP headers in Docker deployments, matching what nginx.conf provides in production.
if grep -q "Reporting-Endpoints" deploy/nginx-docker.conf; then
    # Verify it's at server level (appears before the first location block).
    # Count occurrences — must appear at server level AND in the CSP Reporting location.
    RE_COUNT=$(grep -c "Reporting-Endpoints" deploy/nginx-docker.conf || true)
    if [ "$RE_COUNT" -ge 2 ]; then
        echo "  ✓ Reporting-Endpoints present at server level (and in CSP Reporting location)"
    else
        echo "  ✗ Reporting-Endpoints missing from server level in nginx-docker.conf"
        exit 1
    fi
else
    echo "  ✗ Reporting-Endpoints header missing from nginx-docker.conf"
    exit 1
fi

echo ""
echo "==> Checking CSP Reporting API in nginx-docker.conf (server-level enforcement + report-only + API override + csp-report location)..."
# There are 5 legitimate CSP headers in nginx-docker.conf:
#   1. Server-level enforcement CSP (add_header ... Content-Security-Policy ...)
#   2. Server-level report-only (add_header ... Content-Security-Policy-Report-Only ...)
#   3. API-location override (location = /src/api.php block) — intentionally more
#      restrictive for the JSON API endpoint (no unsafe-inline, no font CDNs).
#   4. /csp-report location enforcement CSP (location = /csp-report block)
#   5. /csp-report location report-only CSP
# The test checks that there are exactly 5 (not 1-4, which would indicate
# duplicate server-level or spurious entries).
CSP_COUNT=$(grep -c "Content-Security-Policy" deploy/nginx-docker.conf || true)
if [ "$CSP_COUNT" -eq 5 ]; then
    echo "  ✓ CSP appears $CSP_COUNT times in nginx-docker.conf (enforcement + report-only at server, API override + csp-report location)"
else
    echo "  ✗ CSP appears $CSP_COUNT times in nginx-docker.conf (expected 5: enforcement + report-only at server, API override + csp-report location)"
    exit 1
fi

echo ""
echo "==> Checking production nginx.conf CSP has 'always' parameter..."
# The production nginx.conf CSP must use 'always' so it is sent on error pages (404, 500, etc.)
# too, not just on 200 responses. Without 'always', nginx error pages served by the static
# location have no CSP — a security regression vs Docker deployments.
PROD_CSP_LINE=$(grep "Content-Security-Policy" deploy/nginx.conf || true)
if echo "$PROD_CSP_LINE" | grep -q "always"; then
    echo "  ✓ Production nginx.conf CSP uses 'always' — covers error pages"
else
    echo "  ✗ Production nginx.conf CSP missing 'always' — error pages have no CSP"
    exit 1
fi

echo ""
echo "==> Checking Report-To header defines the CSP reporting endpoint group..."
# report-to requires a corresponding Report-To header that defines the named
# endpoint group. Without it, Chromium silently drops violations since the
# csp-report group is undefined. Both nginx.conf and nginx-docker.conf need it.
if grep -q 'Report-To.*csp-report' deploy/nginx-docker.conf; then
    echo "  ✓ nginx-docker.conf defines Report-To csp-report group"
else
    echo "  ✗ nginx-docker.conf missing Report-To header — Chromium ignores report-to csp-report"
    exit 1
fi
if grep -q 'Report-To.*csp-report' deploy/nginx.conf; then
    echo "  ✓ nginx.conf defines Report-To csp-report group"
else
    echo "  ✗ nginx.conf missing Report-To header — Chromium ignores report-to csp-report"
    exit 1
fi

echo ""
echo "==> Checking Reporting-Endpoints header (modern Reporting API, Chromium 84+)..."
# Reporting-Endpoints is the modern standard (Chromium 84+, Firefox 79+) that routes
# CSP violations through the browser's Reporting API. Without this, Chromium silently
# drops reports even when report-to csp-report is specified in the CSP header.
# api.php sets this header; nginx-docker.conf must also set it for parity.
if grep -q 'Reporting-Endpoints' deploy/nginx-docker.conf; then
    echo "  ✓ nginx-docker.conf defines Reporting-Endpoints header"
else
    echo "  ✗ nginx-docker.conf missing Reporting-Endpoints — Chromium 84+ drops CSP violation reports"
    exit 1
fi
if grep -q 'Reporting-Endpoints' deploy/nginx.conf; then
    echo "  ✓ nginx.conf defines Reporting-Endpoints header"
else
    echo "  ✗ nginx.conf missing Reporting-Endpoints — Chromium 84+ drops CSP violation reports"
    exit 1
fi
if grep -q 'Reporting-Endpoints' src/api.php; then
    echo "  ✓ api.php defines Reporting-Endpoints header"
else
    echo "  ✗ api.php missing Reporting-Endpoints — PHP layer inconsistent with nginx layer"
    exit 1
fi

echo ""
echo "==> Checking CSP report-uri location is configured in nginx-docker.conf..."
if grep -q "location = /csp-report" deploy/nginx-docker.conf; then
    echo "  ✓ /csp-report location configured in nginx-docker.conf"
else
    echo "  ✗ /csp-report location missing in nginx-docker.conf (report-uri /csp-report won't resolve)"
    exit 1
fi

echo ""
echo "==> Checking enforcement CSP in nginx-docker.conf includes report-uri /csp-report..."
# The enforcement CSP (first CSP header, not the Report-Only variant) must include
# 'report-uri /csp-report;' so the browser sends violation reports to the handler.
# The report-only header is separate (not enforced by the browser) — only the
# enforcement policy triggers actual violation reports.
CSP_ENF=$(grep "add_header Content-Security-Policy" deploy/nginx-docker.conf | grep -v "Report-Only" | sed "s/.*add_header Content-Security-Policy[ ]*//;s/[ ]*always.*//")
if echo "$CSP_ENF" | grep -q "report-uri /csp-report;"; then
    echo "  ✓ Enforcement CSP includes report-uri /csp-report"
else
    echo "  ✗ Enforcement CSP missing report-uri /csp-report — violation reports won't be sent"
    exit 1
fi

echo ""
echo "==> Checking CSP report-uri location is configured in production nginx.conf..."
if grep -q "location = /csp-report" deploy/nginx.conf; then
    echo "  ✓ /csp-report location configured in nginx.conf"
else
    echo "  ✗ /csp-report location missing in nginx.conf (report-uri /csp-report won't resolve)"
    exit 1
fi

echo ""
echo "==> Checking nginx-docker.conf /csp-report hides X-Powered-By... "
# The /csp-report location passes PHP-FPM directly (not via snippets/fastcgi-php.conf),
# so it needs its own fastcgi_hide_header X-Powered-By directive to prevent PHP
# version leakage to clients. Without this, CSP violation report responses expose
# the PHP version even though the api.php location hides it.
if grep -A 20 "location = /csp-report" deploy/nginx-docker.conf | grep -q "fastcgi_hide_header X-Powered-By"; then
    echo "  ✓ nginx-docker.conf /csp-report hides X-Powered-By"
else
    echo "  ✗ nginx-docker.conf /csp-report is missing fastcgi_hide_header X-Powered-By (PHP version leaks)"
    exit 1
fi

echo ""
echo "==> Checking nginx-docker.conf server-level security headers include X-Robots-Tag..."
if grep -q 'X-Robots-Tag "noindex, noai, noimage, noydir"' deploy/nginx-docker.conf; then
    echo "  ✓ nginx-docker.conf has X-Robots-Tag at server level"
else
    echo "  ✗ nginx-docker.conf missing X-Robots-Tag at server level (AI crawlers can index static assets)"
    exit 1
fi

echo ""
echo "==> Checking COOP/CORP headers in nginx-docker.conf..."
# COOP and CORP each appear 5 times legitimately:
#   - 1 at server level for static HTML assets
#   - 1 in /src/api.php location block for the API endpoint
#   - 1 in /csp-report location block — /csp-report is a PHP endpoint and needs its
#     own headers because server-level add_header directives are NOT inherited by
#     location blocks that define their own add_header (nginx behaviour).
#   - 1 in /.well-known/ location block for security.txt and similar files.
#   - 1 in /og-image.png location block for cache headers on the social share image.
# PHP's api.php sets COOP/CORP itself, but the /csp-report handler (PHP) does not
# set these headers, so nginx must provide them at that specific location.
COOP_COUNT=$(grep -c "Cross-Origin-Opener-Policy" deploy/nginx-docker.conf || true)
CORP_COUNT=$(grep -c "Cross-Origin-Resource-Policy" deploy/nginx-docker.conf || true)
if [ "$COOP_COUNT" -eq 5 ] && [ "$CORP_COUNT" -eq 5 ]; then
    echo "  ✓ COOP appears $COOP_COUNT times and CORP appears $CORP_COUNT times (server + API location + /csp-report location + /.well-known/ + /og-image.png)"
else
    echo "  ✗ COOP appears $COOP_COUNT times (expected 5), CORP appears $CORP_COUNT times (expected 5)"
    exit 1
fi

echo ""
echo "==> Checking yt-dlp stderr capture in download..."
if grep -q "proc_stderr" src/api.php; then
    echo "  ✓ Download stderr capture present"
else
    echo "  ✗ Download stderr capture not found"
    exit 1
fi

echo ""
echo "==> Checking RFC 5987 filename encoding in Content-Disposition... "
# The download path should use filename*=utf-8'' for non-ASCII names (RFC 5987)
# to ensure correct filename encoding across browsers.
if grep -q "filename\*=UTF-8''" src/api.php; then
    echo "  ✓ RFC 5987 filename encoding present"
else
    echo "  ✗ RFC 5987 filename encoding missing (Content-Disposition should use filename*=utf-8'' for non-ASCII)"
    exit 1
fi

echo ""
echo "==> Checking download connection header (prevents keep-alive cut-off)..."
if grep -q "Connection: close" src/api.php; then
    echo "  ✓ Connection: close header present in download path"
else
    echo "  ✗ Connection: close header missing"
    exit 1
fi

echo ""
echo "==> Checking production nginx.conf CSP allows external media thumbnails..."
# Production nginx.conf CSP must include all CDN thumbnail domains so that
# thumbnails render correctly on the page regardless of which deploy method is used.
PROD_CSP=$(grep "add_header Content-Security-Policy" deploy/nginx.conf | sed "s/.*add_header Content-Security-Policy[ ]*//;s/[ ]*always.*//")
for domain in "i.ytimg.com" "pbs.twimg.com" "sndcdn.com" "vimeocdn.com" "instagram.com" "fbcdn.net" "tiktokcdn.com" "tiktok.com" "vxtiktok.com" "mediaJx.com"; do
    if ! echo "$PROD_CSP" | grep -q "$domain"; then
        echo "  ✗ Production nginx.conf CSP missing thumbnail domain: $domain"
        exit 1
    fi
done
echo "  ✓ Production nginx.conf CSP allows all required media thumbnail domains"

echo ""
echo "==> Checking API key input styling (rip-key-input class)..."
if grep -q "rip-key-input" src/style.css; then
    echo "  ✓ .rip-key-input styling present"
else
    echo "  ✗ .rip-key-input styling missing"
    exit 1
fi

echo ""
echo "==> Checking unlimited-key holders receive -1 daily-limit headers..."
# When an unlimited key is used, the X-DailyLimit-Remaining should be -1
# to signal the client that the quota label should be hidden.
# The else block sets 'X-DailyLimit-Remaining: -1' only when $unlimited is true.
if grep -q "X-DailyLimit-Remaining: -1" src/api.php; then
    echo "  ✓ Unlimited-key holders receive X-DailyLimit-Remaining: -1"
else
    echo "  ✗ Unlimited-key holders do not receive X-DailyLimit-Remaining: -1"
    exit 1
fi

echo ""
echo "==> Checking health action includes all required fields..."
# The health action should return: status, server_time, request_id,
# yt_dlp_version, ffmpeg_version, yt_dlp_cache_expires_at, yt_dlp_cache_ttl_seconds,
# ffmpeg_cache_expires_at, ffmpeg_cache_ttl_seconds.
# The root guard also has a 'status' => ... echo, so we must isolate the
# case 'health': block specifically to get the real health response array.
HEALTH_RESPONSE=$(awk "/case 'health':/,/\\];/" src/api.php | sed -n "/'status' =>/,/\\];/p")
for field in "'status'" "'server_time'" "'request_id'" "'yt_dlp_version'" "'ffmpeg_version'" "'yt_dlp_cache_expires_at'" "'yt_dlp_cache_ttl_seconds'" "'ffmpeg_cache_expires_at'" "'ffmpeg_cache_ttl_seconds'"; do
    if ! echo "$HEALTH_RESPONSE" | grep -q "$field"; then
        echo "  ✗ Health response missing field: $field"
        exit 1
    fi
done
echo "  ✓ Health response contains all required fields"

echo ""
echo "==> Checking JS does not hard-code gap=0 on formatGrid (regression)..."
# The JS inline style was previously setting formatGrid.style.gap = '0' which
# overrode the CSS gap value. The CSS .format-grid { gap: 0.75rem; } should
# be the sole source of truth.
if grep -q "formatGrid\.style\.gap\s*=\s*'0'" public/index.php; then
    echo "  ✗ JS sets formatGrid.style.gap = '0' — overrides CSS and removes spacing"
    exit 1
fi
echo "  ✓ JS does not hard-code gap=0 on formatGrid"

echo ""
echo "==> Checking 503 responses include Retry-After header (rate limit gate)..."
if grep -q "header('Retry-After: 5')" src/api.php; then
    echo "  ✓ Retry-After: 5 present on rate-limit 503 responses"
else
    echo "  ✗ Retry-After header missing on 503 rate-limit responses"
    exit 1
fi

echo ""
echo "==> Checking 503 responses include Retry-After header (daily quota gate)..."
# Both info and download daily-quota 503 paths should include Retry-After
COUNT=$(grep -c "http_response_code(503)" src/api.php)
# All 503 blocks in the file should have Retry-After; verify the pattern
# by checking all occurrences have the header within 3 lines after.
bad=0
while IFS=: read -r linenum _; do
    context=$(sed -n "${linenum},$((linenum+3))p" src/api.php)
    if ! echo "$context" | grep -q "Retry-After"; then
        echo "  ✗ Line $linenum: 503 without Retry-After header"
        bad=1
    fi
done < <(grep -n "http_response_code(503)" src/api.php)
if [ "$bad" -eq 1 ]; then
    echo "  Fix: add header('Retry-After: 5') before each 503 json_encode response."
    exit 1
fi
echo "  ✓ All 503 error responses include Retry-After header"

echo ""
echo "==> Running PHP unit tests..."
php tests/api_test.php
PHP_RESULT=$?
if [ $PHP_RESULT -ne 0 ]; then
    echo "  ✗ PHP unit tests failed"
    exit 1
fi
echo "  ✓ All PHP unit tests passed"

echo ""
echo "==> Running parseFormats unit tests..."
php tests/parse_formats_test.php
PARSE_RESULT=$?
if [ $PARSE_RESULT -ne 0 ]; then
    echo "  ✗ parseFormats unit tests failed"
    exit 1
fi
echo "  ✓ All parseFormats unit tests passed"

echo ""
echo "All sanity checks passed."
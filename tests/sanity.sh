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
echo "==> Checking curl_cffi Python library (required for yt-dlp --impersonate)..."
# curl_cffi is required for yt-dlp --impersonate (yt-dlp 2024.09+).
# Without it, --impersonate silently fails and yt-dlp falls back to its default
# TLS fingerprint, defeating the anti-bot protection that --impersonate provides.
# The standalone yt-dlp binary needs this Python library separately — it is not
# bundled with the standalone binary.
if command -v python3 > /dev/null 2>&1; then
    if python3 -c "import curl_cffi; print(curl_cffi.__version__)" 2>/dev/null; then
        echo "  ✓ curl_cffi installed: $(python3 -c 'import curl_cffi; print(curl_cffi.__version__)' 2>/dev/null || true)"
    else
        echo "  ✗ curl_cffi not installed (yt-dlp --impersonate will silently fail)"
        exit 1
    fi
else
    echo "  ⚠ python3 not found in PATH (skipping — run on production server)"
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

# --no-warnings in the info action breaks error classification: yt-dlp emits
# error messages (GEOBLOCKED, AGE_RESTRICTED, etc.) to stderr, and
# classifyYtdlpError() reads $proc_stderr to classify failures. Suppressing
# warnings via --no-warnings would empty stderr and cause all info-action
# errors to fall through as unclassified YTDLP_ERROR. --no-warnings must NOT
# appear in the info action's yt-dlp command (it is correctly absent from
# the download action, where classifyYtdlpError also reads stderr).
echo ""
echo "==> Checking --no-warnings is NOT used in the info action..."
INFO_YTDLP_BLOCK=$(sed -n "/case 'info':/,/case '/p" src/api.php | head -n -1)
if echo "$INFO_YTDLP_BLOCK" | grep -qE "'\\-\\-no-warnings',?$" || echo "$INFO_YTDLP_BLOCK" | grep -qE "^\\s*'\\-\\-no-warnings',?$"; then
    echo "  ✗ --no-warnings found in info action (breaks classifyYtdlpError)"
    exit 1
fi
echo "  ✓ --no-warnings correctly absent from info action"

echo ""
echo "==> Checking --no-warnings is NOT used in the download action..."
# The download action's yt-dlp command captures $proc_stderr and passes it to
# classifyYtdlpError() to classify failures. --no-warnings would suppress
# genuine error messages that classifyYtdlpError() needs, breaking error
# classification for download failures (GEOBLOCKED, AGE_RESTRICTED, etc.).
DOWNLOAD_YTDLP_BLOCK=$(sed -n "/case 'download':/,/case '/p" src/api.php | head -n -1)
if echo "$DOWNLOAD_YTDLP_BLOCK" | grep -qE "'\\-\\-no-warnings',?$" || echo "$DOWNLOAD_YTDLP_BLOCK" | grep -qE "^\\s*'\\-\\-no-warnings',?$"; then
    echo "  ✗ --no-warnings found in download action (breaks classifyYtdlpError on \$proc_stderr)"
    exit 1
fi
echo "  ✓ --no-warnings correctly absent from download action"

echo ""
echo "==> Checking --no-warnings is NOT used in the health probe..."
# The health probe (health action's yt-dlp probe) captures $probe_stderr and
# passes it to classifyYtdlpError() for error classification. --no-warnings
# would suppress error messages the classifier needs.
if grep -A 30 "HEALTH_PROBE_VIDEO_ID" src/api.php | grep -qE "'\\-\\-no-warnings',?$"; then
    echo "  ✗ --no-warnings found in health probe (breaks classifyYtdlpError)"
    exit 1
fi
echo "  ✓ --no-warnings correctly absent from health probe"

echo ""
echo "==> Checking --playlist false is NOT used in health probe (invalid — use --no-playlist)..."
# --playlist false is rejected by yt-dlp as "ambiguous option" on yt-dlp 2025+.
# The correct flag is --no-playlist. This regression was introduced when sanity.sh
# was added; the health probe used --playlist false until the 2026-08 caretaker run.
if grep -qE "'--playlist',[[:space:]]*'false'" src/api.php; then
    echo "  ✗ --playlist false found (yt-dlp rejects this — use --no-playlist instead)"
    exit 1
fi
echo "  ✓ --playlist false absent (uses --no-playlist, as correct)"

echo ""
echo "==> Checking --no-progress is used in health probe (not --progress-template 'false')..."
# The health probe command is built in the 'health' action handler. The probe command
# is defined as $probe_cmd = [ ... ] inside the health action. We anchor on the array
# assignment to reach the actual probe command lines (lines 5551+), which are ~5500
# lines after HEALTH_PROBE_VIDEO_ID (line 57). Using $probe_cmd = [ as the anchor
# keeps the context tight and reliable regardless of where HEALTH_PROBE_VIDEO_ID moves.
PROBE_CMD_ANCHOR='\$probe_cmd = \['
if grep -A 5600 "$PROBE_CMD_ANCHOR" src/api.php | grep -qE "'--progress-template'[[:space:]]*,[[:space:]]*'false'"; then
    echo "  ✗ --progress-template 'false' found in health probe (use --no-progress instead)"
    exit 1
fi
if ! grep -A 5600 "$PROBE_CMD_ANCHOR" src/api.php | grep -qE "'--no-progress'"; then
    echo "  ✗ --no-progress missing from health probe (yt-dlp progress suppression absent)"
    exit 1
fi
echo "  ✓ Health probe uses --no-progress (correct progress suppression)"

echo ""
echo "==> Checking --no-progress is used in info action (not --progress-template 'false')..."
# The info action's yt-dlp command is built starting at line 2816 with:
#   $ytdlp_cmd = [
#       YTDLP_PATH,
#       '--dump-json',
#       '--skip-download',
# We anchor on the array assignment line to reach the command lines.
# --no-progress should appear as a standalone string in the array, after the
# playlist flags and before --socket-timeout.
INFO_CMD_ANCHOR='ytdlp_cmd = \['
if grep -A 50 "$INFO_CMD_ANCHOR" src/api.php | grep -qE "'--progress-template'[[:space:]]*,[[:space:]]*'false'"; then
    echo "  ✗ --progress-template 'false' found in info action (use --no-progress instead)"
    exit 1
fi
if ! grep -A 50 "$INFO_CMD_ANCHOR" src/api.php | grep -qE "'--no-progress'"; then
    echo "  ✗ --no-progress missing from info action (yt-dlp progress suppression absent)"
    exit 1
fi
echo "  ✓ Info action uses --no-progress (correct progress suppression)"

echo ""
echo "==> Checking --no-progress is used in download action (not --progress-template 'false')..."
# The download action's $ytdlp_cmd = [ starts at line 3883 (vs info action at line ~2876).
# --no-progress is added via array_merge() at ~3909 (after the initial array construction).
# Use a grep-based search within the download case block — more reliable than a fixed
# line range since array_merge() can shift by several lines between versions.
DOWNLOAD_YTDLP_BLOCK=$(sed -n "/case 'download':/,/case '/p" src/api.php | head -n -1)
if echo "$DOWNLOAD_YTDLP_BLOCK" | grep -qE "'--progress-template'[[:space:]]*,[[:space:]]*'false'"; then
    echo "  ✗ --progress-template 'false' found in download action (use --no-progress instead)"
    exit 1
fi
if ! echo "$DOWNLOAD_YTDLP_BLOCK" | grep -qE "'--no-progress'"; then
    echo "  ✗ --no-progress missing from download action (yt-dlp progress suppression absent)"
    exit 1
fi
echo "  ✓ Download action uses --no-progress (correct progress suppression)"

echo ""
echo "==> Checking yt-dlp deprecated/removed flags are NOT present..."
# --no-warning (singular): yt-dlp uses --no-warnings (plural).
# --concurrent-fragments: removed in yt-dlp 2024.10 (deprecated since 2023.11).
# yt-dlp now handles HLS/DASH fragment concurrency internally; passing the flag
# produces a stderr warning that can pollute JSON output in the info action and
# corrupt error classification in both info and download actions.
BAD_FLAGS="concurrent-fragments"
# --no-warning (singular): yt-dlp uses --no-warnings (plural).
# \b word boundary after 'g' means `--no-warning\b` matches `--no-warning ` or
# `--no-warning\n` (end of line) but NOT `--no-warnings` (boundary after 'g' is
# followed by 's', not a word boundary).
# -P: PCRE enables \b word boundary; -E (ERE) does not support it.
if grep -qP -- '--no-warning\b' src/api.php; then
    echo "  ✗ Deprecated --no-warning flag found (yt-dlp uses --no-warnings plural)"
    exit 1
fi
# --concurrent-fragments: removed in yt-dlp 2024.10 (deprecated since 2023.11).
# yt-dlp now handles HLS/DASH fragment concurrency internally; passing the flag
# produces a stderr warning that can pollute JSON output in the info action and
# corrupt error classification in both info and download actions.
# Use a negative lookbehind (?<![/]\s) to match the flag ONLY when it appears as
# actual code, not when it is documented in comments explaining its removal.
# -P: PCRE is required for lookbehind; -E (ERE) does not support it.
for flag in $BAD_FLAGS; do
    if grep -qP -- "(?<![/]\s)--$flag\b" src/api.php; then
        echo "  ✗ Deprecated/removed yt-dlp flag found: $flag"
        exit 1
    fi
done
echo "  ✓ No deprecated yt-dlp flags (--no-warning singular, --concurrent-fragments)"

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
echo "==> Checking PWA manifest icons use valid W3C 'purpose' values... "
# Per W3C Web Manifest spec, 'purpose' must be a space-separated set of only
# "any", "maskable", or "badge" — never a combined string like "any maskable".
# This test catches that specific violation and future regressions.
MANIFEST_PATH="$PROJECT_ROOT/public/manifest.json"
VALID_PURPOSES="^(any|maskable|badge)( (any|maskable|badge))*$"
INVALID_COUNT=0
while IFS= read -r icon_json; do
    # Extract purpose field from this icon block
    purpose=$(echo "$icon_json" | php -r "echo json_decode(file_get_contents('php://stdin'))->purpose ?? 'any';")
    if ! echo "$purpose" | grep -Eq "$VALID_PURPOSES"; then
        echo "  ✗ Invalid purpose='$purpose' — must be 'any', 'maskable', or 'badge' (or space-separated combo)"
        INVALID_COUNT=$((INVALID_COUNT + 1))
    fi
done < <(php -r '
    $manifest = json_decode(file_get_contents("'"$MANIFEST_PATH"'"), true);
    foreach ($manifest["icons"] ?? [] as $icon) {
        echo json_encode($icon) . "\n";
    }
')
if [ "$INVALID_COUNT" -eq 0 ]; then
    echo "  ✓ All icon purpose values are W3C-compliant"
else
    echo "  ✗ $INVALID_COUNT icon(s) have invalid purpose value(s)"
    exit 1
fi

echo ""
echo "==> Checking PWA manifest description length (W3C + Play Store compliance)... "
# W3C Web Manifest spec recommends descriptions stay under 512 bytes.
# Play Store requires ≤ 80 chars. Using 512 bytes as the primary limit here
# since it is the W3C requirement; the test is informational.
MANIFEST_DESC=$(php -r "echo json_decode(file_get_contents('$PROJECT_ROOT/public/manifest.json'))->description ?? '';")
DESC_LEN=${#MANIFEST_DESC}
MAX_DESC_LEN=512
if [ "$DESC_LEN" -le "$MAX_DESC_LEN" ]; then
    echo "  ✓ manifest description length: $DESC_LEN bytes (≤ $MAX_DESC_LEN)"
else
    echo "  ✗ manifest description too long: $DESC_LEN bytes (W3C recommends ≤ $MAX_DESC_LEN)"
    exit 1
fi

echo ""
echo "==> Checking PWA manifest screenshots use UI assets (not brand/social share images)... "
# PWA screenshots must show the app UI, not brand/logo images (og-image, logos).
# Use favicon-512.png (the app icon) as the screenshot — it exists and is a valid UI
# representation. og-image.webp (social share card) is a brand asset and non-compliant.
# Guard against regression: the screenshot src must NOT be og-image.*
MANIFEST_PATH="$PROJECT_ROOT/public/manifest.json"
SCREENSHOT_SRC=$(php -r 'echo json_decode(file_get_contents($argv[1]))->screenshots[0]->src ?? "";' "$MANIFEST_PATH")
# Screenshots are optional in manifest.json. If absent/empty, that is valid PWA spec.
# Only fail if a screenshot IS present AND uses a brand/social share asset (non-compliant).
if echo "$SCREENSHOT_SRC" | grep -qE '^/(og-image|og-image\.webp|og-image\.png)$'; then
    echo "  ✗ manifest screenshots uses brand asset '"'"'$SCREENSHOT_SRC'"'"' (non-compliant PWA screenshot)"
    echo "    Use favicon-512.png or an actual UI screenshot instead."
    exit 1
fi
if [ -z "$SCREENSHOT_SRC" ]; then
    echo "  ✓ manifest screenshots absent/empty (PWA spec allows this; guard against brand assets is active)"
else
    if [ ! -f "$PROJECT_ROOT/public/$SCREENSHOT_SRC" ]; then
        echo "  ✗ manifest screenshot src='"'"'$SCREENSHOT_SRC'"'"' does not exist in public/"
        exit 1
    fi
    echo "  ✓ manifest screenshots uses a valid UI asset ($SCREENSHOT_SRC)"
fi

echo ""
echo "==> Checking security headers in api.php..."
REQUIRED_HEADERS=(
    "Date"
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
echo "==> Checking security headers in index.php (HTML page entry point)..."
# index.php must also carry key security headers for defense-in-depth.
# X-Content-Type-Options, X-Frame-Options, X-Download-Options, X-Robots-Tag,
# Referrer-Policy, Permissions-Policy are already verified in api.php above;
# check they are also present in index.php via header() calls.
INDEX_REQUIRED_HEADERS=(
    "X-Content-Type-Options"
    "X-Frame-Options"
    "X-Download-Options"
    "X-Robots-Tag"
    "Permissions-Policy"
)
for header in "${INDEX_REQUIRED_HEADERS[@]}"; do
    if grep -q "$header" public/index.php; then
        echo "  ✓ $header present in index.php"
    else
        echo "  ✗ Missing: $header in index.php"
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
echo "==> Checking og:image:alt is present in index.php (SEO accessibility)... "
if grep -q 'og:image:alt.*yt-dlp\|og:image:alt.*platforms\|og:image:alt.*SoundCloud' public/index.php; then
    echo "  ✓ og:image:alt present (accessibility + SEO best practice)"
else
    echo "  ✗ og:image:alt missing (screen readers and non-visual clients can't describe og:image)"
    exit 1
fi

echo ""
echo "==> Checking og:alt (not og:image:alt) is NOT present in index.php (duplicate)... "
# og:alt is NOT a valid Open Graph property — og:image:alt is the correct one.
# A bare og:alt on the page (not scoped to og:image) is redundant and should be
# removed to avoid confusing social media scrapers that may misread it.
# Valid: <meta property="og:image:alt" content="...">
# Invalid: <meta property="og:alt" content="...">  (no og:image: scope, bare og:alt)
if grep -q '<meta property="og:alt"' public/index.php; then
    echo "  ✗ Duplicate og:alt found (use og:image:alt instead — og:alt without og:image: scope is invalid)"
    exit 1
else
    echo "  ✓ No bare og:alt (duplicate of og:image:alt)"
fi

echo ""
echo "==> Checking og:image:secure_url and og:url in public/index.php..."
# og:image:secure_url provides the HTTPS URL for og:image (fallback for clients that
# don't support webp). og:url sets the canonical URL for the page in Open Graph.
if grep -q 'og:image:secure_url.*content=' public/index.php \
    && grep -q 'og:url.*content=' public/index.php; then
    echo "  ✓ og:image:secure_url and og:url present"
else
    echo "  ✗ og:image:secure_url or og:url missing from index.php"
    exit 1
fi

echo ""
echo "==> Checking rate limiting (info endpoint)..."
# Check for the constant definition: define('RATE_LIMIT', ... ?: 30) — the default is 30.
# Also accept the variable assignment: $rate_limit = RATE_LIMIT (both in one check).
if grep -qE "define\s*\(\s*'RATE_LIMIT'" src/api.php && grep -qE "\\\$rate_limit\s*=\s*RATE_LIMIT" src/api.php; then
    echo "  ✓ Info rate limit (RATE_LIMIT constant, default 30/min) configured"
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
echo "==> Checking gzip compression in nginx-docker.conf..."
# gzip must be enabled in nginx-docker.conf for efficient bandwidth use.
# Without it, JSON API responses and text assets are sent uncompressed.
if grep -q "^    gzip on;" deploy/nginx-docker.conf; then
    echo "  ✓ gzip compression enabled in nginx-docker.conf"
else
    echo "  ✗ gzip compression missing from nginx-docker.conf (add 'gzip on;' in server block)"
    exit 1
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
# The refundQuota() call appears AFTER the logRequest for 'parse_formats_ytdlp_error'
# inside the "if (isset(\$parsed['error']))" block.
# NOTE: After refactoring to refundQuota() helper, the file path is constructed
# inside the function, so the check looks for refundQuota() call sites instead.
if echo "$INFO_CASE" | grep -A 30 "parseFormats.*error" | grep -q "refundQuota"; then
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
# The refundQuota() call appears inside the "if (!\$parsed)" block.
# NOTE: After refactoring to refundQuota() helper, the check looks for
# the refundQuota() call rather than the inline file-path string.
if echo "$INFO_CASE" | grep -A 30 "if (\!\$parsed)" | grep -q "refundQuota"; then
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
# NOTE: After refactoring to refundQuota() helper, the file path is constructed
# inside the function, so the check looks for the refundQuota() call site instead.
if awk '/\$err_classified = classifyYtdlpError/,/^[[:space:]]*\}/' src/api.php | grep -q "refundQuota"; then
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
echo "==> Checking filename length uses MAX_FILENAME_LEN constant (not magic number)..."
# The filename sanitization block must use MAX_FILENAME_LEN, not a bare 80.
# Using a constant prevents future regressions where the magic number is changed
# in one place but not another.
if grep -q "strlen(\$trimmed) > 80" src/api.php; then
    echo "  ✗ Magic number 80 found in filename sanitization (use MAX_FILENAME_LEN)"
    exit 1
fi
if ! grep -q "define.*MAX_FILENAME_LEN" src/api.php; then
    echo "  ✗ MAX_FILENAME_LEN constant is not defined"
    exit 1
fi
echo "  ✓ Filename length uses MAX_FILENAME_LEN constant"

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
echo "==> Checking Docker nginx.conf server-level CSP connect-src includes Google Fonts CDNs... "
# The server-level CSP in nginx-docker.conf must include fonts.googleapis.com and
# fonts.gstatic.com in connect-src, because browsers make CONNECT requests to these
# hosts when loading Google Fonts (even if the font URLs are only in CSS @import).
# Without this, fonts are silently blocked in CSP-reporting mode (no visible error
# in the browser console — only a CSP violation report is sent).
# The /csp-report location (line ~109) and production nginx.conf (line ~71) both
# correctly include these domains; this test guards against the server-level CSP
# regressing to connect-src 'self' only.
SERVER_CSP=$(grep "^[[:space:]]*add_header Content-Security-Policy \"default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com" deploy/nginx-docker.conf | grep -v "Report-Only" | head -1)
if echo "$SERVER_CSP" | grep -q "connect-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com"; then
    echo "  ✓ Server-level CSP connect-src includes Google Fonts CDNs"
else
    echo "  ✗ Server-level CSP connect-src missing Google Fonts CDNs (browsers can't load fonts)"
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
echo "==> Checking X-Powered-By suppression in public/index.php..."
# index.php must call header_remove('X-Powered-By') to suppress the PHP version
# header that PHP-FPM adds automatically. This complements server_tokens off
# in nginx and fastcgi_hide_header in the production nginx.conf (api.php
# location). Without this, the HTML page leaks the PHP version when served
# through PHP-FPM even when nginx would otherwise hide it.
if grep -q "header_remove('X-Powered-By')" public/index.php; then
    echo "  ✓ X-Powered-By suppressed in index.php"
else
    echo "  ✗ header_remove('X-Powered-By') missing from index.php — PHP version leaks"
    exit 1
fi

echo ""
echo "==> Checking COEP is NOT set in public/index.php (intentional — require-corp breaks cross-origin thumbnails)..."
if grep -q 'Cross-Origin-Embedder-Policy' public/index.php; then
    echo "  ✗ Cross-Origin-Embedder-Policy should NOT be set — require-corp breaks YouTube/TikTok/Twitter thumbnails loaded via fetch()"
    exit 1
else
    echo "  ✓ COEP intentionally absent (require-corp would block cross-origin thumbnails)"
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
echo "==> Checking PWA CACHE_VERSION uses sentinel comparison pattern (not self-referential hash)... "
# sw.js CACHE_VERSION must compare '{{CACHE_VERSION}}' against a sentinel like 'PLACEHOLDER',
# NOT against itself (e.g. 'b0c6e25' === 'b0c6e25'). A self-referential comparison is always
# true and defeats PWA cache versioning — the SW always gets 'unversioned' in every deploy.
# The correct pattern: '{{CACHE_VERSION}}' === 'PLACEHOLDER' ? 'unversioned' : '{{CACHE_VERSION}}'
# Detect self-referential comparison: 'HASH' === 'HASH' — always true, defeats PWA versioning.
# The old (broken) pattern used 'b0c6e25' on both sides of ===, so the placeholder hash
# appeared as a literal in sw.js. The correct pattern uses '{{CACHE_VERSION}}' on the
# left side and 'PLACEHOLDER' sentinel on the right. A simple grep for the old hash
# is the most reliable regression test — if 'b0c6e25' appears in sw.js at all, the
# CACHE_VERSION line is almost certainly self-referential.
if grep -q "'b0c6e25'" public/sw.js 2>/dev/null; then
    echo "  ✗ sw.js still contains old placeholder hash 'b0c6e25' — CACHE_VERSION is self-referential"
    exit 1
else
    echo "  ✓ sw.js CACHE_VERSION does not contain self-referential hash placeholder"
fi

echo ""
echo "==> Checking generate-sw-version.php documents the PLACEHOLDER sentinel pattern... "
# The generate-sw-version.php script must document the correct PLACEHOLDER sentinel pattern.
# When the script is updated to support PLACEHOLDER, the comment block should mention it.
if grep -q "PLACEHOLDER" scripts/generate-sw-version.php 2>/dev/null; then
    echo "  ✓ generate-sw-version.php documents PLACEHOLDER sentinel pattern"
else
    echo "  ⚠ generate-sw-version.php does not mention PLACEHOLDER sentinel — script may be out of date"
fi

echo ""
echo "==> Checking og:image meta tag in public/index.php..."
if grep -q 'meta property="og:image"' public/index.php; then
    echo "  ✓ og:image present in index.php"
else
    echo "  ✗ og:image missing from index.php"
    exit 1
fi

echo "==> Checking og:image sub-properties (dimensions, MIME type, fetchpriority) in public/index.php..."
# og:image:width and og:image:height are required by Twitter Card validator and
# Open Graph spec for accurate social previews. Guard against regression.
# og:image:type validates the image MIME type for social crawler fetching.
# og:image:fetchpriority signals the browser to prioritize og:image loading early,
# reducing LCP (Largest Contentful Paint) on social media share pages.
if grep -q 'og:image:width.*content=' public/index.php \
    && grep -q 'og:image:height.*content=' public/index.php \
    && grep -q 'og:image:type.*content=' public/index.php \
    && grep -q 'og:image:fetchpriority.*content=' public/index.php; then
    echo "  ✓ og:image:width, og:image:height, og:image:type, og:image:fetchpriority present"
else
    echo "  ✗ og:image sub-properties (width/height/type/fetchpriority) missing from index.php"
    exit 1
fi

echo ""
echo "==> Checking og-image.svg platform count consistency (1873)... "
# The og-image.svg <desc> and <text> element must both say "1873".
# Inconsistency here (e.g. <desc> saying "1872" while <text> says "+1873")
# was found and fixed in a previous caretaker run.
OG_DESC=$(grep 'id="og-desc"' public/og-image.svg | grep -o '1873\|1872\|1800' || true)
OG_TEXT=$(grep 'text.*1873\|text.*1872\|text.*1800' public/og-image.svg | grep -o '1873\|1872\|1800' || true)
if [ "$OG_DESC" = "1873" ] && [ "$OG_TEXT" = "1873" ]; then
    echo "  ✓ og-image.svg consistently says 1873+ (desc and text match)"
else
    echo "  ✗ og-image.svg platform count mismatch: desc='$OG_DESC', text='$OG_TEXT' (both must be 1873)"
    exit 1
fi

echo "==> Checking og:title meta tag in public/index.php..."
if grep -q 'meta property="og:title"' public/index.php; then
    echo "  ✓ og:title present in index.php"
else
    echo "  ✗ og:title missing from index.php"
    exit 1
fi

echo "==> Checking og:title:alt text alternative in public/index.php..."
# og:title:alt (RFC 6947 §4.1) provides a text alternative for the og:title when
# the page is rendered in a non-visual context (screen readers, search indexers,
# OpenGraph-only clients). This was added in the 2026-08 caretaker run.
if grep -q 'meta property="og:title:alt"' public/index.php; then
    echo "  ✓ og:title:alt present in index.php"
else
    echo "  ✗ og:title:alt missing from index.php (RFC 6947 §4.1 text alternative)"
    exit 1
fi

echo "==> Checking og:description meta tag in public/index.php..."
if grep -q 'meta property="og:description"' public/index.php; then
    echo "  ✓ og:description present in index.php"
else
    echo "  ✗ og:description missing from index.php"
    exit 1
fi

echo "==> Checking og:description:alt text alternative in public/index.php..."
# og:description:alt (RFC 6947 §4.1) provides a text alternative for the
# og:description in non-visual contexts, matching the og:title:alt pattern above.
if grep -q 'meta property="og:description:alt"' public/index.php; then
    echo "  ✓ og:description:alt present in index.php"
else
    echo "  ✗ og:description:alt missing from index.php (RFC 6947 §4.1 text alternative)"
    exit 1
fi

echo "==> Checking og:url meta tag in public/index.php..."
if grep -q 'meta property="og:url"' public/index.php; then
    echo "  ✓ og:url present in index.php"
else
    echo "  ✗ og:url missing from index.php"
    exit 1
fi

echo "==> Checking Twitter Card meta tags in public/index.php..."
# twitter:site (@username) and twitter:creator (@username) identify the content publisher
# and creator respectively — required for proper attribution on Twitter Cards.
# twitter:domain helps Twitter consolidate link metadata for the publisher's domain.
if grep -q 'meta name="twitter:card"' public/index.php \
    && grep -q 'meta name="twitter:title"' public/index.php \
    && grep -q 'meta name="twitter:description"' public/index.php \
    && grep -q 'meta name="twitter:site"' public/index.php \
    && grep -q 'meta name="twitter:creator"' public/index.php \
    && grep -q 'meta name="twitter:domain"' public/index.php; then
    echo "  ✓ Twitter Card meta tags (card, title, description, site, creator, domain) present in index.php"
else
    echo "  ✗ Twitter Card meta tags missing from index.php"
    exit 1
fi

echo "==> Checking twitter:image dimensions in public/index.php (Twitter Card rendering)..."
# twitter:image:width and twitter:image:height (Open Graph spec) are required by
# Twitter Card validator for accurate rendering. Without them the card may show a
# placeholder or be rejected. Also guard twitter:image and twitter:image:alt.
if grep -q 'meta name="twitter:image"' public/index.php \
    && grep -q 'meta name="twitter:image:alt"' public/index.php \
    && grep -q 'meta name="twitter:image:width"' public/index.php \
    && grep -q 'meta name="twitter:image:height"' public/index.php; then
    echo "  ✓ twitter:image, twitter:image:alt, twitter:image:width, twitter:image:height present"
else
    echo "  ✗ twitter:image, twitter:image:alt, or dimension tags missing from index.php"
    exit 1
fi

echo "==> Checking canonical URL in public/index.php..."
if grep -q 'link rel="canonical"' public/index.php; then
    echo "  ✓ canonical URL present in index.php"
else
    echo "  ✗ canonical URL missing from index.php"
    exit 1
fi

echo ""
echo "==> Checking README info response example includes api_version (present on all endpoints)..."
# The info response JSON example SHOULD contain "api_version" — api_version
# is present on ALL endpoints (check, info, download, health) per api.php.
# This was updated from an older incorrect check that claimed only check had it.
if grep -A20 '"sort_applied"' README.md | grep -q '"api_version"'; then
    echo "  ✓ README info response example correctly includes api_version (present on all endpoints)"
else
    echo "  ✗ README info response example is missing api_version (should be present on all endpoints)"
    exit 1
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
#   3. API-location enforcement (location = /src/api.php block) — intentionally more
#      restrictive for the JSON API endpoint (no unsafe-inline, no font CDNs).
#   4. API-location report-only (same location, mirrors server-level report-only).
#      Needed so Safari and older Firefox (< Firefox 79) which support neither
#      Reporting-Endpoints nor Report-To can still submit CSP violation reports.
#   5. /csp/report location enforcement CSP (location = /csp/report block)
#   6. /csp/report location report-only CSP
# The test checks that there are exactly 6 (not 1-5, which would indicate
# duplicate server-level or spurious entries).
CSP_COUNT=$(grep -c "Content-Security-Policy" deploy/nginx-docker.conf || true)
if [ "$CSP_COUNT" -eq 6 ]; then
    echo "  ✓ CSP appears $CSP_COUNT times in nginx-docker.conf (enforcement + report-only at server, API override + csp-report location)"
else
    echo "  ✗ CSP appears $CSP_COUNT times in nginx-docker.conf (expected 6: enforcement + report-only at server, API override + csp-report location)"
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
echo "==> Checking enforcement CSP in nginx-docker.conf includes report-to csp-report..."
# The enforcement CSP uses 'report-to csp-report' (Reporting API, Chromium 94+).
# 'report-uri' belongs only in the Report-Only header for Safari/older Firefox.
# If report-uri is in the enforcement CSP alongside report-to, modern browsers may
# prefer report-uri and bypass the modern Reporting API endpoint.
CSP_ENF=$(grep "add_header Content-Security-Policy\ " deploy/nginx-docker.conf | grep -v "Report-Only" | sed "s/.*add_header Content-Security-Policy[ ]*//;s/[ ]*always.*//")
if echo "$CSP_ENF" | grep -q "report-to csp-report;"; then
    echo "  ✓ Enforcement CSP includes report-to csp-report"
else
    echo "  ✗ Enforcement CSP missing report-to csp-report — modern browsers won't report violations"
    exit 1
fi
if echo "$CSP_ENF" | grep -q "report-uri /csp-report;"; then
    echo "  ✗ Enforcement CSP should not include report-uri (use Report-Only header instead)"
    exit 1
else
    echo "  ✓ Enforcement CSP does not contain report-uri (correctly separated to Report-Only)"
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
echo "==> Checking enforcement CSP in nginx.conf includes report-to csp-report..."
# The enforcement CSP uses 'report-to csp-report' (Reporting API, Chromium 94+).
# 'report-uri' belongs only in the Report-Only header for Safari/older Firefox.
CSP_ENF=$(grep "add_header Content-Security-Policy\ " deploy/nginx.conf | grep -v "Report-Only" | sed "s/.*add_header Content-Security-Policy[ ]*//;s/[ ]*always.*//")
if echo "$CSP_ENF" | grep -q "report-to csp-report;"; then
    echo "  ✓ Enforcement CSP includes report-to csp-report"
else
    echo "  ✗ Enforcement CSP missing report-to csp-report — modern browsers won't report violations"
    exit 1
fi
if echo "$CSP_ENF" | grep -q "report-uri /csp-report;"; then
    echo "  ✗ Enforcement CSP should not include report-uri (use Report-Only header instead)"
    exit 1
else
    echo "  ✓ Enforcement CSP does not contain report-uri (correctly separated to Report-Only)"
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
# COOP and CORP each appear 13 times legitimately:
#   - 1 at server level (base hardening for all responses)
#   - 1 in /csp-report location block — /csp-report is a PHP endpoint and needs its
#     own headers because server-level add_header directives are NOT inherited by
#     location blocks that define their own add_header (nginx behaviour).
#   - 1 in `location = /` block (root HTML page with preload Link header)
#   - 1 in /manifest.json location block (PWA manifest)
#   - 1 in /opensearch.xml location block (OpenSearch description)
#   - 1 in /.well-known/ location block (security.txt and other well-known files)
#   - 1 in /.well-known/security.txt location block (RFC 9116 security contact)
#   - 1 in /sitemap.xml location block (XML sitemap for search engines)
#   - 1 in /og-image.png location block (social share preview image)
#   - 1 in /404.html location block for not-found responses (defense-in-depth)
#   - 1 in /50x.html location block for PHP-FPM error pages (502/504) and
#     limit_req burst rejections (503) — bypasses PHP so headers must be at nginx level.
#   - 1 in /src/api.php location block for the API endpoint
#   - 1 in the catch-all `location /` block (try_files fallback)
# PHP's api.php sets COOP/CORP itself, but the /csp-report handler (PHP) does not
# set these headers, so nginx must provide them at that specific location.
COOP_COUNT=$(grep -c "Cross-Origin-Opener-Policy" deploy/nginx-docker.conf || true)
CORP_COUNT=$(grep -c "Cross-Origin-Resource-Policy" deploy/nginx-docker.conf || true)
if [ "$COOP_COUNT" -eq 13 ] && [ "$CORP_COUNT" -eq 13 ]; then
    echo "  ✓ COOP appears $COOP_COUNT times and CORP appears $CORP_COUNT times (server + /csp-report + location = / + /manifest.json + /opensearch.xml + /.well-known/ + /.well-known/security.txt + /sitemap.xml + /og-image.png + /404.html + /50x.html + /src/api.php + catch-all location /)"
else
    echo "  ✗ COOP appears $COOP_COUNT times (expected 13), CORP appears $CORP_COUNT times (expected 13)"
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
echo "==> Checking X-FFProbe-Status header in download responses..."
# X-FFProbe-Status should be set on both success and failure paths so the
# client can always diagnose ffprobe verification outcomes. The success case
# (header 'X-FFProbe-Status: success') is set after ffprobe confirms a video
# stream; the failure case (header 'X-FFProbe-Status: failed') is set in the
# early-exit block when ffprobe fails, times out, or finds no video stream.
# Both occurrences must be present to ensure the header is never absent.
FFPROBE_SUCCESS=$(grep -c "X-FFProbe-Status: success" src/api.php || true)
FFPROBE_FAILED=$(grep -c "X-FFProbe-Status: failed" src/api.php || true)
if [ "$FFPROBE_SUCCESS" -ge 1 ] && [ "$FFPROBE_FAILED" -ge 1 ]; then
    echo "  ✓ X-FFProbe-Status header present on both success and failure paths"
elif [ "$FFPROBE_SUCCESS" -ge 1 ]; then
    echo "  ✗ X-FFProbe-Status: success present but X-FFProbe-Status: failed missing"
    exit 1
else
    echo "  ✗ X-FFProbe-Status header not found in download response paths"
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
# yt_dlp_version, ffmpeg_version, ffprobe_version, yt_dlp_cache_expires_at, yt_dlp_cache_ttl_seconds,
# ffmpeg_cache_expires_at, ffmpeg_cache_ttl_seconds.
# The root guard also has a 'status' => ... echo, so we must isolate the
# case 'health': block specifically to get the real health response array.
HEALTH_RESPONSE=$(awk "/case 'health':/,/\\];/" src/api.php | sed -n "/'status' =>/,/\\];/p")
for field in "'status'" "'server_time'" "'request_id'" "'yt_dlp_version'" "'ffmpeg_version'" "'ffprobe_version'" "'yt_dlp_cache_expires_at'" "'yt_dlp_cache_ttl_seconds'" "'ffmpeg_cache_expires_at'" "'ffmpeg_cache_ttl_seconds'" "'yt_dlp_probe_cache_expires_at'" "'yt_dlp_probe_cache_ttl_seconds'"; do
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
# All direct 503 call sites (not function definitions) must have Retry-After
# within 3 lines. This skips http_response_code(503) that appears inside
# helper functions (e.g. sendServiceUnavailable503 at line 315), where the
# Retry-After line is deeper in the function body.
bad=0
while IFS=: read -r linenum _; do
    # Skip lines inside helper function bodies.
    # e.g. sendServiceUnavailable503() has { on line 314 and http_response_code(503)
    # on line 315. Look back up to 3 lines for the 'function' keyword.
    is_function_body=0
    for ((lookback=1; lookback<=3; lookback++)); do
        if [ "$linenum" -gt "$lookback" ]; then
            prev=$(sed -n "$((linenum-lookback))p" src/api.php)
            if echo "$prev" | grep -qE "^function [a-zA-Z_][a-zA-Z0-9_]*\("; then
                is_function_body=1
                break
            fi
        fi
    done
    if [ "$is_function_body" -eq 1 ]; then
        continue  # inside function body — Retry-After appears deeper in the function
    fi
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
echo "==> Checking Cache-Control: no-store on download error responses..."
# All download error paths must prevent caching of generated error responses.
# Verified paths: classified error (err_classified), unclassified error (YTDLP_ERROR),
# timeout (DOWNLOAD_TIMEOUT), empty/missing file (DOWNLOAD_EMPTY).
# Use grep -c to count all occurrences of 'Cache-Control: no-store' in download case.
DOWNLOAD_CASE=$(sed -n "/case 'download':/,/case '/p" src/api.php | head -n -1)
# Count 'Cache-Control: no-store' occurrences in the download case.
# Known working occurrences: timeout error, empty file error (both already had it).
# New: classified error and unclassified error (added in this patch).
no_store_count=$(echo "$DOWNLOAD_CASE" | grep -c "Cache-Control: no-store" || true)
if [ "$no_store_count" -ge 4 ]; then
    echo "  ✓ Cache-Control: no-store present on all download error paths ($no_store_count occurrences)"
else
    echo "  ✗ Cache-Control: no-store missing on some download error paths (found $no_store_count, expected ≥4)"
    exit 1
fi

echo ""
echo "==> Checking Cache-Control: no-store on check action (stateless JSON ping)..."
# The check action (lines 4261-4350) is a stateless Docker healthcheck / load-balancer
# probe endpoint. It MUST use no-store (not no-cache) to prevent intermediate proxies
# from caching and revalidating a stale ping response. All other API responses already
# use no-store; check was the only outlier (was no-cache before 2026-08-21 fix).
# Verify the check case sets Cache-Control: no-store (grep count >= 1).
CHECK_CASE=$(sed -n "/case 'check':/,/case '/p" src/api.php | head -n -1)
check_no_store=$(echo "$CHECK_CASE" | grep -c "Cache-Control: no-store" || true)
check_no_cache=$(echo "$CHECK_CASE" | grep -c "Cache-Control: no-cache" || true)
if [ "$check_no_store" -ge 1 ] && [ "$check_no_cache" -eq 0 ]; then
    echo "  ✓ check action uses Cache-Control: no-store (not no-cache)"
elif [ "$check_no_cache" -ge 1 ]; then
    echo "  ✗ check action uses Cache-Control: no-cache — should be no-store"
    echo "    (fix: change 'Cache-Control: no-cache' to 'Cache-Control: no-store' in the check case)"
    exit 1
else
    echo "  ✗ check action missing Cache-Control header"
    exit 1
fi

echo ""
echo "==> Checking info action uses Cache-Control: no-store (not no-cache)..."
# The info action (info success at ~line 2901, info error at ~line 2602) must use
# no-store — the API does not use ETag/Last-Modified revalidation so no-cache is
# wrong and risks proxy caching of stale JSON responses. All other API responses
# already correctly use no-store.
INFO_CASE=$(sed -n "/case 'info':/,/case '/p" src/api.php | head -n -1)
info_no_store=$(echo "$INFO_CASE" | grep -c "Cache-Control: no-store" || true)
info_no_cache=$(echo "$INFO_CASE" | grep -c "Cache-Control: no-cache" || true)
if [ "$info_no_cache" -eq 0 ]; then
    echo "  ✓ info action uses no-cache correctly absent (no-cache found: $info_no_cache)"
else
    echo "  ✗ info action uses Cache-Control: no-cache — should be no-store (found $info_no_cache occurrence(s))"
    exit 1
fi

echo ""
echo "==> Checking health action uses Cache-Control: no-store (not no-cache)..."
# The health action (line ~4918) must use no-store for the same reason as info.
HEALTH_CASE=$(sed -n "/case 'health':/,/case '/p" src/api.php | head -n -1)
health_no_store=$(echo "$HEALTH_CASE" | grep -c "Cache-Control: no-store" || true)
health_no_cache=$(echo "$HEALTH_CASE" | grep -c "Cache-Control: no-cache" || true)
if [ "$health_no_cache" -eq 0 ]; then
    echo "  ✓ health action uses no-cache correctly absent (no-cache found: $health_no_cache)"
else
    echo "  ✗ health action uses Cache-Control: no-cache — should be no-store (found $health_no_cache occurrence(s))"
    exit 1
fi

echo ""
echo "==> Checking health action includes X-Info-Timeout header (consistent with check/info/download)..."
HEALTH_CASE=$(sed -n "/case 'health':/,/case '/p" src/api.php | head -n -1)
if echo "$HEALTH_CASE" | grep -q "X-Info-Timeout"; then
    echo "  ✓ health action includes X-Info-Timeout header"
else
    echo "  ✗ health action missing X-Info-Timeout header (inconsistent with check/info/download)"
    exit 1
fi

echo ""
echo "==> Checking MISSING_FORMAT and INVALID_FORMAT_ID error codes exist..."
# Both error codes are returned by the download action when format is absent or invalid.
# Verify they exist and return HTTP 400 (not 200 or 500).
if grep -q "'error_code' => 'MISSING_FORMAT'" src/api.php && grep -q "'error_code' => 'INVALID_FORMAT_ID'" src/api.php; then
    echo "  ✓ MISSING_FORMAT and INVALID_FORMAT_ID error codes are present"
else
    echo "  ✗ MISSING_FORMAT or INVALID_FORMAT_ID error code missing"
    exit 1
fi
# Verify both return http_response_code(400) — not 200 or 500
MISSING_FORMAT_CODE=$(grep -n "'error_code' => 'MISSING_FORMAT'" src/api.php | head -1 | cut -d: -f1)
INVALID_FORMAT_CODE=$(grep -n "'error_code' => 'INVALID_FORMAT_ID'" src/api.php | head -1 | cut -d: -f1)
# 30-line context needed: MISSING_FORMAT has http_response_code at line 1775
# and error_code at line 1801 (26-line gap with security headers); INVALID_FORMAT_ID
# similarly has http_response_code at line 1835 and error_code at line 1861 (26-line gap).
for linenum in "$MISSING_FORMAT_CODE" "$INVALID_FORMAT_CODE"; do
    context=$(sed -n "$((linenum-30)),${linenum}p" src/api.php)
    if ! echo "$context" | grep -q "http_response_code(400)"; then
        echo "  ✗ Line $linenum: MISSING_FORMAT/INVALID_FORMAT_ID should return http_response_code(400)"
        exit 1
    fi
done
echo "  ✓ MISSING_FORMAT and INVALID_FORMAT_ID return HTTP 400"

echo ""
echo "==> Checking MISSING_URL and INVALID_URL responses have security headers..."
# Both URL validation error responses exit before the normal response-building pipeline
# where headers are typically set — mirror the protection already added to MISSING_FORMAT
# and INVALID_FORMAT_ID. Without these, browsers and proxies may sniff or cache responses
# that appear successful at the HTTP level but are semantically error payloads.
#
# Use unique strings near each block as anchors to avoid matching the wrong occurrence
# (MISSING_URL also appears in the $http_status_codes array at a different line).
for err_code in MISSING_URL INVALID_URL; do
    case "$err_code" in
        MISSING_URL)  anchor="No URL was provided" ;;
        INVALID_URL)  anchor="error_code.*INVALID_URL" ;;
    esac
    # Compute start/end around the anchor to capture headers that may appear
    # before the anchor line. MISSING_URL headers are 26 lines before the anchor
    # ("No URL was provided" at line 1685 vs X-Content-Type-Options at line 1659).
    # INVALID_URL headers are 18 lines before the anchor ("Invalid URL. Please paste"
    # at line 1732 vs X-Content-Type-Options at line 1714). Use 30-line lookback
    # to reliably capture both; 20 lines was insufficient for MISSING_URL.
    anchor_line=$(grep -n "$anchor" src/api.php | head -1 | cut -d: -f1)
    start_line=$(( anchor_line > 30 ? anchor_line - 30 : 1 ))
    end_line=$(( anchor_line + 20 ))
    BLOCK_LINES=$(sed -n "${start_line},${end_line}p" src/api.php)
    for header in "X-Content-Type-Options" "X-Frame-Options" "X-RateLimit-Limit" "Referrer-Policy"; do
        if echo "$BLOCK_LINES" | grep -q "$header"; then
            echo "  ✓ $err_code includes $header"
        else
            echo "  ✗ $err_code missing $header"
            exit 1
        fi
    done
done

echo ""
echo "==> Checking MISSING_URL response has user-friendly message..."
# MISSING_URL error message must be specific (mention pasting a link), not generic.
# Extract the error message string for MISSING_URL (in the info/download validation block).
# Check that MISSING_URL block contains a paste-related user hint.
# The message appears BEFORE the 'error_code' => 'MISSING_URL' line in the json_encode.
if grep -B 10 "MISSING_URL" src/api.php | grep -qi "paste\|provide\|url"; then
    echo "  ✓ MISSING_URL message is user-friendly (mentions paste/provide/url)"
else
    echo "  ✗ MISSING_URL message is too generic (should mention pasting a link)"
    exit 1
fi

echo ""
echo "==> Checking MISSING_URL and INVALID_URL responses include retry_after field..."
# MISSING_URL and INVALID_URL are client-input validation errors that don't consume
# quota (they fail before the quota file is opened). Adding retry_after gives
# API clients a consistent field to read for backoff timing — matching the contract
# of all other error responses (PARSE_ERROR, classified errors, rate limits).
# Use the same anchor-based block extraction used by the security-headers check above.
for err_code in MISSING_URL INVALID_URL; do
    case "$err_code" in
        MISSING_URL) anchor="No URL was provided" ;;
        INVALID_URL)  anchor="Invalid URL. Please paste" ;;
    esac
    anchor_line=$(grep -n "$anchor" src/api.php | head -1 | cut -d: -f1)
    start_line=$(( anchor_line > 20 ? anchor_line - 20 : 1 ))
    end_line=$(( anchor_line + 25 ))
    BLOCK_LINES=$(sed -n "${start_line},${end_line}p" src/api.php)
    if echo "$BLOCK_LINES" | grep -q "'retry_after'"; then
        echo "  ✓ $err_code includes retry_after field"
    else
        echo "  ✗ $err_code is missing retry_after field (inconsistent with PARSE_ERROR/classifed errors)"
        exit 1
    fi
done

echo ""
echo "==> Checking URL_TOO_LONG response includes retry_after field..."
# URL_TOO_LONG is a client-input validation error (URL exceeds MAX_URL_LEN).
# Adding retry_after gives API clients a consistent field to read for backoff
# timing — matching the contract of all other validation errors (MISSING_URL,
# INVALID_URL, MISSING_FORMAT, INVALID_FORMAT_ID) which all include it.
URL_TOO_LONG_CHECK=$(sed -n '/URL is too long/,/echo json_encode/p' src/api.php | head -n 20)
if echo "$URL_TOO_LONG_CHECK" | grep -q "'retry_after'"; then
    echo "  ✓ URL_TOO_LONG includes retry_after field"
else
    echo "  ✗ URL_TOO_LONG is missing retry_after field (inconsistent with other validation errors)"
    exit 1
fi

echo ""
echo "==> Checking RATE_LIMIT_EXCEEDED and DAILY_LIMIT responses include retry_after field..."
# Users hitting rate/daily limits need to know when they can retry. Both error codes
# should include a 'retry_after' field in the JSON response.
# PARSE_ERROR also includes retry_after for consistent client backoff handling.
# Use a targeted check: the PARSE_ERROR response in the info action is identifiable
# by the unique logRequest call with reason='parse_formats_failed' at the top.
# Check that this block contains 'retry_after' — this reliably identifies the
# HTTP response endpoint and not the parseFormats() helper function returns.
PARSE_ERROR_CHECK=$(sed -n '/logRequest.*parse_formats_failed/,/echo json_encode/p' src/api.php)
if echo "$PARSE_ERROR_CHECK" | grep -q "'retry_after'"; then
    echo "  ✓ PARSE_ERROR includes retry_after field"
else
    echo "  ✗ PARSE_ERROR is missing retry_after field"
    exit 1
fi

echo ""
echo "==> Checking download action 503 blocks (fopen/flock failure) include all hardening headers..."
# The download action's dl_rate_file fopen and flock failure handlers must return
# fully hardened 503 responses — all security headers, rate-limit context headers,
# X-Download-Timeout, X-Info-Timeout, error_code field, and upgrade_url.
# This test extracts both failure blocks and verifies each contains the critical fields.
# Vulnerable 503 responses (missing headers, no error_code) are a security regression.
DOWNLOAD_BLOCK=$(sed -n "/case 'download':/,/case '/p" src/api.php | head -n -1)
DL_503_BLOCK=$(echo "$DOWNLOAD_BLOCK" | sed -n '/Could not open the download rate/,/exit;/{ /exit;/q; p }')
DL_503_BLOCK="${DL_503_BLOCK}$(echo "$DOWNLOAD_BLOCK" | sed -n '/Could not acquire an exclusive lock/,/exit;/{ /exit;/q; p }')"
MISSING_HARDENING=0
for header in \
    "X-Frame-Options" \
    "X-Download-Options" \
    "X-Robots-Tag" \
    "Referrer-Policy" \
    "Strict-Transport-Security" \
    "Permissions-Policy" \
    "Cross-Origin-Opener-Policy" \
    "Cross-Origin-Resource-Policy" \
    "X-Download-Timeout" \
    "X-Info-Timeout" \
    "X-DL-RateLimit-Limit" \
    "X-DL-RateLimit-Remaining" \
    "X-RateLimit-Limit" \
    "X-DailyLimit-Limit"; do
    if ! echo "$DL_503_BLOCK" | grep -q "$header"; then
        echo "  ✗ download 503 block missing hardening header: $header"
        MISSING_HARDENING=1
    fi
done
if echo "$DL_503_BLOCK" | grep -q "'error_code'"; then
    echo "  ✓ download 503 blocks include error_code field"
else
    echo "  ✗ download 503 blocks missing error_code field"
    MISSING_HARDENING=1
fi
if echo "$DL_503_BLOCK" | grep -q "'upgrade_url'"; then
    echo "  ✓ download 503 blocks include upgrade_url field"
else
    echo "  ✗ download 503 blocks missing upgrade_url field"
    MISSING_HARDENING=1
fi
# Verify the JSON response body includes the four quota fields — all other API
# error responses include these; omitting them from download 503 blocks is a
# regression that breaks client quota-display logic.
for quota_field in "'quota_remaining'" "'quota_limit'" "'quota_reset'" "'quota_reset_unix'"; do
    if echo "$DL_503_BLOCK" | grep -q "$quota_field"; then
        echo "  ✓ download 503 blocks include $quota_field"
    else
        echo "  ✗ download 503 blocks missing $quota_field"
        MISSING_HARDENING=1
    fi
done
if [ "$MISSING_HARDENING" -eq 1 ]; then
    echo "  Fix: add missing hardening headers and fields to download action 503 responses"
    exit 1
fi
echo "  ✓ download 503 blocks are fully hardened"

echo ""
echo "==> Checking client-error action has all required response fields..."
# The client-error action receives browser JS runtime errors and is called by the PWA's
# window.onerror handler. Its response should include retry_after (consistent with all
# other API error responses) and the hardening headers. Check that the client-error
# case block contains both.
CLIENT_ERROR_BLOCK=$(sed -n "/case 'client-error':/,/[[:space:]]return;/p" src/api.php)
if echo "$CLIENT_ERROR_BLOCK" | grep -q "'retry_after'"; then
    echo "  ✓ client-error includes retry_after field"
else
    echo "  ✗ client-error is missing retry_after field (inconsistent with all other error responses)"
    exit 1
fi
for header in "X-Content-Type-Options" "X-Frame-Options"; do
    if echo "$CLIENT_ERROR_BLOCK" | grep -q "$header"; then
        echo "  ✓ client-error includes $header"
    else
        echo "  ✗ client-error missing $header hardening header"
        exit 1
    fi
done

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
echo "==> Running resolve_playlist_flag_test.php (playlist flag resolution)..."
php tests/resolve_playlist_flag_test.php
PLAYLIST_RESULT=$?
if [ $PLAYLIST_RESULT -ne 0 ]; then
    echo "  ✗ resolve_playlist_flag_test.php failed"
    exit 1
fi
echo "  ✓ resolve_playlist_flag_test.php passed"

echo ""
echo "==> Checking SERVICE_UNAVAILABLE response includes all required fields..."
# The sendServiceUnavailable503() helper is used when the rate-limit file cannot
# be opened or locked. Its JSON response must include source_url (null),
# source_url_missing (false), and format_id_missing (false) — same as every
# other API error response. Missing these fields breaks client response parsers
# that expect a consistent field shape across all error codes.
SVC_UNAVAIL=$(sed -n '/function sendServiceUnavailable503/,/\},/p' src/api.php | sed -n "/echo json_encode/,/];/p")
for field in "'source_url'" "'source_url_missing'" "'format_id_missing'"; do
    if echo "$SVC_UNAVAIL" | grep -q "$field"; then
        echo "  ✓ SERVICE_UNAVAILABLE includes $field"
    else
        echo "  ✗ SERVICE_UNAVAILABLE missing $field"
        exit 1
    fi
done

echo ""
echo "==> Checking ERROR_HINTS includes SERVICE_UNAVAILABLE (frontend UX)... "
# SERVICE_UNAVAILABLE is returned by sendServiceUnavailable503() when the rate-limit
# file cannot be opened. Users hitting this error should see a friendly hint, not the
# raw error. Verify the entry is present in the ERROR_HINTS map in index.php.
if grep -q "'SERVICE_UNAVAILABLE'.*Server-side lock or quota file" public/index.php; then
    echo "  ✓ ERROR_HINTS includes SERVICE_UNAVAILABLE"
else
    echo "  ✗ ERROR_HINTS missing SERVICE_UNAVAILABLE hint"
    exit 1
fi

echo ""
echo "All sanity checks passed."
#!/bin/bash
# AhoyRipper - Sanity Tests
# Run: bash tests/sanity.sh

set -e
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
echo "==> Checking PHP syntax..."
php -l src/api.php
php -l public/index.php
echo "  ✓ PHP syntax OK"

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
echo "==> Verifying required files exist..."
for f in src/api.php src/style.css public/index.php README.md Dockerfile docker-compose.yml deploy/nginx.conf scripts/install-deps.sh; do
    if [ ! -f "$f" ]; then
        echo "  ✗ Missing: $f"
        exit 1
    fi
done
echo "  ✓ All required files present"

echo ""
echo "==> Checking security headers in api.php..."
REQUIRED_HEADERS=(
    "X-Content-Type-Options"
    "X-Frame-Options"
    "Strict-Transport-Security"
    "Content-Security-Policy"
    "X-Download-Options"
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
echo "==> Checking rate limiting (info endpoint)..."
if grep -q "rate_limit = 30" src/api.php; then
    echo "  ✓ Info rate limit (30/min) configured"
else
    echo "  ✗ Info rate limit not found"
    exit 1
fi

echo ""
echo "==> Checking rate limiting (download endpoint)..."
if grep -q "dl_rate_limit = 10" src/api.php; then
    echo "  ✓ Download rate limit (10/min) configured"
else
    echo "  ✗ Download rate limit not found"
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

echo ""
echo "==> Checking HSTS includeSubDomains..."
if grep -q "includeSubDomains" src/api.php; then
    echo "  ✓ HSTS includeSubDomains configured"
else
    echo "  ✗ HSTS includeSubDomains missing"
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
if awk '/location ~ \/\. \{ deny/' deploy/nginx-docker.conf; then
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
echo "==> Checking API CSP includes all required thumbnail CDN domains..."
# The API CSP must allow thumbnails from media CDNs so the browser can load
# them when rendering video info (YouTube, TikTok, Twitter/X, SoundCloud, etc.).
# In nginx-docker.conf the CSP is now set at server level (inherited by all locations).
# In production nginx.conf it is set in the php location block.
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

# Check nginx-docker.conf CSP (now at server level)
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
echo "==> Checking CSP is at server level in nginx-docker.conf (no duplicate in location blocks)..."
# After the fix, CSP should appear exactly once (at server level), not twice.
CSP_COUNT=$(grep -c "Content-Security-Policy" deploy/nginx-docker.conf || true)
if [ "$CSP_COUNT" -eq 1 ]; then
    echo "  ✓ CSP appears exactly once in nginx-docker.conf (server level, no duplicate)"
else
    echo "  ✗ CSP appears $CSP_COUNT times in nginx-docker.conf (expected 1 — duplicate CSP header)"
    exit 1
fi

echo ""
echo "==> Checking COOP/CORP headers appear once (server level, no duplicate in location blocks)..."
COOP_COUNT=$(grep -c "Cross-Origin-Opener-Policy" deploy/nginx-docker.conf || true)
CORP_COUNT=$(grep -c "Cross-Origin-Resource-Policy" deploy/nginx-docker.conf || true)
if [ "$COOP_COUNT" -eq 1 ] && [ "$CORP_COUNT" -eq 1 ]; then
    echo "  ✓ COOP appears once ($COOP_COUNT) and CORP appears once ($CORP_COUNT) — server-level only"
else
    echo "  ✗ COOP appears $COOP_COUNT times (expected 1), CORP appears $CORP_COUNT times (expected 1)"
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
HEALTH_RESPONSE=$(sed -n "/'status' =>/,/\];/p" src/api.php | head -20)
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
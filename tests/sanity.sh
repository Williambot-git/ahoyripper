#!/bin/bash
# AhoyRipper - Sanity Tests
# Run: bash tests/sanity.sh

set -e

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
echo "==> Checking format_id validation..."
if grep -q "preg_match.*format_id" src/api.php; then
    echo "  ✓ format_id validation present"
else
    echo "  ✗ format_id validation not found"
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
echo "==> Checking download exit-code error handling..."
if grep -q "actual_exit" src/api.php; then
    echo "  ✓ Download exit-code validation present"
else
    echo "  ✗ Download exit-code validation not found"
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
echo "==> Checking Docker nginx.conf CSP allows external media thumbnails..."
# Docker nginx.conf CSP must allow thumbnails from the same CDN domains
# as the production nginx.conf to prevent broken thumbnails in Docker deploys.
DOCKER_CSP=$(grep "Content-Security-Policy" deploy/nginx-docker.conf | sed "s/.*Content-Security-Policy[ ]*//")
for domain in "i.ytimg.com" "pbs.twimg.com" "sndcdn.com" "vimeocdn.com" "instagram.com" "fbcdn.net"; do
    if ! echo "$DOCKER_CSP" | grep -q "$domain"; then
        echo "  ✗ Docker CSP missing thumbnail domain: $domain"
        exit 1
    fi
done
echo "  ✓ Docker CSP allows all required media thumbnail domains"

echo ""
echo "==> Checking API key input styling (rip-key-input class)..."
if grep -q "rip-key-input" src/style.css; then
    echo "  ✓ .rip-key-input styling present"
else
    echo "  ✗ .rip-key-input styling missing"
    exit 1
fi

echo ""
echo "All sanity checks passed."
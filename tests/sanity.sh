#!/bin/bash
# AhoyRipper - Sanity Tests
# Run: bash tests/sanity.sh

set -e

echo "==> Checking PHP syntax..."
php -l src/api.php
php -l public/index.php
echo "  ✓ PHP syntax OK"

echo ""
echo "==> Checking yt-dlp flags in source..."
if grep -q -- '--no-warnings' src/api.php; then
    echo "  ✗ Deprecated --no-warnings flag found (use --no-warning instead)"
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
echo "==> Checking download exit-code error handling..."
if grep -q "actual_exit" src/api.php; then
    echo "  ✓ Download exit-code validation present"
else
    echo "  ✗ Download exit-code validation not found"
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
echo "All sanity checks passed."
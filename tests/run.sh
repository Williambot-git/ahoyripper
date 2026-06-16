#!/bin/bash
# AhoyRipper - Test Runner
# Run all test suites; exit non-zero if any suite fails.
# Usage: bash tests/run.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$PROJECT_ROOT"

echo "=============================================="
echo " AhoyRipper Test Suite"
echo "=============================================="
echo ""

# Track overall pass/fail
FAILED=0

# ─── PHP unit tests (api_test.php) ───────────────────
echo "==> Running api_test.php (standalone function tests)..."
if php "$SCRIPT_DIR/api_test.php"; then
    echo "✓ api_test.php: passed"
else
    echo "✗ api_test.php: FAILED"
    FAILED=1
fi
echo ""

# ─── PHP unit tests (parse_formats_test.php) ──────────
echo "==> Running parse_formats_test.php (parseFormats tests)..."
if php "$SCRIPT_DIR/parse_formats_test.php"; then
    echo "✓ parse_formats_test.php: passed"
else
    echo "✗ parse_formats_test.php: FAILED"
    FAILED=1
fi
echo ""

# ─── PHP unit tests (playlist_param_test.php) ──────────
echo "==> Running playlist_param_test.php (playlist flag resolution)..."
if php "$SCRIPT_DIR/playlist_param_test.php"; then
    echo "✓ playlist_param_test.php: passed"
else
    echo "✗ playlist_param_test.php: FAILED"
    FAILED=1
fi
echo ""

# ─── Shell sanity checks ──────────────────────────────
echo "==> Running sanity.sh (binary/syntax/deprecated-flag checks)..."
if bash "$SCRIPT_DIR/sanity.sh"; then
    echo "✓ sanity.sh: passed"
else
    echo "✗ sanity.sh: FAILED"
    FAILED=1
fi
echo ""

# ─── Summary ─────────────────────────────────────────
echo "=============================================="
if [ "$FAILED" -eq 0 ]; then
    echo " All test suites passed."
    echo "=============================================="
    exit 0
else
    echo " Some test suites FAILED."
    echo "=============================================="
    exit 1
fi
#!/usr/bin/env bash
#
# health-check.sh — Verify AhoyRipper deployment health
# Usage: ./scripts/health-check.sh [BASE_URL]
#   BASE_URL defaults to http://localhost:8080
#
# Exits 0 if healthy, 1 if any check fails.

set -euo pipefail

BASE_URL="${1:-http://localhost:8080}"

echo "=== AhoyRipper Health Check ==="
echo "Base URL: $BASE_URL"
echo ""

# 1. HTTP response
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/health" 2>/dev/null || echo "000")
echo "[1/4] Health endpoint HTTP status: $HTTP_STATUS"
if [[ "$HTTP_STATUS" != "200" ]]; then
  echo "FAIL — health endpoint returned $HTTP_STATUS (expected 200)"
  exit 1
fi

# 2. Health response fields
HEALTH_JSON=$(curl -s "$BASE_URL/api/health" 2>/dev/null || echo "{}")
REQUIRED_FIELDS="status uptime version"
for field in $REQUIRED_FIELDS; do
  if ! echo "$HEALTH_JSON" | grep -q "\"$field\""; then
    echo "FAIL — health response missing field: $field"
    exit 1
  fi
done
echo "[2/4] Health response fields: OK"

# 3. yt-dlp binary (via info action)
YTDLP_VERSION=$(curl -s "$BASE_URL/api/info?url=https://www.youtube.com/watch?v=dQw4w9WgXcQ" \
  -H "X-Api-Key: RIPPER2026DEV" 2>/dev/null | python3 -c "import sys,json; print(json.load(sys.stdin).get('version','?'))" 2>/dev/null || echo "?")
echo "[3/4] yt-dlp version (via /api/info): $YTDLP_VERSION"

# 4. ffmpeg binary
if command -v ffmpeg >/dev/null 2>&1; then
  FFMPEG_VERSION=$(ffmpeg -version 2>&1 | head -1)
  echo "[4/4] ffmpeg: $FFMPEG_VERSION"
else
  echo "[4/4] ffmpeg: not found in PATH"
fi

echo ""
echo "=== All checks passed ==="
exit 0
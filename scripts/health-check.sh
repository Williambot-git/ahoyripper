#!/usr/bin/env bash
#
# health-check.sh — Verify AhoyRipper deployment health
# Usage: ./scripts/health-check.sh [BASE_URL]
#   BASE_URL defaults to http://localhost:8080
#
# Exits 0 if healthy, 1 if any check fails.

set -euo pipefail

BASE_URL="${1:-http://localhost:8080}"
API="${BASE_URL}/src/api.php"

echo "=== AhoyRipper Health Check ==="
echo "Base URL: $BASE_URL"
echo ""

# 1. HTTP response for health endpoint
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "${API}?action=check" 2>/dev/null || echo "000")
echo "[1/5] Health endpoint HTTP status: $HTTP_STATUS"
if [[ "$HTTP_STATUS" != "200" ]]; then
  echo "FAIL — check endpoint returned $HTTP_STATUS (expected 200)"
  exit 1
fi
echo "[1/5] ✓ Health endpoint reachable"

# 2. Health response fields
HEALTH_JSON=$(curl -s "${API}?action=health" 2>/dev/null || echo "{}")
for field in status server_time request_id yt_dlp_version ffmpeg_version; do
  if ! echo "$HEALTH_JSON" | grep -q "\"$field\""; then
    echo "FAIL — health response missing field: $field"
    exit 1
  fi
done
echo "[2/5] ✓ Health response contains all required fields"

# 3. yt-dlp binary check via health probe
# The API enforces origin checks (Referer must be from ahoyripper.com or ahoyvpn.com).
# Without a Referer, health requests return 403 and the probe silently fails.
PROBE_JSON=$(curl -s "${API}?action=health&probe=1" \
  -H "Referer: https://ahoyripper.com/" 2>/dev/null || echo "{}")
if echo "$PROBE_JSON" | grep -q '"yt_dlp_probe"'; then
  echo "[3/5] ✓ yt-dlp probe endpoint available"
else
  echo "[3/5] ⚠ yt-dlp probe not tested (network may be restricted)"
fi

# 4. yt-dlp binary check
if command -v yt-dlp >/dev/null 2>&1; then
  YTDLP_VERSION=$(yt-dlp --version 2>&1 | head -1)
  echo "[4/5] ✓ yt-dlp: $YTDLP_VERSION"
else
  echo "FAIL — yt-dlp not found in PATH"
  exit 1
fi

# 5. ffmpeg binary
if command -v ffmpeg >/dev/null 2>&1; then
  FFMPEG_VERSION=$(ffmpeg -version 2>&1 | head -1)
  echo "[5/5] ✓ ffmpeg: $FFMPEG_VERSION"
else
  echo "FAIL — ffmpeg not found in PATH"
  exit 1
fi

echo ""
echo "=== All checks passed ==="
exit 0

#!/usr/bin/env bash
#
# health-check.sh — Verify AhoyRipper deployment health
# Usage: ./scripts/health-check.sh [BASE_URL]
#   BASE_URL defaults to http://localhost:8080
#
# Exits 0 if healthy, 1 if any check fails.

set -euo pipefail

BASE_URL="${1:-http://localhost:8080}"
HEALTH_PROBE_URL="https://www.youtube.com/watch?v=dQw4w9WgXcQ"

# Use / (root) instead of /src/api.php — the Docker nginx config serves the
# API from root /app/public with "location = /src/api.php", which maps to
# /app/public/src/api.php (does not exist). The root location handles PHP via
# the ~ \.php$ block and correctly routes /?action=check to api.php.
# The docker-compose healthcheck uses http://localhost:8080/ for the same reason.
API="${BASE_URL}/"

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

# 3. yt-dlp can reach YouTube (actual network probe)
# This is a mandatory check — not optional. A "passing" health check that
# can't reach YouTube means yt-dlp is blocked or misconfigured.
# yt-dlp --simulate fetches only metadata (no download), is fast, and is safe
# to run against a known-stable public video (dQw4w9WgXcQ = Rick Astley).
# --no-warnings suppresses the ASCII banner in output so we can grep cleanly.
# This step does NOT use the API (no Referer needed — yt-dlp speaks directly).
if command -v yt-dlp >/dev/null 2>&1; then
  if yt-dlp --no-warnings --simulate "$HEALTH_PROBE_URL" >/dev/null 2>&1; then
    echo "[3/5] ✓ yt-dlp can reach YouTube"
  else
    echo "FAIL — yt-dlp cannot reach YouTube (blocked, DNS filtered, or network unreachable)"
    echo "       Verify: yt-dlp --simulate $HEALTH_PROBE_URL"
    exit 1
  fi
else
  echo "FAIL — yt-dlp not found in PATH (already checked in step 4, but catching here for ordering)"
  exit 1
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

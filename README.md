# AhoyRipper

| **Rip any video, anywhere.** A free, no-signup media converter that pulls video and audio from YouTube, X/Twitter, SoundCloud, TikTok, Instagram, Facebook, Vimeo, and 1872+ other platforms. |

Built on [yt-dlp](https://github.com/yt-dlp/yt-dlp), styled to match the AhoyVPN brand.

---

## Features

- **No signup, no tracking, no ads in the rip flow**
- MP4, WEBM, MP3, M4A, WAV, FLAC, OGG and more
- YouTube, X (Twitter), SoundCloud, TikTok, Instagram, Facebook, Vimeo + 1872+ platforms
- Dark theme matching ahoyvpn.com
- Files streamed directly to your download - nothing stored on our servers
- Built-in AhoyVPN upsell (supports the tool)

---

## Supported Platforms

AhoyRipper is powered by [yt-dlp](https://github.com/yt-dlp/yt-dlp) and supports **1872+ platforms**. A comprehensive table of all major platforms with type labels and notes is in [the reference section below](#supported-platforms-reference). Platform-specific error codes (`AGE_RESTRICTED`, `GEOBLOCKED`, `PRIVATE_VIDEO`, `LOGIN_REQUIRED`, `UNSUPPORTED_SITE`, etc.) and per-platform troubleshooting tips are also documented there.

For the full extractor list:

```bash
yt-dlp --list-extractors
```

---

```bash
# 1. Clone the repo
git clone https://github.com/Williambot-git/ahoyripper.git /var/www/ahoyripper
cd /var/www/ahoyripper

# 2. Run the installer (needs root)
sudo bash scripts/install-deps.sh

# 3. Configure nginx
sudo cp deploy/nginx.conf /etc/nginx/sites-available/ahoyripper
sudo ln -s /etc/nginx/sites-available/ahoyripper /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx

# 4. Set permissions
sudo chown -R www-data:www-data /var/www/ahoyripper

# 5. Configure HTTPS (required)
# AhoyRipper requires HTTPS. Choose one of the following options:

# Option A — Certbot (Let's Encrypt) — recommended for production
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d ahoyripper.com -d www.ahoyripper.com
# Certbot auto-renews and configures TLS in the nginx config.
# Restart nginx after certbot: sudo systemctl reload nginx

# Option B — Cloudflare (or any reverse proxy/CDN in front of the server)
# If Cloudflare proxies traffic to your server, enable "Full (strict)" TLS
# in the Cloudflare dashboard. Your origin server does not need a certificate.
# Ensure nginx listens on port 80 only (no TLS config needed) and Cloudflare
# adds the X-Forwarded-Proto: https header so the application detects HTTPS.

# Option C — Self-signed (testing only)
# Generate a self-signed certificate (browsers will show a warning):
sudo apt install openssl
sudo mkdir -p /etc/nginx/ssl
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/nginx/ssl/ahoyripper.key \
    -out /etc/nginx/ssl/ahoyripper.crt
# Then add the following to /etc/nginx/sites-available/ahoyripper inside the
# `server {` block, before the `location /` block:
#
#     listen 443 ssl http2;
#     listen [::]:443 ssl http2;
#     ssl_certificate /etc/nginx/ssl/ahoyripper.crt;
#     ssl_certificate_key /etc/nginx/ssl/ahoyripper.key;
#     ssl_protocols TLSv1.2 TLSv1.3;
#     ssl_prefer_server_ciphers on;
#     ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
#
# Keep the existing `listen 80` block for ACME certificate challenges (Option A).

# 6. Run tests (optional but recommended after updates)
bash tests/run.sh
```

---

## Docker

```bash
# Clone and configure
git clone https://github.com/Williambot-git/ahoyripper.git /var/www/ahoyripper
cd /var/www/ahoyripper

# Set a secure API key — the default key is only for local dev
# Generate one with: openssl rand -hex 32
echo "AHOY_UNLIMITED_KEY=your-generated-key" > .env

# Start the app (app runs at http://localhost:8080)
# AHOY_UNLIMITED_KEY must be set — generate one with: openssl rand -hex 32
docker compose up -d
```

### Environment Variables (Docker)

| Variable | Default | Description |
|----------|---------|-------------|
| `AHOY_USER_AGENT` | `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36` | Custom User-Agent string for yt-dlp requests. yt-dlp defaults to `python-requests/X.Y.Z` which is trivially blocked by anti-bot measures — this overrides it with a modern Chrome UA. Override via `AHOY_USER_AGENT` env var in docker-compose or cloud dashboard to mimic a different browser. |
| `AHOY_UNLIMITED_KEY` | `RIPPER2026DEV` | API key granting unlimited daily quota. **Change this in production** — generate a secure value with `openssl rand -hex 32`. |
| `QUOTA_DAILY` | `5` | Daily rip limit for unauthenticated users. Set to a positive integer to increase or decrease the free quota. `-1` or `0` effectively disables the free tier (users must provide a valid `AHOY_UNLIMITED_KEY`). |
| `YTDLP_TIMEOUT` | `45` | Per-request timeout (seconds) for yt-dlp metadata/info operations. Covers the initial metadata fetch and format list retrieval. Increase if the source site is slow to respond or if fetching info for very long videos (e.g. multi-hour livestreams) times out. |
| `YTDLP_DOWNLOAD_TIMEOUT` | `300` | Per-request timeout (seconds) for yt-dlp download operations (the actual media file transfer). The default 300s (5 min) accommodates large files on slow connections. Decrease in resource-constrained environments; increase for high-quality 4K/8K downloads on fast connections. |
| `YTDLP_PATH` | `/usr/local/bin/yt-dlp` | Path to the yt-dlp binary. Override to use a custom-built yt-dlp or a different installation path (e.g. `/usr/bin/yt-dlp`). Changing this also invalidates the yt-dlp version cache so the new binary is probed on the next request. |
| `FFPROBE_PATH` | `/usr/bin/ffprobe` | Path to the ffprobe binary used for post-download codec/resolution verification. Override to use a custom ffprobe path (e.g. `/usr/local/bin/ffprobe`). Changing this also invalidates the ffmpeg version cache. |

All environment variables are read from the `.env` file in the project root (created above). To update a value after the container is running, edit `.env` and restart:

```bash
docker compose down && docker compose up -d
```

> **Security note:** The default key `RIPPER2026DEV` is only suitable for local development. Never deploy with it in production — anyone who knows it gets unlimited quota.

---

## Tech Stack

- **Engine:** yt-dlp + ffmpeg
- **Web layer:** PHP 8.x
- **Frontend:** Vanilla JS + CSS (no framework)
- **Server:** Nginx + PHP-FPM

---

## File Structure

```
ahoyripper/
├── public/                      # Web root (served by nginx)
│   ├── index.php               # Main page
│   ├── manifest.json           # PWA manifest (installable web app)
│   ├── sw.js                    # Service worker (PWA offline support)
│   ├── robots.txt               # SEO + AI-crawler blocking
│   ├── 404.html                 # Custom 404 error page
│   ├── sitemap.xml              # XML sitemap for search engines
│   ├── opensearch.xml           # OpenSearch description (browser search)
│   ├── og-image.png             # Open Graph social share image
│   ├── og-image.svg             # SVG source for og-image
│   ├── favicon.ico              # Favicon (legacy browsers)
│   ├── favicon.svg              # Favicon (vector)
│   ├── favicon-180.png          # Apple Touch icon (180×180)
│   ├── favicon-512.png          # PWA icon (512×512)
│   ├── favicon-512.svg          # SVG source for PWA icon
│   ├── AhoyMonthly.png          # Brand art
│   ├── AhoyMonthly_transparent.png
│   └── .well-known/
│       └── security.txt         # RFC 9116 security contact
├── src/
│   ├── api.php                  # yt-dlp API (info, download, health)
│   └── style.css                # CSS (AhoyVPN dark theme)
├── deploy/
│   ├── nginx.conf               # Nginx config (production, VPS)
│   └── nginx-docker.conf        # Nginx config (Docker standalone)
├── scripts/
│   ├── install-deps.sh          # Dependency installer + yt-dlp updater
│   ├── health-check.sh          # Deployment health verification script
│   └── generate-sw-version.php  # PWA SW cache version generator
├── tests/
│   ├── run.sh                   # Unified test runner (runs all suites)
│   ├── sanity.sh                # Shell-based sanity / regression checks
│   ├── api_test.php             # Unit tests for standalone API functions
│   └── parse_formats_test.php   # Unit tests for parseFormats()
├── .env.example                 # Environment variable template (Docker)
├── .dockerignore                # Docker build context exclusions
├── CHANGELOG.md                 # Project version history
├── docker-compose.yml           # Docker Compose configuration
├── Dockerfile                   # Docker image definition
├── README.md                    # This file
└── LICENSE                      # GPL-3.0 license
```

> **Note:** `robots.txt` lives in `public/` and is also served at the root by nginx — do not place a separate `robots.txt` at the project root.

---

## API

### Get video info + formats
```
GET /src/api.php?action=info&url=<url>&sort=<height|filesize|filesize_asc|tbr|quality>&playlist=<0|1>&key=<api_key>
```

**Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `url` | — | **(required)** URL of the video to rip |
| `sort` | `height` | Format sort order — see table below |
| `playlist` | `0` | Set to `1` to fetch info for all videos in a playlist (yt-dlp `--yes-playlist`). Each video counts as a separate rip. |
| `key` | — | AhoyVPN unlimited API key to bypass the daily 5-rip quota |

The `sort` parameter (optional, default `height`) controls format sort order:
- `height` — quality, highest resolution first (default)
- `filesize` — estimated file size, largest first
- `filesize_asc` — estimated file size, smallest first
- `tbr` — bitrate, highest first
- `quality` — quality tier, highest first (video = pixel height, e.g. 1080p > 720p > 480p; audio = bitrate tier, e.g. 320kbps > 256kbps > 192kbps)

The `label` field is a compact shorthand (e.g. `"720p60 mp4"`). The `format_description` field provides richer human-readable context from yt-dlp (e.g. `"1280x720 720p60 HDR 10bit"`) — use this for display when available. The `format_type` field distinguishes `"combined"` (video+audio), `"video"` (video-only), and `"audio"` (audio-only) formats. The `platform` field surfaces yt-dlp's extractor name (e.g. `"YouTube"`, `"Twitter"`, `"TikTok"`) so API consumers can confirm which platform the URL was routed to.

The `source_url` field in the info response is the exact URL that was ripped — it is always the URL you passed, included so API consumers can match a response back to the source link. `source_url` is also included in error responses so clients can correlate failures with the original request. The `yt_dlp_version` field reports the version of yt-dlp installed on the server (e.g. `"2026.06.02"`), useful for debugging format availability on older extractors. It is present on all responses including error responses, so clients can correlate errors with a specific yt-dlp build. `uploader_url` is the URL to the channel/uploader page as reported by yt-dlp (e.g. a YouTube channel URL), or `null` when not available from the source.

**Success response:**
```json
{
  "request_id": "a3f1b2c9d4e5f678",
  "title": "Video Title",
  "thumbnail": "https://...",
  "duration": 180,
  "uploader": "Channel Name",
  "uploader_url": "https://www.youtube.com/channel/...",
  "platform": "YouTube",
  "derived_filename": "Video_Title",
  "formats": [
    {
      "id": "22",
      "label": "720p60 HDR mp4",
      "description": "1280x720 720p60 HDR 10bit",
      "ext": "mp4",
      "filesize_mb": 45.2,
      "height": 720,
      "quality": 720,
      "fps": 60,
      "tbr": 2500,
      "abr": null,
      "vcodec": "avc1.64001F",
      "acodec": "mp4a.40.2",
      "format_type": "combined",
      "type_group": 0,
      "language": null
    },
    {
      "id": "140",
      "label": "128kbps m4a",
      "description": "Audio m4a",
      "ext": "m4a",
      "filesize_mb": 2.0,
      "height": null,
      "quality": 128,
      "fps": null,
      "tbr": 128,
      "abr": 128,
      "vcodec": "none",
      "acodec": "mp4a.40.2",
      "format_type": "audio",
      "type_group": 2,
      "language": null
    }
  ],
  "sort_applied": "height",
  "source_url": "https://www.youtube.com/watch?v=...",
  "yt_dlp_version": "2026.03.17"
}
```

> **Note:** `api_version` appears only on `action=check` responses (the minimal ping endpoint). It is not present on `action=info` or other endpoints.



The `abr` (audio bitrate, in kbps) is present on audio-only formats (`format_type: "audio"`) and `null` on video formats. The `tbr` (total bitrate, in kbps) is available on most formats and can be used as a proxy for quality when `height` is not available.

**Error codes:**

| Code | Meaning |
|------|---------|
| `400` | Malformed request — missing or invalid URL (`MISSING_URL`, `INVALID_URL`), or missing format on download (`MISSING_FORMAT`) |
| `401` | Invalid API key (`INVALID_KEY`) |
| `403` | Request blocked — must originate from ahoyripper.com or ahoyvpn.com (`FORBIDDEN_ORIGIN`) |
| `405` | Method not allowed — API accepts GET only (`METHOD_NOT_ALLOWED`) |
| `406` | Not acceptable — JSON requested (`NOT_ACCEPTABLE`) |
| `422` | URL could not be fetched, parsed, or is unsupported — also returned for geo-blocked, private, copyrighted, or login-required content. See the `error_code` field for detail. |
| `429` | Rate limit exceeded — see `Retry-After` header and `upgrade_url` in response body. See classified error codes below. |
| `502` | Bad gateway — source site or proxy failed (`CONNECTION_FAILED`, `SSL_ERROR`) |
| `503` | Service temporarily unavailable |
| `504` | Gateway timeout — source site did not respond in time (`SOURCE_TIMEOUT`) |

**Classified error codes** (surfaced in the `error_code` field of 422 responses):

| error_code | Meaning | User action |
|------------|---------|-------------|
| `MISSING_URL` | No URL was provided on the request | Paste a valid link from YouTube, Twitter, TikTok, SoundCloud, Instagram, etc. |
| `MISSING_FORMAT` | No format was selected on a download request | Select a format from the list above first |
| `INVALID_FORMAT_ID` | The format ID was rejected as invalid | Refresh to get a fresh format list, then pick a valid format from the list |
| `RATE_LIMIT_EXCEEDED` | Too many requests — rate limit exceeded | Wait a minute and try again, or upgrade to an unlimited API key |
| `INVALID_KEY` | The API key is invalid or malformed | Use a valid AhoyVPN unlimited key, or leave blank for the free tier |
| `DAILY_LIMIT` | Daily free quota (5 rips/day) has been exhausted | Quota resets at midnight UTC. Get AhoyVPN for unlimited rips |
| `FORBIDDEN_ORIGIN` | Request did not originate from ahoyripper.com or ahoyvpn.com | Requests must come from the AhoyRipper web page — direct API calls are not allowed |
| `GEOBLOCKED` | Video is geo-restricted in your region | Use AhoyVPN to route through an unblocked region |
| `PRIVATE_VIDEO` | Video is private and cannot be downloaded | Try a public video instead |
| `LOGIN_REQUIRED` | Video requires login or subscription on the source platform | Try downloading while signed in to the platform |
| `PARSE_ERROR` | The site returned a non-standard or unparseable response | The site may be temporarily unavailable or not supported |
| `UNSUPPORTED_SITE` | The site is not supported by yt-dlp | Check the supported sites list at github.com/yt-dlp/yt-dlp |
| `PLAYLIST_MISSING` | Playlist not found or no longer exists | Verify the playlist is public and still available |
| `COPYRIGHT_REMOVED` | Content removed due to a copyright claim | This content cannot be redistributed |
| `VIDEO_UNAVAILABLE` | Video has been removed, delisted, or is no longer available | Try another video |
| `AGE_RESTRICTED` | Video is age-restricted and requires verification | Sign in to the source platform to verify your age |
| `SOURCE_RATE_LIMITED` | The source site is rate-limiting requests | Try again in a few minutes |
| `SOURCE_FORBIDDEN` | The source site blocked this request (HTTP 403) | Try a different format or use AhoyVPN to change your exit IP |
| `SOURCE_NOT_FOUND` | The source returned HTTP 404 — the content may have been moved or deleted | Try another video or source |
| `SOURCE_SERVER_ERROR` | The source site returned HTTP 5xx and is having issues | Try again shortly |
| `SOURCE_HTTP_ERROR` | The source site returned an unexpected HTTP error | Try again shortly |
| `SOURCE_TIMEOUT` | The source site took too long to respond | Try a smaller format (audio-only is fastest) or try again when the site is less busy |
| `SSL_ERROR` | Secure connection to the source failed | Try again shortly |
| `CONNECTION_FAILED` | Could not connect to the source | Check your network and try again |
| `FILE_TOO_LARGE` | File exceeds the server's maximum size | Try audio-only or a lower resolution |
| `FORMAT_UNAVAILABLE` | That format is not available for this video | Choose another from the list |
| `DISALLOWED_CONTENT` | Content not available due to a terms of service violation | This content cannot be redistributed |
| `YTDLP_ERROR` | General yt-dlp error (see `raw_error` field for detail) | Try another format from the list, or wait and try again |
| `DOWNLOAD_EMPTY` | The downloaded file was empty or invalid. Try another format from the list. |
| `DOWNLOAD_CANCELLED` | Download was cancelled — tab closed or connection lost mid-transfer. Your daily quota was not charged. |
| `DOWNLOAD_TIMEOUT` | Download exceeded the 5-minute server timeout. Try a smaller format or lower resolution. |
| `UNKNOWN_ACTION` | The requested action is not recognized | Use `info`, `download`, `health`, or `progress` |

### Download a format
```
GET /src/api.php?action=download&url=<url>&format=<format_id>&filename=<name>&playlist=<0|1>
Authorization: Bearer ***
```

The `format_id` comes from the `id` field in the info response. The API reads the key from the `Authorization: Bearer` header (preferred — keeps the key out of URLs and server logs). A `key` query parameter is also accepted for backwards compatibility but is discouraged.

The `filename` param (optional) sets the downloaded file's name. Only alphanumeric, spaces, dots, underscores, and hyphens are allowed; everything else is stripped. Falls back to `ahoyrip.<ext>` if omitted or empty.

> **Note:** The free tier allows 5 total rips per day (each call to the info or download API counts as one rip). Switching the sort order re-fetches the format list and counts as an additional rip. Unlimited-key holders have no daily cap.

**Download error response (422 with classified error):**
```json
{
  "error": "This video is geo-restricted in your region.",
  "error_code": "GEOBLOCKED",
  "request_id": "a3f1b2c9d4e5f678",
  "source_url": "https://www.youtube.com/watch?v=...",
  "raw_error": "ERROR: [youtube] NGeR...: This video is available in United States."
}
```

**Download error response (422 with unclassified yt-dlp error):**
```json
{
  "error": "Download failed: requested format not available.",
  "error_code": "YTDLP_ERROR",
  "request_id": "a3f1b2c9d4e5f678",
  "source_url": "https://www.youtube.com/watch?v=...",
  "raw_error": "ERROR: [youtube] NGeR...: requested format not available"
}
```

**Download error responses** (any of these may be returned — from pre-rip validation failures like missing URLs/keys, to source-site errors like geo-blocking or timeouts, through to rip-time failures like empty files or cancelled transfers):

| Code | `error_code` | Meaning |
|------|--------------|---------|
| `401` | `INVALID_KEY` | The API key is invalid or malformed. Use a valid AhoyVPN unlimited key, or leave blank for the free tier. |
| `403` | `FORBIDDEN_ORIGIN` | Request did not originate from ahoyripper.com or ahoyvpn.com. Requests must come from the AhoyRipper web page. |
| `422` | `MISSING_URL` | No URL was provided on the download request. |
| `422` | `MISSING_FORMAT` | No format was selected on the download request. |
| `422` | `INVALID_FORMAT_ID` | The format ID was rejected as invalid — refresh to get a fresh format list, then pick a valid format from the list. |
| `429` | `DAILY_LIMIT` | Daily free quota (5 rips/day) has been exhausted. Quota resets at midnight UTC. |
| `422` | `GEOBLOCKED` | Video is geo-restricted in your region |
| `403` | `AGE_RESTRICTED` | Video is age-restricted and requires verification on the source platform |
| `403` | `PRIVATE_VIDEO` | Video is private and cannot be downloaded |
| `401` | `LOGIN_REQUIRED` | Video requires login or subscription |
| `422` | `COPYRIGHT_REMOVED` | Content removed due to a copyright claim |
| `404` | `UNSUPPORTED_SITE` | The site is not supported by yt-dlp |
| `404` | `PLAYLIST_MISSING` | Playlist not found or no longer exists |
| `422` | `VIDEO_UNAVAILABLE` | Video has been removed, delisted, or is no longer available |
| `429` | `SOURCE_RATE_LIMITED` | The source site is rate-limiting requests |
| `403` | `SOURCE_FORBIDDEN` | The source site blocked this request (HTTP 403) — try a different format or use AhoyVPN |
| `404` | `SOURCE_NOT_FOUND` | The source returned HTTP 404 — the content may have been moved or deleted |
| `502` | `SOURCE_SERVER_ERROR` | The source site returned HTTP 5xx and is having issues |
| `502` | `SOURCE_HTTP_ERROR` | The source site returned an unexpected HTTP error |
| `502` | `SSL_ERROR` | SSL/TLS error when connecting to the source — try again or use AhoyVPN |
| `504` | `SOURCE_TIMEOUT` | The source site timed out — try a smaller format or audio-only |
| `502` | `CONNECTION_FAILED` | Could not connect to the source |
| `413` | `FILE_TOO_LARGE` | File exceeds the server's maximum size |
| `422` | `FORMAT_UNAVAILABLE` | That format is not available for this video |
| `422` | `DISALLOWED_CONTENT` | Content is not available due to a terms of service violation |
| `422` | `YTDLP_ERROR` | General yt-dlp error (see `raw_error` field) |
| `500` | `PROC_OPEN_FAILED` | Server error — could not start the download process. Try again shortly. |
| `422` | `PARSE_ERROR` | Could not fetch video info during download. The site may be temporarily unavailable. |
| `504` | `DOWNLOAD_TIMEOUT` | Download exceeded the 5-minute server timeout — try a smaller format or audio-only |
| `500` | `DOWNLOAD_EMPTY` | The downloaded file was empty or invalid — try another format from the list |
| `499` | `DOWNLOAD_CANCELLED` | Download was cancelled — tab closed or connection lost mid-transfer. Your daily quota was not charged. Try again when ready. |

### Health check / progress
```
GET /src/api.php?action=check          # lightweight internal ping (Docker healthcheck-safe)
GET /src/api.php?action=health         # full system status with resource metrics
GET /src/api.php?action=health&probe=1 # include live yt-dlp connectivity probe
GET /src/api.php?action=progress       # alias for health (legacy)
POST /src/api.php?action=csp-report     # CSP violation report receiver (nginx report-uri)
```

`action=check` is a minimal ping with zero server overhead — no dependency on yt-dlp, ffmpeg, or /proc/sys calls. It returns instantly and is safe to call every 10 seconds. Use it for Docker healthchecks and load-balancer probes:

```json
{
  "status": "ok",
  "server_time": "2026-05-21T16:00:00+00:00",
  "request_id": "a3f1b2c9d4e5f678",
  "app_version": "1.0.0",
  "php_version": "8.2.0",
  "api_version": "1.0.0"
}
```

`php_version` and `api_version` are only present on `action=check` (a minimal ping endpoint) — they are omitted from `action=health` to keep that response focused on system-resource metrics. `app_version` is present on both.

`action=health` returns full system status:
```
{
  "status": "ok",
  "server_time": "2026-05-21T16:00:00+00:00",
  "request_id": "a3f1b2c9d4e5f678",
  "app_version": "1.0.0",
  "os": "Linux",
  "yt_dlp_version": "2026.03.17",
  "ffmpeg_version": "ffmpeg version 6.x",
  "yt_dlp_ok": true,
  "ffmpeg_ok": true,
  "yt_dlp_cache_expires_at": "2026-05-21T17:00:00+00:00",
  "yt_dlp_cache_ttl_seconds": 542,
  "ffmpeg_cache_expires_at": "2026-05-21T17:00:00+00:00",
  "ffmpeg_cache_ttl_seconds": 542,
  "server_uptime_seconds": 86400,
  "yt_dlp_probe": { "ok": true, "title": "Rick Astley - Never Gonna Give You Up (Official Music Video)", "source_url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ" },
  "load_avg": [0.15, 0.08, 0.05],
  "memory_available_pct": 72.4,
  "disk_free_gb": 48.2
}
```

`server_uptime_seconds` is Linux-only — available on servers, omitted in Docker containers or non-Linux environments.

`yt_dlp_probe` is only present when the request includes `&probe=1`. It runs a lightweight metadata fetch against a known-stable YouTube video to confirm end-to-end connectivity and parsing capability. The result is cached for 5 minutes.

The `source_url` field in the probe result shows the URL that was used for the connectivity check (`https://www.youtube.com/watch?v=dQw4w9WgXcQ`). When `ok` is `true`, `title` contains the video title (truncated to 80 characters). When `ok` is `false`, `error` contains the yt-dlp error message and `source_url` still shows which URL failed.

`load_avg` requires Linux. `memory_available_pct` reads `/proc/meminfo`. `disk_free_gb` uses `disk_free_space()`. Cache fields reflect internal version-caching TTLs (1 hour).

`action=csp-report` receives [Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP) violation reports from browsers. Nginx is configured with a `report-uri /src/api.php?action=csp-report` directive in the CSP-Report-Only header, so violations (e.g., mixed content, inline script attempts) are logged to `error_log` rather than silently ignored. The report body is sanitized before logging — video URLs and referrers are omitted. This endpoint returns `200 OK` to all POST requests so browsers do not retry.

### Rate Limits

| Endpoint | Limit | Window |
|----------|-------|--------|
| `/src/api.php?action=info` | 30 requests | 60 seconds |
| `/src/api.php?action=download` | 10 requests | 60 seconds |

Response headers on every API response:
- `X-Request-ID` — unique per-request correlation ID (16 hex chars); use this when reporting issues to correlate browser, API, and server-side logs
- `X-RateLimit-Limit` — max requests allowed
- `X-RateLimit-Remaining` — requests left in window
- `X-RateLimit-Reset` — Unix timestamp when window resets
- `X-RateLimit-Window` — window size in seconds

Download endpoint rate-limit headers use the `X-DL-RateLimit-*` prefix (e.g., `X-DL-RateLimit-Limit: 10`). Both `info` and `download` endpoints return daily quota headers (`X-DailyLimit-*`) for non-unlimited users.

> **Note:** Download responses return two sets of rate-limit headers:
> - `X-RateLimit-*` — shared gate (applies to both `info` and `download` together)
> - `X-DL-RateLimit-*` — download-specific gate (download-only limit: 10/min)
>
> The download-specific `X-DL-RateLimit-*` headers are set **after** the quota increment, so `X-DL-RateLimit-Remaining: 0` means the download quota for this window is exhausted. The shared `X-RateLimit-*` headers are also present and cover the combined `info + download` request budget.

On `info` and `download` responses (non-unlimited), additional daily quota headers:
- `X-DailyLimit-Limit` — daily rip limit (default 5, unlimited-key holders see `-1`)
- `X-DailyLimit-Remaining` — rips left in the current day (`-1` for unlimited-key holders)
- `X-DailyLimit-Reset` — Unix timestamp of the next daily reset (midnight UTC)
- `X-DailyLimit-Window` — always `daily` (unlimited-key holders see `unlimited`)

---

## Supported Platforms Reference

AhoyRipper uses [yt-dlp](https://github.com/yt-dlp/yt-dlp) under the hood. It supports **1872+ platforms** — every site that yt-dlp can extract from works with AhoyRipper.

### Quick-reference table

| Platform | Type | Notes |
|----------|------|-------|
| [YouTube](https://youtube.com) | Video + Audio | Largest platform |
| [X/Twitter](https://x.com) | Video | |
| [TikTok](https://tiktok.com) | Video + Audio | |
| [SoundCloud](https://soundcloud.com) | Audio | |
| [Instagram](https://instagram.com) | Video + Audio | Reels, stories, posts |
| [Facebook](https://facebook.com) | Video | |
| [Vimeo](https://vimeo.com) | Video | |
| [Reddit](https://reddit.com) | Video + Audio | |
| [VK](https://vk.com) | Video + Audio | |
| [Pornhub](https://pornhub.com) | Video + Audio | Adult content |
| [xHamster](https://xhamster.com) | Video + Audio | Adult content |
| [Xnxx](https://xnxx.com) | Video + Audio | Adult content |
| [xvideos](https://xvideos.com) | Video + Audio | Adult content |
| [Dailymotion](https://dailymotion.com) | Video | |
| [Twitch](https://twitch.tv) | Video + Audio | VODs, clips |
| [Kick](https://kick.com) | Video + Audio | |
| [Rumble](https://rumble.com) | Video | |
| [Bilibili](https://bilibili.com) | Video + Audio | Chinese platform |
| [Niconico](https://nicovideo.jp) | Video + Audio | Japanese platform |
| [Bandcamp](https://bandcamp.com) | Audio | |
| [Mixcloud](https://mixcloud.com) | Audio | |
| [Spotify](https://spotify.com) | Audio | Requires cookies for full access |
| [Netflix](https://netflix.com) | Video | Non-DRM only |
| [Disney+](https://disneyplus.com) | Video | Non-DRM only |
| [Amazon Prime Video](https://amazon.com/prime-video) | Video | Non-DRM only |
| [Hulu](https://hulu.com) | Video | Non-DRM only |
| [Paramount+](https://paramountplus.com) | Video | Non-DRM only |
| [Peacock](https://peacocktv.com) | Video | Non-DRM only |
| [Max/HBO](https://max.com) | Video | Non-DRM only |
| [Pinterest](https://pinterest.com) | Images + Video | |
| [Tumblr](https://tumblr.com) | Video + Audio | |
| [Douyin](https://douyin.com) | Video + Audio | Chinese TikTok |
| [Kuaishou](https://kuaishou.com) | Video + Audio | Chinese platform |
| [Weibo](https://weibo.com) | Video + Audio | Chinese platform |
| [Snapchat](https://snapchat.com) | Video | Stories, spotlight |
| [Telegram](https://telegram.org) | Video + Audio | Public channels |

> **DRM note:** Netflix, Disney+, Amazon Prime Video, Hulu, Paramount+, Peacock, and Max content with digital rights management (DRM) cannot be ripped. Only non-DRM content from these platforms will work.

### Full extractor list

Run `yt-dlp --list-extractors` locally, or see the [yt-dlp supported sites list](https://github.com/yt-dlp/yt-dlp?tab=readme-ov-file#supported-sites) online. Every extractor that works with yt-dlp works with AhoyRipper.

### Platform categories

**Video platforms:** YouTube, X/Twitter, Facebook, Vimeo, TikTok, Instagram, Dailymotion, Twitch, Kick, Rumble, Bilibili, Niconico, Netflix, Disney+, Paramount+, Peacock, HBO Max/Max, Amazon Prime Video, Hulu, and more.

**Audio platforms:** SoundCloud, Bandcamp, Spotify (requires auth), Apple Music, Deezer, Mixcloud, Audiomack, and more.

**Social media:** All platforms above, plus: VK, Douyin, Kuaishou, Weibo, Tumblr, Reddit (video/audio), Pinterest, Snapchat, Telegram, and more.

**Adult content:** Pornhub, xHamster, XNXX, XVideos, and more (all yt-dlp extractors).

### Platforms requiring authentication

Some platforms require you to be logged in to access certain content. If you encounter a `LOGIN_REQUIRED` error:

1. **On YouTube:** Age-restricted videos require authentication. You can pass cookies to yt-dlp by adding a `cookies` option to the command in `src/api.php` — see [yt-dlp cookies guide](https://github.com/yt-dlp/yt-dlp?tab=readme-ov-file#how-do-i-pass-cookies-to-yt-dlp).
2. **On other platforms:** Content behind login walls (Instagram private posts, Patreon, etc.) cannot be downloaded without valid credentials.

### Passing cookies to yt-dlp

Some platforms (e.g., age-restricted YouTube videos, Spotify) require authentication. yt-dlp supports reading browser cookies via the `--cookies` flag, which lets it use your authenticated session to access restricted content.

To enable cookie-based authentication:

1. Export cookies from your browser (e.g., using the "Export Cookies" extension for Chrome/Edge, or the "cookies.txt" format from the "cookies.txt" extension for Firefox).
2. Save the exported cookies file to a location on your server (e.g., `/var/www/ahoyripper/cookies.txt`) and ensure it's readable by the web server user (`www-data` on Ubuntu, or the `php` user in Docker).
3. **Self-hosted (non-Docker):** Set the `COOKIES_PATH` environment variable before starting PHP-FPM:
   ```bash
   export COOKIES_PATH=/var/www/ahoyripper/cookies.txt
   ```
   Or add it to your PHP-FPM or systemd environment file.
4. **Docker:** Mount the cookies file into the container and set the path via docker-compose:
   ```yaml
   services:
     ahoyripper:
       volumes:
         - /path/to/your/cookies.txt:/cookies.txt:ro
       environment:
         - COOKIES_PATH=/cookies.txt
   ```
   Then `docker compose up -d` to apply.

The `cookies.txt` file must be in the Netscape cookie format (the format produced by browser cookie exporters). Keep the file updated — cookies expire and may cause `LOGIN_REQUIRED` errors if they go stale.

---

## Troubleshooting

### Update yt-dlp first
yt-dlp releases are frequent — an outdated version often causes `YTDLP_ERROR` or `UNSUPPORTED_SITE` on platforms that have since changed their APIs. Update before trying anything else:

```bash
# Self-hosted: update via pip
pip install -U yt-dlp

# Docker: rebuild to pull the latest yt-dlp
docker compose down && docker compose build --no-cache && docker compose up -d
```

### Common error codes

| Error code | Cause | Solution |
|-----------|-------|---------|
| `MISSING_URL` | No URL was provided | Paste a valid link from a supported platform |
| `INVALID_URL` | URL is malformed or not supported | Verify the link is correct and public |
| `GEOBLOCKED` | Video is restricted in your region | Route through AhoyVPN to an unblocked region |
| `PRIVATE_VIDEO` | Video is set to private | Request the video from the uploader directly |
| `LOGIN_REQUIRED` | Content requires platform login | Pass cookies from a logged-in session (see [cookies](#passing-cookies-to-yt-dlp)) |
| `AGE_RESTRICTED` | Video requires age verification | Sign in to the platform in your browser first, then try |
| `COPYRIGHT_REMOVED` | Content was removed due to a copyright claim | This content cannot be redistributed |
| `UNSUPPORTED_SITE` | Site is not in yt-dlp's extractor list | Check [yt-dlp's supported sites](https://github.com/yt-dlp/yt-dlp?tab=readme-ov-file#supported-sites) |
| `SOURCE_FORBIDDEN` | Source site blocked this request (HTTP 403) | Try a different format or use AhoyVPN to change your exit IP |
| `SOURCE_RATE_LIMITED` | Source site is throttling requests | Wait a few minutes and try again |
| `SOURCE_TIMEOUT` | Source site took too long to respond | Try audio-only (fastest) or a lower resolution |
| `DOWNLOAD_TIMEOUT` | Download exceeded the 5-minute server limit | Try a smaller format or audio-only |
| `FILE_TOO_LARGE` | File exceeds server's maximum size | Choose audio-only or a lower resolution |
| `FORMAT_UNAVAILABLE` | That format is not available for this video | Pick a different format from the list |
| `PARSE_ERROR` | Site returned an unrecognizable response | The site may be temporarily unavailable |
| `RATE_LIMIT_EXCEEDED` | Too many requests (rate limit) | Wait ~60 seconds and retry, or get AhoyVPN for unlimited access |
| `DAILY_LIMIT` | Daily free quota (5 rips) exhausted | Quota resets at midnight UTC. Get AhoyVPN for unlimited rips |
| `DOWNLOAD_EMPTY` | Empty or corrupt output file | Try another format or wait and retry |
| `INVALID_FORMAT_ID` | Format ID rejected as invalid | Refresh to get a fresh format list, then pick a valid format |
| `MISSING_FORMAT` | No format selected on download | Select a format from the list before downloading |

### Still stuck?

- **VPN-related blocks**: Many sites (YouTube, TikTok, etc.) block requests from VPN exit nodes. If you get repeated `SOURCE_FORBIDDEN` or `CONNECTION_FAILED` errors, try switching to a different VPN server location.
- **Playlist URLs**: Use the playlist URL and pass `&playlist=1` on the download endpoint. Note this counts as one rip per video in the playlist.
- **Instagram private posts**: Requires a valid Instagram session cookie. See [Passing cookies to yt-dlp](#passing-cookies-to-yt-dlp).
- **TikTok without watermark**: Use the TikTok app to copy the link — the official share link gives the cleanest URL. Watermark removal depends on TikTok's current implementation.
- **SoundCloud**: Public tracks work out of the box. Private tracks or tracks behind login walls require cookies.
- **Age-restricted YouTube**: Pass cookies from a signed-in browser session. See [Passing cookies to yt-dlp](#passing-cookies-to-yt-dlp).
- **DRM-protected content** (Netflix, Disney+, Prime, Hulu, Max, Peacock): These platforms use DRM encryption. Only non-DRM content (some older or user-uploaded videos) can be ripped. Subscription content with DRM cannot be bypassed.

> **Security note:** The cookies file contains your authenticated session cookies. Treat it like a password — restrict file permissions to `www-data:www-data` with `0600` and never commit it to version control. In Docker, map it as a read-only volume or pass it via an environment variable pointing to a secure mount path.

### Platforms with known limitations

| Platform | Limitation |
|----------|-------------|
| YouTube | Age-restricted videos require authentication/cookies |
| TikTok | Some videos may be geo-restricted or require login |
| Spotify | Requires `--cookies` (file path) for full access — non-authenticated requests have limited metadata access. See the cookie setup section above. |
| Netflix + streaming sites | DRM-protected content cannot be ripped |

### Verify connectivity with the health probe

Add `&probe=1` to the health endpoint to run a live yt-dlp connectivity check:

```
GET /src/api.php?action=health&probe=1
```

This fetches metadata from a known-stable YouTube video to verify end-to-end connectivity. The result is cached for 5 minutes, so repeated probes within that window return the cached result without calling yt-dlp again.

A `yt_dlp_probe.ok: false` response indicates that yt-dlp itself is failing — check `yt_dlp_version` and `ffmpeg_version` in the health response to confirm both are installed and callable.

### Still not working?

1. Update yt-dlp: Re-run `scripts/install-deps.sh` (the script detects the current install method and updates accordingly), or manually replace the binary:
   ```
   curl -L -o /usr/local/bin/yt-dlp https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp && chmod +x /usr/local/bin/yt-dlp
   ```
2. Try a different format (audio-only often works when video fails)
3. Try a different video from the same platform (rules out site-wide blocks)
4. Check [yt-dlp supported sites](https://github.com/yt-dlp/yt-dlp?tab=readme-ov-file#supported-sites) — the platform may have added/changed its API

---

## Usage Tips

- **Paste & go** — Paste any supported URL into the input field and the rip starts automatically. No need to press Enter or click a button.
- **Pre-fill a URL via query param** — Append `?url=https://...` to the page URL to pre-load a video. Useful for sharing links directly (e.g. `https://ahoyripper.com/?url=https://www.youtube.com/watch?v=...`).
- **Sort formats** — Use the Quality / Size / Bitrate dropdown above the format cards to reorder the list. Switching the sort re-fetches the format list from the server (costs 1 quota hit) — this is intentional as it lets yt-dlp sort accurately on the server side.
- **Save your sort preference** — The sort choice is remembered in localStorage across visits.
- **Daily quota resets at midnight UTC** — The free tier allows 5 total rips per day. Each call to the `info` or `download` API counts as one rip. Quota resets at 00:00 UTC.
- **API key** — Enter your AhoyVPN unlimited key in the optional field to bypass the daily 5-rip limit.

---

## FAQ

### General

**Q: What is AhoyRipper?**
AhoyRipper is a free, browser-based tool for downloading video and audio from the internet. It streams media directly through our servers — nothing is stored on our infrastructure. No signup, no tracking, no ads.

**Q: How is this different from a browser extension or desktop app?**
AhoyRipper runs entirely in your browser. There's nothing to install — just open the page and paste a link. Your IP address is hidden behind our servers, which can help when a site blocks your connection.

**Q: What platforms are supported?**
Every platform that [yt-dlp supports](https://github.com/yt-dlp/yt-dlp/blob/master/supportedsites.md) — currently 1872+ sites. The supported platforms table above lists the most popular ones.

**Q: Is there a daily limit?**
The free tier allows 5 rips per day (each `info` or `download` API call counts as one rip). The quota resets at midnight UTC. Get [AhoyVPN](https://ahoyvpn.com) for unlimited rips.

**Q: Does AhoyRipper store my downloaded files?**
No. Files are streamed directly from the source to your browser. Nothing is stored on our servers — the download happens entirely between you and the source platform.

---

### Downloads

**Q: Why did my download fail?**
Common reasons:
- **GEOBLOCKED** — The video is not available in our server's region. Use AhoyVPN to route through an unblocked country.
- **LOGIN_REQUIRED** — The video requires a platform account. See the cookies section to sign in.
- **AGE_RESTRICTED** — YouTube requires age verification. Pass your browser cookies to enable this.
- **SOURCE_TIMEOUT** — The source site is slow or overloaded. Try audio-only (fastest) or a lower resolution.
- **DOWNLOAD_TIMEOUT** — The file is very large. Try a smaller format or audio-only.
- **VPN blocks** — Many sites (YouTube, TikTok, etc.) block VPN exit IPs. If you get repeated `SOURCE_FORBIDDEN` errors, try a different VPN server location.

**Q: I got a "Format unavailable" error but the video exists.**
The format you selected (e.g. 1080p60fps) may not exist in that combination. Try the next available quality down, or use the `best` format for the highest quality available.

**Q: My download started but the file is empty or corrupt.**
This is usually a server-side issue (the source returned an empty file). Try a different format — if the same error persists across formats, the source may be temporarily having issues.

**Q: The audio is out of sync with the video.**
This happens when yt-dlp has to merge separate video and audio streams. Try a "combined" format (a single file with both video and audio) if available — these don't require merging and are less prone to sync issues.

**Q: Can I download an entire playlist?**
Yes — paste the playlist URL and add `&playlist=1` to the download URL (e.g. `?action=download&url=...&format=best&playlist=1`). Each video in the playlist counts as one rip.

---

### Authentication & Cookies

**Q: Why do some videos say "Login required"?**
Some content (age-restricted YouTube videos, private Instagram posts, etc.) requires an active platform session. See [Passing cookies to yt-dlp](#passing-cookies-to-yt-dlp) to export your browser session and enable access.

**Q: How do I export cookies?**
1. Install a cookie exporter extension (e.g. "Export Cookies" for Chrome/Edge, or "cookies.txt" for Firefox).
2. Log into the platform in your browser.
3. Export the cookies in Netscape format and save the file.
4. Mount it into AhoyRipper as described in the [cookies section](#passing-cookies-to-yt-dlp).

**Q: Do cookies expire?**
Yes. Cookies have built-in expiration dates set by each platform. Update your cookies file periodically — expired cookies cause `LOGIN_REQUIRED` errors.

---

### Quality & Formats

**Q: What's the difference between format types?**
- **Combined** (`bestvideo+bestaudio` or single file) — Contains both video and audio. Best for watching on a device.
- **Video-only** — Video stream without audio. Requires a separate audio track or a media player that can merge them.
- **Audio-only** — Audio stream only. Smallest file size, ideal for music or podcasts.

**Q: Why are some formats grouped together (e.g. "bestvideo+bestaudio")?**
YouTube and some other platforms serve video and audio as separate streams. AhoyRipper shows them as a combined option for convenience, but they're actually merged at download time using ffmpeg.

**Q: What does "quality tier" mean?**
The quality tier (`quality` field in the API) ranks formats by their quality level: 4K > 1080p > 720p > 480p > 360p > audio-only (320kbps > 256kbps > 192kbps > 128kbps). The `sort=quality` option orders formats by this tier rather than by raw resolution height.

**Q: What does "format substitution" mean?**
When you request a format that's not available (e.g. 1080p60fps doesn't exist), yt-dlp silently substitutes the nearest available alternative. AhoyRipper detects this via ffprobe and shows the `X-Format-Substituted` header so you know what you actually received vs. what you requested.

**Q: What's the largest file I can download?**
There is no hard size limit, but downloads are subject to the server timeout (5 minutes by default). Very large files (feature films, 4K content) may exceed this. Try audio-only or a lower resolution if downloads timeout.

---

### Rate Limits & Quotas

**Q: I hit my daily limit. How do I get more?**
Get [AhoyVPN](https://ahoyvpn.com) — it includes an unlimited AhoyRipper API key that bypasses the daily cap entirely.

**Q: Can I use the API directly with my own tool?**
Yes. The API is documented in the [API section](#api) above. Use your AhoyVPN unlimited key in the `Authorization: Bearer` header for unlimited access.

---

### Privacy & Security

**Q: Do you log what I download?**
Request logs are kept temporarily for operational debugging (typically ≤7 days). No media content is stored. Your IP address is partially masked in logs for privacy.

**Q: My employer/school network is blocking video sites. Can AhoyRipper help?**
Yes — your request goes through our servers, not directly to the video platform. As long as ahoyripper.com is accessible from your network, downloads should work.

**Q: Is using AhoyRipper legal?**
AhoyRipper is a tool. What you do with it is your responsibility. Do not use it to download content you don't have the right to access. Respect copyright and platform terms of service.

---

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `AHOY_USER_AGENT` | `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36` | Custom User-Agent string for yt-dlp requests. yt-dlp defaults to `python-requests/X.Y.Z` which is trivially blocked by anti-bot measures — this overrides it with a modern Chrome UA. Override via `AHOY_USER_AGENT` env var in docker-compose or cloud dashboard to mimic a different browser. |
| `QUOTA_DAILY` | `5` | Daily rip limit per IP for unauthenticated requests. Each call to `info` or `download` counts as one rip. Set to a higher number (e.g. `100`) for less restrictive quotas, or use an unlimited API key to bypass the daily cap entirely. |
| `YTDLP_TIMEOUT` | `45` | Timeout in seconds for the info action (metadata fetch). If yt-dlp does not return within this window, the process is terminated and a `SOURCE_TIMEOUT` error is returned. Override via `YTDLP_TIMEOUT` env var (e.g. `YTDLP_TIMEOUT=60`). The download action has its own separate timeout controlled by `YTDLP_DOWNLOAD_TIMEOUT`. |
| `YTDLP_DOWNLOAD_TIMEOUT` | `300` | Timeout in seconds for the download action (file download). Large media files may require longer than the default 5 minutes; increase this for high-resolution or slow-source downloads. Override via `YTDLP_DOWNLOAD_TIMEOUT` env var (e.g. `YTDLP_DOWNLOAD_TIMEOUT=600`). The info action has its own separate timeout controlled by `YTDLP_TIMEOUT`. |
| `YTDLP_PATH` | `/usr/local/bin/yt-dlp` | Path to the yt-dlp binary. Override this when yt-dlp is installed in a non-standard location (e.g. `/usr/bin/yt-dlp` on some systems, or a custom path in a Docker image). The version cache is keyed on this path, so changing it invalidates the stale version cache. |
| `FFPROBE_PATH` | `/usr/bin/ffprobe` | Path to the ffprobe binary used for post-download codec/resolution verification. Override this when ffprobe is in a non-standard location (e.g. `/usr/local/bin/ffprobe` on macOS or custom Docker images). The version cache is keyed on this path, so changing it invalidates the stale version cache. |
| `AHOY_UNLIMITED_KEY` | `RIPPER2026DEV` | API key that grants unlimited daily quota. **Change in production.** Set to a long random string (e.g. `openssl rand -hex 32`) and pass to the container via `-e` or your orchestration layer. |
| `COOKIES_PATH` | _(none)_ | Path to a Netscape-format `cookies.txt` file for authenticated requests (age-restricted YouTube, Spotify, etc.). When set, `--cookies` is passed to yt-dlp automatically. Mount the file into the container and set the path here (e.g. `/cookies.txt`). See [cookies section](#passing-cookies-to-yt-dlp) for setup instructions. |

Example:
```bash
# Generate a secure key
openssl rand -hex 32

# Run with custom key
docker run -e AHOY_UNLIMITED_KEY=your-generated-key ahoyripper
```

The default key is only suitable for local development — never deploy with it in production.

---

## Requirements

- Ubuntu 22.04+ (or any Linux with apt)
- yt-dlp (standalone binary — see `scripts/install-deps.sh` for the automated install/update script)
- ffmpeg
- PHP 8.x + php-fpm + php-mbstring + php-curl
- Nginx
- 4GB+ RAM recommended

---

## Security

AhoyRipper follows [RFC 9116](https://www.rfc-editor.org/rfc/rfc9116) (security.txt). A machine-readable security contact is available at:

```
https://ahoyripper.com/.well-known/security.txt
```

**Responsible disclosure:** If you discover a security vulnerability, please report it to `security@ahoyripper.com`. Include a description of the issue and any relevant details. You can expect a response within 48–72 hours on business days.

**Scope:** Reports are accepted for the AhoyRipper application, its API, and infrastructure. Do not attempt to exploit vulnerabilities for research purposes — report only.

---

## Legal

For personal use only. Respect copyright. This tool is provided as-is. DMCA requests: dmca@ahoyvpn.com

---

## Hosting / Support

- Main site: https://ahoyripper.com (or ahoyvpn.com/rip)
- VPN: https://ahoyvpn.com
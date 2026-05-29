# AhoyRipper

**Rip any video, anywhere.** A free, no-signup media converter that pulls video and audio from YouTube, X/Twitter, SoundCloud, TikTok, Instagram, Facebook, Vimeo, and 1800+ other platforms.

Built on [yt-dlp](https://github.com/yt-dlp/yt-dlp), styled to match the AhoyVPN brand.

---

## Features

- **No signup, no tracking, no ads in the rip flow**
- MP4, WEBM, MP3, M4A, WAV, FLAC, OGG and more
- YouTube, X (Twitter), SoundCloud, TikTok, Instagram, Facebook, Vimeo + 1800+ platforms
- Dark theme matching ahoyvpn.com
- Files streamed directly to your download - nothing stored on our servers
- Built-in AhoyVPN upsell (supports the tool)

---

## Quick Start (Production)

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
docker compose up -d
```

### Environment Variables (Docker)

| Variable | Default | Description |
|----------|---------|-------------|
| `AHOY_UNLIMITED_KEY` | `RIPPER2026DEV` | API key granting unlimited daily quota. **Change this in production** — generate a secure value with `openssl rand -hex 32`. |

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
├── public/
│   ├── index.php          # Main page
│   └── robots.txt         # SEO + AI-crawler blocking
├── src/
│   ├── style.css          # CSS (AhoyVPN brand)
│   └── api.php            # yt-dlp API (info, download)
├── deploy/
│   ├── nginx.conf         # Nginx config (production)
│   └── nginx-docker.conf  # Nginx config (Docker)
├── scripts/
│   └── install-deps.sh    # Dependency installer
├── tests/
│   ├── sanity.sh             # Shell-based sanity / regression checks
│   ├── parse_formats_test.php # Unit tests for parseFormats() — no dependencies
│   └── api_test.php           # Integration tests for the API endpoints
├── docker-compose.yml
├── Dockerfile
├── README.md
└── LICENSE
```

> **Note:** `robots.txt` lives in `public/` and is also served at the root by nginx — do not place a separate `robots.txt` at the project root.

---

## API

### Get video info + formats
```
GET /src/api.php?action=info&url=<url>&sort=<height|filesize|tbr>
```

The `sort` parameter (optional, default `height`) controls format sort order:
- `height` — quality, highest resolution first (default)
- `filesize` — estimated file size, largest first
- `tbr` — bitrate, highest first

**Success response:**
```json
{
  "request_id": "a3f1b2c9d4e5f678",
  "title": "Video Title",
  "thumbnail": "https://...",
  "duration": 180,
  "uploader": "Channel Name",
  "derived_filename": "Video_Title",
  "formats": [
    {
      "id": "22",
      "label": "720p60 HDR mp4",
      "description": "1280x720 720p60 HDR 10bit",
      "ext": "mp4",
      "filesize_mb": 45.2,
      "height": 720,
      "fps": 60,
      "tbr": 2500,
      "abr": null,
      "vcodec": "avc1.64001F",
      "acodec": "mp4a.40.2",
      "format_type": "combined",
      "language": null
    }
  ],
  "sort_applied": "height"
}
```

The `label` field is a compact shorthand (e.g. `"720p60 mp4"`). The `description` field provides richer human-readable context from yt-dlp (e.g. `"1280x720 720p60 HDR 10bit"`) — use this for display when available. The `format_type` field distinguishes `"combined"` (video+audio), `"video"` (video-only), and `"audio"` (audio-only) formats.

**Error codes:**

| Code | Meaning |
|------|---------|
| `400` | Invalid URL or missing parameters |
| `403` | Request blocked — must originate from ahoyripper.com or ahoyvpn.com (`FORBIDDEN_ORIGIN`) |
| `405` | Method not allowed — API accepts GET only (`METHOD_NOT_ALLOWED`) |
| `406` | Not acceptable — JSON requested (`NOT_ACCEPTABLE`) |
| `422` | URL could not be fetched, parsed, or is unsupported — also returned for geo-blocked, private, copyrighted, or login-required content (`error_code` field provides detail) |
| `429` | Rate limit exceeded — see `Retry-After` header and `upgrade_url` in response body |
| `503` | Service temporarily unavailable |

**Classified error codes** (surfaced in the `error_code` field of 422 responses):

| error_code | Meaning |
|------------|---------|
| `GEOBLOCKED` | Video is geo-restricted in your region |
| `PRIVATE_VIDEO` | Video is private and cannot be downloaded |
| `LOGIN_REQUIRED` | Video requires login or subscription on the source platform |
| `PARSE_ERROR` | The site returned a non-standard or unparseable response — it may not be supported |
| `UNSUPPORTED_SITE` | The site is not supported by yt-dlp |
| `PLAYLIST_MISSING` | Playlist not found or no longer exists |
| `COPYRIGHT_REMOVED` | Content removed due to a copyright claim |
| `VIDEO_UNAVAILABLE` | Video has been removed, delisted, or is no longer available |
| `AGE_RESTRICTED` | Video is age-restricted and requires verification on the source platform |
| `SOURCE_RATE_LIMITED` | The source site is rate-limiting requests — try again shortly |
| `SSL_ERROR` | Secure connection to the source failed |
| `CONNECTION_FAILED` | Could not connect to the source |
| `FILE_TOO_LARGE` | File exceeds the server's maximum size |
| `FORMAT_UNAVAILABLE` | That format is not available for this video |
| `DISALLOWED_CONTENT` | Content not available due to a terms of service violation |
| `YTDLP_ERROR` | General yt-dlp error (see `raw_error` field for detail) |
| `DOWNLOAD_TIMEOUT` | Download exceeded the 5-minute server timeout. Try a smaller format or lower resolution. |
| `DOWNLOAD_FAILED` | The rip produced an empty or corrupt file. Try another format from the list. |
| `INVALID_FORMAT_ID` | The format ID was rejected as invalid — refresh the page and pick another format. |

### Download a format
```
GET /src/api.php?action=download&url=<url>&format=<format_id>&filename=<name>
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
  "raw_error": "ERROR: [youtube] NGeR...: This video is available in United States."
}
```

**Download error response (500 with generic error):**
```json
{
  "error": "Download failed: Unable to download (exit code 1).",
  "error_code": "YTDLP_ERROR",
  "request_id": "a3f1b2c9d4e5f678",
  "raw_error": "ERROR: [youtube] NGeR...:requested format not available"
}
```

**Download error responses** (any of these may be returned when the rip itself fails):

| Code | `error_code` | Meaning |
|------|--------------|---------|
| `422` | `GEOBLOCKED` | Video is geo-restricted in your region |
| `422` | `PRIVATE_VIDEO` | Video is private and cannot be downloaded |
| `422` | `LOGIN_REQUIRED` | Video requires login or subscription |
| `422` | `COPYRIGHT_REMOVED` | Content removed due to a copyright claim |
| `422` | `SOURCE_RATE_LIMITED` | The source site is rate-limiting requests |
| `422` | `CONNECTION_FAILED` | Could not connect to the source |
| `422` | `FILE_TOO_LARGE` | File exceeds the server's maximum size |
| `422` | `FORMAT_UNAVAILABLE` | That format is not available for this video |
| `422` | `DISALLOWED_CONTENT` | Content not available due to a terms of service violation |
| `422` | `YTDLP_ERROR` | General yt-dlp error (see `raw_error` field) |
| `422` | `DOWNLOAD_TIMEOUT` | Download exceeded the 5-minute server timeout. Try a smaller format or lower resolution. |
| `422` | `INVALID_FORMAT_ID` | The format ID was rejected as invalid — refresh and pick another format. |
| `500` | `DOWNLOAD_FAILED` | The rip produced an empty or corrupt file. Try another format from the list. |

### Health check / progress
```
GET /src/api.php?action=check   # lightweight internal ping (Docker healthcheck-safe)
GET /src/api.php?action=health   # full system status with resource metrics
GET /src/api.php?action=health&probe=1   # include live yt-dlp connectivity probe
GET /src/api.php?action=progress         # alias for health (legacy)
```

`action=check` is a minimal ping with zero server overhead — no dependency on yt-dlp, ffmpeg, or /proc/sys calls. It returns instantly and is safe to call every 10 seconds. Use it for Docker healthchecks and load-balancer probes.

`action=health` returns full system status:
```
{
  "status": "ok",
  "server_time": "2026-05-21T16:00:00+00:00",
  "request_id": "a3f1b2c9d4e5f678",
  "yt_dlp_version": "2024.x.x",
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

`load_avg` requires Linux. `memory_available_pct` reads `/proc/meminfo`. `disk_free_gb` uses `disk_free_space()`. Cache fields reflect internal version-caching TTLs (1 hour).

### Rate Limits

| Endpoint | Limit | Window |
|----------|-------|--------|
| `/src/api.php?action=info` | 30 requests | 60 seconds |
| `/src/api.php?action=download` | 10 requests | 60 seconds |

Response headers on every API response:
- `X-RateLimit-Limit` — max requests allowed
- `X-RateLimit-Remaining` — requests left in window
- `X-RateLimit-Reset` — Unix timestamp when window resets
- `X-RateLimit-Window` — window size in seconds

For download endpoint, headers are `X-DL-RateLimit-*`.

On `info` and `download` responses (non-unlimited), additional daily quota headers:
- `X-DailyLimit-Limit` — daily rip limit (default 5)
- `X-DailyLimit-Remaining` — rips left in the current day
- `X-DailyLimit-Reset` — Unix timestamp of the next daily reset (midnight UTC)
- `X-DailyLimit-Window` — always `daily`

---

## Supported Platforms

AhoyRipper uses [yt-dlp](https://github.com/yt-dlp/yt-dlp) under the hood. It supports **1800+ platforms** including all major video, audio, and social media sites.

### Platform Categories

**Video platforms:** YouTube, X/Twitter, Facebook, Vimeo, TikTok, Instagram, Dailymotion, Twitch, Kick, Rumble, Bilibili, Niconico, Netflix, Disney+, Paramount+, Peacock, HBO Max/Max, Amazon Prime Video, Hulu, and more.

**Audio platforms:** SoundCloud, Bandcamp, Spotify (requires auth), Apple Music, Deezer, Mixcloud, Audiomack, and more.

**Social media:** All platforms above, plus: VK, Douyin, Kuaishou, Weibo, Tumblr, Reddit (video/audio), and more.

**Full extractor list:** Run `yt-dlp --list-extractors` locally, or see the [yt-dlp supported sites list](https://github.com/yt-dlp/yt-dlp?tab=readme-ov-file#supported-sites) online. Every extractor that works with yt-dlp works with AhoyRipper.

### Platforms requiring authentication

Some platforms require you to be logged in to access certain content. If you encounter a `LOGIN_REQUIRED` error:

1. **On YouTube:** Age-restricted videos require authentication. You can pass cookies to yt-dlp by adding a `cookies` option to the command in `src/api.php` — see [yt-dlp cookies guide](https://github.com/yt-dlp/yt-dlp?tab=readme-ov-file#how-do-i-pass-cookies-to-yt-dlp).
2. **On other platforms:** Content behind login walls (Instagram private posts, Patreon, etc.) cannot be downloaded without valid credentials.

### Platforms with known limitations

| Platform | Limitation |
|----------|-------------|
| YouTube | Age-restricted videos require authentication/cookies |
| TikTok | Some videos may be geo-restricted or require login |
| Spotify | Requires `--cookies-from-browser` or `--cookies` for full access |
| Netflix + streaming sites | DRM-protected content cannot be ripped |

---

---

## Troubleshooting

**"Could not fetch that URL"** — The site may not be supported by yt-dlp, or the video is geo-restricted/private/unavailable. Check the [yt-dlp supported sites list](https://github.com/yt-dlp/yt-dlp?tab=readme-ov-file#supported-sites). If the site is supported, the video may be age-restricted, region-locked, or removed.

**Rate limited (429)** — Wait before retrying. Check the `Retry-After` header for the number of seconds to wait. Limits reset every 60 seconds (rate limit) or at midnight UTC (daily quota).

**Download times out** — Large 4K/8K rips can exceed the 5-minute server timeout. Try an audio-only format (MP3/AAC) or a lower resolution (480p/720p). The source may also be slow or unresponsive.

**Empty download / corrupt file** — The selected format may not be available in that combination. Try another format from the list, or fall back to `best` which lets yt-dlp pick the most reliable option.

**"No formats found"** — If the info API returns an empty or near-empty format list, yt-dlp could not extract any playable streams from the URL. This usually means: the video is on an unsupported platform, geo-restricted, requires login, or has been removed. Try running `yt-dlp --list-formats <URL>` directly on your server to see what yt-dlp itself reports.

**Quota exhausted (5/5 rips used)** — The free tier allows 5 total API calls per day (midnight UTC reset). Each call to `info` or `download` counts as one rip. Enter an AhoyVPN unlimited key in the optional field to bypass the daily cap.

**Sort change triggers a new API call** — Switching the Quality / Size / Bitrate dropdown re-fetches the format list from the server (costs 1 quota hit). This is intentional — it lets yt-dlp sort formats differently on the server side for accurate results.

**503 Service unavailable** — The server is temporarily overloaded or the rate-limit gate could not open a file. Retry after a few seconds. If the issue persists, it may indicate a server-side resource problem.

**Geo-blocked / region restricted** — The video is not available in your server's geographic location. Using AhoyVPN can route the request through a different region.

### Diagnosing with the health probe

Add `&probe=1` to the health endpoint to run a live connectivity check:

```
GET /src/api.php?action=health&probe=1
```

This fetches metadata from a known-stable YouTube video to verify end-to-end connectivity. The result is cached for 5 minutes, so repeated probes within that window return the cached result without calling yt-dlp again.

A `yt_dlp_probe.ok: false` response indicates that yt-dlp itself is failing — check `yt_dlp_version` and `ffmpeg_version` in the health response to confirm both are installed and callable.

### Interpreting error codes

| error_code | Cause | Action |
|------------|-------|--------|
| `GEOBLOCKED` | Video is region-locked | Use a VPN to route through an allowed region |
| `PRIVATE_VIDEO` | Video is private or unlisted | Cannot be downloaded |
| `LOGIN_REQUIRED` | Video requires platform login | Sign in to the platform first |
| `UNSUPPORTED_SITE` | Site not in yt-dlp extractor list | Check [supported sites](https://github.com/yt-dlp/yt-dlp?tab=readme-ov-file#supported-sites) |
| `COPYRIGHT_REMOVED` | Content removed by copyright holder | No workaround |
| `VIDEO_UNAVAILABLE` | Video deleted or delisted | No workaround |
| `AGE_RESTRICTED` | Video requires age verification on source | Sign in to the platform |
| `SOURCE_RATE_LIMITED` | Source site is throttling requests | Wait 30–60 seconds and retry |
| `SSL_ERROR` | TLS/certificate error with source | Retry — usually transient |
| `CONNECTION_FAILED` | Network error reaching source | Check your server's network and retry |
| `FILE_TOO_LARGE` | File exceeds server limit | Try audio-only or lower resolution |
| `FORMAT_UNAVAILABLE` | Selected format not available | Choose another format from the list |
| `DOWNLOAD_TIMEOUT` | Exceeded 5-minute server timeout | Try a smaller format or lower resolution |
| `DOWNLOAD_FAILED` | Empty or corrupt output file | Try another format or wait and retry |
| `INVALID_FORMAT_ID` | Format ID rejected as invalid | Refresh the page and pick another format |

---

## Usage Tips

- **Paste & go** — Paste any supported URL into the input field and the rip starts automatically. No need to press Enter or click a button.
- **Pre-fill a URL via query param** — Append `?url=https://...` to the page URL to pre-load a video. Useful for sharing links directly (e.g. `https://ahoyripper.com/?url=https://www.youtube.com/watch?v=...`).
- **Sort formats** — Use the Quality / Size / Bitrate dropdown above the format cards to reorder the list.
- **Save your sort preference** — The sort choice is remembered in localStorage across visits.
- **API key** — Enter your AhoyVPN unlimited key in the optional field to bypass the daily 5-rip limit.

---

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `AHOY_UNLIMITED_KEY` | `RIPPER2026DEV` | API key that grants unlimited daily quota. **Change in production.** Set to a long random string (e.g. `openssl rand -hex 32`) and pass to the container via `-e` or your orchestration layer. |

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
- yt-dlp (pip install yt-dlp)
- ffmpeg
- PHP 8.x + php-fpm + php-mbstring + php-curl
- Nginx
- 4GB+ RAM recommended

---

## Legal

For personal use only. Respect copyright. This tool is provided as-is. DMCA requests: dmca@ahoyvpn.com

---

## Hosting / Support

- Main site: https://ahoyripper.com (or ahoyvpn.com/rip)
- VPN: https://ahoyvpn.com
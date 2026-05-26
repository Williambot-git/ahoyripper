# AhoyRipper

**Rip any video, anywhere.** A free, no-signup media converter that pulls video and audio from YouTube, X/Twitter, SoundCloud, TikTok, Instagram, Facebook, Vimeo, and 1800+ other platforms.

Built on [yt-dlp](https://github.com/yt-dlp/yt-dlp), styled to match the AhoyVPN brand.

---

## Features

- **No signup, no tracking, no ads in the rip flow**
- MP4, WEBM, MP3, M4A, FLAC, OGG and more
- YouTube, Twitter/X, SoundCloud, TikTok, Instagram, Facebook, Vimeo + 1800 sites
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
docker compose up -d
# App runs at http://localhost:8080
```

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
│   └── sanity.sh          # Sanity / regression checks
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

The `sort` parameter (optional, default `height`) controls format sort order:
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
| `UNSUPPORTED_SITE` | The site is not supported by yt-dlp |
| `PLAYLIST_MISSING` | Playlist not found or no longer exists |
| `COPYRIGHT_REMOVED` | Content removed due to a copyright claim |
| `AGE_RESTRICTED` | Video is age-restricted and requires verification on the source platform |
| `SOURCE_RATE_LIMITED` | The source site is rate-limiting requests — try again shortly |
| `SSL_ERROR` | Secure connection to the source failed |
| `CONNECTION_FAILED` | Could not connect to the source |
| `FILE_TOO_LARGE` | File exceeds the server's maximum size |
| `FORMAT_UNAVAILABLE` | That format is not available for this video |
| `YTDLP_ERROR` | General yt-dlp error (see `raw_error` field for detail) |

### Download a format
```
GET /src/api.php?action=download&url=<url>&format=<format_id>&filename=<name>
Authorization: Bearer ***
```

The `format_id` comes from the `id` field in the info response. The API reads the key from the `Authorization: Bearer` header (preferred — keeps the key out of URLs and server logs). A `key` query parameter is also accepted for backwards compatibility but is discouraged.

The `filename` param (optional) sets the downloaded file's name. Only alphanumeric, spaces, dots, underscores, and hyphens are allowed; everything else is stripped. Falls back to `ahoyrip.<ext>` if omitted or empty.

> **Note:** The free tier allows 5 total rips per day (each call to the info or download API counts as one rip). Unlimited-key holders have no daily cap.

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
| `422` | `YTDLP_ERROR` | General yt-dlp error (see `raw_error` field) |
| `500` | `DOWNLOAD_FAILED` | The rip produced an empty or corrupt file. Try another format from the list. |

### Health check / progress
```
GET /src/api.php?action=health
GET /src/api.php?action=health&probe=1   # include live yt-dlp connectivity probe
GET /src/api.php?action=progress         # alias for health (legacy)
```

Returns:
```json
{
  "status": "ok",
  "server_time": "2026-05-21T16:00:00+00:00",
  "request_id": "a3f1b2c9d4e5f678",
  "yt_dlp_version": "2024.x.x",
  "ffmpeg_version": "ffmpeg version 6.x",
  "yt_dlp_cache_expires_at": "2026-05-21T17:00:00+00:00",
  "yt_dlp_cache_ttl_seconds": 542,
  "ffmpeg_cache_expires_at": "2026-05-21T17:00:00+00:00",
  "ffmpeg_cache_age_seconds": 542,
  "yt_dlp_probe": { "ok": true, "title": "Rick Astley - Never Gonna Give You Up (Official Music Video)" },
  "load_avg": [0.15, 0.08, 0.05],
  "memory_available_pct": 72.4,
  "disk_free_gb": 48.2
}
```

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

AhoyRipper uses [yt-dlp](https://github.com/yt-dlp/yt-dlp) under the hood. It supports **1800+ platforms** including:

**Video:** YouTube, X/Twitter, Facebook, Vimeo, TikTok, Instagram, Dailymotion, Twitch, Kick, Rumble, Bilibili, Niconico, and more

**Audio:** SoundCloud, Bandcamp, Spotify (with auth), Apple Music, Deezer, Mixcloud, Audiomack, and more

**Full list:** See the [yt-dlp supported sites list](https://github.com/yt-dlp/yt-dlp?tab=readme-ov-file#supported-sites) online — no installation needed.

You can also check from the command line:
```bash
yt-dlp --list-extractors
```

---

## Troubleshooting

**"Could not fetch that URL"** — The site may not be supported by yt-dlp, or the video is geo-restricted/private/unavailable.

**Rate limited (429)** — Wait 30 seconds. Limits reset every 60 seconds.

**Download times out** — Try an audio-only format or a lower resolution. Large 4K rips can exceed the 5-minute timeout.

**Empty download** — The format may not be available in that combination. Try another format from the list.

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
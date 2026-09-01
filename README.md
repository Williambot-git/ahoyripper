# AhoyRipper

| **Rip any video, anywhere.** A free, no-signup media converter that pulls video and audio from YouTube, X/Twitter, SoundCloud, TikTok, Instagram, Facebook, Vimeo, and 1873+ other platforms. |

Built on [yt-dlp](https://github.com/yt-dlp/yt-dlp), styled to match the AhoyVPN brand.

---

## Features

- **No signup, no tracking, no ads in the rip flow**
- MP4, WEBM, MP3, M4A, WAV, FLAC, OGG and more
- YouTube, X (Twitter), SoundCloud, TikTok, Instagram, Facebook, Vimeo + 1873+ platforms
- Dark theme matching ahoyvpn.com
- Files streamed directly to your download - nothing stored on our servers
- Built-in AhoyVPN upsell (supports the tool)
- OpenSearch — add AhoyRipper to your browser's search bar for one-click ripping

---

## Quick Start

**1. Paste a URL** — Copy any video or audio link and paste it into the input field on the home page.

**2. Click "Rip It"** — Or just press Enter. AhoyRipper fetches the available formats from the server.

**3. Pick a format** — Browse the format cards, sort by quality (height/bitrate), file size, or audio quality using the dropdown, then click **Download** next to your preferred option.

The file streams directly to your browser — nothing is stored on the server. Each rip counts toward your daily quota (5 free rips per day, resetting at midnight UTC). Enter an AhoyVPN API key to bypass the limit.

> **Tip:** Append `?url=https://...` to the page URL to pre-load a video link — useful for sharing direct rip links.
>
> **Add to search bar:** OpenSearch is enabled — your browser may already suggest adding AhoyRipper as a search engine. Once added, type your video URL directly in the URL bar and press Tab or Enter to rip instantly.

---

## Quick Examples

All API calls require a `Referer: https://ahoyripper.com` header (browser requests already include this; add it explicitly when using `curl`).

### Get video info and format list

```bash
curl -s -X GET "https://ahoyripper.com/src/api.php?action=info&url=https://www.youtube.com/watch?v=dQw4w9WgXcQ" \
  -H "Referer: https://ahoyripper.com/" | python3 -m json.tool
```

Response includes `title`, `thumbnail`, `duration`, `uploader`, `uploader_url`, `url`, `platform`, `derived_filename`, and `formats[]` with `id`, `label`, `description`, `format_note`, `filesize_mb`, `height`, `fps`, `quality`, `ext`, `vcodec`, `acodec`, `abr`, `format_type`, `type_group`, and `language` for each available format. `uploader_url` is the channel/uploader page URL (or `null`). `url` is the canonical video page URL after any redirect. Sort formats with `&sort=height` (default), `&sort=filesize` (largest first), `&sort=filesize_asc` (smallest first), `&sort=tbr` (bitrate), `&sort=quality`, or `&sort=audio_quality`.

### Download a specific format

Pick a format `id` from the info response (e.g. `bestaudio[ext=m4a]`) and pass it to the download action:

```bash
# Stream download to file
curl -s -X GET "https://ahoyripper.com/src/api.php?action=download&url=https://www.youtube.com/watch?v=dQw4w9WgXcQ&format=bestaudio%5Bext%3Dm4a%5D" \
  -H "Referer: https://ahoyripper.com/" \
  -o video.m4a
```

### Advanced format selectors

yt-dlp's format selectors let you request specific quality, codec, and container combinations beyond the simple format IDs in the info response.

```bash
# Best audio only (e.g., best available m4a or opus)
format=bestaudio

# Specific height — best video up to 720p + best audio (merger format)
format=bestvideo[height<=720]+bestaudio/best

# High quality — 1080p+ video with best audio
format=bestvideo[height>=1080]+bestaudio/bestvideo+bestaudio/best

# Audio-only with specific extension
format=bestaudio[ext=m4a]

# Prefer framerate (highest fps available at or above target height)
format=bestvideo[height>=720][fps>=30]+bestaudio/best

# Exclude certain codecs (e.g., avoid VP9 to prefer H.264 on older devices)
format=bestvideo[height>=720][vcodec!VP9]+bestaudio/bestvideo[height>=720]+bestaudio/best
```

**Selector operators:**

| Operator | Meaning |
|----------|---------|
| `=` | Exact match (e.g., `[height=720]`) |
| `>` `>=` | Greater than / greater than or equal |
| `<` `<=` | Less than / less than or equal |
| `!=` | Not equal (exclude matches) |
| `/` | Fallback — try left side, fall back to right if unavailable |

**Common fields:**

| Field | Example | Notes |
|-------|---------|-------|
| `height` | `[height>=720]` | Video vertical resolution in px |
| `fps` | `[fps>=30]` | Frames per second |
| `ext` | `[ext=mp4]` | File extension / container |
| `vcodec` | `[vcodec!=VP9]` | Video codec (`mp4a`, `opus`, `vorbis`, `h264`, `vp9`, `av1`) |
| `acodec` | `[acodec=mp4a]` | Audio codec |
| `filesize` | `[filesize>=10M]` | Approximate max file size |
| `tbr` | `[tbr>=256]` | Total bitrate in kbps |

For the full selector reference, see the [yt-dlp format selection docs](https://github.com/yt-dlp/yt-dlp#format-selection).

### With an API key (unlimited quota)

```bash
# Pass key via Authorization header (preferred — keeps key out of logs)
curl -s -X GET "https://ahoyripper.com/src/api.php?action=info&url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3DdQw4w9WgXcQ" \
  -H "Authorization: Bearer ***"\
  -H "Referer: https://ahoyripper.com/"

# Or via query parameter (key appears in server URLs)
curl -s -X GET "https://ahoyripper.com/src/api.php?action=info&url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3DdQw4w9WgXcQ&key=YOUR_API_KEY" \
  -H "Referer: https://ahoyripper.com/"
```

### Lightweight check

`action=check` is a minimal ping with zero server overhead — no dependency on yt-dlp, ffmpeg, or /proc/sys calls. It returns instantly and is safe to call every 10 seconds. Use it for Docker healthchecks and load-balancer probes:

```bash
curl -s "https://ahoyripper.com/src/api.php?action=check" | python3 -m json.tool
# {
#   "status": "ok",
#   "server_time": "2026-08-17T00:00:00+00:00",
#   "server_time_unix": 1752787200,
#   "request_id": "...",
#   "app_version": "...",
#   "php_version": "8.2.0",
#   "api_version": "...",
#   "os": "Linux",
#   "yt_dlp_version": "2026.03.17",
#   "yt_dlp_ok": true,
#   "ffprobe_version": "6.1-1ubuntu3",
#   "ffmpeg_ok": true,
#   "upgrade_url": "https://ahoyvpn.com",
#   "quota_remaining": -1,
#   "quota_limit": 5,
#   "quota_reset": -1,
#   "quota_reset_unix": -1,
#   "source_url": null
# }
```

### Full health check

```bash
curl -s "https://ahoyripper.com/src/api.php?action=health" | python3 -m json.tool
```

Add `&probe=1` to run an end-to-end yt-dlp connectivity probe and verify that yt-dlp can reach YouTube:

```bash
curl -s "https://ahoyripper.com/src/api.php?action=health&probe=1" | python3 -m json.tool
# {
#   "status": "ok",
#   "api_ok": true,
#   "server_time": "2026-08-06T03:30:00+00:00",
#   "server_time_unix": 1749180000,
#   "request_id": "...",
#   "app_version": "...",
#   "php_version": "8.2.0",
#   "api_version": "...",
#   "os": "Linux",
#   "yt_dlp_version": "2026.03.17",
#   "ffmpeg_version": "6.1...",
#   "ffprobe_version": "6.1...",
#   "yt_dlp_ok": true,
#   "ffmpeg_ok": true,
#   "yt_dlp_cache_expires_at": "2026-08-06T03:35:00+00:00",
#   "yt_dlp_cache_ttl_seconds": 3600,
#   "ffmpeg_cache_expires_at": "2026-08-06T03:35:00+00:00",
#   "ffmpeg_cache_ttl_seconds": 3600,
#   "yt_dlp_probe": {
#     "ok": true,
#     "title": "Rick Astley - Never Gonna Give You Up (Official) (Music...",
#     "source_url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
#     "probe_age_seconds": 0
#   },
#   "yt_dlp_probe_cache_expires_at": "2026-08-06T03:35:00+00:00",
#   "yt_dlp_probe_cache_ttl_seconds": 300,
#   "server_uptime_seconds": 86400,
#   "load_avg": 0.15,
#   "memory_available_pct": 72.4,
#   "disk_free_gb": 48.2,
#   "quota_remaining": -1,
#   "quota_limit": 5,
#   "quota_reset": -1,
#   "quota_reset_unix": -1,
#   "source_url": null
# }
```

The probe result:
- `yt_dlp_probe.ok: true` — yt-dlp successfully fetched metadata from YouTube; the server has working outbound connectivity.
- `yt_dlp_probe.ok: false` with an `error_code` — yt-dlp itself is failing. Check `yt_dlp_version` and `ffmpeg_version` to confirm both are installed.

The probe is cached for 5 minutes (`yt_dlp_probe_cache_ttl_seconds: 300`). Repeated calls within that window return the cached result without calling yt-dlp again. This prevents hammering YouTube during health-check storms.

`yt_dlp_probe_cache_expires_at` is `null` when the probe has never been run (no cache exists yet). `yt_dlp_probe_cache_ttl_seconds` shows `300` (the configured TTL) in that case — use it to predict when the next `?probe=1` call will complete.

---

## Supported Platforms

AhoyRipper is powered by [yt-dlp](https://github.com/yt-dlp/yt-dlp) and supports **1873+ platforms**. A comprehensive table of all major platforms with type labels and notes is in [the reference section below](#supported-platforms-reference). Platform-specific error codes (`AGE_RESTRICTED`, `GEOBLOCKED`, `PRIVATE_VIDEO`, `LOGIN_REQUIRED`, `UNSUPPORTED_SITE`, etc.) and per-platform troubleshooting tips are also documented there.

For the full extractor list:

```bash
yt-dlp --list-extractors
```

---

## Installation Steps

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
| `AHOY_IMPERSONATE` | `chrome` | yt-dlp 2024.09+ browser TLS fingerprint impersonation target. Passed as `--impersonate chrome` to yt-dlp, spoofs browser TLS/ALPN fingerprints and dramatically reduces 403/422 bot-detection errors on protected sites (YouTube, Twitter, etc.). Override via `AHOY_IMPERSONATE` env var to change the impersonation target (e.g. `chrome`, `firefox`, `safari`) or set to `''` to disable impersonation entirely. |
| `AHOY_UNLIMITED_KEY` | `RIPPER2026DEV` | API key granting unlimited daily quota. **Change this in production** — generate a secure value with `openssl rand -hex 32`. |
| `AHOY_KEY` | _(same as `AHOY_UNLIMITED_KEY`)_ | Alias for `AHOY_UNLIMITED_KEY`. Use whichever name is more convenient; both env vars set the same value. |
| `PLAUSIBLE_HOST` | _(empty)_ | Hostname of the self-hosted Plausible analytics server. When empty (default), events are routed through AhoyRipper's own `/src/api.php?action=analytics` proxy — no external requests leave the browser and no third-party domain is needed in the CSP. Set to `plausible.io` (or a custom self-hosted domain) to forward events directly to a Plausible server. Set to `''` (empty string) to disable analytics entirely — the endpoint returns 204 silently and no requests are forwarded. |
| `QUOTA_DAILY` | `5` | Daily rip limit for unauthenticated users. Set to a positive integer to increase or decrease the free quota. `-1` or `0` effectively disables the free tier (users must provide a valid `AHOY_UNLIMITED_KEY`). |
| `YTDLP_TIMEOUT` | `45` | Per-request timeout (seconds) for yt-dlp metadata/info operations. Covers the initial metadata fetch and format list retrieval. Increase if the source site is slow to respond or if fetching info for very long videos (e.g. multi-hour livestreams) times out. |
| `YTDLP_DOWNLOAD_TIMEOUT` | `300` | Per-request timeout (seconds) for yt-dlp download operations (the actual media file transfer). The default 300s (5 min) accommodates large files on slow connections. Decrease in resource-constrained environments; increase for high-quality 4K/8K downloads on fast connections. |
| `FFPROBE_TIMEOUT` | `10` | Timeout in seconds for ffprobe post-download verification. ffprobe should finish in well under 10s for any real file; increase this when running on slow storage or with very large files that need extra time for codec probing. |
| `HEALTH_PROBE_TIMEOUT` | `15` | Timeout in seconds for the health probe (`action=health&probe=1`). Override via `HEALTH_PROBE_TIMEOUT` env var. The probe is a lightweight `--dump-json` fetch on a known-short video (Rick Astley), so 15s is plenty. Increase if the probe times out on slow networks or under heavy load. |
| `HEALTH_PROBE_VIDEO_ID` | `dQw4w9WgXcQ` | YouTube video ID used for the health probe. Any stable, publicly accessible YouTube video ID works. Change to a different video if the default is ever geo-restricted or unavailable in your region. |
| `UPGRADE_URL` | `https://ahoyvpn.com` | URL shown to users in rate-limit and quota-exceeded error responses. Set to your own upsell page (Patreon, Ko-fi, etc.) for self-hosted deployments. Must be an absolute URL with scheme. |
| `RATE_LIMIT` | `30` | Request rate limit per IP per minute. Applied to both `info` and `download` actions (both share the same counter). nginx enforces a separate 30r/m shared gate before requests reach PHP; this PHP-layer limit is the per-action ceiling. The `download` action also has its own independent `DL_RATE_LIMIT` counter (default 10/min) that governs download-specific burst limiting. Tune `RATE_LIMIT` and `DL_RATE_LIMIT` independently to discipline clients that pass through nginx but exceed per-action quotas. |
| `DL_RATE_LIMIT` | `10` | Download rate limit per IP per minute. Applies only to the `download` action; the `info` action is governed by `RATE_LIMIT`. Both limits run independently. |
| `YTDLP_PATH` | `/usr/local/bin/yt-dlp` | Path to the yt-dlp binary. Override when yt-dlp is in a non-standard location (e.g. `/usr/bin/yt-dlp` on some systems, or a custom path in a Docker image). Changing this invalidates the yt-dlp version cache. |
| `FFPROBE_PATH` | `/usr/bin/ffprobe` | Path to the ffprobe binary used for post-download codec/resolution verification. Override when ffprobe is in a non-standard location (e.g. `/usr/local/bin/ffprobe` on macOS). Changing this invalidates the ffprobe version cache. |
| `COOKIES_PATH` | _(none)_ | Path to a Netscape-format `cookies.txt` file for authenticated requests (age-restricted YouTube, Spotify, etc.). When set, `--cookies` is passed to yt-dlp automatically. See [cookies section](#passing-cookies-to-yt-dlp) for setup instructions. |
| `MAX_URL_LEN` | `2048` | Maximum URL length in characters. URLs exceeding this limit receive an `INVALID_URL` (400) response. Prevents excessively long URLs from reaching yt-dlp. |
| `MAX_FILENAME_LEN` | `80` | Maximum filename length in characters after sanitization. Filenames longer than this are truncated to this limit. Prevents overly long filenames on filesystems with path length limits. |
| `PROBE_CACHE_TTL` | `300` | Cache TTL in seconds for the yt-dlp connectivity probe in the health endpoint. The probe result is cached to avoid hammering YouTube with repeated health checks. Override via `PROBE_CACHE_TTL` env var in `.env` or docker-compose (e.g. `PROBE_CACHE_TTL=600` for a 10-minute cache). Use `max(1, ...)` clamping so the value is always at least 1 second. |
| `VERSION_CACHE_TTL` | `3600` | Cache TTL in seconds for yt-dlp and ffmpeg version checks. Both the yt-dlp version (checked on every `info`/`download` request) and ffmpeg version (checked on every `download` request) are cached to avoid repeated subprocess calls. Override via `VERSION_CACHE_TTL` env var in `.env` or docker-compose (e.g. `VERSION_CACHE_TTL=7200` for a 2-hour cache). Use `max(1, ...)` clamping so the value is always at least 1 second. |
| `YTDLP_VERSION` | `latest` | yt-dlp version to install in the Docker image. Set to `latest` (default) for the newest release on each build, or pin to a specific version (e.g. `2024.08.06`) for reproducible builds. In non-Docker installs, update yt-dlp via `pip install -U yt-dlp` or `scripts/install-deps.sh`. |

All environment variables are read from the `.env` file in the project root (created above). To update a value after the container is running, edit `.env` and restart:

```bash
docker compose down && docker compose up -d
```

> **Security note:** The default key `RIPPER2026DEV` is only suitable for local development. Never deploy with it in production — anyone who knows it gets unlimited quota.

---

## Analytics

AhoyRipper uses [Plausible Analytics](https://plausible.io) — a self-hosted, cookie-free, and GDPR-compliant analytics platform. No PII leaves the browser, and video URLs in query strings are stripped before any data is sent.

### How it works

The frontend (`public/js/analytics.js`) fires pageview and custom events to AhoyRipper's own `/src/api.php?action=analytics` proxy endpoint. The proxy:
- Strips the `?url=` query parameter (which may contain a video URL) before forwarding
- Strips the full referrer URL, keeping only the hostname
- Strips IP addresses before forwarding (nginx handles this)
- Forwards sanitised events to the configured Plausible server

This means:
- **No third-party requests from the browser** — all analytics stay within the same origin
- **No Plausible domain needed in `connect-src`** — the CSP doesn't need to allow `plausible.io`
- **No PII in analytics** — video URLs and full referrer URLs never reach Plausible
- **Server controls the destination** — configure via the `PLAUSIBLE_HOST` environment variable

### Privacy properties

- Cookie-free: Plausible does not use cookies or any client-side storage
- No IP address tracking: nginx strips IPs before forwarding; Plausible never sees them
- URL stripping: the `?url=` param (video prefill links) is removed server-side
- Full referrer stripped: only the hostname is forwarded, never the full URL

### Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `PLAUSIBLE_HOST` | _(empty)_ | Hostname of your Plausible server. When empty (default), events are routed through AhoyRipper's own `/src/api.php?action=analytics` proxy — no external requests leave the browser and no third-party domain is needed in the CSP. Set to `plausible.io` (or a custom self-hosted domain) to forward events directly to a Plausible server. Set to `''` (empty string) to disable analytics entirely — the endpoint returns 204 silently and no requests are forwarded. |

To completely disable analytics without removing the script, set `PLAUSIBLE_HOST=''` in your environment. The frontend script will still load but will send events to a null destination.

## Tech Stack

- **Engine:** yt-dlp + ffmpeg
- **Web layer:** PHP 8.x
- **Frontend:** Vanilla JS + CSS (no framework)
- **Server:** Nginx + PHP-FPM
- **Analytics:** Plausible (self-hosted, cookie-free, GDPR-compliant)

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
│   └── .well-known/
│       └── security.txt         # RFC 9116 security contact
├── src/
│   ├── api.php                  # yt-dlp API (info, download, health)
│   ├── style.css                # Served at /src/style.css (nginx alias)
│   └── TestUtils.php            # Canonical function copies used by test files
├── deploy/
│   ├── nginx.conf               # Nginx config (production, VPS)
│   └── nginx-docker.conf        # Nginx config (Docker standalone)
├── scripts/
│   ├── install-deps.sh          # Dependency installer + yt-dlp updater
│   ├── health-check.sh          # Deployment health verification script
│   └── generate-sw-version.php  # PWA SW cache version generator
├── tests/
│   ├── run.sh                        # Unified test runner (runs all suites)
│   ├── sanity.sh                     # Shell-based sanity / regression checks
│   ├── api_test.php                  # Unit tests for API action routing and URL validation
│   ├── classify_ytdlp_error_test.php # Unit tests for yt-dlp error classification
│   ├── clean_test.php                # Unit tests for the clean() sanitisation function
│   ├── is_valid_url_test.php         # Unit tests for SSRF URL validation
│   ├── parse_formats_test.php        # Unit tests for parseFormats()
│   └── resolve_playlist_flag_test.php # Unit tests for resolvePlaylistFlag()
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
GET /src/api.php?action=info&url=<url>&sort=<height|filesize|filesize_asc|tbr|quality>&playlist=<0|1>&referer=<url>&key=<api_key>
```

**Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `url` | — | **(required)** URL of the video to rip |
| `sort` | `height` | Format sort order — see table below |
| `playlist` | `0` | Set to `1` to fetch info for all videos in a playlist (`--yes-playlist` flag). By default (`playlist=0`), `--no-playlist` is passed to yt-dlp so single-video URLs always return one result regardless of whether the URL is part of a playlist. Each video in a playlist counts as a separate rip. |
| `referer` | `https://ahoyripper.com/` | Custom HTTP Referer sent to the source platform. Useful for platforms that validate the referer header. Defaults to `https://ahoyripper.com/` which hides the user's video URL from third-party servers. |
| `key` | — | AhoyVPN unlimited API key to bypass the daily 5-rip quota |

The `sort` parameter (optional, default `height`) controls format sort order:
- `height` — quality, highest resolution first (default)
- `filesize` — estimated file size, largest first
- `filesize_asc` — estimated file size, smallest first
- `tbr` — bitrate, highest first
- `quality` — quality tier, highest first (video = pixel height, e.g. 1080p > 720p > 480p; audio = bitrate tier, e.g. 320kbps > 256kbps > 192kbps)
- `audio_quality` — audio formats first, then video/combined; within each group sorts by quality tier descending, then tbr descending

**Sort usage examples:**
```
# Default — best quality first (1080p before 720p before 480p, all combined formats)
GET /src/api.php?action=info&url=https://www.youtube.com/watch?v=dQw4w9WgXcQ

# Smallest file first — useful for bandwidth-constrained or mobile clients
GET /src/api.php?action=info&url=https://www.youtube.com/watch?v=dQw4w9WgXcQ&sort=filesize_asc

# Largest file first — useful when storage is not a constraint and quality matters most
GET /src/api.php?action=info&url=https://www.youtube.com/watch?v=dQw4w9WgXcQ&sort=filesize

# Audio-only browsing — music podcasts, audiobooks, sound effect clips
GET /src/api.php?action=info&url=https://www.youtube.com/watch?v=dQw4w9WgXcQ&sort=audio_quality

# Bitrate-first — high-bitrate streams when bandwidth is plentiful
GET /src/api.php?action=info&url=https://www.youtube.com/watch?v=dQw4w9WgXcQ&sort=tbr
```

The `quality` field in each format is a numeric tier that enables cross-format comparisons:
- **Video formats** (combined or video-only): `quality` equals the pixel height (e.g. `720` = 720p, `1080` = 1080p). This is identical to the `height` field for video formats.
- **Audio-only formats**: `quality` equals the bitrate tier (kbps mapped to tier: `320` ≥ 320kbps, `256` ≥ 256kbps, `192` ≥ 192kbps, `128` ≥ 128kbps, `96` ≥ 96kbps, `64` ≥ 64kbps, `48` < 64kbps). `null` when bitrate is unknown.
- Because video heights (1080, 720, 480) and audio tier values (320, 256, 128) use different scales, the `quality` field is only meaningful for comparing formats of the same type — use it within `type_group` segments, not across them.

The `sort_applied` field (e.g. `"height"`) confirms which sort was applied — useful because the sort is computed server-side and the client renders from the sorted list. The `type_group` field groups formats as the primary sort dimension: `0` = combined (video+audio), `1` = video-only, `2` = audio-only. Formats are always grouped by type first (combined → video-only → audio-only), then sorted within each group by the chosen sort key. For example, with `sort=height` (default): all combined formats appear first sorted by height descending, then all video-only formats sorted by height descending, then all audio-only formats sorted by bitrate descending. The `format_type` field distinguishes `"combined"`, `"video"`, and `"audio"` for display purposes. The `platform` field surfaces yt-dlp's extractor name (e.g. `"YouTube"`, `"Twitter"`, `"TikTok"`) so API consumers can confirm which platform the URL was routed to.

The `label` field is a compact shorthand (e.g. `"720p60 mp4"`). The `description` field provides richer human-readable context from yt-dlp (e.g. `"1920x1080 1080p60 HDR 10bit"`) — use this for display when available. The `format_description` field exposes yt-dlp's raw format notes (e.g. `"720p60 HDR 10bit"`) stripped of the resolution prefix that appears in `description`. The `format_note` field is yt-dlp's original format annotation (e.g. `"DASH audio"` or `"HDR"`).

**Format fields reference:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | `string` | Format selector ID for use with the `format` parameter on download (e.g. `"22"`, `"bestaudio[ext=m4a]"`). |
| `label` | `string` | Compact human-readable label (e.g. `"720p60 HDR mp4"`). Suitable for primary format display. |
| `description` | `string` | Full resolution prefix + format note from yt-dlp (e.g. `"1920x720 720p60 HDR 10bit"`). Use for detailed display. `null` when unavailable. |
| `format_description` | `string\|null` | yt-dlp's raw format annotation without the resolution prefix (e.g. `"720p60 HDR 10bit"`). `null` when yt-dlp does not provide a note. |
| `format_note` | `string\|null` | yt-dlp's original format annotation string (e.g. `"DASH audio"`, `"HDR"`, `"3D"`). `null` when yt-dlp does not annotate this format. |
| `ext` | `string` | File extension / container (e.g. `"mp4"`, `"m4a"`, `"webm"`). |
| `filesize_mb` | `float\|null` | Estimated file size in MB. `null` when yt-dlp does not provide filesize metadata. |
| `height` | `int\|null` | Video vertical resolution in pixels (e.g. `1080`, `720`, `480`). `null` on audio-only formats. |
| `fps` | `int\|null` | Frames per second (e.g. `30`, `60`). `null` on audio-only formats. |
| `quality` | `int\|null` | Numeric quality tier for sorting. Equals `height` for video formats; for audio formats equals the bitrate tier (320 ≥ 320kbps, 256 ≥ 256kbps, etc.). `null` when quality cannot be determined. |
| `tbr` | `float\|null` | Total bitrate in kbps (video + audio combined). Available on most formats; `null` when not reported by yt-dlp. |
| `abr` | `float\|null` | Audio bitrate in kbps. Present on audio-only and combined formats; `null` on video-only formats. |
| `vcodec` | `string` | Video codec (`"avc1.64001F"`, `"vp9"`, `"av1"`, `"none"`). `"none"` on audio-only formats. |
| `acodec` | `string` | Audio codec (`"mp4a.40.2"`, `"opus"`, `"vorbis"`, `"none"`). `"none"` on video-only formats. |
| `format_type` | `string` | `"combined"` (video+audio), `"video"` (video-only), or `"audio"` (audio-only). |
| `type_group` | `int` | Sort grouping key: `0` = combined, `1` = video-only, `2` = audio-only. Used as the primary sort dimension so formats are grouped by media type first. |
| `language` | `string\|null` | ISO 639-1 language code of the format's audio stream (e.g. `"en"`, `"ja"`). `null` when not available or not applicable. |

The `source_url` field in the info response is the exact URL that was ripped — it is always the URL you passed, included so API consumers can match a response back to the source link. `source_url` is also included in error responses so clients can correlate failures with the original request. The `source_url_missing` boolean field distinguishes `MISSING_URL` (no URL provided at all) from other error cases where a URL was given but failed for other reasons — it is `true` only when the request included no URL whatsoever, and `false` for all other responses (including when a malformed URL was provided). The `yt_dlp_version` field reports the version of yt-dlp installed on the server (e.g. `"2026.03.17"`), useful for debugging format availability on older extractors. It is present on all responses including error responses, so clients can correlate errors with a specific yt-dlp build. `uploader_url` is the URL to the channel/uploader page as reported by yt-dlp (e.g. a YouTube channel URL), or `null` when not available from the source. The `url` field is the canonical video page URL (e.g. `https://www.youtube.com/watch?v=...`) as reported by yt-dlp — this is the URL yt-dlp resolved to after any redirects, which may differ from `source_url` for platforms that normalize URLs (e.g. `youtu.be` → `youtube.com/watch?v=...`). The `platform` field surfaces yt-dlp's extractor name (e.g. `"YouTube"`, `"Twitter"`, `"TikTok"`) so API consumers can confirm which platform the URL was routed to — useful when a URL redirects to a different platform (e.g. `youtu.be` → YouTube, or a shortener that resolves to a video platform).

**Success response:**
```json
{
  "request_id": "a3f1b2c9d4e5f678",
  "title": "Video Title",
  "thumbnail": "https://...",
  "url": "https://www.youtube.com/watch?v=...",
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
      "format_description": "720p60 HDR 10bit",
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
      "format_description": "Audio m4a",
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
  "source_url_missing": false,
  "yt_dlp_version": "2026.03.17",
  "api_version": "1.0.0",
  "quota_remaining": 4,
  "quota_limit": 5,
  "quota_reset": "2026-08-28T00:00:00+00:00",
  "quota_reset_unix": 1756080000
}
```

**Info error response (422 — classified yt-dlp error):**
```json
{
  "error": "This video is geo-restricted in your region.",
  "error_code": "GEOBLOCKED",
  "action": "info",
  "request_id": "a3f1b2c9d4e5f678",
  "source_url": "https://www.youtube.com/watch?v=...",
  "source_url_missing": false,
  "yt_dlp_version": "2026.03.17",
  "api_version": "1.0.0",
  "retry_after": 300,
  "quota_remaining": 4,
  "quota_limit": 5,
  "quota_reset": "2026-08-28T00:00:00+00:00",
  "quota_reset_unix": 1756080000,
  "raw_error": "ERROR: [youtube] NGeR...: This video is available in United States."
}
```

> **Note:** `api_version` appears on `action=check`, `action=info`, `action=download`, and `action=health` responses — consistent across all endpoints so API consumers always have the version.

### Info Response Headers

Every `action=info` response — success and error — includes these HTTP headers. Rate-limit headers (`X-RateLimit-*`) and download-rate-limit headers (`X-DL-RateLimit-*`) are always present; the download-specific headers are sent as `-1`/`unavailable` on info-action responses since the info action does not consume download rate slots.

| Header | Description |
|--------|-------------|
| `X-Request-ID` | Unique per-request correlation ID (16 hex chars); use this to correlate browser, API, and server-side logs |
| `X-RateLimit-Limit` | Max requests allowed per window (default 30/min for `info`) |
| `X-RateLimit-Remaining` | Requests left in the current window |
| `X-RateLimit-Reset` | Unix timestamp when the rate limit window resets |
| `X-RateLimit-Window` | Window size in seconds (`60`) |
| `X-DL-RateLimit-Limit` | Max concurrent downloads allowed (default 10, configurable via `DL_RATE_LIMIT` env var). Sent as `-1` (unavailable) on info-action responses since the info action does not consume download rate slots. |
| `X-DL-RateLimit-Remaining` | Download slots left in the current window. Sent as `-1` (unavailable) on info-action responses. |
| `X-DL-RateLimit-Reset` | Unix timestamp when the download rate-limit window resets. Sent as `-1` on info-action responses. |
| `X-DL-RateLimit-Window` | Window size in seconds for the download rate limit (`60`). Sent as `unavailable` on info-action responses. |
| `X-Info-Timeout` | Server-side info timeout in seconds (integer). Clients should set their fetch timeout to at least this value so the client deadline never exceeds the server deadline. The value matches `INFO_TIMEOUT` (default: 45 seconds, configurable via `YTDLP_TIMEOUT` env var). Present on every `info` response — success and error — so clients can always read it for retry timeout guidance. |
| `X-DailyLimit-Limit` | Daily rip limit (default 5, unlimited-key holders see `-1`) |
| `X-DailyLimit-Remaining` | Rips left in the current day (`-1` for unlimited-key holders) |
| `X-DailyLimit-Reset` | Unix timestamp of the next daily reset (midnight UTC) |
| `X-DailyLimit-Window` | Reset window in seconds (`86400`, or `unlimited` for unlimited-key holders) |

The `X-Info-Timeout` header is analogous to `X-Download-Timeout` (documented in the Download Response Headers section) — both tell clients the server-side timeout value so they can size their fetch timeouts appropriately and avoid premature client-side aborts.

**Info success responses** additionally include `Content-Type: application/json` and `Cache-Control: no-store`. Binary download headers are never present on `info` responses — those only apply to `action=download` success (200) responses.

**Info error responses** (any non-200 status) return JSON with the standard error shape — the binary download headers are absent, consistent with all non-200 responses.

The `abr` (audio bitrate, in kbps) is present on audio-only formats (`format_type: "audio"`) and `null` on video formats. The `tbr` (total bitrate, in kbps) is available on most formats and can be used as a proxy for quality when `height` is not available.

**Error codes:**

| Code | Meaning |
|------|---------|
| `400` | Malformed request — missing or invalid URL (`MISSING_URL`, `INVALID_URL`), missing format on download (`MISSING_FORMAT`), or invalid format ID (`INVALID_FORMAT_ID`) |
| `401` | Invalid API key (`INVALID_KEY`) |
| `403` | Request blocked — must originate from ahoyripper.com or ahoyvpn.com (`FORBIDDEN_ORIGIN`) |
| `405` | Method not allowed — API accepts GET only (`METHOD_NOT_ALLOWED`) |
| `406` | Not acceptable — JSON requested (`NOT_ACCEPTABLE`) |
| `422` | URL could not be fetched, parsed, or is unsupported — also returned for geo-blocked, private, copyrighted, or login-required content. See the `error_code` field for detail. |
| `429` | Rate limit exceeded — see `Retry-After` header, `upgrade_url`, and `retry_after` (Unix timestamp) in the response body. See classified error codes below. |
| `502` | Bad gateway — source site or proxy failed (`CONNECTION_FAILED`, `SSL_ERROR`) |
| `503` | Service temporarily unavailable |
| `504` | Gateway timeout — source site did not respond in time (`SOURCE_TIMEOUT`) |

**Classified error codes** (surfaced in the `error_code` field of 422 responses):

| error_code | Meaning | User action |
|------------|---------|-------------|
| `MISSING_URL` | No URL was provided on the request | Paste a valid link from YouTube, Twitter, TikTok, SoundCloud, Instagram, etc. |
| `MISSING_FORMAT` | No format was selected on a download request | Select a format from the list above first |
| `INVALID_URL` | URL is malformed, uses an unsupported scheme, or exceeds the 2048-character limit | Paste a valid public video URL (YouTube, TikTok, X, SoundCloud, Instagram, etc.) |
| `INVALID_FORMAT_ID` | The format ID was rejected as invalid | Refresh to get a fresh format list, then pick a valid format from the list |
| `RATE_LIMIT_EXCEEDED` | Too many requests — rate limit exceeded. The response includes `retry_after` (delta-seconds, integer — seconds to wait before retrying) and `upgrade_url` (AhoyVPN upsell link). | Wait a minute and try again, or upgrade to an unlimited API key |
| `INVALID_KEY` | The API key is invalid or malformed | Use a valid AhoyVPN unlimited key, or leave blank for the free tier |
| `DAILY_LIMIT` | Daily free quota (5 rips/day) has been exhausted | Quota resets at midnight UTC. Get AhoyVPN for unlimited rips |
| `FORBIDDEN_ORIGIN` | Request did not originate from ahoyripper.com or ahoyvpn.com | Requests must come from the AhoyRipper web page — direct API calls are not allowed |
| `GEOBLOCKED` | Video is geo-restricted in your region | Use AhoyVPN to route through an unblocked region |
| `PRIVATE_VIDEO` | Video is private and cannot be downloaded | Try a public video instead |
| `LOGIN_REQUIRED` | Video requires login or subscription on the source platform | Sign in to the platform in your browser, or pass cookies to yt-dlp for server-side auth (see [cookies section](#passing-cookies-to-yt-dlp) for setup) |
| `PARSE_ERROR` | The site returned a non-standard or unparseable response | The site may be temporarily unavailable or not supported |
| `UNSUPPORTED_SITE` | The site is not supported by yt-dlp | Check the supported sites list at github.com/yt-dlp/yt-dlp |
| `PLAYLIST_MISSING` | Playlist not found or no longer exists | Verify the playlist is public and still available |
| `COPYRIGHT_REMOVED` | Content removed due to a copyright claim | This content cannot be redistributed |
| `VIDEO_UNAVAILABLE` | Video has been removed, delisted, or is no longer available | Try another video |
| `AGE_RESTRICTED` | Video is age-restricted and requires verification | Sign in to the source platform to verify your age |
| `SOURCE_RATE_LIMITED` | The source site is rate-limiting requests | Try again in a few minutes |
| `SOURCE_FORBIDDEN` | The source site blocked this request (HTTP 403) | Try a different format or use AhoyVPN to change your exit IP |
| `SOURCE_NOT_FOUND` | The source returned HTTP 404 — the content may have been moved or deleted | Try another video or source |
| `SOURCE_HTTP_ERROR` | The source site returned HTTP 4xx/5xx and is having issues | Try again shortly |
| `SOURCE_TIMEOUT` | The source site took too long to respond | Try a smaller format (audio-only is fastest) or try again when the site is less busy |
| `SSL_ERROR` | Secure connection to the source failed | Try again shortly |
| `CONNECTION_FAILED` | Could not connect to the source | Check your network and try again |
| `CONNECTION_TIMEOUT` | Connection timed out before the source responded. Distinct from `SOURCE_TIMEOUT` — this fires when the TCP handshake stalls (network-level), whereas `SOURCE_TIMEOUT` fires when yt-dlp receives data but the source takes too long. | Try again. If the issue persists, the server's network route to the source may be degraded. |
| `FILE_TOO_LARGE` | File exceeds the server's maximum size | Try audio-only or a lower resolution |
| `FORMAT_UNAVAILABLE` | That format is not available for this video | Choose another from the list |
| `DISALLOWED_CONTENT` | Content not available due to a terms of service violation | This content cannot be redistributed |
| `YTDLP_ERROR` | General yt-dlp error (see `raw_error` field for detail) | Try another format from the list, or wait and try again |
| `FILE_READ_ERROR` | Server-side error — the downloaded file could not be read even though it exists. This is a rare server-side issue. Try again or pick a different format. |
| `DOWNLOAD_EMPTY` | The downloaded file was empty — the source returned no data (not your format choice). Try another format or wait and retry. Your quota was not charged. |
| `VERIFICATION_FAILED` | The downloaded file could not be verified — ffprobe found the file corrupt or unreadable. Try another format. |
| `VERIFICATION_TIMEOUT` | Verification timed out — the file may be valid but could not be confirmed within the server's time limit. Try a smaller format or try again. |
| `DOWNLOAD_CANCELLED` | Download was cancelled — tab closed or connection lost mid-transfer. Your daily quota was not charged. |
| `CONFIG_ERROR` | Browser impersonation is not available on the server. The `curl_cffi` Python library may be missing. | Set `AHOY_IMPERSONATE=` (empty) in `.env` to disable impersonation, or contact the server operator. |
| `DOWNLOAD_TIMEOUT` | Download exceeded the server's per-request timeout (default 5 minutes; configurable via `YTDLP_DOWNLOAD_TIMEOUT`). The file may be too large or the source is slow. Try audio-only or a smaller format. |
| `PROC_OPEN_FAILED` | The info or download process could not be started. Distinct from `YTDLP_ERROR`: this fires when the OS-level `proc_open()` call fails (binary missing, permission denied, or resource exhaustion). Applies to both `info` and `download` actions. The server may be restarting or overloaded — try again shortly. |
| `PROBE_FAILED` | The yt-dlp health probe failed to fetch the test video. The server's yt-dlp installation may be broken, or the source site (YouTube) may be blocking the server. Check `yt_dlp_version` and `ffmpeg_version` in the health response. |
| `SERVICE_UNAVAILABLE` | Server-side lock or rate-limit file could not be opened. The server may be overloaded or starting up. | Try again in a few seconds. |
| `NOT_ACCEPTABLE` | Request did not send an `Accept: application/json` header. The API only serves JSON. | Send `Accept: application/json` on your request. |
| `METHOD_NOT_ALLOWED` | Request used an HTTP method other than GET. The API accepts GET only. | Use GET to call the API. |
| `UNKNOWN_ACTION` | The requested action is not recognized | Use `info`, `download`, `check`, `health`, or `analytics` |

### Download a format
```
GET /src/api.php?action=download&url=<url>&format=<format_id>&filename=<name>&playlist=<0|1>&key=<api_key>
Authorization: Bearer YOUR_API_KEY
```

**Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `url` | — | **(required)** URL of the video to rip |
| `format` | — | **(required)** Format ID from the info response (e.g. `18`, `22`, `bestvideo[height>=720]+bestaudio`) |
| `filename` | `ahoyrip.<ext>` | Custom output filename (alphanumeric, spaces, dots, underscores, hyphens only) |
| `playlist` | `0` | Set to `1` to download all videos in a playlist (`--yes-playlist` flag). Each video counts as a separate rip. |
| `key` | — | AhoyVPN unlimited API key to bypass the daily 5-rip quota |
| `referer` | `https://ahoyripper.com/` | Custom HTTP Referer sent to the source platform. Useful for platforms that validate the referer header. Defaults to `https://ahoyripper.com/` which hides the user's video URL from third-party servers. |

The `format_id` comes from the `id` field in the info response. The API reads the key from the `Authorization: Bearer` header (preferred — keeps the key out of URLs and server logs). A `key` query parameter is also accepted for backwards compatibility but is discouraged.

> **Note:** The free tier allows 5 total rips per day (each call to the info or download API counts as one rip). Switching the sort order re-fetches the format list and counts as an additional rip. Unlimited-key holders have no daily cap.

**Response headers** — every download response includes quota headers plus timeout guidance:

| Header | Description |
|--------|-------------|
| `X-DailyLimit-Limit` | Daily rip limit for this key (e.g. `5`) |
| `X-DailyLimit-Remaining` | Rips remaining after this request |
| `X-DailyLimit-Reset` | Unix timestamp when the quota resets (midnight UTC) |
| `X-DailyLimit-Window` | Reset window in seconds (`86400`) |
| `X-Download-Timeout` | Server-side download timeout in seconds (integer). Clients should set their fetch timeout to at least this value so the client deadline never exceeds the server deadline. The value matches `YTDLP_DOWNLOAD_TIMEOUT` (default: 300 seconds). |
| `X-Info-Timeout` | Server-side info timeout in seconds (integer). Present for consistency with info-action responses. The value matches `INFO_TIMEOUT` (default: 45 seconds). |

**Download error response (422 with classified error):**
```json
{
  "error": "This video is geo-restricted in your region.",
  "error_code": "GEOBLOCKED",
  "request_id": "a3f1b2c9d4e5f678",
  "source_url": "https://www.youtube.com/watch?v=...",
  "yt_dlp_version": "2026.03.17",
  "api_version": "1.0.0",
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
  "yt_dlp_version": "2026.03.17",
  "api_version": "1.0.0",
  "raw_error": "ERROR: [youtube] NGeR...: requested format not available"
}
```

**Download error responses** (any of these may be returned — from pre-rip validation failures like missing URLs/keys, to source-site errors like geo-blocking or timeouts, through to rip-time failures like empty files or cancelled transfers):

| Code | `error_code` | Meaning |
|------|--------------|---------|
| `401` | `INVALID_KEY` | The API key is invalid or malformed. Use a valid AhoyVPN unlimited key, or leave blank for the free tier. |
| `403` | `FORBIDDEN_ORIGIN` | Request did not originate from ahoyripper.com or ahoyvpn.com. Requests must come from the AhoyRipper web page. |
| `400` | `MISSING_URL` | No URL was provided on the download request. The response also includes `"source_url_missing": true` so clients can distinguish this from `INVALID_URL` (a URL was given but malformed). |
| `400` | `MISSING_FORMAT` | No format was selected on the download request. The response also includes `"format_id_missing": true` so clients can distinguish this from `INVALID_FORMAT_ID` (a format ID was given but malformed — `format_id_missing` is `false` in that case). |
| `400` | `INVALID_FORMAT_ID` | The format ID was rejected as invalid — refresh to get a fresh format list, then pick a valid format from the list. The response includes `"format_id_missing": false`. |
| `429` | `DAILY_LIMIT` | Daily free quota (5 rips/day) has been exhausted. Quota resets at midnight UTC. The response body also includes `retry_after` (delta-seconds, integer — seconds until the daily quota resets), `quota_limit` (integer matching `quota_limit` on all other responses), and `upgrade_url` (AhoyVPN upsell link). |
| `429` | `RATE_LIMIT_EXCEEDED` | Too many rapid requests — throttled by the server's per-IP rate limit. Wait ~60 seconds and retry, or use AhoyVPN for unlimited access. |
| `422` | `GEOBLOCKED` | Video is geo-restricted in your region |
| `403` | `AGE_RESTRICTED` | Video is age-restricted and requires verification on the source platform |
| `403` | `PRIVATE_VIDEO` | Video is private and cannot be downloaded |
| `401` | `LOGIN_REQUIRED` | Video requires login or subscription |
| `451` | `COPYRIGHT_REMOVED` | Content removed due to a copyright claim |
| `404` | `UNSUPPORTED_SITE` | The site is not supported by yt-dlp |
| `404` | `PLAYLIST_MISSING` | Playlist not found or no longer exists |
| `422` | `VIDEO_UNAVAILABLE` | Video has been removed, delisted, or is no longer available |
| `429` | `SOURCE_RATE_LIMITED` | The source site is rate-limiting requests |
| `403` | `SOURCE_FORBIDDEN` | The source site blocked this request (HTTP 403) — try a different format or use AhoyVPN |
| `404` | `SOURCE_NOT_FOUND` | The source returned HTTP 404 — the content may have been moved or deleted |
| `502` | `SOURCE_HTTP_ERROR` | The source site returned HTTP 4xx/5xx and is having issues (specific status propagated to response) |
| `502` | `SSL_ERROR` | SSL/TLS error when connecting to the source — try again or use AhoyVPN |
| `504` | `SOURCE_TIMEOUT` | The source site timed out — try a smaller format or audio-only |
| `502` | `CONNECTION_FAILED` | Could not connect to the source |
| `504` | `CONNECTION_TIMEOUT` | Connection timed out before the source responded — TCP handshake stalled (network-level) |
| `413` | `FILE_TOO_LARGE` | File exceeds the server's maximum size |
| `422` | `FORMAT_UNAVAILABLE` | That format is not available for this video |
| `451` | `DISALLOWED_CONTENT` | Content is not available due to a terms of service violation |
| `422` | `YTDLP_ERROR` | General yt-dlp error (see `raw_error` field) |
| `500` | `PROC_OPEN_FAILED` | The download process could not be started — `proc_open()` failed. Either the server is temporarily overloaded (try again shortly), or yt-dlp is not installed, the path is wrong, or permissions are missing (contact the operator). |
| `503` | `SERVICE_UNAVAILABLE` | The quota or rate-limit subsystem is temporarily unavailable — try again in a few seconds. If persistent, the server may be overloaded. |
| `422` | `PARSE_ERROR` | Could not fetch video info during download. The site may be temporarily unavailable. |
| `504` | `DOWNLOAD_TIMEOUT` | Download exceeded the 5-minute server timeout — try a smaller format or audio-only |
| `500` | `FILE_READ_ERROR` | The downloaded file could not be read — rare server-side issue. Try again or pick a different format. |
| `500` | `DOWNLOAD_EMPTY` | The downloaded file was empty or invalid — try another format from the list |
| `500` | `VERIFICATION_FAILED` | The downloaded file could not be verified — ffprobe found it corrupt or unreadable. Try another format. |
| `504` | `VERIFICATION_TIMEOUT` | ffprobe verification timed out — the file may be valid but could not be confirmed within the server's time limit. Try a smaller format or try again. |
| `499` | `DOWNLOAD_CANCELLED` | Download was cancelled — tab closed or connection lost mid-transfer. Your daily quota was not charged. Try again when ready. |
| `503` | `CONFIG_ERROR` | Browser impersonation is not available — the `curl_cffi` Python library may be missing. Set `AHOY_IMPERSONATE=` (empty) to disable impersonation, or update yt-dlp and install `pip install curl_cffi`. |

### Health check / progress
```
GET /src/api.php?action=check           # lightweight internal ping (Docker healthcheck-safe)
GET /src/api.php?action=health          # full system status with resource metrics
GET /src/api.php?action=progress        # alias for health (same response shape)
GET /src/api.php?action=health&probe=1  # include live yt-dlp connectivity probe
POST /src/api.php?action=csp-report     # CSP violation report receiver (nginx report-uri)
POST /src/api.php?action=client-error   # client-side JS error reporting (internal)
POST /src/api.php?action=analytics     # Plausible analytics proxy (browser → server → Plausible)
```

`action=check` is a minimal ping with zero server overhead — no dependency on yt-dlp, ffmpeg, or /proc/sys calls. It returns instantly and is safe to call every 10 seconds. Use it for Docker healthchecks and load-balancer probes:

```json
{
  "status": "ok",
  "action": "check",
  "server_time": "2026-08-17T00:00:00+00:00",
  "server_time_unix": 1752787200,
  "request_id": "a3f1b2c9d4e5f678",
  "app_version": "1.0.0",
  "php_version": "8.2.0",
  "api_version": "1.0.0",
  "yt_dlp_version": "2026.03.17",
  "quota_remaining": -1,
  "quota_limit": 5,
  "quota_reset": -1,
  "quota_reset_unix": -1,
  "source_url": null
}
```

`yt_dlp_version` is present on all endpoints (`check`, `health`, `info`, `download`) for consistent API surface metadata. On `check` it is the cached version string (no additional subprocess call); on `health`/`info`/`download` it confirms the binary was exercised.

`action=health` returns full system status:
```
{
  "status": "ok",
  "api_ok": true,
  "server_time": "2026-05-21T16:00:00+00:00",
  "server_time_unix": 1747843200,
  "request_id": "a3f1b2c9d4e5f678",
  "app_version": "1.0.0",
  "api_version": "1.0.0",
  "php_version": "8.2.0",
  "os": "Linux",
  "yt_dlp_version": "2026.03.17",
  "ffmpeg_version": "ffmpeg version 6.x",
  "ffprobe_version": "ffmpeg version 6.x",
  "yt_dlp_ok": true,
  "ffmpeg_ok": true,
  "yt_dlp_cache_expires_at": "2026-05-21T17:00:00+00:00",
  "yt_dlp_cache_ttl_seconds": 542,
  "ffmpeg_cache_expires_at": "2026-05-21T17:00:00+00:00",
  "ffmpeg_cache_ttl_seconds": 542,
  "yt_dlp_probe_cache_expires_at": "2026-05-21T16:05:00+00:00",
  "yt_dlp_probe_cache_ttl_seconds": 300,
  "server_uptime_seconds": 86400,
  "yt_dlp_probe": {
    "ok": true,
    "title": "Rick Astley - Never Gonna Give You Up (Official Music Video)",
    "source_url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
    "probe_age_seconds": 120
  },
  "load_avg": 0.15,
  "memory_available_pct": 72.4,
  "disk_free_gb": 48.2,
  "quota_remaining": -1,
  "quota_limit": 5,
  "quota_reset": -1,
  "quota_reset_unix": -1,
  "source_url": null
}
```

A failed probe (when yt-dlp cannot fetch the test video) returns `ok: false` with a classified `error_code` and human-readable `error_msg`:

```json
{
  "status": "degraded",
  "api_ok": true,
  "server_time": "2026-05-21T16:00:00+00:00",
  "server_time_unix": 1747843200,
  "request_id": "a3f1b2c9d4e5f678",
  "app_version": "1.0.0",
  "api_version": "1.0.0",
  "php_version": "8.2.0",
  "os": "Linux",
  "yt_dlp_version": "2026.03.17",
  "ffmpeg_version": "ffmpeg version 6.x",
  "ffprobe_version": "ffmpeg version 6.x",
  "yt_dlp_ok": true,
  "ffmpeg_ok": true,
  "yt_dlp_cache_expires_at": "2026-05-21T17:00:00+00:00",
  "yt_dlp_cache_ttl_seconds": 542,
  "ffmpeg_cache_expires_at": "2026-05-21T17:00:00+00:00",
  "ffmpeg_cache_ttl_seconds": 542,
  "yt_dlp_probe_cache_expires_at": "2026-05-21T16:05:00+00:00",
  "yt_dlp_probe_cache_ttl_seconds": 300,
  "server_uptime_seconds": 86400,
  "yt_dlp_probe": {
    "ok": false,
    "error_code": "SOURCE_FORBIDDEN",
    "error_msg": "The source site blocked this request (HTTP 403). Try a different format or use AhoyVPN to change your exit IP.",
    "source_url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
    "probe_age_seconds": 45
  },
  "load_avg": 0.15,
  "memory_available_pct": 72.4,
  "disk_free_gb": 48.2,
  "quota_remaining": -1,
  "quota_limit": 5,
  "quota_reset": -1,
  "quota_reset_unix": -1
}
```

`yt_dlp_probe.error_code` uses the same classified error codes as the `info` and `download` endpoints (e.g. `SOURCE_FORBIDDEN`, `SSL_ERROR`, `CONNECTION_FAILED`, `SOURCE_TIMEOUT`, `PROBE_FAILED`). See the [error codes table](#error-codes) for the full list and their meanings.

`server_uptime_seconds` is Linux-only — available on servers, omitted in Docker containers or non-Linux environments.

`yt_dlp_probe` is only present when the request includes `&probe=1`. It runs a lightweight metadata fetch against a known-stable YouTube video to confirm end-to-end connectivity and parsing capability. The result is cached for 5 minutes; `yt_dlp_probe_cache_expires_at` and `yt_dlp_probe_cache_ttl_seconds` surface the cache expiration so monitoring dashboards can track when the cached result will be refreshed.

`yt_dlp_cache_expires_at` / `yt_dlp_cache_ttl_seconds` track the yt-dlp version cache (1-hour TTL). `ffmpeg_cache_expires_at` / `ffmpeg_cache_ttl_seconds` track the ffmpeg version cache (1-hour TTL). `yt_dlp_probe_cache_expires_at` / `yt_dlp_probe_cache_ttl_seconds` track the yt-dlp connectivity probe cache (5-minute TTL).

`quota_remaining`, `quota_limit`, and `quota_reset` are included in `action=check` and `action=health` responses (as `-1`, the configured limit, and `-1` respectively) for API surface consistency with `action=info` and `action=download` responses. Since `check` and `health` are read-only probes that do not consume quota, `quota_remaining` is `-1` and `quota_reset` is `-1`. `quota_limit` always reflects the configured daily limit (default `5`).

`quota_reset` and `quota_reset_unix` are a dual-field pair that represents the daily quota reset time:

- `quota_reset` — ISO 8601 date string (e.g. `"2026-08-28T00:00:00+00:00"`) for HTTP-header parity and human-readable timestamps.
- `quota_reset_unix` — Unix timestamp integer (e.g. `1756080000`) for clients that prefer integer comparison without date parsing.

Both fields carry the same reset moment. The Unix variant exists because the `X-DailyLimit-Reset` HTTP header accepts only an integer, not an ISO string. For active quota both fields are set; for inactive/unlimited states both are `-1`. Clients should check `quota_remaining === -1` to detect unlimited status rather than comparing against the timestamp fields.

`action=csp-report` receives [Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP) violation reports from browsers. Nginx is configured with a `report-uri /src/api.php?action=csp-report` directive in the CSP-Report-Only header, so violations (e.g., mixed content, inline script attempts) are logged to `error_log` rather than silently ignored. The report body is sanitized before logging — video URLs and referrers are omitted. This endpoint returns `200 OK` to all POST requests so browsers do not retry.

`action=client-error` receives client-side JavaScript error reports from the web UI. The frontend calls this via `navigator.sendBeacon` (fire-and-forget) when an uncaught JS exception occurs, providing operational visibility into browser-side failures without affecting UX. The body is a JSON object with fields: `type` (error class name), `message` (error message), `url` (page URL), `page_request_id` (correlates with server-side access logs), and optionally `stack`, `line`, and `col` for stack-trace details. All string fields are truncated to 500 chars before logging to prevent log flooding. This endpoint returns `200 OK` to all POST requests so browsers do not retry. Logged to `/var/log/ahoyripper/access.log`.

**Response** (all client-error POSTs return `200 OK`):
```json
{
  "ok": true,
  "request_id": "a3f1b2c9d4e5f678",
  "api_version": "1.0.0",
  "yt_dlp_version": "2026.03.17"
}
```

### Rate Limits

| Endpoint | Limit | Window |
|----------|-------|--------|
| `/src/api.php?action=info` | 30 requests | 60 seconds |
| `/src/api.php?action=download` | 10 requests (download-specific) + 30 requests (shared info+download gate) | 60 seconds |

> The download endpoint is gated by **two** independent rate limits simultaneously: the download-specific `$dl_rate_limit` (10 req/min via `X-DL-RateLimit-*` headers) and the shared `$rate_limit` that covers both `info` and `download` together (30 req/min via `X-RateLimit-*` headers). Both must pass for a download request to proceed.

Response headers on every API response:
- `X-Request-ID` — unique per-request correlation ID (16 hex chars); use this when reporting issues to correlate browser, API, and server-side logs
- `X-RateLimit-Limit` — max requests allowed
- `X-RateLimit-Remaining` — requests left in window
- `X-RateLimit-Reset` — Unix timestamp when window resets
- `X-RateLimit-Window` — window size in seconds
- `X-Info-Timeout` — server-side info/action timeout in seconds (integer). Present on every API response (check, health, info, download, analytics, client-error). Clients should set their fetch timeout to at least this value when calling the `info` action to avoid premature client-side aborts. The value matches `INFO_TIMEOUT` (default: 45 seconds, configurable via `YTDLP_TIMEOUT` env var).
- `X-Download-Timeout` — server-side download timeout in seconds (integer). Present on every API response so clients always have the download timeout value available for retry logic without branching on the response type. The value matches `DOWNLOAD_TIMEOUT` (default: 300 seconds).

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

### Download Response Headers

When `action=download` succeeds (HTTP 200), the response includes binary file data with these headers:

| Header | Description |
|--------|-------------|
| `Content-Type` | Detected MIME type of the downloaded file (e.g. `video/mp4`, `audio/mpeg`, `audio/webm`). Determined by `fileinfo` on the actual downloaded file, not assumed from the requested format. |
| `Content-Length` | Exact byte size of the file. Clients can use this to display download progress, validate the transfer completed, or estimate time remaining. |
| `Content-Disposition` | `attachment; filename="<name>.<ext>"` with RFC 5987 UTF-8 encoding for non-ASCII filenames. The filename is derived from the `filename` query parameter (sanitized), or `ahoyrip.<ext>` if not provided. |
| `Accept-Ranges: none` | Explicitly disables resume / partial-fetch range requests. The download is always a full-file transfer. |
| `Transfer-Encoding: identity` | Suppresses PHP's automatic chunked transfer encoding for binary streams, ensuring raw bytes are sent with the declared `Content-Length`. |
| `Cache-Control: no-store, must-revalidate` | Disables caching of the download response by shared caches (proxies, CDNs). The file is an on-demand generated artifact — it must not be stored or re-served without revalidation. |
| `Connection: close` | Closes the connection after this response to prevent keep-alive issues where long-running downloads cause premature client cut-off. Also ensures the full JSON error body (on early-exit failures) is readable before connection closure. |
| `X-Content-Type-Options: nosniff` | Prevents browsers from MIME-sniffing the response away from the declared `Content-Type`. |
| `X-Download-Options: noopen` | Prevents the file from automatically opening in the browser context (forces a save dialog). |
| `X-Download-Timeout` | Server-side download timeout in seconds (integer). Clients should set their fetch timeout to this value so the client deadline never exceeds the server deadline — preventing premature client-side aborts that waste server resources. The value matches `DOWNLOAD_TIMEOUT` (default: 300 seconds). |
| `X-Info-Timeout` | Server-side info timeout in seconds (integer). Present on download error responses alongside `X-Download-Timeout` so clients have the info-action timeout value available when retrying a failed download. The value matches `INFO_TIMEOUT` (default: 45 seconds, configurable via `YTDLP_TIMEOUT` env var). |
| `X-FFProbe-Status` | Set on every download response. `success` means ffprobe confirmed a video stream was present in the downloaded file. `skipped` means ffprobe exited successfully but found no video stream (e.g. an audio-only format, or the container is malformed/empty). `failed` means ffprobe could not verify the file (corrupt, unreadable, or ffprobe execution error) — the user's quota is refunded in this case. Allows clients to distinguish between a successful download and an unverifiable one. |
| `X-Format-Substituted` | Set only when ffprobe detected the downloaded file differs materially from what was requested (different resolution, codec, or container). The value is the actual quality label (e.g. `720p` or `1280x720 vp9`). Absent on all normal downloads — only present when yt-dlp silently substituted a format. |
| `X-DL-RateLimit-Limit` | Download-specific rate limit (10/min). |
| `X-DL-RateLimit-Remaining` | Download requests remaining in the current window. |
| `X-DL-RateLimit-Reset` | Unix timestamp when the download rate limit window resets. |
| `X-RateLimit-Limit` | Shared rate-limit ceiling (30/min). Sent on all responses alongside the download-specific `X-DL-RateLimit-*` headers. Uses `$rate_limit` (the per-minute request-rate limit, shared by both info and download actions) as the shared envelope so generic API consumers always see the request-rate context. The download-specific 10/min limit is reported via `X-DL-RateLimit-*` headers, not these headers. |
| `X-RateLimit-Remaining` | Remaining requests in the shared per-minute window. |
| `X-RateLimit-Reset` | Unix timestamp when the shared per-minute window resets. |
| `X-DailyLimit-*` | Daily quota headers for non-unlimited users (same pattern as `info`). |

On `action=download` failure (any non-200 status), the response is always JSON with the standard error shape. The binary download headers above (`Content-Type`, `Content-Length`, `Content-Disposition`, etc.) are never sent on error responses, but rate-limit headers, quota headers, `X-Info-Timeout`, and `X-Download-Timeout` are included so clients have full context for retry logic.

---

## Supported Platforms Reference

AhoyRipper uses [yt-dlp](https://github.com/yt-dlp/yt-dlp) under the hood. It supports **1873+ platforms** — every site that yt-dlp can extract from works with AhoyRipper.

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
| [Dailymotion](https://dailymotion.com) | Video | |
| [Twitch](https://twitch.tv) | Video + Audio | VODs, clips |
| [Kick](https://kick.com) | Video + Audio | |
| [Rumble](https://rumble.com) | Video | |
| [Bilibili](https://bilibili.com) | Video + Audio | Chinese platform |
| [Niconico](https://nicovideo.jp) | Video + Audio | Japanese platform |
| [Bandcamp](https://bandcamp.com) | Audio | |
| [Mixcloud](https://mixcloud.com) | Audio | |
| [Spotify](https://spotify.com) | Audio | Requires cookies for full access |
| [Apple Music](https://music.apple.com) | Audio | |
| [Deezer](https://deezer.com) | Audio | |
| [Audiomack](https://audiomack.com) | Audio | |
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
| [Google Drive](https://drive.google.com) | Video + Audio | Shared files, Google Photos |
| [Discord](https://discord.com) | Video | Shared video links |
| [WhatsApp](https://whatsapp.com) | Video + Audio | Status, channels |
| [LinkedIn](https://linkedin.com) | Video | |
| [Steam](https://store.steampowered.com) | Video | Steam store trailers |
| [Pornhub](https://pornhub.com) | Video + Audio | |
| [XVideos](https://xvideos.com) | Video + Audio | |
| [xHamster](https://xhamster.com) | Video + Audio | |

> **DRM note:** Netflix, Disney+, Amazon Prime Video, Hulu, Paramount+, Peacock, and Max content with digital rights management (DRM) cannot be ripped. Only non-DRM content from these platforms will work.

### Full extractor list

Run `yt-dlp --list-extractors` locally, or see the [yt-dlp supported sites list](https://github.com/yt-dlp/yt-dlp?tab=readme-ov-file#supported-sites) online. Every extractor that works with yt-dlp works with AhoyRipper.

### Platform categories

**Video platforms:** YouTube, X/Twitter, Facebook, Vimeo, TikTok, Instagram, Telegram, Dailymotion, Twitch, Kick, Rumble, Bilibili, Niconico, Netflix, Disney+, Paramount+, Peacock, HBO Max/Max, Amazon Prime Video, Hulu, and more.

**Audio platforms:** SoundCloud, Bandcamp, Spotify (requires auth), Apple Music, Deezer, Mixcloud, Audiomack, and more.

**Social media:** All platforms above, plus: VK, Douyin, Kuaishou, Weibo, Tumblr, Reddit (video/audio), Pinterest, Snapchat, Telegram, and more.

**Adult content:** Pornhub, xHamster, XNXX, XVideos, and more (all yt-dlp extractors).

### Platforms requiring authentication

Some platforms require you to be logged in to access certain content. If you encounter a `LOGIN_REQUIRED` error:

1. **On YouTube:** Age-restricted videos require authentication. Set the `COOKIES_PATH` environment variable to enable cookie-based authentication — see [cookies section](#passing-cookies-to-yt-dlp) for full setup instructions. No code changes are required.
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
- **Update yt-dlp first**: yt-dlp releases are frequent — an outdated version often causes `YTDLP_ERROR` or `UNSUPPORTED_SITE` on platforms that have since changed their APIs. Update before trying anything else:

```bash
# Self-hosted: update via pip (includes curl_cffi for --impersonate)
pip install -U yt-dlp curl-cffi

# Docker: rebuild to pull the latest yt-dlp and curl_cffi
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
| `DOWNLOAD_TIMEOUT` | Download exceeded the server's per-request timeout (default 5 minutes; configurable). Try a smaller format or audio-only. |
| `FILE_TOO_LARGE` | File exceeds server's maximum size | Choose audio-only or a lower resolution |
| `FORMAT_UNAVAILABLE` | That format is not available for this video | Pick a different format from the list |
| `PARSE_ERROR` | Site returned an unrecognizable response | The site may be temporarily unavailable |
| `RATE_LIMIT_EXCEEDED` | Too many requests (rate limit) | Wait ~60 seconds and retry, or get AhoyVPN for unlimited access |
| `DAILY_LIMIT` | Daily free quota (5 rips) exhausted | Quota resets at midnight UTC. Get AhoyVPN for unlimited rips |
| `DOWNLOAD_EMPTY` | Empty or corrupt output file | Try another format or wait and retry |
| `VERIFICATION_FAILED` | ffprobe could not verify the downloaded file — file may be corrupt | Try another format |
| `VERIFICATION_TIMEOUT` | ffprobe verification timed out — file may be valid but could not be confirmed within the server's time limit | Try a smaller format or try again |
| `DOWNLOAD_CANCELLED` | Download was cancelled (tab closed or connection lost) | Your quota was not charged — try again when ready |
| `FORBIDDEN_ORIGIN` | Request did not originate from ahoyripper.com or ahoyvpn.com | API requests must include a Referer or Origin header from an allowed domain |
| `METHOD_NOT_ALLOWED` | HTTP method not allowed for this endpoint | Use GET for info/download/health; POST for CSP report submission |
| `NOT_ACCEPTABLE` | Client requested an unsupported response format | API only returns application/json — ensure Accept: application/json header is sent |
| `PROC_OPEN_FAILED` | Server could not start the download process | The server may be restarting or overloaded — try again shortly |
| `SERVICE_UNAVAILABLE` | Rate-limit or quota file could not be opened or locked | The server's quota system is temporarily unavailable — retry after 5 seconds (`retry_after` field in response). If persistent, the server may be overloaded or the quota storage may be inaccessible. |
| `DISALLOWED_CONTENT` | Content blocked due to a terms of service or legal violation | This content cannot be redistributed |
| `YTDLP_ERROR` | General yt-dlp error — the site may not be supported or yt-dlp timed out | Try another format, update yt-dlp (`pip install -U yt-dlp`), or try again shortly |
| `CONFIG_ERROR` | Browser impersonation not available — `curl_cffi` library missing | Set `AHOY_IMPERSONATE=` (empty) in `.env` to disable, or install: `pip install curl_cffi` |
| `SOURCE_NOT_FOUND` | Source returned HTTP 404 — content moved or deleted | Try another video |
| `SOURCE_HTTP_ERROR` | Source site returned HTTP 4xx/5xx | Try again shortly |
| `SSL_ERROR` | Secure connection to the source failed | Try again shortly |
| `CONNECTION_FAILED` | Could not connect to the source | Check your network and try again |
| `CONNECTION_TIMEOUT` | TCP handshake stalled before the source responded — network-level timeout (distinct from `SOURCE_TIMEOUT` which fires after data transfer begins) | Try again. If persistent, the server's route to the source platform may be degraded. |
| `INVALID_FORMAT_ID` | Format ID rejected as invalid | Refresh to get a fresh format list, then pick a valid format |
| `MISSING_FORMAT` | No format selected on download | Select a format from the list before downloading |
| `INVALID_KEY` | API key is invalid or malformed | Use a valid AhoyVPN unlimited key, or leave blank for the free tier |
| `PLAYLIST_MISSING` | Playlist not found or no longer exists | Verify the playlist is public and still available |
| `VIDEO_UNAVAILABLE` | Video has been removed, delisted, or is unavailable | Try another video |

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
5. **If you get `SOURCE_FORBIDDEN` (HTTP 403 from the source site):** Many sites (YouTube, TikTok, Twitter) block known VPN/proxy exit IPs. Use AhoyVPN to route through a different server location, or try during off-peak hours when the IP pool is less flaggged.
6. **If you get `AGE_RESTRICTED` on YouTube:** Age-restricted videos require an authenticated session. Pass your browser cookies to yt-dlp — see [Passing cookies to yt-dlp](#passing-cookies-to-yt-dlp). The cookies must include a valid YouTube session that has passed age verification. If you don't have cookies, try a different video that isn't age-gated.

---

## Usage Tips

- **Paste & go** — Paste any supported URL into the input field and the rip starts automatically. No need to press Enter or click a button.
- **Pre-fill a URL via query param** — Append `?url=https://...` to the page URL to pre-load a video. Useful for sharing links directly (e.g. `https://ahoyripper.com/?url=https://www.youtube.com/watch?v=...`).
- **Sort formats** — Use the Quality / Size / Bitrate dropdown above the format cards to reorder the list. Switching the sort re-fetches the format list from the server (costs 1 quota hit) — this is intentional as it lets yt-dlp sort accurately on the server side.
- **Save your sort preference** — The sort choice is remembered in localStorage across visits.
- **Daily quota resets at midnight UTC** — The free tier allows 5 total rips per day. Each call to the `info` or `download` API counts as one rip. Quota resets at 00:00 UTC.
- **API key** — Enter your AhoyVPN unlimited key in the optional field to bypass the daily 5-rip limit.
- **Add to browser search** — Chrome, Firefox, Safari, and Edge can add AhoyRipper as a search engine via OpenSearch. Once added, type `ahoyripper.com` in the URL bar, press Tab, and paste any video link to rip it instantly. Look for the "Add AhoyRipper" prompt when visiting ahoyripper.com, or search your browser's settings for "search engines."
- **Share on social media** — Sharing `ahoyripper.com` or `ahoyripper.com/?url=...` on X/Twitter, Facebook, or Reddit renders a rich link preview with the AhoyRipper logo and description via Open Graph and Twitter Card meta tags. Pre-filling `?url=...` in the shared link lets recipients start a rip with one click.

---

## FAQ

### General

**Q: What is AhoyRipper?**
AhoyRipper is a free, browser-based tool for downloading video and audio from the internet. It streams media directly through our servers — nothing is stored on our infrastructure. No signup, no tracking, no ads.

**Q: How is this different from a browser extension or desktop app?**
AhoyRipper runs entirely in your browser. There's nothing to install — just open the page and paste a link. Your IP address is hidden behind our servers, which can help when a site blocks your connection.

**Q: What platforms are supported?**
Every platform that [yt-dlp supports](https://github.com/yt-dlp/yt-dlp?tab=readme-ov-file#supported-sites) — currently 1873+ sites. The supported platforms table above lists the most popular ones.

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
- **CONNECTION_TIMEOUT** — The TCP connection to the source stalled before any data was received. This is a network-level issue (distinct from SOURCE_TIMEOUT which fires after data transfer begins). Try again — if it persists, the server's route to the source platform may be degraded.
- **DOWNLOAD_TIMEOUT** — The file exceeded the server's per-request timeout (default 5 minutes; configurable via `YTDLP_DOWNLOAD_TIMEOUT`). Try a smaller format or audio-only.
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
| `AHOY_IMPERSONATE` | `chrome` | yt-dlp 2024.09+ browser TLS fingerprint impersonation target. Passed as `--impersonate chrome` to yt-dlp, spoofs browser TLS/ALPN fingerprints and dramatically reduces 403/422 bot-detection errors on protected sites (YouTube, Twitter, etc.). Override via `AHOY_IMPERSONATE` env var (e.g. `chrome`, `firefox`, `safari`) or set to `''` to disable impersonation entirely. |
| `QUOTA_DAILY` | `5` | Daily rip limit per IP for unauthenticated requests. Each call to `info` or `download` counts as one rip. Set to a higher number (e.g. `100`) for less restrictive quotas, or use an unlimited API key to bypass the daily cap entirely. |
| `YTDLP_TIMEOUT` | `45` | Timeout in seconds for the info action (metadata fetch). If yt-dlp does not return within this window, the process is terminated and a `SOURCE_TIMEOUT` error is returned. Override via `YTDLP_TIMEOUT` env var (e.g. `YTDLP_TIMEOUT=60`). The download action has its own separate timeout controlled by `YTDLP_DOWNLOAD_TIMEOUT`. |
| `YTDLP_DOWNLOAD_TIMEOUT` | `300` | Timeout in seconds for the download action (file download). Large media files may require longer than the default 5 minutes; increase this for high-resolution or slow-source downloads. Override via `YTDLP_DOWNLOAD_TIMEOUT` env var (e.g. `YTDLP_DOWNLOAD_TIMEOUT=600`). The info action has its own separate timeout controlled by `YTDLP_TIMEOUT`. |
| `YTDLP_PATH` | `/usr/local/bin/yt-dlp` | Path to the yt-dlp binary. Override this when yt-dlp is installed in a non-standard location (e.g. `/usr/bin/yt-dlp` on some systems, or a custom path in a Docker image). The version cache is keyed on this path, so changing it invalidates the stale version cache. |
| `FFPROBE_PATH` | `/usr/bin/ffprobe` | Path to the ffprobe binary used for post-download codec/resolution verification. Override this when ffprobe is in a non-standard location (e.g. `/usr/local/bin/ffprobe` on macOS or custom Docker images). The version cache is keyed on this path, so changing it invalidates the stale version cache. |
| `FFPROBE_TIMEOUT` | `10` | Timeout in seconds for ffprobe post-download verification. ffprobe should finish in well under 10s for any real file; increase this when running on slow storage or with very large files that need extra time for codec probing. |
| `HEALTH_PROBE_TIMEOUT` | `15` | Timeout in seconds for the `action=health&probe=1` connectivity probe. Override via `HEALTH_PROBE_TIMEOUT` env var. The probe is a lightweight `--dump-json` fetch on a known-short video, so 15s is plenty. Increase if the probe times out on slow networks or under heavy load. |
| `HEALTH_PROBE_VIDEO_ID` | `dQw4w9WgXcQ` | YouTube video ID used for the health probe. Any stable, publicly accessible YouTube video ID works. Change this to a different video if the default is ever geo-restricted or unavailable in your region. |
| `DL_RATE_LIMIT` | `10` | Download rate limit per IP per minute. Protects the server against burst download activity. The `info` action has a separate limit of 30 requests/min — both limits are independent. |
| `RATE_LIMIT` | `30` | Request rate limit per IP per minute. Applied to both `info` and `download` actions (both share the same rate limiter). Tune independently of the nginx-level rate limit to discipline clients that pass through nginx but exceed per-action quotas. |
| `AHOY_UNLIMITED_KEY` | `RIPPER2026DEV` | API key that grants unlimited daily quota. **Change in production.** Set to a long random string (e.g. `openssl rand -hex 32`) and pass to the container via `-e` or your orchestration layer. |
| `AHOY_KEY` | _(same as `AHOY_UNLIMITED_KEY`)_ | Alias for `AHOY_UNLIMITED_KEY`. Use whichever name is more convenient; both env vars set the same value. |
| `PLAUSIBLE_HOST` | _(empty)_ | Hostname of the self-hosted Plausible analytics server. When empty (default), events are routed through AhoyRipper's own `/src/api.php?action=analytics` proxy — no external requests leave the browser and no third-party domain is needed in the CSP. Set to `plausible.io` (or a custom self-hosted domain) to forward events directly to a Plausible server. Set to `''` (empty string) to disable analytics entirely — the endpoint returns 204 silently and no requests are forwarded. |
| `YTDLP_VERSION` | `latest` | yt-dlp version to install in the Docker image. Set to `latest` (default) for the newest release on each build, or pin to a specific version (e.g. `2024.08.06`) for reproducible builds. When pinned, the Docker build verifies the SHA256 checksum and confirms the installed version matches. In non-Docker installs, this variable is not used — update yt-dlp via `pip install -U yt-dlp` or `scripts/install-deps.sh`. |
| `UPGRADE_URL` | `https://ahoyvpn.com` | URL shown to users in rate-limit and quota-exceeded error responses. Set to your own upsell page (Patreon, Ko-fi, etc.) for self-hosted deployments. Must be an absolute URL with scheme. |
| `COOKIES_PATH` | _(none)_ | Path to a Netscape-format `cookies.txt` file for authenticated requests (age-restricted YouTube, Spotify, etc.). When set, `--cookies` is passed to yt-dlp automatically. Mount the file into the container and set the path here (e.g. `/cookies.txt`). See [cookies section](#passing-cookies-to-yt-dlp) for setup instructions. |
| `MAX_URL_LEN` | `2048` | Maximum URL length in characters. URLs exceeding this limit receive an `INVALID_URL` (400) response. Prevents excessively long URLs from reaching yt-dlp. |
| `MAX_FILENAME_LEN` | `80` | Maximum filename length in characters after sanitization. Filenames longer than this are truncated to this limit. Prevents overly long filenames on filesystems with path length limits. |
| `PROBE_CACHE_TTL` | `300` | Cache TTL in seconds for the yt-dlp connectivity probe in the health endpoint. The probe result is cached to avoid hammering YouTube with repeated health checks. Override via `PROBE_CACHE_TTL` env var in `.env` or docker-compose (e.g. `PROBE_CACHE_TTL=600` for a 10-minute cache). Use `max(1, ...)` clamping so the value is always at least 1 second. |
| `VERSION_CACHE_TTL` | `3600` | Cache TTL in seconds for yt-dlp and ffmpeg version checks. Both the yt-dlp version (checked on every `info`/`download` request) and ffmpeg version (checked on every `download` request) are cached to avoid repeated subprocess calls. Override via `VERSION_CACHE_TTL` env var in `.env` or docker-compose (e.g. `VERSION_CACHE_TTL=7200` for a 2-hour cache). Use `max(1, ...)` clamping so the value is always at least 1 second. |

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
- curl_cffi (Python library for browser impersonation — dramatically reduces 403/422 errors on protected sites; included by `scripts/install-deps.sh`)
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

**Security headers:** All API responses include a comprehensive set of HTTP security headers applied at the nginx and PHP level:

| Header | Value | Purpose |
|--------|-------|---------|
| `Content-Security-Policy` | `default-src 'self'; ...` | Prevents cross-site script injection, inline scripts restricted to fonts only |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` | Enforces HTTPS (HSTS) — preloaded in browser trust stores |
| `X-Frame-Options` | `SAMEORIGIN` | Prevents AhoyRipper pages from being embedded in iframes (clickjacking protection) |
| `X-Content-Type-Options` | `nosniff` | Prevents browsers from MIME-sniffing responses away from declared Content-Type |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Limits referrer leakage when navigating to third-party thumbnail/CDN hosts |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), interest-cohort=()` | Disables all browser APIs not used by AhoyRipper |
| `Cross-Origin-Opener-Policy` | `same-origin` | Prevents cross-origin documents from interacting with AhoyRipper's window |
| `Cross-Origin-Resource-Policy` | `same-origin` | Prevents cross-origin resources from loading AhoyRipper's assets |
| `X-Robots-Tag` | `noindex, noai, noimage, noydir` | Instructs crawlers not to index, cache, or AI-train on API responses |
| `Report-To` / `Reporting-Endpoints` | csp-report endpoint | Receives CSP violation reports for monitoring |
| `X-Download-Options` | `noopen` | Prevents direct execution of downloaded files (IE/legacy compat) |

API endpoints additionally enforce `Referer` validation — requests without a referer originating from `ahoyripper.com` or `ahoyvpn.com` are rejected with HTTP 403. The CSP report-uri endpoint (`/src/api.php?action=csp-report`) receives and sanitizes CSP violation reports from browsers and logs them for security monitoring.

**Responsible disclosure:** If you discover a security vulnerability, please report it to `security@ahoyripper.com`. Include a description of the issue and any relevant details. You can expect a response within 48–72 hours on business days.

**Scope:** Reports are accepted for the AhoyRipper application, its API, and infrastructure. Do not attempt to exploit vulnerabilities for research purposes — report only.

---

## Legal

For personal use only. Respect copyright. This tool is provided as-is. DMCA requests: dmca@ahoyvpn.com

---

## Hosting / Support

- Main site: https://ahoyripper.com (or ahoyvpn.com/rip)
- VPN: https://ahoyvpn.com
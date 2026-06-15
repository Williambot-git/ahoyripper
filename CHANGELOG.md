# Changelog

All notable changes to AhoyRipper are documented here.

AhoyRipper follows [Keep a Changelog](https://keepachangelog.com/) conventions.
Unreleased changes are listed under `## [Unreleased]` and promoted to a version
on release day.

Format: `## [YYYY-MM-DD] X.Y.Z` — date is the git tag/release date.
Zero-padded fields only where they appear in yt-dlp conventions (e.g. `2026.03.17`).

---

## [Unreleased]

### Added
- Initial `CHANGELOG.md` — project version history now tracked here.

---

## [2026.06.15] — https://github.com/Williambot-git/ahoyripper

> Ships on top of yt-dlp [2026.03.17](https://github.com/yt-dlp/yt-dlp/releases/tag/2026.03.17).

### Added

#### Core ripping
- **1872+ platform support** via yt-dlp — every extractor that works with yt-dlp
  works with AhoyRipper. Platforms include YouTube, X/Twitter, TikTok, SoundCloud,
  Instagram, Facebook, Vimeo, Reddit, VK, Pornhub, Twitch, Kick, Rumble, Bilibili,
  Niconico, Bandcamp, Mixcloud, Spotify, Netflix, Disney+, and more.
- **`info` action** — fetches video metadata and returns a sorted format list
  with `id`, `label`, `description`, `ext`, `filesize_mb`, `height`, `quality`,
  `fps`, `tbr`, `abr`, `vcodec`, `acodec`, `format_type`, `type_group`,
  `language`, and `source_url` for each format.
- **`download` action** — streams the selected format directly to the client.
  File is written to a temp directory with a random name, streamed, then deleted.
- **`check` action** — lightweight ping for Docker healthchecks and load-balancer
  probes. Zero dependency on yt-dlp, ffmpeg, or /proc/sys. Returns instantly.
- **`health` / `progress` action** — full system status including `yt_dlp_version`,
  `ffmpeg_version`, `yt_dlp_ok`, `ffmpeg_ok`, `server_uptime_seconds`,
  `load_avg`, `memory_available_pct`, `disk_free_gb`, and version cache TTLs.
  Optional `&probe=1` runs a live yt-dlp connectivity check against a known
  YouTube video (result cached 5 minutes).

#### Format sorting
- `sort` param on `info` action: `height` (default), `filesize`, `filesize_asc`,
  `tbr`, `quality`.
- Format cards grouped by type: **Video + Audio**, **Video Only**, **Audio Only**.
- `X-Format-Substituted` response header on download when ffprobe detects the
  delivered file differs materially from the requested format (different resolution
  or container). Frontend shows a 5-second toast notice.

#### Security
- **HTTPS required** — all server configs enforce TLS; HTTP redirects to HTTPS.
- **CSP (Content Security Policy)** — multi-layer enforcement with both
  `Content-Security-Policy-Report-Only` (enforcement via nginx) and
  `Content-Security-Policy` (PHP override on API responses). Includes
  `report-to csp-report` (modern Reporting API, Chromium 84+) and `report-uri`
  (legacy fallback). Reporting endpoint at `POST /src/api.php?action=csp-report`
  logs sanitized violations to `/var/log/ahoyripper/csp-reports.log`.
- **Security headers** — `Strict-Transport-Security` (max-age=1 year, includeSubDomains,
  preload), `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `X-Download-Options: noopen`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy` (camera, microphone, geolocation, interest-cohort all denied),
  `Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Resource-Policy: same-origin`.
  `COEP` (Cross-Origin-Embedder-Policy) is deliberately omitted — require-corp
  breaks cross-origin thumbnail loads from CDN.
- **AI crawler blocking** — `X-Robots-Tag: noindex, noai, noimage, noydir` on all
  responses. `robots.txt` blocks 20+ AI training crawlers including GPTBot,
  ClaudeBot, Google-Extended, AdsBot, FacebookBot, and more.
- **CORS hardening** — COOP/CORP headers prevent cross-origin document access.
  API rejects requests not originating from `ahoyripper.com` or `ahoyvpn.com`
  (returns `403 Forbidden`).
- **Shell injection prevention** — yt-dlp called via `proc_open` with
  `bypass_shell=true` array syntax. No shell metacharacters in format_id
  (`preg_match('/^[a-zA-Z0-9_.,<>=!\\[\\]+\\/-~()*%@!\'"]+$/')`).
  Derived filename sanitized (alphanumeric, space, dot, underscore, hyphen only;
  CR/LF stripped before sanitization to prevent Content-Disposition header injection).
- **SSRF prevention** — `isValidUrl()` requires HTTPS, rejects private IP ranges
  (127.x, 10.x, 172.16-31.x, 192.168.x, 169.254.x), and rejects IPv6 loopback/link-local.
- **RFC 5987 filename encoding** — non-ASCII filenames use `filename*=UTF-8''...`
  encoding in `Content-Disposition` to prevent encoding-related injection.
- **Cookie authentication** — optional `COOKIES_PATH` env var mounts a
  Netscape-format cookies file. When set, `--cookies` is passed to yt-dlp
  automatically, enabling authenticated ripping for age-restricted YouTube,
  Instagram private posts, Spotify, and other login-walled platforms.
- **`security.txt`** (RFC 9116) at `/.well-known/security.txt`.

#### Rate limiting & quotas
- **Per-minute rate limits** — `info`: 30 req/min, `download`: 10 req/min.
  Limits enforced atomically via `flock` on a temp file. Every response
  includes `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`,
  `X-RateLimit-Window`, and `X-DL-RateLimit-*` (download-specific) headers.
- **Daily quota** — 5 rips/day per IP for unauthenticated requests.
  Configurable via `QUOTA_DAILY` env var. Resets at midnight UTC.
  Quota is not burned on failed rips — failures (parse error, classified error,
  download failure) automatically refund the increment.
  Unlimited-key holders bypass quota entirely (signaled with `-1` in headers).
- **API key** — `Authorization: Bearer <key>` header (preferred) or `?key=<key>`
  query param. Unlimited key grants unlimited daily quota and bypasses rate limits.
  Default key (`RIPPER2026DEV`) is for local development only.

#### Error classification
- **`classifyYtdlpError()`** maps raw yt-dlp stderr to structured error codes with
  user-friendly messages. 20+ classified codes:
  `GEOBLOCKED`, `PRIVATE_VIDEO`, `LOGIN_REQUIRED`, `UNSUPPORTED_SITE`,
  `PLAYLIST_MISSING`, `COPYRIGHT_REMOVED`, `VIDEO_UNAVAILABLE`, `AGE_RESTRICTED`,
  `SSL_ERROR`, `SOURCE_TIMEOUT`, `CONNECTION_FAILED`, `FILE_TOO_LARGE`,
  `FORMAT_UNAVAILABLE`, `DISALLOWED_CONTENT`, `SOURCE_FORBIDDEN`, `SOURCE_NOT_FOUND`,
  `SOURCE_SERVER_ERROR`, `SOURCE_HTTP_ERROR`, `SOURCE_RATE_LIMITED`, `DOWNLOAD_TIMEOUT`,
  `DOWNLOAD_EMPTY`, `DOWNLOAD_CANCELLED`.
- HTTP status codes mapped per error category (e.g. `GEOBLOCKED` → 451,
  `SOURCE_RATE_LIMITED` → 429, `SOURCE_TIMEOUT` → 504).
- `raw_error` field surfaces yt-dlp's original output for diagnostics.
- `retry_after` Unix timestamp on rate-limit and timeout responses for
  client-side countdown UI.

#### Process management
- **Timeout enforcement** — global `(time() - $start) > $timeout` as authoritative
  timeout (not `stream_set_timeout` which only closes the pipe, not the loop).
  SIGKILL via `proc_terminate($proc, 9)` on timeout. `$proc = null` sentinel
  prevents double-`proc_close()`. stdin explicitly closed before reading to
  prevent hangs.
- **`--socket-timeout`** passed to yt-dlp (info: timeout-5s; download: timeout-15s)
  so PHP's outer timeout always fires first and produces a classified
  `SOURCE_TIMEOUT` error rather than an unclassified `CONNECTION_FAILED`.
- **`--progress-template ""`** — suppresses all yt-dlp progress output to stderr
  which would otherwise corrupt the JSON parse in info action and pollute error
  classification in download action. Passed as `json_encode('')` (`"`) which
  yt-dlp interprets as an empty template.
- **Download temp file cleanup** — `register_shutdown_function` catches unexpected
  exits (fatal errors, connection aborts). Both timeout and success paths also
  clean up explicitly. Glob pattern matches the randomly-named output file.
- **`clearstatcache(true, $actual_file)`** before `filesize()` — prevents stale
  metadata on long-running PHP processes hitting the same path.
- **`SIGPIPE` suppression** — `pcntl_signal(SIGPIPE, SIG_IGN)` prevents PHP
  process termination when a client aborts mid-stream during the download loop.
- **`Transfer-Encoding: identity`** — suppresses PHP's automatic chunked transfer
  encoding so `Content-Length` is respected for binary downloads.
- **`Connection: close`** — prevents keep-alive from causing premature cut-off
  on long-running downloads.

#### Frontend (public/index.php)
- Single-page application with dark theme matching ahoyVPN brand.
- Auto-fetches on URL paste (300ms delay for UI readiness).
- **Quota UI** — shows remaining daily rips, `low` class when 1–2 remaining,
  upgrade link prominent when exhausted. Persisted to `localStorage`. Hidden for
  unlimited-key holders.
- **Sort selector** — dropdown to change sort order (`height`, `filesize`,
  `filesize_asc`, `tbr`, `quality`). Persisted to `localStorage`. Re-fetches
  with new sort on change.
- **Format cards** — grouped headers (Video + Audio / Video Only / Audio Only),
  badge colors, size display with `≈` prefix for estimated values, language badge,
  keyboard accessible (Tab + Enter/Space), `title` attribute with full format info.
  `description` field used for display when available (carries yt-dlp's rich format
  notes like "720p60 HDR 10bit").
- **Error hints** — `ERROR_HINTS` map surfaces actionable messages for each error
  code (with AhoyVPN upgrade links for rate-limit and quota errors).
- **Retry countdown** — `retry_after` Unix timestamp from 429/504 responses used
  to show human-readable "try again in N minutes/seconds" appended to error messages.
- **Abort timeout** — `AbortSignal.timeout(300000)` (5 minutes) on download fetch
  matches server-side `DOWNLOAD_TIMEOUT`. Info fetch has 60-second client timeout.
- **Rip Again** — resets UI, clears input, scrolls to top smoothly.
- **Click-outside dismiss** — clicking outside the error box dismisses it.
- **Quota refresh** — `updateQuotaFromHeaders()` reads `X-DailyLimit-*` headers
  from every info response and updates the quota display.

#### PWA & SEO
- **Service Worker** (`sw.js`) — caches app shell for offline use.
- **Web App Manifest** (`manifest.json`) — `display: standalone`, theme color,
  start URL, icons at multiple resolutions.
- **OpenSearch** (`/opensearch.xml`) — browser search bar integration.
- **Meta tags** — `robots` (dynamic: `noindex, follow` when URL param present),
  `theme-color`, `apple-mobile-web-app-*` tags, `viewport` with `viewport-fit=cover`.
  `X-Robots-Tag: noai` on all pages blocks AI training crawlers.
- **Open Graph + Twitter Card** — full og:image (1200×630), og:type, og:title,
  og:description, twitter:card, twitter:site.
- **Canonical URL** — set to `https://ahoyripper.com`.
- **`security.txt`** (RFC 9116) — Contact, Encryption, Policy, and Acknowledgements.
- **`sitemap.xml`** — full list of site URLs for search engine indexing.

#### Performance
- **Gzip compression** — nginx compresses text/plain, text/css,
  application/javascript, application/json, application/xml, image/svg+xml.
- **Static asset caching** — immutable `Cache-Control` (max-age=1 year) on
  versioned/cached assets (fonts, manifest, icons).
- **Version caching** — yt-dlp and ffmpeg versions cached to temp files
  (1-hour TTL) to avoid spawning processes on every health check.
- **`skip-download`** on info action — metadata only, no file downloaded.

#### API reference
- `GET /src/api.php?action=info&url=<url>&sort=<sort>` — metadata + format list.
- `GET /src/api.php?action=download&url=<url>&format=<id>&filename=<name>` —
  binary file download.
- `GET /src/api.php?action=check` — lightweight ping.
- `GET /src/api.php?action=health&probe=1` — full system status.
- `POST /src/api.php?action=csp-report` — CSP violation receiver.
- Bearer token auth, `X-Request-ID` correlation IDs, full response headers
  for rate limits, quotas, and error codes on every response.

#### Developer experience
- **PHP unit tests** — `api_test.php` (139 tests): `isValidUrl()` SSRF/IP range
  rejection, `classifyYtdlpError()` all 20+ codes, format_id validation regex,
  derived filename sanitization, CRLF injection prevention, rating pair validation,
  `clean()` zero-edge-case handling.
- **parseFormats tests** — `parse_formats_test.php` (105 tests): metadata
  extraction, format card fields, quality tier mapping, label/description building,
  sort order, filesize estimation, yt-dlp error classification, malformed input.
- **Sanity checks** — `sanity.sh` (816 lines): binary presence, PHP syntax
  on all .php files, deprecated flag check, YouTube URL-rewrite and
  --extractor-args bypass absence, geo-bypass enabled, --user-agent present,
  required files, security headers, CSP worker-src, rate limits, format_id
  validation allows yt-dlp selector chars, HSTS includeSubDomains, nginx.conf
  redirect order, dotfile catch-all, manifest MIME type, API key in info action,
  quota undo for all failure paths, download exit-code handling, timeout
  double-close guard, thumbnail CDN domains in CSP, www redirect order,
  security.txt RFC 9116 compliance, COOP/CORP, RFC 5987 encoding,
  Connection: close, and 40+ more checks.
- **CI pipeline** (`.github/workflows/ci.yml`) — PHP syntax check, unit tests,
  required files, security headers, CSP on check endpoint, rate limits,
  format_id validation, deprecated flags, shell injection prevention,
  Docker build + healthcheck polling (up to 60s for php-fpm initialization),
  container health verification (check + health + index endpoints),
  COOP/CORP header verification, custom 404 on unknown routes.
- **`scripts/install-deps.sh`** — automated dependency installation/updating for
  yt-dlp (standalone binary, respects current install method), ffmpeg, PHP modules.

#### Infrastructure
- **Docker** — `Dockerfile` + `docker-compose.yml`. PHP-FPM + nginx in one image.
  Healthcheck via `action=check`. All environment variables supported.
- **nginx** — production config (`deploy/nginx.conf`) and Docker config
  (`deploy/nginx-docker.conf`). Dynamic PHP-FPM socket via `map` directive.
  Server-level security headers, CSP, gzip, static caching, error pages,
  CSP report endpoint, dotfile deny, www redirects.
- **PHP defaults hardened** — `memory_limit` raised to 256M for large downloads,
  `ignore_user_abort(true)` ensures downloads complete even if client disconnects.
  `header_remove('X-Powered-By')` hides PHP version.

#### Environment variables
| Variable | Default | Description |
|----------|---------|-------------|
| `AHOY_USER_AGENT` | Chrome 136 UA | yt-dlp browser UA (overrides python-requests default) |
| `QUOTA_DAILY` | `5` | Daily rip limit per IP for unauthenticated requests |
| `YTDLP_TIMEOUT` | `45` | Info action timeout (seconds) |
| `YTDLP_DOWNLOAD_TIMEOUT` | `300` | Download action timeout (seconds) |
| `AHOY_UNLIMITED_KEY` | `RIPPER2026DEV` | API key for unlimited quota (change in production) |
| `COOKIES_PATH` | _(none)_ | Path to Netscape cookies.txt for authenticated ripping |

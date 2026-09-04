<?php
declare(strict_types=1);

/**
 * AhoyRipper - API Endpoint
 * Handles: info extraction, format listing, and download serving
 */

// Production hardening — explicitly disable error display at runtime so that
// even if php.ini has display_errors=On (a misconfigured production setup),
// no PHP warnings/notices can leak into API JSON responses.
// log_errors=On is preserved so errors are still written to error_log.
error_reporting(0);
ini_set('display_errors', '0');

// These constants are used throughout the file (yt-dlp path, timeouts, rate limits).
define('AHOYRIPPER_VERSION', require __DIR__ . '/version.php');

// Path to yt-dlp binary — configurable via YTDLP_PATH env var so deployments
// can override the default /usr/local/bin/yt-dlp without editing source.
// Defined early because the version-probe proc_open (line ~533) runs before
// the constants section and needs this value before any other constants exist.
define('YTDLP_PATH', getenv('YTDLP_PATH') ?? '/usr/local/bin/yt-dlp');

// Path to ffprobe binary — configurable via FFPROBE_PATH env var so deployments
// can override the default /usr/bin/ffprobe (e.g. to /usr/local/bin/ffprobe).
// Used for post-download codec/resolution verification in the download action.
// The ffprobe binary path is also used as the cache-key filename for the ffprobe
// version cache so that changing FFPROBE_PATH invalidates stale cache entries.
define('FFPROBE_PATH', getenv('FFPROBE_PATH') ?? '/usr/bin/ffprobe');

// Timeout (seconds) for ffprobe post-download verification. ffprobe should finish
// in well under 10s for any real file; 10s is generous for large or slow files.
// Override via FFPROBE_TIMEOUT env var (e.g. FFPROBE_TIMEOUT=20 in .env).
// Use an explicit guard: getenv() returns false for unset AND '' for empty-string;
// the guard ensures empty-string (a valid Docker env:) is treated the same as unset,
// falling through to the documented default. min=1 prevents zero/negative values.
$_raw = getenv('FFPROBE_TIMEOUT');
define('FFPROBE_TIMEOUT', max(1, ($_raw !== false && $_raw !== '') ? (int)$_raw : 10));
unset($_raw);

// TTL (seconds) for the yt-dlp connectivity probe cache in the health endpoint.
// PROBE_CACHE_TTL (5 minutes) prevents hammering YouTube with repeated health checks while
// keeping the probe result fresh enough to detect real outages. Override via
// PROBE_CACHE_TTL env var in .env or docker-compose (e.g. PROBE_CACHE_TTL=600 for 10 minutes).
// Use an explicit guard: getenv() returns false for unset AND '' for empty-string;
// the guard ensures empty-string is treated the same as unset, falling through to
// the documented default. min=1 prevents zero/negative values.
$_raw = getenv('PROBE_CACHE_TTL');
define('PROBE_CACHE_TTL', max(1, ($_raw !== false && $_raw !== '') ? (int)$_raw : 300));
unset($_raw);

// TTL (seconds) for yt-dlp and ffprobe binary version caches.
// Cached for 1 hour by default — version rarely changes and probing on every health check
// is unnecessary overhead. Override via VERSION_CACHE_TTL env var in .env or docker-compose
// (e.g. VERSION_CACHE_TTL=7200 for 2-hour cache, VERSION_CACHE_TTL=300 for 5-minute cache).
// Use an explicit guard: getenv() returns false for unset AND '' for empty-string;
// the guard ensures empty-string is treated the same as unset, falling through to
// the documented default. min=1 prevents zero/negative values.
$_raw = getenv('VERSION_CACHE_TTL');
define('VERSION_CACHE_TTL', max(1, ($_raw !== false && $_raw !== '') ? (int)$_raw : 3600));
unset($_raw);

// YouTube video ID used for the /health endpoint connectivity probe. Rick Astley's
// "Never Gonna Give You Up" is reliably available, long enough to detect stream stalls,
// and unlikely to be geo-restricted or age-gated. Configurable via HEALTH_PROBE_VIDEO_ID
// env var so deployments can substitute a different stable video if needed.
define('HEALTH_PROBE_VIDEO_ID', getenv('HEALTH_PROBE_VIDEO_ID') ?: 'dQw4w9WgXcQ');
define('HEALTH_PROBE_URL', 'https://www.youtube.com/watch?v=' . HEALTH_PROBE_VIDEO_ID);

// Default daily quota for unauthenticated users (free tier).
// Override via QUOTA_DAILY env var in .env or docker-compose.
// Named with _DEFAULT suffix to distinguish from the runtime $daily_limit variable
// and to signal that this is a compile-time fallback, not the runtime value.
define('QUOTA_DAILY_DEFAULT', 5);
/**
 * Get the configured daily quota limit from the QUOTA_DAILY env var.
 * Uses an explicit guard so both unset (false) and empty-string ('') fall through
 * to QUOTA_DAILY_DEFAULT — consistent with the pattern used for timeout and
 * rate-limit constants throughout this file. A Docker env: QUOTA_DAILY: (empty)
 * is thus treated the same as an absent variable, returning the default.
 */
function getDailyQuotaLimit(): int {
    $_raw = getenv('QUOTA_DAILY');
    return max(0, ($_raw !== false && $_raw !== '') ? (int)$_raw : QUOTA_DAILY_DEFAULT);
}


// URL shown to users when they hit quota/rate-limit barriers — directs users to
// the upsell destination (e.g. AhoyVPN landing page for the public deploy).
// Override via UPGRADE_URL env var so self-hosted deployments can point to
// their own product page, Patreon, Ko-fi, or any preferred destination.
// Must be an absolute URL with scheme (https:// preferred).
define('UPGRADE_URL', rtrim(getenv('UPGRADE_URL') ?: 'https://ahoyvpn.com', '/'));

// Plausible analytics host — '' (empty, default) routes events through the
// /src/api.php?action=analytics proxy so no third-party requests leave the browser.
// Set PLAUSIBLE_HOST to a hostname (e.g. 'plausible.io' or 'analytics.yourdomain.com')
// to forward events directly to a self-hosted or hosted Plausible server.
// Set to '' to disable analytics entirely (endpoint returns 204 silently).
define('PLAUSIBLE_HOST', getenv('PLAUSIBLE_HOST') ?: '');

// Rate limit: max info/download requests per IP per minute.
// Defined early so the rate-limit gate (line ~199) can reference it before the
// constants section at line ~1778. nginx's 30r/m shared gate is the first
// threshold; this PHP-layer limit is the per-action ceiling.
// Use an explicit guard: getenv() returns false for unset AND '' for empty-string;
// the guard ensures empty-string is treated the same as unset, falling through to
// the documented default. min=1 prevents zero/negative values.
$_raw = getenv('RATE_LIMIT');
define('RATE_LIMIT', max(1, ($_raw !== false && $_raw !== '') ? (int)$_raw : 30));
unset($_raw);

// Download rate limit: max download requests per IP per minute.
// Defined early for the same reason as RATE_LIMIT above. Named in all-caps
// to match the env-var convention used throughout this file.
// Use an explicit guard: getenv() returns false for unset AND '' for empty-string;
// the guard ensures empty-string is treated the same as unset, falling through to
// the documented default. min=1 prevents zero/negative values.
$_raw = getenv('DL_RATE_LIMIT');
define('DL_RATE_LIMIT', max(1, ($_raw !== false && $_raw !== '') ? (int)$_raw : 10));
unset($_raw);

// Timeout (seconds) for the info action (metadata fetch). yt-dlp should finish
// in under 30s for most videos; 45s is generous for slow/unstable sources.
// An explicit 0 (or any non-positive integer) is passed through as-is;
// max(1, ...) then clamps it to a minimum of 1 second.
// Use an explicit guard: getenv() returns false for unset AND '' for empty-string;
// the guard ensures empty-string is treated the same as unset, falling through to
// the documented default.
$_raw = getenv('YTDLP_TIMEOUT');
define('INFO_TIMEOUT', max(1, ($_raw !== false && $_raw !== '') ? (int)$_raw : 45));
unset($_raw);

// Configurable timeout for the download action (file download).
// Override via YTDLP_DOWNLOAD_TIMEOUT env var (e.g. YTDLP_DOWNLOAD_TIMEOUT=120 in .env).
// Defaults to 300 seconds (5 minutes) when the env var is absent or zero/negative.
// The download action is I/O-bound (large media files) and needs a longer timeout
// than the info action (metadata fetch). INFO_TIMEOUT controls info; this constant
// controls download so the two can be tuned independently without compromise.
// Use an explicit guard: getenv() returns false for unset AND '' for empty-string;
// the guard ensures empty-string is treated the same as unset, falling through to
// the documented default.
$_raw = getenv('YTDLP_DOWNLOAD_TIMEOUT');
define('DOWNLOAD_TIMEOUT', max(1, ($_raw !== false && $_raw !== '') ? (int)$_raw : 300));
unset($_raw);

// ─── ORIGIN / REFERER VALIDATION ────────────────────────────────────────────
// throughout this script without an explicit timezone argument. PHP issues
// a warning when no default timezone is configured and a date function is
// called. Setting UTC here ensures consistent, predictable output regardless
// of the host system's PHP timezone configuration.
date_default_timezone_set('UTC');

// CORS headers for API access
header('Content-Type: application/json; charset=utf-8');
// Date: RFC 9110 §6.5.1 requires origin servers to send a Date header on all responses.
// The header is used by API consumers to reconcile server clock, validate cached responses,
// and correlate request timing across distributed systems.
header('Date: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
// Remove the "PHP/x.y.z" Server header that PHP-FPM adds automatically.
// header_remove() is idempotent — safe to call even when no such header was set.
// This complements server_tokens off in nginx, completing the version-hiding
// stack for both layers. Using remove() rather than setting a generic replacement
// value (e.g. "WebServer") ensures no version information leaks at all.
header_remove('X-Powered-By');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
header('X-Download-Options: noopen');
// X-Robots-Tag: prevent all API responses from being indexed or crawled.
// Set globally so info, health, and future actions are all covered.
// The check and download cases also set this explicitly (in case they are
// reached before this global block due to code reorganization).
// Search engines (Google, Bing) and AI training crawlers (CCBot, GPTBot, etc.)
// all respect X-Robots-Tag. This complements robots.txt which only covers the
// public page — the API endpoint (which returns JSON) needs its own directive.
header('X-Robots-Tag: noindex, noai, noimage, noydir');
// Use the client-provided X-Request-ID if present; otherwise generate one.
// Echoing the client's own ID back lets them confirm receipt and correlate
// their local error events with server-side log entries. If no ID was sent
// (direct API call, non-browser client), generate a server-side ID.
$request_id = $_SERVER['HTTP_X_REQUEST_ID'] ?: bin2hex(random_bytes(8));
header('X-Request-ID: ' . $request_id);

// Make request ID available to logRequest via a static global
$GLOBALS['__request_id'] = $request_id;
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
// Note: COEP removed — require-corp breaks cross-origin image loads (e.g. thumbnails
// from CDNs) which are common in media rippers. Omit unless you use SharedArrayBuffer
// or other COEP-locked features.

// Reporting-Endpoints (modern CSP violation reporting — supersedes legacy report-uri).
// nginx uses report-uri /csp-report in its CSP header. The Reporting-Endpoints header
// tells Chromium 84+ (May 2021) to route CSP violation reports to that endpoint via
// the browser's Reporting API. Both mechanisms are set so older browsers (Firefox <79,
// Safari) still receive reports via the legacy report-uri path while Chromium uses
// the modern Reporting API.
header('Reporting-Endpoints: csp-report="/csp-report"');
// Also include report-to for browsers that support the modern Reporting API.
// report-uri is kept as a fallback for older browsers.
header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
// Cache-Control: no-store — prevents all API responses from being cached.
// This is critical for security-sensitive JSON APIs: cached responses could be
// retrieved by other users on shared proxies or computers. The API serves
// per-user quota state and video metadata that should not persist across requests.
// Download responses (action=download) set no-store explicitly in their case block;
// setting it globally here ensures check, health, info, and any future actions
// are also protected without requiring each case to duplicate the header.
header('Cache-Control: no-store');

// ─── Early action routing ───────────────────────────────────────────────
// Declare $action before the referer gate so the exempt check can reference it.
// Also used by the rate-limit gate below.
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Anti-hotlinking: validate origin for API requests.
// All legitimate traffic arrives as a browser navigation to the AhoyRipper page
// (which then calls the API via fetch from JS) — such calls always carry a referer.
// Cross-site resource loads (IMG embeds, iframes) won't have a referer set by the
// browser. Requests with no referer cannot be from the legitimate single-page app
// flow, so they are blocked. This also blocks direct API calls (curl, Postman, etc.)
// that lack a browser-context referer.
//
// Security note: if the fix ever needs to allow direct-API callers (non-browser clients),
// switch to validating the Origin header instead of Referer — Origin is always set by
// browsers on same-site fetch requests and CORS preflight requests.
//
// Allowed origins for browser-based API calls (SPA fetches land here with proper referer).
$allowed_origins = ['https://ahoyripper.com', 'https://www.ahoyripper.com', 'https://ahoyvpn.com', 'https://www.ahoyvpn.com'];
$referer = $_SERVER['HTTP_REFERER'] ?? '';
// Fallback: read referer from query param for direct browser navigation downloads
// (e.g. window.location.href carrying &referer=https://ahoyripper.com/). This is
// needed because the download action uses window.location.href navigation rather than
// fetch(), which means no custom headers (including Referer) are sent. The frontend
// passes the page URL as &referer=<encoded-url> to work around this limitation.
if (!$referer && isset($_GET['referer'])) {
    $referer = $_GET['referer'];
}
$blocked = false;
$block_reason = '';

if ($referer) {
    $ref_parts = @parse_url($referer);
    // Guard against malformed URLs that cause parse_url to return false/null
    if (!is_array($ref_parts)) {
        $ref_parts = [];
    }
    $ref_origin = ($ref_parts['scheme'] ?? '') . '://' . ($ref_parts['host'] ?? '');
    if (!in_array(strtolower($ref_origin), array_map('strtolower', $allowed_origins), true)) {
        $blocked = true;
        $block_reason = 'invalid_origin';
    }
} else {
    // No referer — request did not originate from the AhoyRipper page.
    // This blocks direct API calls (curl, tools) and cross-site embeds.
    $blocked = true;
    $block_reason = 'missing_referer';
}

if ($blocked) {
    // Exempt check and analytics actions (zero-dependency monitoring ping and
    // analytics beacon used by Docker HEALTHCHECK and external probes that cannot
    // send a browser Referer header). info/download remain fully protected.
    if (!in_array($action, ['check', 'analytics'], true)) {
        logRequest('cors_block', 403, ['reason' => $block_reason, 'referer' => $referer]);
        error_log("AhoyRipper: blocked request ($block_reason) from referer: " . ($referer ?: '(none)'));
        http_response_code(403);
        // Security headers — mirrors the same set sent by every other error response
        // so CORS-blocked responses are equally hardened regardless of gate location.
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Download-Options: noopen');
        header('X-Robots-Tag: noindex, noai, noimage, noydir');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        // CORS origin validation happens before any action is dispatched, so
        // quota tracking has not started. Use -1 sentinels consistent with
        // other pre-quota-gate errors (MISSING_URL, INVALID_URL, etc.).
        header('X-RateLimit-Limit: -1');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unavailable');
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unavailable');
        $quota_reset_ts = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp();
        $quota_reset_iso = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->format('c');
        header('X-DailyLimit-Limit: -1');
        header('X-DailyLimit-Remaining: -1');
        header('X-DailyLimit-Reset: -1');
        header('X-DailyLimit-Window: unavailable');
        header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
        header('X-Info-Timeout: ' . INFO_TIMEOUT);
        // Content-Security-Policy, Reporting-Endpoints, and Report-To are set at
        // the top of the script (lines ~109-163) but are not inherited into this
        // exit path because this block calls exit() before the normal script flow
        // continues. Add them here explicitly so FORBIDDEN_ORIGIN responses are
        // fully consistent with all other API error responses.
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; report-to csp-report;');
        header('Reporting-Endpoints: csp-report="/csp-report"');
        header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
        // Cache-Control: no-store is set globally at line 163, but add it here too
        // for consistency with the default: block (UNKNOWN_ACTION) which also explicitly
        // sets it, and to ensure intermediate proxies don't cache this response.
        header('Cache-Control: no-store');
        echo json_encode([
            'error' => 'Requests must originate from ahoyripper.com or ahoyvpn.com.',
            'error_code' => 'FORBIDDEN_ORIGIN',
            'action' => $action ?: null,
            // retry_after: 0 — CORS validation failure is a client configuration issue
            // with no server-side backoff needed. The client just needs to use a
            // browser context with the correct referer.
            'retry_after' => 0,
            'request_id' => $request_id,
            'source_url' => null,
            // source_url_missing and format_id_missing are both false — CORS validation
            // fires before URL or format validation, so neither parameter has been checked.
            // The false values signal "validation has not run" rather than "value is invalid".
            'source_url_missing' => false,
            'format_id_missing' => false,
            'upgrade_url' => UPGRADE_URL,
            'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
            'api_version' => AHOYRIPPER_VERSION,
            // quota fields: -1 signals that quota tracking is not available at this
            // early pre-action validation stage (before any action is dispatched).
            'quota_remaining' => -1,
            'quota_limit' => -1,
            'quota_reset' => $quota_reset_iso,
            'quota_reset_unix' => $quota_reset_ts,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

/**
 * Sends a 503 SERVICE_UNAVAILABLE response for rate-limit subsystem failures.
 * Used when the rate-limit file cannot be opened or locked — distinct from
 * a client hitting a rate limit, so X-RateLimit-Remaining uses -1 (not 0).
 * Uses the same full response shape as the action-level quota-file 503
 * responses (lines 2565-2641) so all SERVICE_UNAVAILABLE errors have a
 * consistent interface: Content-Type, X-Info-Timeout, X-Download-Timeout,
 * and the complete JSON body with all standard error fields.
 */
function sendServiceUnavailable503(string $request_id, string $action): void
{
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Request-ID: ' . $request_id);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Download-Options: noopen');
    header('X-Robots-Tag: noindex, noai, noimage, noydir');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Retry-After: 5');
    header('X-Info-Timeout: ' . INFO_TIMEOUT);
    header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
    // Rate-limit subsystem failure: all counter values use -1 (not 0) to
    // signal that no rate-limit state is available — distinct from a client
    // actually hitting the limit (where remaining=0 would be correct).
    // X-*-Reset uses -1 (not time()+5) since Retry-After is already set to
    // delta-seconds (5s) and using an absolute timestamp here was inconsistent —
    // clients following Retry-After: 5 would retry, but the reset header said
    // time()+5 which equals the same absolute moment but created ambiguity about
    // whether the header was absolute or relative. Using -1 for reset timestamps
    // is consistent with the "unknown/unavailable" sentinel used throughout the
    // codebase for pre-gate errors where rate-limit state is not yet available.
    header('X-RateLimit-Limit: -1');
    header('X-RateLimit-Remaining: -1');
    header('X-RateLimit-Reset: -1');
    header('X-RateLimit-Window: 5');
    header('X-DailyLimit-Limit: -1');
    header('X-DailyLimit-Remaining: -1');
    header('X-DailyLimit-Reset: -1');
    header('X-DailyLimit-Window: unavailable');
    // X-DL-RateLimit-*: download-specific rate limit is unavailable (rate-limit
    // subsystem failure — not a per-IP download limit hit), so use -1 sentinels
    // to signal "unknown", matching the same pattern used by X-RateLimit-*.
    // X-DL-RateLimit-Window uses "unavailable" — there is no known
    // time window for this error state since the rate-limit store itself is
    // inaccessible. Using "5" would falsely imply a 5-second recovery window,
    // which has no basis in the subsystem failure. Consistent with all other
    // pre-gate and unavailable states that set X-*-Window: unavailable.
    header('X-DL-RateLimit-Limit: -1');
    header('X-DL-RateLimit-Remaining: -1');
    header('X-DL-RateLimit-Reset: -1');
    header('X-DL-RateLimit-Window: unavailable');
    echo json_encode([
        'error' => 'Service temporarily unavailable.',
        'error_code' => 'SERVICE_UNAVAILABLE',
        'action' => $action,
        'upgrade_url' => UPGRADE_URL,
        'retry_after' => 5,
        'request_id' => $request_id,
        'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
        'api_version' => AHOYRIPPER_VERSION,
        // source_url: null — SERVICE_UNAVAILABLE fires before URL validation.
        // source_url_missing: false — no URL was found to be missing.
        // format_id_missing: false — SERVICE_UNAVAILABLE fires before format validation.
        'source_url' => null,
        'source_url_missing' => false,
        'format_id_missing' => false,
        // quota fields: unavailable — the rate-limit file could not be accessed.
        // Use -1 sentinels so clients can distinguish this from a known limit.
        'quota_remaining' => -1,
        'quota_limit' => -1,
        'quota_reset' => -1,
        'quota_reset_unix' => -1,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ─── Rate limiting gate ───────────────────────────────────────────────────
// Rate limiting applies to expensive actions only (info, download).
// Lightweight endpoints (health, progress, check) are exempt to allow frequent
// monitoring without burning the user's rate budget.
// NOTE: this gate only runs when $action is set BEFORE this point (moved from
// line 743). The internal_actions check below exits before this block for
// lightweight actions, so rate limiting still applies to info/download.
$rate_limited_actions = ['info', 'download'];
$is_rate_limited = in_array($action, $rate_limited_actions, true);

// Rate limiting - atomic IP-based gate using flock
// $ip is used for both rate limiting and daily quota; declared early so it is
// available for both the rate-limit block and the daily-quota block (info action
// reads it at line 2365, download action at line 3115).
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_file = '/tmp/ahoyrip_rate_' . md5($ip);
$rate_limit = RATE_LIMIT; // requests per minute (configurable via RATE_LIMIT env var)
$rate_window = 60;
// $cleanup_cutoff: stale rate files older than $rate_window seconds are removed.
// A file is stale when (now - stored_timestamp) > $rate_window, meaning the
// rate-limit window has fully expired and no new requests arrived to refresh it.
$cleanup_cutoff = $rate_window;

// $data is declared here so headers can be set outside the if block below,
// making rate-limit metadata available to all API responses (including
// unlimited-key users who still pass through this gate).
$data = ['t' => time(), 'c' => 0];

if ($is_rate_limited) {
    $fp = fopen($rate_file, 'c+');
    if (!$fp) {
        sendServiceUnavailable503($request_id, $action ?? 'info');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        sendServiceUnavailable503($request_id, $action ?? 'info');
    }

    $raw = fread($fp, 4096);
    if ($raw) {
        $decoded = json_decode($raw, true);
        if ($decoded && is_array($decoded)) {
            $data = $decoded;
        }
    }

    if (time() - $data['t'] < $rate_window) {
        // $data['c'] is the count AFTER the previous request's increment.
        // Block NOW if the NEXT request would push us over the limit.
        // Using $data['c'] + 1 (not >=) enforces the exact limit without
        // allowing one request to exceed it before blocking.
        if ($data['c'] + 1 > $rate_limit) {
            $reset_timestamp = $data['t'] + $rate_window;
            flock($fp, LOCK_UN);
            fclose($fp);
            http_response_code(429);
            header('X-RateLimit-Limit: ' . $rate_limit);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . $reset_timestamp);
            header('X-RateLimit-Window: ' . $rate_window);
            // X-DL-RateLimit sentinels (-1): the download-rate-limit state is not available
            // at this gate (dl_rate_file is opened later in the download action), so send -1
            // to signal "unavailable" rather than misleadingly echoing the request-rate limit.
            // Uses "unavailable" to match the semantic used consistently in the download action's
            // other early-exit blocks (INVALID_KEY, etc.) — both mean "not applicable here".
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unavailable');
            // Guard retry_after with max(0, ...) to prevent negative values if the
            // reset timestamp is somehow in the past (clock skew, stale rate file).
            // A negative Retry-After is invalid per HTTP spec and rejected by some clients.
            header('Retry-After: ' . max(0, $reset_timestamp - time()));
            // Daily-limit sentinels (-1) signal clients this is a per-minute rate limit,
            // not a daily quota hit — allows the UI to distinguish the two cases without
            // parsing the error message. The daily-quota 429 block (when $daily_limit is
            // exceeded) sends the real daily-limit values instead.
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
            // Security headers — mirrors the same set sent by the top-of-script
            // 403/503 error blocks, since this 429 bypasses the normal switch/case.
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('X-Request-ID: ' . $request_id);
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            // X-Download-Timeout: mirrors the header set on all other info/download responses.
            // Present even on rate-limit 429s so clients can always read the download timeout
            // value without branching on the response code.
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            // X-Info-Timeout: consistent with all other info-action error responses.
            // Clients can use this to set appropriate fetch timeouts on retry.
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            $rate_quota_limit = getDailyQuotaLimit();
            $rate_quota_reset = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp();
            echo json_encode([
                'error' => 'Too many requests. Slow down.',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
                'upgrade_url' => UPGRADE_URL,
                'retry_after' => max(0, (int)($reset_timestamp - time())),
                'request_id' => $request_id,
                'source_url' => null,
                // source_url_missing: false — rate-limit gate fires before URL validation,
                // so the URL field being null here reflects that validation has not yet run,
                // not that a URL was explicitly invalid. Same pattern for format_id_missing.
                'source_url_missing' => false,
                'format_id_missing' => false,
                'platform' => null,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                // quota fields: included for consistency with all other error responses.
                // At this point in the code (rate-limit gate, before daily-quota gate),
                // the quota file has not been opened so exact remaining is unknown.
                // Use configured limit and reset timestamp — same pattern as MISSING_URL.
                'quota_remaining' => -1,
                'quota_limit' => $rate_quota_limit,
                'quota_reset' => $rate_quota_reset,
                // quota_reset_unix carries the same Unix timestamp as quota_reset.
                // Both fields must always contain the same reset timestamp so clients
                // can use either field interchangeably without special-casing -1.
                // The -1 sentinel pattern is reserved for quota_remaining (which uses
                // -1 to signal "unknown" or "unlimited") — quota_reset always has
                // a concrete reset timestamp or -1 only when the daily-quota concept
                // itself does not apply (e.g. check/health actions).
                'quota_reset_unix' => $rate_quota_reset,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $data['c']++;
    } else {
        $data = ['t' => time(), 'c' => 0]; // Fresh window — current request will be counted after the write
    }

    // Write back atomically
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

// Periodic cleanup of stale rate files and cache entries.
// Proactively removes expired entries from /tmp to prevent indefinite accumulation
// on servers that run for months without restart.
// Runs on EVERY request (not just rate-limited actions) so stale files never
// accumulate regardless of which endpoints are hit — critical for servers that
// primarily serve health checks or check-only probes.
// The rate file stores ['t' => timestamp_of_first_request_in_window, 'c' => count].
// A file is stale when the stored timestamp is older than $rate_window seconds ago
// (meaning the window has fully expired and no new requests arrived to refresh it).
// Note: abs() is intentionally omitted — time() - $d['t'] is always >= 0 for valid
// timestamps, and omitting abs() makes the condition self-documenting.
foreach (glob('/tmp/ahoyrip_rate_*') as $f) {
    $d = @json_decode(@file_get_contents($f), true);
    if (!$d || !is_array($d) || (time() - ($d['t'] ?? 0)) > $cleanup_cutoff) {
        @unlink($f);
    }
}
// Clean up stale daily-quota files. These are stored as /tmp/ahoyrip_daily_<ip_hash>
// and reset at midnight UTC. A quota file is stale when its stored date does not
// match today (UTC), meaning midnight has passed and a fresh window has started.
// Unlike rate files which expire after $rate_window seconds, quota files must wait
// for the next calendar day — the midnight UTC boundary is the only valid reset point.
// Stale quota files are safe to remove: the user starts a fresh window on the next
// request, and keeping old files serves no purpose.
$today_utc = gmdate('Y-m-d');
foreach (glob('/tmp/ahoyrip_daily_*') as $f) {
    $d = @json_decode(@file_get_contents($f), true);
    $file_date = $d['t'] ?? null;
    if (!$d || !is_array($d) || $file_date !== $today_utc) {
        @unlink($f);
    }
}
// Clean up stale version cache files (yt-dlp and ffprobe) and the yt-dlp
// connectivity probe cache — they expire after their respective TTLs but the
// files themselves accumulate on long-running servers if not removed.
// When the cache is cleared, also clear the in-memory global so the next request
// fetches a fresh value rather than holding a stale entry across requests.
// Uses glob patterns for ffprobe caches since the filename includes an MD5 hash
// of FFPROBE_PATH — this also cleans up stale caches from a previous FFPROBE_PATH
// value after a path change (which the old hardcoded filename never handled).
foreach (array_merge(
    glob('/tmp/ahoyrip_ytdlp_*.cache') ?: [],
    glob('/tmp/ahoyrip_ffprobe_*.cache') ?: [],
    is_file('/tmp/ahoyrip_ytdlp_probe.cache') ? ['/tmp/ahoyrip_ytdlp_probe.cache'] : []
) as $cache) {
    $d = @json_decode(@file_get_contents($cache), true);
    if (!$d || !is_array($d) || ($d['exp'] ?? 0) < time()) {
        @unlink($cache);
        if (strpos($cache, 'ahoyrip_ytdlp_') === 0 && strpos($cache, '_probe') === false) {
            $GLOBALS['__ytdlp_version'] = null;
        }
        if (strpos($cache, 'ahoyrip_ffprobe_') === 0) {
            $GLOBALS['__ffmpeg_version'] = null;
        }
        if (strpos($cache, 'ahoyrip_ytdlp_probe') === 0) {
            $GLOBALS['__ytdlp_probe'] = null;
        }
    }
}

// ─── Daily quota gate ─────────────────────────────────────────────────────
// Quota is tracked per-IP as a simple counter. Unlimited-key holders bypass it.
// A HOURLY rate limit (RATE_LIMIT, default 30 req/min) was already applied above;
// this daily quota is an additional guard against sustained abuse.
// Applies to info and download actions only — health/progress/check/analytics
// are exempt (they consume no server resources beyond a JSON response).

// Set rate limit headers unconditionally so they are present on every response,
// including unlimited-key requests that still pass through this gate.
// This gives clients (monitoring tools, load balancers) consistent metadata.
$reset = $data['t'] + $rate_window;
header('X-RateLimit-Limit: ' . $rate_limit);
header('X-RateLimit-Remaining: ' . max(0, $rate_limit - $data['c']));
header('X-RateLimit-Reset: ' . $reset);
header('X-RateLimit-Window: ' . $rate_window);

// X-DL-* headers reflect the download-specific rate limit (DL_RATE_LIMIT).
// For the 'download' action: read the dl_rate file and report actual state.
// For all other actions: send -1 sentinels (no download rate limit applies).
// Inside the download 429 block these are overridden with real values.
$dl_limit = DL_RATE_LIMIT;
$dl_window = 60;
$dl_remaining = -1;
$dl_reset = -1;
$dl_window_label = 'unavailable';
if (($action ?? '') !== 'download') {
    // For non-download actions, $dl_remaining is set to -1 above (no download
    // rate limit applies). Set $dl_limit to -1 as well for header consistency —
    // the X-DL-RateLimit-Limit header should match the semantic of the other
    // sentinels (-1 = not applicable to this action). Without this, the header
    // would incorrectly report the configured DL_RATE_LIMIT value even though
    // no download rate limit is in effect for info/health/check/client-error.
    $dl_limit = -1;
}
if (($action ?? '') === 'download') {
    $dl_rate_file = '/tmp/ahoyrip_dl_rate_' . md5($ip);
    $dl_fp2 = @fopen($dl_rate_file, 'r');
    if ($dl_fp2) {
        $dl_raw = @fread($dl_fp2, 4096);
        if ($dl_raw) {
            $dl_decoded = @json_decode($dl_raw, true);
            if ($dl_decoded && is_array($dl_decoded)) {
                $dl_remaining = max(0, $dl_limit - $dl_decoded['c']);
                $dl_reset = $dl_decoded['t'] + $dl_window;
            }
        }
        fclose($dl_fp2);
    }
}
header('X-DL-RateLimit-Limit: ' . $dl_limit);
header('X-DL-RateLimit-Remaining: ' . $dl_remaining);
header('X-DL-RateLimit-Reset: ' . $dl_reset);
header('X-DL-RateLimit-Window: ' . $dl_window_label);

// ─── Lightweight internal check (no auth, no rate-limit, no referer check) ───
// Dedicated endpoint for Docker healthchecks and load-balancer probes.
// Unlike health (which may run yt-dlp, syscalls, reads /proc), this is a pure
// JSON ping that adds zero server load — safe to call every 10 seconds.
// Placed BEFORE the referer gate so it exits before that check runs.
// Both 'health' and 'progress' map to the same health-probe handler (the
// 'progress' case falls through to 'health' in the switch below). Exposing
// both names maintains backwards compatibility with any clients that use the
// older 'progress' action name while guiding new integrations toward 'health'.
// 'analytics' is listed here so the default: block doesn't route it to
// UNKNOWN_ACTION. It is handled by its own case at line 6403.
$internal_actions = ['analytics', 'check', 'health', 'progress', 'csp-report', 'client-error'];
// NOTE: $action is already declared at line 75 before the rate-limit gate.
if (in_array($action, $internal_actions, true)) {
    // csp-report: receive and log browser CSP violation reports (nginx POSTs
    // violation details here per the report-uri directive in CSP-Report-Only).
    // This endpoint intentionally exits before the GET-method and Accept-header
    // gates since violation reports are always POST with no Accept header.
    if ($action === 'csp-report') {
        // Always return 200 so the browser doesn't retry failed reports.
        // Log the report body for security monitoring (stripped of sensitive data).
        $body = file_get_contents('php://input');
        $report = json_decode($body, true);
        // Validate the report structure before accessing nested keys — a malformed
        // or unexpectedly-structured POST body could cause php warnings or undefined
        // index errors if $report is null (json_decode failure) or not an array.
        if (!is_array($report) || !is_array($report['csp-report'] ?? null)) {
            // Log with request_id for correlation; omit body to avoid leaking data.
            error_log("AhoyRipper CSP-VIOLATION [{$request_id}]: malformed report body");
        } else {
            // Log to error_log with identifiable prefix and request_id for correlation.
            // Omit document-uri and referrer which may contain video URLs.
            $safe = [
                'blocked-uri' => $report['csp-report']['blocked-uri'] ?? null,
                'violated-directive' => $report['csp-report']['violated-directive'] ?? null,
                'original-policy' => $report['csp-report']['original-policy'] ?? null,
            ];
            error_log("AhoyRipper CSP-VIOLATION [{$request_id}]: " . json_encode($safe));
        }
        // Harden the csp-report response to match the rest of the API.
        // Use fastcgi_finish_request() (PHP-FPM only) to flush the full response
        // (top-of-script headers + body) before exiting. This eliminates the
        // maintenance burden of manually duplicating all security headers here
        // whenever a new header is added to the top-of-script block. In non-FPM
        // SAPIs the function doesn't exist and we fall back to manual headers.
        //
        // NOTE: nginx's add_header directives for the /csp-report location block
        // (Reporting-Endpoints, Report-To, Content-Security-Policy-Report-Only)
        // are NOT guaranteed to be applied to the response after fastcgi_finish_request()
        // flushes, since nginx may have already committed its headers. To guarantee
        // these headers are present in ALL deployments (FPM and non-FPM), they are
        // set explicitly here in the FPM path alongside the nginx-level headers.
        if (function_exists('fastcgi_finish_request')) {
            // Explicitly re-set ALL standard security headers in the FPM fast-path so
            // the response is fully hardened regardless of nginx layer-header state.
            // These complement (not replace) the top-of-script headers already in the
            // buffer. X-Request-ID is excluded since it was already sent above.
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            // Location-level nginx headers that may be missed after fastcgi_finish_request()
            // flushes — set them explicitly here to guarantee they're present in all deployments.
            header('Reporting-Endpoints: csp-report="/csp-report"');
            header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
            header('Content-Security-Policy-Report-Only: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; report-to csp-report; report-uri /csp-report;');
            echo json_encode(['status' => 'ok'], JSON_INVALID_UTF8_SUBSTITUTE);
            fastcgi_finish_request();
            exit;
        }
        // Fallback for non-FPM SAPIs (CLI, etc.) — manually set required headers.
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Download-Options: noopen');
        header('X-Robots-Tag: noindex, noai, noimage, noydir');
        header('X-Request-ID: ' . $request_id);
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        // Cache-Control: no-store — prevents all API responses from being cached.
        // Set explicitly here since the top-of-script header block is bypassed.
        header('Cache-Control: no-store');
        // Rate-limit headers: -1 sentinel (unlimited) since csp-report is a read-only
        // fire-and-forget endpoint. Mirrors the pattern used by action=check and health.
        header('X-RateLimit-Limit: -1');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unlimited');
        header('Reporting-Endpoints: csp-report="/csp-report"');
        header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
        // Content-Security-Policy-Report-Only: mirrors the nginx-layer header so non-FPM
        // SAPIs (CLI, built-in server) also enforce report-only mode for the CSP endpoint.
        // Required alongside the CSP header below so the /csp-report endpoint can receive
        // violation reports in report-only mode without blocking legitimate reports.
        // Matches the FPM-path header set at line 641.
        header('Content-Security-Policy-Report-Only: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; report-to csp-report; report-uri /csp-report;');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');
        echo json_encode(['status' => 'ok'], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // client-error: receive and log browser JavaScript runtime errors and unhandled
    // promise rejections forwarded via navigator.sendBeacon (fetch in the browser with
    // no way to read the response). Reports are fire-and-forget from the client side.
    // This endpoint intentionally exits before the GET-method and Accept-header gates
    // since sendBeacon sends POST with no custom Accept header.
    if ($action === 'client-error') {
        $body = file_get_contents('php://input');
        $payload = json_decode($body, true);
        // json_decode with assoc=true returns null on parse failure (not false).
        // Guard matches the pattern used by csp-report at line 591.
        if ($payload === null || !is_array($payload)) {
            error_log("AhoyRipper CLIENT-ERROR [{$request_id}]: malformed payload");
        } else {
            // Log with identifiable prefix and request_id for correlation.
            // Omit document-uri and referrer which may contain video URLs.
            $client_error_code = $payload['error'] ?? $payload['message'] ?? null;
            $client_error_info = [
                'error' => $client_error_code,
                'page_url' => $payload['pageUrl'] ?? null,
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
            ];
            error_log("AhoyRipper CLIENT-ERROR [{$request_id}]: " . json_encode($client_error_info));
        }
        // Harden the client-error response to match the rest of the API.
        // Use fastcgi_finish_request() (PHP-FPM only) to flush the full response
        // (top-of-script headers + body) before exiting. This eliminates the
        // maintenance burden of manually duplicating all security headers here
        // whenever a new header is added to the top-of-script block. In non-FPM
        // SAPIs the function doesn't exist and we fall back to manual headers.
        if (function_exists('fastcgi_finish_request')) {
            // Re-set ALL standard security headers in the FPM fast-path so the
            // response is fully hardened regardless of nginx layer-header state.
            // These complement the top-of-script headers in the output buffer.
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('X-Request-ID: ' . $request_id);
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            // Cache-Control: no-store — prevents all API responses from being cached.
            // Set explicitly here (not relying on the global header at line 214) because
            // fastcgi_finish_request() flushes the output buffer before line 214 is reached
            // in the FPM fast-path, so the global Cache-Control would not be included.
            // The non-FPM fallback block also sets this explicitly (line 881) for consistency.
            header('Cache-Control: no-store');
            // X-Info-Timeout: mirrors the header set in the non-FPM fallback block.
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            // X-Download-Timeout: mirrors the header set in the non-FPM fallback block.
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            // X-DL-RateLimit-*: download-specific rate limit (not applicable here, so -1).
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unlimited');
            // Rate-limit headers: -1 sentinel (unlimited) since client-error is a
            // read-only fire-and-forget endpoint.
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: unlimited');
            // X-DailyLimit-*: daily quota sentinel (-1 = not applicable to this endpoint).
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
            header('Reporting-Endpoints: csp-report="/csp-report"');
            header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
            header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');
            // retry_after: 0 — client-error is a fire-and-forget endpoint with no
            // server-side backoff; clients can immediately retry their original action.
            echo json_encode(['status' => 'ok', 'retry_after' => 0], JSON_INVALID_UTF8_SUBSTITUTE);
            fastcgi_finish_request();
            exit;
        }
        // Fallback for non-FPM SAPIs (CLI, etc.) — manually set required headers.
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Download-Options: noopen');
        header('X-Robots-Tag: noindex, noai, noimage, noydir');
        header('X-Request-ID: ' . $request_id);
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        // Cache-Control: no-store — prevents all API responses from being cached.
        // Mirrors the header set in the FPM fast-path block above.
        header('Cache-Control: no-store');
        // Rate-limit headers: -1 sentinel (unlimited) since client-error is a read-only
        // fire-and-forget endpoint. Mirrors the pattern used by action=check and health.
        header('X-RateLimit-Limit: -1');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unlimited');
        // X-DL-RateLimit-*: download-specific rate limit (not applicable here, so -1).
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unlimited');
        // X-DailyLimit-*: daily quota sentinel (-1 = not applicable to this endpoint).
        header('X-DailyLimit-Limit: -1');
        header('X-DailyLimit-Remaining: -1');
        header('X-DailyLimit-Reset: -1');
        header('X-DailyLimit-Window: unlimited');
        // X-Info-Timeout: mirrors the header set in the FPM fast-path block above.
        header('X-Info-Timeout: ' . INFO_TIMEOUT);
        header('Reporting-Endpoints: csp-report="/csp-report"');
        header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');
        // retry_after: 0 — client-error is a fire-and-forget endpoint with no
        // server-side backoff; clients can immediately retry their original action.
        echo json_encode(['status' => 'ok', 'retry_after' => 0], JSON_INVALID_UTF8_SUBSTITUTE);
        fastcgi_finish_request();
        exit;
    }
    // Fallback for non-FPM SAPIs (CLI, etc.) — manually set required headers.
    // NOTE: exit is REQUIRED here — without it, the script falls through to the
    // check/health handler below (line 913) and returns a spurious status:ok from
    // the wrong handler, confusing API clients that expect no body from client-error.
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Download-Options: noopen');
    header('X-Robots-Tag: noindex, noai, noimage, noydir');
    header('X-Request-ID: ' . $request_id);
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cache-Control: no-store');
    header('X-Info-Timeout: ' . INFO_TIMEOUT);
    header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
    header('X-DL-RateLimit-Limit: -1');
    header('X-DL-RateLimit-Remaining: -1');
    header('X-DL-RateLimit-Reset: -1');
    header('X-DL-RateLimit-Window: unlimited');
    header('X-RateLimit-Limit: -1');
    header('X-RateLimit-Remaining: -1');
    header('X-RateLimit-Reset: -1');
    header('X-RateLimit-Window: unlimited');
    header('X-DailyLimit-Limit: -1');
    header('X-DailyLimit-Remaining: -1');
    header('X-DailyLimit-Reset: -1');
    header('X-DailyLimit-Window: unlimited');
    header('Reporting-Endpoints: csp-report="/csp-report"');
    header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');
    echo json_encode(['status' => 'ok', 'retry_after' => 0], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
    // All other internal_actions (check, health, progress)
    // receive X-Robots-Tag via the nginx add_header in deploy/nginx.conf
    // when served through the = /src/api.php location block (line ~98).
    // api.php also sets this header at the top of the script (line 20)
    // for all non-download responses.
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-ID: ' . $request_id);
    // Return PHP version as a minimal version signal for load-balancer health checks.
    // load-balancer probes can confirm expected version without triggering a full yt-dlp probe.
    // NOTE: Connection: close is intentionally NOT set here. Sending "Connection: close"
    // breaks HTTP keep-alive, forcing a new TCP connection for every check request and
    // negating connection-pooling benefits. For high-frequency pings (every 10s), the
    // overhead of establishing a new connection each time is measurable. With keep-alive,
    // the same connection is reused across multiple requests, which is the correct
    // default for a lightweight JSON API endpoint.
    // Daily quota fields — check is a read-only probe (does not consume quota)
    // so quota_remaining is -1 (unlimited signal). quota_limit, quota_reset, and
    // quota_reset_unix are included for API surface consistency with health/info
    // responses, allowing clients to always determine the limit and reset from the body.
    echo json_encode([
        'status' => 'ok',
        'server_time' => date('c'),
        'server_time_unix' => time(),
        'request_id' => $request_id,
        'app_version' => AHOYRIPPER_VERSION,
        'php_version' => PHP_VERSION,
        'api_version' => AHOYRIPPER_VERSION,
        'os' => PHP_OS,
        'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
        // yt_dlp_ok: true when yt-dlp binary is installed and callable.
        // Mirrors the field in action=health so monitoring scripts that hit
        // action=check (the lightweight no-probe endpoint) can determine binary
        // status without parsing the version string.
        'yt_dlp_ok' => !empty($GLOBALS['__ytdlp_version']) && strpos($GLOBALS['__ytdlp_version'], 'not installed') === false,
        // ffprobe_version: version string for the ffprobe binary (part of ffmpeg suite).
        // Mirrors the field in action=health for consistency across all endpoints.
        'ffprobe_version' => $GLOBALS['__ffmpeg_version'] ?? null,
        // ffmpeg_ok: true when ffprobe binary is installed and callable.
        // Mirrors the field in action=health so monitoring can confirm ffprobe
        // availability without parsing the version string.
        'ffmpeg_ok' => !empty($GLOBALS['__ffmpeg_version']) && strpos($GLOBALS['__ffmpeg_version'], 'not installed') === false,
        'source_url' => null,
        'upgrade_url' => UPGRADE_URL,
        'quota_remaining' => -1,
        'quota_limit' => getDailyQuotaLimit(),
        'quota_reset' => -1,
        'quota_reset_unix' => -1,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Only allow HTTPS URLs and block private IP ranges to prevent SSRF attacks.
// yt-dlp accepts file:// URLs directly, so we restrict to HTTP(S) and reject
// private ranges (127.x, 10.x, 172.16-31.x, 192.168.x, 169.254.x), IPv6
// private/loopback/link-local ranges, and IPv4-mapped IPv6 (::ffff:192.168.x.x).
/**
 * Validate that a URL is a public HTTPS URL safe to pass to yt-dlp.
 *
 * @param mixed $url  URL to validate. Non-strings return false immediately.
 * @return bool  True if the URL is a public HTTPS URL; false otherwise.
 * @throws InvalidArgumentException  Never thrown; reserved for future validation use.
 */
function isValidUrl($url) {
    if (!is_string($url)) {
        return false;
    }
    // Trim whitespace — callers are responsible for trimming too, but this
    // guards against any caller that passes untrimmed input and makes
    // isValidUrl() self-contained and safe for reuse as a standalone validator.
    $url = trim($url);
    if (!preg_match('/^https:\/\//', $url)) {
        return false; // Only HTTPS — reject http:// and other schemes
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    // Block private and reserved IP ranges in the host portion
    $parsed = parse_url($url, PHP_URL_HOST);
    if ($parsed === false || $parsed === null) {
        return false;
    }
    // Enforce RFC 1035 hostname length limit (253 chars max for full domain,
    // 63 chars per label). This prevents edge-case parse_url edge cases with
    // extreme input and aligns with what DNS can actually resolve.
    if (strlen($parsed) > 253) {
        return false;
    }
    // Strip brackets from IPv6 URLs (e.g., [::1] -> ::1) before validation.
    // parse_url with PHP_URL_HOST returns IPv6 addresses in bracketed form.
    // filter_var with FILTER_VALIDATE_IP rejects bracketed strings, so we must
    // strip the brackets before passing the host to the validator.
    // Helper: returns false if the IP is private, reserved, or multicast.
    // For IPv4-mapped IPv6 (::ffff:x.x.x.x), validates the embedded IPv4.
    $isPublicIp = function(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // IPv4-mapped IPv6 addresses (::ffff:192.168.x.x) pass the filter above
        // because FILTER_FLAG_NO_PRIV_RANGE only checks the IPv6 portion.
        // Extract the embedded IPv4 and validate it separately for private ranges.
        if (str_starts_with($ip, '::ffff:')) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        // FILTER_FLAG_NO_RES_RANGE does NOT block multicast IPs (IPv4 224.0.0.0/4
        // or IPv6 ff00::/8). Block them explicitly — multicast addresses cannot be
        // routed on the public internet and are never valid targets for outbound
        // HTTP requests.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = array_map('intval', explode('.', $ip));
            if ($octets[0] >= 224 && $octets[0] <= 239) {
                return false; // IPv4 multicast (224.0.0.0/4)
            }
            // Block 100.64.0.0/10 — carrier-grade NAT (CGN) addresses.
            // FILTER_FLAG_NO_PRIV_RANGE intentionally leaves 100.64.0.0/10 unblocked
            // because RFC 6598 classifies it as shared address space (not private).
            // However, CGN addresses cannot receive inbound connections from the
            // public internet and should not be targeted by outbound requests.
            if ($octets[0] === 100 && $octets[1] >= 64 && $octets[1] <= 127) {
                return false; // 100.64.0.0/10 — CGN (shared address space, not routable)
            }
        } else {
            // IPv6: block multicast range ff00::/8. Unlike IPv4 where FILTER_FLAG_IPV4
            // is used as the detection mechanism, IPv6 multicast is detected by
            // checking if the first byte is 0xff (ff00::/8 prefix).
            if (str_starts_with($ip, 'ff')) {
                return false; // IPv6 multicast (ff00::/8)
            }
        }
        return true;
    };

    if (filter_var($parsed, FILTER_VALIDATE_IP) !== false) {
        // Host is a bare IP (no brackets)
        $host = $parsed;
    } elseif (filter_var(substr($parsed, 1, -1), FILTER_VALIDATE_IP) !== false) {
        // Host is a bracketed IP like [::1] or [fe80::1] — extract the bare IP
        $host = substr($parsed, 1, -1);
    } else {
        // Host is a domain name — validate its format before attempting DNS resolution.
        // Reject hostnames that violate RFC 1123 / RFC 952 rules:
        //   - Each label: 1–63 chars, alphanumeric/hyphen, must not start/end with hyphen
        //   - No leading/trailing dots, no consecutive dots
        //   - Total length ≤ 253 chars (already checked above)
        // This prevents parse_url edge cases with crafted URLs and reduces
        // unnecessary DNS lookups for obviously invalid hostnames.
        if (!preg_match('/^(?!-)[a-zA-Z0-9-]{1,63}(?<!-)(\.(?!-)[a-zA-Z0-9-]{1,63}(?<!-))*$/', $parsed)) {
            return false;
        }
        // Resolve and validate each resolved IP.
        // This prevents SSRF via DNS rebinding (e.g. localhost resolving to 127.0.0.1
        // or an attacker controlling DNS to point a domain at a private IP).
        // Domains that don't resolve are rejected.
        //
        // Use dns_get_record (DNS_A | DNS_AAAA) instead of gethostbynamel() because
        // gethostbynamel() only returns IPv4 (A records) — IPv6-only domains (e.g.
        // ipv6.google.com) return false and are incorrectly rejected. dns_get_record
        // returns both A ('ip' key) and AAAA ('ipv6' key) records so IPv6-only
        // domains are handled correctly.
        $resolved = @dns_get_record($parsed, DNS_A | DNS_AAAA);
        if ($resolved === false || empty($resolved)) {
            return false; // Cannot resolve — reject
        }
        // Validate every IP the domain resolves to. Reject if ANY is private/reserved/multicast.
        // Collect IPv4 from 'ip' key and IPv6 from 'ipv6' key.
        // CNAME-only responses (no A/AAAA records) yield an empty 'ip'/'ipv6' field —
        // explicitly reject those so a CNAME pointing to a private IP can't bypass SSRF guards.
        $found_ip = false;
        foreach ($resolved as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null || $ip === '') {
                continue; // CNAME-only record, no IP to check
            }
            $found_ip = true;
            if (!$isPublicIp($ip)) {
                return false;
            }
        }
        if (!$found_ip) {
            return false; // Domain resolves to CNAMEs only — no public IPs found
        }
        return true;
    }
    // If the host resolved to an IP address, validate it is not private/reserved/multicast.
    // This catches bare IPs and IPv6 loopback/link-local stripped of brackets.
    if ($host !== null && filter_var($host, FILTER_VALIDATE_IP) !== false) {
        if (!$isPublicIp($host)) {
            return false;
        }
    }
    return true;
}

// yt-dlp version cache (declared early so periodic cleanup can reference it)
// Stores: ['ver' => string, 'hash' => string, 'exp' => int]
// 'hash' is MD5 of the binary — if the binary is replaced (new yt-dlp installed),
// the hash changes and the cached version is invalidated so we re-fetch the new version.
$version_cache_file = '/tmp/ahoyrip_ytdlp_ver.cache';
$GLOBALS['__ytdlp_version'] = null;
$GLOBALS['__ytdlp_probe'] = null;
if ($version_cache_file && is_readable($version_cache_file)) {
    $cached = @json_decode(@file_get_contents($version_cache_file), true);
    if ($cached && is_array($cached) && ($cached['exp'] ?? 0) > time()) {
        // Hash check: verify the binary hasn't been replaced since we cached it.
        // If the hash doesn't match, the binary was upgraded — invalidate and re-fetch.
        $current_hash = @md5_file(YTDLP_PATH);
        // If the binary can't be read, treat the cache as invalid — we can't
        // verify whether the binary was replaced while the cache was expired.
        if ($current_hash === false) {
            $GLOBALS['__ytdlp_version'] = null;
        } elseif (isset($cached['hash']) && $current_hash === $cached['hash']) {
            $GLOBALS['__ytdlp_version'] = $cached['ver'] ?? null;
        }
    }
}
if (!$GLOBALS['__ytdlp_version']) {
    // Use proc_open with bypass_shell=true to read yt-dlp's version without
    // shell metacharacters. The previous shell_exec(YTDLP_PATH . ' --version 2>&1')
    // used a shell pipe (2>&1) — inconsistent with the proc_open approach used
    // throughout the rest of the file. yt-dlp 2024.02.07+ outputs version to
    // stdout; stderr contains non-version info. Reading only stdout is sufficient.
    // If the binary is absent, proc_open returns false and $ver stays empty.
    $ver = '';
    $ytdlp_ver_cmd = [YTDLP_PATH, '--version'];
    $ytdlp_ver_proc = proc_open($ytdlp_ver_cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $ytdlp_ver_pipes, null, [], ['bypass_shell' => true]);
    if ($ytdlp_ver_proc) {
        // Close stdin immediately — we never write to it. Leaving it open causes
        // the child to hold an unused pipe fd; proc_close waits for all pipe
        // writers (stdin writer in the parent) to close before returning.
        fclose($ytdlp_ver_pipes[0]);
        unset($ytdlp_ver_pipes[0]);
        // Read only the first line (version string is always line 1).
        $first_line = fgets($ytdlp_ver_pipes[1]);
        if ($first_line !== false) {
            $ver = trim($first_line);
        }
        fclose($ytdlp_ver_pipes[1]);
        fclose($ytdlp_ver_pipes[2]);
        proc_close($ytdlp_ver_proc);
    }
    // Distinguish a real version string from a missing binary.
    // When the binary is absent, proc_open returns false (proc never started)
    // and $ver stays empty. Unlike the shell error form that shell_exec produced
    // ("sh: 1: /usr/local/bin/yt-dlp: not found"), proc_open with bypass_shell
    // does not generate a shell error message — the absence is indicated by
    // $ver === '' alone. The strpos($ver, 'not installed') check handles the
    // sentinel string (used by the ffmpeg probe). The health check (line 5116)
    // uses strpos($version, 'not installed') === false to detect "not installed",
    // so this sentinel must be consistent.
    if ($ver === '' || strpos($ver, 'not installed') !== false) {
        $ver = 'not installed';
    }
    $GLOBALS['__ytdlp_version'] = $ver;
    if ($version_cache_file) {
        // Always write the cache so the health check (which re-reads the cache
        // from disk, not from $GLOBALS) sees the correct 'not installed' sentinel
        // on subsequent requests. Only write a valid hash when the binary exists —
        // if md5_file fails (binary missing), write an empty hash so the next
        // request re-probes (because empty hash !== any valid hash the binary
        // might have after installation).
        $hash = @md5_file(YTDLP_PATH);
        if ($hash !== false) {
            @file_put_contents($version_cache_file, json_encode(['ver' => $ver, 'hash' => $hash, 'exp' => time() + VERSION_CACHE_TTL]));
        } elseif ($ver === 'not installed') {
            @file_put_contents($version_cache_file, json_encode(['ver' => $ver, 'hash' => '', 'exp' => time() + VERSION_CACHE_TTL]));
        }
    }
}

// Cache ffprobe version similarly — running `ffprobe -version` on every health check
// is wasteful and adds latency under load. Tracks hash to invalidate on binary upgrade.
// ffprobe version cache — keyed on FFPROBE_PATH so that changing the path
// invalidates stale cache entries. The cache filename includes a hash of FFPROBE_PATH
// so that switching to a different ffprobe binary (e.g. /usr/bin/ffprobe vs
// /usr/local/bin/ffprobe) creates a separate cache rather than returning a stale
// version from the previous binary. Only the ffprobe binary is probed (used for
// post-download codec/resolution verification); ffmpeg itself is not separately
// checked since ffprobe is shipped alongside ffmpeg in virtually all deployments.
// If ffprobe is present but ffmpeg is not, AhoyRipper's download flow would fail
// at the yt-dlp merge stage anyway — so checking ffprobe's presence is sufficient.
$ffmpeg_cache_file = '/tmp/ahoyrip_ffprobe_' . md5(FFPROBE_PATH) . '.cache';
$GLOBALS['__ffmpeg_version'] = null;
if ($ffmpeg_cache_file && is_readable($ffmpeg_cache_file)) {
    $cached = @json_decode(@file_get_contents($ffmpeg_cache_file), true);
    if ($cached && is_array($cached) && ($cached['exp'] ?? 0) > time()) {
        $current_hash = @md5_file(FFPROBE_PATH);
        // If the binary can't be read, treat the cache as invalid — we can't
        // verify whether the binary was replaced while the cache was expired.
        if ($current_hash === false) {
            $GLOBALS['__ffmpeg_version'] = null;
        } elseif (isset($cached['hash']) && $current_hash === $cached['hash']) {
            $GLOBALS['__ffmpeg_version'] = $cached['ver'] ?? null;
        }
    }
}
if (!$GLOBALS['__ffmpeg_version']) {
    // Use FFPROBE_PATH (not hardcoded 'ffprobe') so the version probe matches
    // the binary whose hash is used as the cache key. If FFPROBE_PATH points
    // to a non-standard location (e.g. /usr/local/bin/ffprobe on macOS), the
    // version and hash now correctly reference the same binary.
    // Use proc_open array form (bypass_shell=true) instead of shell_exec with a
    // pipe — consistent with the shell-escaping approach used throughout the rest
    // of this file. The pipe (| head -1) is unnecessary since ffprobe's version
    // string is always on the first line of stdout; we read exactly one line.
    $ffprobe_ver_cmd = [FFPROBE_PATH, '-version'];
    $ffprobe_ver_proc = proc_open($ffprobe_ver_cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $ffprobe_ver_pipes, null, [], ['bypass_shell' => true]);
    $ffmpeg_ver = '';
    if ($ffprobe_ver_proc) {
        // Close stdin immediately — we never write to it. Leaving it open causes
        // the child to hold an unused pipe fd; proc_close waits for all pipe
        // writers (stdin writer in the parent) to close before returning.
        fclose($ffprobe_ver_pipes[0]);
        unset($ffprobe_ver_pipes[0]);
        // Read only the first line (version string is always line 1).
        $first_line = fgets($ffprobe_ver_pipes[1]);
        if ($first_line !== false) {
            $ffmpeg_ver = trim($first_line);
        }
        fclose($ffprobe_ver_pipes[1]);
        fclose($ffprobe_ver_pipes[2]);
        proc_close($ffprobe_ver_proc);
    }
    $GLOBALS['__ffmpeg_version'] = $ffmpeg_ver ?: 'not installed';
    if ($ffmpeg_cache_file) {
        $hash = @md5_file(FFPROBE_PATH);
        // Only write to cache when we successfully read the binary.
        // If md5_file fails, skip cache write so the next request re-probes
        // rather than persisting an invalid empty hash that masks binary upgrades.
        if ($hash !== false) {
            @file_put_contents($ffmpeg_cache_file, json_encode(['ver' => $GLOBALS['__ffmpeg_version'], 'hash' => $hash, 'exp' => time() + VERSION_CACHE_TTL]));
        } elseif ($GLOBALS['__ffmpeg_version'] === 'not installed') {
            // md5_file failed AND binary is absent — write a sentinel so subsequent
            // requests don't re-probe every time (matches yt-dlp pattern at line 848).
            @file_put_contents($ffmpeg_cache_file, json_encode(['ver' => $GLOBALS['__ffmpeg_version'], 'hash' => '', 'exp' => time() + VERSION_CACHE_TTL]));
        }
    }
}

// Sanitize string for JSON output
/**
 * Sanitize a value from yt-dlp metadata for safe JSON output.
 *
 * @param mixed $s  Any value from yt-dlp JSON output.
 * @return string|null  'Unknown' for null/empty/whitespace-only strings;
 *   (string)$s for scalar values (int, float, non-empty string);
 *   null for booleans, arrays, and objects (prevents "1"/""/"Array" corruption).
 * @throws InvalidArgumentException  Never thrown; reserved for future validation use.
 */
function clean($s) {
    // Return 'Unknown' for null, empty string, or whitespace-only string.
    // Integer 0 is NOT treated as Unknown — it is a valid numeric value that
    // appears in yt-dlp metadata (e.g., height=0 for audio-only formats).
    // Passing 0 through as '0' (string) keeps the UI consistent and prevents
    // silent label corruption (e.g., "0kbps m4a" would become "Unknown kbps m4a").
    // Whitespace-only strings from yt-dlp metadata would produce blank or
    // space-filled labels (e.g., "  kbps m4a") — trim before checking emptiness.
    if (is_string($s)) {
        $s = trim($s);
        if ($s === '') return 'Unknown';
    } elseif ($s === null) {
        return 'Unknown';
    }
    // Reject booleans, arrays and objects — yt-dlp metadata is always scalar
    // (string, int, float, or null). A boolean in a format label field would
    // become "1" or "" (empty string) via (string) cast, silently corrupting
    // the label. An array/object would become the literal string "Array", also
    // corrupting the API response. Return null for all of these so the label
    // builder's `$format_note ?: null` correctly produces null rather than the
    // truthy string 'Unknown' (which would append "Unknown" to the format label).
    // Floats (float type) pass through to (string) coercion which is safe and
    // produces a canonical string representation (e.g. 60.0 → "60").
    if (is_bool($s) || is_array($s) || is_object($s)) return null;
    // No htmlspecialchars — API outputs JSON, not HTML.
    // Type coercion to string is sufficient.
    return (string)$s;
}

/**
 * Resolve the playlist URL parameter to yt-dlp playlist flags.
 *
 * yt-dlp accepts --yes-playlist (fetch all videos in a playlist) and
 * --no-playlist (fetch single video only). yt-dlp does NOT support
 * --playlist true/false — that syntax is rejected as ambiguous.
 *
 * The ?playlist=1 URL param requests playlist mode; all other values
 * (including absent, empty, 0, "yes", "true") default to --no-playlist.
 *
 * @param string|null $playlist_get  $_GET['playlist'] value
 * @return array  Array of flag strings, e.g. ['--yes-playlist'] or ['--no-playlist']
 * @throws InvalidArgumentException  Never thrown; reserved for future validation use.
 */
function resolvePlaylistFlag($playlist_get) {
    // Reject booleans explicitly — isset(true) is true and 1&&!is_string(true)
    // is true, causing boolean true to incorrectly return --yes-playlist via
    // the ($playlist_get === 1 && !is_string()) arm. URL params are always
    // strings; booleans should never reach this function.
    if (is_bool($playlist_get)) {
        return ['--no-playlist'];
    }
    // yt-dlp does NOT support --playlist true/false — that syntax is rejected
    // as ambiguous. Only --yes-playlist and --no-playlist are valid.
    // Treat playlist=1 as the only truthy value.
    // Accepts string '1' (canonical URL param) and int 1 (edge case from PHP code).
    // Explicitly reject numeric strings like '01' and '1.0' that would be true
    // for loose int comparison but are not the canonical '1' value.
    // All other values ('yes', 'true', '01', '1.0', 0, null, etc.) → --no-playlist.
    if (isset($playlist_get) && ($playlist_get === '1' || ($playlist_get === 1 && !is_string($playlist_get)))) {
        return ['--yes-playlist'];
    }
    return ['--no-playlist'];
}

// Classify yt-dlp error messages into actionable error codes.
// Each entry includes an HTTP status code appropriate to the error category:
//   451 — Unavailable For Legal Reasons (geo-restricted, copyright, TOS)
//   410 — Gone (video removed/deleted)
//   429 — Too Many Requests (source-side rate limiting)
//   403 — Forbidden (private, age-restricted, login required)
//   404 — Not Found (playlist missing, unsupported site)
//   502 — Bad Gateway (connection/SSL failures)
//   413 — Payload Too Large (file exceeds server limit)
//   422 — Unprocessable Entity (format unavailable — client chose invalid option)
/**
 * Classify a yt-dlp error message into a structured error with HTTP status.
 *
 * @param string $raw_err   Raw stderr/output from yt-dlp.
 * @param int|null $exit_code  yt-dlp exit code (null if unknown).
 * @return array|null  ['code' => string, 'msg' => string, 'status' => int] on match; null if unclassified.
 * @throws InvalidArgumentException  Never thrown; reserved for future validation use.
 */
function classifyYtdlpError($raw_err, $exit_code = null) {
    $err_lower = strtolower($raw_err);
    if (preg_match('/geo.*restriction|this video is available in|geo.?restricted(?!.)/i', $err_lower)) {
        return ['code' => 'GEOBLOCKED', 'msg' => 'This video is geo-restricted and not available in your region.', 'upgrade_url' => UPGRADE_URL, 'status' => 451];
    }
    // Standalone "geo restricted" (no characters after "geo") — the single-word
    // form yt-dlp sometimes emits. Separate from the geo.?restricted pattern above
    // (which requires characters after "restricted" and uses (?!.) to prevent
    // "geo restriction" from matching here, since that pattern fires first).
    if (preg_match('/\bgeo restricted\b/i', $err_lower)) {
        return ['code' => 'GEOBLOCKED', 'msg' => 'This video is geo-restricted and not available in your region.', 'upgrade_url' => UPGRADE_URL, 'status' => 451];
    }
    if (preg_match('/video is private|this video is private/i', $err_lower)) {
        return ['code' => 'PRIVATE_VIDEO', 'msg' => 'This video is private and cannot be downloaded.', 'upgrade_url' => UPGRADE_URL, 'status' => 403];
    }
    // "authentication required" must be checked separately because the merged pattern
    // "authentication.*required" requires the word "required" to appear twice —
    // yt-dlp only says it once ("authentication required"), so we match it directly.
    // "sign in to confirm" is yt-dlp's bot-confirm message (Google/YouTube): the user
    // must sign in to their browser (passing cookies via --cookies) to proceed.
    if (preg_match('/authentication required|login.*required|this video requires login|sign in to confirm/i', $err_lower)) {
        return ['code' => 'LOGIN_REQUIRED', 'msg' => 'This video requires login or subscription.', 'upgrade_url' => UPGRADE_URL, 'status' => 401];
    }
    if (preg_match('/not.*support|unsupported site|is not a supported URL/i', $err_lower)) {
        return ['code' => 'UNSUPPORTED_SITE', 'msg' => 'This site is not supported by yt-dlp.', 'upgrade_url' => UPGRADE_URL, 'status' => 404];
    }
    if (preg_match('/playlist.*not.*found|does not exist/i', $err_lower)) {
        return ['code' => 'PLAYLIST_MISSING', 'msg' => 'Playlist not found or no longer exists.', 'upgrade_url' => UPGRADE_URL, 'status' => 404];
    }
    if (preg_match('/copyright|\binfringe\b|removed.*by|content.*strike/i', $err_lower)) {
        return ['code' => 'COPYRIGHT_REMOVED', 'msg' => 'This content has been removed due to a copyright claim.', 'upgrade_url' => UPGRADE_URL, 'status' => 451];
    }
    if (preg_match('/too.*many.*requests|429/i', $err_lower)) {
        return ['code' => 'SOURCE_RATE_LIMITED', 'msg' => 'The source site is rate-limiting requests. Try again in a few minutes, or use AhoyVPN for a different exit IP.', 'upgrade_url' => UPGRADE_URL, 'status' => 429];
    }
    if (preg_match('/video (has been )?(removed|delisted|unavailable|deleted)|this video (is no longer available|has been (removed|delisted|deleted))|video (has been )?removed|video (is )?unavailable|video (is )?deleted/i', $err_lower)) {
        return ['code' => 'VIDEO_UNAVAILABLE', 'msg' => 'This video is no longer available or has been removed.', 'upgrade_url' => UPGRADE_URL, 'status' => 410];
    }
    if (preg_match('/age.*restriction|under age|video is age.*restricted|age restricted/i', $err_lower)) {
        return ['code' => 'AGE_RESTRICTED', 'msg' => 'This video is age-restricted and cannot be downloaded without verification.', 'upgrade_url' => UPGRADE_URL, 'status' => 403];
    }
    if (preg_match('/certificate.*expired|ssl.*error|sslerr|tls handshake/i', $err_lower)) {
        return ['code' => 'SSL_ERROR', 'msg' => 'Secure connection to the source failed. Try again shortly, or use AhoyVPN to change your exit IP.', 'upgrade_url' => UPGRADE_URL, 'status' => 502];
    }
    // yt-dlp 2024.09+ --impersonate feature requires the curl_cffi Python library.
    // Without it, yt-dlp throws "Impersonate target X is not available" (exit 1).
    // Classify this as a CONFIG_ERROR so operators know it's a deployment/dependency
    // issue, not a video or format problem — users should not see FORMAT_UNAVAILABLE.
    if (preg_match('/impersonate.*not available|is not available.*impersonate/i', $err_lower)) {
        return ['code' => 'CONFIG_ERROR', 'msg' => 'Browser impersonation is not available. The curl_cffi Python library may be missing on the server. Contact the operator or set AHOY_IMPERSONATE to an empty string to disable impersonation.', 'upgrade_url' => UPGRADE_URL, 'status' => 503];
    }

    // "process timed out" is produced by the PHP-side timeout in the inline
    // proc_open timeout handler (api.php).
    // Distinct from connection-level "timed out" which implies a network failure.
    // The PHP-side timeout fires when (time() - $start) > INFO_TIMEOUT (configurable
    // via YTDLP_TIMEOUT env var, default 45s) and terminates the yt-dlp process.
    // INFO_TIMEOUT (controlled by YTDLP_TIMEOUT) limits the info action;
    // DOWNLOAD_TIMEOUT (controlled by YTDLP_DOWNLOAD_TIMEOUT) limits the download action.
    // This means the server reached the source but it was too slow to respond within
    // the allowed window. Return 504 so the client distinguishes it from CONNECTION_FAILED
    // (502) which implies a network or DNS issue on our end.
    if (preg_match('/process timed out|read at byte.*timeout/i', $err_lower)) {
        return ['code' => 'SOURCE_TIMEOUT', 'msg' => 'The source site took too long to respond. Try a smaller format (audio-only is fastest) or try again when the site is less busy.', 'upgrade_url' => UPGRADE_URL, 'status' => 504];
    }

    // CONNECTION_FAILED: broad class of connection-level failures where data transfer
    // started but was interrupted (reset, broken pipe, DNS failure, etc.).
    // Does NOT include standalone "connection timed out" — that is classified as
    // CONNECTION_TIMEOUT (504) by the dedicated check below.
    // (?<!process )timed out\b — "timed out" as a standalone word, NOT preceded
    // by "process " (PHP-side timeout → SOURCE_TIMEOUT above). Negative lookbehind
    // (?<!) checks the character positions immediately before "timed" and correctly
    // rejects "Process timed out". A negative lookahead (?!...) at the word boundary
    // would check what FOLLOWS "timed out", not what precedes it — it cannot
    // exclude "Process timed out" based on the prefix. The lookbehind is correct.
    // \bi?/o timeout\b — IO timeout as a standalone word (handles "i/o timeout").
    if (preg_match('#connection.*fail|dns.*fail|could not connect|\bi?/o timeout\b|(?<!process )timed out\b|connection reset|broken pipe|unable to connect|connection refused|getaddrinfo failed|name or service not known|network is unreachable|no route to host#i', $err_lower)) {
        return ['code' => 'CONNECTION_FAILED', 'msg' => 'Could not connect to the source. Check your network and try again, or use AhoyVPN to change your exit IP.', 'upgrade_url' => UPGRADE_URL, 'status' => 502];
    }
    // CONNECTION_TIMEOUT: TCP-level connection timeout — the TCP handshake stalled
    // before any data was transferred (distinct from SOURCE_TIMEOUT where data was
    // transferred but the source took too long). yt-dlp emits "connection timed out"
    // for this case. Runs AFTER CONNECTION_FAILED so that generic connection failures
    // (reset, broken pipe, etc.) are caught first; a bare "connection timed out"
    // with no other qualifier routes here (504) instead of CONNECTION_FAILED (502).
    if (preg_match('#\bconnection timed out\b(?!\s)(?!\s+after)#i', $err_lower)) {
        return ['code' => 'CONNECTION_TIMEOUT', 'msg' => 'Connection timed out before the source responded. Use AhoyVPN to change your exit IP and try again.', 'upgrade_url' => UPGRADE_URL, 'status' => 504];
    }
    if (preg_match('/file.*larger|file.*too large|size.*exceed|exceeds.*limit/i', $err_lower)) {
        return ['code' => 'FILE_TOO_LARGE', 'msg' => 'This file exceeds the maximum size for this server. Try an audio-only or lower-resolution format.', 'upgrade_url' => UPGRADE_URL, 'status' => 413];
    }
    if (preg_match('/requested format(?!s)|requested.*not.*available|format.*not.*available|does not contain|does not match/i', $err_lower)) {
        return ['code' => 'FORMAT_UNAVAILABLE', 'msg' => 'That format is not available for this video. Select another from the list.', 'upgrade_url' => UPGRADE_URL, 'status' => 422];
    }
    // yt-dlp emits "content is not allowed" (with status 451 from some extractors) when
    // the source blocks content on legal/TOS grounds — distinct from HTTP 403 which
    // signals an IP ban (SOURCE_FORBIDDEN). Also catches explicit TOS-violation messages.
    // The 'disallowed.*content' check is kept separate from 'content.*violat' so that
    // a plain "disallowed content" (no violation language) is NOT classified here —
    // it falls through to SOURCE_FORBIDDEN (HTTP 403) if the message contains "content
    // is not allowed" specifically from yt-dlp, use the content-disallowed sentinel.
    // Negative lookahead (?!\S+\s+\S+) prevents "disallowed content" (two separate words
    // where "content" immediately follows "disallowed") from matching — that pattern
    // fires for generic "disallowed content" errors that should route to SOURCE_FORBIDDEN.
    // (?<!\bdisallowed\s) prevents "content" preceded by "disallowed " from matching
    // (same intent as the negative lookahead above, belt-and-suspenders).
    if (preg_match('/\bdisallowed\b(?!\s+content\b)(?!.*\bTOS\b)(?!.*\bterms\b)|content-disallow(ed)?\b|TOS.*violat|terms.*of.*service.*violat|violat.*(TOS|terms.*of.*service)/i', $err_lower)) {
        return ['code' => 'DISALLOWED_CONTENT', 'msg' => 'This content is not available due to a terms of service or legal violation.', 'upgrade_url' => UPGRADE_URL, 'status' => 451];
    }
    // HTTP error responses from the source site (e.g. "HTTP Error 403: Forbidden").
    // yt-dlp emits these when the source returns a non-2xx status. The numeric
    // status is extracted from the message for classification; 403/404/429 are the
    // most common and map to existing error codes. Others fall through to a generic
    // upstream HTTP error response.
    if (preg_match('/http error (\d+)/i', $err_lower, $m)) {
        $code = (int)$m[1];
        if ($code === 403) {
            return ['code' => 'SOURCE_FORBIDDEN', 'msg' => 'The source site blocked this request (HTTP 403). Try a different format or use AhoyVPN to change your exit IP.', 'upgrade_url' => UPGRADE_URL, 'status' => 403];
        }
        if ($code === 401 || $code === 407) {
            return ['code' => 'LOGIN_REQUIRED', 'msg' => 'This content requires authentication. Sign in to the platform in your browser, or pass cookies to yt-dlp (see README).', 'upgrade_url' => UPGRADE_URL, 'status' => 401];
        }
        if ($code === 404) {
            return ['code' => 'SOURCE_NOT_FOUND', 'msg' => 'The source returned HTTP 404 — the content may have been moved or deleted.', 'upgrade_url' => UPGRADE_URL, 'status' => 404];
        }
        if ($code === 429) {
            return ['code' => 'SOURCE_RATE_LIMITED', 'msg' => 'The source site is rate-limiting requests. Try again in a few minutes, or use AhoyVPN for a different exit IP.', 'upgrade_url' => UPGRADE_URL, 'status' => 429];
        }
        if ($code === 500 || $code === 502 || $code === 503) {
            return ['code' => 'SOURCE_HTTP_ERROR', 'msg' => "The source site returned HTTP $code and is having issues. Try again shortly, or use AhoyVPN for a different exit IP.", 'upgrade_url' => UPGRADE_URL, 'status' => $code];
        }
        // Other HTTP errors — surface the status but give a generic message.
        return ['code' => 'SOURCE_HTTP_ERROR', 'msg' => "The source site returned HTTP $code. Try again shortly, or use AhoyVPN for a different exit IP.", 'upgrade_url' => UPGRADE_URL, 'status' => $code];
    }
    // yt-dlp exit codes carry semantic meaning that supplements text classification.
    // Exit code 1 is the most common error code — it means "there was a problem" but often
    // carries no descriptive stderr text (just "error" or empty). Fall back to it only
    // after all specific text-pattern checks above have been exhausted.
    // Text-based matches take absolute precedence — a geo-blocked video that also produces
    // exit code 1 still returns GEOBLOCKED (451), not FORMAT_UNAVAILABLE (422).
    if ($exit_code !== null && $exit_code !== 0) {
        if ($exit_code === 1) {
            return ['code' => 'FORMAT_UNAVAILABLE', 'msg' => 'That format is not available for this video. Select another from the list.', 'upgrade_url' => UPGRADE_URL, 'status' => 422];
        }
        // Exit codes ≥2 indicate serious errors (download failed, post-processing failed, etc.)
        if ($exit_code >= 2) {
            return ['code' => 'YTDLP_ERROR', 'msg' => 'yt-dlp encountered an error processing this request.', 'upgrade_url' => UPGRADE_URL, 'status' => 422];
        }
    }
    return null;
}

// Parse yt-dlp output to extract formats
// $sort: one of 'height' (default), 'filesize', 'filesize_asc', 'tbr', 'quality', 'audio_quality'
/**
 * Parse yt-dlp JSON output into a structured format list response.
 *
 * @param string $json_str       Raw yt-dlp stdout (newline-delimited JSON for playlists).
 * @param string|null &$raw_error_out  Populated with raw yt-dlp error text on parse failure.
 * @param string $sort           Sort key: 'height' (default), 'filesize', 'filesize_asc', 'tbr', 'quality', 'audio_quality'.
 * @param int|null $exit_code    yt-dlp exit code. Passed to classifyYtdlpError so the
 *                                exit-code fallback (exit 1 → FORMAT_UNAVAILABLE, exit ≥2
 *                                → YTDLP_ERROR) fires when the error text alone is ambiguous.
 * @return array  ['formats' => [...], 'error' => string|null, 'error_code' => string|null, 'title' => string|null, ...].
 * @throws InvalidArgumentException  Never thrown; reserved for future validation use.
 */
function parseFormats($json_str, &$raw_error_out = null, $sort = 'height', $exit_code = null) {
    // Validate sort key — makes parseFormats self-contained and safe for reuse.
    $allowed_sorts = ['height', 'filesize', 'filesize_asc', 'tbr', 'quality', 'audio_quality'];
    if (!in_array($sort, $allowed_sorts, true)) {
        $sort = 'height';
    }
    // Reject non-string input explicitly. yt-dlp always returns a string; any other
    // type (null, int, array, object) indicates a caller bug or unexpected state.
    // Without this guard, e.g. trim(null) produces a PHP warning that leaks into
    // error_log and could expose implementation details. Return a clean PARSE_ERROR.
    if (!is_string($json_str)) {
        $parse_fail_msg = 'Internal parse error — invalid input type.';
        if ($raw_error_out !== null) {
            $raw_error_out = $parse_fail_msg;
        }
        return [
            'error' => 'Could not parse video info. The site may not be supported or returned a non-standard response.',
            'error_code' => 'PARSE_ERROR',
            'raw_error' => $parse_fail_msg,
            'formats' => [],
            // platform: available from $first_valid when playlist JSON was partially parsed;
            // null when the failure occurred before any valid JSON was collected.
            'platform' => $first_valid['extractor_key'] ?? null,
        ];
    }
    // yt-dlp outputs newline-delimited JSON when --yes-playlist is used (playlist=1),
    // with one JSON object per video. A single json_decode() on the full multi-line
    // string fails because newlines are not valid JSON separators. Detect and handle
    // this by splitting on newlines and parsing each line independently, then merging
    // formats from all videos into a single formats array.
    $lines = preg_split('/\r\n|\n|\r/', trim($json_str));
    $all_formats = [];
    $first_valid = null;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed === '...') continue; // skip empty / truncation sentinel
        $decoded = json_decode($trimmed, true);
        if ($decoded && is_array($decoded) && array_key_exists('formats', $decoded)) {
            if ($first_valid === null) $first_valid = $decoded;
            $all_formats = array_merge($all_formats, $decoded['formats'] ?? []);
        }
    }
    // If we successfully collected formats from multiple lines, this was a playlist.
    // Use the first entry's metadata and the merged formats array.
    if ($first_valid !== null && !empty($all_formats)) {
        $data = $first_valid;
        // Replace formats with the merged collection from all playlist entries.
        $data['formats'] = $all_formats;
        // The single-json-decode path below will process $data normally from here.
    } else {
        $data = json_decode($json_str, true);
    }
    if (!$data) {
        // Repair non-UTF-8 byte sequences before declaring the JSON invalid.
        // yt-dlp metadata from niche/extractor-specific sites may contain invalid
        // UTF-8 (e.g. raw ESC sequences in titles, locale-specific characters
        // that don't round-trip through PHP's default ISO-8859-1 interpretation).
        // mb_convert_encoding replaces malformed byte sequences with a replacement
        // character (U+FFFD), producing valid UTF-8 that json_decode can parse.
        // This is idempotent for valid UTF-8 input — no change if already clean.
        $data = json_decode(mb_convert_encoding($json_str, 'UTF-8', 'UTF-8'), true);
    }
    if (!$data) {
        // Differentiate yt-dlp errors from actual parsing failures.
        // Clean the raw output first so the ERROR/WARNING prefix check (and all
        // downstream processing) operates on sanitized text rather than raw bytes.
        $raw = trim($json_str);
        $err_msg = preg_replace('/[\x00-\x1F\x7F]/', '', $raw);
        $err_msg = strip_tags($err_msg);
        $err_msg = preg_replace('/\s+/', ' ', $err_msg);
        if (preg_match('/^(ERROR|WARNING)/im', $err_msg)) {
            // yt-dlp returned an error message — surface it clearly.
            // $err_msg is already whitespace-normalized, so classification patterns
            // have the best chance of matching.

            // Classify on the FULL message — truncation would discard the tail of
            // long errors that may contain the distinguishing keyword (e.g. "login required"
            // at byte 300 of a 500-byte message). The classified['msg'] is always short
            // so it never needs truncation; the user-facing error uses that short message.
            $classified = classifyYtdlpError($err_msg, $exit_code);
            if ($classified) {
                // raw_error_out: truncated only for the raw diagnostic field — the
                // classified human-readable message (used in 'error') is always concise.
                $raw_diag = $err_msg;
                if (mb_strlen($raw_diag, 'UTF-8') > 200) {
                    $raw_diag = mb_substr($raw_diag, 0, 200, 'UTF-8') . '...';
                }
                if ($raw_error_out !== null) {
                    $raw_error_out = $raw_diag;
                }
                // Always include 'formats' => [] so API consumers can always
                // access response.formats without checking if the key exists first.
                // 'upgrade_url' is included so classified source errors (rate-limit, forbidden,
                // timeout, etc.) surface the AhoyVPN upsell opportunity in info responses.
                // 'platform': available from $first_valid when playlist JSON was parsed;
                // null when the failure occurred before any valid JSON was collected.
                return [
                    'error' => $classified['msg'],
                    'error_code' => $classified['code'],
                    'formats' => [],
                    'upgrade_url' => $classified['upgrade_url'] ?? UPGRADE_URL,
                    'platform' => $first_valid['extractor_key'] ?? null,
                ];
            }
            // Unclassified yt-dlp error: use truncated version for the user-facing
            // message (long raw strings are unreadable); preserve full text in raw_error.
            if (mb_strlen($err_msg, 'UTF-8') > 200) {
                $err_msg = mb_substr($err_msg, 0, 200, 'UTF-8') . '...';
            }
            if ($raw_error_out !== null) {
                $raw_error_out = $err_msg;
            }
            // Always include 'formats' => [] so API consumers can always
            // access response.formats without checking if the key exists first.
            return ['error' => 'yt-dlp error: ' . $err_msg, 'error_code' => 'YTDLP_ERROR', 'raw_error' => $err_msg, 'formats' => [], 'platform' => $first_valid['extractor_key'] ?? null];
        }
        // True JSON parse failure — return a structured PARSE_ERROR so the
        // frontend's error hint ('PARSE_ERROR' → "Could not parse...") fires.
        // Assign the message to $raw_error_out when the caller passed a reference
        // so the diagnostic string propagates to the 'raw_error' field in the
        // returned array. When $raw_error_out was passed as null (caller didn't
        // request raw error capture), the assignment is skipped and 'raw_error'
        // is set to null — the ternary always evaluates both sides before PHP 8's
        // short-circuit, so we use if/else for clarity and correctness.
        $parse_fail_msg = 'JSON parse failed — response was not valid JSON.';
        if ($raw_error_out !== null) {
            $raw_error_out = $parse_fail_msg;
        }
        // Always include 'formats' => [] so API consumers can always
        // access response.formats without checking if the key exists first.
        return [
            'error' => 'Could not parse video info. The site may not be supported or returned a non-standard response.',
            'error_code' => 'PARSE_ERROR',
            'raw_error' => $parse_fail_msg,
            'formats' => [],
            'platform' => $first_valid['extractor_key'] ?? null,
        ];
    }

    // JSON parsed successfully but has no formats key — this is a distinct
    // failure mode from a true JSON parse failure. yt-dlp always includes
    // a formats array in its output; an absent formats key indicates the
    // extractor returned a partial/empty response (e.g. unsupported site
    // with no fallback, or a site that returned non-standard JSON).
    // Return a classified PARSE_ERROR so the client shows a specific message.
    if (!array_key_exists('formats', $data)) {
        $no_formats_msg = 'No formats returned — site may be unsupported or returned non-standard metadata.';
        if ($raw_error_out !== null) {
            $raw_error_out = $no_formats_msg;
        }
        return [
            'error' => 'Could not parse video info. The site may not be supported or returned a non-standard response.',
            'error_code' => 'PARSE_ERROR',
            // Use the computed message when caller didn't pass $raw_error_out (null).
            // Mirrors the pattern used in the JSON-parse-failure case above.
            'raw_error' => $raw_error_out ?? $no_formats_msg,
            // Always include 'formats' => [] so API consumers can always
            // access response.formats without checking if the key exists first.
            'formats' => [],
            'platform' => $first_valid['extractor_key'] ?? null,
        ];
    }

    $title = clean($data['title'] ?? 'Unknown');
    $thumbnail = clean($data['thumbnail'] ?? '');
    $duration = (int)($data['duration'] ?? 0);
    $uploader = clean($data['uploader'] ?? '');
    // extractor_key is the platform name yt-dlp uses (e.g. "YouTube", "Twitter", "TikTok").
    // Surface it in the info response so the UI can display "From: YouTube" to confirm
    // the URL was parsed by the correct extractor.
    $platform = clean($data['extractor_key'] ?? '');
    // webpage_url is the canonical video page URL (e.g. https://www.youtube.com/watch?v=...).
    // This is the URL the user originally submitted (after HTTPS normalization by yt-dlp).
    // Exposing it enables API consumers to correlate info responses with the originating
    // URL without requiring the client to track it separately across requests.
    $video_url = isset($data['webpage_url']) && is_string($data['webpage_url'])
        ? $data['webpage_url']
        : null;
    // uploader_url is the URL to the video/channel page (e.g. YouTube channel URL).
    // Return null when absent so API consumers can distinguish "no URL provided" from
    // empty string — both clean() to 'Unknown' but uploader_url should be null.
    $uploader_url = isset($data['uploader_url']) && $data['uploader_url'] !== ''
        ? (string)$data['uploader_url']
        : null;
    // Sanitize a derived filename from the title for use in Content-Disposition.
    // yt-dlp would name the file this way; we use it so the browser saves a
    // meaningful name instead of the generic "ahoyrip.mp4".
    // Use \p{L}\p{N} (Unicode letters and numbers) instead of \w which only
    // matches ASCII letters in PHP. This preserves non-Latin titles
    // (Japanese, Chinese, Arabic, Cyrillic, etc.) in the derived filename.
    // The /u flag enables UTF-8 mode for Unicode property escapes.
    $raw_fn = preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $title);
    $raw_fn = preg_replace('/\s+/u', '_', trim($raw_fn));
    if (strlen($raw_fn) > MAX_FILENAME_LEN) $raw_fn = substr($raw_fn, 0, MAX_FILENAME_LEN);
    // Fall back to 'ahoyrip' when the title was entirely numeric (e.g. "0", "1080")
    // and all digits were stripped by the sanitization regex above. Also guard
    // against empty string after trim (whitespace-only titles).
    // Use ctype_digit() to catch ALL purely-numeric titles, not just "0".
    // PHP's empty('1080') is false, so $raw_fn ?: 'ahoyrip' would incorrectly
    // use '1080' as the derived filename for a video whose title is "1080".
    $derived_filename = ($raw_fn !== '' && !ctype_digit($raw_fn)) ? $raw_fn : 'ahoyrip';

    $formats = [];
    foreach (($data['formats'] ?? []) as $f) {
        $ext = clean($f['ext'] ?? '');
        $format_id = clean($f['format_id'] ?? '');
        $format_note = clean($f['format_note'] ?? '');
        $tbr = isset($f['tbr']) ? round((float)$f['tbr']) : null;
        $filesize = isset($f['filesize']) ? (int)$f['filesize'] : (isset($f['filesize_approx']) ? (int)$f['filesize_approx'] : 0);
        $width = isset($f['width']) ? (int)$f['width'] : 0;
        $height = isset($f['height']) ? (int)$f['height'] : 0;
        $vcodec = clean($f['vcodec'] ?? 'none');
        $acodec = clean($f['acodec'] ?? 'none');
        $fps = isset($f['fps']) && $f['fps'] !== null ? (int)(float)$f['fps'] : null;
        $language = clean($f['language'] ?? '');
        $format_description = clean($f['format_description'] ?? '');
        $abr = isset($f['abr']) ? (int)$f['abr'] : null;

        // Build label
        $label = '';
        if ($vcodec !== 'none' && $acodec !== 'none') {
            // Video+audio combined
            if ($height > 0) {
                $label = "{$height}p";
                if ($fps) $label .= "{$fps}";
                // Skip 'Unknown' sentinel — clean() returns 'Unknown' for null/empty
                // format_note values (absent or malformed yt-dlp metadata). Appending it
                // would produce ugly labels like "1080p60 Unknown mp4".
                if ($format_note && $format_note !== 'Unknown') $label .= " {$format_note}";
                $label .= " {$ext}";
            } else {
                // height=0 means yt-dlp didn't report a resolution (e.g. audio-video
                // stream with no declared frame size) — fall back to extension only.
                $label = strtoupper($ext);
            }
        } elseif ($vcodec !== 'none') {
            // Video only
            if ($height > 0) {
                $label = "Video {$height}p";
                if ($fps) $label .= " {$fps}fps";
                $label .= " {$ext}";
            } else {
                // height=0 for video-only is malformed yt-dlp metadata — omit resolution.
                $label = "Video {$ext}";
            }
        } elseif ($acodec !== 'none') {
            // Audio only
            $br = $tbr ?? (isset($f['abr']) ? (int)$f['abr'] : null);
            if ($br) {
                $label = "{$br}kbps {$ext}";
            } else {
                $label = "Audio {$ext}";
            }
        } else {
            continue; // skip unknown
        }
        // Build description string:
        // - Video-containing formats (combined or video-only): always prepend
        //   resolution when width + height are available (e.g. "1920x1080 1080p60 HDR 10bit").
        // - When format_description is absent (empty or "Unknown"), fall back to
        //   format_note first (e.g. "480p" or "720p60 HDR"), then the compact
        //   label as the final fallback.
        // - Audio-only formats: never prefix resolution; use label directly since
        //   format_description carries no useful resolution context for audio.
        $resolution = ($width > 0 && $height > 0) ? ($width . 'x' . $height) : null;
        if ($resolution !== null && $vcodec !== 'none') {
            // Video-containing formats (combined or video-only) get resolution prefix.
            // Use null/empty-string/'Unknown' checks instead of empty() to avoid false
            // positives on the literal string "0" (empty("0") === true in PHP).
            // 'Unknown' is clean()'s sentinel for absent/malformed values (null, '',
            // arrays, objects) — treat it the same as absent so the format_note fallback
            // fires when clean() normalizes a missing description to 'Unknown'.
            $has_desc = $format_description !== null && $format_description !== '' && $format_description !== 'Unknown';
            $desc = !$has_desc
                ? trim("{$resolution} " . ($format_note ?: $label))
                : trim("{$resolution} {$format_description}");
        } else {
            // Audio-only (or unknown codec with no resolution): use label directly.
            $desc = $label;
        }

        // Estimate filesize if not available
        if ($filesize === 0) {
            $duration_secs = $duration ?: 180;
            if ($vcodec !== 'none' && $acodec !== 'none') {
                // Video+audio
                $bitrate_kbps = $tbr ?? (($height > 720) ? 5000 : (($height > 480) ? 2500 : 1000));
                $filesize = ($bitrate_kbps * 1000 / 8) * $duration_secs;
            } elseif ($vcodec !== 'none') {
                $bitrate_kbps = $tbr ?? (($height > 720) ? 4000 : 1500);
                $filesize = ($bitrate_kbps * 1000 / 8) * $duration_secs;
            } else {
                // Audio-only with no bitrate data: use a sensible default (128kbps).
                $bitrate_kbps = $tbr ?? $abr ?? 128;
                $filesize = ($bitrate_kbps * 1000 / 8) * $duration_secs;
            }
        }

        $filesize_mb = round($filesize / 1048576, 1);

        // quality: numeric quality tier for sorting/filtering without parsing description strings.
        // - Video/combined formats: pixel height (e.g. 1080, 720, 480) — same as height.
        // - Audio-only formats: approximate bitrate tier (320, 256, 192, 128, 96, 64, 48).
        //   Audio quality is subjective; tier numbers map loosely to kbps so API consumers
        //   can sort audio by quality without needing to know codec specifics.
        // - null for unknown/unparseable formats.
        $quality = null;
        if ($vcodec !== 'none') {
            $quality = $height;
        } elseif ($acodec !== 'none') {
            // Map common audio bitrates to tier numbers for consistent sorting.
            // yt-dlp reports abr in kbps; use it when available.
            $br = $tbr ?? $abr;
            if ($br !== null) {
                if ($br >= 320) $quality = 320;
                elseif ($br >= 256) $quality = 256;
                elseif ($br >= 192) $quality = 192;
                elseif ($br >= 128) $quality = 128;
                elseif ($br >= 96) $quality = 96;
                elseif ($br >= 64) $quality = 64;
                else $quality = 48;
            } else {
                // Audio-only format with no bitrate metadata at all (e.g. opus/ogg
                // where yt-dlp doesn't report abr). Assign a low-tier fallback so it
                // still participates in quality-based sorting rather than being null
                // (which sorts last in descending sorts, obscuring real audio options).
                $quality = 32;
            }
        }

        $is_combined = ($vcodec !== 'none' && $acodec !== 'none');
        $is_video_only = ($vcodec !== 'none' && $acodec === 'none');
        $is_audio_only = ($vcodec === 'none' && $acodec !== 'none');
        $formats[] = [
            'id' => $format_id,
            'label' => $label,
            'description' => $desc,
            'format_note' => $format_note,
            'format_description' => $format_description !== 'Unknown' ? $format_description : null,
            'ext' => $ext,
            'filesize_mb' => $filesize_mb,
            'height' => $height,
            'quality' => $quality,
            'fps' => $fps,
            'tbr' => $tbr,
            'abr' => $abr,
            'vcodec' => $vcodec,
            'acodec' => $acodec,
            'format_type' => ($vcodec !== 'none' && $acodec !== 'none') ? 'combined' : ($vcodec !== 'none' ? 'video' : 'audio'),
            'type_group' => $is_combined ? 0 : ($is_video_only ? 1 : 2),
            'language' => $language ?: null,
        ];
    }

    // Sort: combined formats first, then by the caller's selected sort key.
    // $sort is one of 'height' (default), 'filesize', 'filesize_asc', 'tbr', 'quality',
    // 'audio_quality' — validated by the caller before being passed in, so no
    // additional validation is needed here.
    usort($formats, function($a, $b) use ($sort) {
        // ── audio_quality: audio-first ordering ─────────────────────────────────
        // audio formats (type_group=2) come BEFORE video/combined (type_group=0,1).
        // Within each group, sort by quality tier descending, then tbr descending.
        if ($sort === 'audio_quality') {
            // type_group: 0=combined, 1=video-only, 2=audio-only.
            // Audio-first means higher type_group values come FIRST (audio-only=2 before video=1 before combined=0).
            // $b['type_group'] <=> $a['type_group'] gives: 2>1>0 — audio-first. Correct.
            $ag = $b['type_group'] <=> $a['type_group'];
            if ($ag !== 0) {
                return $ag;
            }
            // Same type group — primary: quality tier desc, secondary: tbr desc
            $cmp = ($b['quality'] ?? -1) <=> ($a['quality'] ?? -1);
            if ($cmp === 0) {
                $cmp = ($b['tbr'] ?? 0) <=> ($a['tbr'] ?? 0);
            }
            return $cmp;
        }

        // ── standard sort: type_group primary, then sort key ───────────────────
        // type_group: 0=combined, 1=video-only, 2=audio-only — used as primary
        // sort key for 'quality' sort so video formats always appear before audio
        // regardless of their quality number (e.g. 720p video-only = 720 sorts
        // above 320kbps audio-only = 320, which would be wrong if sorted by quality alone).
        $type_cmp = $a['type_group'] <=> $b['type_group'];
        if ($type_cmp !== 0) {
            // Different type groups — sort by group order (combined → video → audio).
            // For 'quality' sort this is the primary signal; for other sorts it ensures
            // the type-group separation is preserved even when sort keys are equal.
            return $type_cmp;
        }
        // Same type group — sort by the caller's selected key.
        // For filesize_asc: use PHP_INT_MAX as the null sentinel so unknown sizes
        // sort LAST (ascending = smallest first, so null = unknown = largest unknown
        // = should appear after known values). Using 0 as the sentinel incorrectly
        // put unknown-size formats at the top of an ascending (smallest-first) sort.
        // Using -PHP_INT_MAX (negative) would make null sort as the smallest value
        // in an ascending sort — the opposite of the intended behavior.
        if ($sort === 'filesize') {
            $cmp = ($b['filesize_mb'] ?? 0) <=> ($a['filesize_mb'] ?? 0);
        } elseif ($sort === 'filesize_asc') {
            $cmp = ($a['filesize_mb'] ?? PHP_INT_MAX) <=> ($b['filesize_mb'] ?? PHP_INT_MAX);
            // Put unknown-size formats at the bottom of an ascending (smallest-first) sort.
        } elseif ($sort === 'tbr') {
            $cmp = ($b['tbr'] ?? 0) <=> ($a['tbr'] ?? 0);
        } elseif ($sort === 'quality') {
            $cmp = ($b['quality'] ?? -1) <=> ($a['quality'] ?? -1);
        } else {
            $cmp = ($b['height'] ?? 0) <=> ($a['height'] ?? 0);
        }
        // Secondary: within same type group and quality tier, sort by the most
        // meaningful quality metric for that format type. For video (height > 0)
        // and combined formats, height and fps distinguish quality meaningfully.
        // For audio-only (height = 0), height is always 0 — use tbr (bitrate)
        // as the secondary sort so higher-bitrate audio appears first within
        // the same quality tier. This makes the 'quality' sort useful for
        // audio-heavy playlists where tier alone doesn't distinguish quality.
        if ($cmp === 0) {
            $cmp = ($b['height'] ?? 0) <=> ($a['height'] ?? 0);
        }
        if ($cmp === 0) {
            $cmp = ($b['fps'] ?? 0) <=> ($a['fps'] ?? 0);
        }
        // Tertiary: within same type + height + fps, highest tbr wins.
        // For audio-only formats where height=0 and fps=0, this resolves to
        // sorting by bitrate as the primary differentiator within the quality tier.
        if ($cmp === 0) {
            $cmp = ($b['tbr'] ?? 0) <=> ($a['tbr'] ?? 0);
        }
        return $cmp;
    });

    return [
        'title' => $title,
        'thumbnail' => $thumbnail,
        'url' => $video_url,
        'duration' => $duration,
        'uploader' => $uploader,
        'uploader_url' => $uploader_url,
        'platform' => $platform,
        'derived_filename' => $derived_filename,
        'formats' => $formats,
        'sort_applied' => $sort,
    ];
}

// ─── Structured Request Logging ──────────────────────────────────────────
// Logs request metadata to /var/log/ahoyripper/requests.log for monitoring.
// Uses JSON Lines format (one JSON object per line) for easy grep/jq parsing.
// Requires: /var/log/ahoyripper/ to be created and writable by the web server.
// Falls back to error_log silently if the file is not writable.
function logRequest($action, $status, $extra = []) {
    static $log_dir = '/var/log/ahoyripper';
    static $log_file = '/var/log/ahoyripper/requests.log';
    static $log_init = false;

    // Attempt to create log dir on first call if it doesn't exist
    if (!$log_init) {
        $log_init = true;
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
    }

    // Capture the incoming X-Request-ID from the browser (if any) so server
    // logs can be correlated with the browser's page-view logs. The browser
    // sets PAGE_REQUEST_ID on each page load and sends it as the X-Request-ID
    // request header (available in PHP as HTTP_X_REQUEST_ID).
    $entry = [
        'ts' => date('c'),
        'req_id' => $GLOBALS['__request_id'] ?? '',
        'client_req_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? '',
        'action' => $action,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        // Strip query string from REQUEST_URI to prevent video URL and API key
        // from appearing in logs. The action alone is sufficient for monitoring.
        'uri' => preg_replace('/\?.*/', '', $_SERVER['REQUEST_URI'] ?? ''),
        'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        'status' => $status,
    ];
    if ($extra) {
        foreach ($extra as $k => $v) {
            // Omit sensitive fields from extra
            if (in_array($k, ['api_key', 'key', 'url', 'filename'], true)) continue;
            $entry[$k] = is_string($v) ? substr($v, 0, 200) : $v;
        }
    }

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    if (@file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX) === false) {
        // Fallback to PHP error_log if file write fails
        @error_log("AhoyRipper [$action]: " . json_encode($entry, JSON_UNESCAPED_SLASHES));
    }
}

// ─── Quota refund helper ───────────────────────────────────────────────
// Best-effort refund of one daily-quota increment applied before a failure.
// $ip:                  client IP used to build the quota filename
// $unlimited:           skip refund if true (unlimited-key holders never incremented)
// $daily_limit:         safe default return value when refund cannot be performed
// $pre_increment_count: the daily count BEFORE the request incremented it.
//                        Used to detect whether this request's increment is still
//                        present in the quota file (not yet refunded by a concurrent
//                        request that failed at the same time).
// Returns the post-refund daily count; callers use this to compute quota_remaining.
function refundQuota(string $ip, bool $unlimited, int $daily_limit, int $pre_increment_count): int {
    if ($unlimited) return $daily_limit;
    $undo_fp = fopen('/tmp/ahoyrip_daily_' . md5($ip), 'c+');
    if (!$undo_fp) return $daily_limit;
    if (!flock($undo_fp, LOCK_EX)) {
        fclose($undo_fp);
        return $daily_limit;
    }
    $undo_raw = fread($undo_fp, 4096);
    $undo_data = ['t' => gmdate('Y-m-d'), 'c' => 0];
    if ($undo_raw) {
        $decoded = json_decode($undo_raw, true);
        if ($decoded && is_array($decoded)) $undo_data = $decoded;
    }
    // Only decrement if: same day AND this request's increment is still present.
    // c > $pre_increment_count means the increment from this request is reflected
    // in the stored count (another concurrent request hasn't refunded it yet).
    // This prevents the race where Request B reads c=6 (both requests incremented
    // from 5), Request A's refund decrements to 5, then Request B's refund ALSO
    // decrements to 4 (should be 5 — Request B's increment was undone by Request A).
    if ($undo_data['t'] === gmdate('Y-m-d') && $undo_data['c'] > $pre_increment_count) {
        $undo_data['c']--;
        ftruncate($undo_fp, 0);
        rewind($undo_fp);
        fwrite($undo_fp, json_encode($undo_data));
        fflush($undo_fp);
    }
    flock($undo_fp, LOCK_UN);
    fclose($undo_fp);
    return $undo_data['c'];
}

// ─── Shared validation helper ─────────────────────────────────────────
// DRY helper for URL and format validation. Used by both info and download
// actions to ensure consistent error codes and log messages.
// Keep outside the switch so both case blocks can reference it.
// Sends X-DailyLimit-* headers with the configured daily limit value.
// Called on validation errors that occur BEFORE the quota-check gate so clients
// can always determine the daily-limit configuration from any error response.
// Uses QUOTA_DAILY env var with QUOTA_DAILY_DEFAULT fallback. Does not attempt
// to read the quota file since that would require IP-based tracking that is not
// available at this stage (the quota file is opened only after these early exits).
$sendDailyLimitHeaders = function(int $limit, ?int $remaining) {
    // X-DailyLimit-Remaining: when $remaining is null, quota is unknown at this
    // validation stage (before the quota file is opened). Use -1 as the sentinel,
    // consistent with the 'quota_remaining: -1' JSON body field.
    header('X-DailyLimit-Limit: ' . $limit);
    header('X-DailyLimit-Remaining: ' . ($remaining ?? -1));
    header('X-DailyLimit-Reset: ' . (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp());
    header('X-DailyLimit-Window: 86400');
};

$validation = function(string $action) use($request_id, $sendDailyLimitHeaders) {
    // Determine the daily limit from the environment to include in error
    // responses. This is the configured limit, not the user's remaining quota
    // (quota tracking is not available at this early validation stage).
    $daily_limit = getDailyQuotaLimit();

    $url = trim($_GET['url'] ?? $_POST['url'] ?? '');
    if (!$url) {
        http_response_code(400);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Download-Options: noopen');
        header('X-Robots-Tag: noindex, noai, noimage, noydir');
        header('X-Request-ID: ' . $request_id);
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-RateLimit-Limit: -1');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unavailable');
        // X-DL-RateLimit-* mirrors the X-RateLimit-* sentinels for download-specific
        // monitoring. Both sets use -1 (unavailable) since MISSING_URL occurs before
        // the download action's rate-limit gate and no download is involved.
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unavailable');
        logRequest($action, 400, ['reason' => 'missing_url']);
        $quota_reset_ts = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp();
        $quota_reset_iso = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->format('c');
        $sendDailyLimitHeaders($daily_limit, null);
        header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
        header('X-Info-Timeout: ' . INFO_TIMEOUT);
        echo json_encode([
            'error' => 'No URL was provided. Paste a valid link from YouTube, Twitter, SoundCloud, TikTok, Instagram, etc.',
            'error_code' => 'MISSING_URL',
            'action' => $action,
            // retry_after: 0 signals "retry immediately once input is corrected" — a
            // validation error has no server-side backoff; the client just needs to
            // provide valid input. Consistent with INVALID_URL and INVALID_FORMAT_ID.
            'retry_after' => 0,
            'hint' => 'Pass a supported URL via the "url" query parameter. E.g. ?action=info&url=https://www.youtube.com/watch?v=...',
            'request_id' => $request_id,
            'source_url' => null,
            // 'video_url' mirrors source_url in error responses for consistency with
            // the info response (where video_url holds the resolved page URL).
            // Null here because no URL was provided at all.
            'video_url' => null,
            // 'source_url_missing' is true when the client provided no URL at all,
            // distinguishing MISSING_URL from other null-source_url error cases.
            // API consumers can check this flag for precise error routing without
            // relying on string matching on the error message.
            'source_url_missing' => true,
            // 'format_id_missing' is false — URL validation fires before format validation
            // and the format parameter is not relevant for non-download actions (info, health, etc.).
            // Including this field provides consistent structure across all validation-error responses.
            'format_id_missing' => false,
            'format_id' => null,
            'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
            'api_version' => AHOYRIPPER_VERSION,
            'server_time' => date('c'),
            'server_time_unix' => time(),
            'upgrade_url' => UPGRADE_URL,
            // quota_remaining: -1 signals that quota tracking is not available at this
            // early validation stage (before the quota file is opened). Matches the
            // X-DailyLimit-Remaining: -1 header set by $sendDailyLimitHeaders for the
            // same reason. API consumers should treat -1 as "unknown remaining quota".
            'quota_remaining' => -1,
            'quota_limit' => $daily_limit,
            'quota_reset' => $quota_reset_iso,
            'quota_reset_unix' => $quota_reset_ts,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        return false;
    }
    if (!isValidUrl($url)) {
        http_response_code(400);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Download-Options: noopen');
        header('X-Robots-Tag: noindex, noai, noimage, noydir');
        header('X-Request-ID: ' . $request_id);
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-RateLimit-Limit: -1');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unavailable');
        // X-DL-RateLimit-* mirrors the X-RateLimit-* sentinels for download-specific
        // monitoring. Both sets use -1 (unavailable) since INVALID_URL occurs before
        // the download action's rate-limit gate and no download is involved.
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unavailable');
        logRequest($action, 400, ['reason' => 'invalid_url']);
        $quota_reset_ts = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp();
        $quota_reset_iso = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->format('c');
        $sendDailyLimitHeaders($daily_limit, null);
        header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
        header('X-Info-Timeout: ' . INFO_TIMEOUT);
        echo json_encode([
            'error' => 'Invalid URL. Please paste a valid video link.',
            'error_code' => 'INVALID_URL',
            // retry_after: 0 signals "retry immediately once input is corrected" — a
            // validation error has no server-side backoff; the client just needs to
            // provide valid input. Consistent with MISSING_URL and INVALID_FORMAT_ID.
            'retry_after' => 0,
            'hint' => 'URL must be a public HTTPS link to a supported platform. Use the info action to validate a URL before downloading.',
            'request_id' => $request_id,
            'source_url' => $url,
            'source_url_missing' => false,
            // 'format_id_missing' is false — URL validation fires before format validation
            // and the format parameter is not relevant for non-download actions.
            'format_id_missing' => false,
            'format_id' => null,
            'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
            'api_version' => AHOYRIPPER_VERSION,
            'server_time' => date('c'),
            'server_time_unix' => time(),
            'upgrade_url' => UPGRADE_URL,
            // quota_remaining: -1 signals that quota tracking is not available at this
            // early validation stage (before the quota file is opened). Matches the
            // X-DailyLimit-Remaining: -1 header set by $sendDailyLimitHeaders for the
            // same reason. API consumers should treat -1 as "unknown remaining quota".
            'quota_remaining' => -1,
            'quota_limit' => $daily_limit,
            'quota_reset' => $quota_reset_iso,
            'quota_reset_unix' => $quota_reset_ts,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        return false;
    }
    // Enforce the shared URL length limit so clients get consistent error codes
    // regardless of which action they call. Uses the shared MAX_URL_LEN constant.
    // The download action previously duplicated this check here as a workaround;
    // centralising it in the validation helper ensures both actions are covered.
    if (strlen($url) > MAX_URL_LEN) {
        http_response_code(400);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Download-Options: noopen');
        header('X-Robots-Tag: noindex, noai, noimage, noydir');
        header('X-Request-ID: ' . $request_id);
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        logRequest($action, 400, ['reason' => 'url_too_long', 'url_len' => strlen($url)]);
        // Rate-limit headers: -1 sentinel since this error occurs before the
        // per-minute rate-limit gate (no rate tracking has occurred yet).
        header('X-RateLimit-Limit: -1');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unavailable');
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unavailable');
        $sendDailyLimitHeaders($daily_limit, null);
        header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
        header('X-Info-Timeout: ' . INFO_TIMEOUT);
        echo json_encode([
            'error' => 'URL is too long. Please paste a shorter link.',
            'error_code' => 'INVALID_URL',
            'action' => $action,
            // retry_after: 0 signals "retry immediately once input is corrected" — a
            // validation error has no server-side backoff; the client just needs to
            // provide a shorter URL. Consistent with MISSING_URL and INVALID_URL.
            'retry_after' => 0,
            'hint' => 'URL exceeds the maximum allowed length (' . MAX_URL_LEN . ' chars). Try shortening the URL or removing unnecessary query parameters.',
            'request_id' => $request_id,
            'source_url' => $url,
            'source_url_missing' => false,
            // 'format_id_missing' is false — URL validation fires before format validation.
            'format_id_missing' => false,
            'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
            'api_version' => AHOYRIPPER_VERSION,
            'server_time' => date('c'),
            'server_time_unix' => time(),
            'upgrade_url' => UPGRADE_URL,
            // quota_remaining: -1 signals that quota tracking is not available at this
            // early validation stage (before the quota file is opened). Matches the
            // X-DailyLimit-Remaining: -1 header set by $sendDailyLimitHeaders for the
            // same reason. API consumers should treat -1 as "unknown remaining quota".
            'quota_remaining' => -1,
            'quota_limit' => $daily_limit,
            'quota_reset' => $quota_reset_iso,
            'quota_reset_unix' => $quota_reset_ts,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        return false;
    }
    // Download-only: a format must be selected before downloading.
    // Info action does not require a format parameter.
    // NOTE: $format_id is returned via $validation_result so it is available in
    // the caller's scope. Declaring it inside the closure without returning it
    // would make it unavailable to the download case below (PHP closures do not
    // leak local variables to the outer scope).
    $format_id = null;
    if ($action === 'download') {
        $format_id = trim($_GET['format'] ?? '');
        if ($format_id === '') {
            http_response_code(400);
            logRequest($action, 400, ['reason' => 'missing_format']);
            // Security headers — same set as MISSING_URL / INVALID_URL.
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('X-Request-ID: ' . $request_id);
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            // Rate-limit headers: -1 sentinel since this error occurs before the
            // per-minute rate-limit gate (no rate tracking has occurred yet).
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: unavailable');
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unavailable');
            $sendDailyLimitHeaders($daily_limit, null);
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            // Cache-Control: no-store — prevents all API responses from being cached.
            header('Cache-Control: no-store');
            echo json_encode([
                'error' => 'No format was selected. Call the info action first to see available formats, then pass a format id to the download action.',
                'error_code' => 'MISSING_FORMAT',
                'action' => 'download',
                // retry_after: 0 signals "retry immediately once input is corrected" —
                // validation error has no server-side backoff; the client just needs to
                // provide valid input. Consistent with MISSING_FORMAT and other validation errors.
                'retry_after' => 0,
                'hint' => 'Call the info action first to get available formats, then pass a format id (e.g. "18" or "bestaudio[ext=m4a]") as the "format" parameter to the download action.',
                'request_id' => $request_id,
                'source_url' => $url,
                'source_url_missing' => false,
                'format_id' => null,
                // 'format_id_missing' is true when no format was selected at all —
                // distinguishing MISSING_FORMAT from INVALID_FORMAT_ID (format was
                // provided but failed validation). API consumers can check this flag
                // for precise error routing without relying on string matching.
                'format_id_missing' => true,
                'upgrade_url' => UPGRADE_URL,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                'server_time' => date('c'),
                'server_time_unix' => time(),
                // quota_remaining: -1 signals that quota tracking is not available at this
                // early validation stage (before the quota file is opened). Matches the
                // X-DailyLimit-Remaining: -1 header set by $sendDailyLimitHeaders for the
                // same reason. API consumers should treat -1 as "unknown remaining quota".
                'quota_remaining' => -1,
                'quota_limit' => $daily_limit,
                'quota_reset' => $quota_reset_iso,
                'quota_reset_unix' => $quota_reset_ts,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            return false;
        }
        // Validate format_id character-class — reject shell metacharacters that could
        // survive into proc_open args even with bypass_shell=true (e.g. whitespace
        // tokens, command substitutions, glob patterns). yt-dlp selectors and merge
        // syntax (bestvideo[height>=720]+bestaudio, 18/22, etc.) are alphanumeric
        // plus: _ . , / + - ~ < > = ! [ ] ( ) * % ' "
        // The character class below is intentionally strict: no shell metacharacters
        // including ; ` { } | @ or embedded spaces. The hyphen range \- is escaped
        // properly to avoid creating a range from ASCII 45(-) to 126(~) which would
        // accidentally include ; ` { } | . This mirrors the validation already
        // present in the download action and is checked here so the info action fails
        // fast with INVALID_FORMAT_ID before wasting any yt-dlp cycles.
        //
        // NOTE: this block is inside the if ($action === 'download') guard because
        // format_id is null for info requests. Calling preg_match with a null subject
        // issues a PHP warning and returns false, incorrectly triggering INVALID_FORMAT_ID.
        // NOTE: $ is used in yt-dlp filter expressions (format_id$=_mp4 = "ends with _mp4")
        // and in merge formulas for per-output destination labels, and in some fallback
        // syntax (bv*+ba/b$ where $ means "same format as the first alternative").
        if (!preg_match('/^[a-zA-Z0-9_.,<>=!\\[\\]+\\/\\-~()*%!\'\"\\$]+$/', $format_id)) {
            http_response_code(400);
            logRequest($action, 400, ['reason' => 'invalid_format_id', 'format_id' => $format_id]);
            // Security headers — same set as MISSING_FORMAT / MISSING_URL.
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('X-Request-ID: ' . $request_id);
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            // Rate-limit headers: -1 sentinel since this error occurs before the
            // per-minute rate-limit gate (no rate tracking has occurred yet).
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: unavailable');
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unavailable');
            $sendDailyLimitHeaders($daily_limit, null);
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            echo json_encode([
                'error' => 'That format ID was not recognized. Refresh to get a fresh format list, then pick a valid format from the list.',
                'error_code' => 'INVALID_FORMAT_ID',
                'action' => 'download',
                // retry_after: 0 signals "retry immediately once input is corrected" —
                // validation error has no server-side backoff; the client just needs to
                // provide a valid format_id. Consistent with MISSING_FORMAT and other validation errors.
                'retry_after' => 0,
                'hint' => 'Refresh the page to get a fresh format list, then pick a format id from the returned list. Format ids look like "18", "22", or "bestaudio[ext=m4a]".',
                'request_id' => $request_id,
                'source_url' => $url,
                'source_url_missing' => false,
                'format_id' => $format_id,
                // 'format_id_missing' is false when the client provided a format_id that
                // failed validation — distinguishing INVALID_FORMAT_ID from MISSING_FORMAT.
                // API consumers can check this flag for precise error routing without
                // relying on string matching on the error message.
                'format_id_missing' => false,
                'upgrade_url' => UPGRADE_URL,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                'server_time' => date('c'),
                'server_time_unix' => time(),
                // quota_remaining: -1 signals that quota tracking is not available at this
                // early validation stage (before the quota file is opened). Matches the
                // X-DailyLimit-Remaining: -1 header set by $sendDailyLimitHeaders for the
                // same reason. API consumers should treat -1 as "unknown remaining quota".
                'quota_remaining' => -1,
                'quota_limit' => $daily_limit,
                'quota_reset' => $quota_reset_iso,
                'quota_reset_unix' => $quota_reset_ts,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            return false;
        }
    }
    return [$url, $format_id];
};

// ─── CONSTANTS ──────────────────────────────────────────────
// Unlimited API key — read from environment variable in production.
// The env var takes precedence; falling back to a compile-time default
// only for local development / docker where env is not set.
// Keep the value in a single place to simplify rotation.
define('AHOY_UNLIMITED_KEY', getenv('AHOY_KEY') ?: (getenv('AHOY_UNLIMITED_KEY') ?: 'RIPPER2026DEV'));

// Configurable User-Agent — follows the same env-var pattern as AHOY_UNLIMITED_KEY.
// Override via AHOY_USER_AGENT env var in docker-compose or cloud dashboard.
// Used by all yt-dlp invocations (info, download) so agents stay consistent.
define('AHOY_USER_AGENT', getenv('AHOY_USER_AGENT') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36');
// yt-dlp 2024.09+ impersonation target — spoofs browser TLS/ALPN fingerprints to
// reduce anti-bot 403/422 errors on protected sites (YouTube, Twitter, etc.).
// Defaults to 'chrome' (curl_cffi impersonates Chrome on Linux).
// Override via AHOY_IMPERSONATE env var in docker-compose or cloud dashboard.
// Set to '' (empty string) to disable impersonation entirely. The --user-agent
// flag is still passed alongside --impersonate so both the TLS fingerprint and
// the HTTP User-Agent header match when impersonation is enabled.
// Use ?? (null coalescing) instead of ?: (ternary) so that an explicitly set
// empty-string AHOY_IMPERSONATE disables impersonation and returns 204.
// PHP's getenv() returns false (not '') for an unset var, and ?: treats both
// false and '' as falsy — ?? distinguishes them by only falling back on null (unset).
define('AHOY_IMPERSONATE', getenv('AHOY_IMPERSONATE') ?? 'chrome');

// Path to a Netscape-format cookies.txt file for authenticated requests
// (age-restricted YouTube, Spotify, etc.). Set via COOKIES_PATH env var or
// docker-compose. When absent or empty, no --cookies flag is passed to yt-dlp.
// See README.md "Passing cookies to yt-dlp" for setup instructions.
define('COOKIES_PATH', getenv('COOKIES_PATH') ?: '');

// Shared constant: maximum URL length in characters.
// Both info and download actions enforce this same limit so clients get
// consistent error codes (INVALID_URL) regardless of which action they call.
// Override via MAX_URL_LEN env var in .env or Docker environment.
define('MAX_URL_LEN', max(1, (int)(getenv('MAX_URL_LEN') ?: 2048)));

// Shared constant: maximum filename length in characters for user-supplied filenames.
// Enforced during filename sanitization so that excessively long filenames are truncated
// to a safe length before being passed to yt-dlp. yt-dlp itself also enforces a
// reasonable limit internally, but setting it here gives us a predictable ceiling.
// Override via MAX_FILENAME_LEN env var in .env or Docker environment.
define('MAX_FILENAME_LEN', max(1, (int)(getenv('MAX_FILENAME_LEN') ?: 80)));

// Configurable timeout for the health probe (lightweight yt-dlp metadata fetch).
// Override via HEALTH_PROBE_TIMEOUT env var (e.g. HEALTH_PROBE_TIMEOUT=20 in .env).
// Defaults to 15 seconds. The probe is a simple --dump-json --skip-download call
// on a known-short video (Rick Astley), so 15s is plenty. A shorter timeout keeps
// the /health endpoint responsive under load. The yt-dlp --socket-timeout flag
// is set to half this value so the inner connection timeout fires before the outer
// PHP-side loop timeout, producing a clean CONNECTION_TIMEOUT classification.
// Note: the env var is read raw and tested for empty-string first, because
// getenv() returns false for unset AND empty-string (both are "not set" from a
// shell perspective). max(5, ...) then clamps the result to a minimum of 5s.
$_hp_raw = getenv('HEALTH_PROBE_TIMEOUT');
define('HEALTH_PROBE_TIMEOUT', ($_hp_raw !== false && $_hp_raw !== '') ? max(5, (int)$_hp_raw) : 15);
unset($_hp_raw);

// ─── ROUTING ────────────────────────────────────────────────

// $unlimited is set in the download case below after reading the API key.
// Default to false here so the info-action daily-quota check (which runs
// before the switch) has a safe fallback — it will be overwritten with the
// real value when action=download, which is the only place a key is sent.
$unlimited = false;

// Enforce GET for all API actions — POST is not used or documented.
// Rejecting wrong methods early gives a clear 405 instead of ambiguous behaviour.
// RFC 7231 §6.5.5: a 405 response MUST include an Allow header listing valid
// methods so clients can discover the supported interface without trial-and-error.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    // Security headers: mirror the full set sent by the 406 handler so both early-exit
    // error paths are equally hardened. Both bypass the switch block that would otherwise
    // set them globally, so they must be set explicitly here.
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Download-Options: noopen');
    header('X-Robots-Tag: noindex, noai, noimage, noydir');
    header('X-Request-ID: ' . $request_id);
    // application/json Content-Type was missing — the body is JSON but this header
    // was absent, causing generic API clients and browser DevTools to misrender
    // the response body. Fixed alongside the other headers below.
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    // X-Info-Timeout and X-Download-Timeout: present on all API responses
    // (check, health, client-error) for generic header-parsing consistency.
    // GET-gate 405 was missing these — add them now to mirror POST-gate 405 blocks.
    header('X-Info-Timeout: ' . INFO_TIMEOUT);
    header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
    // CSP and Reporting headers: GET-gate 405 was missing these.
    // Mirrors the headers set in the POST-gate 405 blocks (analytics, client-error, csp-report).
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');
    header('Reporting-Endpoints: csp-report="/csp-report"');
    header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
    // Rate-limit headers on 405: check is not a download action (X-DL-RateLimit=-1)
    // and has no per-minute ceiling (X-RateLimit=-1, not 0). -1 is the sentinel for
    // "no rate limit applies" — 0 means "rate limit exhausted" which is wrong here.
    // Daily limit is also inapplicable (-1). Including these on error responses gives
    // API clients consistent header coverage regardless of which code path they hit.
    header('X-DL-RateLimit-Limit: -1');
    header('X-DL-RateLimit-Remaining: -1');
    header('X-DL-RateLimit-Reset: -1');
    header('X-DL-RateLimit-Window: unlimited');
    header('X-RateLimit-Limit: -1');
    header('X-RateLimit-Remaining: -1');
    header('X-RateLimit-Reset: -1');
    header('X-RateLimit-Window: unlimited');
    header('X-DailyLimit-Limit: -1');
    header('X-DailyLimit-Remaining: -1');
    header('X-DailyLimit-Reset: -1');
    header('X-DailyLimit-Window: unlimited');
    echo json_encode([
        'error' => 'Method not allowed. Use GET.',
        'error_code' => 'METHOD_NOT_ALLOWED',
        'action' => $action,
        'retry_after' => 0,
        'request_id' => $request_id,
        'upgrade_url' => UPGRADE_URL,
        'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
        'api_version' => AHOYRIPPER_VERSION,
        // source_url_missing: false — METHOD_NOT_ALLOWED fires before URL processing
        // (it is a HTTP-method validation failure, not a URL validation failure).
        'source_url_missing' => false,
        // format_id_missing: false — format validation fires after method validation.
        'format_id_missing' => false,
        // source_url: null — METHOD_NOT_ALLOWED fires before URL processing
        // (it is a HTTP-method validation failure, not a URL validation failure).
        'source_url' => null,
        // quota fields: -1 signals that quota tracking is not applicable at this
        // early pre-action validation stage (before any action is dispatched).
        'quota_remaining' => -1,
        'quota_limit' => -1,
        'quota_reset' => -1,
        'quota_reset_unix' => -1,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Verify the Accept header expects JSON — reject non-JSON requests
// to prevent the API from returning HTML/error pages to API clients.
// Allow */* (browsers/clients that accept anything) and application/json variants.
// Accept absent (empty string) is also accepted — curl, bots, and many API clients
// do not send an Accept header; in that case we assume JSON and proceed.
// Download action is exempt — it always returns the file regardless of Accept.
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$json_actions = ['info', 'check', 'health', 'progress'];
if (in_array($action, $json_actions, true) && $accept !== '' && $accept !== '*/*' && !preg_match('/application\/json/i', $accept)) {
    http_response_code(406);
    // Re-set security headers that the top-of-script block already set — this
    // block bypasses the normal switch/case flow and sends its own response via
    // exit, so the top-of-script headers may not have been applied. This mirrors
    // the same pattern used by action=check and action=health for the same reason.
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Download-Options: noopen');
    header('X-Robots-Tag: noindex, noai, noimage, noydir');
    header('X-Request-ID: ' . $request_id);
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    // Consistent with the METHOD_NOT_ALLOWED (405) response: include all
    // rate-limit headers so API clients always get complete header coverage
    // regardless of which early-exit code path they hit.
    header('X-RateLimit-Limit: -1');
    header('X-RateLimit-Remaining: -1');
    header('X-RateLimit-Reset: -1');
    header('X-RateLimit-Window: unlimited');
    // info action is subject to daily quota; others (check, health, progress) are not.
    if ($action === 'info') {
        $dl = getDailyQuotaLimit();
        header('X-DailyLimit-Limit: ' . $dl);
        header('X-DailyLimit-Remaining: ' . $dl);
        header('X-DailyLimit-Reset: ' . (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp());
        header('X-DailyLimit-Window: 86400');
    } else {
        header('X-DailyLimit-Limit: -1');
        header('X-DailyLimit-Remaining: -1');
        header('X-DailyLimit-Reset: -1');
        header('X-DailyLimit-Window: unlimited');
    }
    echo json_encode([
        'error' => 'Not acceptable. API only returns application/json.',
        'error_code' => 'NOT_ACCEPTABLE',
        'action' => $action,
        'retry_after' => 0,
        'request_id' => $request_id,
        'received_accept' => $accept,
        'hint' => 'Send Accept: */* or Accept: application/json',
        'upgrade_url' => UPGRADE_URL,
        'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
        'api_version' => AHOYRIPPER_VERSION,
        // source_url_missing: false — NOT_ACCEPTABLE fires before URL processing
        // (it is an Accept-header validation failure, not a URL validation failure).
        'source_url_missing' => false,
        // format_id_missing: false — format validation fires after Accept validation.
        'format_id_missing' => false,
        // source_url: null — NOT_ACCEPTABLE fires before URL processing
        // (it is an Accept-header validation failure, not a URL validation failure).
        'source_url' => null,
        // quota fields: -1 signals that quota tracking is not applicable at this
        // early pre-action validation stage (before any action is dispatched).
        'quota_remaining' => -1,
        'quota_limit' => -1,
        'quota_reset' => -1,
        'quota_reset_unix' => -1,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ─── Daily quota gate ─────────────────────────────────────────────────
// ─── Daily download quota (free tier limit, skip if unlimited key) ───
switch ($action) {
    case 'info': {
        // Get video info + formats
        $url = trim($_GET['url'] ?? $_POST['url'] ?? '');

        // Validate URL — rejects missing, malformed, private-IP, non-HTTPS, and
        // over-long URLs. Returns [url, format_id] on success, or false
        // on any validation failure (the helper sends its own error response).
        $validation_result = $validation('info');
        if ($validation_result === false) {
            exit;
        }
        [$url, $format_id] = $validation_result;

        // Read and validate sort parameter — must be declared before parseFormats
        // is called. Controls format ordering: height (default), filesize (largest
        // first), filesize_asc (smallest first), tbr, or quality.
        // Invalid values fall back to 'height'.
        $raw_sort = $_GET['sort'] ?? 'height';
        $allowed_sorts = ['height', 'filesize', 'filesize_asc', 'tbr', 'quality', 'audio_quality'];
        $sort = in_array($raw_sort, $allowed_sorts, true) ? $raw_sort : 'height';

        // ─── Check for unlimited API key ───
        // Prefer Authorization: Bearer header (keeps key out of URLs and server logs).
        // Fall back to GET/POST query param only for legacy clients that can't send headers.
        // Omit empty-string Bearer tokens — a misconfigured client sending
        // "Authorization: Bearer " (trailing space, no token) should fall through to key= param.
        $api_key = null;
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)) {
            $bearer_token = trim($m[1]);
            if ($bearer_token !== '') {
                $api_key = $bearer_token;
            }
        }
        if ($api_key === null) {
            $api_key = $_GET['key'] ?? $_POST['key'] ?? null;
        }

        // Reject invalid (non-null, non-matching) keys early so they don't burn
        // a daily quota hit. Null keys and empty-string tokens fall through and
        // are treated as unauthenticated (quota applies normally).
        // Use hash_equals() for timing-safe comparison to prevent timing side-channel
        // attacks. PHP's !== short-circuits on first mismatched character — an
        // attacker's response-time measurements could reveal how many prefix characters
        // of the key are correct.
        if ($api_key !== null && !hash_equals(AHOY_UNLIMITED_KEY, $api_key)) {
            logRequest('info', 401, ['reason' => 'invalid_api_key']);
            http_response_code(401);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: unlimited');
            // X-DL-RateLimit-*: download-specific rate limit (not applicable here, so -1).
            // Matches the same pattern used in the download action's invalid-key block.
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unlimited');
            // X-Info-Timeout: mirrors the header set on all other info-action responses.
            // Clients can use this to set appropriate fetch timeouts on retry.
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            // X-Download-Timeout: present on all API responses for consistent header coverage.
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            // Content-Type: required for correct JSON rendering in browsers and API clients.
            // Missing from the original block — added for consistency with all other API responses.
            header('Content-Type: application/json; charset=utf-8');
            // retry_after: 0 — invalid key is a validation error with no server-side
            // backoff; the client just needs to correct the key. Same as MISSING_FORMAT.
            header('Retry-After: 0');
            // Cache-Control: no-store — prevents all API responses from being cached.
            header('Cache-Control: no-store');
            echo json_encode([
                'error' => 'Invalid API key.',
                'error_code' => 'INVALID_API_KEY',
                'action' => $action,
                'hint' => 'Provide a valid AhoyVPN unlimited API key via the "key" query parameter or the Authorization: Bearer *** header. Generate a key at https://ahoyvpn.com.',
                'retry_after' => 0,
                'request_id' => $request_id,
                'source_url' => $url,
                'source_url_missing' => false,
                'upgrade_url' => UPGRADE_URL,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                // quota fields: invalid key means no quota tracking applies — consistent
                // with the -1 sent for unlimited-key responses.
                'quota_remaining' => -1,
                'quota_limit' => -1,
                'quota_reset' => -1,
                'quota_reset_unix' => -1,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $unlimited = ($api_key !== null && hash_equals(AHOY_UNLIMITED_KEY, $api_key));

        // Send X-DailyLimit: -1 headers for unlimited-key holders BEFORE opening
        // the quota file. This ensures unlimited-key responses always include
        // the -1 signal regardless of whether the quota file is reachable.
        // NOTE: $unlimited is declared at line 2299 as `false` by default — it is
        // set to true here only when a valid key is present.
        if ($unlimited) {
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
            // Return early — unlimited-key holders skip the quota file entirely.
            // NOTE: the yt-dlp info call (line ~1675+) runs AFTER this block,
            // so valid-key holders still get full info responses.
        }

        // ─── Daily download quota (free tier limit, skip if unlimited key) ───
        // Key must be read BEFORE this point so $unlimited is available for the
        // quota gate. The key-reading block is placed immediately below so it
        // runs before any stateful operations (rate limit, quota).
        if (!$unlimited) {
            // Use the same $ip variable declared above for the rate-limit gate.
            // Both info and download actions share the same daily-quota file so
            // that a user hitting 5 info calls has no download quota left.
            $daily_file = '/tmp/ahoyrip_daily_' . md5($ip);
            // Override via QUOTA_DAILY env var (e.g. QUOTA_DAILY=100 in .env).
            // Defaults to QUOTA_DAILY_DEFAULT (5) when the env var is absent. Set to 0
            // or -1 to disable the free tier entirely (unlimited-key required).
            $daily_limit = getDailyQuotaLimit();
            $daily_fp = fopen($daily_file, 'c+');
            if (!$daily_fp) {
                // All security headers — consistent with every other API error response.
                header('Content-Type: application/json; charset=utf-8');
                header('X-Request-ID: ' . $request_id);
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('X-Download-Options: noopen');
                header('X-Robots-Tag: noindex, noai, noimage, noydir');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
                header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
                header('Cross-Origin-Opener-Policy: same-origin');
                header('Cross-Origin-Resource-Policy: same-origin');
                header('Cache-Control: no-store');
                http_response_code(503);
                // Rate-limit and CSP headers — mirrors the download action's quota-gate
                // fopen block. The X-DL-RateLimit-* family uses -1 sentinels because
                // the quota file could not be opened (quota state is unreadable).
                // X-RateLimit-Window: 5 to match the Retry-After delta.
                header('X-DL-RateLimit-Limit: -1');
                header('X-DL-RateLimit-Remaining: -1');
                header('X-DL-RateLimit-Reset: -1');
                header('X-DL-RateLimit-Window: unavailable');
                header('X-RateLimit-Limit: -1');
                header('X-RateLimit-Remaining: -1');
                header('X-RateLimit-Reset: -1');
                header('X-RateLimit-Window: 5');
                header('Reporting-Endpoints: csp-report="/csp-report"');
                header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
                header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'; report-to csp-report;');
                header('Retry-After: 5');
                header('X-Info-Timeout: ' . INFO_TIMEOUT);
                echo json_encode([
                    'error' => 'Service temporarily unavailable.',
                    'error_code' => 'SERVICE_UNAVAILABLE',
                    'action' => $action ?: 'info',
                    'upgrade_url' => UPGRADE_URL,
                    'retry_after' => 5,
                    'request_id' => $request_id,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
                    // source_url: null — SERVICE_UNAVAILABLE fires before URL validation.
                    // source_url_missing: false — no URL was found to be missing.
                    // format_id_missing: false — SERVICE_UNAVAILABLE fires before format validation.
                    'source_url' => null,
                    'source_url_missing' => false,
                    'format_id_missing' => false,
                    // quota fields: unavailable — the quota file could not be opened.
                    // Use -1 sentinels so clients can distinguish this from a known limit.
                    'quota_remaining' => -1,
                    'quota_limit' => $daily_limit,
                    'quota_reset' => -1,
                    'quota_reset_unix' => -1,
                ], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            if (!flock($daily_fp, LOCK_EX)) {
                fclose($daily_fp);
                // All security headers — consistent with every other API error response.
                header('Content-Type: application/json; charset=utf-8');
                header('X-Request-ID: ' . $request_id);
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('X-Download-Options: noopen');
                header('X-Robots-Tag: noindex, noai, noimage, noydir');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
                header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
                header('Cross-Origin-Opener-Policy: same-origin');
                header('Cross-Origin-Resource-Policy: same-origin');
                header('Cache-Control: no-store');
                http_response_code(503);
                // Rate-limit and CSP headers — mirrors the download action's quota-gate
                // flock block. The X-DL-RateLimit-* family uses -1 sentinels because
                // the quota file could not be locked (quota state is unreadable).
                // X-RateLimit-Window: 5 to match the Retry-After delta.
                header('X-DL-RateLimit-Limit: -1');
                header('X-DL-RateLimit-Remaining: -1');
                header('X-DL-RateLimit-Reset: -1');
                header('X-DL-RateLimit-Window: unavailable');
                header('X-RateLimit-Limit: -1');
                header('X-RateLimit-Remaining: -1');
                header('X-RateLimit-Reset: -1');
                header('X-RateLimit-Window: 5');
                header('Reporting-Endpoints: csp-report="/csp-report"');
                header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
                header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'; report-to csp-report;');
                header('Retry-After: 5');
                header('X-Info-Timeout: ' . INFO_TIMEOUT);
                echo json_encode([
                    'error' => 'Service temporarily unavailable.',
                    'error_code' => 'SERVICE_UNAVAILABLE',
                    'action' => $action ?: 'info',
                    'upgrade_url' => UPGRADE_URL,
                    'retry_after' => 5,
                    'request_id' => $request_id,
                    'source_url' => $url ?? null,
                    'source_url_missing' => ($url ?? '') === '',
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
                    // quota fields: unavailable — the quota file could not be locked.
                    // Use -1 sentinels so clients can distinguish this from a known limit.
                    'quota_remaining' => -1,
                    'quota_limit' => $daily_limit,
                    'quota_reset' => -1,
                    'quota_reset_unix' => -1,
                ], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $daily_data = ['t' => gmdate('Y-m-d'), 'c' => 0];
            $daily_raw = fread($daily_fp, 4096);
            if ($daily_raw) {
                $decoded = json_decode($daily_raw, true);
                if ($decoded && is_array($decoded)) {
                    $daily_data = $decoded;
                }
            }
            $today = gmdate('Y-m-d');
            if ($daily_data['t'] !== $today) {
                $daily_data = ['t' => $today, 'c' => 0];
                // Day rolled over — explicitly truncate the file before writing
                // so any stale bytes from the prior day's larger record cannot
                // persist and be misinterpreted as a higher count on the next read.
                ftruncate($daily_fp, 0);
                rewind($daily_fp);
            }
            if ($daily_data['c'] >= $daily_limit) {
                flock($daily_fp, LOCK_UN);
                fclose($daily_fp);
                logRequest('info', 429, ['reason' => 'daily_limit_exceeded']);
                http_response_code(429);
                $reset_timestamp = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp();
                $quota_reset_iso = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->format('c');
                header('Retry-After: ' . max(0, $reset_timestamp - time()));
                // Cache-Control: no-store — daily-limit responses must not be cached by
                // intermediaries. Mirrors the header set by all other API error responses.
                header('Cache-Control: no-store');
                // Security headers — mirrors the same set sent by the per-minute
                // rate-limit 429 block so daily-quota 429 responses are equally
                // hardened regardless of which limit was hit first.
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('X-Download-Options: noopen');
                header('X-Robots-Tag: noindex, noai, noimage, noydir');
                header('X-Request-ID: ' . $request_id);
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
                header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
                header('Cross-Origin-Opener-Policy: same-origin');
                header('Cross-Origin-Resource-Policy: same-origin');
                header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
                header('X-Info-Timeout: ' . INFO_TIMEOUT);
                // X-RateLimit-*: -1 sentinels since the per-minute rate-limit gate was
                // not yet passed when this daily-quota check fires (for info action).
                header('X-RateLimit-Limit: -1');
                header('X-RateLimit-Remaining: -1');
                header('X-RateLimit-Reset: -1');
                header('X-RateLimit-Window: unlimited');
                // X-DL-RateLimit-*: -1 sentinels — dl_rate_file is opened later in the
                // download action (line 3185), so not yet available here for the info action.
                header('X-DL-RateLimit-Limit: -1');
                header('X-DL-RateLimit-Remaining: -1');
                header('X-DL-RateLimit-Reset: -1');
                header('X-DL-RateLimit-Window: unlimited');
                header('X-DailyLimit-Limit: ' . $daily_limit);
                header('X-DailyLimit-Remaining: 0');
                header('X-DailyLimit-Reset: ' . $reset_timestamp);
                header('X-DailyLimit-Window: 86400');
                echo json_encode([
                    'error' => $daily_limit > 0
                        ? "Daily limit reached. You get {$daily_limit} free rips per day. For unlimited access, visit " . UPGRADE_URL
                        : "Daily limit reached. This server does not offer a free tier. For unlimited access, visit " . UPGRADE_URL,
                    'error_code' => 'DAILY_LIMIT',
                    'action' => 'info',
                    'source_url' => $url,
                    'source_url_missing' => false,
                    'format_id_missing' => false,
                    'upgrade_url' => UPGRADE_URL,
                    'platform' => null,
                    'retry_after' => max(0, (int)($reset_timestamp - time())),
                    'hint' => 'Get an AhoyVPN unlimited API key to bypass the daily limit, or wait until ' . $quota_reset_iso . ' UTC.',
                    'quota_remaining' => 0,
                    'quota_limit' => $daily_limit,
                    'quota_reset' => $quota_reset_iso,
                    'quota_reset_unix' => (int)$reset_timestamp,
                    'request_id' => $request_id,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
                ], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $daily_data['c']++;
            ftruncate($daily_fp, 0);
            rewind($daily_fp);
            fwrite($daily_fp, json_encode($daily_data));
            fflush($daily_fp);
            flock($daily_fp, LOCK_UN);
            fclose($daily_fp);  // explicitly close to release lock without waiting for GC
            $daily_fp = null;
            // Refund guard baseline: captured AFTER the increment is persisted so
            // the classified-error refund block can detect whether the quota file
            // was modified by another request since this increment (prevents
            // double-refund when concurrent requests hit different error paths).
            // This is the count AFTER increment — refundQuota's c > baseline guard
            // will only decrement if the stored count is still above this value,
            // meaning this request's increment hasn't been refunded by a concurrent req.
            $info_quota_before_refund = $daily_data['c'];

            // Surface daily quota state so the client can display remaining rips.
            // Show how many rips remain AFTER this request: limit minus the new count.
            // c=1 (after 1st rip) means 1 rip has been consumed; remaining = limit - c = 5.
            // c=5 (after 5th rip) means 5 rips consumed; remaining = 1 — the last rip.
            // The X-DailyLimit-Remaining header must match quota_remaining in the JSON body.
            header('X-DailyLimit-Limit: ' . $daily_limit);
            header('X-DailyLimit-Remaining: ' . max(0, $daily_limit - $daily_data['c']));
            header('X-DailyLimit-Reset: ' . (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp());
            header('X-DailyLimit-Window: 86400');
        }

        // Per-minute rate-limit headers — info is not a download action, so
        // X-DL-RateLimit uses -1 (not applicable). X-RateLimit reflects the
        // info endpoint's configured per-minute ceiling (30 req/min), giving
        // clients full header parity with download/health/check responses.
        // $data['c'] was incremented above; $fp is still locked so these values
        // are consistent with the write that happens after this block.
        $info_rate_remaining = max(0, $rate_limit - $data['c']);
        $info_rate_reset = $data['t'] + $rate_window;
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unlimited');
        header('X-RateLimit-Limit: ' . $rate_limit);
        header('X-RateLimit-Remaining: ' . $info_rate_remaining);
        header('X-RateLimit-Reset: ' . $info_rate_reset);
        header('X-RateLimit-Window: ' . $rate_window);

        // URL is already validated by isValidUrl() and the length-check above.
        // No shell metacharacters possible when passed as a direct array element
        // to proc_open (no shell involved). $MAX_URL_LEN is declared at the top
        // of this action and shared between the length check and yt-dlp call.
        // Pass URL as a direct array element (no shell involvement) so URLs
        // containing whitespace or special characters in query params are
        // handled correctly. With bypass_shell=true, proc_open parses the
        // array into argv without a shell, so no shell escaping is needed.
        // Set a realistic browser User-Agent so yt-dlp's requests are not blocked
        // by anti-bot measures that detect the default python-requests User-Agent.
        // yt-dlp defaults to "python-requests/X.Y.Z" which is trivially blocked.
        // --concurrent-fragments N was removed in yt-dlp 2024.10 (deprecated since 2023.11).
        // yt-dlp now handles HLS/DASH fragment concurrency internally; passing the flag
        // produces a stderr warning that can pollute the JSON output in the info action
        // and corrupt error classification. Removed from both info and download commands.
        // --socket-timeout:yt-dlp's per-connection timeout. Set to INFO_TIMEOUT - 5s so
        // PHP's process-level timeout (INFO_TIMEOUT) is always the outer limit and has time
        // to cleanly terminate the process and emit a classified SOURCE_TIMEOUT error.
        // Without this, yt-dlp uses its own default (~20s) which can fire before PHP's
        // timeout and produce an unclassified CONNECTION_FAILED instead of SOURCE_TIMEOUT.
        // --no-playlist / --yes-playlist: mirrors the download action — pass the user's
        // explicit playlist preference so the info action behaves consistently with download.
        // When playlist=1, --yes-playlist fetches info for all videos in a playlist.
        // When playlist=0/absent, --no-playlist fetches info for the single video.
        // NOTE: yt-dlp does NOT support --playlist true/false (yt-dlp rejects --playlist
        // with any value as ambiguous). The correct boolean flags are --yes-playlist and
        // --no-playlist. The --playlist=true/false syntax was mistakenly documented in
        // yt-dlp 2024.02.07 release notes but was never actually implemented.
        // Delegated to resolvePlaylistFlag() — a pure helper that returns ['--yes-playlist']
        // or ['--no-playlist'] to avoid duplicating the resolution logic inline.
        $playlist_flags = resolvePlaylistFlag($_GET['playlist'] ?? null);
        // yt-dlp per-connection timeout: PHP-side INFO_TIMEOUT is the outer limit,
        // yt-dlp's --socket-timeout is the inner limit. Set to INFO_TIMEOUT - 5s so
        // PHP always fires first and classifies as SOURCE_TIMEOUT rather than CONNECTION_FAILED.
        $socket_timeout = max(1, INFO_TIMEOUT - 5);
        // resolvePlaylistFlag() returns ['--yes-playlist'] or ['--no-playlist'].
        // --no-playlist is the safe default (single video); --yes-playlist is
        // added only when playlist=1 is explicitly requested.
        // NOTE: playlist flags must appear BEFORE the URL (the positional argument).
        // The foreach comes first so that $ytdlp_cmd starts with the binary + flags,
        // then array_merge appends the network/header flags, then URL goes last.
        $ytdlp_cmd = [
            YTDLP_PATH,
            '--dump-json',
            '--skip-download',
            // --no-playlist / --yes-playlist: prevent accidental playlist fetching when
            // the user pastes a playlist URL intending only the single video. resolvePlaylistFlag()
            // returns exactly one flag: --yes-playlist (when playlist=1) or --no-playlist
            // (all other cases). Mirrors the download action pattern.
        ];
        foreach ($playlist_flags as $flag) {
            $ytdlp_cmd[] = $flag;
        }
        $ytdlp_cmd = array_merge($ytdlp_cmd, [
            // --no-progress: suppress all progress output. yt-dlp emits progress
            // template noise even during --skip-download which would prepend garbage
            // to stderr and corrupt json_decode on stdout. --no-progress is the
            // correct modern flag (consistent with the health probe at line 5942).
            '--no-progress',
            '--socket-timeout', (string)$socket_timeout,
            '--retries', '3',
            // --extractor-retries: yt-dlp retries known extractor errors (rate limits,
            // temporary 5xx, etc.) separately from generic --retries. Useful for
            // recovering from transient source-platform errors without escalating to
            // the generic retry budget. Default is 3 when omitted; set explicitly
            // so the behavior is intentional and documented.
            '--extractor-retries', '3',
            // yt-dlp sends the URL itself as referer by default. Allow per-request override
            // via ?referer= URL param (same pattern used by the download action at line 3801).
            // A platform-specific referer (e.g. youtube.com) can improve extraction success
            // for platforms that validate the referer header.
            '--referer', isset($_GET['referer']) && $_GET['referer'] !== ''
                ? $_GET['referer']
                : 'https://ahoyripper.com/',
            '--user-agent', AHOY_USER_AGENT,
        ]);
        // Add --impersonate to spoof browser TLS/ALPN fingerprints (yt-dlp 2024.09+).
        // Dramatically reduces 403/422 bot-detection errors on protected sites.
        if (AHOY_IMPERSONATE !== '') {
            $ytdlp_cmd[] = '--impersonate';
            $ytdlp_cmd[] = AHOY_IMPERSONATE;
        }
        // Add --cookies if COOKIES_PATH is configured (enables authenticated ripping
        // for age-restricted YouTube, Spotify, etc.). See README.md cookie instructions.
        if (COOKIES_PATH !== '') {
            $ytdlp_cmd[] = '--cookies';
            $ytdlp_cmd[] = COOKIES_PATH;
        }
        $ytdlp_cmd = array_merge($ytdlp_cmd, [
            '--add-header', 'Accept-Language: ' . preg_replace('/[^\x20-\x7E;,=]/', '', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en-US;q=0.9,*;q=0.5'),
            '--',
            $url,
        ]);
        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = null;
        $proc = proc_open($ytdlp_cmd, $desc, $pipes, '/tmp', [], ['bypass_shell' => true]);
        if (!$proc) {
            // pipes[0] (stdin) may be partially open — clean up all three pipes
            if ($pipes !== null) {
                foreach ($pipes as $p) { if ($p !== null && is_resource($p)) fclose($p); }
                $pipes = null;
            }
            // proc_open failed — the process could not be started at all.
            // This is a server-side error (binary missing, permissions, resource exhaustion),
            // distinct from yt-dlp running but failing — return 500, not 422.
            // Refund quota: unlimited-key holders skip increment; for free users who already
            // had their count bumped before this point, undo it before responding.
            // $post_refund_count tracks the quota count AFTER the refund is applied.
            // Initialised to $daily_limit as a safe default (no refund on failure).
            $post_refund_count = $daily_limit;
            if (!$unlimited) {
                $post_refund_count = refundQuota($ip, $unlimited, $daily_limit, $info_quota_before_refund);
            }
            logRequest('info', 500, ['reason' => 'proc_open_failed']);
            http_response_code(500);
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            header('Reporting-Endpoints: csp-report="/csp-report"');
            header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
            header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; report-to csp-report;');
            header('Cache-Control: no-store');
            header('X-Request-ID: ' . $request_id);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            // Rate-limit sentinels (-1): no rate limit was consumed because proc_open
            // itself failed before yt-dlp could run. Consistent with other pre-gate errors.
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: unavailable');
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unavailable');
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unavailable');
            // retry_after: delta-seconds until the download can be retried.
            // Per RFC 9110, Retry-After accepts either an HTTP-date or delta-seconds;
            // delta-seconds is simpler and consistent with all other Retry-After
            // headers in this file. Using INFO_TIMEOUT (not time() + INFO_TIMEOUT)
            // ensures this stays consistent with the delta-seconds format.
            $retry_delta = INFO_TIMEOUT;
            header('Retry-After: ' . max(0, $retry_delta));
            echo json_encode([
                'error' => 'Failed to start info process.',
                'error_code' => 'PROC_OPEN_FAILED',
                'action' => 'info',
                'retry_after' => max(0, $retry_delta),
                'hint' => 'The server is overloaded or the download tool is unavailable. Wait a moment and retry. If persistent, the server may need maintenance.',
                'request_id' => $request_id,
                'source_url' => $url,
                'source_url_missing' => false,
                'upgrade_url' => UPGRADE_URL,
                'platform' => null,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                // quota fields: quota was refunded before this response.
                // Unlimited-key holders ($unlimited=true) were never incremented, so
                // $post_refund_count is $daily_limit for them (no change from baseline).
                // Use ternary to return -1 for unlimited-key holders, matching the
                // pattern used by every other info-action error response.
                // $post_refund_count is the post-refund daily count returned by
                // refundQuota() — it IS the remaining quota, not an offset from the limit.
                'quota_remaining' => !$unlimited ? $post_refund_count : -1,
                'quota_limit' => !$unlimited ? $daily_limit : -1,
                'quota_reset' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                'quota_reset_unix' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        } else {
            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_timeout($pipes[1], 0);  // Infinite — global (hrtime(true) - $start) / 1e9 > INFO_TIMEOUT is authoritative
            stream_set_timeout($pipes[2], 0);  // Timeout fires only when child process stalls; feof() stays false until proc closes pipe
            $out = $err = '';
            $start = hrtime(true);
            while (!feof($pipes[1]) || !feof($pipes[2])) {
                if ((hrtime(true) - $start) / 1e9 > INFO_TIMEOUT) {
                    proc_terminate($proc, 9);
                    $err .= "\nProcess timed out after " . INFO_TIMEOUT . "s";
                    $exit = -1;
                    // Close and null individual pipe elements — the download action
                    // uses the same pattern. This allows the while-loop condition
                    // (!feof($pipes[1]) || !feof($pipes[2])) to evaluate to
                    // false after the break, cleanly exiting the loop rather than
                    // continuing to the stream_select call with a null $pipes array.
                    foreach ($pipes as $i => $p) { if ($p) { fclose($p); $pipes[$i] = null; } }
                    $proc = null;  // sentinel: prevents double proc_close() below
                    $out = '';
                    break;
                }
                $read = [];
                if (!feof($pipes[1])) $read[] = $pipes[1];
                if (!feof($pipes[2])) $read[] = $pipes[2];
                if (empty($read)) break;
                $w = $e = null;
                $changed = @stream_select($read, $w, $e, 1, 0);
                if ($changed === false || $changed === 0) { usleep(100000); continue; }
                foreach ($read as $p) {
                    if ($p === $pipes[1]) {
                        $s = @fread($p, 8192);
                        if ($s === false || $s === '') { if (feof($pipes[1])) { fclose($pipes[1]); $pipes[1] = null; } continue; }
                        $out .= $s;
                    } elseif ($p === $pipes[2]) {
                        $s = @fread($p, 8192);
                        if ($s === false || $s === '') { if (feof($pipes[2])) { fclose($pipes[2]); $pipes[2] = null; } continue; }
                        $err .= $s;
                    }
                }
                if ($pipes[1] === null && $pipes[2] === null) break;
            }
            // Only call proc_close if $proc is still open (null sentinel means timeout
            // handler already closed it to avoid double-close).
            if ($proc !== null) {
                foreach ($pipes as $p) { if ($p) fclose($p); }
                $pipes = null;
                $exit = proc_close($proc);
            }
        }

        if ($exit !== 0 || !$out) {
            // The fetch failed — undo the quota increment so failed attempts don't
            // burn the user's daily limit. Only count successful info retrievals.
            if (!$unlimited) {
                refundQuota($ip, $unlimited, $daily_limit, $info_quota_before_refund);
            }

            // Extract a clean, readable error from yt-dlp output
            // Strip HTML tags and control chars; truncate to a useful length
            $raw_err = trim($err ?: $out);
            $err_msg = preg_replace('/[\x00-\x1F\x7F]/', '', $raw_err); // remove control chars
            $err_msg = strip_tags($err_msg); // remove any HTML markup
            $err_msg = preg_replace('/\s+/', ' ', $err_msg); // collapse whitespace
            if (mb_strlen($err_msg, 'UTF-8') > 200) $err_msg = mb_substr($err_msg, 0, 200, 'UTF-8') . '...';
            $ytdlp_ver = $GLOBALS['__ytdlp_version'];
            $version_info = $ytdlp_ver ? " (yt-dlp $ytdlp_ver)" : '';
            logRequest('info', 422, ['reason' => 'ytdlp_fetch_failed', 'exit' => $exit, 'err_preview' => mb_substr($err_msg, 0, 100, 'UTF-8')]);
            http_response_code(422);
            // retry_after: delta-seconds until the info request can be retried.
            // Use INFO_TIMEOUT as a fixed window so the client has a consistent
            // countdown value regardless of when the response is processed.
            $retry_delta = INFO_TIMEOUT;
            header('Retry-After: ' . max(0, $retry_delta));
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            $resp = [
                'error' => "Could not fetch that URL. $err_msg$version_info",
                'error_code' => 'YTDLP_ERROR',
                'action' => 'info',
                'request_id' => $request_id,
                'source_url' => $url,
                'source_url_missing' => false,
                'upgrade_url' => UPGRADE_URL,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                'retry_after' => max(0, $retry_delta),
                // quota fields: consistent with success and classified-error responses.
                // Quota was incremented before this error path (line 2614); the refund
                // above reversed it, so show the pre-increment count.
                'quota_remaining' => !$unlimited ? max(0, $daily_limit - $daily_data['c']) : -1,
                'quota_limit' => !$unlimited ? $daily_limit : -1,
                'quota_reset' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                'quota_reset_unix' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
            ];
            if ($raw_err) {
                $resp['raw_error'] = $raw_err;
            }
            echo json_encode($resp, JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $parsed = parseFormats($out, $raw_err, $sort, $exit);
        if (!$parsed) {
            // Undo the quota increment — parseFormats returned null means the content
            // could not be parsed; we don't burn the user's daily limit for this.
            if (!$unlimited) {
                refundQuota($ip, $unlimited, $daily_limit, $info_quota_before_refund);
            }
            $err_status = 422;
            logRequest('info', $err_status, ['reason' => 'parse_formats_failed', 'exit' => $exit]);
            http_response_code($err_status);
            // retry_after: delta-seconds until the info request can be retried.
            // Use INFO_TIMEOUT as a fixed window so the client has a consistent
            // countdown value regardless of when the response is processed.
            $retry_delta = INFO_TIMEOUT;
            header('Retry-After: ' . max(0, $retry_delta));
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            $resp = [
                'error' => 'Could not parse video info. The site may not be supported or returned a non-standard response.',
                'error_code' => 'PARSE_ERROR',
                'action' => 'info',
                'request_id' => $request_id,
                'source_url' => $url,
                'source_url_missing' => false,
                'format_id_missing' => false,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                'upgrade_url' => UPGRADE_URL,
                'retry_after' => max(0, $retry_delta),
                // quota fields: consistent with success and classified-error responses.
                // Quota was incremented before this error path; the refund above reversed it.
                'quota_remaining' => !$unlimited ? max(0, $daily_limit - $daily_data['c']) : -1,
                'quota_limit' => !$unlimited ? $daily_limit : -1,
                'quota_reset' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                'quota_reset_unix' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
            ];
            // Surface yt-dlp's raw stderr so the user sees the actual reason
            if ($raw_err) {
                $resp['raw_error'] = $raw_err;
            }
            echo json_encode($resp, JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (isset($parsed['error'])) {
            // parseFormats surfaced a yt-dlp error message — pass it through with
            // the HTTP status appropriate to the error category.
            $err_code = $parsed['error_code'] ?? 'PARSE_ERROR';
            // Map error codes to HTTP status for proper client signaling.
            // Default to 422 if code is unknown.
            $err_status_map = [
                'AGE_RESTRICTED' => 403,
                'CONFIG_ERROR' => 503,
                'CONNECTION_FAILED' => 502,
                'CONNECTION_TIMEOUT' => 504,
                'COPYRIGHT_REMOVED' => 451,
                'DAILY_LIMIT' => 429,
                'DISALLOWED_CONTENT' => 451,
                'DOWNLOAD_CANCELLED' => 499,
                'DOWNLOAD_EMPTY' => 500,
                'DOWNLOAD_TIMEOUT' => 504,
                'FILE_READ_ERROR' => 500,
                'FILE_TOO_LARGE' => 413,
                'FORBIDDEN_ORIGIN' => 403,
                'FORMAT_UNAVAILABLE' => 422,
                'GEOBLOCKED' => 451,
                'INVALID_FORMAT_ID' => 400,
                'INVALID_API_KEY' => 401,
                'INVALID_KEY' => 401,
                'INVALID_URL' => 400,
                'LOGIN_REQUIRED' => 401,
                'MISSING_FORMAT' => 400,
                'MISSING_URL' => 400,
                'PARSE_ERROR' => 422,
                'PLAYLIST_MISSING' => 404,
                'PRIVATE_VIDEO' => 403,
                'PROC_OPEN_FAILED' => 500,
                'RATE_LIMIT_EXCEEDED' => 429,
                'SOURCE_FORBIDDEN' => 403,
                'SOURCE_HTTP_ERROR' => 502,
                'SOURCE_NOT_FOUND' => 404,
                'SOURCE_RATE_LIMITED' => 429,
                'SOURCE_TIMEOUT' => 504,
                'SSL_ERROR' => 502,
                'UNSUPPORTED_SITE' => 404,
                'PROBE_FAILED' => 503,
                'VERIFICATION_FAILED' => 500,
                'VERIFICATION_TIMEOUT' => 504,
                'VIDEO_UNAVAILABLE' => 410,
                'YTDLP_ERROR' => 422,
                // UNKNOWN_ACTION uses 404 (set directly by http_response_code in the
                // default: block, which bypasses this map). The 404 is intentional:
                // the action name is an unrecognized endpoint — "Not Found" fits better
                // than "Bad Request" (malformed syntax). Kept here so the map fully
                // documents all error codes, even if this entry is reached only
                // if a future refactor routes unknown actions through the info path.
                'UNKNOWN_ACTION' => 404,
                // HTTP 451: Unavailable For Legal Reasons — specifically for content
                // blocked by legal demand (TOS violations, court orders, etc.).
                // Distinct from SOURCE_FORBIDDEN (403) which is an access-control failure.
            ];
            $err_status = $err_status_map[$parsed['error_code']] ?? 422;
            logRequest('info', $err_status, ['reason' => 'parse_formats_ytdlp_error', 'err_code' => $err_code]);
            // Undo the quota increment — parseFormats succeeded (returned a classified error
            // like GEOBLOCKED/PRIVATE_VIDEO) but the content is not downloadable. We don't
            // burn the user's daily limit for content that simply can't be ripped.
            // Refund guard: if parseFormats returned a classified error (GEOBLOCKED,
            // PRIVATE_VIDEO, etc.), the user burned a quota hit but got no usable
            // content. Undo the increment so it doesn't count against their daily cap.
            // Uses the same c > baseline guard as the download action to prevent
            // double-refund when concurrent requests hit different error paths.
            if (!$unlimited) {
                refundQuota($ip, $unlimited, $daily_limit, $info_quota_before_refund);
            }
            http_response_code($err_status);
            // retry_after: delta-seconds until the info request can be retried.
            // Use INFO_TIMEOUT as a fixed window so the client has a consistent
            // countdown value regardless of when the response is processed.
            $retry_delta = INFO_TIMEOUT;
            header('Retry-After: ' . max(0, $retry_delta));
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            $resp = [
                'error' => $parsed['error'],
                'error_code' => $parsed['error_code'] ?? 'YTDLP_ERROR',
                'action' => 'info',
                'request_id' => $request_id,
                'source_url' => $url,
                'source_url_missing' => false,
                // format_id_missing: info action errors (parseFormats classification) do not
                // involve format validation — the info action only reads metadata. The format
                // parameter is not relevant here. Set to false to match the pattern used by
                // other info-action error responses (e.g. MISSING_URL).
                'format_id_missing' => false,
                'format_id' => null,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                'upgrade_url' => UPGRADE_URL,
                'retry_after' => max(0, $retry_delta),
                // quota fields: consistent with success and other error responses.
                // Quota was incremented before this error path; the refund above reversed it.
                // post-refund count is the pre-increment baseline since the increment was undone.
                'quota_remaining' => !$unlimited ? max(0, $daily_limit - $info_quota_before_refund) : -1,
                'quota_limit' => !$unlimited ? $daily_limit : -1,
                'quota_reset' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                'quota_reset_unix' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
            ];
            // Surface the raw yt-dlp output so the client can show diagnostic info
            if ($raw_err) {
                $resp['raw_error'] = $raw_err;
            }
            echo json_encode($resp, JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        // Strip the "yt-dlp error: " prefix from the error message returned by
        // parseFormats for unclassified yt-dlp errors — the "yt-dlp error:" prefix
        // is an implementation detail that makes the backend log more descriptive, but
        // is not user-facing text. The frontend's ERROR_HINTS[YTDLP_ERROR] already
        // provides a clean, human-readable message, and raw_error surfaces the raw
        // yt-dlp output separately.
        if (isset($parsed['error_code']) && $parsed['error_code'] === 'YTDLP_ERROR' && isset($parsed['error'])) {
            $parsed['error'] = preg_replace('/^yt-dlp error: /i', '', $parsed['error']);
        }

        $parsed['request_id'] = $request_id;
        $parsed['source_url'] = $url;
        $parsed['yt_dlp_version'] = $GLOBALS['__ytdlp_version'] ?? null;
        // api_version was previously missing from the info response but present on
        // check and health endpoints — add it for consistent API surface metadata.
        $parsed['api_version'] = AHOYRIPPER_VERSION;
        // upgrade_url: AhoyVPN upsell URL included on all API responses (success and error).
        // Error responses already include it via individual error blocks. Add it here for
        // the info success path so the upsell opportunity is always surfaced regardless
        // of response type — consistent with the check and health action patterns.
        $parsed['upgrade_url'] = UPGRADE_URL;
        // Surface daily quota state in the JSON body for client UI — mirrors the
        // X-DailyLimit-* headers set above so clients can read quota from either.
        // -1 sentinel values signal "not applicable" (unlimited-key holder).
        // $daily_data['c'] is the count AFTER incrementQuota was called. Since each
        // successful request consumes one quota slot, remaining = limit - c.
        $parsed['quota_remaining'] = !$unlimited ? max(0, $daily_limit - $daily_data['c']) : -1;
        $parsed['quota_limit'] = $unlimited ? -1 : $daily_limit;
        $parsed['quota_reset'] = $unlimited ? -1 : (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp();
        $parsed['quota_reset_unix'] = $unlimited ? -1 : (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp();
        // X-Info-Timeout: server-side info timeout in seconds. Clients should set their
        // fetch timeout to at least this value so the client deadline never exceeds the
        // server deadline. Present on every info response — success and error — so clients
        // can always read it for retry timeout guidance.
        // X-Download-Timeout: also present for consistency — the info response does not
        // return a downloadable resource, but having both timeout headers available is
        // harmless and helps clients that use a single header-parsing path for all responses.
        header('X-Info-Timeout: ' . INFO_TIMEOUT);
        header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
        header('Cache-Control: no-store');
        echo json_encode($parsed, JSON_INVALID_UTF8_SUBSTITUTE);
        logRequest('info', 200, ['platform' => $platform, 'url_type' => 'single', 'format_count' => count($parsed['formats'] ?? [])]);
        break;
    }

    case 'download': {
        // ─── Validate required params first (before rate limiting or any I/O) ───
        // Rejecting early avoids burning rate-limit slots or opening temp files on bad input.
        // The shared $validation helper is defined before the switch and handles
        // URL validation (missing, invalid) for both info and download actions.
        // The format parameter check is only enforced for download (checked inside helper).
        // Validate URL — rejects missing, malformed, private-IP, non-HTTPS, and
        // over-long URLs. Returns [url, format_id] on success, or false
        // on any validation failure (the helper sends its own error response).
        $validation_result = $validation('download');
        if ($validation_result === false) {
            exit;
        }
        [$url, $format_id] = $validation_result;

// ─── Check for unlimited API key ───
        // Prefer Authorization: Bearer *** (keeps key out of URLs and server logs).
        // Fall back to GET/POST query param only for legacy clients that can't send headers.
        // Omit empty-string Bearer tokens — a misconfigured client sending
        // Authorization: Bearer header — trim whitespace from captured token.
        // An empty token ("Authorization: Bearer " with trailing space but no value)
        // means a misconfigured client; skip it and fall through to key= param.
        $api_key = null;
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)) {
            $bearer_token = trim($m[1]);
            if ($bearer_token !== '') {
                $api_key = $bearer_token;
            }
        }
        if ($api_key === null) {
            $api_key = $_GET['key'] ?? $_POST['key'] ?? null;
        }

        // Reject invalid (non-null, non-matching) keys early so they don't burn
        // a daily quota hit. Null keys and empty-string tokens fall through and
        // are treated as unauthenticated (quota applies normally).
        // Use hash_equals() for timing-safe comparison to prevent timing side-channel
        // attacks. PHP's !== short-circuits on first mismatched character — an
        // attacker's response-time measurements could reveal how many prefix characters
        // of the key are correct.
        if ($api_key !== null && !hash_equals(AHOY_UNLIMITED_KEY, $api_key)) {
            logRequest('download', 401, ['reason' => 'invalid_api_key']);
            http_response_code(401);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
            // Download action rate-limit sentinels — download action has not yet
            // opened its rate file at this point, so use -1 (not applicable) to match
            // the pattern used by other early-exit blocks (405, 406) for consistency.
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unavailable');
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: unavailable');
            // X-Info-Timeout: mirrors the header set on all other info-action responses.
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            // X-Download-Timeout: mirrors the header set on all other download-action responses.
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            echo json_encode([
                'error' => 'Invalid API key.',
                'error_code' => 'INVALID_API_KEY',
                'action' => 'download',
                'hint' => 'Provide a valid AhoyVPN unlimited API key via the "key" query parameter or the Authorization: Bearer *** header. Generate a key at https://ahoyvpn.com.',
                'retry_after' => 0,
                'request_id' => $request_id,
                'source_url' => $url,
                'source_url_missing' => false,
                'upgrade_url' => UPGRADE_URL,
                'platform' => null,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                // quota fields: invalid key means no quota tracking applies — consistent
                // with the -1 sent for unlimited-key responses.
                'quota_remaining' => -1,
                'quota_limit' => -1,
                'quota_reset' => -1,
                'quota_reset_unix' => -1,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $unlimited = ($api_key !== null && hash_equals(AHOY_UNLIMITED_KEY, $api_key));

        // ─── Download rate limiting (atomic via flock) ───
        $dl_rate_limit = DL_RATE_LIMIT; // download requests per minute
        $dl_rate_window = 60;
        // Separate file from the request rate limiter to prevent the download
        // action's write (which runs after the request gate check) from wiping
        // the request gate's counter and causing spurious rate-limit hits.
        $dl_rate_file = '/tmp/ahoyrip_dl_rate_' . md5($ip);

        $dl_fp = fopen($dl_rate_file, 'c+');
        if (!$dl_fp) {
            // Could not open the download rate-limit file — respond with a fully
            // hardened 503 so this subsystem failure is indistinguishable from any
            // other server-side error. Mirrors the pattern used by the general
            // rate-limit fopen failure at line ~316 and the download-rate-limit
            // 429 block at line ~3190: all standard security headers, complete
            // rate-limit context headers, error_code field, and retry_after guidance.
            http_response_code(503);
            header('Retry-After: 5');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('X-Request-ID: ' . $request_id);
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            // Download-rate-limit state is unavailable (file couldn't be opened).
            // Send -1 sentinels so clients can distinguish this from a known limit.
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unavailable');
            // Generic rate-limit context headers — use -1 sentinels (same rationale).
            // X-RateLimit-Window: 5 (NOT unavailable) — Retry-After is 5 (delta-seconds),
            // so the generic rate-limit window must also be 5s to stay consistent.
            // X-DL-RateLimit-Window stays unavailable because the download-specific
            // rate-limit store itself is inaccessible (file couldn't be opened).
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: 5');
            // Daily-limit sentinels — -1 signals "not applicable" at this early stage.
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unavailable');
            echo json_encode([
                'error' => 'Service temporarily unavailable.',
                'error_code' => 'SERVICE_UNAVAILABLE',
                'action' => $action ?? 'download',
                'upgrade_url' => UPGRADE_URL,
                'platform' => null,
                'retry_after' => 5,
                'request_id' => $request_id,
                'source_url' => $url ?? null,
                'source_url_missing' => ($url ?? '') === '',
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                // quota fields: daily quota was not consumed since the file couldn't be opened.
                // Use -1 sentinels consistent with other pre-quota-gate errors.
                'quota_remaining' => -1,
                'quota_limit' => -1,
                'quota_reset' => -1,
                'quota_reset_unix' => -1,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (!flock($dl_fp, LOCK_EX)) {
            // Could not acquire an exclusive lock on the download rate-limit file —
            // another process is currently writing to it. Respond with a fully
            // hardened 503 consistent with the fopen failure block above and the
            // general rate-limit flock failure at line ~347. Includes all standard
            // security headers, complete rate-limit context headers, and error_code.
            fclose($dl_fp);
            http_response_code(503);
            header('Retry-After: 5');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('X-Request-ID: ' . $request_id);
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            // Download-rate-limit state unavailable (could not acquire lock).
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unavailable');
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            // X-RateLimit-Window: 5 (NOT unavailable) — Retry-After is 5 (delta-seconds),
            // so the generic rate-limit window must also be 5s to stay consistent.
            // Mirrors the fopen failure block at line ~3495.
            header('X-RateLimit-Window: 5');
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unavailable');
            echo json_encode([
                'error' => 'Service temporarily unavailable.',
                'error_code' => 'SERVICE_UNAVAILABLE',
                'action' => $action ?? 'download',
                'upgrade_url' => UPGRADE_URL,
                'retry_after' => 5,
                'request_id' => $request_id,
                'source_url' => $url ?? null,
                'source_url_missing' => ($url ?? '') === '',
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                // quota fields: daily quota was not consumed since lock couldn't be acquired.
                // Use -1 sentinels consistent with other pre-quota-gate errors.
                'quota_remaining' => -1,
                'quota_limit' => -1,
                'quota_reset' => -1,
                'quota_reset_unix' => -1,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $dl_data = ['t' => time(), 'c' => 0];
        $dl_raw = fread($dl_fp, 4096);
        if ($dl_raw) {
            $dl_decoded = json_decode($dl_raw, true);
            if ($dl_decoded && is_array($dl_decoded)) {
                $dl_data = $dl_decoded;
            }
        }

        if (time() - $dl_data['t'] < $dl_rate_window) {
            if ($dl_data['c'] >= $dl_rate_limit) {
                $dl_reset_ts = $dl_data['t'] + $dl_rate_window;
                flock($dl_fp, LOCK_UN);
                fclose($dl_fp);
                http_response_code(429);
                header('Content-Type: application/json; charset=utf-8');
                header('Retry-After: ' . max(0, $dl_reset_ts - time()));
                // Include download rate-limit headers so clients can distinguish this
                // from the per-minute rate limit without parsing the error body.
                // Mirrors the X-DL-RateLimit-* family set on successful responses.
                header('X-DL-RateLimit-Limit: ' . $dl_rate_limit);
                header('X-DL-RateLimit-Remaining: 0');
                header('X-DL-RateLimit-Reset: ' . $dl_reset_ts);
                header('X-DL-RateLimit-Window: ' . $dl_rate_window);
                // Standard X-RateLimit-* family for generic API consumers.
                // Uses download-specific values (dl_rate_limit, dl_data) since this is
                // the download-action rate-limit gate. $dl_reset_ts is the authoritative
                // reset timestamp for this gate — not the request-level $reset.
                header('X-RateLimit-Limit: ' . $dl_rate_limit);
                header('X-RateLimit-Remaining: ' . max(0, $dl_rate_limit - $dl_data['c']));
                header('X-RateLimit-Reset: ' . $dl_reset_ts);
                // X-RateLimit-Window uses $rate_window (60s), not $dl_rate_window (60s),
                // so the generic header accurately reflects the per-minute request rate limit
                // (not the download-specific rate limit). Consistent with the VERIFICATION_FAILED
                // and successful download response blocks which also use $rate_window here.
                header('X-RateLimit-Window: ' . $rate_window);
                // Daily-limit sentinels (-1) signal clients this is a per-minute rate limit,
                // not a daily quota hit — allows the UI to distinguish the two cases without
                // parsing the error message. The daily-quota 429 block sends the real values.
                header('X-DailyLimit-Limit: -1');
                header('X-DailyLimit-Remaining: -1');
                header('X-DailyLimit-Reset: -1');
                header('X-DailyLimit-Window: unlimited');
                header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
                // X-Info-Timeout: present on every download-action error response so clients
                // can always read the info timeout value without branching on the response code.
                header('X-Info-Timeout: ' . INFO_TIMEOUT);
                echo json_encode([
                    'error' => 'Too many download requests. Slow down.',
                    'error_code' => 'RATE_LIMIT_EXCEEDED',
                    'action' => 'download',
                    'source_url' => $url,
                    'source_url_missing' => false,
                    'upgrade_url' => UPGRADE_URL,
                    'platform' => null,
                    'retry_after' => max(0, (int)($dl_reset_ts - time())),
                    'hint' => 'Wait ' . (int)(max(1, ($dl_reset_ts - time()))) . ' seconds before making another download request. Pass an AhoyVPN unlimited API key for unlimited downloads.',
                    'request_id' => $request_id,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
                    // quota fields: included for consistency with other download error responses.
                    // Quota state is not available at this gate (quota file not yet opened).
                    'quota_remaining' => -1,
                    'quota_limit' => -1,
                    'quota_reset' => -1,
                    'quota_reset_unix' => -1,
                ], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $dl_data['c']++;
        } else {
            $dl_data = ['t' => time(), 'c' => 1];
        }

        // Set remaining AFTER increment so it reflects the cost of this request.
        // Uses the same post-increment pattern as the info endpoint so remaining
        // = limit - count consistently shows how many requests are left AFTER
        // accommodating the current one.
        $dl_remaining = max(0, $dl_rate_limit - $dl_data['c']);
        $dl_reset = $dl_data['t'] + $dl_rate_window;

        ftruncate($dl_fp, 0);
        rewind($dl_fp);
        fwrite($dl_fp, json_encode($dl_data));
        fflush($dl_fp);
        flock($dl_fp, LOCK_UN);
        fclose($dl_fp);

        // Add download rate limit response headers — set after increment so
        // X-DL-RateLimit-Remaining is accurate (post-increment count pattern).
        header('X-DL-RateLimit-Limit: ' . $dl_rate_limit);
        header('X-DL-RateLimit-Remaining: ' . $dl_remaining);
        header('X-DL-RateLimit-Reset: ' . $dl_reset);
        header('X-DL-RateLimit-Window: ' . $dl_rate_window);
        // Mirrors the X-RateLimit-Limit header sent by the info action so
        // generic API consumers always see a consistent rate-limit envelope.
        header('X-RateLimit-Limit: ' . $dl_rate_limit);

        // ─── Daily download quota (free tier limit, skip if unlimited key) ───
        if (!$unlimited) {
            // Use the same $ip variable declared at the top of the script for the
            // rate-limit gate. Both info and download share the daily-quota file.
            $daily_file = '/tmp/ahoyrip_daily_' . md5($ip);
            // Override via QUOTA_DAILY env var (e.g. QUOTA_DAILY=100 in .env).
            // Defaults to QUOTA_DAILY_DEFAULT (5) when the env var is absent. Set to 0
            // or -1 to disable the free tier entirely (unlimited-key required).
            // Mirrors the same constant used in the info action so both actions
            // enforce the same daily limit regardless of which endpoint is called.
            $daily_limit = getDailyQuotaLimit();
            $daily_fp = fopen($daily_file, 'c+');
            if (!$daily_fp) {
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
                header('X-Request-ID: ' . $request_id);
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('X-Download-Options: noopen');
                header('X-Robots-Tag: noindex, noai, noimage, noydir');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
                header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
                header('Cross-Origin-Opener-Policy: same-origin');
                header('Cross-Origin-Resource-Policy: same-origin');
                header('Cache-Control: no-store');
                header('Reporting-Endpoints: csp-report="/csp-report"');
                header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
                header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; report-to csp-report;');
                header('Retry-After: 5');
                // X-DL-RateLimit-*: download-specific rate limit — not applicable here
                // (daily quota file open failed, no download is possible). Use -1 sentinel.
                header('X-DL-RateLimit-Limit: -1');
                header('X-DL-RateLimit-Remaining: -1');
                header('X-DL-RateLimit-Reset: -1');
                header('X-DL-RateLimit-Window: unavailable');
                // X-RateLimit-*: the per-minute request rate limit was consumed by the
                // info call that preceded this block. Preserve those values and add the
                // window header which was not set in the download action's pre-quota section.
                header('X-RateLimit-Window: ' . $rate_window);
                // X-DailyLimit-*: daily quota — the quota file couldn't be opened, so
                // the daily limit state is unavailable. Use -1 sentinels.
                header('X-DailyLimit-Limit: -1');
                header('X-DailyLimit-Remaining: -1');
                header('X-DailyLimit-Reset: -1');
                header('X-DailyLimit-Window: unavailable');
                header('X-Info-Timeout: ' . INFO_TIMEOUT);
                header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
                echo json_encode([
                    'error' => 'Service unavailable.',
                    'error_code' => 'SERVICE_UNAVAILABLE',
                    'action' => 'download',
                    'upgrade_url' => UPGRADE_URL,
                    'retry_after' => 5,
                    'request_id' => $request_id,
                    'source_url' => $url ?? null,
                    'source_url_missing' => ($url ?? '') === '',
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
                    // quota fields: unavailable — the quota file could not be opened.
                    // Use -1 sentinels so clients can distinguish this from a known limit.
                    'quota_remaining' => -1,
                    'quota_limit' => $daily_limit,
                    'quota_reset' => -1,
                    'quota_reset_unix' => -1,
                ], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            if (!flock($daily_fp, LOCK_EX)) {
                fclose($daily_fp);
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
                header('X-Request-ID: ' . $request_id);
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('X-Download-Options: noopen');
                header('X-Robots-Tag: noindex, noai, noimage, noydir');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
                header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
                header('Cross-Origin-Opener-Policy: same-origin');
                header('Cross-Origin-Resource-Policy: same-origin');
                header('Cache-Control: no-store');
                header('Reporting-Endpoints: csp-report="/csp-report"');
                header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
                header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; report-to csp-report;');
                header('Retry-After: 5');
                // X-DL-RateLimit-*: download-specific rate limit — not applicable here
                // (could not acquire daily quota lock, no download is possible). Use -1 sentinel.
                header('X-DL-RateLimit-Limit: -1');
                header('X-DL-RateLimit-Remaining: -1');
                header('X-DL-RateLimit-Reset: -1');
                header('X-DL-RateLimit-Window: unavailable');
                // X-RateLimit-*: the per-minute request rate limit was consumed by the
                // info call that preceded this block. Preserve those values and add the
                // window header which was not set in the download action's pre-quota section.
                header('X-RateLimit-Window: ' . $rate_window);
                // X-DailyLimit-*: daily quota — could not acquire lock, state unavailable.
                // Use -1 sentinels consistent with other pre-quota-gate errors.
                header('X-DailyLimit-Limit: -1');
                header('X-DailyLimit-Remaining: -1');
                header('X-DailyLimit-Reset: -1');
                header('X-DailyLimit-Window: unavailable');
                header('X-Info-Timeout: ' . INFO_TIMEOUT);
                header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
                echo json_encode([
                    'error' => 'Service unavailable.',
                    'error_code' => 'SERVICE_UNAVAILABLE',
                    'action' => 'download',
                    'upgrade_url' => UPGRADE_URL,
                    'retry_after' => 5,
                    'request_id' => $request_id,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
                    // source_url: null — SERVICE_UNAVAILABLE fires before URL validation.
                    // source_url_missing: false — no URL was found to be missing.
                    // format_id_missing: false — SERVICE_UNAVAILABLE fires before format validation.
                    'source_url' => null,
                    'source_url_missing' => false,
                    'format_id_missing' => false,
                    // quota fields: unavailable — the quota file could not be locked.
                    // Use -1 sentinels so clients can distinguish this from a known limit.
                    'quota_remaining' => -1,
                    'quota_limit' => $daily_limit,
                    'quota_reset' => -1,
                    'quota_reset_unix' => -1,
                ], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $daily_data = ['t' => gmdate('Y-m-d'), 'c' => 0];
            $daily_raw = fread($daily_fp, 4096);
            if ($daily_raw) {
                $decoded = json_decode($daily_raw, true);
                if ($decoded && is_array($decoded)) {
                    $daily_data = $decoded;
                }
            }
            $today = gmdate('Y-m-d');
            if ($daily_data['t'] !== $today) {
                $daily_data = ['t' => $today, 'c' => 0];
                // Day rolled over — explicitly truncate the file before writing
                // so any stale bytes from the prior day's larger record cannot
                // persist and be misinterpreted as a higher count on the next read.
                ftruncate($daily_fp, 0);
                rewind($daily_fp);
            }
            if ($daily_data['c'] >= $daily_limit) {
                flock($daily_fp, LOCK_UN);
                fclose($daily_fp);
                logRequest('download', 429, ['reason' => 'daily_limit_exceeded']);
                http_response_code(429);
                $reset_timestamp = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp();
                $quota_reset_iso = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->format('c');
                header('Retry-After: ' . max(0, $reset_timestamp - time()));
                // Cache-Control: no-store — daily-limit responses must not be cached by
                // intermediaries. Mirrors the header set by all other API error responses.
                header('Cache-Control: no-store');
                // Security headers — mirrors the same set sent by the per-minute
                // rate-limit 429 block (line ~344) so daily-quota 429 responses are
                // equally hardened regardless of which limit was hit first.
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('X-Download-Options: noopen');
                header('X-Robots-Tag: noindex, noai, noimage, noydir');
                header('X-Request-ID: ' . $request_id);
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
                header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
                header('Cross-Origin-Opener-Policy: same-origin');
                header('Cross-Origin-Resource-Policy: same-origin');
                // Daily-quota 429 uses -1 sentinels for per-minute rate-limit headers
                // since the per-minute gate was already passed (this is a daily limit).
                // Consistent with the -1 pattern used by other pre-gate error responses.
                header('X-RateLimit-Limit: -1');
                header('X-RateLimit-Remaining: -1');
                header('X-RateLimit-Reset: -1');
                header('X-RateLimit-Window: unlimited');
                header('X-DL-RateLimit-Limit: -1');
                header('X-DL-RateLimit-Remaining: -1');
                header('X-DL-RateLimit-Reset: -1');
                header('X-DL-RateLimit-Window: unlimited');
                header('X-DailyLimit-Limit: ' . $daily_limit);
                header('X-DailyLimit-Remaining: 0');
                header('X-DailyLimit-Reset: ' . $reset_timestamp);
                header('X-DailyLimit-Window: 86400');
                header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
                header('X-Info-Timeout: ' . INFO_TIMEOUT);
                echo json_encode([
                    'error' => $daily_limit > 0
                        ? "Daily limit reached. You get {$daily_limit} free rips per day. For unlimited access, visit " . UPGRADE_URL
                        : "Daily limit reached. This server does not offer a free tier. For unlimited access, visit " . UPGRADE_URL,
                    'error_code' => 'DAILY_LIMIT',
                    'action' => 'download',
                    'source_url' => $url,
                    'source_url_missing' => false,
                    'format_id_missing' => false,
                    'upgrade_url' => UPGRADE_URL,
                    'retry_after' => max(0, (int)($reset_timestamp - time())),
                    'hint' => 'Get an AhoyVPN unlimited API key to bypass the daily limit, or wait until ' . $quota_reset_iso . ' UTC.',
                    'quota_remaining' => 0,
                    'quota_limit' => $daily_limit,
                    'quota_reset' => $quota_reset_iso,
                    'quota_reset_unix' => (int)$reset_timestamp,
                    'request_id' => $request_id,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
                ], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $daily_data['c']++;
            // Refund guard: if proc_open fails below, we decrement here to reverse
            // the increment. This is the pre-refund baseline — must stay in sync
            // with the refund block that runs on download failure.
            $dl_quota_before_refund = $daily_data['c'];
            ftruncate($daily_fp, 0);
            rewind($daily_fp);
            fwrite($daily_fp, json_encode($daily_data));
            fflush($daily_fp);
            flock($daily_fp, LOCK_UN);
            fclose($daily_fp);  // explicitly close to release lock without waiting for GC
            $daily_fp = null;

            // Surface daily quota state so the client can display remaining rips.
            // Show how many rips remain AFTER this request: limit minus the new count.
            // c=1 (after 1st rip) means 1 rip has been consumed; remaining = limit - c = 5.
            // c=5 (after 5th rip) means 5 rips consumed; remaining = 1 — the last rip.
            // The X-DailyLimit-Remaining header must match quota_remaining in the JSON body.
            header('X-DailyLimit-Limit: ' . $daily_limit);
            header('X-DailyLimit-Remaining: ' . max(0, $daily_limit - $daily_data['c']));
            header('X-DailyLimit-Reset: ' . (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp());
            header('X-DailyLimit-Window: 86400');
        } else {
            // Unlimited-key holder — quota does not apply, signal this to the
            // client with -1 so it can hide the "N free rips/day" UI element.
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
        }

// ─── Sanitize derived filename ───
        // allow only safe chars; fall back to generic name if empty/too long.
        // Also strip CR/LF to prevent Content-Disposition header CRLF injection
        // (a newline in the Content-Disposition filename parameter could allow
        // header injection attacks even though the filename field itself is not
        // directly used in binary download responses).
        // URL-decode first: the frontend sends this as a URL-encoded query parameter,
        // so a filename like "My%20Video" must be decoded to "My Video" before
        // length validation. Without urldecode(), encoded chars are counted literally
        // (strlen("My%20Video") = 13) but the actual decoded value is shorter,
        // causing valid filenames to fail the length check unexpectedly.
        $download_filename = trim(urldecode($_GET['filename'] ?? ''));
        if ($download_filename !== '') {
            // Strip control characters including newlines and carriage returns
            // before sanitizing so that a filename like "evil\r\nContent-Type:..."
            // cannot inject headers through the Content-Disposition header below.
            // Unicode letters, numbers, spaces, dots, underscores, hyphens are preserved.
            $download_filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $download_filename);
            $download_filename = preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $download_filename);
            $download_filename = preg_replace('/\s+/u', '_', $download_filename);
            // Validate trimmed result — a filename that trims to empty is invalid.
            // Check this AFTER sanitization so inputs like "   " fall through to fallback.
            $trimmed = trim($download_filename);
            if (strlen($trimmed) === 0 || strlen($trimmed) > MAX_FILENAME_LEN) {
                $download_filename = 'ahoyrip';
            } else {
                $download_filename = $trimmed;
            }
        } else {
            $download_filename = 'ahoyrip';
        }

        // Build output template — use exec array to bypass shell entirely.
        // yt-dlp appends the file extension to the output path automatically,
        // so the template must NOT contain a literal extension — pass the
        // base path only. Using a .tmp suffix would result in yt-dlp naming
        // the file "ahoyrip_<hash>.tmp.mp4" (wrong extension placement).
        $tmp_dir = sys_get_temp_dir();
        $out_base = 'ahoyrip_' . bin2hex(random_bytes(8));
        $out_template = $tmp_dir . '/' . $out_base;  // yt-dlp auto-appends e.g. .mp4

        // Register shutdown handler to clean up any temp files on unexpected exit.
        // Catches: fatal errors, connection aborts, timeout before normal cleanup.
        // The glob pattern is captured by PHP's closure semantics.
        register_shutdown_function(function() use($tmp_dir, $out_base) {
            foreach (glob($tmp_dir . '/' . $out_base . '*') as $f) { @unlink($f); }
        });

        // yt-dlp sends the URL itself as referer by default. Allow per-request override
        // via ?referer= URL param (same pattern used by the info action at line 184).
        // A platform-specific referer (e.g. youtube.com) can improve extraction success
        // on sites that validate the referer header. Falls back to ahoyripper.com if
        // no override is provided, preventing the user's video URL from leaking.
        $referer = isset($_GET['referer']) && $_GET['referer'] !== ''
            ? $_GET['referer']
            : 'https://ahoyripper.com/';

        // --socket-timeout: yt-dlp's per-connection timeout. Set to DOWNLOAD_TIMEOUT - 15s so
        // PHP's process-level timeout (DOWNLOAD_TIMEOUT) is always the outer limit and has time
        // to cleanly terminate the process and emit a classified DOWNLOAD_TIMEOUT error.
        // Without this, yt-dlp uses its own default (~20s) which can fire before PHP's timeout
        // and produce an unclassified error instead of DOWNLOAD_TIMEOUT.
        // --no-playlist / --yes-playlist: controls whether to download a playlist (all
        // videos) or a single video. yt-dlp accepts --yes-playlist and --no-playlist
        // as boolean flags (not --playlist true/false, which yt-dlp rejects as ambiguous).
        // Pass --no-playlist by default (playlist=0, the default) so single-video URLs
        // always get one video. Pass --yes-playlist when playlist=1 is explicitly requested.
        // Note: playlist flags must appear BEFORE the URL in the yt-dlp command.
        // Delegated to resolvePlaylistFlag() — a pure helper that returns ['--yes-playlist']
        // or ['--no-playlist'] to avoid duplicating the resolution logic inline.
        $playlist_flags = resolvePlaylistFlag($_GET['playlist'] ?? null);
        // yt-dlp per-connection timeout: PHP-side DOWNLOAD_TIMEOUT is the outer limit,
        // yt-dlp's --socket-timeout is the inner limit. Set to DOWNLOAD_TIMEOUT - 15s so
        // PHP always fires first and classifies as DOWNLOAD_TIMEOUT rather than CONNECTION_FAILED.
        $socket_timeout = max(1, DOWNLOAD_TIMEOUT - 15);
        $ytdlp_cmd = [
            YTDLP_PATH,
            '-f', $format_id,
            '-o', $out_template,
            '--force-overwrite',
            '--retries', '3',
            // --extractor-retries: yt-dlp retries known extractor errors (rate limits,
            // temporary 5xx, etc.) separately from generic --retries. Useful for
            // recovering from transient source-platform errors without escalating to
            // the generic retry budget. Default is 3 when omitted; set explicitly
            // so the behavior is intentional and documented.
            '--extractor-retries', '3',
            '--restrict-filenames',
            // --no-mtime: do not set the downloaded file's modification time to the
            // source video's upload date. AhoyRipper streams files to the client rather
            // than storing them on disk long-term, so the source mtime is meaningless
            // and could cause cache confusion or unexpected file-identity behavior when
            // the same file is re-downloaded. The file's actual mtime (download moment)
            // is the meaningful timestamp for a streaming service.
            '--no-mtime',
        ];
        // resolvePlaylistFlag() returns ['--yes-playlist'] or ['--no-playlist'].
        // --no-playlist is the safe default (single video); --yes-playlist is
        // added only when playlist=1 is explicitly requested.
        foreach ($playlist_flags as $flag) {
            $ytdlp_cmd[] = $flag;
        }
        $ytdlp_cmd = array_merge($ytdlp_cmd, [
            // --no-progress: suppress all progress output. yt-dlp emits progress
            // template noise to stderr that can corrupt downstream parsing in PHP.
            // --no-progress is the correct modern flag (consistent with info action
            // at line 2833 and health probe at line 5568).
            '--no-progress',
            '--socket-timeout', (string)$socket_timeout,
            '--referer', $referer,
            '--user-agent', AHOY_USER_AGENT,
        ]);
        // Add --impersonate to spoof browser TLS/ALPN fingerprints (yt-dlp 2024.09+).
        // Dramatically reduces 403/422 bot-detection errors on protected sites.
        if (AHOY_IMPERSONATE !== '') {
            $ytdlp_cmd[] = '--impersonate';
            $ytdlp_cmd[] = AHOY_IMPERSONATE;
        }
        // Add --cookies if COOKIES_PATH is configured (enables authenticated ripping
        // for age-restricted YouTube, Spotify, etc.). See README.md cookie instructions.
        if (COOKIES_PATH !== '') {
            $ytdlp_cmd[] = '--cookies';
            $ytdlp_cmd[] = COOKIES_PATH;
        }
        $ytdlp_cmd = array_merge($ytdlp_cmd, [
            '--add-header', 'Accept-Language: ' . preg_replace('/[^\x20-\x7E;,=]/', '', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en-US;q=0.9,*;q=0.5'),
            '--',
            $url,
        ]);

        $pipes = null;
        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = proc_open($ytdlp_cmd, $desc, $pipes, '/tmp', [], ['bypass_shell' => true]);

        if (!$proc) {
            // pipes[0] (stdin) may be partially open — clean up all three pipes
            if ($pipes !== null) {
                foreach ($pipes as $p) { if ($p !== null && is_resource($p)) fclose($p); }
                $pipes = null;
            }
            logRequest('download', 500, ['reason' => 'proc_open_failed']);
            // Refund daily quota since no download attempt was possible.
            // Only refund when the baseline was set (proc_open was attempted after
            // quota increment). Unlimited-key holders ($unlimited=true) skip
            // increment so no refund needed.
            // $post_refund_count tracks the quota count AFTER the refund is applied.
            // Initialised to $daily_limit as a safe default (no refund on failure).
            $post_refund_count = $daily_limit;
            if (!$unlimited && isset($dl_quota_before_refund)) {
                $post_refund_count = refundQuota($ip, $unlimited, $daily_limit, $dl_quota_before_refund);
            }
            http_response_code(500);
            header('Cache-Control: no-store');
            header('X-Request-ID: ' . $request_id);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('Reporting-Endpoints: csp-report="/csp-report"');
            header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
            header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; report-to csp-report;');
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: unavailable');
            // X-DL-RateLimit-*: download-specific rate limit.
            // PROC_OPEN_FAILED means proc_open itself failed — no download rate limit
            // was consumed. Use -1 sentinel to signal "not applicable", consistent
            // with the same sentinel used by other pre-limit-gate errors.
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unavailable');
            // X-DailyLimit-*: daily quota sentinels (-1) since proc_open failure means
            // no quota was consumed. Consistent with other pre-gate error responses.
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unavailable');
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            // X-FFProbe-Status: ffprobe was never reached — proc_open itself failed
            // before yt-dlp could run, so no file was ever produced for ffprobe to verify.
            // Mark as skipped so clients can distinguish this from VERIFICATION_FAILED
            // (where ffprobe ran but found the file corrupt/unreadable).
            header('X-FFProbe-Status: skipped');
            // retry_after: delta-seconds until the download can be retried.
            // Per RFC 9110, Retry-After accepts either an HTTP-date or delta-seconds;
            // delta-seconds is simpler and consistent with all other Retry-After
            // headers in this file. Using DOWNLOAD_TIMEOUT (not time() + DOWNLOAD_TIMEOUT)
            // ensures this stays consistent with the delta-seconds format.
            $retry_delta = DOWNLOAD_TIMEOUT;
            header('Retry-After: ' . max(0, $retry_delta));
            echo json_encode([
                'error' => 'Failed to start download process.',
                'error_code' => 'PROC_OPEN_FAILED',
                'action' => 'download',
                'upgrade_url' => UPGRADE_URL,
                'hint' => 'The server is overloaded or the download tool is unavailable. Wait a moment and retry. If persistent, the server may need maintenance.',
                'retry_after' => max(0, $retry_delta),
                'request_id' => $request_id,
                'source_url' => $url,
                'source_url_missing' => false,
                'format_id' => $format_id,
                'format_id_missing' => ($format_id === '' || $format_id === null),
                'platform' => null,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                // quota fields: quota was refunded before this response.
                // $post_refund_count is the post-refund daily count returned by
                // refundQuota() — it IS the remaining quota, not an offset from the limit.
                // Unlimited-key holders ($unlimited=true) were never incremented — use -1 sentinel
                // for quota_remaining to match the pattern used by every other
                // download-action error response.
                'quota_remaining' => !$unlimited ? $post_refund_count : -1,
                'quota_limit' => !$unlimited ? $daily_limit : -1,
                'quota_reset' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                'quota_reset_unix' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        // Close stdin — yt-dlp does not need input from stdin. Not closing it can
        // cause yt-dlp to hang waiting for input on stdin if the pipe is inherited
        // open across an exec or in certain subprocess scenarios. The info action
        // also closes stdin immediately after proc_open (line 1915).
        if ($pipes !== null && $pipes[0] !== null) {
            fclose($pipes[0]);
            unset($pipes[0]);
        }

        $start = hrtime(true);
        $timeout = DOWNLOAD_TIMEOUT; // configurable via YTDLP_DOWNLOAD_TIMEOUT env var (default 300s)
        $proc_killed = false;
        $proc_stdout = '';
        $proc_stderr = '';

        stream_set_timeout($pipes[1], 0);  // Infinite — global (hrtime(true) - $start) / 1e9 > $timeout is authoritative
        stream_set_timeout($pipes[2], 0);  // Timeout fires only when child process stalls; feof() stays false until proc closes pipe

        while (true) {
            if ($timeout > 0 && (hrtime(true) - $start) / 1e9 > $timeout) {
                // Clean up process handle before exit to avoid zombie processes.
                // proc_terminate sends SIGKILL; setting $proc = null is the sentinel
                // that prevents the post-loop proc_close() from running on an
                // already-closed handle (avoids double-close).
                proc_terminate($proc, 9);
                $proc = null;  // sentinel: post-loop proc_close() skips this
                $proc_killed = true;
                // Use glob pattern — $out_file was never set in this scope.
                // $out_base was set above and holds the safe base name.
                foreach (glob($tmp_dir . '/' . $out_base . '*') as $f) { @unlink($f); }
                // Refund daily quota since the download never started successfully.
                // Only refund when the baseline was set (proc_open was attempted after
                // quota increment). Unlimited-key holders ($unlimited=true) skip
                // increment so no refund needed. Capture return value — refundQuota()
                // returns the post-refund daily count (the remaining quota).
                $post_refund_count = $daily_limit;
                if (!$unlimited && isset($dl_quota_before_refund)) {
                    $post_refund_count = refundQuota($ip, $unlimited, $daily_limit, $dl_quota_before_refund);
                }
                logRequest('download', 504, ['reason' => 'timeout', 'timeout_seconds' => $timeout]);
                http_response_code(504);
                // X-RateLimit-*: use $rate_window (60s) for the generic request rate limit
                // so the generic header accurately reflects the per-minute request rate.
                header('X-RateLimit-Limit: -1');
                header('X-RateLimit-Remaining: -1');
                header('X-RateLimit-Reset: -1');
                header('X-RateLimit-Window: ' . $rate_window);
                // X-DailyLimit-*: download timed out before starting — no quota consumed.
                header('X-DailyLimit-Limit: -1');
                header('X-DailyLimit-Remaining: -1');
                header('X-DailyLimit-Reset: -1');
                header('X-DailyLimit-Window: unavailable');
                // retry_after: delta-seconds until the download can be retried.
                // Use DOWNLOAD_TIMEOUT as a fixed window so the client has a consistent
                // countdown value regardless of when the response is processed.
                $retry_delta = DOWNLOAD_TIMEOUT;
                header('Retry-After: ' . max(0, $retry_delta));
                header('Cache-Control: no-store');
                header('X-Request-ID: ' . $request_id);
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
                header('Cross-Origin-Opener-Policy: same-origin');
                header('Cross-Origin-Resource-Policy: same-origin');
                header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
                header('X-Info-Timeout: ' . INFO_TIMEOUT);
                header('X-Download-Options: noopen');
                header('X-Robots-Tag: noindex, noai, noimage, noydir');
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
                // X-FFProbe-Status: ffprobe was never reached — yt-dlp timed out before
                // producing any output file, so no file was produced for ffprobe to verify.
                // Mark as skipped so clients can distinguish this from VERIFICATION_FAILED.
                header('X-FFProbe-Status: skipped');
                // CSP headers: the DOWNLOAD_TIMEOUT block exit()s from within the download
                // loop before reaching the classified/unclassified yt-dlp error blocks that
                // also add CSP headers. Add the three headers here to maintain consistent
                // CSP reporting and browser security policy enforcement for timeouts.
                header('Reporting-Endpoints: csp-report="/csp-report"');
                header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
                header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; report-to csp-report;');
                // X-DL-RateLimit-*: surface download-specific rate limit state so clients always
                // have rate-limit metadata regardless of how the download ends (classified or not).
                // Mirrors the headers set at line ~3822 for classified YTDLP_ERROR downloads.
                if ($unlimited) {
                    header('X-DL-RateLimit-Limit: -1');
                    header('X-DL-RateLimit-Remaining: -1');
                    header('X-DL-RateLimit-Reset: -1');
                    header('X-DL-RateLimit-Window: unlimited');
                } else {
                    header('X-DL-RateLimit-Limit: ' . $dl_rate_limit);
                    header('X-DL-RateLimit-Remaining: ' . max(0, $dl_remaining));
                    header('X-DL-RateLimit-Reset: ' . $dl_reset);
                    header('X-DL-RateLimit-Window: ' . $dl_rate_window);
                }
                echo json_encode([
                    'error' => 'Download timed out after ' . $timeout . ' seconds. The file may be too large or the source is slow. Try a smaller format.',
                    'error_code' => 'DOWNLOAD_TIMEOUT',
                    'action' => 'download',
                    'upgrade_url' => UPGRADE_URL,
                    'retry_after' => max(0, $retry_delta),
                    'request_id' => $request_id,
                    'source_url' => $url,
                    'source_url_missing' => false,
                    'format_id' => $format_id,
                    'format_id_missing' => false,
                    'platform' => null,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
                    'quota_remaining' => !$unlimited ? $post_refund_count : -1,
                    'quota_limit' => !$unlimited ? $daily_limit : -1,
                    'quota_reset' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                    'quota_reset_unix' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                ], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }

            $read = [];
            if ($pipes[1] !== null && !feof($pipes[1])) $read[] = $pipes[1];
            if ($pipes[2] !== null && !feof($pipes[2])) $read[] = $pipes[2];

            if (empty($read)) {
                break;
            }

            $w = $e = null;
            $changed = @stream_select($read, $w, $e, 1, 0);
            if ($changed === false || $changed === 0) {
                usleep(100000);
                continue;
            }

            foreach ($read as $p) {
                $s = @fread($p, 65536);
                if ($s === false || $s === '') {
                    if (feof($p)) {
                        fclose($p);
                        if ($p === $pipes[1]) $pipes[1] = null;
                        elseif ($p === $pipes[2]) $pipes[2] = null;
                    }
                } else {
                    if ($p === $pipes[1]) {
                        $proc_stdout .= $s;
                    } elseif ($p === $pipes[2]) {
                        $proc_stderr .= $s;
                    }
                }
            }
        }
        // Close any remaining open pipes
        if ($pipes[1] !== null) fclose($pipes[1]);
        if ($pipes[2] !== null) fclose($pipes[2]);

        // proc_close() returns the exit code — only call if $proc is still open.
        // $proc = null is set by the timeout handler to prevent double-close.
        // When $proc is null the process was already terminated and closed there.
        $actual_exit = ($proc !== null) ? proc_close($proc) : -1;
        if ($actual_exit !== 0) {
            foreach (glob($tmp_dir . '/' . $out_base . '*') as $f) { @unlink($f); }
            // Build a descriptive error from the captured stderr/stdout
            $proc_err = trim($proc_stderr ?? '');
            if (!$proc_err) {
                $proc_err = trim($proc_stdout ?? '');
            }
            $proc_err = preg_replace('/[\x00-\x1F\x7F]/', '', $proc_err);
            $proc_err = strip_tags($proc_err);
            $proc_err = preg_replace('/\s+/', ' ', $proc_err);
            if (mb_strlen($proc_err, 'UTF-8') > 200) $proc_err = mb_substr($proc_err, 0, 200, 'UTF-8') . '...';
            $err_classified = classifyYtdlpError($proc_err, $actual_exit);

            // Refund daily quota for any download failure — classified or not.
            // Whether the error is GEOBLOCKED (content unavailable) or an unexpected
            // yt-dlp exit (e.g. network glitch, source timeout), the user didn't
            // successfully download anything, so the quota should not be burned.
            // Skip refund only for successful exits and when the user is on the
            // free tier ($unlimited is false) — unlimited-key holders never had
            // their quota incremented in the first place.
            // refundQuota() uses an at-most-once guard internally to handle
            // proc_open failure gracefully (if it decremented before us, we skip).
            if (!$unlimited && isset($dl_quota_before_refund)) {
                refundQuota($ip, $unlimited, $daily_limit, $dl_quota_before_refund);
            }

            // Retry-After: delta-seconds until the download can be retried.
            // Use DOWNLOAD_TIMEOUT as a fixed window so the client has a consistent
            // countdown value regardless of when the response is processed.
            // Permanent failures (GEOBLOCKED 451, COPYRIGHT 451, PRIVATE 403) still
            // carry this header — clients treat it as advisory; the user sees the error
            // message and won't retry a permanent failure even if they ignore the hint.
            $retry_delta = DOWNLOAD_TIMEOUT;
            if ($err_classified) {
                $status = $err_classified['status'] ?? 422;
                logRequest('download', $status, ['reason' => 'ytdlp_error_classified', 'err_code' => $err_classified['code']]);
                http_response_code($status);
                header('Retry-After: ' . max(0, $retry_delta));
                header('Cache-Control: no-store');
                header('X-Request-ID: ' . $request_id);
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
                header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
                header('Cross-Origin-Opener-Policy: same-origin');
                header('Cross-Origin-Resource-Policy: same-origin');
                header('X-Download-Options: noopen');
                header('X-Robots-Tag: noindex, noai, noimage, noydir');
                header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
                header('X-Info-Timeout: ' . INFO_TIMEOUT);
                // X-FFProbe-Status: ffprobe was never reached in the classified-error path
                // (yt-dlp exited non-zero before ffprobe was called). Mark as skipped so
                // clients can distinguish this from a ffprobe-verification failure.
                header('X-FFProbe-Status: skipped');
                // CSP headers: classified errors exit() from within a switch block that
                // bypasses the global headers set at the top of the script. These three
                // headers are needed to maintain consistent CSP reporting and browser
                // security policy enforcement even when yt-dlp returns a classified error.
                header('Reporting-Endpoints: csp-report="/csp-report"');
                header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
                header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; report-to csp-report;');
                // X-DL-RateLimit-*: surface download-specific rate limit state in classified
                // error responses so clients always have rate-limit metadata, even when yt-dlp
                // produces a recognized error (GEOBLOCKED, AGE_RESTRICTED, etc.) instead of an
                // unclassified exit. Mirrors the headers set at line ~3155 for successful downloads.
                if ($unlimited) {
                    header('X-DL-RateLimit-Limit: -1');
                    header('X-DL-RateLimit-Remaining: -1');
                    header('X-DL-RateLimit-Reset: -1');
                    header('X-DL-RateLimit-Window: unlimited');
                } else {
                    header('X-DL-RateLimit-Limit: ' . $dl_rate_limit);
                    header('X-DL-RateLimit-Remaining: ' . max(0, $dl_remaining));
                    header('X-DL-RateLimit-Reset: ' . $dl_reset);
                    header('X-DL-RateLimit-Window: ' . $dl_rate_window);
                }
                // Compute post-refund quota for the JSON body. refundQuota() is idempotent
                // (safe to call even if already refunded via the proc_open failure path above).
                $post_refund_count = $unlimited ? $daily_limit : refundQuota($ip, $unlimited, $daily_limit, $dl_quota_before_refund);
                // Mirror X-DailyLimit-* headers set on successful download responses,
                // so the client always has quota metadata regardless of how the download ends.
                // Unlimited-key holders get -1 sentinel values signaling "no quota applies".
                if ($unlimited) {
                    header('X-DailyLimit-Limit: -1');
                    header('X-DailyLimit-Remaining: -1');
                    header('X-DailyLimit-Reset: -1');
                    header('X-DailyLimit-Window: unlimited');
                } else {
                    header('X-DailyLimit-Limit: ' . $daily_limit);
                    header('X-DailyLimit-Remaining: ' . ($unlimited ? -1 : $post_refund_count));
                    header('X-DailyLimit-Reset: ' . (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp());
                    header('X-DailyLimit-Window: 86400');
                }
                $resp = [
                    'error' => $err_classified['msg'],
                    'error_code' => $err_classified['code'],
                    'action' => 'download',
                    'upgrade_url' => UPGRADE_URL,
                    'hint' => 'The download failed with a source error. Check the error message for details, try another format, or try again shortly.',
                    'request_id' => $request_id,
                    'source_url' => $url,
                    'source_url_missing' => false,
                    'format_id' => $format_id,
                    'format_id_missing' => false,
                    // platform: the download action runs after the info action in normal usage
                    // (the UI always fetches info first), so the platform is known from the
                    // info response in the client state. This endpoint has no access to that
                    // phase's $first_valid['extractor_key']. Set to null to match all other
                    // download error paths and avoid fabricating platform data here.
                    'platform' => null,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
                    'retry_after' => max(0, $retry_delta),
                    'quota_remaining' => $unlimited ? -1 : $post_refund_count,
                    'quota_limit' => !$unlimited ? $daily_limit : -1,
                    'quota_reset' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                    'quota_reset_unix' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                ];
                // Surface the raw yt-dlp output for classified errors too
                if ($proc_err) {
                    $resp['raw_error'] = $proc_err;
                }
                echo json_encode($resp, JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            } else {
                // Unclassified error — $err_classified is null; use 422 as safe default.
                logRequest('download', 422, ['reason' => 'ytdlp_error', 'exit' => $actual_exit, 'err_preview' => substr($proc_err, 0, 100)]);
                http_response_code(422);
                header('Retry-After: ' . max(0, $retry_delta));
                header('Cache-Control: no-store');
                header('X-Request-ID: ' . $request_id);
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
                header('Cross-Origin-Opener-Policy: same-origin');
                header('Cross-Origin-Resource-Policy: same-origin');
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
                header('X-Download-Options: noopen');
                header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
                header('X-Info-Timeout: ' . INFO_TIMEOUT);
                header('X-Robots-Tag: noindex, noai, noimage, noydir');
                // X-FFProbe-Status: ffprobe was never reached in the unclassified-error path
                // (yt-dlp exited non-zero before ffprobe was called). Mark as skipped so
                // clients can distinguish this from a ffprobe-verification failure.
                header('X-FFProbe-Status: skipped');
                // CSP headers: unclassified errors exit() from within a switch block that
                // bypasses the global headers set at the top of the script. These three
                // headers are needed to maintain consistent CSP reporting and browser
                // security policy enforcement even when yt-dlp returns an unrecognized error.
                header('Reporting-Endpoints: csp-report="/csp-report"');
                header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
                header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; report-to csp-report;');
                // X-DL-RateLimit-*: surface download-specific rate limit state so clients always
                // have rate-limit metadata regardless of how the download ends (classified or not).
                // Mirrors the headers set at line ~3155 for successful downloads.
                if ($unlimited) {
                    header('X-DL-RateLimit-Limit: -1');
                    header('X-DL-RateLimit-Remaining: -1');
                    header('X-DL-RateLimit-Reset: -1');
                    header('X-DL-RateLimit-Window: unlimited');
                } else {
                    header('X-DL-RateLimit-Limit: ' . $dl_rate_limit);
                    header('X-DL-RateLimit-Remaining: ' . max(0, $dl_remaining));
                    header('X-DL-RateLimit-Reset: ' . $dl_reset);
                    header('X-DL-RateLimit-Window: ' . $dl_rate_window);
                }
                // Truncate the user-facing error message to match the ~200-char ceiling used
                // throughout the rest of the API (parseFormats YTDLP_ERROR, classified errors).
                // The full raw error is preserved in 'raw_error' for diagnostics.
                $user_err = $proc_err ?: "exit code $actual_exit";
                if (mb_strlen($user_err, 'UTF-8') > 200) {
                    $user_err = mb_substr($user_err, 0, 200, 'UTF-8') . '...';
                }
                // Compute post-refund quota inline — $post_refund_count from the classified-error
                // branch (line 3019) is not valid here since that block was never entered.
                // refundQuota() is idempotent (safe to call even if already refunded via the
                // proc_open failure path above).
                $uncl_post_refund_count = $unlimited ? $daily_limit : refundQuota($ip, $unlimited, $daily_limit, $dl_quota_before_refund);
                if ($unlimited) {
                    header('X-DailyLimit-Limit: -1');
                    header('X-DailyLimit-Remaining: -1');
                    header('X-DailyLimit-Reset: -1');
                    header('X-DailyLimit-Window: unlimited');
                } else {
                    header('X-DailyLimit-Limit: ' . $daily_limit);
                    header('X-DailyLimit-Remaining: ' . $uncl_post_refund_count);
                    header('X-DailyLimit-Reset: ' . (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp());
                    header('X-DailyLimit-Window: 86400');
                }
                $resp = [
                    'error' => "Download failed" . ($proc_err ? ": $user_err" : " (exit code $actual_exit)."),
                    'error_code' => 'YTDLP_ERROR',
                    'action' => 'download',
                    'upgrade_url' => UPGRADE_URL,
                    'hint' => 'The source returned an unrecognised error. Try another format, or wait a moment and try again. If persistent, the source platform may be temporarily unavailable.',
                    'request_id' => $request_id,
                    'source_url' => $url,
                    'source_url_missing' => false,
                    'format_id' => $format_id,
                    'format_id_missing' => false,
                    'platform' => null,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
                    'retry_after' => max(0, $retry_delta),
                    'quota_remaining' => $unlimited ? -1 : $uncl_post_refund_count,
                    'quota_limit' => !$unlimited ? $daily_limit : -1,
                    'quota_reset' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                    'quota_reset_unix' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                ];
                if ($proc_err) {
                    $resp['raw_error'] = $proc_err;
                }
                echo json_encode($resp, JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }

        // Find the actual downloaded file — glob for the resolved extension
        $glob_pattern = $tmp_dir . '/' . $out_base . '.*';
        $matched = glob($glob_pattern);
        $actual_file = $matched[0] ?? null;

        // Clear stat cache before reading filesize — glob() uses cached directory
        // entries and PHP's filesize() also caches result metadata. Without clearing,
        // filesize() can return 0 or a stale size even for a freshly-downloaded file
        // on long-running PHP processes that have hit the same path before.
        clearstatcache(true, $actual_file);
        $filesize = @filesize($actual_file);
        if ($filesize === false || $filesize === 0 || !$actual_file || !is_file($actual_file)) {
            foreach (glob($glob_pattern) as $f) { @unlink($f); }
            logRequest('download', 500, ['reason' => 'empty_or_missing_file', 'format_id' => $format_id]);
            // Refund daily quota — yt-dlp exited 0 but produced no file or an
            // empty/zero-byte file. The user received nothing usable and shouldn't
            // be charged. Consistent with all other download failure paths.
            $post_refund_count = $daily_limit;
            if (!$unlimited && isset($dl_quota_before_refund)) {
                $post_refund_count = refundQuota($ip, $unlimited, $daily_limit, $dl_quota_before_refund);
            }
            http_response_code(500);
            header('Cache-Control: no-store, must-revalidate');
            header('X-Request-ID: ' . $request_id);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            // CSP headers: DOWNLOAD_EMPTY calls exit() from within a switch block that
            // does not reach the normal global CSP headers set at the top of the script.
            // Include them explicitly so this block is self-contained and consistent
            // with other 500-class error responses in the download action
            // (e.g. PROC_OPEN_FAILED at line ~3971).
            header('Reporting-Endpoints: csp-report="/csp-report"');
            header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
            header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; report-to csp-report;');
            // X-RateLimit-*: generic request-rate limit sentinels (-1) since
            // DOWNLOAD_EMPTY occurs before the download rate limit gate. yt-dlp exited 0
            // but produced no/empty file — no download rate limit was consumed. Consistent
            // with other pre-gate error responses in the download action.
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: unavailable');
            // X-DL-RateLimit-*: download-specific rate limit.
            // yt-dlp exited 0 but produced no/empty file — no download rate limit was
            // consumed. Use -1 sentinel to signal "not applicable", consistent with
            // other pre-limit-gate errors in the download action.
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unavailable');
            // X-DailyLimit-*: daily quota sentinels (-1) since empty-file means no
            // valid download was consumed. Consistent with other pre-gate error responses.
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unavailable');
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            // retry_after: delta-seconds until the download can be retried.
            // Per RFC 9110, Retry-After accepts either an HTTP-date or delta-seconds;
            // delta-seconds is simpler and consistent with all other Retry-After
            // headers in this file. Using DOWNLOAD_TIMEOUT (not time() + DOWNLOAD_TIMEOUT)
            // keeps this as a delta-seconds value.
            $retry_delta = DOWNLOAD_TIMEOUT;
            header('Retry-After: ' . max(0, $retry_delta));
            echo json_encode([
                'error' => 'Download failed: the source returned an empty file. This is a server-side issue, not a format problem. Please try again in a moment or choose a different format.',
                'error_code' => 'DOWNLOAD_EMPTY',
                'action' => 'download',
                'upgrade_url' => UPGRADE_URL,
                'hint' => 'The server encountered a temporary issue downloading the file. Try again in a moment or pick a different format.',
                'retry_after' => max(0, $retry_delta),
                'request_id' => $request_id,
                'source_url' => $url,
                'source_url_missing' => false,
                'format_id' => $format_id,
                'format_id_missing' => false,
                'platform' => null,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                'server_time' => date('c'),
                'server_time_unix' => time(),
                'quota_remaining' => !$unlimited ? $post_refund_count : -1,
                'quota_limit' => !$unlimited ? $daily_limit : -1,
                'quota_reset' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                'quota_reset_unix' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        // Detect extension and MIME from the actual downloaded file
        $ext = pathinfo($actual_file, PATHINFO_EXTENSION);
        // Use the sanitized derived filename from the URL param, falling back to
        // the generic "ahoyrip.<ext>" so the browser still proposes a useful name.
        $download_name = $download_filename . '.' . ($ext ?: 'mp4');

        // Detect format substitution: yt-dlp may silently substitute a different
        // format when the requested one is unavailable (e.g. no 1080p → best 720p).
        // Run ffprobe on the actual file to get real codec and resolution metadata,
        // then compare against the requested format_id to determine if substitution occurred.
        // Only flag substitution when it materially changes the quality the user selected.
        //
        // Skip ffprobe entirely for audio-only formats — there is no video stream to
        // probe. Since ffprobe uses -select_streams v:0, it will always return zero
        // streams for audio files, so the substitution check can never fire. Avoiding
        // the unnecessary proc_open + ffprobe call saves ~50-100ms per audio download.
        // yt-dlp never substitutes audio-only formats (bitrate is a tier, not a codec),
        // so no substitution detection is needed for these cases.
        $actual_height = null;
        $actual_width = null;
        $actual_video_codec = null;
        $format_substituted = false;
        $substituted_label = null;
        $ffprobe_bin = FFPROBE_PATH;
        // Probe only when there is a video stream to check: skip for audio-only
        // format IDs (bestaudio, any vcodec=none) and bare audio codecs.
        // NOTE: $acodec and $vcodec are NOT available in the download action — they
        // are only set by parseFormats() in the info action. Detection must rely
        // entirely on the format_id string passed by the client.
        $is_bare_audio_id = strpos($format_id, 'bestaudio') !== false
            || preg_match('/^(140|141|251|250|249|171|172|18|139)$/', $format_id);
        // audio-only if bare audio ID: no video stream to probe, skip ffprobe.
        $is_audio_only_format = $is_bare_audio_id;
        // Set probe_exit=0 upfront for skipped (audio-only) case — needed so the
        // refund condition (line ~4703) correctly treats "not run" as "success" (no refund).
        // When ffprobe runs, probe_exit is set inside the block below.
        $probe_exit = $is_audio_only_format ? 0 : -1;
        if (!$is_audio_only_format && !$is_bare_audio_id
            && is_file($actual_file) && is_executable($ffprobe_bin)) {
            // JSON probe — video stream only, no audio needed for substitution check.
            // Exit code 0 is required; ffprobe returns non-zero for unreadable files.
            $probe_cmd = [
                $ffprobe_bin,
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_entries', 'stream=codec_name,codec_type,width,height',
                '-select_streams', 'v:0',
                '--',
                $actual_file,
            ];
            $probe_out = '';
            $probe_err = '';
            // Default to -1 (failure sentinel) so that a failed proc_open (binary
            // missing/not executable at runtime) is correctly treated as a probe
            // failure rather than silently passing with exit=0.
            $probe_exit = -1;
            $probe_timed_out = false; // tracks whether ffprobe was killed by the timeout
            $probe_start = hrtime(true);
            $probe_timeout = FFPROBE_TIMEOUT; // outer kill timeout — ffprobe should finish in under 10s for any real file
            $probe_proc = proc_open($probe_cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $probe_pipes, null, [], ['bypass_shell' => true]);
            if ($probe_proc) {
                $probe_exit = 0; // proc_open succeeded — will be overwritten by proc_close
                fclose($probe_pipes[0]);
                unset($probe_pipes[0]);
                stream_set_timeout($probe_pipes[1], FFPROBE_TIMEOUT);
                stream_set_timeout($probe_pipes[2], FFPROBE_TIMEOUT);
                while (!feof($probe_pipes[1]) || !feof($probe_pipes[2])) {
                    // Outer timeout: ffprobe that takes >FFPROBE_TIMEOUT s is hung on a malformed/corrupt
                    // file. Terminate it rather than letting proc_close() block indefinitely.
                    if ((hrtime(true) - $probe_start) / 1e9 > $probe_timeout) {
                        proc_terminate($probe_proc, 9);
                        $probe_exit = -1;
                        $probe_timed_out = true; // distinguish from corrupt-file ffprobe failure
                        foreach ($probe_pipes as $p) { if ($p) fclose($p); }
                        $probe_pipes = null;
                        $probe_proc = null;  // sentinel: prevents double proc_close() below
                        break;
                    }
                    $read = [];
                    if (!feof($probe_pipes[1])) $read[] = $probe_pipes[1];
                    if (!feof($probe_pipes[2])) $read[] = $probe_pipes[2];
                    if (empty($read)) break;
                    $w = $e = null;
                    $changed = @stream_select($read, $w, $e, 1, 0);
                    if ($changed === false || $changed === 0) { usleep(100000); continue; }
                    foreach ($read as $p) {
                        if ($p === $probe_pipes[1]) {
                            $s = fread($p, 8192);
                            if ($s === false || $s === '') { if (feof($probe_pipes[1])) { fclose($probe_pipes[1]); $probe_pipes[1] = null; } continue; }
                            $probe_out .= $s;
                        } elseif ($p === $probe_pipes[2]) {
                            $s = fread($p, 8192);
                            if ($s === false || $s === '') { if (feof($probe_pipes[2])) { fclose($probe_pipes[2]); $probe_pipes[2] = null; } continue; }
                            $probe_err .= $s;
                        }
                    }
                    if ($probe_pipes[1] === null && $probe_pipes[2] === null) break;
                }
                if ($probe_pipes !== null) {
                    foreach ($probe_pipes as $p) { if ($p) fclose($p); }
                    $probe_pipes = null;
                    $probe_exit = ($probe_proc !== null) ? proc_close($probe_proc) : -1;
                }
            }
            if ($probe_exit === 0 && $probe_out) {
                $probe = @json_decode($probe_out, true);
                $vstream = $probe['streams'][0] ?? null;
                // ffprobe with -select_streams v:0 returns exit 0 even when no video
                // stream exists — it simply omits the streams key or returns [].
                // Treat this as a verification failure: the file has no video to deliver.
                if ($vstream) {
                    $actual_video_codec = $vstream['codec_name'] ?? null;
                    $actual_width = isset($vstream['width']) ? (int)$vstream['width'] : null;
                    $actual_height = isset($vstream['height']) ? (int)$vstream['height'] : null;
                    // Surface ffprobe verification outcome in response headers for client
                    // diagnostics. 'success' means ffprobe confirmed a video stream was
                    // present in the file. The failure case sets 'failed' in the early-exit
                    // block at line 4335.
                    header('X-FFProbe-Status: success');
                } else {
                    // ffprobe succeeded (exit 0, valid JSON) but found no video stream —
                    // the downloaded file is a broken container. Flag as verification
                    // failure so the quota is refunded and the user sees a clear error.
                    $probe_exit = -1;
                    $probe_err = 'No video stream found in downloaded file (malformed or empty container).';
                    // ffprobe exited 0 but found no streams — ffprobe itself did not "fail"
                    // per se, but verification could not be completed. Use 'skipped' to
                    // distinguish from a genuine ffprobe execution error (which sets
                    // 'failed' at line 4630).
                    header('X-FFProbe-Status: skipped');
                }
            } else {
                // ffprobe failed (non-zero exit, timeout, or unreadable output).
                // The file may be corrupt or the ffprobe binary may have failed.
                // When $probe_timed_out is set, ffprobe was killed because it exceeded
                // FFPROBE_TIMEOUT — this is a distinct failure mode from a corrupt file.
                // The yt-dlp download itself may have succeeded; the file just could not
                // be verified within the time limit. Return VERIFICATION_TIMEOUT so clients
                // can distinguish this from VERIFICATION_FAILED (actual file corruption).
                $is_verification_timeout = $probe_timed_out;
                if ($is_verification_timeout) {
                    $error_code = 'VERIFICATION_TIMEOUT';
                    $error_msg = 'Download verification timed out. The file may be valid but could not be confirmed within the server\'s verification time limit. Try a smaller format or try again.';
                } else {
                    $error_code = 'VERIFICATION_FAILED';
                    $error_msg = 'Download could not be verified. The file may be corrupt or the verification tool (ffprobe) encountered an error. Please try again or choose a different format.';
                }
                // Surface a structured error so the client can distinguish this from
                // a successful download, rather than silently sending an unverifiable file.
                // Refund the quota since the file could not be verified.
                $probe_err_clean = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $probe_err ?: ''));
                // Truncate ffprobe stderr for both the log entry and the JSON response.
                // Use strlen (byte count) not mb_strlen for the length check — $probe_err_clean
                // may contain invalid UTF-8 bytes after control-char stripping; mb_strlen with
                // UTF-8 mode can return false on invalid sequences, making the >150 comparison
                // unreliable. strlen() always returns the byte length regardless of encoding.
                if (strlen($probe_err_clean) > 150) {
                    $probe_err_truncated = substr($probe_err_clean, 0, 150) . '...';
                } else {
                    $probe_err_truncated = $probe_err_clean;
                }
                logRequest('download', 500, [
                    'reason' => 'ffprobe_verification_failed',
                    'format_id' => $format_id,
                    'ffprobe_exit' => $probe_exit,
                    'ffprobe_err' => $probe_err_truncated,
                ]);
                foreach (glob($glob_pattern) as $f) { @unlink($f); }
                // Refund quota inline — the conditional refund block below (which sets
                // $post_refund_count) is never reached due to this early exit, so compute
                // and apply the refund here before building the response.
                // $ffprobe_post_refund_count is computed first so it is available for
                // both the X-DailyLimit-Remaining header and the JSON body below.
                $ffprobe_post_refund_count = $unlimited ? $daily_limit : refundQuota($ip, $unlimited, $daily_limit, $dl_quota_before_refund);
                // Consistent header envelope with all other download error responses: include
                // X-DL-RateLimit-*, X-RateLimit-*, and X-DailyLimit-* headers so API clients
                // always have complete rate-limit context regardless of which error path they hit.
                // X-DL-RateLimit-*: reflects the download action's per-minute rate limit.
                // X-RateLimit-*: mirrors X-DL-RateLimit for generic API consumers.
                // X-DailyLimit-*: mirrors the free-tier daily quota; -1 for unlimited-key holders.
                if ($unlimited) {
                    header('X-DL-RateLimit-Limit: -1');
                    header('X-DL-RateLimit-Remaining: -1');
                    header('X-DL-RateLimit-Reset: -1');
                    header('X-DL-RateLimit-Window: unlimited');
                    header('X-RateLimit-Limit: -1');
                    header('X-RateLimit-Remaining: -1');
                    header('X-RateLimit-Reset: -1');
                    header('X-RateLimit-Window: unlimited');
                    header('X-DailyLimit-Limit: -1');
                    header('X-DailyLimit-Remaining: -1');
                    header('X-DailyLimit-Reset: -1');
                    header('X-DailyLimit-Window: unlimited');
                } else {
                    header('X-DL-RateLimit-Limit: ' . $dl_rate_limit);
                    header('X-DL-RateLimit-Remaining: ' . $dl_remaining);
                    header('X-DL-RateLimit-Reset: ' . $dl_reset);
                    header('X-DL-RateLimit-Window: ' . $dl_rate_window);
                    // X-RateLimit-*: mirrors the per-minute request rate limit (not the
                    // download-specific rate limit), consistent with the download-rate-limit
                    // 429 block at line ~3365 which also uses $rate_limit and $data.
                    header('X-RateLimit-Limit: ' . $rate_limit);
                    header('X-RateLimit-Remaining: ' . max(0, $rate_limit - $data['c']));
                    header('X-RateLimit-Reset: ' . $reset_timestamp);
                    header('X-RateLimit-Window: ' . $rate_window);
                    header('X-DailyLimit-Limit: ' . $daily_limit);
                    header('X-DailyLimit-Remaining: ' . $ffprobe_post_refund_count);
                    header('X-DailyLimit-Reset: ' . (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp());
                    header('X-DailyLimit-Window: 86400');
                }
                header('Cache-Control: no-store');
                header('X-Request-ID: ' . $request_id);
                header('X-FFProbe-Status: failed');
                header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
                // X-Info-Timeout: present on every download-action error response so clients
                // can set appropriate timeouts on retry (mirrors all other error paths).
                header('X-Info-Timeout: ' . INFO_TIMEOUT);
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
                header('Cross-Origin-Opener-Policy: same-origin');
                header('Cross-Origin-Resource-Policy: same-origin');
                header('X-Download-Options: noopen');
                header('X-Robots-Tag: noindex, noai, noimage, noydir');
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
                // VERIFICATION_TIMEOUT uses 504 to distinguish from VERIFICATION_FAILED (500).
                // Both are retryable, but 504 signals the verification step timed out
                // rather than finding a corrupt/unverifiable file.
                http_response_code($is_verification_timeout ? 504 : 500);
                // retry_after: delta-seconds until the download can be retried.
                // Per RFC 9110, Retry-After accepts either an HTTP-date or delta-seconds;
                // delta-seconds is simpler and consistent with all other Retry-After
                // headers in this file. Using DOWNLOAD_TIMEOUT (not time() + DOWNLOAD_TIMEOUT)
                // keeps this as a delta-seconds value.
                $retry_delta = DOWNLOAD_TIMEOUT;
                header('Retry-After: ' . max(0, $retry_delta));
                echo json_encode([
                    'error' => $error_msg,
                    'error_code' => $error_code,
                    'action' => 'download',
                    'upgrade_url' => UPGRADE_URL,
                    'hint' => $is_verification_timeout
                        ? 'Verification timed out — try a smaller format (audio-only is fastest) or try again shortly.'
                        : 'Download verification failed — try another format or try again in a moment.',
                    'retry_after' => max(0, $retry_delta),
                    'request_id' => $request_id,
                    'source_url' => $url,
                    'source_url_missing' => false,
                    'format_id' => $format_id,
                    'platform' => null,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
                    // quota_remaining/quota_limit/quota_reset: file was verified as corrupt/unverifiable,
                    // quota was refunded above. Unlimited-key holders ($unlimited=true) were never
                    // incremented, so quota fields use -1 sentinel values.
                    'quota_remaining' => $unlimited ? -1 : $ffprobe_post_refund_count,
                    'quota_limit' => $unlimited ? -1 : $daily_limit,
                    'quota_reset' => $unlimited ? -1 : (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp(),
                    'quota_reset_unix' => $unlimited ? -1 : (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp(),
                    // Surface ffprobe failure details for client diagnostics — the early-exit path
                    // (ffprobe exit !== 0) uses $probe_err_truncated (truncated to 150 bytes).
                    // The no-stream path (ffprobe exit === 0 but vstream missing) sets
                    // $probe_err to a descriptive string directly (short by nature).
                    'verification_error' => $probe_err_truncated ?? $probe_err ?? null,
                ], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            // Determine if substitution occurred by checking whether the requested format
            // materially differed from what was delivered. Only flag as substituted when
            // the actual height dropped by more than one quality tier (≥144p drop).
            // Parse requested height from format_id (e.g. "bestvideo[height>=1080]" → 1080).
            if ($actual_height !== null && $format_id !== 'best') {
                $requested_height = null;
                if (preg_match('/\[height(>=|<=|<|>)?(\d+)\]/', $format_id, $hm)) {
                    $requested_height = (int)$hm[2];
                    if ($hm[1] === '>=' || $hm[1] === '>') {
                        // Requested minimum; actual is substituted only if below that minimum
                        if ($actual_height < $requested_height) {
                            $format_substituted = true;
                        }
                    } elseif ($hm[1] === '<=') {
                        // Requested maximum; actual above it means yt-dlp upgraded
                        if ($actual_height > $requested_height) {
                            $format_substituted = true;
                        }
                    } elseif ($hm[1] === '<') {
                        // Requested strict maximum; actual at or above it means yt-dlp upgraded
                        if ($actual_height >= $requested_height) {
                            $format_substituted = true;
                        }
                    } elseif ($hm[1] === null) {
                        // Exact match (no operator, e.g. "22" or "bestvideo[height=720]");
                        // $requested_height was set from the captured \d+, so any difference is substitution.
                        if ($actual_height !== $requested_height) {
                            $format_substituted = true;
                        }
                    } else {
                        // Unrecognized operator — flag as substituted to be safe.
                        // This future-proofs against new yt-dlp format selectors.
                        $format_substituted = true;
                    }
                }
                // Also flag substitution when the actual stream height is suspiciously
                // low (<180p). This catches bare format IDs like "22" (YouTube 720p)
                // where $requested_height is null (no height constraint in format ID),
                // but ffprobe detected video with an unexpectedly low resolution.
                // PHP's null !== null is false, so this never fires spuriously for
                // null actual_height (audio-only files have actual_height = null).
                if (!$format_substituted && $actual_height !== null && $actual_height < 180) {
                    $format_substituted = true;
                }
            }
            // Flag substitution when the extension changed (e.g. webm → mkv) —
            // this usually means yt-dlp had to use a different container.
            if (!$format_substituted && $ext !== '') {
                $requested_ext = null;
                if (preg_match('/\[ext=([^\]]+)\]/', $format_id, $em)) {
                    $requested_ext = $em[1];
                    if ($requested_ext !== $ext) {
                        $format_substituted = true;
                    }
                }
            }
            if ($format_substituted && $actual_height !== null) {
                $substituted_label = ($actual_width && $actual_height)
                    ? "{$actual_width}x{$actual_height}"
                    : "{$actual_height}p";
                if ($actual_video_codec) {
                    $substituted_label .= " {$actual_video_codec}";
                }
            }
        }

        // Refund daily quota if ffprobe verification failed or was skipped — the file was
        // downloaded successfully but ffprobe could not verify the codec/resolution (e.g. ffprobe
        // timed out on a corrupt file, or the binary was not executable or not found).
        // Since the substitution-detection info is unreliable in these cases, the user
        // effectively received the same outcome as if no ffprobe had run.
        // Refunding is the consistent choice: we refund on all yt-dlp failures regardless of
        // classified/unclassified status, so ffprobe failures (which are outside the user's
        // control) deserve the same treatment.
        // Skip when: ffprobe succeeded ($probe_exit === 0), audio-only (no probe ran),
        // or user is unlimited-key holder ($unlimited=true — never had quota incremented).
        // $probe_exit is only set inside the ffprobe block (line 4245), so isset() distinguishes
        // "ffprobe ran and exited 0" (no refund) from "ffprobe ran and failed" or "ffprobe
        // was skipped" (both get a refund). The distinction between failure and skip is
        // made by the probe_exit value: 0 = success (no refund), -1 or non-zero = fail/refund.
        // A non-existent FFPROBE_PATH causes is_executable() to return false, the if block
        // is never entered, $probe_exit is never set, and this refund fires — correct
        // behavior since the user shouldn't be charged when ffprobe couldn't even be attempted.
        $ffprobe_ok = isset($probe_exit) && $probe_exit === 0;
        if (!$ffprobe_ok && !$unlimited && isset($dl_quota_before_refund)) {
            $post_refund_count = refundQuota($ip, $unlimited, $daily_limit, $dl_quota_before_refund);
        }
        // Surface ffprobe verification outcome in response headers for client diagnostics.
        // Matches the failure header set in the early-exit block at line 4335.
        // X-Request-ID is always set on every API response; add it here for consistency
        // with all other download response paths (empty-file, timeout, proc failure, etc.).
        // NOTE: Connection: close was already sent before the streaming loop (line 4864).
        header('X-FFProbe-Status: ' . ($ffprobe_ok ? 'success' : 'skipped'));
        header('X-Request-ID: ' . $request_id);
        header('Retry-After: 0');

        // Detect MIME type for Content-Type header.
        // finfo is the authoritative source — it reads the file's actual magic bytes.
        // Fall back to extension-based mapping when finfo is unavailable, returns a
        // generic type (e.g. application/octet-stream for unknown binary files),
        // or fails for any reason.
        $mime = 'application/octet-stream';
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($actual_file);
        if ($detected !== false && strpos($detected, '/') !== false) {
            // Only accept finfo's result if it returned a real MIME type
            // (not the generic application/octet-stream fallback).
            // Generic octet-stream means finfo couldn't determine a specific type —
            // extension-based detection is more useful in that case.
            if ($detected !== 'application/octet-stream') {
                $mime = $detected;
            }
        }
        // Extension-based fallback when finfo is unavailable, fails, or returns
        // the generic fallback. This correctly identifies common media types that
        // finfo sometimes fails on (e.g. partially-downloaded files, rare containers).
        if ($mime === 'application/octet-stream' && $ext !== '') {
            $ext_lower = strtolower($ext);
            $ext_mimes = [
                'mp4'  => 'video/mp4',
                'm4a'  => 'audio/mp4',
                'webm' => 'video/webm',
                'mkv'  => 'video/x-matroska',
                'mov'  => 'video/quicktime',
                'avi'  => 'video/x-msvideo',
                'flv'  => 'video/x-flv',
                'wmv'  => 'video/x-ms-wmv',
                'mp3'  => 'audio/mpeg',
                'opus' => 'audio/opus',
                'ogg'  => 'audio/ogg',
                'flac' => 'audio/flac',
                'wav'  => 'audio/wav',
                'aac'  => 'audio/aac',
                'm4b'  => 'audio/mp4',
                'weba' => 'audio/webm',
            ];
            if (isset($ext_mimes[$ext_lower])) {
                $mime = $ext_mimes[$ext_lower];
            }
        }

        header('Content-Length: ' . $filesize);
        // Send RFC 5987 filename encoding so non-ASCII characters in the derived
        // filename are handled correctly across browsers (RFC 5987 = UTF-8 encoded
        // filename*=utf-8''...). The ascii-check prevents double-encoding plain ASCII.
        $dl_raw = $download_name;
        $needs_encoding = preg_match('/[^\x00-\x7F]/', $dl_raw);
        if ($needs_encoding) {
            $encoded = rawurlencode($dl_raw);
            // filename= must be ASCII-only per RFC 2616/6266 — percent-encode
            // non-ASCII bytes so the fallback is safe for all HTTP implementations.
            // filename*= carries the canonical UTF-8 value per RFC 5987.
            $ascii_fallback = preg_replace_callback('/[^\x00-\x7F]/', function($m) {
                return rawurlencode($m[0]);
            }, $dl_raw);
            $disposition = "attachment; filename*=UTF-8''{$encoded}; filename=\"{$ascii_fallback}\"";
        } else {
            $disposition = "attachment; filename=\"{$dl_raw}\"";
        }
        header('Content-Disposition: ' . $disposition);
        // no-store: do not cache this response — it is a file download and must not
        // be stored by shared caches (proxies, corporate proxies, CDNs).
        // must-revalidate: once the file is delivered, caches must not serve the stale
        // file without revalidating. This is the correct directive for file downloads.
        // (no-cache would allow serving from cache after revalidation, which is wrong for
        // on-demand generated file downloads that are not cacheable by definition.)
        header('Cache-Control: no-store, must-revalidate');
        // Accept-Ranges: none — this response is a full-file download with no seeking.
        // Explicitly disabling range requests prevents proxies and browser caches from
        // attempting to resume or partial-fetch the download, which could corrupt the
        // streamed file delivery. The PHP layer already disables output buffering and
        // sends Content-Length, making range requests unnecessary and potentially harmful.
        header('Accept-Ranges: none');
        // X-Format-Substituted: set when ffprobe detects the downloaded file differs
        // materially from what was requested (different resolution or container).
        // The frontend uses this to show "Downloaded 720p (requested 1080p — not available)"
        // instead of silently giving the user a lower quality than they selected.
        if ($format_substituted) {
            header('X-Format-Substituted: ' . ($substituted_label ?? 'true'));
        }
        // Content-Type and X-Download-Options are set immediately before streaming
        // so that error response paths above (empty-file, timeout, proc failure)
        // return with the default Content-Type: application/json from the top of
        // the script rather than application/octet-stream.
        // X-Download-Timeout is set here (before the streaming loop) so it is always
        // present on successful download responses. Without it, client-side fetch
        // timeouts may be misconfigured (hardcoded values that don't match the server
        // deadline), causing premature client-side aborts that waste server resources.
        // The value is in seconds (integer).
        header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);

        // Suppress SIGPIPE so that a client abort during the streaming loop does
        // not kill the PHP process. Without this, writing to a closed connection
        // sends SIGPIPE to the process, which terminates it ungracefully (the
        // connection_aborted() check happens on the NEXT iteration, not before
        // echo, so the first SIGPIPE can still fire). Using pcntl_signal(SIGPIPE, SIG_IGN)
        // requires pcntl extension; guard with function_exists as a hard requirement.
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGPIPE, SIG_IGN);
        }
        ignore_user_abort(true);

        $mem_set = ini_set('memory_limit', '256M');
        if ($mem_set === false) {
            error_log("AhoyRipper: ini_set('memory_limit', '256M') failed — check disable_functions or open_basedir restrictions");
        }

        // Set download-specific headers just before streaming — ensures error
        // responses above return JSON with default Content-Type, not binary.
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('X-Download-Options: noopen');
        // X-Info-Timeout: present on every download-action response (success and error).
        // Consistent with all other download paths which include this header so clients
        // can always determine the info timeout from response headers without parsing
        // the JSON body or checking a separate /info endpoint.
        header('X-Info-Timeout: ' . INFO_TIMEOUT);
        // Suppress PHP's automatic chunked transfer encoding for binary streams.
        // PHP adds Transfer-Encoding: chunked for large responses; identity
        // forces raw bytes so the Content-Length header is respected.
        header('Transfer-Encoding: identity');
        // Explicitly close connection after this response to prevent keep-alive
        // issues where long-running downloads cause premature client cut-off.
        // This is set here (not earlier in the action) so that early-exit error
        // responses (429, 504, 500) are not affected — those must leave the
        // connection open so the client can read the full JSON error body.
        header('Connection: close');

        $fp = fopen($actual_file, 'rb');
        if (!$fp) {
            // Content-Type was already set to the detected MIME above; override
            // back to JSON so the error response has the correct Content-Type.
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            header('X-Request-ID: ' . $request_id);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Cache-Control: no-store');
            header('Reporting-Endpoints: csp-report="/csp-report"');
            header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
            header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; report-to csp-report;');
            // X-RateLimit-*: download action consumed the per-minute request rate limit
            // (info call was made). Use $rate_window (60s) — the request-level window,
            // not $dl_rate_window (60s). Consistent with VERIFICATION_FAILED block.
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: ' . $rate_window);
            // X-DL-RateLimit-*: download-specific rate limit was consumed when the
            // file was successfully written by yt-dlp, even though it could not be
            // read back for streaming. Use the actual post-consumption values.
            if ($unlimited) {
                header('X-DL-RateLimit-Limit: -1');
                header('X-DL-RateLimit-Remaining: -1');
                header('X-DL-RateLimit-Reset: -1');
                header('X-DL-RateLimit-Window: unlimited');
            } else {
                header('X-DL-RateLimit-Limit: ' . $dl_rate_limit);
                header('X-DL-RateLimit-Remaining: ' . max(0, $dl_rate_limit - $dl_data['c']));
                header('X-DL-RateLimit-Reset: ' . $dl_reset);
                header('X-DL-RateLimit-Window: ' . $dl_rate_window);
            }
            // X-DailyLimit-*: daily quota was charged when yt-dlp wrote the file.
            // Use post-refund values so the client sees current remaining quota.
            header('X-DailyLimit-Limit: ' . (!$unlimited ? $daily_limit : -1));
            header('X-DailyLimit-Remaining: ' . (!$unlimited ? $post_refund_count : -1));
            header('X-DailyLimit-Reset: ' . (!$unlimited ? $quota_reset_ts : -1));
            header('X-DailyLimit-Window: ' . (!$unlimited ? '86400' : 'unlimited'));
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            $retry_delta = DOWNLOAD_TIMEOUT;
            header('Retry-After: ' . max(0, $retry_delta));
            echo json_encode([
                'error' => 'Failed to read downloaded file.',
                'error_code' => 'FILE_READ_ERROR',
                'action' => 'download',
                'upgrade_url' => UPGRADE_URL,
                'hint' => 'The server temporarily could not read the downloaded file. Try again — if it persists, the file may be too large for the server to handle.',
                'retry_after' => max(0, DOWNLOAD_TIMEOUT),
                'request_id' => $request_id,
                'source_url' => $url,
                'source_url_missing' => false,
                'format_id' => $format_id,
                'format_id_missing' => false,
                'platform' => null,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                // quota fields: included for consistency with all other error responses.
                // The file was downloaded by yt-dlp (quota was charged) but could not be
                // read back for streaming — this is a server-side issue, not a quota problem.
                // Quota was not refunded here since the download itself succeeded.
                'quota_remaining' => !$unlimited ? $post_refund_count : -1,
                'quota_limit' => !$unlimited ? $daily_limit : -1,
                'quota_reset' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
                'quota_reset_unix' => !$unlimited ? (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp() : -1,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        while (!feof($fp) && !connection_aborted()) {
            $chunk = fread($fp, 65536);
            if ($chunk === false || $chunk === '') {
                // Read error or connection closed — stop streaming and mark cancelled.
                // Do NOT treat this as a quota-burning failure; the client simply gave up.
                fclose($fp);
                if ($actual_file && file_exists($actual_file)) { @unlink($actual_file); }
                logRequest('download', 499, ['reason' => 'connection_aborted', 'filesize_bytes_partial' => $filesize]);
                // HTTP 499 (Client Closed Request) — set explicitly since this is a
                // distinct error code in err_status_map but was not being applied here.
                // All other 4xx/5xx responses in this file call http_response_code() directly.
                http_response_code(499);
                // Content-Type was already set to binary MIME above; override
                // back to JSON so the error response has the correct Content-Type.
                header('Content-Type: application/json; charset=utf-8');
                // Full security headers — required on all responses including errors.
                header('X-Request-ID: ' . $request_id);
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
                header('Cross-Origin-Opener-Policy: same-origin');
                header('Cross-Origin-Resource-Policy: same-origin');
                header('X-Download-Options: noopen');
                header('X-Robots-Tag: noindex, noai, noimage, noydir');
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
                header('Cache-Control: no-store');
                header('X-RateLimit-Limit: -1');
                header('X-RateLimit-Remaining: -1');
                header('X-RateLimit-Reset: -1');
                header('X-RateLimit-Window: unavailable');
                // Download-specific rate limit: client aborted before the file was fully
                // sent — the quota was charged when yt-dlp completed the download, so
                // the actual post-consumption values apply here (same as FILE_READ_ERROR).
                if ($unlimited) {
                    header('X-DL-RateLimit-Limit: -1');
                    header('X-DL-RateLimit-Remaining: -1');
                    header('X-DL-RateLimit-Reset: -1');
                    header('X-DL-RateLimit-Window: unlimited');
                } else {
                    header('X-DL-RateLimit-Limit: ' . $dl_rate_limit);
                    header('X-DL-RateLimit-Remaining: ' . max(0, $dl_rate_limit - $dl_data['c']));
                    header('X-DL-RateLimit-Reset: ' . $dl_reset);
                    header('X-DL-RateLimit-Window: ' . $dl_rate_window);
                }
                // X-DailyLimit-*: daily quota was charged when yt-dlp completed the download.
                // Include post-refund values for consistency with other download error responses.
                header('X-DailyLimit-Limit: ' . (!$unlimited ? $daily_limit : -1));
                header('X-DailyLimit-Remaining: ' . (!$unlimited ? $post_refund_count : -1));
                header('X-DailyLimit-Reset: ' . (!$unlimited ? $quota_reset_ts : -1));
                header('X-DailyLimit-Window: ' . (!$unlimited ? '86400' : 'unlimited'));
                // X-Info-Timeout and X-Download-Timeout: present on all API responses.
                // Both are included for consistency with other download action responses.
                header('X-Info-Timeout: ' . INFO_TIMEOUT);
                header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
                // Use DOWNLOAD_TIMEOUT (not 0) to prevent clients from rapid-retrying
                // a cancelled download. FILE_READ_ERROR uses the same value for the same
                // reason — the download was partially or fully consumed; retry immediately
                // is not appropriate in either case. The Retry-After HTTP header and
                // retry_after JSON field are intentionally kept in sync.
                header('Retry-After: ' . DOWNLOAD_TIMEOUT);
                echo json_encode([
                    'error' => 'Download cancelled by client.',
                    'error_code' => 'DOWNLOAD_CANCELLED',
                    'action' => 'download',
                    'upgrade_url' => UPGRADE_URL,
                    'hint' => 'Download was cancelled — you may have closed the tab or lost connection. Your daily quota was not charged. Try again when ready.',
                    'retry_after' => max(0, DOWNLOAD_TIMEOUT),
                    'request_id' => $request_id,
                    'source_url' => $url,
                    'source_url_missing' => false,
                    'format_id' => $format_id,
                    'format_id_missing' => false,
                    'platform' => null,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
                    // quota fields: included for consistency with all other download error responses.
                    // Quota was not charged since no usable file was received.
                    'quota_remaining' => $unlimited ? -1 : $post_refund_count,
                    'quota_limit' => $unlimited ? -1 : $daily_limit,
                    'quota_reset' => $unlimited ? -1 : (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp(),
                    'quota_reset_unix' => $unlimited ? -1 : (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp(),
                ], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            echo $chunk;
            flush();
        }
        fclose($fp);
        // Detect client abort AFTER the loop — feof() exits when the client disconnects,
        // so connection_aborted() here catches the abort cleanly. An aborted transfer
        // means the client gave up; no quota is burned since no usable file was received.
        // NOTE: Connection: close was already sent before the streaming loop (line 4864).
        // The server will close the connection immediately after the last chunk is sent.
        // Sending a JSON error body after binary data on a half-closed connection is
        // at best a protocol violation and at worst causes the JSON to be received as
        // trailing garbage by proxies or clients that don't close immediately. Skip
        // the JSON response — the client already received partial binary data and any
        // retry logic should be handled by the caller, not the server.
        if (connection_aborted()) {
            if ($actual_file && file_exists($actual_file)) { @unlink($actual_file); }
            logRequest('download', 499, ['reason' => 'connection_aborted', 'filesize_bytes_partial' => $filesize]);
            exit;
        }
        // Shutdown function handles unlink; call it explicitly on success
        if ($actual_file && file_exists($actual_file)) {
            @unlink($actual_file);
        }
        logRequest('download', 200, ['filesize_bytes' => $filesize, 'format_id' => $format_id]);
        exit;
    }

    case 'check': {
        // Minimal ping — zero dependency on yt-dlp, ffmpeg, or /proc/sys calls.
        // Intentionally omit: server_uptime, load_avg, memory, disk_free, versions.
        // Docker healthchecks and load-balancer probes should use this, not health.
        // All other security headers (HSTS, X-Frame-Options, Referrer-Policy,
        // Permissions-Policy) are set at the top of api.php, but this action
        // bypasses that block by sending its own echo+break — so set them here
        // too so check responses are always fully hardened regardless of how
        // the endpoint is served (nginx, PHP built-in server, reverse proxy, etc.).
        // Compute quota_reset locally — the health action's $quota_reset_ts/$quota_reset_iso
        // are defined inside that case block and not in scope here.
        $quota_reset_ts = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp();
        $quota_reset_iso = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->format('c');
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Download-Options: noopen');
        header('X-Robots-Tag: noindex, noai, noimage, noydir');
        header('X-Request-ID: ' . $request_id);
        header('X-Info-Timeout: ' . INFO_TIMEOUT);
        header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        // Cache-Control: no-store — check is a live system probe; responses must
        // not be cached by intermediaries (CDNs, proxies) since system state is
        // trivial and changes on every call. Mirrors the same header set in the
        // 'health' action (line ~5625) and the top-of-script default (line ~178).
        header('Cache-Control: no-store');
        // Connection: close is intentionally NOT set — the check endpoint is a
        // lightweight JSON ping meant for frequent calls (Docker healthchecks every
        // 10s, load-balancer probes). Closing the connection forces a new TCP
        // handshake on every request, negating keep-alive pooling benefits.
        // See lines 323-328 for the full rationale.
        // Set the same CSP and Reporting-Endpoints headers that the top-of-script
        // block applies to all other responses. api.php sets these globally but
        // the 'check' action sends its own response via echo+break and therefore
        // bypasses that block — repeat them here so check responses are fully
        // hardened (especially important since this endpoint is used by Docker
        // healthchecks and load-balancer probes that may route around the normal
        // nginx security-header stack). X-Powered-By is already removed at the
        // top of the script, so no need to repeat it here.
        //
        // NOTE: 'upgrade-insecure-requests' is ABSENT from this JSON API endpoint.
        // That directive only applies to HTML documents (instructs the browser to
        // upgrade HTTP→HTTPS for all subresources). For a JSON API response it has
        // no effect and could cause unexpected browser behavior. It belongs only in
        // HTML page CSP headers (nginx-level for the root location, and api.php's
        // top-of-script block for HTML error responses).
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'; report-to csp-report;');
        header('Reporting-Endpoints: csp-report="/csp-report"');
        header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
        // The check action is a lightweight ping with zero dependency on yt-dlp,
        // ffmpeg, or /proc/sys. Rate-limit headers are included (with -1/unlimited
        // sentinel values) so clients can distinguish this endpoint from /download
        // without needing to interpret different response shapes.
        // download rate limit: check is not a download action, so -1 (no limit)
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unlimited');
        // Standard rate-limit header family for generic API consumers.
        // X-RateLimit-Limit: -1 = no rate limit applies (convention: -1 means
        // "unlimited", 0 means "limit exhausted"). Mirrors X-DL-RateLimit-Limit.
        header('X-RateLimit-Limit: -1');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unlimited');
        // Daily-limit sentinels (-1) signal clients this is a read-only probe,
        // not a rip-consuming action — mirrors the pattern used by action=health.
        header('X-DailyLimit-Limit: -1');
        header('X-DailyLimit-Remaining: -1');
        header('X-DailyLimit-Reset: -1');
        header('X-DailyLimit-Window: unlimited');
        // no-store: consistent with all other API responses — prevents intermediate
        // proxies (CDN, corporate proxies, load balancers) from caching this response.
        // no-cache would allow caching while revalidating on every request, which is
        // unnecessary for a stateless JSON ping and inconsistent with the rest of
        // the API surface (all other responses use no-store).
        header('Cache-Control: no-store');
        // Retry-After: 0 — check is a read-only probe with no server-side backoff;
        // the client should retry immediately. Mirrors the same pattern in action=health
        // (which also uses Retry-After: 0 alongside X-*-Limit: -1 sentinels).
        header('Retry-After: 0');
        // yt_dlp_version is included in the check response for consistency with
        // health/info/download responses. The version string is pre-cached before
        // the routing switch (lines 535-583) so no additional subprocess call is
        // needed here — $GLOBALS['__ytdlp_version'] is already set.
        echo json_encode([
            'status' => 'ok',
            'action' => 'check',
            'server_time' => date('c'),
            'server_time_unix' => time(),
            'request_id' => $request_id,
            'app_version' => AHOYRIPPER_VERSION,
            'php_version' => PHP_VERSION,
            'api_version' => AHOYRIPPER_VERSION,
            'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
            // yt_dlp_ok: true when yt-dlp binary is installed and callable.
            // Mirrors the field in action=health so monitoring scripts that hit
            // action=check (the lightweight no-probe endpoint) can determine binary
            // status without parsing the version string.
            'yt_dlp_ok' => !empty($GLOBALS['__ytdlp_version']) && strpos($GLOBALS['__ytdlp_version'], 'not installed') === false,
            // ffprobe_version: version string for the ffprobe binary (part of ffmpeg suite).
            // Mirrors the field in action=health for consistency across all endpoints.
            'ffprobe_version' => $GLOBALS['__ffmpeg_version'] ?? null,
            // ffmpeg_ok: true when ffprobe binary is installed and callable.
            // Mirrors the field in action=health so monitoring can confirm ffprobe
            // availability without parsing the version string.
            'ffmpeg_ok' => !empty($GLOBALS['__ffmpeg_version']) && strpos($GLOBALS['__ffmpeg_version'], 'not installed') === false,
            // Daily quota fields — check is a read-only probe (does not consume quota)
            // so quota_remaining is -1 (unlimited signal). quota_limit mirrors the
            // configured daily limit for API surface consistency with info/download
            // responses, allowing clients to always determine the limit from the body.
            // quota_reset and quota_reset_unix are always valid timestamps (never -1)
            // per API contract — even read-only probes return the next reset time.
            'quota_remaining' => -1,
            'quota_limit' => getDailyQuotaLimit(),
            'quota_reset' => $quota_reset_iso,
            'quota_reset_unix' => $quota_reset_ts,
            // source_url: null — check is a read-only server probe with no source video URL.
            // source_url_missing: true — no video URL was provided (probe endpoint).
            'source_url' => null,
            'source_url_missing' => true,
            // upgrade_url: AhoyVPN upsell URL on all API responses for consistent
            // upsell opportunity. Mirrors the same field in the health response.
            'upgrade_url' => UPGRADE_URL,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    }
    // Returns server system metrics: uptime (seconds), load avg (1-min avg from
    // /proc/loadavg), memory available (%), disk free GB. Each field is null on
    // failure so the health endpoint degrades gracefully on restricted containers.
    function getSystemMetrics() {
        $metrics = [
            'server_uptime_seconds' => null,
            'load_avg' => null,
            'memory_available_pct' => null,
            'disk_total_gb' => null,
            'disk_free_gb' => null,
            'disk_free_pct' => null,
        ];
        // Uptime: /proc/uptime is text file, first token is seconds.
        // Falls back to PHP's $_SERVER['REQUEST_TIME'] (relative to request, not boot).
        @[$up] = explode(' ', @file_get_contents('/proc/uptime') ?: '', 2);
        if ($up !== null) {
            $metrics['server_uptime_seconds'] = (int)floor((float)$up);
        }
        // Load avg: /proc/loadavg has three values (1/5/15 min). Use 1-min for responsiveness.
        @[$l1] = explode(' ', @file_get_contents('/proc/loadavg') ?: '', 1);
        if ($l1 !== null) {
            $metrics['load_avg'] = (float)$l1;
        }
        // Memory: /proc/meminfo. Parse "MemAvailable:" (available, not just free).
        // Not all kernels have MemAvailable; fall back to MemFree if unavailable.
        $mem_content = @file_get_contents('/proc/meminfo') ?: '';
        if ($mem_content) {
            $avail = $total = null;
            foreach (explode("\n", $mem_content) as $line) {
                if (preg_match('/^(MemAvailable|MemTotal|MemFree):\s+(\d+)/', $line, $m)) {
                    $kb = (int)$m[2];
                    if ($m[1] === 'MemAvailable') {
                        $avail = $kb;
                    } elseif ($m[1] === 'MemTotal') {
                        $total = $kb;
                    } elseif ($m[1] === 'MemFree') {
                        // Only used as last-resort fallback when MemAvailable is absent.
                        if ($avail === null) {
                            $avail = $kb;
                        }
                    }
                }
            }
            if ($total !== null && $total > 0 && $avail !== null) {
                $metrics['memory_available_pct'] = round(($avail / $total) * 100, 1);
            }
        }
        // Disk: check the /tmp partition (where logs and caches live) rather than
        // root — a separate /tmp mount is common in containerized deployments.
        // disk_free_space() returns bytes available to the caller (accounting for
        // filesystem quotas and reserved blocks); disk_total_space() returns total
        // bytes in the partition. Together they derive disk_free_pct, which is
        // more immediately useful than free bytes alone (1 GB free could be 1%
        // on a 100 GB partition or 99% on a 1.1 GB partition).
        $df = @disk_free_space('/tmp');
        $dt = @disk_total_space('/tmp');
        if ($df !== false) {
            $metrics['disk_free_gb'] = round($df / (1024 ** 3), 2);
        }
        if ($dt !== false && $dt > 0) {
            $metrics['disk_total_gb'] = round($dt / (1024 ** 3), 2);
            if ($df !== false) {
                $metrics['disk_free_pct'] = round(($df / $dt) * 100, 1);
            }
        }
        return $metrics;
    }

    // Receives client-side JavaScript error reports from the frontend.
    // Enables server-side operational monitoring of uncaught JS exceptions,
    // fetch failures, and unhandled promise rejections — supplementing the
    // browser-side page_request_id correlation that already exists for support tickets.
    //
    // POST-only: errors are one-way signals (fire-and-forget), no response body needed.
    // No referer check: errors may arrive from contexts where the referer header
    // is absent or stripped (e.g., PWA standalone mode, browser extensions).
    // No daily-quota or rate-limit overhead: this endpoint is read-only and costs
    // nothing on the server (just a file write to the request log).
    case 'client-error': {
        // Method validation — client-error receives POST from navigator.sendBeacon.
        // Rejecting non-POST with a clear 405 gives clients an unambiguous signal.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Request-ID: ' . $request_id);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            // Set the same CSP and Reporting-Endpoints headers that the top-of-script
            // block applies to all other responses. Mirrors the csp-report and analytics
            // POST-gate 405 responses for consistency.
            header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');
            header('Reporting-Endpoints: csp-report="/csp-report"');
            header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
            // Rate-limit sentinels: -1 = not applicable (fire-and-forget endpoint).
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: unlimited');
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unavailable');
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
            // Timeout headers: client-error is a fire-and-forget endpoint (no yt-dlp
            // involvement) but X-Info-Timeout/X-Download-Timeout are included for
            // complete API surface parity — clients can always find these headers.
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            echo json_encode([
                'error' => 'Method not allowed. Use POST for action=client-error.',
                'error_code' => 'METHOD_NOT_ALLOWED',
                'action' => $action,
                'request_id' => $request_id,
                'api_version' => AHOYRIPPER_VERSION,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'upgrade_url' => UPGRADE_URL,
                'retry_after' => 0,
                'source_url' => null,
                'source_url_missing' => false,
                'format_id_missing' => false,
                'quota_remaining' => -1,
                'quota_limit' => -1,
                'quota_reset' => -1,
                'quota_reset_unix' => -1,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }

        // Always return 200 so the browser doesn't retry failed reports.
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Download-Options: noopen');
        header('X-Robots-Tag: noindex, noai, noimage, noydir');
        header('X-Request-ID: ' . $request_id);
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cache-Control: no-store');
        // Reporting-Endpoints + Report-To: enables the browser's Reporting API for CSP
        // violation reports from this endpoint. Matches the headers set by every other API
        // response path (top-of-script, client-error 405 block, csp-report, analytics).
        header('Reporting-Endpoints: csp-report="/csp-report"');
        header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
        // Rate-limit headers: -1 sentinel (unlimited) since client-error is a read-only
        // fire-and-forget endpoint that does not consume from the per-minute rate budget.
        // Mirrors the pattern used by action=check and action=health for consistency.
        // X-DL-RateLimit-*: download-specific rate limit (not applicable here, so -1).
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unlimited');
        // X-RateLimit-*: generic rate-limit header family for API consumers.
        header('X-RateLimit-Limit: -1');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unlimited');
        // X-DailyLimit-*: daily quota sentinel (-1 = not applicable to this endpoint).
        header('X-DailyLimit-Limit: -1');
        header('X-DailyLimit-Remaining: -1');
        header('X-DailyLimit-Reset: -1');
        header('X-DailyLimit-Window: unlimited');
        // X-Info-Timeout: the client-error action is a fire-and-forget endpoint that
        // does not involve yt-dlp directly, but X-Info-Timeout is included for
        // consistency with the rest of the API surface. API consumers inspecting headers
        // will always find this field present, simplifying generic response parsers.
        header('X-Info-Timeout: ' . INFO_TIMEOUT);
        // X-Download-Timeout: also present for consistency — client-error does not
        // involve yt-dlp, but X-Download-Timeout is included on all API responses
        // so generic response parsers can always find this field without special-casing.
        header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
        // CSP: same policy as every other API response. The client-error endpoint
        // bypasses the top-of-script header block by sending its own — repeat them here
        // so client-error POST responses are fully hardened regardless of how this action
        // is served (nginx, PHP built-in server, reverse proxy, etc.).
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        // Guard against null (json_decode failure) or non-array input (e.g. empty body).
        // Every other json_decode call site in this file has a similar guard — this
        // action was missing one, causing PHP warnings on malformed POST bodies.
        if ($data === null || !is_array($data)) {
            $data = [];
        }

        // Build a structured log entry from the error payload.
        // Fields: message (string, required), stack (string, optional), type
        // (string: Error|TypeError|SyntaxError|etc), page_request_id (string,
        // optional — correlates with server-side access logs), url (string,
        // optional — URL of the page where the error occurred), line (int,
        // optional), col (int, optional).
        // All string fields are truncated to 500 chars to prevent log flooding.
        $entry = [
            'ts' => date('c'),
            'req_id' => $request_id,
            'page_req_id' => is_string($data['page_request_id'] ?? null)
                ? substr($data['page_request_id'], 0, 32) : null,
            'type' => is_string($data['type'] ?? null)
                ? substr($data['type'], 0, 80) : 'unknown',
            'msg' => is_string($data['message'] ?? null)
                ? substr($data['message'], 0, 500) : null,
            'url' => is_string($data['url'] ?? null)
                ? substr($data['url'], 0, 500) : null,
            'line' => is_int($data['line'] ?? null) ? $data['line'] : null,
            'col' => is_int($data['col'] ?? null) ? $data['col'] : null,
            'stack' => is_string($data['stack'] ?? null)
                ? substr($data['stack'], 0, 1000) : null,
            'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        ];

        logRequest('client-error', 200, $entry);

        // Consistent JSON response with api_version and request_id for API surface parity.
        // retry_after: 0 — client-error is a fire-and-forget endpoint that does not
        // consume rate limit or quota budget, so no backoff is needed on the client.
        // upgrade_url: included on all API responses for consistent upsell opportunity.
        echo json_encode([
            'ok' => true,
            'request_id' => $request_id,
            'api_version' => AHOYRIPPER_VERSION,
            'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
            'upgrade_url' => UPGRADE_URL,
            'retry_after' => 0,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }

    case 'progress':
    case 'health': {
        // Health/progress — full system status with resource metrics.
        // Note: most security headers are set at the top of the script, but
        // this action bypasses that block by sending its own response — so
        // set the full security header family here too so health responses
        // are always fully hardened regardless of how the endpoint is served
        // (nginx, PHP built-in server, reverse proxy, etc.). This mirrors the
        // approach taken by the 'check' action at line ~4796.
        header('Content-Type: application/json; charset=utf-8');
        header('X-Info-Timeout: ' . INFO_TIMEOUT);
        header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
        // CSP and Reporting headers — set here explicitly (not relying on the
        // top-of-script block) so health responses are always fully hardened.
        // NOTE: 'upgrade-insecure-requests' is intentionally ABSENT from this
        // JSON API endpoint — that directive only applies to HTML documents.
        // It has no effect on JSON responses and could cause unexpected browser
        // behavior if left in. It belongs only in HTML page CSP headers.
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'; report-to csp-report;');
        header('Reporting-Endpoints: csp-report="/csp-report"');
        header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
        // Remaining security headers — the top-of-script block sets these globally
        // but 'health'/'progress' bypasses that block by sending its own headers,
        // so set them here too for consistent hardening. Mirrors the 'check' action
        // pattern at line ~4859 which sets the same full header family explicitly.
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Download-Options: noopen');
        header('X-Robots-Tag: noindex, noai, noimage, noydir');
        header('X-Request-ID: ' . $request_id);
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        // Cache-Control: no-store — health is a live system probe; responses must
        // not be cached by intermediaries (CDNs, proxies) since system state is
        // trivial and changes on every call. Mirrors the same header set in the
        // 'check' action (line ~5266) and the top-of-script default (line ~178).
        header('Cache-Control: no-store');

        // Rate-limit sentinels for the health probe endpoint — mirrors the same
        // header family set in the 'check' action block (lines 4959-4975).
        // Health is a read-only probe: it does not consume the download rate limit
        // or the daily quota. Use -1 (unlimited signal) for all counter values,
        // consistent with the 'check' action pattern.
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unlimited');
        header('X-RateLimit-Limit: -1');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unlimited');
        header('X-DailyLimit-Limit: -1');
        header('X-DailyLimit-Remaining: -1');
        header('X-DailyLimit-Reset: -1');
        header('X-DailyLimit-Window: unlimited');

        // $daily_limit is not defined in the health action scope (it lives inside the
        // info/download/validation closures). Declare it locally here so the health
        // response uses the same configured limit as info/download responses, avoiding
        // the inconsistency of a separate getenv() call for the same value.
        // Also compute quota_reset_ts locally so quota_reset/quota_reset_unix use the
        // same tomorrow-midnight UTC timestamp as info/download responses — clients
        // that rely on this field for reset timing will now get a correct value.
        $daily_limit = getDailyQuotaLimit();
        $quota_reset_ts = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp();
        $quota_reset_iso = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->format('c');

        $version = $GLOBALS['__ytdlp_version'] ?? 'not installed';
        $ffmpeg = $GLOBALS['__ffmpeg_version'] ?? 'not installed';

        $ytdlp_cache_ttl = null;
        $ytdlp_cache_expires_at = null;
        if ($version_cache_file && is_readable($version_cache_file)) {
            $cached = @json_decode(@file_get_contents($version_cache_file), true);
            if ($cached && is_array($cached)) {
                $exp = $cached['exp'] ?? 0;
                $ytdlp_cache_expires_at = date('c', $exp);
                $ytdlp_cache_ttl = max(0, $exp - time());
            }
        }

        // yt-dlp probe cache — TTL controlled by PROBE_CACHE_TTL constant so repeated
        // health?probe=1 calls don't hammer YouTube. Declared early here (before the
        // ffprobe block below) so the cache-read is adjacent to the ffprobe block for clarity.
        // The actual probe execution lives deeper in the case block where it has
        // access to the full $out response array.
        $probe_cache_file = '/tmp/ahoyrip_ytdlp_probe.cache';
        $do_probe = isset($_GET['probe']) && $_GET['probe'] === '1';
        if ($do_probe && is_readable($probe_cache_file)) {
            $cached = @json_decode(@file_get_contents($probe_cache_file), true);
            if ($cached && is_array($cached) && ($cached['exp'] ?? 0) > time()) {
                $GLOBALS['__ytdlp_probe'] = $cached['result'] ?? null;
            }
        }

        // yt-dlp probe cache — TTL controlled by PROBE_CACHE_TTL constant.
        // Surface the expiration so monitoring dashboards can track when the cached
        // probe result will be refreshed without needing to read the cache file directly.
        $probe_cache_ttl = null;
        $probe_cache_expires_at = null;
        if ($probe_cache_file && is_readable($probe_cache_file)) {
            $cached = @json_decode(@file_get_contents($probe_cache_file), true);
            if ($cached && is_array($cached)) {
                $exp = $cached['exp'] ?? 0;
                $probe_cache_expires_at = date('c', $exp);
                $probe_cache_ttl = max(0, $exp - time());
            }
        }
        // If the cache file doesn't exist yet (probe has never run), the TTL is
        // unknown — surface PROBE_CACHE_TTL as the not-yet-computed TTL so callers
        // can predict when the next ?probe=1 call will complete without guessing.
        if ($probe_cache_ttl === null) {
            $probe_cache_ttl = PROBE_CACHE_TTL;
            $probe_cache_expires_at = null; // null signals "not yet computed"
        }

        // ffprobe (ffmpeg) version cache — same TTL/read pattern as yt-dlp version
        // cache above. The cache file path uses md5(FFPROBE_PATH) so it automatically
        // diverges if the binary path changes. Read it here so the TTL and expiry
        // can be surfaced in the health response (lines 3632-3633).
        $ffmpeg_cache_file = '/tmp/ahoyrip_ffprobe_' . md5(FFPROBE_PATH) . '.cache';
        $ffmpeg_cache_ttl = null;
        $ffmpeg_cache_expires_at = null;
        if ($ffmpeg_cache_file && is_readable($ffmpeg_cache_file)) {
            $cached = @json_decode(@file_get_contents($ffmpeg_cache_file), true);
            if ($cached && is_array($cached)) {
                $exp = $cached['exp'] ?? 0;
                $ffmpeg_cache_expires_at = date('c', $exp);
                $ffmpeg_cache_ttl = max(0, $exp - time());
            }
        }

        $sys = getSystemMetrics();
        $yt_dlp_ok = !empty($version) && strpos($version, 'not installed') === false;
        $ffmpeg_ok = !empty($ffmpeg) && strpos($ffmpeg, 'not installed') === false;

        // api_ok: single boolean for trivial uptime checks (monitoring dashboards,
        // cron health checks, curl | grep api_ok scripts). Mirrors the degraded/ok
        // status but in boolean form so callers don't need to parse string values.
        $api_ok = $yt_dlp_ok && $ffmpeg_ok;
        $out = [
            'status' => $api_ok ? 'ok' : 'degraded',
            'action' => 'health',
            'api_ok' => $api_ok,
            'server_time' => date('c'),
            'server_time_unix' => time(),
            'request_id' => $request_id,
            'app_version' => AHOYRIPPER_VERSION,
            'php_version' => PHP_VERSION,
            'api_version' => AHOYRIPPER_VERSION,
            'os' => PHP_OS,
            'yt_dlp_version' => $version,
            'ffmpeg_version' => $ffmpeg,
            // ffprobe_version mirrors ffmpeg_version — both report the same binary
            // (ffprobe is part of the ffmpeg suite). Having both fields reduces
            // confusion since ffprobe is the actual binary being checked, while
            // ffmpeg_version is kept for backwards compatibility with existing clients.
            'ffprobe_version' => $ffmpeg,
            'yt_dlp_ok' => $yt_dlp_ok,
            'ffmpeg_ok' => $ffmpeg_ok,
            'yt_dlp_cache_expires_at' => $ytdlp_cache_expires_at,
            'yt_dlp_cache_ttl_seconds' => $ytdlp_cache_ttl,
            'ffmpeg_cache_expires_at' => $ffmpeg_cache_expires_at,
            'ffmpeg_cache_ttl_seconds' => $ffmpeg_cache_ttl,
            'yt_dlp_probe_cache_expires_at' => $probe_cache_expires_at,
            'yt_dlp_probe_cache_ttl_seconds' => $probe_cache_ttl,
            // System metrics are fetched once by getSystemMetrics() above — do not
            // re-read /proc here. The function checks /tmp for disk space (where
            // logs and caches live in containerized deployments) rather than the
            // root partition, which is the correct location for this application's health.
            'server_uptime_seconds' => $sys['server_uptime_seconds'],
            'load_avg' => $sys['load_avg'],
            'memory_available_pct' => $sys['memory_available_pct'],
            'disk_total_gb' => $sys['disk_total_gb'],
            'disk_free_gb' => $sys['disk_free_gb'],
            'disk_free_pct' => $sys['disk_free_pct'],
            // platform: null for health (no associated video URL). Mirrors source_url
            // being null for probe endpoints — consistent field presence across all actions.
            'platform' => null,
            // Daily quota fields — health is a read-only probe (does not consume quota)
            // so quota_remaining is -1 (unlimited signal). quota_limit mirrors the
            // configured daily limit for API surface consistency. quota_reset and
            // quota_reset_unix are always valid timestamps (never -1) per API contract.
            'quota_remaining' => -1,
            'quota_limit' => $daily_limit,
            'quota_reset' => $quota_reset_iso,
            'quota_reset_unix' => $quota_reset_ts,
            // upgrade_url: AhoyVPN upsell URL included on all API responses so clients
            // can always surface the upsell opportunity regardless of which endpoint
            // was called. Health is a probe endpoint (no content rip), but the upsell
            // is equally valid here — consistent with the check action pattern.
            'upgrade_url' => UPGRADE_URL,
            // source_url: null for server-probe endpoints (no associated video URL).
            // source_url_missing: true — no video URL was provided (probe endpoint).
            // Mirrors the source_url field in the /check response, giving API consumers
            // a consistent null reference for probe endpoints rather than a hardcoded URL.
            'source_url' => null,
            'source_url_missing' => true,
        ];

        // yt-dlp live probe — disabled by default (add ?probe=1 to enable).
        // Running a real YouTube probe adds ~1-3s of latency per uncached health check
        // (proc_open + yt-dlp startup + network round-trip). The probe is useful when
        // a client wants to verify end-to-end connectivity, but adds unnecessary overhead
        // for routine load checks. The probe result is cached per PROBE_CACHE_TTL regardless.
        if ($do_probe) {
            // Only run the probe if the cache did not already populate __ytdlp_probe
            // (the cache-read above set $GLOBALS['__ytdlp_probe'] when a cached result existed).
            if (!isset($GLOBALS['__ytdlp_probe'])) {
                // Use a fast, stable YouTube video for the probe — short, public,
                // unlikely to be geo-restricted. Timeout is controlled by HEALTH_PROBE_TIMEOUT
                // (default 15s) to keep the health endpoint responsive.
                // --skip-download fetches metadata without downloading the full file,
                // saving bandwidth and keeping the health check lightweight.
                //
                // Build the probe command as an explicit array (NOT a shell string) to
                // avoid breaking AHOY_USER_AGENT which contains parentheses
                // "(KHTML, like Gecko)" — preg_split-based argument tokenizers split on
                // unquoted whitespace and would misparse the UA string into separate
                // tokens, causing yt-dlp to receive a mangled --user-agent argument.
                // Using bypass_shell=true with a direct array bypasses the shell
                // entirely so no escaping is needed regardless of UA string content.
                // Build the full yt-dlp command array before proc_open.
                // --impersonate: spoof browser TLS/ALPN fingerprints to reduce 403/422 on
                // YouTube health checks. Only used when AHOY_IMPERSONATE is non-empty.
                $probe_cmd = [
                    YTDLP_PATH,
                    '--dump-json',
                    '--no-playlist',
                    '--skip-download',
                    // --no-progress: suppress all progress output. yt-dlp emits progress
                    // template noise even during --skip-download which would prepend garbage
                    // to stderr and corrupt json_decode on stdout.
                    '--no-progress',
                    '--retries', '3',
                    // --extractor-retries: retry known extractor errors (rate limits, temporary
                    // 5xx) separately from generic --retries. Mirrors the info and download
                    // action pattern so the health probe accurately reflects real ripping behavior.
                    '--extractor-retries', '3',
                    '--socket-timeout', (string)max(1, floor(HEALTH_PROBE_TIMEOUT / 2)),
                    '--referer', 'https://ahoyripper.com/',
                    '--user-agent', AHOY_USER_AGENT,
                ];
                if (AHOY_IMPERSONATE !== '') {
                    $probe_cmd[] = '--impersonate';
                    $probe_cmd[] = AHOY_IMPERSONATE;
                }
                // --cookies: pass authenticated cookies if COOKIES_PATH is configured.
                // Mirrors the cookie handling in the info and download actions so the
                // health probe accurately reflects real ripping capability (including
                // cookie-gated platforms like age-restricted YouTube).
                if (COOKIES_PATH !== '') {
                    $probe_cmd[] = '--cookies';
                    $probe_cmd[] = COOKIES_PATH;
                }
                $probe_cmd[] = '--add-header';
                $probe_cmd[] = 'Accept-Language: ' . preg_replace('/[^\x20-\x7E;,=]/', '', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en-US;q=0.9,*;q=0.5');
                $probe_cmd[] = '--';
                $probe_cmd[] = HEALTH_PROBE_URL;
                $probe_desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
                $probe_pipes = null;
                $probe_proc = proc_open(
                    $probe_cmd,
                    $probe_desc,
                    $probe_pipes,
                    '/tmp',
                    [],
                    ['bypass_shell' => true]
                );

                $probe_out = $probe_err = '';
                $probe_exit = -1;
                if ($probe_proc) {
                    fclose($probe_pipes[0]);
                    unset($probe_pipes[0]);
                    $probe_start = hrtime(true);
                    while (!feof($probe_pipes[1]) || !feof($probe_pipes[2])) {
                        if ((hrtime(true) - $probe_start) / 1e9 > HEALTH_PROBE_TIMEOUT) {
                            proc_terminate($probe_proc, 9);
                            $probe_proc = null;  // sentinel: prevents double proc_close() below
                            $probe_err = "Process timed out after " . HEALTH_PROBE_TIMEOUT . "s";
                            break;
                        }
                        $r = [$probe_pipes[1], $probe_pipes[2]];
                        $w = $e = null;
                        $changed = @stream_select($r, $w, $e, 0, 200000);
                        if ($changed === false) { break; }
                        if ($changed === 0) {
                            usleep(100000);
                            continue;
                        }
                        foreach ($r as $p) {
                            $chunk = fread($p, 65536);
                            if ($chunk === false || $chunk === '') { continue; }
                            if ($p === $probe_pipes[1]) {
                                $probe_out .= $chunk;
                            } else {
                                $probe_err .= $chunk;
                            }
                        }
                        if (feof($probe_pipes[1]) && feof($probe_pipes[2])) { break; }
                    }
                    $probe_exit = ($probe_proc !== null) ? proc_close($probe_proc) : -1;
                    $probe_proc = null;  // reset for next use
                } else {
                    $probe_err = "proc_open failed";
                }

                $probe_result = $probe_exit === 0 && $probe_out
                    ? json_decode($probe_out, true)
                    : null;
                if ($probe_result) {
                    $GLOBALS['__ytdlp_probe'] = [
                        'ok' => true,
                        'action' => 'health',
                        'title' => substr($probe_result['title'] ?? '', 0, 80),
                        'source_url' => HEALTH_PROBE_URL,
                        // yt_dlp_version and api_version are included on all API responses —
                        // add them here for consistency even though the probe result is
                        // not a full info response (avoids clients having to check for
                        // missing fields when inspecting probe results).
                        'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                        'api_version' => AHOYRIPPER_VERSION,
                        // upgrade_url: mirrors the health response body for consistency
                        // when clients read the probe sub-field directly.
                        'upgrade_url' => UPGRADE_URL,
                    ];
                } else {
                    // Probe failed — surface a structured error_code and error_msg.
                    // Three distinct failure modes need explicit classification:
                    //   1. proc_open returned false  → binary missing or system-level failure
                    //   2. proc_open succeeded but exit=-1 → PHP-side timeout fired
                    //   3. proc_open succeeded with exit>0 → yt-dlp itself reported an error
                    //
                    // classifyYtdlpError handles case 3 well (yt-dlp stderr). For cases 1-2
                    // it returns null because the error text does not match any yt-dlp pattern.
                    // Detect these explicitly before falling through to classifyYtdlpError so
                    // clients receive a meaningful error_code instead of the generic 'PROBE_FAILED'.
                    $probe_raw_err = trim($probe_err ?: $probe_out);
                    // case 1: proc_open false (binary absent, permissions, or system exhaustion).
                    // Guard: $probe_err === '' distinguishes this from case 2 (PHP-side timeout),
                    // where proc_open succeeds but $probe_err contains "Process timed out...".
                    // Without the guard, strpos('', 'timed out')===0 would incorrectly match case 2
                    // and classify a missing binary as SOURCE_TIMEOUT instead of PROC_OPEN_FAILED.
                    // Uses PROC_OPEN_FAILED (not YTDLP_NOT_FOUND) to match the error code used
                    // in the info and download action paths, so operators get a consistent
                    // HTTP 500 / PROC_OPEN_FAILED signal regardless of which action triggered
                    // the startup failure.
                    if ($probe_exit === -1 && $probe_err === '' && $probe_raw_err === '') {
                        $probe_classified = [
                            'code' => 'PROC_OPEN_FAILED',
                            'msg' => 'yt-dlp binary could not be started. Check that it is installed and the path is correct.',
                            'upgrade_url' => UPGRADE_URL,
                        ];
                    // case 2: PHP-side timeout (proc_open succeeded, process was killed)
                    } elseif ($probe_exit === -1 && strpos($probe_raw_err, 'timed out') !== false) {
                        $probe_classified = [
                            'code' => 'SOURCE_TIMEOUT',
                            'msg' => 'The source site took too long to respond during the health probe. Try again when the site is less busy.',
                            'upgrade_url' => UPGRADE_URL,
                        ];
                    // case 3: yt-dlp exited with a real error — classify from stderr
                    } else {
                        $probe_classified = classifyYtdlpError($probe_raw_err, $probe_exit);
                    }
                    $GLOBALS['__ytdlp_probe'] = [
                        'ok' => false,
                        'action' => 'health',
                        'source_url_missing' => false,
                        'error_code' => $probe_classified['code'] ?? 'PROBE_FAILED',
                        'error_msg' => $probe_classified['msg'] ?? $probe_raw_err ?: 'Unknown error during yt-dlp health probe.',
                        'source_url' => HEALTH_PROBE_URL,
                        // yt_dlp_version and api_version are included on all API responses;
                        // add them here for consistency even though the probe failed,
                        // so clients always have version info regardless of probe outcome.
                        'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                        'api_version' => AHOYRIPPER_VERSION,
                        // upgrade_url: included on failed probe responses so clients can
                        // always surface the AhoyVPN upsell regardless of probe outcome.
                        'upgrade_url' => UPGRADE_URL,
                    ];
                }
                if ($probe_cache_file) {
                    @file_put_contents($probe_cache_file, json_encode([
                        'result' => $GLOBALS['__ytdlp_probe'],
                        'exp' => time() + PROBE_CACHE_TTL,
                        'cached_at' => time(),
                    ]));
                }
            }
            // Always include probe result in response when ?probe=1 is set,
            // whether it came from cache or was just computed.
            // Add probe_age_seconds so clients can determine how stale a cached
            // result is without reading the cache file directly.
            // Read from the cache file directly (not $GLOBALS) to get the actual
            // cached_at timestamp — this ensures probe_age_seconds is always
            // meaningful, even when the response was served from a stale cache
            // (GLOBALS holds the freshly-computed probe result, but a previous
            // request's cache file might be older than the computed one, and we
            // want the age relative to when it was actually cached, not computed).
            $probe_result = $GLOBALS['__ytdlp_probe'];
            if (is_readable($probe_cache_file)) {
                $cached = @json_decode(@file_get_contents($probe_cache_file), true);
                if ($cached && isset($cached['cached_at'])) {
                    $probe_result['probe_age_seconds'] = max(0, time() - (int)$cached['cached_at']);
                }
            }
            if (!isset($probe_result['probe_age_seconds'])) {
                $probe_result['probe_age_seconds'] = 0; // freshly computed
            }
            $out['yt_dlp_probe'] = $probe_result;
        }
        // When no probe is requested, the yt_dlp_probe field is intentionally
        // omitted from the response (not null, absent) so the response shape
        // is stable and clients can distinguish "probe disabled" from errors.

        // System metrics (uptime, load, memory, disk) were fetched once by
        // getSystemMetrics() at the start of the health case — use those values
        // directly. The disk_free_gb from getSystemMetrics() uses /tmp (where
        // logs and caches live), which is the correct partition for this app's
        // health check rather than the root partition.

        // X-Request-ID: echo the client-provided ID or the server-generated one.
        // Set explicitly here (not relying on the top-of-script block) so the health
        // response always includes it regardless of how the case block is entered.
        header('X-Request-ID: ' . $request_id);
        // Rate-limit headers for the health endpoint — signals to clients that
        // this endpoint is not subject to download rate limiting (X-DL-RateLimit
        // uses -1/unlimited sentinel values since health is a read-only probe).
        // download rate limit: health is not a download action, so -1 (no limit)
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unlimited');
        // Standard rate-limit header family for generic API consumers.
        // X-RateLimit-Limit: -1 = no rate limit applies (convention: -1 means
        // "unlimited", 0 means "limit exhausted"). Mirrors X-DL-RateLimit-Limit.
        header('X-RateLimit-Limit: -1');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unlimited');
        // Daily-limit sentinels (-1) signal clients this is a read-only probe,
        // not a rip-consuming action — mirrors the pattern used by action=check.
        header('X-DailyLimit-Limit: -1');
        header('X-DailyLimit-Remaining: -1');
        header('X-DailyLimit-Reset: -1');
        header('X-DailyLimit-Window: unlimited');
        // Retry-After: 0 — health is a read-only probe with no server-side backoff;
        // the client should retry immediately. Mirrors the same pattern in the 'check'
        // action (which also uses Retry-After: 0 alongside X-*-Limit: -1 sentinels).
        header('Retry-After: 0');

        header('Cache-Control: no-store');
        echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    }
    case 'csp-report': {
        // NOTE: csp-report is also handled inline at lines 362–421 (inside the
        // internal_actions block above). That inline handler is the active one — it
        // calls fastcgi_finish_request() and exits. This case block is unreachable
        // dead code preserved for documentation and as a fallback if the inline
        // handler is ever refactored. All security headers are set by the inline handler.
        // Receive and log CSP violation reports from browsers.
        // nginx routes POST /csp-report here (via fastcgi_pass to this script).
        // The browser POSTs a JSON report body — no authentication needed since
        // the endpoint is only accessible via cross-origin CSP violation triggers
        // (which require the user to visit the AhoyRipper page first).
        // The Referer check above already ensures the request originated from
        // the AhoyRipper origin, providing origin confirmation.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            // Standard security headers for consistency with all other API responses.
            // Mirrors the headers set in the action=check and action=analytics 405 blocks.
            header('Cache-Control: no-store');
            header('X-Request-ID: ' . $request_id);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            // X-Info-Timeout and X-Download-Timeout: present on all API responses.
            // CSP-report 405 block was missing these — add for consistency with check/health.
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            // X-DL-RateLimit-*: download-specific rate limit (not applicable here, so -1).
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unlimited');
            // X-RateLimit-*: generic rate-limit header family for API consumers.
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: unlimited');
            // X-DailyLimit-*: daily quota sentinel (-1 = not applicable to this endpoint).
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
            // CSP and Reporting headers: csp-report 405 block bypasses the top-of-script
            // block that sets these globally — repeat them here so the response is fully
            // hardened regardless of how this action is served.
            header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'; report-to csp-report;');
            header('Reporting-Endpoints: csp-report="/csp-report"');
            header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
            echo json_encode([
                'error' => 'Method Not Allowed. Use POST for CSP reports.',
                'error_code' => 'METHOD_NOT_ALLOWED',
                'action' => 'csp-report',
                'retry_after' => 0,
                'request_id' => $request_id,
                'upgrade_url' => UPGRADE_URL,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                // Internal reporting endpoint — no video URL or quota applies.
                'source_url' => null,
                'source_url_missing' => false,
                'format_id_missing' => false,
                'quota_remaining' => -1,
                'quota_limit' => -1,
                'quota_reset' => -1,
                'quota_reset_unix' => -1,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            break;
        }
        $raw_body = file_get_contents('php://input');
        $report = json_decode($raw_body, true);
        if (!$report || !is_array($report)) {
            // Return 204 anyway — browsers don't retry CSP reports and a
            // malformed report should not cause client-side error display.
            http_response_code(204);
            break;
        }
        // Strip any null bytes or control characters from report fields
        // to prevent log injection via CSP violation reports.
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/', '', json_encode($report));
        $log_line = json_encode([
            'ts' => date('c'),
            'request_id' => $request_id,
            'csp_report' => json_decode($sanitized, true),
        ]);
        @file_put_contents('/var/log/ahoyripper/csp-reports.log', $log_line . "\n", FILE_APPEND);
        // 204 No Content — the standard response for successful CSP reports.
        // Browsers don't parse the response body and don't retry on 204.
        http_response_code(204);
        break;
    }

    case 'analytics': {
        // Thin proxy for Plausible analytics — receives browser beacons from
        // /js/analytics.js and forwards them to the self-hosted Plausible server.
        // This avoids sending analytics requests to third-party servers from the
        // browser, keeps all data within the same origin, and lets the server
        // control the destination (PLAUSIBLE_HOST env var).
        //
        // Benefits:
        //   - No third-party requests from the browser (unlike direct Plausible calls)
        //   - Server-side rate limiting on the analytics endpoint
        //   - Server strips PII (IPs, full URLs with video links) before forwarding
        //   - CSP-compliant: analytics.js loads from same origin, not external domain
        //   - Fully self-hosted: no plausible.io domain required in connect-src
        //
        // To configure: set PLAUSIBLE_HOST env var to your self-hosted Plausible domain
        // (or 'plausible.io' for the official hosted service). Defaults to '' (self-hosted proxy).
        //
        // If no PLAUSIBLE_HOST is configured, the endpoint returns 204 silently so
        // analytics failures never affect page load or UX.

        // Only accept POST (navigator.sendBeacon uses POST).
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            // Standard security headers for consistency with all other API responses.
            // Mirrors the headers set in the action=check and action=csp-report 405 blocks.
            header('Cache-Control: no-store');
            header('X-Request-ID: ' . $request_id);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            // X-Info-Timeout and X-Download-Timeout: present on all API responses
            // (check, health, client-error) for generic header-parsing consistency.
            // Analytics 405 block was missing these — add them now.
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            // X-RateLimit-* sentinels: analytics is a read-only internal action
            // that does not consume from the per-minute download or info rate budget.
            // Mirrors the same -1 sentinel pattern used in check/health/client-error.
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unlimited');
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: unlimited');
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
            // COOP and CORP: complete the security header family for this endpoint.
            // Mirrors the headers set in the csp-report and client-error 405 blocks.
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            // CSP and Reporting headers — analytics 405 was missing these.
            // Mirrors the headers set in the csp-report and client-error 405 blocks.
            header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');
            header('Reporting-Endpoints: csp-report="/csp-report"');
            header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
            echo json_encode([
                'error' => 'Method Not Allowed. Use POST for analytics beacons.',
                'error_code' => 'METHOD_NOT_ALLOWED',
                'action' => 'analytics',
                'retry_after' => 0,
                'request_id' => $request_id,
                'upgrade_url' => UPGRADE_URL,
                // Consistent with all other API error responses:
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
                'source_url' => null,
                'source_url_missing' => false,
                'format_id_missing' => false,
                // Analytics is an internal action — quota does not apply.
                'quota_remaining' => -1,
                'quota_limit' => -1,
                'quota_reset' => -1,
                'quota_reset_unix' => -1,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            break;
        }

        $raw_body = @file_get_contents('php://input');
        if ($raw_body === false || $raw_body === '') {
            http_response_code(204);
            break;
        }

        $payload = @json_decode($raw_body, true);
        if (!is_array($payload)) {
            http_response_code(204);
            break;
        }

        // Use PLAUSIBLE_HOST constant (defined at top of file).
        // '' (default, from getenv): routes through /src/api.php?action=analytics proxy.
        // 'plausible.io' or 'analytics.yourdomain.com': forward directly to Plausible.
        // '' (explicitly set): disable analytics entirely (204 returned silently).
        $plausible_host = PLAUSIBLE_HOST;

        // Strip PII from the payload before forwarding:
        //   - URL: remove any ?url= param (contains the video link prefill).
        //   - referrer: keep only the hostname (strip full URL).
        //   - IP addresses never reach Plausible (nginx strips them before PHP).
        if (isset($payload['url']) && is_string($payload['url'])) {
            $parsed = @parse_url($payload['url']);
            if (is_array($parsed)) {
                $payload['url'] = ($parsed['scheme'] ?? 'https') . '://'
                    . ($parsed['host'] ?? '')
                    . ($parsed['path'] ?? '');
            }
        }
        if (isset($payload['referrer']) && is_string($payload['referrer'])) {
            $ref_parsed = @parse_url($payload['referrer']);
            $payload['referrer'] = is_array($ref_parsed)
                ? (($ref_parsed['scheme'] ?? 'https') . '://' . ($ref_parsed['host'] ?? ''))
                : '';
        }

        // Return 204 to the browser immediately — analytics is fire-and-forget.
        // Never let Plausible downtime affect user UX. Use fastcgi_finish_request()
        // (PHP-FPM only) to flush the full response before the outbound HTTP request.
        // This prevents the slow Plausible call (up to 5s timeout) from blocking
        // the PHP-FPM worker, keeping it available for other requests.
        // In non-FPM SAPIs the function doesn't exist so we fall back to inline
        // execution (the Plausible call is still non-blocking for the browser).
        http_response_code(204);
        if (function_exists('fastcgi_finish_request')) {
            // Re-set standard headers explicitly since the top-of-script header
            // buffer may not be flushed before fastcgi_finish_request().
            // These headers are normally set by the top-of-script block but this
            // fastcgi_finish_request() path bypasses that block, so they must be
            // set explicitly here to prevent header-less responses in PHP-FPM mode.
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Download-Options: noopen');
            header('X-Robots-Tag: noindex, noai, noimage, noydir');
            header('X-Request-ID: ' . $request_id);
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            header('Cache-Control: no-store');
            header('X-Info-Timeout: ' . INFO_TIMEOUT);
            header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
            // Rate-limit sentinels (-1): analytics is a read-only internal action
            // that does not consume from the per-minute download or info rate budget.
            header('X-DL-RateLimit-Limit: -1');
            header('X-DL-RateLimit-Remaining: -1');
            header('X-DL-RateLimit-Reset: -1');
            header('X-DL-RateLimit-Window: unlimited');
            header('X-RateLimit-Limit: -1');
            header('X-RateLimit-Remaining: -1');
            header('X-RateLimit-Reset: -1');
            header('X-RateLimit-Window: unlimited');
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
            // CSP and Reporting headers: complete the security header family.
            header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'; report-to csp-report;');
            header('Reporting-Endpoints: csp-report="/csp-report"');
            header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
            echo '';
            fastcgi_finish_request();
        }

        // Forward to Plausible API after the response is returned to the browser.
        // Use file_get_contents with stream context to avoid adding a dependency.
        // Skip the HTTP request entirely when analytics is disabled (PLAUSIBLE_HOST='')
        // so the 204 is returned immediately without a useless outbound connection.
        if ($plausible_host !== '') {
            $forward_url = 'https://' . $plausible_host . '/api/event';
            $forward_body = @json_encode($payload);

            $context = @stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $forward_body,
                    'timeout' => 5, // 5s — analytics delivery is non-critical.
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $response = @file_get_contents($forward_url, false, $context);
        }
        break;
    }

    default: {
        // Return 404 Not Found — the action/endpoint is not recognized.
        // 400 Bad Request would imply a malformed request syntax, which is
        // inaccurate when the server simply doesn't know that action name.
        logRequest($action ?: 'unknown', 404, ['reason' => 'unknown_action']);
        http_response_code(404);
        // All security headers — consistent with every other API response.
        // These mirror the headers set at the top of api.php for all responses,
        // ensuring the 404 case is fully hardened even though it bypasses
        // the normal action flow (no switch-case block-specific headers are sent).
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-ID: ' . $request_id);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Download-Options: noopen');
        header('X-Robots-Tag: noindex, noai, noimage, noydir');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://*.tiktokcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\'; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');
        header('Reporting-Endpoints: csp-report="/csp-report"');
        header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
        // Rate-limit headers for consistency with the rest of the API.
        // Unknown actions are not rate-limited actions (info/download), so use -1
        // sentinel values to signal "no limit applies" to generic API consumers.
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unlimited');
        // Rate-limit headers: -1 = no limit applies (0 = exhausted).
        header('X-RateLimit-Limit: -1');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unlimited');
        header('X-DailyLimit-Limit: -1');
        header('X-DailyLimit-Remaining: -1');
        header('X-DailyLimit-Reset: -1');
        header('X-DailyLimit-Window: unlimited');
        // X-Info-Timeout and X-Download-Timeout: present on all other API error
        // responses so clients can set appropriate fetch timeouts on retry.
        // The default: case has no associated video URL, but the timeout headers
        // are included for consistency with the rest of the API surface.
        header('X-Info-Timeout: ' . INFO_TIMEOUT);
        header('X-Download-Timeout: ' . DOWNLOAD_TIMEOUT);
        // Retry-After: 0 — unknown-action is a validation error (the action name is
        // not recognized), not a server-side backoff situation. The client should
        // retry immediately with a corrected action name. Consistent with MISSING_URL,
        // INVALID_URL, and other validation errors which also use retry_after: 0.
        header('Retry-After: 0');
        // Prevent caching of the unknown-action JSON response.
        // All other API responses (success and error) set Cache-Control: no-store
        // globally or in their respective case blocks. This case was missing it,
        // creating a cacheable response surface for a security-sensitive JSON endpoint.
        header('Cache-Control: no-store');
        echo json_encode([
            'error' => 'Unknown action. Use ?action=info, ?action=download, ?action=check, ?action=health, ?action=progress, ?action=analytics, ?action=client-error, or ?action=csp-report.',
            'error_code' => 'UNKNOWN_ACTION',
            'action' => $action,
            'retry_after' => 0,
            'request_id' => $request_id,
            'server_time' => date('c'),
            'server_time_unix' => time(),
            'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
            'api_version' => AHOYRIPPER_VERSION,
            'upgrade_url' => UPGRADE_URL,
            // source_url is null here because an unknown action has no associated video URL.
            // This matches the pattern used by MISSING_URL (source_url: null) and ensures
            // all API error responses have a consistent top-level shape.
            'source_url' => null,
            // source_url_missing: false — URL validation has not run for unknown actions.
            // An unknown action rejects the request before URL processing, so the URL
            // field (if any) is not validated. Matches UNKNOWN_ACTION default: block.
            'source_url_missing' => false,
            // format_id_missing: false — format ID is not relevant to unknown actions.
            'format_id_missing' => false,
            // platform: null — unknown actions have no associated source platform.
            'platform' => null,
            // quota_remaining: -1 signals that quota tracking is not available for unknown
            // actions. Matches MISSING_URL which also has quota_remaining: -1 for the same
            // reason. API consumers should treat -1 as "unknown remaining quota".
            'quota_remaining' => -1,
            'quota_limit' => $daily_limit,
            'quota_reset' => (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp(),
            'quota_reset_unix' => (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp(),
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    }
}
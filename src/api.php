<?php
/**
 * AhoyRipper - API Endpoint
 * Handles: info extraction, format listing, and download serving
 */

define('AHOYRIPPER_VERSION', require __DIR__ . '/version.php');

// Path to yt-dlp binary — configurable via YTDLP_PATH env var so deployments
// can override the default /usr/local/bin/yt-dlp without editing source.
// Defined early because the version-probe shell_exec (line ~427) runs before
// the constants section and needs this value before any other constants exist.
define('YTDLP_PATH', getenv('YTDLP_PATH') ?: '/usr/local/bin/yt-dlp');

// Path to ffprobe binary — configurable via FFPROBE_PATH env var so deployments
// can override the default /usr/bin/ffprobe (e.g. to /usr/local/bin/ffprobe).
// Used for post-download codec/resolution verification in the download action.
// The ffprobe binary path is also used as the cache-key filename for the ffprobe
// version cache so that changing FFPROBE_PATH invalidates stale cache entries.
define('FFPROBE_PATH', getenv('FFPROBE_PATH') ?: '/usr/bin/ffprobe');

// Timeout (seconds) for ffprobe post-download verification. ffprobe should finish
// in well under 10s for any real file; 10s is generous for large or slow files.
// Override via FFPROBE_TIMEOUT env var (e.g. FFPROBE_TIMEOUT=20 in .env).
define('FFPROBE_TIMEOUT', max(1, (int)getenv('FFPROBE_TIMEOUT') ?: 10));

// TTL (seconds) for the yt-dlp connectivity probe cache in the health endpoint.
// PROBE_CACHE_TTL (5 minutes) prevents hammering YouTube with repeated health checks while
// keeping the probe result fresh enough to detect real outages. Not configurable via
// env var — increase the constant directly if longer TTL is ever needed.
define('PROBE_CACHE_TTL', 300);

// Set UTC for all date/time functions — gmdate() and date('c') are used
// throughout this script without an explicit timezone argument. PHP issues
// a warning when no default timezone is configured and a date function is
// called. Setting UTC here ensures consistent, predictable output regardless
// of the host system's PHP timezone configuration.
date_default_timezone_set('UTC');

// CORS headers for API access
header('Content-Type: application/json; charset=utf-8');
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
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\' https://ahoyripper.com; font-src \'self\' https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
header('X-Download-Options: noopen');
// Prevent AI / crawler indexing and training on API responses.
// Search engines (Google, Bing) and AI training crawlers (CCBot, GPTBot, etc.)
// all respect X-Robots-Tag. This complements robots.txt which only covers the
// public page — the API endpoint (which returns JSON) needs its own directive.
header('X-Robots-Tag: noindex, noai, noimage, noydir');
$request_id = bin2hex(random_bytes(8));
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
    // Exempt the check action (zero-dependency monitoring ping used by Docker
    // HEALTHCHECK and external probes that cannot send a browser Referer header).
    // info/download remain fully protected — monitoring tools should use action=check.
    if ($action !== 'check') {
        logRequest('cors_block', 403, ['reason' => $block_reason, 'referer' => $referer]);
        error_log("AhoyRipper: blocked request ($block_reason) from referer: " . ($referer ?: '(none)'));
        http_response_code(403);
        echo json_encode(['error' => 'Requests must originate from ahoyripper.com or ahoyvpn.com.', 'error_code' => 'FORBIDDEN_ORIGIN', 'request_id' => $request_id], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
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
// reads it at line 593, download action at line 818).
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_file = '/tmp/ahoyrip_rate_' . md5($ip);
$rate_limit = 30; // requests per minute
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
        http_response_code(503);
        header('Retry-After: 5');
        echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        http_response_code(503);
        header('Retry-After: 5');
        echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $raw = fread($fp, 4096);
    if ($raw) {
        $decoded = json_decode($raw, true);
        if ($decoded && is_array($decoded)) {
            $data = $decoded;
        }
    }

    if (time() - $data['t'] < $rate_window) {
        if ($data['c'] >= $rate_limit) {
            $reset_timestamp = $data['t'] + $rate_window;
            flock($fp, LOCK_UN);
            fclose($fp);
            http_response_code(429);
            header('X-RateLimit-Limit: ' . $rate_limit);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . $reset_timestamp);
            header('X-RateLimit-Window: ' . $rate_window);
            header('X-DL-RateLimit-Limit: ' . $rate_limit);
            header('X-DL-RateLimit-Remaining: 0');
            header('X-DL-RateLimit-Reset: ' . $reset_timestamp);
            header('X-DL-RateLimit-Window: ' . $rate_window);
            header('Retry-After: ' . max(0, $reset_timestamp - time()));
            // Daily-limit sentinels (-1) signal clients this is a per-minute rate limit,
            // not a daily quota hit — allows the UI to distinguish the two cases without
            // parsing the error message. The daily-quota 429 block (when $daily_limit is
            // exceeded) sends the real daily-limit values instead.
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
            echo json_encode([
                'error' => 'Too many requests. Slow down.',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
                'upgrade_url' => 'https://ahoyvpn.com',
                'retry_after' => max(0, (int)($reset_timestamp - time())),
                'request_id' => $request_id,
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

// Set rate limit headers unconditionally so they are present on every response,
// including unlimited-key requests that still pass through this gate.
// This gives clients (monitoring tools, load balancers) consistent metadata.
$reset = $data['t'] + $rate_window;
header('X-RateLimit-Limit: ' . $rate_limit);
header('X-RateLimit-Remaining: ' . max(0, $rate_limit - $data['c']));
header('X-RateLimit-Reset: ' . $reset);
header('X-RateLimit-Window: ' . $rate_window);
// X-DL-* mirrors the X-RateLimit-* headers for download-specific monitoring.
// Set unconditionally so download responses always carry these headers regardless
// of whether the rate limit was hit. Inside the 429 block (lines 192-196) they
// are also set to 0/-1 sentinel values for over-limit responses.
header('X-DL-RateLimit-Limit: ' . $rate_limit);
header('X-DL-RateLimit-Remaining: ' . max(0, $rate_limit - $data['c']));
header('X-DL-RateLimit-Reset: ' . $reset);
header('X-DL-RateLimit-Window: ' . $rate_window);

// Periodic cleanup of stale rate files and cache entries.
// Proactively removes expired entries from /tmp to prevent indefinite accumulation
// on servers that run for months without restart.
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
// Clean up stale version cache files (yt-dlp and ffmpeg) and the yt-dlp
// connectivity probe cache — they expire after their respective TTLs but the
// files themselves accumulate on long-running servers if not removed.
// When the cache is cleared, also clear the in-memory global so the next request
// fetches a fresh value rather than holding a stale entry across requests.
foreach (['/tmp/ahoyrip_ytdlp_ver.cache', '/tmp/ahoyrip_ffmpeg_ver.cache', '/tmp/ahoyrip_ytdlp_probe.cache'] as $cache) {
    $d = @json_decode(@file_get_contents($cache), true);
    if (!$d || !is_array($d) || ($d['exp'] ?? 0) < time()) {
        @unlink($cache);
        if ($cache === '/tmp/ahoyrip_ytdlp_ver.cache') {
            $GLOBALS['__ytdlp_version'] = null;
        }
        if ($cache === '/tmp/ahoyrip_ffmpeg_ver.cache') {
            $GLOBALS['__ffmpeg_version'] = null;
        }
        if ($cache === '/tmp/ahoyrip_ytdlp_probe.cache') {
            $GLOBALS['__ytdlp_probe'] = null;
        }
    }
}

// ─── Lightweight internal check (no auth, no rate-limit, no referer check) ───
// Dedicated endpoint for Docker healthchecks and load-balancer probes.
// Unlike health (which may run yt-dlp, syscalls, reads /proc), this is a pure
// JSON ping that adds zero server load — safe to call every 10 seconds.
// Placed BEFORE the referer gate so it exits before that check runs.
// Both 'health' and 'progress' map to the same health-probe handler (the
// 'progress' case falls through to 'health' in the switch below). Exposing
// both names maintains backwards compatibility with any clients that use the
// older 'progress' action name while guiding new integrations toward 'health'.
$internal_actions = ['check', 'health', 'progress', 'csp-report'];
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
            // These location-level nginx headers may be missed after fastcgi_finish_request()
            // flushes — set them explicitly here to guarantee they're present.
            header('Reporting-Endpoints: csp-report="/csp-report"');
            header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
            header('Content-Security-Policy-Report-Only: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; report-to csp-report; report-uri /csp-report;');
            echo json_encode(['status' => 'ok']);
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
        header('Reporting-Endpoints: csp-report="/csp-report"');
        header('Report-To: {"group":"csp-report","max_age":86400,"endpoints":[{"url":"/csp-report"}]}');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');
        echo json_encode(['status' => 'ok']);
        exit;
    }

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
    echo json_encode([
        'status' => 'ok',
        'server_time' => date('c'),
        'request_id' => $request_id,
        'app_version' => AHOYRIPPER_VERSION,
        'php_version' => PHP_VERSION,
        'api_version' => AHOYRIPPER_VERSION,
        'source_url' => 'https://ahoyripper.com',
    ]);
    exit;
}

// Only allow HTTPS URLs and block private IP ranges to prevent SSRF attacks.
// yt-dlp accepts file:// URLs directly, so we restrict to HTTP(S) and reject
// private ranges (127.x, 10.x, 172.16-31.x, 192.168.x, 169.254.x) and IPv6 loopback.
function isValidUrl($url) {
    if (!is_string($url)) {
        return false;
    }
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
    // Strip brackets from IPv6 URLs (e.g., [::1] -> ::1) before validation.
    // parse_url with PHP_URL_HOST returns IPv6 addresses in bracketed form.
    // filter_var with FILTER_VALIDATE_IP rejects bracketed strings, so we must
    // strip the brackets before passing the host to the validator.
    if (filter_var($parsed, FILTER_VALIDATE_IP) !== false) {
        // Host is a bare IP (no brackets)
        $host = $parsed;
    } elseif (filter_var(substr($parsed, 1, -1), FILTER_VALIDATE_IP) !== false) {
        // Host is a bracketed IP like [::1] or [fe80::1] — extract the bare IP
        $host = substr($parsed, 1, -1);
    } else {
        // Host is a domain name — skip IP validation (domains don't fail FILTER_VALIDATE_IP)
        $host = null;
    }
    // If the host resolved to an IP address, validate it is not private/reserved.
    // This catches both bare IPs and IPv6 loopback/link-local stripped of brackets.
    if ($host !== null && filter_var($host, FILTER_VALIDATE_IP) !== false) {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
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
    // Note: yt-dlp outputs version to stdout (not stderr) — redirect stderr to
    // stdout so shell_exec captures it (shell_exec sees only stdout, not the
    // stderr pipe). Without 2>&1, the probe always returns empty and the cache
    // is always stale, causing yt-dlp startup overhead on every single request.
    $ver = trim(shell_exec(YTDLP_PATH . ' --version 2>&1') ?: '');
    // Distinguish a real version string from a shell "command not found" error.
    // When the binary is absent, shell_exec returns either '' (empty) or a
    // shell error like "sh: 1: /usr/local/bin/yt-dlp: not found". The
    // strpos($ver, 'not installed') check handles the 'not installed' string
    // (used by the ffmpeg probe). The regex catches the shell error form.
    // The health check (line 2510) uses strpos($version, 'not installed') ===
    // false to detect "not installed", so this sentinel must be consistent.
    if ($ver === '' || preg_match('/^sh: \d+: /', $ver) || strpos($ver, 'not found') !== false) {
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
            @file_put_contents($version_cache_file, json_encode(['ver' => $ver, 'hash' => $hash, 'exp' => time() + 3600]));
        } elseif ($ver === 'not installed') {
            @file_put_contents($version_cache_file, json_encode(['ver' => $ver, 'hash' => '', 'exp' => time() + 3600]));
        }
    }
}

// Cache ffmpeg version similarly — running `ffmpeg -version` on every health check
// is wasteful and adds latency under load. Tracks hash to invalidate on binary upgrade.
// Uses FFPROBE_PATH for hash computation to stay consistent with the actual binary used
// by the post-download verification probe — if FFPROBE_PATH changes, the cache is
// automatically invalidated because the hash will not match.
$ffmpeg_cache_file = '/tmp/ahoyrip_ffmpeg_ver.cache';
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
    // Use FFPROBE_PATH (not hardcoded 'ffmpeg') so the version probe matches
    // the binary whose hash is used as the cache key. If FFPROBE_PATH points
    // to a non-standard location (e.g. /usr/local/bin/ffprobe on macOS), the
    // version and hash now correctly reference the same binary.
    $ffmpeg_ver = trim(shell_exec(FFPROBE_PATH . ' -version 2>&1 | head -1') ?: '');
    $GLOBALS['__ffmpeg_version'] = $ffmpeg_ver ?: 'not installed';
    if ($ffmpeg_cache_file) {
        $hash = @md5_file(FFPROBE_PATH);
        // Only write to cache when we successfully read the binary.
        // If md5_file fails, skip cache write so the next request re-probes
        // rather than persisting an invalid empty hash that masks binary upgrades.
        if ($hash !== false) {
            @file_put_contents($ffmpeg_cache_file, json_encode(['ver' => $GLOBALS['__ffmpeg_version'], 'hash' => $hash, 'exp' => time() + 3600]));
        }
    }
}

// runYtdlp runs yt-dlp with the given arguments and captures stdout/stderr.
// $timeout = max seconds (0 = no limit). The referer is always set to
// ahoyripper.com to avoid leaking the user's video URL to source sites.
function runYtdlp($args, &$stdout, &$stderr, &$exit, $timeout = 0) {
    // Defensive cap: even if a caller passes an unbounded timeout, cap it at 5 minutes
    // to prevent runaway processes. yt-dlp downloads are bounded by the format
    // selection; a 5-minute metadata-only operation covers all reasonable cases.
    static $MAX_YTDLP_TIMEOUT = 300;
    if ($timeout <= 0 || $timeout > $MAX_YTDLP_TIMEOUT) {
        $timeout = $MAX_YTDLP_TIMEOUT;
    }

    // Build the command as an array so bypass_shell works as intended.
    // Shell redirection ('2>&1') is unnecessary — we capture stderr via pipe.
    $ytdlp_bin = YTDLP_PATH;
    // Split args preserving quoted strings (handles $shell_url = "'https://...'")
    $parts = preg_split('/\s+(?=(?:[^"\']|["\'][^"\']*["\'])*$)/', trim($args));
    $cmd = array_merge([$ytdlp_bin], $parts);
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = null;
    $proc = proc_open($cmd, $desc, $pipes, '/tmp', [], ['bypass_shell' => true]);

    if (!$proc) {
        $exit = -1;
        return false;
    }

    // Close stdin immediately — yt-dlp doesn't need interactive input
    // and an unclosed stdin pipe can cause the process to hang
    fclose($pipes[0]);
    unset($pipes[0]);

    // Use hrtime(true) for sub-second timeout precision. time() has only 1-second
    // resolution — a 15s timeout can fire at t=15.999s, adding nearly a full extra
    // second of latency to every timed-out health probe. hrtime(true) returns
    // nanoseconds on Linux (converted to a float here), giving true sub-second
    // accuracy so the process is terminated within milliseconds of the target timeout.
    // stream_set_timeout (set below) is intentionally left at 0 (infinite) — the
    // global hrtime() check is the authoritative timeout; stream_set_timeout would
    // stall the loop on spurious expiry since feof is not set until the process
    // closes its pipes, causing indefinite hangs.
    stream_set_timeout($pipes[1], 0);
    stream_set_timeout($pipes[2], 0);

    $stdout = '';
    $stderr = '';
    $start = hrtime(true);

    while (!feof($pipes[1]) || !feof($pipes[2])) {
        if ($timeout > 0 && (hrtime(true) - $start) / 1e9 > $timeout) {
            proc_terminate($proc, 9);
            $stderr .= "\nProcess timed out after {$timeout}s";
            $exit = -1; // convention: -1 = timeout
            // proc_terminate kills the process but leaves its handle open until
            // proc_close() is called. Close the handle immediately so the descriptor
            // is not leaked. Setting $proc = null after proc_close() prevents the
            // post-loop cleanup from attempting a second close on the already-closed
            // handle (double proc_close on the same descriptor is undefined behavior).
            // This mirrors the pattern used by the ffprobe probe (line ~2432) and the
            // health probe (line ~2906): proc_terminate followed immediately by
            // proc_close, with null sentinel to guard the post-loop cleanup.
            proc_close($proc);
            $proc = null;
            // Close any remaining open pipes first to release handles.
            foreach ($pipes as $p) { if ($p) fclose($p); }
            $pipes = null;
            // $stderr is already populated with the timeout message (line 508 above).
            // The $stdout reference parameter is intentionally left empty on timeout
            // since there is no valid JSON to parse from a timed-out process.
            return false;
        }
        $read = [];
        if (!feof($pipes[1])) $read[] = $pipes[1];
        if (!feof($pipes[2])) $read[] = $pipes[2];
        if (empty($read)) break;
        $w = $e = null;
        $changed = @stream_select($read, $w, $e, 1, 0);
        if ($changed === false) {
            // stream_select error — log and continue to avoid blocking on spurious failure
            usleep(100000);
            continue;
        }
        if ($changed === 0) {
            usleep(100000);
            continue;
        }
        foreach ($read as $p) {
            if ($p === $pipes[1]) {
                $s = fread($p, 8192);
                if ($s === false || $s === '') {
                    if (feof($pipes[1])) {
                        fclose($pipes[1]);
                        $pipes[1] = null;
                    }
                    continue;
                }
                $stdout .= $s;
            } elseif ($p === $pipes[2]) {
                $s = fread($p, 8192);
                if ($s === false || $s === '') {
                    if (feof($pipes[2])) {
                        fclose($pipes[2]);
                        $pipes[2] = null;
                    }
                    continue;
                }
                $stderr .= $s;
            }
        }
        // Exit when both pipes are closed
        if ($pipes[1] === null && $pipes[2] === null) break;
    }

    foreach ($pipes as $p) { if ($p) fclose($p); }
    $pipes = null;
    // Only call proc_close if $proc is still open (null sentinel means timeout
    // handler already closed it to avoid double-close).
    $exit = ($proc !== null) ? proc_close($proc) : -1;
    return true;
}

// Sanitize string for JSON output
function clean($s) {
    // Return 'Unknown' for null or empty string only.
    // Integer 0 is NOT treated as Unknown — it is a valid numeric value that
    // appears in yt-dlp metadata (e.g., height=0 for audio-only formats).
    // Passing 0 through as '0' (string) keeps the UI consistent and prevents
    // silent label corruption (e.g., "0kbps m4a" would become "Unknown kbps m4a").
    if ($s === null || $s === '') return 'Unknown';
    // Reject booleans, arrays and objects — yt-dlp metadata is always scalar
    // (string, int, float, or null). A boolean in a format label field would
    // become "1" or "" (empty string) via (string) cast, corrupting the label
    // silently. An array/object would become the literal string "Array", also
    // corrupting the API response. Return 'Unknown' for all of these.
    if (is_bool($s) || is_array($s) || is_object($s)) return 'Unknown';
    // No htmlspecialchars — API outputs JSON, not HTML.
    // Type coercion to string is sufficient.
    return (string)$s;
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
function classifyYtdlpError($raw_err) {
    $err_lower = strtolower($raw_err);
    if (preg_match('/geo.*restriction|this video is available in|geo.?restricted/i', $err_lower)) {
        return ['code' => 'GEOBLOCKED', 'msg' => 'This video is geo-restricted and not available in your region.', 'status' => 451];
    }
    if (preg_match('/video is private|this video is private/i', $err_lower)) {
        return ['code' => 'PRIVATE_VIDEO', 'msg' => 'This video is private and cannot be downloaded.', 'status' => 403];
    }
    // "authentication required" must be checked separately because the merged pattern
    // "authentication.*required" requires the word "required" to appear twice —
    // yt-dlp only says it once ("authentication required"), so we match it directly.
    if (preg_match('/authentication required|login.*required|this video requires login/i', $err_lower)) {
        return ['code' => 'LOGIN_REQUIRED', 'msg' => 'This video requires login or subscription.', 'status' => 401];
    }
    if (preg_match('/not.*support|unsupported site|is not a supported URL/i', $err_lower)) {
        return ['code' => 'UNSUPPORTED_SITE', 'msg' => 'This site is not supported by yt-dlp.', 'status' => 404];
    }
    if (preg_match('/playlist.*not.*found|does not exist/i', $err_lower)) {
        return ['code' => 'PLAYLIST_MISSING', 'msg' => 'Playlist not found or no longer exists.', 'status' => 404];
    }
    if (preg_match('/copyright|infringe|removed.*by|content.*strike/i', $err_lower)) {
        return ['code' => 'COPYRIGHT_REMOVED', 'msg' => 'This content has been removed due to a copyright claim.', 'status' => 451];
    }
    if (preg_match('/too.*many.*requests|429/i', $err_lower)) {
        return ['code' => 'SOURCE_RATE_LIMITED', 'msg' => 'The source site is rate-limiting requests. Try again in a few minutes.', 'status' => 429];
    }
    if (preg_match('/video (has been )?(removed|delisted|unavailable|deleted)|this video (is no longer available|has been (removed|delisted))|video (has been )?removed|video (is )?unavailable/i', $err_lower)) {
        return ['code' => 'VIDEO_UNAVAILABLE', 'msg' => 'This video is no longer available or has been removed.', 'status' => 410];
    }
    if (preg_match('/age.*restriction|under age|video is age.*restricted/i', $err_lower)) {
        return ['code' => 'AGE_RESTRICTED', 'msg' => 'This video is age-restricted and cannot be downloaded without verification.', 'status' => 403];
    }
    if (preg_match('/certificate.*expired|ssl.*error|sslerr|tls handshake/i', $err_lower)) {
        return ['code' => 'SSL_ERROR', 'msg' => 'Secure connection to the source failed. Try again shortly.', 'status' => 502];
    }

    // "process timed out" is produced by the PHP-side timeout in runYtdlp() (api.php).
    // Distinct from connection-level "timed out" which implies a network failure.
    // The PHP-side timeout fires when (time() - $start) > INFO_TIMEOUT (configurable
    // via YTDLP_TIMEOUT env var, default 45s) and terminates the yt-dlp process.
    // This means the server reached the source but it was too slow to respond within
    // the allowed window. Return 504 so the client distinguishes it from CONNECTION_FAILED
    // (502) which implies a network or DNS issue on our end.
    if (preg_match('/process timed out|read at byte.*timeout/i', $err_lower)) {
        return ['code' => 'SOURCE_TIMEOUT', 'msg' => 'The source site took too long to respond. Try a smaller format (audio-only is fastest) or try again when the site is less busy.', 'status' => 504];
    }

    // \b(?!process )timed out\b — "timed out" as a standalone word, NOT preceded
    // by "Process " (PHP-side timeout → SOURCE_TIMEOUT above) and NOT followed by
    // " after" (PHP timeout format: "Process timed out after 45s"). The negative
    // lookahead (?!) at word boundary rejects "Process timed out" at the word level
    // rather than relying solely on the (?<!Process ) lookbehind, making the intent
    // explicit and robust against future variations of the PHP timeout message.
    // \bi?/o timeout\b — IO timeout as a standalone word (handles "i/o timeout").
    if (preg_match('#connection.*fail|dns.*fail|could not connect|\bi?/o timeout\b|connection timed out|\b(?!process )timed out\b|connection reset|broken pipe|unable to connect|connection refused|getaddrinfo failed|name or service not known|network is unreachable|no route to host#i', $err_lower)) {
        return ['code' => 'CONNECTION_FAILED', 'msg' => 'Could not connect to the source. Check your network and try again.', 'status' => 502];
    }
    if (preg_match('/file.*larger|size.*exceed|exceeds.*limit/i', $err_lower)) {
        return ['code' => 'FILE_TOO_LARGE', 'msg' => 'This file exceeds the maximum size for this server. Try an audio-only or lower-resolution format.', 'status' => 413];
    }
    if (preg_match('/requested format(?!s)|requested.*not.*available|format.*not.*available|does not contain|does not match/i', $err_lower)) {
        return ['code' => 'FORMAT_UNAVAILABLE', 'msg' => 'That format is not available for this video. Select another from the list.', 'status' => 422];
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
        return ['code' => 'DISALLOWED_CONTENT', 'msg' => 'This content is not available due to a terms of service or legal violation.', 'status' => 451];
    }
    // HTTP error responses from the source site (e.g. "HTTP Error 403: Forbidden").
    // yt-dlp emits these when the source returns a non-2xx status. The numeric
    // status is extracted from the message for classification; 403/404/429 are the
    // most common and map to existing error codes. Others fall through to a generic
    // upstream HTTP error response.
    if (preg_match('/http error (\d+)/i', $err_lower, $m)) {
        $code = (int)$m[1];
        if ($code === 403) {
            return ['code' => 'SOURCE_FORBIDDEN', 'msg' => 'The source site blocked this request (HTTP 403). Try a different format or use AhoyVPN to change your exit IP.', 'status' => 403];
        }
        if ($code === 401 || $code === 407) {
            return ['code' => 'LOGIN_REQUIRED', 'msg' => 'This content requires authentication. Sign in to the platform in your browser, or pass cookies to yt-dlp (see README).', 'status' => 401];
        }
        if ($code === 404) {
            return ['code' => 'SOURCE_NOT_FOUND', 'msg' => 'The source returned HTTP 404 — the content may have been moved or deleted.', 'status' => 404];
        }
        if ($code === 429) {
            return ['code' => 'SOURCE_RATE_LIMITED', 'msg' => 'The source site is rate-limiting requests. Try again in a few minutes.', 'status' => 429];
        }
        if ($code === 500 || $code === 502 || $code === 503) {
            return ['code' => 'SOURCE_SERVER_ERROR', 'msg' => "The source site returned HTTP $code and is having issues. Try again shortly.", 'status' => 502];
        }
        // Other HTTP errors — surface the status but give a generic message.
        return ['code' => 'SOURCE_HTTP_ERROR', 'msg' => "The source site returned HTTP $code. Try again shortly.", 'status' => 502];
    }
    return null;
}

// Parse yt-dlp output to extract formats
// $sort: one of 'height' (default), 'filesize', 'filesize_asc', 'tbr', 'quality' — validated by caller
function parseFormats($json_str, &$raw_error_out = null, $sort = 'height') {
    $data = json_decode($json_str, true);
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
        // Differentiate yt-dlp errors from actual parsing failures
        $raw = trim($json_str);
        if (preg_match('/^(ERROR|WARNING)/im', $raw)) {
            // yt-dlp returned an error message — surface it clearly.
            // Whitespace-normalize the full message first (before truncation), so
            // classification patterns have the best chance of matching.
            $err_msg = preg_replace('/[\x00-\x1F\x7F]/', '', $raw);
            $err_msg = strip_tags($err_msg);
            $err_msg = preg_replace('/\s+/', ' ', $err_msg);

            // Classify on the FULL message — truncation would discard the tail of
            // long errors that may contain the distinguishing keyword (e.g. "login required"
            // at byte 300 of a 500-byte message). The classified['msg'] is always short
            // so it never needs truncation; the user-facing error uses that short message.
            $classified = classifyYtdlpError($err_msg);
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
                return [
                    'error' => $classified['msg'],
                    'error_code' => $classified['code'],
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
            return ['error' => 'yt-dlp error: ' . $err_msg, 'error_code' => 'YTDLP_ERROR', 'raw_error' => $err_msg];
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
        return ['error' => 'Could not parse video info. The site may not be supported or returned a non-standard response.', 'error_code' => 'PARSE_ERROR', 'raw_error' => $parse_fail_msg];
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
    $raw_fn = preg_replace('/[^\w\s.-]/', '', $title);
    $raw_fn = preg_replace('/\s+/', '_', trim($raw_fn));
    if (strlen($raw_fn) > 80) $raw_fn = substr($raw_fn, 0, 80);
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
                if ($format_note) $label .= " {$format_note}";
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
            }
        }

        $is_combined = ($vcodec !== 'none' && $acodec !== 'none');
        $is_video_only = ($vcodec !== 'none' && $acodec === 'none');
        $is_audio_only = ($vcodec === 'none' && $acodec !== 'none');
        $formats[] = [
            'id' => $format_id,
            'label' => $label,
            'description' => $desc,
            'format_description' => $format_description,
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
    // $sort is one of 'height' (default), 'filesize', 'filesize_asc', 'tbr', 'quality' — validated by the
    // caller before being passed in, so no additional validation is needed here.
    usort($formats, function($a, $b) use ($sort) {
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
        if ($sort === 'filesize') {
            $cmp = ($b['filesize_mb'] ?? 0) <=> ($a['filesize_mb'] ?? 0);
        } elseif ($sort === 'filesize_asc') {
            $cmp = ($a['filesize_mb'] ?? 0) <=> ($b['filesize_mb'] ?? 0);
        } elseif ($sort === 'tbr') {
            $cmp = ($b['tbr'] ?? 0) <=> ($a['tbr'] ?? 0);
        } elseif ($sort === 'quality') {
            $cmp = ($b['quality'] ?? -1) <=> ($a['quality'] ?? -1);
        } else {
            $cmp = ($b['height'] ?? 0) <=> ($a['height'] ?? 0);
        }
        // Secondary: within same type group, sort by height descending for consistency.
        // When height is also equal, prefer higher fps (60fps > 30fps > 24fps) so
        // smoother formats appear first within the same resolution tier.
        if ($cmp === 0) {
            $cmp = ($b['height'] ?? 0) <=> ($a['height'] ?? 0);
        }
        if ($cmp === 0) {
            $cmp = ($b['fps'] ?? 0) <=> ($a['fps'] ?? 0);
        }
        // Tertiary: within same type + height + fps, highest tbr wins.
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
    header('X-DailyLimit-Limit: ' . $limit);
    header('X-DailyLimit-Remaining: ' . ($remaining ?? $limit));
    header('X-DailyLimit-Reset: ' . strtotime('tomorrow midnight UTC'));
    header('X-DailyLimit-Window: 86400');
};

$validation = function(string $action) use($request_id, $sendDailyLimitHeaders) {
    // Determine the daily limit from the environment to include in error
    // responses. This is the configured limit, not the user's remaining quota
    // (quota tracking is not available at this early validation stage).
    $daily_limit = max(0, (int)(getenv('QUOTA_DAILY') ?? QUOTA_DAILY_DEFAULT));

    $url = trim($_GET['url'] ?? $_POST['url'] ?? '');
    if (!$url) {
        http_response_code(400);
        logRequest($action, 400, ['reason' => 'missing_url']);
        $sendDailyLimitHeaders($daily_limit, null);
        echo json_encode([
            'error' => 'No URL was provided. Paste a valid link from YouTube, Twitter, SoundCloud, TikTok, Instagram, etc.',
            'error_code' => 'MISSING_URL',
            'request_id' => $request_id,
            'source_url' => null,
            'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        return false;
    }
    if (!isValidUrl($url)) {
        http_response_code(400);
        logRequest($action, 400, ['reason' => 'invalid_url']);
        $sendDailyLimitHeaders($daily_limit, null);
        echo json_encode([
            'error' => 'Invalid URL. Please paste a valid video link.',
            'error_code' => 'INVALID_URL',
            'request_id' => $request_id,
            'source_url' => $url,
            'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        return false;
    }
    // Enforce the shared URL length limit so clients get consistent error codes
    // regardless of which action they call. Uses the shared MAX_URL_LEN constant.
    // The download action previously duplicated this check here as a workaround;
    // centralising it in the validation helper ensures both actions are covered.
    if (strlen($url) > MAX_URL_LEN) {
        http_response_code(400);
        logRequest($action, 400, ['reason' => 'url_too_long', 'url_len' => strlen($url)]);
        $sendDailyLimitHeaders($daily_limit, null);
        echo json_encode([
            'error' => 'URL is too long. Please paste a shorter link.',
            'error_code' => 'INVALID_URL',
            'request_id' => $request_id,
            'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
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
            $sendDailyLimitHeaders($daily_limit, null);
            echo json_encode([
                'error' => 'Select a format from the list above first, then click it to download.',
                'error_code' => 'MISSING_FORMAT',
                'request_id' => $request_id,
                'source_url' => $url,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            return false;
        }
        // Validate format_id character-class — reject shell metacharacters that could
        // survive into proc_open args even with bypass_shell=true (e.g. whitespace
        // tokens, command substitutions, glob patterns). yt-dlp selectors and merge
        // syntax (bestvideo[height>=720]+bestaudio, 18/22, etc.) are all alphanumeric
        // plus the safe chars in the character class below. This mirrors the validation
        // already present in the download action (line ~1888) and is checked here so the
        // info action fails fast with INVALID_FORMAT_ID before wasting any yt-dlp cycles.
        if (!preg_match('/^[a-zA-Z0-9_.,<>=!\\[\\]+\\/-~()*%@!\'"]+$/', $format_id)) {
            http_response_code(400);
            logRequest($action, 400, ['reason' => 'invalid_format_id', 'format_id' => $format_id]);
            $sendDailyLimitHeaders($daily_limit, null);
            echo json_encode([
                'error' => 'That format ID was not recognized. Refresh to get a fresh format list, then pick a valid format from the list.',
                'error_code' => 'INVALID_FORMAT_ID',
                'request_id' => $request_id,
                'source_url' => $url,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
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
define('AHOY_UNLIMITED_KEY', getenv('AHOY_UNLIMITED_KEY') ?: 'RIPPER2026DEV');

// Configurable User-Agent — follows the same env-var pattern as AHOY_UNLIMITED_KEY.
// Override via AHOY_USER_AGENT env var in docker-compose or cloud dashboard.
// Used by all yt-dlp invocations (info, download) so agents stay consistent.
define('AHOY_USER_AGENT', getenv('AHOY_USER_AGENT') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36');

// Path to a Netscape-format cookies.txt file for authenticated requests
// (age-restricted YouTube, Spotify, etc.). Set via COOKIES_PATH env var or
// docker-compose. When absent or empty, no --cookies flag is passed to yt-dlp.
// See README.md "Passing cookies to yt-dlp" for setup instructions.
define('COOKIES_PATH', getenv('COOKIES_PATH') ?: '');

// Shared constant: maximum URL length in characters.
// Both info and download actions enforce this same limit so clients get
// consistent error codes (INVALID_URL) regardless of which action they call.
define('MAX_URL_LEN', 2048);

// Configurable timeout for the health probe (lightweight yt-dlp metadata fetch).
// Override via HEALTH_PROBE_TIMEOUT env var (e.g. HEALTH_PROBE_TIMEOUT=20 in .env).
// Defaults to 15 seconds. The probe is a simple --dump-json --skip-download call
// on a known-short video (Rick Astley), so 15s is plenty. A shorter timeout keeps
// the /health endpoint responsive under load. The yt-dlp --socket-timeout flag
// is set to half this value so the inner connection timeout fires before the outer
// PHP-side loop timeout, producing a clean CONNECTION_TIMEOUT classification.
define('HEALTH_PROBE_TIMEOUT', max(5, (int)getenv('HEALTH_PROBE_TIMEOUT') ?: 15));

// Configurable timeout for the info action (metadata fetch).
// Override via YTDLP_TIMEOUT env var (e.g. YTDLP_TIMEOUT=60 in .env).
// Defaults to 45 seconds when the env var is absent or zero/negative.
// This is the PHP-side timeout — distinct from yt-dlp's own connection timeout.
// The PHP-side timeout fires when (time() - $start) > INFO_TIMEOUT and terminates
// the process, producing "Process timed out after Ns" in the error output.
// yt-dlp's own --socket-timeout flag controls per-connection timeouts separately.
define('INFO_TIMEOUT', max(1, (int)getenv('YTDLP_TIMEOUT') ?: 45));

// Download rate limit: max download requests per minute per IP.
// Override via DL_RATE_LIMIT env var in .env or docker-compose.
// Named in all-caps to match the env-var convention used throughout this file.
// When absent or zero/negative, falls back to 10.
define('DL_RATE_LIMIT', max(1, (int)getenv('DL_RATE_LIMIT') ?: 10));

// Configurable timeout for the download action (file download).
// Override via YTDLP_DOWNLOAD_TIMEOUT env var (e.g. YTDLP_DOWNLOAD_TIMEOUT=120 in .env).
// Defaults to 300 seconds (5 minutes) when the env var is absent or zero/negative.
// The download action is I/O-bound (large media files) and needs a longer timeout
// than the info action (metadata fetch). INFO_TIMEOUT controls info; this constant
// controls download so the two can be tuned independently without compromise.
define('DOWNLOAD_TIMEOUT', max(1, (int)getenv('YTDLP_DOWNLOAD_TIMEOUT') ?: 300));

// Default daily quota for unauthenticated users (free tier).
// Override via QUOTA_DAILY env var in .env or docker-compose.
// Named with _DEFAULT suffix to distinguish from the runtime $daily_limit variable
// and to signal that this is a compile-time fallback, not the runtime value.
define('QUOTA_DAILY_DEFAULT', 5);

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
    // Rate-limit headers on 405: check is not a download action (X-DL-RateLimit=-1)
    // and has no per-minute ceiling (X-RateLimit=-1, not 0). -1 is the sentinel for
    // "no rate limit applies" — 0 means "rate limit exhausted" which is wrong here.
    // Daily limit is also inapplicable (-1). Including these on error responses gives
    // API clients consistent header coverage regardless of which code path they hit.
    // Mirrors the header set sent on the 200 response for the check action.
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
        'request_id' => $request_id,
    ]);
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
    // Consistent with the METHOD_NOT_ALLOWED (405) response: include all
    // rate-limit headers so API clients always get complete header coverage
    // regardless of which early-exit code path they hit.
    header('X-RateLimit-Limit: -1');
    header('X-RateLimit-Remaining: -1');
    header('X-RateLimit-Reset: -1');
    header('X-RateLimit-Window: unlimited');
    // info action is subject to daily quota; others (check, health, progress) are not.
    if ($action === 'info') {
        $dl = max(0, (int)(getenv('QUOTA_DAILY') ?? QUOTA_DAILY_DEFAULT));
        header('X-DailyLimit-Limit: ' . $dl);
        header('X-DailyLimit-Remaining: ' . $dl);
        header('X-DailyLimit-Reset: ' . strtotime('tomorrow midnight UTC'));
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
        'request_id' => $request_id,
        'received_accept' => $accept,
        'hint' => 'Send Accept: */* or Accept: application/json',
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

switch ($action) {
// ─── Daily download quota (free tier limit, skip if unlimited key) ───
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
        $allowed_sorts = ['height', 'filesize', 'filesize_asc', 'tbr', 'quality'];
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
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
            echo json_encode([
                'error' => 'Invalid API key.',
                'error_code' => 'INVALID_KEY',
                'request_id' => $request_id,
            ]);
            exit;
        }
        $unlimited = ($api_key !== null && hash_equals(AHOY_UNLIMITED_KEY, $api_key));

        // ─── Daily download quota (free tier limit, skip if unlimited key) ───
        // Key must be read BEFORE this point so $unlimited is available for the
        // quota gate. The key-reading block is placed immediately below so it
        // runs before any stateful operations (rate limit, quota).
        // NOTE: $unlimited is declared at line 868 as `false` by default — it is
        // set to true here only when a valid key is present.
        if (!$unlimited) {
            // Use the same $ip variable declared above for the rate-limit gate.
            // Both info and download actions share the same daily-quota file so
            // that a user hitting 5 info calls has no download quota left.
            $daily_file = '/tmp/ahoyrip_daily_' . md5($ip);
            // Override via QUOTA_DAILY env var (e.g. QUOTA_DAILY=100 in .env).
            // Defaults to QUOTA_DAILY_DEFAULT (5) when the env var is absent. Set to 0
            // or -1 to disable the free tier entirely (unlimited-key required).
            $daily_limit = max(0, (int)(getenv('QUOTA_DAILY') ?? QUOTA_DAILY_DEFAULT));
            $daily_fp = fopen($daily_file, 'c+');
            if (!$daily_fp) {
                http_response_code(503);
                header('Retry-After: 5');
                echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            if (!flock($daily_fp, LOCK_EX)) {
                fclose($daily_fp);
                http_response_code(503);
                header('Retry-After: 5');
                echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id], JSON_INVALID_UTF8_SUBSTITUTE);
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
                $reset_timestamp = strtotime('tomorrow midnight UTC');
                header('Retry-After: ' . max(0, $reset_timestamp - time()));
                header('X-DailyLimit-Limit: ' . $daily_limit);
                header('X-DailyLimit-Remaining: 0');
                header('X-DailyLimit-Reset: ' . $reset_timestamp);
                header('X-DailyLimit-Window: 86400');
                echo json_encode([
                    'error' => "Daily limit reached. You get {$daily_limit} free rips per day. For unlimited access, get AhoyVPN.",
                    'error_code' => 'DAILY_LIMIT',
                    'upgrade_url' => 'https://ahoyvpn.com',
                    'daily_limit' => $daily_limit,
                    'retry_after' => max(0, (int)($reset_timestamp - time())),
                    'request_id' => $request_id,
                ]);
                exit;
            }
            $daily_data['c']++;
            $daily_remaining = max(0, $daily_limit - $daily_data['c']);
            $info_quota_before_refund = $daily_data['c'];
            ftruncate($daily_fp, 0);
            rewind($daily_fp);
            fwrite($daily_fp, json_encode($daily_data));
            fflush($daily_fp);
            flock($daily_fp, LOCK_UN);
            fclose($daily_fp);  // explicitly close to release lock without waiting for GC
            $daily_fp = null;

            // Surface daily quota state so the client can display remaining rips.
            // Use the remaining count calculated AFTER the increment so the value
            // reflects the number of rips available AFTER this request's quota hit.
            header('X-DailyLimit-Limit: ' . $daily_limit);
            header('X-DailyLimit-Remaining: ' . $daily_remaining);
            header('X-DailyLimit-Reset: ' . strtotime('tomorrow midnight UTC'));
            header('X-DailyLimit-Window: 86400');
        } else {
            // Unlimited-key holder — quota does not apply, signal this to the
            // client with -1 so it can hide the "N free rips/day" UI element.
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
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
        header('X-RateLimit-Limit: 30');
        header('X-RateLimit-Remaining: ' . $info_rate_remaining);
        header('X-RateLimit-Reset: ' . $info_rate_reset);
        header('X-RateLimit-Window: 60');

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
        // --playlist: mirrors the download action — pass the user's explicit playlist
        // preference so the info action behaves consistently with the download action.
        // When playlist=1, --yes-playlist fetches info for all videos in a playlist.
        // When playlist=0/absent, --no-playlist fetches info for the single video.
        $socket_timeout = max(1, INFO_TIMEOUT - 5);
        $playlist_flag = isset($_GET['playlist']) && $_GET['playlist'] === '1' ? '--yes-playlist' : '--no-playlist';
        $ytdlp_cmd = [
            YTDLP_PATH,
            '--dump-json',
            $playlist_flag,
            '--skip-download',
            // --progress-template false: suppress all progress output (replaces the
            // deprecated --no-progress flag). yt-dlp emits progress template noise
            // even during --skip-download which would prepend garbage to stderr
            // and corrupt json_decode on stdout. The empty-string form (below) is an
            // alternative; 'false' is the canonical modern yt-dlp syntax for this.
            '--progress-template', 'false',
            // NOTE: --no-warnings is deliberately NOT used in the info action.
            // yt-dlp emits its error/warning messages to stderr, and
            // classifyYtdlpError() reads $proc_stderr to classify failures
            // (GEOBLOCKED, AGE_RESTRICTED, LOGIN_REQUIRED, etc.).
            // Suppressing warnings via --no-warnings would empty $proc_stderr
            // and break error classification on the info action.
            // yt-dlp progress output is already suppressed via --progress-template false,
            // so --no-warnings is redundant for that purpose anyway.
            // --progress-template false: suppress ALL progress output to stderr.
            '--socket-timeout', (string)$socket_timeout,
            '--retries', '3',
            '--referer', 'https://ahoyripper.com/',
            '--user-agent', AHOY_USER_AGENT,
        ];
        // Add --cookies if COOKIES_PATH is configured (enables authenticated ripping
        // for age-restricted YouTube, Spotify, etc.). See README.md cookie instructions.
        if (COOKIES_PATH !== '') {
            $ytdlp_cmd[] = '--cookies';
            $ytdlp_cmd[] = COOKIES_PATH;
        }
        $ytdlp_cmd = array_merge($ytdlp_cmd, [
            '--add-header', 'Accept-Language: ' . preg_replace('/[^\x20-\x7E]/', '', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en-US;q=0.9,*;q=0.5'),
            '--',
            $url,
        ]);
        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = null;
        $proc = proc_open($ytdlp_cmd, $desc, $pipes, '/tmp', [], ['bypass_shell' => true]);
        if (!$proc) {
            $exit = -1;
            $out = $err = '';
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
                    foreach ($pipes as $p) { if ($p) fclose($p); }
                    $pipes = null;
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
                // Use the same $ip variable declared at the top of the script so the undo
                // targets the correct daily-quota file regardless of which action ran.
                $undo_fp = fopen('/tmp/ahoyrip_daily_' . md5($ip), 'c+');
                if (!$undo_fp) {
                    // Could not open quota file — skip the refund rather than fail the response.
                    // Best-effort grace; the user is not charged when the refund mechanism fails.
                } elseif (flock($undo_fp, LOCK_EX)) {
                    $undo_raw = fread($undo_fp, 4096);
                    $undo_data = ['t' => gmdate('Y-m-d'), 'c' => 0];
                    if ($undo_raw) {
                        $decoded = json_decode($undo_raw, true);
                        if ($decoded && is_array($decoded)) $undo_data = $decoded;
                    }
                    // Only decrement if it's the current day's record
                    if ($undo_data['t'] === gmdate('Y-m-d') && $undo_data['c'] >= $info_quota_before_refund) {
                        $undo_data['c']--;
                        ftruncate($undo_fp, 0);
                        rewind($undo_fp);
                        fwrite($undo_fp, json_encode($undo_data));
                        fflush($undo_fp);
                    }
                    flock($undo_fp, LOCK_UN);
                    fclose($undo_fp);
                }
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
            $resp = [
                'error' => "Could not fetch that URL. $err_msg$version_info",
                'error_code' => 'YTDLP_ERROR',
                'action' => 'info',
                'request_id' => $request_id,
                'source_url' => $url,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
            ];
            if ($raw_err) {
                $resp['raw_error'] = $raw_err;
            }
            echo json_encode($resp, JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $parsed = parseFormats($out, $raw_err, $sort);
        if (!$parsed) {
            // Undo the quota increment — parseFormats returned null means the content
            // could not be parsed; we don't burn the user's daily limit for this.
            if (!$unlimited) {
                $undo_fp = fopen('/tmp/ahoyrip_daily_' . md5($ip), 'c+');
                if (!$undo_fp) {
                    // Could not open quota file — skip the refund rather than fail the response.
                    // Best-effort grace; the user is not charged when the refund mechanism fails.
                } elseif (flock($undo_fp, LOCK_EX)) {
                    $undo_raw = fread($undo_fp, 4096);
                    $undo_data = ['t' => gmdate('Y-m-d'), 'c' => 0];
                    if ($undo_raw) {
                        $decoded = json_decode($undo_raw, true);
                        if ($decoded && is_array($decoded)) $undo_data = $decoded;
                    }
                    if ($undo_data['t'] === gmdate('Y-m-d') && $undo_data['c'] >= $info_quota_before_refund) {
                        $undo_data['c']--;
                        ftruncate($undo_fp, 0);
                        rewind($undo_fp);
                        fwrite($undo_fp, json_encode($undo_data));
                        fflush($undo_fp);
                    }
                    flock($undo_fp, LOCK_UN);
                    fclose($undo_fp);
                }
            }
            $err_status = 422;
            logRequest('info', $err_status, ['reason' => 'parse_formats_failed', 'exit' => $exit]);
            http_response_code($err_status);
            $resp = [
                'error' => 'Could not parse video info. The site may not be supported or returned a non-standard response.',
                'error_code' => 'PARSE_ERROR',
                'action' => 'info',
                'request_id' => $request_id,
                'source_url' => $url,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
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
                'GEOBLOCKED' => 451, 'COPYRIGHT_REMOVED' => 451, 'DISALLOWED_CONTENT' => 451,
                'VIDEO_UNAVAILABLE' => 410,
                'SOURCE_RATE_LIMITED' => 429,
                'SOURCE_TIMEOUT' => 504,
                'PRIVATE_VIDEO' => 403, 'AGE_RESTRICTED' => 403, 'LOGIN_REQUIRED' => 401,
                'UNSUPPORTED_SITE' => 404, 'PLAYLIST_MISSING' => 404,
                'SSL_ERROR' => 502, 'CONNECTION_FAILED' => 502,
                'FILE_TOO_LARGE' => 413,
                'DOWNLOAD_EMPTY' => 500,
                'PROC_OPEN_FAILED' => 500,
                'DOWNLOAD_TIMEOUT' => 504,
                'DOWNLOAD_CANCELLED' => 499,
                'MISSING_URL' => 400, 'MISSING_FORMAT' => 400,
                'FORMAT_UNAVAILABLE' => 422,
                'YTDLP_ERROR' => 422, 'PARSE_ERROR' => 422,
                'SOURCE_FORBIDDEN' => 403, 'SOURCE_NOT_FOUND' => 404,
                'SOURCE_SERVER_ERROR' => 502, 'SOURCE_HTTP_ERROR' => 502,
            ];
            $err_status = $err_status_map[$parsed['error_code']] ?? 422;
            logRequest('info', $err_status, ['reason' => 'parse_formats_ytdlp_error', 'err_code' => $err_code]);
            // Undo the quota increment — parseFormats succeeded (returned a classified error
            // like GEOBLOCKED/PRIVATE_VIDEO) but the content is not downloadable. We don't
            // burn the user's daily limit for content that simply can't be ripped.
            // Refund guard: if parseFormats returned a classified error (GEOBLOCKED,
            // PRIVATE_VIDEO, etc.), the user burned a quota hit but got no usable
            // content. Undo the increment so it doesn't count against their daily cap.
            // Use the same >= guard as the download action to prevent double-refund
            // if proc_open ever fails without decrementing first.
            $info_quota_before_refund = $daily_data['c'];
            if (!$unlimited) {
                $undo_fp = fopen('/tmp/ahoyrip_daily_' . md5($ip), 'c+');
                if (!$undo_fp) {
                    // Could not open quota file — skip the refund rather than fail the response.
                    // Best-effort grace; the user is not charged when the refund mechanism fails.
                } elseif (flock($undo_fp, LOCK_EX)) {
                    $undo_raw = fread($undo_fp, 4096);
                    $undo_data = ['t' => gmdate('Y-m-d'), 'c' => 0];
                    if ($undo_raw) {
                        $decoded = json_decode($undo_raw, true);
                        if ($decoded && is_array($decoded)) $undo_data = $decoded;
                    }
                    if ($undo_data['t'] === gmdate('Y-m-d') && $undo_data['c'] >= $info_quota_before_refund) {
                        $undo_data['c']--;
                        ftruncate($undo_fp, 0);
                        rewind($undo_fp);
                        fwrite($undo_fp, json_encode($undo_data));
                        fflush($undo_fp);
                    }
                    flock($undo_fp, LOCK_UN);
                    fclose($undo_fp);
                }
            }
            http_response_code($err_status);
            $resp = [
                'error' => $parsed['error'],
                'request_id' => $request_id,
                'source_url' => $url,
                // yt_dlp_version helps clients debug which yt-dlp build is running
                // when a classified error (GEOBLOCKED, LOGIN_REQUIRED, etc.) is returned.
                // Included in success responses; add it here for parity on error responses.
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
            ];
            if (!empty($parsed['error_code'])) {
                $resp['error_code'] = $parsed['error_code'];
            }
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
        header('Cache-Control: no-cache');
        echo json_encode($parsed, JSON_INVALID_UTF8_SUBSTITUTE);
        logRequest('info', 200, ['url_type' => 'single', 'format_count' => count($parsed['formats'] ?? [])]);
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
            echo json_encode([
                'error' => 'Invalid API key.',
                'error_code' => 'INVALID_KEY',
                'request_id' => $request_id,
            ]);
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
            http_response_code(503);
            header('Retry-After: 5');
            echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (!flock($dl_fp, LOCK_EX)) {
            fclose($dl_fp);
            http_response_code(503);
            header('Retry-After: 5');
            echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id], JSON_INVALID_UTF8_SUBSTITUTE);
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
                header('Retry-After: ' . max(0, $dl_reset_ts - time()));
                // Include download rate-limit headers so clients can distinguish this
                // from the per-minute rate limit without parsing the error body.
                // Mirrors the X-DL-RateLimit-* family set on successful responses.
                header('X-DL-RateLimit-Limit: ' . $dl_rate_limit);
                header('X-DL-RateLimit-Remaining: 0');
                header('X-DL-RateLimit-Reset: ' . $dl_reset_ts);
                header('X-DL-RateLimit-Window: ' . $dl_rate_window);
                // Standard X-RateLimit-* family for generic API consumers.
                header('X-RateLimit-Limit: ' . $dl_rate_limit);
                header('X-RateLimit-Remaining: 0');
                header('X-RateLimit-Reset: ' . $dl_reset_ts);
                header('X-RateLimit-Window: ' . $dl_rate_window);
                echo json_encode([
                    'error' => 'Too many download requests. Slow down.',
                    'error_code' => 'RATE_LIMIT_EXCEEDED',
                    'upgrade_url' => 'https://ahoyvpn.com',
                    'retry_after' => max(0, (int)($dl_reset_ts - time())),
                    'request_id' => $request_id,
                ]);
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
            $daily_limit = max(0, (int)(getenv('QUOTA_DAILY') ?? QUOTA_DAILY_DEFAULT));
            $daily_fp = fopen($daily_file, 'c+');
            if (!$daily_fp) {
                http_response_code(503);
                header('Retry-After: 5');
                echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            if (!flock($daily_fp, LOCK_EX)) {
                fclose($daily_fp);
                http_response_code(503);
                header('Retry-After: 5');
                echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id], JSON_INVALID_UTF8_SUBSTITUTE);
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
                $reset_timestamp = strtotime('tomorrow midnight UTC');
                header('Retry-After: ' . max(0, $reset_timestamp - time()));
                header('X-DailyLimit-Limit: ' . $daily_limit);
                header('X-DailyLimit-Remaining: 0');
                header('X-DailyLimit-Reset: ' . $reset_timestamp);
                header('X-DailyLimit-Window: 86400');
                echo json_encode([
                    'error' => "Daily limit reached. You get {$daily_limit} free rips per day. For unlimited access, get AhoyVPN.",
                    'error_code' => 'DAILY_LIMIT',
                    'upgrade_url' => 'https://ahoyvpn.com',
                    'daily_limit' => $daily_limit,
                    'retry_after' => max(0, (int)($reset_timestamp - time())),
                    'request_id' => $request_id,
                ]);
                exit;
            }
            $daily_data['c']++;
            $daily_remaining = max(0, $daily_limit - $daily_data['c']);
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
            // Use the remaining count calculated AFTER the increment so the value
            // reflects the number of rips available AFTER this request's quota hit.
            header('X-DailyLimit-Limit: ' . $daily_limit);
            header('X-DailyLimit-Remaining: ' . $daily_remaining);
            header('X-DailyLimit-Reset: ' . strtotime('tomorrow midnight UTC'));
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
            if (strlen($trimmed) === 0 || strlen($trimmed) > 80) {
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

        // Prevent the user's video URL from leaking as HTTP Referer to the source.
        // yt-dlp sends the URL itself as referer by default; using the generic
        // ahoyripper.com referer hides the actual video URL from third-party servers.
        $referer = 'https://ahoyripper.com/';

        // --progress-template "" suppresses ALL progress output to stderr — without this,
        //   yt-dlp emits progress bars to stderr even during file downloads, which
        //   pollutes $proc_stderr and can prevent classifyYtdlpError() from matching
        //   actual error messages correctly (progress bar text prepends the real error).
        //   yt-dlp interprets '' (literal single-quotes) as template content, not empty.
        //   Use json_encode('') which produces "" (two adjacent double-quotes) in the
        //   argv string — same as the correct "" syntax yt-dlp expects for an empty
        //   progress template.
        // --concurrent-fragments was removed in yt-dlp 2024.10 — yt-dlp now handles
        // HLS/DASH fragment concurrency internally.
        // --socket-timeout: yt-dlp's per-connection timeout. Set to DOWNLOAD_TIMEOUT - 15s so
        // PHP's process-level timeout (DOWNLOAD_TIMEOUT) is always the outer limit and has time
        // to cleanly terminate the process and emit a classified DOWNLOAD_TIMEOUT error.
        // Without this, yt-dlp uses its own default (~20s) which can fire before PHP's timeout
        // and produce an unclassified error instead of DOWNLOAD_TIMEOUT.
        // --playlist: controls whether to download a playlist (all videos) or a single
        // video. yt-dlp treats --yes-playlist and --no-playlist as mutually exclusive
        // flags; the last one wins. Pass --no-playlist by default (playlist=0, the
        // default) so single-video URLs always get one video. Pass --yes-playlist when
        // playlist=1 is explicitly requested. Note: passing --yes-playlist via the
        // format field does NOT work — playlist flags must appear BEFORE the URL.
        $playlist = isset($_GET['playlist']) && $_GET['playlist'] === '1' ? '--yes-playlist' : '--no-playlist';
        $socket_timeout = max(1, DOWNLOAD_TIMEOUT - 15);
        $ytdlp_cmd = [
            YTDLP_PATH,
            '-f', $format_id,
            '-o', $out_template,
            '--force-overwrites',
            '--retries', '3',
            $playlist,
            // --progress-template false: suppress all progress output (replaces the
            // deprecated --no-progress flag). yt-dlp emits progress template noise
            // to stderr during download which would corrupt classifyYtdlpError parsing.
            // Using 'false' (the canonical modern yt-dlp syntax) is cleaner than the
            // empty-string form and semantically identical (suppress all progress).
            '--progress-template', 'false',
            '--socket-timeout', (string)$socket_timeout,
            '--referer', $referer,
            '--user-agent', AHOY_USER_AGENT,
        ];
        // Add --cookies if COOKIES_PATH is configured (enables authenticated ripping
        // for age-restricted YouTube, Spotify, etc.). See README.md cookie instructions.
        if (COOKIES_PATH !== '') {
            $ytdlp_cmd[] = '--cookies';
            $ytdlp_cmd[] = COOKIES_PATH;
        }
        $ytdlp_cmd = array_merge($ytdlp_cmd, [
            '--add-header', 'Accept-Language: ' . preg_replace('/[^\x20-\x7E]/', '', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en-US;q=0.9,*;q=0.5'),
            '--',
            $url,
        ]);

        $pipes = null;
        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = proc_open($ytdlp_cmd, $desc, $pipes, '/tmp', [], ['bypass_shell' => true]);

        if (!$proc) {
            logRequest('download', 500, ['reason' => 'proc_open_failed']);
            // Refund daily quota since no download attempt was possible.
            // Only refund when the baseline was set (proc_open was attempted after
            // quota increment). Unlimited-key holders ($unlimited=true) skip
            // increment so no refund needed.
            if (!$unlimited && isset($dl_quota_before_refund)) {
                $undo_fp = fopen('/tmp/ahoyrip_daily_' . md5($ip), 'c+');
                if (!$undo_fp) {
                    // Could not open quota file — skip the refund rather than fail the response.
                    // Best-effort grace; the user is not charged when the refund mechanism fails.
                } elseif (flock($undo_fp, LOCK_EX)) {
                    $undo_raw = fread($undo_fp, 4096);
                    $undo_data = ['t' => gmdate('Y-m-d'), 'c' => 0];
                    if ($undo_raw) {
                        $decoded = json_decode($undo_raw, true);
                        if ($decoded && is_array($decoded)) $undo_data = $decoded;
                    }
                    if ($undo_data['t'] === gmdate('Y-m-d') && $undo_data['c'] >= $dl_quota_before_refund) {
                        $undo_data['c']--;
                        ftruncate($undo_fp, 0);
                        rewind($undo_fp);
                        fwrite($undo_fp, json_encode($undo_data));
                        fflush($undo_fp);
                    }
                    flock($undo_fp, LOCK_UN);
                    fclose($undo_fp);
                }
            }
            http_response_code(500);
            header('Cache-Control: no-cache');
            header('X-Request-ID: ' . $request_id);
            echo json_encode([
                'error' => 'Failed to start download process.',
                'error_code' => 'PROC_OPEN_FAILED',
                'request_id' => $request_id,
                'source_url' => $url,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
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
                // increment so no refund needed.
                if (!$unlimited && isset($dl_quota_before_refund)) {
                    $undo_fp = fopen('/tmp/ahoyrip_daily_' . md5($ip), 'c+');
                    if (!$undo_fp) {
                        // Could not open quota file — skip the refund rather than fail the response.
                    } elseif (flock($undo_fp, LOCK_EX)) {
                        $undo_raw = fread($undo_fp, 4096);
                        $undo_data = ['t' => gmdate('Y-m-d'), 'c' => 0];
                        if ($undo_raw) {
                            $decoded = json_decode($undo_raw, true);
                            if ($decoded && is_array($decoded)) $undo_data = $decoded;
                        }
                        if ($undo_data['t'] === gmdate('Y-m-d') && $undo_data['c'] >= $dl_quota_before_refund) {
                            $undo_data['c']--;
                            ftruncate($undo_fp, 0);
                            rewind($undo_fp);
                            fwrite($undo_fp, json_encode($undo_data));
                            fflush($undo_fp);
                        }
                        flock($undo_fp, LOCK_UN);
                        fclose($undo_fp);
                    }
                }
                logRequest('download', 504, ['reason' => 'timeout', 'timeout_seconds' => $timeout]);
                http_response_code(504);
                // retry_after: Unix timestamp when the download can be retried.
                // Set to now + the actual $timeout so the client has a consistent
                // future reset point to count down to regardless of the configured limit.
                $retry_ts = time() + $timeout;
                header('Retry-After: ' . max(0, $retry_ts));
                header('Cache-Control: no-store, must-revalidate');
                header('X-Request-ID: ' . $request_id);
                echo json_encode([
                    'error' => 'Download timed out after ' . $timeout . ' seconds. The file may be too large or the source is slow. Try a smaller format.',
                    'error_code' => 'DOWNLOAD_TIMEOUT',
                    'retry_after' => max(0, $retry_ts),
                    'request_id' => $request_id,
                    'source_url' => $url,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
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
            if (strlen($proc_err) > 200) $proc_err = substr($proc_err, 0, 200) . '...';
            $err_classified = classifyYtdlpError($proc_err);

            // Refund daily quota for any download failure — classified or not.
            // Whether the error is GEOBLOCKED (content unavailable) or an unexpected
            // yt-dlp exit (e.g. network glitch, source timeout), the user didn't
            // successfully download anything, so the quota should not be burned.
            // Skip refund only for successful exits and when the user is on the
            // free tier ($unlimited is false) — unlimited-key holders never had
            // their quota incremented in the first place.
            // Uses pre-read file approach to handle proc_open failure gracefully:
            // if proc_open failed it decremented before us, so we skip our decrement
            // to avoid double-refunding. This is the baseline for the at-most-once refund.
            if (!$unlimited) {
                $undo_fp = fopen('/tmp/ahoyrip_daily_' . md5($ip), 'c+');
                if (!$undo_fp) {
                    // Could not open quota file — skip the refund rather than fail the response.
                    // Best-effort grace; the user is not charged when the refund mechanism fails.
                } elseif (flock($undo_fp, LOCK_EX)) {
                    $undo_raw = fread($undo_fp, 4096);
                    $undo_data = ['t' => gmdate('Y-m-d'), 'c' => 0];
                    if ($undo_raw) {
                        $decoded = json_decode($undo_raw, true);
                        if ($decoded && is_array($decoded)) $undo_data = $decoded;
                    }
                    // Only decrement if the stored count is at or above our baseline
                    // (meaning proc_open hasn't already decremented). If proc_open
                    // failed and decremented first, our count is already lower — skip.
                    if ($undo_data['t'] === gmdate('Y-m-d') && $undo_data['c'] >= $dl_quota_before_refund) {
                        $undo_data['c']--;
                        ftruncate($undo_fp, 0);
                        rewind($undo_fp);
                        fwrite($undo_fp, json_encode($undo_data));
                        fflush($undo_fp);
                    }
                    flock($undo_fp, LOCK_UN);
                    fclose($undo_fp);
                }
            }

            if ($err_classified) {
                $status = $err_classified['status'] ?? 422;
                logRequest('download', $status, ['reason' => 'ytdlp_error_classified', 'err_code' => $err_classified['code']]);
                http_response_code($status);
                $resp = [
                    'error' => $err_classified['msg'],
                    'error_code' => $err_classified['code'],
                    'request_id' => $request_id,
                    'source_url' => $url,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
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
                // Truncate the user-facing error message to match the ~200-char ceiling used
                // throughout the rest of the API (parseFormats YTDLP_ERROR, classified errors).
                // The full raw error is preserved in 'raw_error' for diagnostics.
                $user_err = $proc_err ?: "exit code $actual_exit";
                if (mb_strlen($user_err, 'UTF-8') > 200) {
                    $user_err = mb_substr($user_err, 0, 200, 'UTF-8') . '...';
                }
                $resp = [
                    'error' => "Download failed" . ($proc_err ? ": $user_err" : " (exit code $actual_exit)."),
                    'error_code' => 'YTDLP_ERROR',
                    'request_id' => $request_id,
                    'source_url' => $url,
                    'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                    'api_version' => AHOYRIPPER_VERSION,
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

        if (!$actual_file || !is_file($actual_file)) {
            foreach (glob($glob_pattern) as $f) { @unlink($f); }
            logRequest('download', 500, ['reason' => 'empty_or_missing_file', 'format_id' => $format_id]);
            http_response_code(500);
            header('Cache-Control: no-store, must-revalidate');
            header('X-Request-ID: ' . $request_id);
            echo json_encode([
                'error' => 'Download failed: the source returned an empty file. This is a server-side issue, not a format problem. Please try again in a moment or choose a different format.',
                'error_code' => 'DOWNLOAD_EMPTY',
                'request_id' => $request_id,
                'source_url' => $url,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        // Clear stat cache before reading filesize — glob() uses cached directory
        // entries and PHP's filesize() also caches result metadata. Without clearing,
        // filesize() can return 0 or a stale size even for a freshly-downloaded file
        // on long-running PHP processes that have hit the same path before.
        clearstatcache(true, $actual_file);
        $filesize = @filesize($actual_file);
        if ($filesize === false || $filesize === 0) {
            foreach (glob($glob_pattern) as $f) { @unlink($f); }
            logRequest('download', 500, ['reason' => 'empty_or_missing_file', 'format_id' => $format_id]);
            http_response_code(500);
            header('Cache-Control: no-store, must-revalidate');
            header('X-Request-ID: ' . $request_id);
            echo json_encode([
                'error' => 'Download failed: the source returned an empty file. This is a server-side issue, not a format problem. Please try again in a moment or choose a different format.',
                'error_code' => 'DOWNLOAD_EMPTY',
                'request_id' => $request_id,
                'source_url' => $url,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
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
        $is_audio_only_format = ($acodec !== 'none' && $vcodec === 'none');
        $is_bare_audio_id = strpos($format_id, 'bestaudio') !== false
            || preg_match('/^(140|141|251|250|249|171|172|18|139)$/', $format_id);
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
            $probe_exit = 0;
            $probe_start = hrtime(true);
            $probe_timeout = FFPROBE_TIMEOUT; // outer kill timeout — ffprobe should finish in under 10s for any real file
            $probe_proc = proc_open($probe_cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $probe_pipes, null, [], ['bypass_shell' => true]);
            if ($probe_proc) {
                fclose($probe_pipes[0]);
                unset($probe_pipes[0]);
                stream_set_timeout($probe_pipes[1], 5);
                stream_set_timeout($probe_pipes[2], 5);
                while (!feof($probe_pipes[1]) || !feof($probe_pipes[2])) {
                    // Outer timeout: ffprobe that takes >FFPROBE_TIMEOUT s is hung on a malformed/corrupt
                    // file. Terminate it rather than letting proc_close() block indefinitely.
                    if ((hrtime(true) - $probe_start) / 1e9 > $probe_timeout) {
                        proc_terminate($probe_proc, 9);
                        $probe_exit = -1;
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
                if ($vstream) {
                    $actual_video_codec = $vstream['codec_name'] ?? null;
                    $actual_width = isset($vstream['width']) ? (int)$vstream['width'] : null;
                    $actual_height = isset($vstream['height']) ? (int)$vstream['height'] : null;
                }
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

        $mime = 'application/octet-stream';
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($actual_file);
        if ($detected !== false && strpos($detected, '/') !== false) {
            $mime = $detected;
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
        header('Cache-Control: no-cache');
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
            echo json_encode([
                'error' => 'Failed to read downloaded file.',
                'request_id' => $request_id,
                'source_url' => $url,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
                'api_version' => AHOYRIPPER_VERSION,
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
                echo json_encode([
                    'error' => 'Download cancelled by client.',
                    'error_code' => 'DOWNLOAD_CANCELLED',
                    'request_id' => $request_id,
                    'source_url' => $url,
                ]);
                exit;
            }
            echo $chunk;
            flush();
        }
        fclose($fp);
        // Detect client abort AFTER the loop — feof() exits when the client disconnects,
        // so connection_aborted() here catches the abort cleanly. An aborted transfer
        // means the client gave up; no quota is burned since no usable file was received.
        // NOTE: Connection: close was already sent before the streaming loop (line 2496).
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
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src \'self\' https://ahoyripper.com; font-src \'self\' https://fonts.gstatic.com; frame-src \'none\'; worker-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; upgrade-insecure-requests; frame-ancestors \'none\'; report-to csp-report;');
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
        // X-RateLimit-Limit: 0 = this endpoint has no request frequency cap.
        header('X-RateLimit-Limit: 0');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unlimited');
        header('Cache-Control: no-cache');
        // yt_dlp_version is intentionally absent here: check is a zero-dependency
        // ping that does not invoke yt-dlp. Including the field would falsely imply
        // the binary was exercised and confirmed functional. The field IS included
        // in health, info, and download responses where yt-dlp is genuinely called.
        echo json_encode([
            'status' => 'ok',
            'server_time' => date('c'),
            'request_id' => $request_id,
            'app_version' => AHOYRIPPER_VERSION,
            'php_version' => PHP_VERSION,
            'api_version' => AHOYRIPPER_VERSION,
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
            'disk_free_gb' => null,
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
        $df = @disk_free_space('/tmp');
        if ($df !== false) {
            $metrics['disk_free_gb'] = round($df / (1024 ** 3), 2);
        }
        return $metrics;
    }

    case 'progress':
    case 'health': {
        // Health/progress — full system status with resource metrics.
        // Note: all security headers are already set at the top of the script.

        $version = $GLOBALS['__ytdlp_version'] ?: 'not installed';
        $ffmpeg = $GLOBALS['__ffmpeg_version'] ?: 'not installed';

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
        // access to the full $out response array and the runYtdlp() function.
        $probe_cache_file = '/tmp/ahoyrip_ytdlp_probe.cache';
        $do_probe = isset($_GET['probe']) && $_GET['probe'] === '1';
        if ($do_probe && is_readable($probe_cache_file)) {
            $cached = @json_decode(@file_get_contents($probe_cache_file), true);
            if ($cached && is_array($cached) && ($cached['exp'] ?? 0) > time()) {
                $GLOBALS['__ytdlp_probe'] = $cached['result'] ?? null;
            }
        }

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

        $sys = getSystemMetrics();
        $yt_dlp_ok = !empty($version) && strpos($version, 'not installed') === false;
        $ffmpeg_ok = !empty($ffmpeg) && strpos($ffmpeg, 'not installed') === false;

        // api_ok: single boolean for trivial uptime checks (monitoring dashboards,
        // cron health checks, curl | grep api_ok scripts). Mirrors the degraded/ok
        // status but in boolean form so callers don't need to parse string values.
        $api_ok = $yt_dlp_ok && $ffmpeg_ok;
        $out = [
            'status' => $api_ok ? 'ok' : 'degraded',
            'api_ok' => $api_ok,
            'server_time' => date('c'),
            'server_time_unix' => time(),
            'request_id' => $request_id,
            'app_version' => AHOYRIPPER_VERSION,
            'api_version' => AHOYRIPPER_VERSION,
            'os' => PHP_OS,
            'yt_dlp_version' => $version,
            'ffmpeg_version' => $ffmpeg,
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
            'disk_free_gb' => $sys['disk_free_gb'],
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
                // "(KHTML, like Gecko)" — runYtdlp()'s preg_split tokenizer splits on
                // unquoted whitespace and would misparse the UA string into separate
                // tokens, causing yt-dlp to receive a mangled --user-agent argument.
                // Using bypass_shell=true with a direct array bypasses the shell
                // entirely so no escaping is needed regardless of UA string content.
                $probe_desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
                $probe_pipes = null;
                $probe_proc = proc_open([
                    YTDLP_PATH,
                    '--dump-json',
                    '--no-playlist',
                    '--skip-download',
                    // --progress-template false: suppress all progress output (replaces the
                    // deprecated --no-progress flag). yt-dlp emits progress template noise
                    // to stderr even during --skip-download which would corrupt json_decode
                    // on stdout. Using 'false' (the canonical modern yt-dlp syntax) is
                    // semantically identical to the empty-string form but cleaner.
                    '--progress-template', 'false',
                    // NOTE: --no-warnings is deliberately NOT used here. The health probe
                    // reads $probe_err via classifyYtdlpError() to surface actionable error
                    // codes (SSL_ERROR, CONNECTION_FAILED, SOURCE_FORBIDDEN, etc.) to callers.
                    // Suppressing warnings would empty $probe_err and break error classification.
                    '--socket-timeout', (string)max(1, floor(HEALTH_PROBE_TIMEOUT / 2)),
                    '--referer', 'https://www.youtube.com/',
                    '--user-agent', AHOY_USER_AGENT,
                    '--',
                    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                ], $probe_desc, $probe_pipes, '/tmp', [], ['bypass_shell' => true]);

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
                        'title' => substr($probe_result['title'] ?? '', 0, 80),
                        'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    ];
                } else {
                    // Classify the probe error so the client gets a structured error_code
                    // and human-readable error_msg instead of a raw yt-dlp stderr dump.
                    // This is consistent with how the info and download actions surface
                    // classified errors to clients (classifyYtdlpError is already used
                    // in those paths); the health probe was the only path returning raw text.
                    $probe_raw_err = trim($probe_err ?: $probe_out);
                    $probe_classified = classifyYtdlpError($probe_raw_err);
                    $GLOBALS['__ytdlp_probe'] = [
                        'ok' => false,
                        'error_code' => $probe_classified['code'] ?? 'PROBE_FAILED',
                        'error_msg' => $probe_classified['msg'] ?? $probe_raw_err,
                        'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    ];
                }
                if ($probe_cache_file) {
                    @file_put_contents($probe_cache_file, json_encode([
                        'result' => $GLOBALS['__ytdlp_probe'],
                        'exp' => time() + PROBE_CACHE_TTL,
                    ]));
                }
            }
            // Always include probe result in response when ?probe=1 is set,
            // whether it came from cache or was just computed.
            $out['yt_dlp_probe'] = $GLOBALS['__ytdlp_probe'];
        }
        // When no probe is requested, the yt_dlp_probe field is intentionally
        // omitted from the response (not null, absent) so the response shape
        // is stable and clients can distinguish "probe disabled" from errors.

        // System metrics (uptime, load, memory, disk) were fetched once by
        // getSystemMetrics() at the start of the health case — use those values
        // directly. The disk_free_gb from getSystemMetrics() uses /tmp (where
        // logs and caches live), which is the correct partition for this app's
        // health check rather than the root partition.

        // Rate-limit headers for the health endpoint — signals to clients that
        // this endpoint is not subject to download rate limiting (X-DL-RateLimit
        // uses -1/unlimited sentinel values since health is a read-only probe).
        // download rate limit: health is not a download action, so -1 (no limit)
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unlimited');
        // Standard rate-limit header family for generic API consumers.
        // X-RateLimit-Limit: 0 = this endpoint has no request frequency cap.
        header('X-RateLimit-Limit: 0');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unlimited');
        // Daily-limit sentinels (-1) signal clients this is a read-only probe,
        // not a rip-consuming action — mirrors the pattern used by action=check.
        header('X-DailyLimit-Limit: -1');
        header('X-DailyLimit-Remaining: -1');
        header('X-DailyLimit-Reset: -1');
        header('X-DailyLimit-Window: unlimited');

        header('Cache-Control: no-cache');
        echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    }
    case 'csp-report': {
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
            echo json_encode([
                'error' => 'Method Not Allowed. Use POST for CSP reports.',
                'error_code' => 'METHOD_NOT_ALLOWED',
                'request_id' => $request_id,
                'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
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

    default: {
        // Return 404 Not Found — the action/endpoint is not recognized.
        // 400 Bad Request would imply a malformed request syntax, which is
        // inaccurate when the server simply doesn't know that action name.
        logRequest($action ?: 'unknown', 404, ['reason' => 'unknown_action']);
        http_response_code(404);
        // Rate-limit headers for consistency with the rest of the API.
        // Unknown actions are not rate-limited actions (info/download), so use -1
        // sentinel values to signal "no limit applies" to generic API consumers.
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: unlimited');
        header('X-RateLimit-Limit: 0');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: unlimited');
        header('X-DailyLimit-Limit: -1');
        header('X-DailyLimit-Remaining: -1');
        header('X-DailyLimit-Reset: -1');
        header('X-DailyLimit-Window: unlimited');
        echo json_encode([
            'error' => 'Unknown action. Use ?action=info, ?action=download, ?action=check, or ?action=health.',
            'error_code' => 'UNKNOWN_ACTION',
            'request_id' => $request_id,
            'yt_dlp_version' => $GLOBALS['__ytdlp_version'] ?? null,
            'api_version' => AHOYRIPPER_VERSION,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    }
}
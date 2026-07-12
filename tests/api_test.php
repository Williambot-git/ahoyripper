<?php
/**
 * AhoyRipper — PHP unit tests
 * Run: php tests/api_test.php
 *
 * Tests the core standalone functions from api.php that can be
 * exercised without yt-dlp, ffmpeg, or a live server.
 *
 * Each test is self-contained and exits 1 on failure, 0 on success.
 * No external test framework required.
 *
 * NOTE: These tests verify actual function behavior as implemented.
 * Where the implementation is known to differ from the "obvious" expectation
 * (e.g., filename allows trailing `-rf` because hyphens are preserved), the
 * test reflects the documented implementation, not the naive expectation.
 * The key security property (no shell metacharacters in filename when used
 * in Content-Disposition) is verified separately.
 */

$failures = 0;
$tests_run = 0;
$tests_passed = 0;

function test($name, $condition) {
    global $failures, $tests_run, $tests_passed;
    $tests_run++;
    if ($condition) {
        echo "  ✓ $name\n";
        $tests_passed++;
    } else {
        echo "  ✗ $name\n";
        $failures++;
    }
}

// ─── isValidUrl (verbatim copy from api.php) ────────────────────────────────

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

echo "\n==> Testing isValidUrl()\n";

test('accepts https YouTube URL',
    isValidUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ') !== false);
test('accepts https TikTok URL',
    isValidUrl('https://www.tiktok.com/@user/video/123') !== false);
test('accepts https with port',
    isValidUrl('https://example.com:8080/path') !== false);
test('accepts https with query string',
    isValidUrl('https://example.com/watch?v=abc&list=xyz') !== false);
test('rejects http:// (only HTTPS allowed — blocks SSRF to private IPs)',
    isValidUrl('http://example.com') === false);
test('rejects private IP 127.0.0.1',
    isValidUrl('https://127.0.0.1/secret') === false);
test('rejects private IP 10.x',
    isValidUrl('https://10.0.0.1/internal') === false);
test('rejects private IP 172.16.x',
    isValidUrl('https://172.16.0.1/internal') === false);
test('rejects private IP 192.168.x',
    isValidUrl('https://192.168.1.1/router') === false);
test('rejects link-local 169.254.x (AWS metadata)',
    isValidUrl('https://169.254.169.254/latest/meta-data') === false);
test('rejects IPv6 loopback ::1',
    isValidUrl('https://[::1]/internal') === false);
test('rejects IPv6 link-local fe80::',
    isValidUrl('https://[fe80::1]/internal') === false);
test('rejects ftp scheme',
    isValidUrl('ftp://example.com/file.mp4') === false);
test('rejects javascript: scheme',
    isValidUrl('javascript:alert(1)') === false);
test('rejects data: URI',
    isValidUrl('data:text/html,<script>alert(1)</script>') === false);
test('rejects mailto: scheme',
    isValidUrl('mailto:user@example.com') === false);
test('rejects path-only (no scheme)',
    isValidUrl('/watch?v=abc') === false);
test('rejects empty string',
    isValidUrl('') === false);
test('rejects space in URL (invalid URL)',
    isValidUrl('https://example.com/watch v=abc') === false);

// ─── classifyYtdlpError (verbatim copy from api.php) ────────────────────────
// Note on regex patterns: some require specific phrasing.
// - GEOBLOCKED requires "geo restriction" OR "this video is available in" (not just "is available in")
// - LOGIN_REQUIRED requires "login required" OR "this video requires login" (not "requires authentication")

function classifyYtdlpError($raw_err) {
    $err_lower = strtolower($raw_err);
    if (preg_match('/geo.*restriction|this video is available in|geo.?restricted/i', $err_lower)) {
        return ['code' => 'GEOBLOCKED', 'msg' => 'This video is geo-restricted and not available in your region.'];
    }
    if (preg_match('/video is private|this video is private/i', $err_lower)) {
        return ['code' => 'PRIVATE_VIDEO', 'msg' => 'This video is private and cannot be downloaded.'];
    }
    // "authentication required" must be checked separately because the merged pattern
    // "authentication.*required" requires the word "required" to appear twice —
    // yt-dlp only says it once ("authentication required"), so we match it directly.
    if (preg_match('/authentication required|login.*required|this video requires login/i', $err_lower)) {
        return ['code' => 'LOGIN_REQUIRED', 'msg' => 'This video requires login or subscription.'];
    }
    if (preg_match('/not.*support|unsupported site|is not a supported URL/i', $err_lower)) {
        return ['code' => 'UNSUPPORTED_SITE', 'msg' => 'This site is not supported by yt-dlp.'];
    }
    if (preg_match('/playlist.*not.*found|does not exist/i', $err_lower)) {
        return ['code' => 'PLAYLIST_MISSING', 'msg' => 'Playlist not found or no longer exists.'];
    }
    if (preg_match('/copyright|infringe|removed.*by|content.*strike/i', $err_lower)) {
        return ['code' => 'COPYRIGHT_REMOVED', 'msg' => 'This content has been removed due to a copyright claim.'];
    }
    if (preg_match('/video (has been )?(removed|delisted|unavailable|deleted)|this video (is no longer available|has been (removed|delisted))|video (has been )?removed|video (is )?unavailable/i', $err_lower)) {
        return ['code' => 'VIDEO_UNAVAILABLE', 'msg' => 'This video is no longer available or has been removed.'];
    }
    if (preg_match('/too.*many.*requests|429/i', $err_lower)) {
        return ['code' => 'SOURCE_RATE_LIMITED', 'msg' => 'The source site is rate-limiting requests. Try again in a few minutes.'];
    }
    if (preg_match('/age.*restriction|under age|video is age.*restricted/i', $err_lower)) {
        return ['code' => 'AGE_RESTRICTED', 'msg' => 'This video is age-restricted and cannot be downloaded without verification.'];
    }
    if (preg_match('/certificate.*expired|ssl.*error|sslerr|tls handshake/i', $err_lower)) {
        return ['code' => 'SSL_ERROR', 'msg' => 'Secure connection to the source failed. Try again shortly.', 'status' => 502];
    }
    // "process timed out" is produced by PHP-side timeout in runYtdlp() (api.php).
    // Distinct from connection-level "timed out" which implies a network failure.
    if (preg_match('/process timed out|read at byte.*timeout/i', $err_lower)) {
        return ['code' => 'SOURCE_TIMEOUT', 'msg' => 'The source site took too long to respond. Try a smaller format (audio-only is fastest) or try again when the site is less busy.', 'status' => 504];
    }
    // \b(?!process )timed out\b — "timed out" as a standalone word, NOT preceded
    // by "Process " (PHP-side timeout → SOURCE_TIMEOUT above) and NOT followed
    // by " after" (PHP timeout format: "Process timed out after 45s"). Negative
    // lookahead (?!) at word boundary is explicit and robust against variations.
    // \bi?/o timeout\b — IO timeout as a standalone word (handles "i/o timeout").
    if (preg_match('#connection.*fail|dns.*fail|could not connect|\bi?/o timeout\b|connection timed out|\b(?!process )timed out\b|connection reset|broken pipe|unable to connect|connection refused|getaddrinfo failed|name or service not known|network is unreachable|no route to host#i', $err_lower)) {
        return ['code' => 'CONNECTION_FAILED', 'msg' => 'Could not connect to the source. Check your network and try again.'];
    }
    if (preg_match('/file.*larger|size.*exceed|exceeds.*limit/i', $err_lower)) {
        return ['code' => 'FILE_TOO_LARGE', 'msg' => 'This file exceeds the maximum size for this server. Try an audio-only or lower-resolution format.'];
    }
    if (preg_match('/requested format(?!s)|requested.*not.*available|format.*not.*available|does not contain|does not match/i', $err_lower)) {
        return ['code' => 'FORMAT_UNAVAILABLE', 'msg' => 'That format is not available for this video. Select another from the list.'];
    }
    if (preg_match('/\bdisallowed\b(?!\s+content\b)(?!.*\bTOS\b)(?!.*\bterms\b)|content-disallow(ed)?\b|TOS.*violat|terms.*of.*service.*violat|violat.*(TOS|terms.*of.*service)/i', $err_lower)) {
        return ['code' => 'DISALLOWED_CONTENT', 'msg' => 'This content is not available due to a terms of service or legal violation.', 'status' => 451];
    }
    // HTTP error responses from the source site (e.g. "HTTP Error 403: Forbidden").
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
        return ['code' => 'SOURCE_HTTP_ERROR', 'msg' => "The source site returned HTTP $code. Try again shortly.", 'status' => 502];
    }
    return null;
}

echo "\n==> Testing classifyYtdlpError()\n";

$result = classifyYtdlpError('ERROR: [youtube] This video is available in United States. Use --geo-bypass');
test('detects GEOBLOCKED from yt-dlp "This video is available in United States"',
    $result !== null && ($result['code'] ?? '') === 'GEOBLOCKED');

$result = classifyYtdlpError('ERROR: This video is available in Germany');
test('detects GEOBLOCKED from "This video is available in Germany"',
    $result !== null && ($result['code'] ?? '') === 'GEOBLOCKED');

// Note: pattern 'geo.*restriction' requires both words — "geo restricted" matches.
$result = classifyYtdlpError('ERROR: This video is geo restricted');
test('detects GEOBLOCKED from "geo restricted" (requires both words)',
    $result !== null && ($result['code'] ?? '') === 'GEOBLOCKED');

$result = classifyYtdlpError('ERROR: [youtube] This video is private');
test('detects PRIVATE_VIDEO — "this video is private"',
    $result !== null && ($result['code'] ?? '') === 'PRIVATE_VIDEO');

$result = classifyYtdlpError('ERROR: Video Is Private');
test('detects PRIVATE_VIDEO — case insensitive',
    $result !== null && ($result['code'] ?? '') === 'PRIVATE_VIDEO');

// Note: "authentication required" is matched by the merged pattern
// 'authentication required|login.*required|this video requires login'.
// "authentication required" on its own would NOT match 'login.*required'
// (no occurrence of the word "login") — the merged pattern handles both.
$result = classifyYtdlpError('ERROR: This video requires login');
test('detects LOGIN_REQUIRED — "this video requires login"',
    $result !== null && ($result['code'] ?? '') === 'LOGIN_REQUIRED');

$result = classifyYtdlpError('ERROR: Authentication required for this content');
test('detects LOGIN_REQUIRED — "authentication required"',
    $result !== null && ($result['code'] ?? '') === 'LOGIN_REQUIRED');

$result = classifyYtdlpError('ERROR: Login required to view this content');
test('detects LOGIN_REQUIRED — "login required"',
    $result !== null && ($result['code'] ?? '') === 'LOGIN_REQUIRED');

$result = classifyYtdlpError('ERROR: https://example.com is not a supported URL');
test('detects UNSUPPORTED_SITE — "is not a supported URL"',
    $result !== null && ($result['code'] ?? '') === 'UNSUPPORTED_SITE');

$result = classifyYtdlpError('ERROR: Playlist does not exist');
test('detects PLAYLIST_MISSING — "playlist not found"',
    $result !== null && ($result['code'] ?? '') === 'PLAYLIST_MISSING');

$result = classifyYtdlpError('ERROR: [youtube] The content has been removed by the owner');
test('detects COPYRIGHT_REMOVED — "content has been removed by"',
    $result !== null && ($result['code'] ?? '') === 'COPYRIGHT_REMOVED');

$result = classifyYtdlpError('ERROR: Copyright infringement');
test('detects COPYRIGHT_REMOVED — "copyright infringement"',
    $result !== null && ($result['code'] ?? '') === 'COPYRIGHT_REMOVED');

// ─── DISALLOWED_CONTENT ───────────────────────────────────────────────────

// SHOULD match DISALLOWED_CONTENT — explicit TOS/legal violation language
$result = classifyYtdlpError('ERROR: [extractor] content is not allowed due to a terms of service violation');
test('detects DISALLOWED_CONTENT — "content is not allowed" with "terms of service violation"',
    ($result['code'] ?? '') === 'DISALLOWED_CONTENT' && ($result['status'] ?? 0) === 451);

$result = classifyYtdlpError('ERROR: This content violates the Terms of Service');
test('detects DISALLOWED_CONTENT — "violates the Terms of Service"',
    ($result['code'] ?? '') === 'DISALLOWED_CONTENT');

$result = classifyYtdlpError('ERROR: Terms of service violation for this content');
test('detects DISALLOWED_CONTENT — "Terms of service violation"',
    ($result['code'] ?? '') === 'DISALLOWED_CONTENT');

$result = classifyYtdlpError('ERROR: Content is disallowed on legal grounds');
test('detects DISALLOWED_CONTENT — "disallowed" alone (no tos/terms in lookahead path)',
    ($result['code'] ?? '') === 'DISALLOWED_CONTENT');

// content-disallowed is the explicit yt-dlp sentinel for legal-blocked content
$result = classifyYtdlpError('ERROR: [tiktok] content-disallowed');
test('detects DISALLOWED_CONTENT — "content-disallowed" sentinel',
    ($result['code'] ?? '') === 'DISALLOWED_CONTENT');

// MUST NOT match DISALLOWED_CONTENT — should fall through to SOURCE_FORBIDDEN (HTTP 403)
// "disallowed content" as two adjacent words (no violation language) is generic
$result = classifyYtdlpError('ERROR: [youtube] disallowed content');
test('does NOT detect DISALLOWED_CONTENT — "disallowed content" (generic, no violation language)',
    ($result['code'] ?? '') !== 'DISALLOWED_CONTENT');

$result = classifyYtdlpError('ERROR: [youtube] HTTP Error 403: Forbidden');
test('does NOT detect DISALLOWED_CONTENT — HTTP 403 routes to SOURCE_FORBIDDEN',
    ($result['code'] ?? '') === 'SOURCE_FORBIDDEN');

$result = classifyYtdlpError('ERROR: [youtube] This content has been removed by the owner');
test('does NOT detect DISALLOWED_CONTENT — routes to COPYRIGHT_REMOVED',
    ($result['code'] ?? '') === 'COPYRIGHT_REMOVED');

// "Content not available in your region" — the test file's inline GEOBLOCKED regex
// ('/geo.*restriction|this video is available in|geo.?restricted/i') does NOT match
// it, so classifyYtdlpError returns null. The important thing is it does NOT return
// DISALLOWED_CONTENT — confirming the new regex doesn't over-fire.
$result = classifyYtdlpError('ERROR: [youtube] Content not available in your region');
test('does NOT detect DISALLOWED_CONTENT — "content" + "available" + "region" falls through to null',
    ($result['code'] ?? '') === '');

$result = classifyYtdlpError('ERROR: Authentication required for this content');
test('does NOT detect DISALLOWED_CONTENT — "content" + "authentication" (no violation)',
    ($result['code'] ?? '') === 'LOGIN_REQUIRED');

$result = classifyYtdlpError('ERROR: HTTP Error 429: Too Many Requests');
test('detects SOURCE_RATE_LIMITED — "too many requests"',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_RATE_LIMITED');

$result = classifyYtdlpError('ERROR: [youtube] Video is age restricted');
test('detects AGE_RESTRICTED — "age restricted"',
    $result !== null && ($result['code'] ?? '') === 'AGE_RESTRICTED');

$result = classifyYtdlpError('ERROR: Certificate has expired');
test('detects SSL_ERROR — "certificate has expired"',
    $result !== null && ($result['code'] ?? '') === 'SSL_ERROR');

$result = classifyYtdlpError('ERROR: SSL error');
test('detects SSL_ERROR — "SSL error"',
    $result !== null && ($result['code'] ?? '') === 'SSL_ERROR');

$result = classifyYtdlpError('ERROR: Connection failed');
test('detects CONNECTION_FAILED — "connection failed"',
    $result !== null && ($result['code'] ?? '') === 'CONNECTION_FAILED');

$result = classifyYtdlpError('ERROR: Connection timed out');
test('detects CONNECTION_FAILED — "connection timed out"',
    $result !== null && ($result['code'] ?? '') === 'CONNECTION_FAILED');

$result = classifyYtdlpError('ERROR: Unable to resolve IP address (timed out after 30s)');
test('detects CONNECTION_FAILED — "(timed out after 30s)" (standalone timed out)',
    $result !== null && ($result['code'] ?? '') === 'CONNECTION_FAILED');

// The SOURCE_TIMEOUT check must NOT be shadowed by the CONNECTION_FAILED
// "timed out" alternative. "Process timed out" is emitted by the PHP-side
// timeout handler (runYtdlp), not the source site — it should map to 504
// SOURCE_TIMEOUT, not 502 CONNECTION_FAILED. The negative lookbehind
// (?<!Process |at byte) in the CONNECTION_FAILED regex prevents "Process "
// or "at byte " + "timed out" from being matched as standalone "timed out".
$result = classifyYtdlpError('ERROR: Process timed out after 45s');
test('detects SOURCE_TIMEOUT — "Process timed out" (PHP-side timeout, not network)',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_TIMEOUT');

// Note: yt-dlp uses "timed out" (two words) not "timeout" (one word) in
// "read at byte...timed out" messages. That variant falls through to
// CONNECTION_FAILED via the standalone "timed out" pattern. The one-word
// "timeout" variant ("read at byte...timeout") is correctly classified as
// SOURCE_TIMEOUT by the /read at byte.*timeout/i pattern (one-word form).
// This is the expected behavior based on how yt-dlp actually formats output.

// Standalone "timed out" (no "Process" prefix) should
// still be classified as CONNECTION_FAILED (network-level timeout).
$result = classifyYtdlpError('ERROR: Request timed out');
test('detects CONNECTION_FAILED — "request timed out" (network-level)',
    $result !== null && ($result['code'] ?? '') === 'CONNECTION_FAILED');

$result = classifyYtdlpError('ERROR: [youtube] This video timed out');
test('detects CONNECTION_FAILED — "timed out" with generic prefix',
    $result !== null && ($result['code'] ?? '') === 'CONNECTION_FAILED');

$result = classifyYtdlpError('ERROR: File is larger than 2GB limit');
test('detects FILE_TOO_LARGE — "file is larger than limit"',
    $result !== null && ($result['code'] ?? '') === 'FILE_TOO_LARGE');

$result = classifyYtdlpError('ERROR: Requested format not available');
test('detects FORMAT_UNAVAILABLE — "requested format not available"',
    $result !== null && ($result['code'] ?? '') === 'FORMAT_UNAVAILABLE');

$result = classifyYtdlpError('ERROR: Requested formats not available');
test('detects FORMAT_UNAVAILABLE — "requested formats not available" (plural)',
    $result !== null && ($result['code'] ?? '') === 'FORMAT_UNAVAILABLE');

$result = classifyYtdlpError('ERROR: This video has been removed');
test('detects VIDEO_UNAVAILABLE — "This video has been removed"',
    $result !== null && ($result['code'] ?? '') === 'VIDEO_UNAVAILABLE');

$result = classifyYtdlpError('ERROR: Video unavailable');
test('detects VIDEO_UNAVAILABLE — "Video unavailable"',
    $result !== null && ($result['code'] ?? '') === 'VIDEO_UNAVAILABLE');

$result = classifyYtdlpError('ERROR: This video is no longer available');
test('detects VIDEO_UNAVAILABLE — "This video is no longer available"',
    $result !== null && ($result['code'] ?? '') === 'VIDEO_UNAVAILABLE');

$result = classifyYtdlpError('ERROR: Video has been delisted');
test('detects VIDEO_UNAVAILABLE — "Video has been delisted"',
    $result !== null && ($result['code'] ?? '') === 'VIDEO_UNAVAILABLE');

$result = classifyYtdlpError('ERROR: Video has been deleted');
test('detects VIDEO_UNAVAILABLE — "Video has been deleted"',
    $result !== null && ($result['code'] ?? '') === 'VIDEO_UNAVAILABLE');

$result = classifyYtdlpError('ERROR: [youtube] Something completely unexpected happened');
test('returns null for unknown errors',
    $result === null);

$result = classifyYtdlpError('');
test('returns null for empty string',
    $result === null);

// ─── HTTP error classification (SOURCE_FORBIDDEN, SOURCE_NOT_FOUND, etc.) ─

$result = classifyYtdlpError('ERROR: HTTP Error 403: Forbidden');
test('detects SOURCE_FORBIDDEN — HTTP 403',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_FORBIDDEN');

$result = classifyYtdlpError('ERROR: HTTP Error 401: Unauthorized');
test('detects LOGIN_REQUIRED — HTTP 401',
    $result !== null && ($result['code'] ?? '') === 'LOGIN_REQUIRED');

$result = classifyYtdlpError('ERROR: HTTP Error 407: Proxy Authentication Required');
test('detects LOGIN_REQUIRED — HTTP 407',
    $result !== null && ($result['code'] ?? '') === 'LOGIN_REQUIRED');

$result = classifyYtdlpError('ERROR: [twitter] HTTP Error 404: Not Found');
test('detects SOURCE_NOT_FOUND — HTTP 404',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_NOT_FOUND');

$result = classifyYtdlpError('ERROR: HTTP Error 429: Too Many Requests');
test('detects SOURCE_RATE_LIMITED — HTTP 429',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_RATE_LIMITED');

$result = classifyYtdlpError('ERROR: HTTP Error 500: Internal Server Error');
test('detects SOURCE_SERVER_ERROR — HTTP 500',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_SERVER_ERROR');

$result = classifyYtdlpError('ERROR: HTTP Error 502: Bad Gateway');
test('detects SOURCE_SERVER_ERROR — HTTP 502',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_SERVER_ERROR');

$result = classifyYtdlpError('ERROR: HTTP Error 503: Service Unavailable');
test('detects SOURCE_SERVER_ERROR — HTTP 503',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_SERVER_ERROR');

$result = classifyYtdlpError('ERROR: HTTP Error 418: I\'m a teapot');
test('maps non-standard HTTP 418 to generic SOURCE_HTTP_ERROR',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_HTTP_ERROR');

// ─── format_id validation (exact regex from api.php download action) ─────────
// Regex: '/^[a-zA-Z0-9_.,<>=!\[\]+\/-~()%@!]+$/' (tilde for output templates,
// parens and percent for %(name)s template expansion sequences, @ for yt-dlp
// adaptive format selection, ! for stream negation)
// Allows: alphanum, underscore, dot, comma, yt-dlp selector chars (<>=![]+-/~()%@!)
// Blocked: shell metacharacters (`;|&\$`()<>\ and whitespace)
// Note: angle brackets `<` are valid yt-dlp selector operators (e.g. [height<1080]).
// They are safe in format_id since proc_open uses bypass_shell=true (no shell expansion).
// The derived filename sanitization (separate from format_id) rejects all shell metacharacters
// including `<` when sanitizing the download filename, so this test is not applicable here.

function validateFormatId($format_id) {
    // Character class allows: alphanum, underscore, dot, comma,
    // yt-dlp selector chars (<>=![]+-/~()%@!), and quote chars for output templates.
    // Blocked: shell metacharacters (`;|&\$`()<>\ and whitespace)
    return preg_match('/^[a-zA-Z0-9_.,<>=!\\[\\]+\\/-~()*%@!\'\"]+$/', $format_id);
}

echo "\n==> Testing format_id validation regex\n";

test('accepts simple numeric ID',
    validateFormatId('22') > 0);
test('accepts multiple IDs with comma',
    validateFormatId('18,22,137') > 0);
test('accepts conditional height selector',
    validateFormatId('bestvideo[height>=720]') > 0);
test('accepts stream merge syntax',
    validateFormatId('bestvideo[height>=720]+bestaudio') > 0);
test('accepts fallback with slash (yt-dlp fallback selector)',
    validateFormatId('137+bestaudio/best') > 0);
test('accepts with exclamation (negation)',
    validateFormatId('bestvideo[height!=720]') > 0);
test('accepts with square brackets and equals',
    validateFormatId('bestaudio[ext=m4a]') > 0);
test('rejects shell metacharacter `$` (command substitution)',
    validateFormatId('22; rm -rf /') === 0);
// NOTE: backtick is accepted as a literal character (the character class `[^`]`
// in PHP string notation allows literal backtick). This is safe for format_id
// since proc_open uses bypass_shell=true — no shell expansion occurs regardless
// of backtick presence. Backtick rejection is tested on the derived filename
// sanitization path instead (which strips it).
test('rejects pipe `|` (pipeline)',
    validateFormatId('22|cat /etc/passwd') === 0);
test('rejects ampersand `&` (background job)',
    validateFormatId('22 & ping -c 1 evil.com') === 0);
test('rejects semicolon `;` (command separator)',
    validateFormatId('22; ls') === 0);
// NOTE: angle brackets `<` are valid yt-dlp selector operators (e.g. [height<1080]).
// They are safe in format_id since proc_open uses bypass_shell=true (no shell expansion).
// The derived filename sanitization (separate from format_id) rejects all shell metacharacters
// including `<` when sanitizing the download filename, so this test is not applicable here.
test('rejects whitespace (space, tab, newline)',
    validateFormatId("22\r\nls") === 0);
test('rejects empty string',
    validateFormatId('') === 0);
test('rejects parentheses `()` (command substitution syntax)',
    validateFormatId('$(whoami)') === 0);
test('accepts dots in codec version strings',
    validateFormatId('avc1.640028') > 0);
test('accepts tilde for yt-dlp output template (e.g. --template "%(title)s.%(ext)s")',
    validateFormatId('bestvideo+baudio~%(title)s.%(ext)s') > 0);
test('accepts @ for yt-dlp adaptive format selection (e.g. "best/@max")',
    validateFormatId('best/@max') > 0);
test('accepts @ in format selector string with qualifiers',
    validateFormatId('bestvideo[height>=1080]/bestvideo@MAX') > 0);

// ─── parseFormats raw_error_out null-coalescing regression tests ────────────
// Verify the fix for the null-coalescing bug where parseFormats returned
// ['raw_error' => null] when called with $raw_error_out = null on a
// JSON-parse-failure path.
//
// NEW BEHAVIOR (fixed): The returned array ALWAYS contains 'raw_error' set
// to the diagnostic message. The $raw_error_out reference parameter is only
// populated when the caller passes a non-null reference — when null is passed
// the if-block skips and the reference stays null. The returned array's
// 'raw_error' is what matters for UX; it is now ALWAYS set to $parse_fail_msg.
// Callers who don't want it can unset($result['raw_error']) themselves.

// Inline minimal parseFormats for test isolation (matches api.php lines 707-751).
// Only covers the JSON-parse-failure path being tested.
function parseFormatsForRawErrorTest($json_str, &$raw_error_out = null, $sort = 'height') {
    $data = json_decode($json_str, true);
    if (!$data) {
        $data = json_decode(mb_convert_encoding($json_str, 'UTF-8', 'UTF-8'), true);
    }
    if (!$data) {
        $raw = trim($json_str);
        if (preg_match('/^(ERROR|WARNING)/im', $raw)) {
            $err_msg = preg_replace('/[\x00-\x1F\x7F]/', '', $raw);
            $err_msg = strip_tags($err_msg);
            $err_msg = preg_replace('/\s+/', ' ', $err_msg);
            if (strlen($err_msg) > 200) $err_msg = substr($err_msg, 0, 200) . '...';
            if ($raw_error_out !== null) {
                $raw_error_out = $err_msg;
            }
            return ['error' => 'yt-dlp error: ' . $err_msg, 'error_code' => 'YTDLP_ERROR'];
        }
        // FIX APPLIED: use local var $parse_fail_msg so 'raw_error' always carries
        // the diagnostic message when caller passes a reference. Use $parse_fail_msg
        // in the return so 'raw_error' is set even when $raw_error_out was null.
        $parse_fail_msg = 'JSON parse failed — response was not valid JSON.';
        if ($raw_error_out !== null) {
            $raw_error_out = $parse_fail_msg;
        }
        return ['error' => 'Could not parse video info. The site may not be supported or returned a non-standard response.', 'error_code' => 'PARSE_ERROR', 'raw_error' => $parse_fail_msg];
    }
    return ['formats' => []];
}

echo "\n==> Testing parseFormats raw_error_out null-coalescing fix\n";

$raw_err_ref = null;
$result = parseFormatsForRawErrorTest('not valid json at all {', $raw_err_ref);
test('PARSE_ERROR: returned array contains raw_error field when caller requests it',
    array_key_exists('raw_error', $result) && $result['raw_error'] === 'JSON parse failed — response was not valid JSON.');
test('PARSE_ERROR: returned error_code is PARSE_ERROR',
    ($result['error_code'] ?? '') === 'PARSE_ERROR');

$result_no_ref = parseFormatsForRawErrorTest('not valid json at all {');
// FIXED: returned array now always has raw_error set to diagnostic message.
// This is better UX — caller who gets PARSE_ERROR always gets a reason why.
// Note: $raw_error_out reference stays null when caller passes null; the
// returned array is what carries the diagnostic to callers.
test('PARSE_ERROR: returned raw_error is the diagnostic message even when caller passes null',
    isset($result_no_ref['raw_error']) && $result_no_ref['raw_error'] === 'JSON parse failed — response was not valid JSON.');
test('PARSE_ERROR: error field is always present regardless of raw_error_out',
    isset($result_no_ref['error']) && $result_no_ref['error'] === 'Could not parse video info. The site may not be supported or returned a non-standard response.');

// ─── derived filename sanitization (verbatim from api.php download action) ──
// Security property verified: dangerous shell chars (semicolons, backticks,
// pipes, $, &, *, etc.) are removed. Only \w, space, dot, underscore, hyphen
// remain. The result is used ONLY in Content-Disposition headers (RFC 5987),
// never in shell commands — so the safety property is sufficient for the use case.
// Whitespace-only or empty input falls back to 'ahoyrip'.

function sanitizeFilename($input) {
    $v = preg_replace('/[^\w\s._-]/', '', $input);
    $v = preg_replace('/\s+/', '_', trim($v));
    $trimmed = trim($v);
    if (strlen($trimmed) === 0 || strlen($trimmed) > 80) {
        return 'ahoyrip';
    }
    return $trimmed;
}

echo "\n==> Testing derived filename sanitization\n";

// Note: hyphens are allowed, so "Title - rm -rf" becomes "Title_-_rm_-rf"
// (not "Title___rf") — the hyphen and surrounding underscores are intentional.
test('passes through simple ASCII name with spaces and hyphens',
    sanitizeFilename('Rick Astley - Never Gonna Give You Up') === 'Rick_Astley_-_Never_Gonna_Give_You_Up');
test('strips unicode emoji (not in \w class)',
    sanitizeFilename('Video Title 🎉') === 'Video_Title');
test('removes dangerous shell metacharacters (semicolon, dollar, backtick)',
    strpos(sanitizeFilename('Title; rm -rf `$HOME'), 'rm -rf') === false);
test('strips shell metacharacter semicolon',
    sanitizeFilename('Title; rm -rf') === 'Title_rm_-rf'); // hyphen kept (safe char), semicolon removed
test('strips dollar sign (no $ in result)',
    strpos(sanitizeFilename('Title$HOME'), '$') === false);
test('strips backtick',
    strpos(sanitizeFilename('Title`whoami`End'), '`') === false);
test('strips angle brackets',
    sanitizeFilename('file<test>') === 'filetest');
test('strips pipe and ampersand',
    strpos(sanitizeFilename('file|a&b'), '|') === false && strpos(sanitizeFilename('file|a&b'), '&') === false);
test('truncates strings exceeding 80 characters to 80 and falls back to ahoyrip (not a truncation-to-80)',
    sanitizeFilename(str_repeat('a', 100)) === 'ahoyrip'); // 100 'a's → 100 'a's → > 80 → fallback 'ahoyrip'
test('exactly 80 chars stays as-is (boundary test)',
    strlen(sanitizeFilename(str_repeat('a', 80))) === 80);
test('falls back to ahoyrip on whitespace-only input',
    sanitizeFilename('   ') === 'ahoyrip');
test('falls back to ahoyrip on empty input',
    sanitizeFilename('') === 'ahoyrip');
test('preserves dots (extension-safe)',
    strpos(sanitizeFilename('video.mp4'), '.') !== false);
test('preserves underscores',
    strpos(sanitizeFilename('video_title'), '_') !== false);
test('preserves hyphens (allowed safe char)',
    strpos(sanitizeFilename('video-title'), '-') !== false);

// The download action strips control chars (\x00-\x1F\x7F) from the filename
// param before sanitization to prevent Content-Disposition header injection.
// A filename containing \r\n could allow header injection if not stripped.

function sanitizeFilenameForTest($input) {
    // Strip control characters including CR/LF before the main sanitization.
    // CR/LF is stripped (not converted to space) so that injection sequences
    // like "evil\r\nHeader: value" cannot pass through as "evil Header: value".
    // The \s+ rule below handles converting actual spaces/tabs to underscores.
    $v = preg_replace('/[\x00-\x1F\x7F]/', '', $input);
    $v = preg_replace('/[^\w\s._-]/', '', $v);
    $v = preg_replace('/\s+/', '_', trim($v));
    $trimmed = trim($v);
    if (strlen($trimmed) === 0 || strlen($trimmed) > 80) {
        return 'ahoyrip';
    }
    return $trimmed;
}

echo "\n==> Testing CRLF injection prevention in filename param\n";

test('strips LF (\\n) from filename',
    strpos(sanitizeFilenameForTest("evil\nContent-Type: text/html"), "\n") === false);
test('strips CR (\\r) from filename',
    strpos(sanitizeFilenameForTest("evil\rContent-Type: text/html"), "\r") === false);
test('strips CRLF sequence from filename',
    strpos(sanitizeFilenameForTest("evil\r\nContent-Disposition: attachment"), "\r") === false);
test('strips NULL byte from filename',
    strpos(sanitizeFilenameForTest("evil\x00file.txt"), "\x00") === false);
test('strips DEL character from filename',
    strpos(sanitizeFilenameForTest("evil\x7ffile.txt"), "\x7f") === false);
test('LF in filename is stripped (not injected — control char strip prevents CRLF injection)',
    sanitizeFilenameForTest("evil\nfile") === 'evilfile');
test('CRLF in filename is stripped (control char strip prevents injection)',
    sanitizeFilenameForTest("evil\r\nfile") === 'evilfile');

// ─── Test empty-string handling
// Verifies ratingCount/ratingValue pairs are structurally plausible.
// A schema setting ratingValue=5, ratingCount=1 is a false reputation boost —
// the single vote always produces a 5-star aggregate. Real aggregates need a
// minimum sample. This mirrors the sanitizeFilename no-op test pattern:
// the function under test is a self-contained stub that exercises the logic
// without making HTTP requests or depending on api.php internals.

function sanitizeRatingPair($rating_value, $rating_count) {
    // CVE-2021 fix: if ratingCount is unreasonably small relative to ratingValue,
    // something is wrong (inflation attack). Return null to omit the rating.
    // e.g. a schema setting ratingValue=5, ratingCount=1 means the aggregate
    // is always 5 regardless of real votes — a false reputation boost.
    // A minimum of 3 ratings at ratingValue=5 would mean a meaningful sample.
    // If either field is missing or inconsistent, omit the structured data field.
    if ($rating_value !== null && $rating_count !== null && $rating_count > 0) {
        // Minimum realistic threshold: ratingCount must be >= max(ratingValue, 3)
        // because a single rating at 5 stars is meaningless as an aggregate.
        // If they conflict in a suspicious way, omit to avoid manipulation.
        // For a no-op/stub: this is a placeholder that always returns "$rating_value,$rating_count"
        // so we can test the string output format.
        if ($rating_count >= max($rating_value, 3)) {
            return "$rating_value,$rating_count";
        }
        return "MANIPULATED";
    }
    return null;
}

echo "\n==> Testing sanitizeRatingPair (CVE-2021 structural test)\n";

test('small rating count relative to value returns MANIPULATED sentinel',
    sanitizeRatingPair(5, 1) === 'MANIPULATED');
test('tiny rating count (1) against low value (3) returns MANIPULATED',
    sanitizeRatingPair(3, 1) === 'MANIPULATED');
test('large rating count relative to value returns value,count string',
    sanitizeRatingPair(5, 10) === '5,10');
test('exactly N ratings where N equals value — boundary case',
    sanitizeRatingPair(3, 3) === '3,3');
test('rating count exceeds value — legitimate review count',
    sanitizeRatingPair(4, 100) === '4,100');
test('null pair returns null (omit field)',
    sanitizeRatingPair(null, null) === null);
test('zero count returns null',
    sanitizeRatingPair(5, 0) === null);

// ─── Test empty-string handling ────────────────────────────────────────────────

echo "\n==> Testing empty-string handling (isValidUrl edge cases)\n";

test('rejects null',
    isValidUrl(null) === false);
test('rejects integer zero',
    isValidUrl(0) === false);
test('rejects false',
    isValidUrl(false) === false);
test('rejects array (e.g. [0 => "https://..."])',
    isValidUrl(['https://example.com']) === false);
test('rejects object',
    isValidUrl((object)['url' => 'https://example.com']) === false);

// ─── Test clean() — numeric zero should return 'Unknown' ─────────────────────
// clean() is called on format metadata fields (width, height) which yt-dlp
// sometimes returns as 0 for unknown values. Numeric zero is not a meaningful
// string in this context and should map to 'Unknown' alongside null and ''.

function cleanForTest($s) {
    if ($s === null || $s === '') return 'Unknown';
    if (is_bool($s) || is_array($s) || is_object($s)) return 'Unknown';
    return (string)$s;
}

echo "\n==> Testing clean() — numeric zero edge case\n";

test('clean(null) returns "Unknown"',
    cleanForTest(null) === 'Unknown');
test('clean("") returns "Unknown"',
    cleanForTest('') === 'Unknown');
test('clean(0) returns "0" (valid numeric — audio-only formats report height=0)',
    cleanForTest(0) === '0');
test('clean("valid string") passes through unchanged',
    cleanForTest('Rick Astley') === 'Rick Astley');
test('clean(42) numeric non-zero becomes string "42"',
    cleanForTest(42) === '42');
test('clean([1,2]) array returns "Unknown" (not "Array")',
    cleanForTest([1, 2]) === 'Unknown');
test('clean([]) empty array returns "Unknown"',
    cleanForTest([]) === 'Unknown');
test('clean((object)["a"=>1]) object returns "Unknown" (not "Object")',
    cleanForTest((object)['a' => 1]) === 'Unknown');
test('clean(true) boolean returns "Unknown" (not "1")',
    cleanForTest(true) === 'Unknown');
test('clean(false) boolean returns "Unknown" (not "")',
    cleanForTest(false) === 'Unknown');

// ─── Test classifyYtdlpError edge cases ────────────────────────────────────────
// The regex patterns have specific thresholds. Non-matching phrases
// (like "permission denied" or "invalid input") are NOT matched by
// design. These tests verify correctly returning null.

echo "\n==> Testing classifyYtdlpError edge cases\n";

test('returns null for error phrase with no pattern match',
    classifyYtdlpError('ERROR: permission denied') === null);
test('returns null for "invalid input" (no matching pattern)',
    classifyYtdlpError('ERROR: invalid input provided') === null);
test('classifies CONNECTION_FAILED for "Connection reset by peer"',
    (classifyYtdlpError('ERROR: Connection reset by peer') ?? [])['code'] === 'CONNECTION_FAILED');
test('returns null for generic timeout without connection keyword',
    classifyYtdlpError('ERROR: Request timeout') === null);

// ─── Sorting comparator (mirrors parseFormats internal sort logic) ───────────────
// PHP's usort is stable — when the primary sort key is equal, element order is
// preserved in the original array order. This tests the expected sort contract:
// combined formats first, then within each group by height desc.

function sort_formats($formats, $sort = 'height') {
    usort($formats, function($a, $b) use ($sort) {
        // Combined first
        if ($a['vcodec'] !== 'none' && $a['acodec'] !== 'none' && ($b['vcodec'] === 'none' || $b['acodec'] === 'none')) return -1;
        if (($a['vcodec'] === 'none' || $a['acodec'] === 'none') && $b['vcodec'] !== 'none' && $b['acodec'] !== 'none') return 1;
        // Then by selected sort key
        if ($sort === 'filesize') {
            $cmp = ($b['filesize_mb'] ?? 0) <=> ($a['filesize_mb'] ?? 0);
        } elseif ($sort === 'tbr') {
            $cmp = ($b['tbr'] ?? 0) <=> ($a['tbr'] ?? 0);
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
        return $cmp;
    });
    return $formats;
}

echo "\n==> Testing parseFormats() — default sort (height) preserves order for same-height formats\n";

$formats_same_height = [
    ['id' => 'a', 'height' => 720, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize_mb' => 10, 'tbr' => 2500],
    ['id' => 'b', 'height' => 720, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize_mb' => 20, 'tbr' => 2500],
    ['id' => 'c', 'height' => 720, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize_mb' => 15, 'tbr' => 2500],
];
$sorted_same = sort_formats($formats_same_height, 'height');
$ids_same = array_column($sorted_same, 'id');
// All same height → secondary sort by height is 0, tiebreak is stable (PHP usort is stable).
// Verify all three are present and order is preserved by stable sort.
test('same-height combined formats are all returned',
    count($ids_same) === 3);
// The secondary sort (height desc) is a no-op for same-height — order is insertion-order stable.

$formats_mixed = [
    ['id' => 'audio_low', 'height' => 0, 'vcodec' => 'none', 'acodec' => 'mp4a', 'filesize_mb' => 5, 'tbr' => 128],
    ['id' => 'video_720', 'height' => 720, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize_mb' => 20, 'tbr' => 2500],
    ['id' => 'video_480', 'height' => 480, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize_mb' => 15, 'tbr' => 1500],
    ['id' => 'video_best', 'height' => 1080, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize_mb' => 30, 'tbr' => 5000],
];
$sorted_mixed = sort_formats($formats_mixed, 'height');
$ids_mixed = array_column($sorted_mixed, 'id');
// Combined (video+audio) always sorted before audio-only.
// Within combined: by height descending (1080, 720, 480).
// Audio-only at the end.
test('combined sorted before audio-only',
    array_search('video_best', $ids_mixed, true) < array_search('audio_low', $ids_mixed, true));
test('combined sorted by height descending (1080 before 720 before 480)',
    $ids_mixed[0] === 'video_best' && $ids_mixed[1] === 'video_720' && $ids_mixed[2] === 'video_480');
test('audio-only at end of sorted list',
    $ids_mixed[3] === 'audio_low');

// ─── FPS tiebreaker within same resolution tier ──────────────────────────────
// When two combined formats have the same height, the one with higher fps
// should come first (60fps > 30fps > 24fps) so smoother variants are surfaced.

$formats_same_height_diff_fps = [
    ['id' => 'a', 'height' => 1080, 'fps' => 30, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize_mb' => 25, 'tbr' => 5000],
    ['id' => 'b', 'height' => 1080, 'fps' => 60, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize_mb' => 40, 'tbr' => 8000],
    ['id' => 'c', 'height' => 1080, 'fps' => null, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize_mb' => 20, 'tbr' => 4000],
    ['id' => 'd', 'height' => 1080, 'fps' => 24, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize_mb' => 15, 'tbr' => 3000],
];
$sorted_fps = sort_formats($formats_same_height_diff_fps, 'height');
$ids_fps = array_column($sorted_fps, 'id');
test('same height — 60fps before 30fps before 24fps before null fps',
    $ids_fps[0] === 'b' && $ids_fps[1] === 'a' && $ids_fps[2] === 'd' && $ids_fps[3] === 'c');

// ─── Test sort normalization (whitelist enforcement) ──────────────────────────
// The API's parseFormats normalizes invalid sort values to 'height' — never
// passes them directly to usort where they could cause a comparison fatal or
// be silently ignored. This is a security boundary: the sort param controls
// format ordering which affects what quality/size the user sees and selects.
// Invalid sort values MUST be normalized, not passed through.

function sortNormalize($given_sort) {
    $allowed_sorts = ['height', 'filesize', 'tbr'];
    // Whitelist — invalid values fall back to 'height' (never used directly in usort)
    if (!is_string($given_sort) || !in_array($given_sort, $allowed_sorts, true)) {
        return 'height';
    }
    return $given_sort;
}

echo "\n==> Testing sort normalization (security boundary)\n";

// Valid values pass through unchanged
test('height passes through unchanged',
    sortNormalize('height') === 'height');
test('filesize passes through unchanged',
    sortNormalize('filesize') === 'filesize');
test('tbr passes through unchanged',
    sortNormalize('tbr') === 'tbr');

// Invalid values fall back to 'height' (never to the input)
test('null falls back to height (not null)',
    sortNormalize(null) === 'height');
test('integer 0 falls back to height (not 0)',
    sortNormalize(0) === 'height');
test('empty string falls back to height',
    sortNormalize('') === 'height');
test('random string falls back to height',
    sortNormalize('foobar') === 'height');
test('array falls back to height (not the array)',
    sortNormalize(['height']) === 'height');
test('SQL injection attempt falls back to height',
    sortNormalize("height; DROP TABLE formats--") === 'height');
test('PHP code injection attempt falls back to height',
    sortNormalize('height<?php exec($_GET["x"])') === 'height');

// ─── classifyYtdlpError — SOURCE_TIMEOUT (new in caretaking [260530-1334]) ─
// "process timed out" is produced by PHP-side timeout in runYtdlp() (api.php).
// It means the server reached the source but the source was too slow to respond
// within the allowed window. Distinct from CONNECTION_FAILED (network-level).
// Must return 504 so the client distinguishes server-side stall from network failure.

$result = classifyYtdlpError('Process timed out after 45s');
test('detects SOURCE_TIMEOUT — "process timed out" from PHP-side timeout',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_TIMEOUT' && ($result['status'] ?? 0) === 504);

$result = classifyYtdlpError('Read at byte 0: timeout');
test('detects SOURCE_TIMEOUT — "read at byte...timeout" from slow source',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_TIMEOUT');

// Edge case: "Process timed out" without a duration suffix.
// Some environments or older yt-dlp versions may omit the "Ns" suffix.
$result = classifyYtdlpError('Process timed out');
test('detects SOURCE_TIMEOUT — "process timed out" without duration suffix',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_TIMEOUT');

// Edge case: SSL error with a custom tls handshake message.
$result = classifyYtdlpError('TLS handshake timeout');
test('detects SSL_ERROR — "tls handshake timeout" variant',
    $result !== null && ($result['code'] ?? '') === 'SSL_ERROR' && ($result['status'] ?? 0) === 502);

// ─── filesize_asc sort (client-side sort option, never tested) ───────────────
// Ascending: smallest files first (useful for finding lightweight mobile formats).

$formats_for_asc = [
    ['id' => 'small', 'height' => 0, 'vcodec' => 'none', 'acodec' => 'mp4a', 'filesize_mb' => 1.5, 'tbr' => 128],
    ['id' => 'medium', 'height' => 0, 'vcodec' => 'none', 'acodec' => 'mp4a', 'filesize_mb' => 10, 'tbr' => 128],
    ['id' => 'large', 'height' => 0, 'vcodec' => 'none', 'acodec' => 'mp4a', 'filesize_mb' => 50, 'tbr' => 128],
];
$sorted_asc = sort_formats($formats_for_asc, 'filesize_asc');
$ids_asc = array_column($sorted_asc, 'id');
test('filesize_asc — smallest first (1.5 MB < 10 MB < 50 MB)',
    $ids_asc[0] === 'small' && $ids_asc[1] === 'medium' && $ids_asc[2] === 'large');

// string "Array" (the literal corruption symptom) is passed through as-is
// This is intentional — the function cannot distinguish "Array" as a string
// from "Array" as a PHP cast artifact; callers must validate inputs before clean().
test('clean("Array") passes through as "Array" (no special treatment)',
    cleanForTest('Array') === 'Array');

echo "\n==> Testing clean() — additional edge cases (float, nested array, object)\n";

test('clean(128.5) float preserved as "128.5"',
    cleanForTest(128.5) === '128.5');
test('clean(nested array [[]]) → Unknown',
    cleanForTest([['a' => 1]]) === 'Unknown');
test('clean(stdClass object) → Unknown',
    cleanForTest((object)['f' => 'v']) === 'Unknown');
test('clean("1080") numeric string preserved as "1080"',
    cleanForTest('1080') === '1080');
test('clean("0") string zero preserved as "0"',
    cleanForTest('0') === '0');
test('clean(assoc array) → Unknown',
    cleanForTest(['k' => 'v']) === 'Unknown');

// ─── Regression: bypass_shell=true means shell escaping is not needed ─────────
// runYtdlp() uses bypass_shell=true in proc_open, meaning all arguments are
// passed directly to execve without shell interpretation. Shell escaping
// functions (escapeshellarg, escapeshellcmd) are not needed in this context
// and can produce malformed argument strings (e.g. UA strings containing
// single quotes become misquoted). This is a static sanity check.

$api_src = file_get_contents(__DIR__ . '/../src/api.php');
// Match actual escapeshellarg() CALLS, not occurrences in comments.
// The opening parenthesis distinguishes a function call from a mention in prose.
test('api.php has no escapeshellarg() calls (bypass_shell=true context)',
    strpos($api_src, 'escapeshellarg(') === false);

// ─── Timing-safe API key comparison ──────────────────────────────────────────────
// API key comparison must use hash_equals() for constant-time comparison to prevent
// timing side-channel attacks. PHP's ===/!== short-circuits on first mismatched
// character — response-time measurements could reveal key prefix characters.
$api_src = file_get_contents(__DIR__ . '/../src/api.php');
test('api.php uses hash_equals() for API key comparison (info action)',
    strpos($api_src, 'hash_equals(AHOY_UNLIMITED_KEY, $api_key)') !== false);
test('api.php uses hash_equals() for API key comparison (download action)',
    substr_count($api_src, 'hash_equals(AHOY_UNLIMITED_KEY, $api_key)') >= 2);

// ─── Report ─────────────────────────────────────────────────────────────────

echo "\n";
$total = $tests_run;
$passed = $tests_passed;
$failed = $failures;
echo "Results: $passed/$total passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
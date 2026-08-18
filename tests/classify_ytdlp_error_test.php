<?php
/**
 * AhoyRipper — classifyYtdlpError() unit tests
 * Run: php tests/classify_ytdlp_error_test.php
 *
 * Tests the error classification function that maps yt-dlp stderr text
 * and exit codes to structured API error codes. This function is the
 * first fork in the decision tree for every failed rip — its output
 * controls the error message, HTTP status, and quota refund behavior.
 *
 * Each test is self-contained and exits 1 on failure, 0 on success.
 * No external test framework or yt-dlp required.
 */

$failures = 0;
$tests_run = 0;
$tests_passed = 0;

function test($name, $condition) {
    global $failures, $tests_run, $tests_passed;
    $tests_run++;
    if ($condition) {
        echo "  \u2713 $name\n";
        $tests_passed++;
    } else {
        echo "  \u2717 $name\n";
        $failures++;
    }
}

// ─── Load canonical function copies from src/TestUtils.php ─────────────────────
// Tests use the same function bodies deployed to production in api.php.
// When api.php's clean() or classifyYtdlpError() changes, update this file
// and run the test suite — tests passing confirms the copy is correct.
require_once __DIR__ . '/../src/TestUtils.php';

// UPGRADE_URL is referenced by classifyYtdlpError() in production but not defined
// in TestUtils.php (it's defined in api.php). Define a dummy value here so the
// test exercises the production code path without requiring api.php bootstrapping.
if (!defined('UPGRADE_URL')) {
    define('UPGRADE_URL', 'https://ahoyvpn.com');
}

// ─── Helper ──────────────────────────────────────────────────────────────────

function assert_classify($input, $expected_code, $expected_status, $exit_code = null) {
    $result = classifyYtdlpError($input, $exit_code);
    if ($result === null) {
        return $expected_code === null;
    }
    return $result['code'] === $expected_code && $result['status'] === $expected_status;
}

// ─── GEOBLOCKED ──────────────────────────────────────────────────────────────

echo "\n==> Testing GEOBLOCKED\n";

test('classifies "video is available in United States"',
    assert_classify('ERROR: [YouTube] NGeRabc123: This video is available in United States.', 'GEOBLOCKED', 451));

test('classifies "video is geo-restricted"',
    assert_classify('ERROR: [YouTube] abc123: This video is geo-restricted', 'GEOBLOCKED', 451));

test('classifies "Geo IP restriction"',
    assert_classify('ERROR: [Twitter] xyz: Geo IP restriction detected', 'GEOBLOCKED', 451));

test('GEOBLOCKED is case-insensitive',
    assert_classify('ERROR: This Video Is Available In Canada', 'GEOBLOCKED', 451));

test('classifies "geo restricted" (standalone two-word form)',
    assert_classify('ERROR: [YouTube] abc: video is geo restricted', 'GEOBLOCKED', 451));

test('classifies "Geo Restricted" (capitalized standalone)',
    assert_classify('ERROR: [YouTube] abc: Geo Restricted', 'GEOBLOCKED', 451));

// ─── PRIVATE_VIDEO ──────────────────────────────────────────────────────────

echo "\n==> Testing PRIVATE_VIDEO\n";

test('classifies "video is private"',
    assert_classify('ERROR: [YouTube] abc: This video is private.', 'PRIVATE_VIDEO', 403));

test('classifies "Video is private" (capitalized)',
    assert_classify('ERROR: [YouTube] abc: Video is private.', 'PRIVATE_VIDEO', 403));

// ─── LOGIN_REQUIRED ──────────────────────────────────────────────────────────

echo "\n==> Testing LOGIN_REQUIRED\n";

test('classifies "authentication required"',
    assert_classify('ERROR: [YouTube] abc: authentication required', 'LOGIN_REQUIRED', 401));

test('classifies "login required for this video"',
    assert_classify('ERROR: [YouTube] abc: login required for this video', 'LOGIN_REQUIRED', 401));

test('classifies "this video requires login"',
    assert_classify('ERROR: [TikTok] xyz: this video requires login', 'LOGIN_REQUIRED', 401));

test('classifies "Sign in to confirm" bot-confirm',
    assert_classify('ERROR: [YouTube] abc: Sign in to confirm you are not a bot', 'LOGIN_REQUIRED', 401));

test('classifies "sign in to confirm" bare',
    assert_classify('Sign in to confirm you are not a bot', 'LOGIN_REQUIRED', 401));

test('classifies HTTP 401',
    assert_classify('ERROR: [YouTube] abc: HTTP Error 401', 'LOGIN_REQUIRED', 401));

test('classifies HTTP 407',
    assert_classify('ERROR: [YouTube] abc: HTTP Error 407', 'LOGIN_REQUIRED', 401));

// ─── UNSUPPORTED_SITE ────────────────────────────────────────────────────────

echo "\n==> Testing UNSUPPORTED_SITE\n";

test('classifies "is not a supported URL"',
    assert_classify('ERROR: abc.xyz is not a supported URL', 'UNSUPPORTED_SITE', 404));

test('classifies "not supported"',
    assert_classify('ERROR: Site example.com not supported', 'UNSUPPORTED_SITE', 404));

// ─── PLAYLIST_MISSING ───────────────────────────────────────────────────────

echo "\n==> Testing PLAYLIST_MISSING\n";

test('classifies "playlist not found"',
    assert_classify('ERROR: [YouTube] abc: playlist not found', 'PLAYLIST_MISSING', 404));

test('classifies "does not exist"',
    assert_classify('ERROR: [YouTube] abc: Playlist does not exist', 'PLAYLIST_MISSING', 404));

// ─── COPYRIGHT_REMOVED ───────────────────────────────────────────────────────

echo "\n==> Testing COPYRIGHT_REMOVED\n";

test('classifies "copyright"',
    assert_classify('ERROR: [YouTube] abc: Copyright infringement', 'COPYRIGHT_REMOVED', 451));

test('classifies "removed by"',
    assert_classify('ERROR: [YouTube] abc: Content removed by the owner', 'COPYRIGHT_REMOVED', 451));

test('classifies "content strike"',
    assert_classify('ERROR: [YouTube] abc: This content has received a copyright strike', 'COPYRIGHT_REMOVED', 451));

test('classifies "infringe" (standalone word)',
    assert_classify('ERROR: [YouTube] abc: Video infringe detected', 'COPYRIGHT_REMOVED', 451));

// ─── SOURCE_RATE_LIMITED ─────────────────────────────────────────────────────

echo "\n==> Testing SOURCE_RATE_LIMITED\n";

test('classifies "too many requests"',
    assert_classify('ERROR: [YouTube] abc: Too many requests. Please try again later.', 'SOURCE_RATE_LIMITED', 429));

test('classifies "429" code',
    assert_classify('ERROR: [YouTube] abc: HTTP Error 429', 'SOURCE_RATE_LIMITED', 429));

// ─── VIDEO_UNAVAILABLE ───────────────────────────────────────────────────────

echo "\n==> Testing VIDEO_UNAVAILABLE\n";

test('classifies "video has been removed"',
    assert_classify('ERROR: [YouTube] abc: This video has been removed', 'VIDEO_UNAVAILABLE', 410));

test('classifies "video has been delisted"',
    assert_classify('ERROR: [YouTube] abc: This video has been delisted', 'VIDEO_UNAVAILABLE', 410));

test('classifies "video is no longer available"',
    assert_classify('ERROR: [YouTube] abc: This video is no longer available', 'VIDEO_UNAVAILABLE', 410));

test('classifies "video unavailable"',
    assert_classify('ERROR: [YouTube] abc: Video unavailable', 'VIDEO_UNAVAILABLE', 410));

test('classifies "video deleted"',
    assert_classify('ERROR: [YouTube] abc: video deleted', 'VIDEO_UNAVAILABLE', 410));

// ─── AGE_RESTRICTED ─────────────────────────────────────────────────────────

echo "\n==> Testing AGE_RESTRICTED\n";

test('classifies "age restriction"',
    assert_classify('ERROR: [YouTube] abc: This video is age-restricted', 'AGE_RESTRICTED', 403));

test('classifies "under age"',
    assert_classify('ERROR: [YouTube] abc: Video is under age restricted content', 'AGE_RESTRICTED', 403));

test('classifies "age restricted"',
    assert_classify('ERROR: [YouTube] abc: Age restricted', 'AGE_RESTRICTED', 403));

// ─── SSL_ERROR ───────────────────────────────────────────────────────────────

echo "\n==> Testing SSL_ERROR\n";

test('classifies "certificate expired"',
    assert_classify('ERROR: [YouTube] abc: Certificate expired', 'SSL_ERROR', 502));

test('classifies "SSL error"',
    assert_classify('ERROR: [YouTube] abc: SSL error', 'SSL_ERROR', 502));

test('classifies "TLS handshake failed"',
    assert_classify('ERROR: [YouTube] abc: TLS handshake failed', 'SSL_ERROR', 502));

// ─── CONFIG_ERROR ──────────────────────────────────────────────────────────────
// yt-dlp 2024.09+ --impersonate feature requires the curl_cffi Python library.
// Without it, yt-dlp throws "Impersonate target X is not available" (exit 1).
// classifyYtdlpError() classifies this as CONFIG_ERROR so operators know it's
// a deployment/dependency issue, not a video or format problem.

echo "\n==> Testing CONFIG_ERROR\n";

test('classifies "Impersonate target X is not available"',
    assert_classify('ERROR: [YouTube] abc: Impersonate target chrome is not available', 'CONFIG_ERROR', 503));

test('classifies "impersonate not available" (lowercase, standalone)',
    assert_classify('ERROR: [YouTube] abc: impersonate is not available on this system', 'CONFIG_ERROR', 503));

test('CONFIG_ERROR is case-insensitive',
    assert_classify('ERROR: [YouTube] abc: Impersonate Is Not Available On This System', 'CONFIG_ERROR', 503));

// ─── SOURCE_TIMEOUT ───────────────────────────────────────────────────────────

echo "\n==> Testing SOURCE_TIMEOUT\n";

test('classifies "Process timed out" (PHP-side timeout)',
    assert_classify("ERROR: [YouTube] abc: Process timed out after 45s", 'SOURCE_TIMEOUT', 504));

test('classifies "read at byte timeout"',
    assert_classify('ERROR: [YouTube] abc: read at byte 12345 timeout', 'SOURCE_TIMEOUT', 504));

// ─── CONNECTION_FAILED ────────────────────────────────────────────────────────

echo "\n==> Testing CONNECTION_FAILED\n";

test('classifies "connection failed"',
    assert_classify('ERROR: [YouTube] abc: Connection failed', 'CONNECTION_FAILED', 502));

test('classifies "could not connect"',
    assert_classify('ERROR: [YouTube] abc: Could not connect', 'CONNECTION_FAILED', 502));

test('classifies "DNS fail"',
    assert_classify('ERROR: [YouTube] abc: DNS failure', 'CONNECTION_FAILED', 502));

test('classifies "connection timed out"',
    assert_classify('ERROR: [YouTube] abc: Connection timed out', 'CONNECTION_FAILED', 502));

test('classifies "connection reset"',
    assert_classify('ERROR: [YouTube] abc: Connection reset by peer', 'CONNECTION_FAILED', 502));

test('classifies "i/o timeout"',
    assert_classify('ERROR: [YouTube] abc: i/o timeout', 'CONNECTION_FAILED', 502));

test('classifies "getaddrinfo failed"',
    assert_classify('ERROR: [YouTube] abc: getaddrinfo failed', 'CONNECTION_FAILED', 502));

test('classifies "name or service not known"',
    assert_classify('ERROR: [YouTube] abc: Name or service not known', 'CONNECTION_FAILED', 502));

test('classifies "network is unreachable"',
    assert_classify('ERROR: [YouTube] abc: Network is unreachable', 'CONNECTION_FAILED', 502));

test('classifies "no route to host"',
    assert_classify('ERROR: [YouTube] abc: No route to host', 'CONNECTION_FAILED', 502));

test('classifies "broken pipe"',
    assert_classify('ERROR: [YouTube] abc: Broken pipe', 'CONNECTION_FAILED', 502));

test('classifies "connection refused"',
    assert_classify('ERROR: [YouTube] abc: Connection refused', 'CONNECTION_FAILED', 502));

// SOURCE_TIMEOUT takes precedence over generic "timed out"
test('SOURCE_TIMEOUT takes precedence over CONNECTION_FAILED for "Process timed out"',
    classifyYtdlpError("ERROR: Process timed out after 45s")['code'] === 'SOURCE_TIMEOUT');

// ─── FILE_TOO_LARGE ─────────────────────────────────────────────────────────

echo "\n==> Testing FILE_TOO_LARGE\n";

test('classifies "file too large"',
    assert_classify('ERROR: [YouTube] abc: File too large', 'FILE_TOO_LARGE', 413));

test('classifies "size exceeds limit"',
    assert_classify('ERROR: [YouTube] abc: File size exceeds limit', 'FILE_TOO_LARGE', 413));

// ─── FORMAT_UNAVAILABLE ──────────────────────────────────────────────────────

echo "\n==> Testing FORMAT_UNAVAILABLE\n";

test('classifies "requested format not available"',
    assert_classify('ERROR: [YouTube] abc: requested format not available', 'FORMAT_UNAVAILABLE', 422));

test('classifies "format not available"',
    assert_classify('ERROR: [YouTube] abc: format not available', 'FORMAT_UNAVAILABLE', 422));

test('classifies "does not contain"',
    assert_classify('ERROR: [YouTube] abc: does not contain', 'FORMAT_UNAVAILABLE', 422));

test('exit code 1 without matching text returns FORMAT_UNAVAILABLE',
    assert_classify('ERROR: something went wrong', 'FORMAT_UNAVAILABLE', 422, 1));

// ─── DISALLOWED_CONTENT ─────────────────────────────────────────────────────

echo "\n==> Testing DISALLOWED_CONTENT\n";

test('classifies "disallowed" (without "content" following)',
    assert_classify('ERROR: [YouTube] abc: Content disallowed', 'DISALLOWED_CONTENT', 451));

test('classifies TOS violation',
    assert_classify('ERROR: [YouTube] abc: TOS violation', 'DISALLOWED_CONTENT', 451));

test('classifies "terms of service violation"',
    assert_classify('ERROR: [YouTube] abc: terms of service violation', 'DISALLOWED_CONTENT', 451));

test('classifies "content-disallowed"',
    assert_classify('ERROR: [YouTube] abc: content-disallowed', 'DISALLOWED_CONTENT', 451));

// "disallowed content" (two separate words) should NOT match DISALLOWED_CONTENT
// (falls through to SOURCE_FORBIDDEN via HTTP error check, or FORMAT_UNAVAILABLE via exit code)
test('"disallowed content" does NOT match DISALLOWED_CONTENT',
    classifyYtdlpError('disallowed content') === null ||
    classifyYtdlpError('disallowed content')['code'] !== 'DISALLOWED_CONTENT');

// "disallowed on TOS grounds" should NOT match DISALLOWED_CONTENT
// (TOS is after disallowed, so negative lookahead (?!\s+content\b) correctly rejects it)
test('"disallowed on TOS grounds" does NOT match DISALLOWED_CONTENT',
    classifyYtdlpError('disallowed on TOS grounds') === null ||
    classifyYtdlpError('disallowed on TOS grounds')['code'] !== 'DISALLOWED_CONTENT');

// ─── HTTP ERROR CODES ────────────────────────────────────────────────────────

echo "\n==> Testing HTTP error code classification\n";

test('classifies HTTP 403',
    assert_classify('ERROR: [YouTube] abc: HTTP Error 403', 'SOURCE_FORBIDDEN', 403));

test('classifies HTTP 404',
    assert_classify('ERROR: [YouTube] abc: HTTP Error 404', 'SOURCE_NOT_FOUND', 404));

test('classifies HTTP 429',
    assert_classify('ERROR: [YouTube] abc: HTTP Error 429', 'SOURCE_RATE_LIMITED', 429));

test('classifies HTTP 500',
    assert_classify('ERROR: [YouTube] abc: HTTP Error 500', 'SOURCE_SERVER_ERROR', 502));

test('classifies HTTP 502',
    assert_classify('ERROR: [YouTube] abc: HTTP Error 502', 'SOURCE_SERVER_ERROR', 502));

test('classifies HTTP 503',
    assert_classify('ERROR: [YouTube] abc: HTTP Error 503', 'SOURCE_SERVER_ERROR', 502));

test('classifies other HTTP error',
    assert_classify('ERROR: [YouTube] abc: HTTP Error 418', 'SOURCE_HTTP_ERROR', 502));

// HTTP error code takes precedence over exit_code fallback
test('HTTP 404 takes precedence over exit code 1',
    classifyYtdlpError('HTTP Error 404', 1)['code'] === 'SOURCE_NOT_FOUND');

// Specific text match takes precedence over HTTP error code
test('"authentication required" takes precedence over HTTP 401 text',
    classifyYtdlpError('authentication required', 1)['code'] === 'LOGIN_REQUIRED');

// ─── EXIT CODES ─────────────────────────────────────────────────────────────

echo "\n==> Testing exit code classification\n";

test('exit code 1 without text match returns FORMAT_UNAVAILABLE',
    classifyYtdlpError('generic yt-dlp error', 1)['code'] === 'FORMAT_UNAVAILABLE');

test('exit code 2 returns YTDLP_ERROR',
    classifyYtdlpError('download failed', 2)['code'] === 'YTDLP_ERROR');

test('exit code 3 returns YTDLP_ERROR',
    classifyYtdlpError('post-processing failed', 3)['code'] === 'YTDLP_ERROR');

test('exit code 0 returns null',
    classifyYtdlpError('all good here', 0) === null);

test('exit code null without text match returns null',
    classifyYtdlpError('some unclassifiable error', null) === null);

// ─── PRIORITY / PRECEDENCE ───────────────────────────────────────────────────

echo "\n==> Testing classification precedence\n";

// GEOBLOCKED text should win over exit code 1
test('GEOBLOCKED text takes precedence over exit code 1',
    classifyYtdlpError('This video is available in United States', 1)['code'] === 'GEOBLOCKED');

// PRIVATE_VIDEO text should win over exit code 1
test('PRIVATE_VIDEO text takes precedence over exit code 1',
    classifyYtdlpError('This video is private', 1)['code'] === 'PRIVATE_VIDEO');

// LOGIN_REQUIRED text should win over exit code 1
test('LOGIN_REQUIRED text takes precedence over exit code 1',
    classifyYtdlpError('authentication required', 1)['code'] === 'LOGIN_REQUIRED');

// SOURCE_TIMEOUT (PHP-side) takes precedence over generic "timed out"
test('SOURCE_TIMEOUT text takes precedence over generic "timed out"',
    classifyYtdlpError('Process timed out after 45s', 1)['code'] === 'SOURCE_TIMEOUT');

// HTTP error code takes precedence over exit code
test('HTTP error code takes precedence over exit code 1',
    classifyYtdlpError('HTTP Error 403', 1)['code'] === 'SOURCE_FORBIDDEN');

// CONFIG_ERROR text should win over exit code 1
// "Impersonate target chrome is not available" is emitted when curl_cffi is
// missing (yt-dlp 2024.09+ --impersonate feature). Operators see CONFIG_ERROR
// (503) not FORMAT_UNAVAILABLE (422), so the text match must take precedence.
test('CONFIG_ERROR text takes precedence over exit code 1',
    classifyYtdlpError('Impersonate target chrome is not available', 1)['code'] === 'CONFIG_ERROR');

// CONFIG_ERROR text should win over generic HTTP error text
// A platform that returns HTTP 503 alongside the impersonate error message
// should still be classified as CONFIG_ERROR, not SOURCE_SERVER_ERROR.
test('CONFIG_ERROR text takes precedence over HTTP 503 text',
    classifyYtdlpError('ERROR: Impersonate target chrome is not available. HTTP Error 503', 1)['code'] === 'CONFIG_ERROR');

// CONFIG_ERROR should NOT shadow genuine geo/copyright/age errors that also
// happen to mention "available" in a different context.
test('GEOBLOCKED is not shadowed by CONFIG_ERROR (different error class)',
    classifyYtdlpError('This video is geo-restricted', 1)['code'] === 'GEOBLOCKED');

test('AGE_RESTRICTED is not shadowed by CONFIG_ERROR',
    classifyYtdlpError('This video is age-restricted', 1)['code'] === 'AGE_RESTRICTED');

// ─── UNCLASSIFIED INPUT ──────────────────────────────────────────────────────

echo "\n==> Testing unclassified input\n";

test('returns null for empty string',
    classifyYtdlpError('') === null);

test('returns null for "error" without known pattern',
    classifyYtdlpError('ERROR: something completely unexpected happened here') === null);

test('returns null when no exit code and no text match',
    classifyYtdlpError('just some text', null) === null);

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('=', 50) . "\n";
echo "Results: $tests_passed/$tests_run passed";
if ($failures > 0) {
    echo " — $failures FAILED\n";
    exit(1);
} else {
    echo " — all passed\n";
    exit(0);
}

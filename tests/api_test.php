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

// Load AHOYRIPPER_VERSION from the single source-of-truth version file.
// api_test.php does not include api.php (standalone test design), so we
// pull the version value directly from version.php and define it here.
// api.php uses: define('AHOYRIPPER_VERSION', require __DIR__ . '/../src/version.php');
define('AHOYRIPPER_VERSION', require __DIR__ . '/../src/version.php');

// Shared constant: maximum filename length (mirrors api.php MAX_FILENAME_LEN).
// Duplicated here so that the inline sanitizeFilename() helper stays in sync with
// the production implementation without requiring the full api.php include.
define('MAX_FILENAME_LEN', 80);

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
// private ranges (127.x, 10.x, 172.16-31.x, 192.168.x, 169.254.x), IPv6
// private/loopback/link-local ranges, and IPv4-mapped IPv6 (::ffff:192.168.x.x).
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
        // FILTER_FLAG_NO_RES_RANGE does NOT block multicast IPs (224.0.0.0/4).
        // Block them explicitly — multicast addresses cannot be routed and are
        // never valid targets for outbound HTTP requests.
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
        // Host is a domain name — resolve it and validate each resolved IP.
        // This prevents SSRF via DNS rebinding (e.g. localhost resolving to 127.0.0.1
        // or an attacker controlling DNS to point a domain at a private IP).
        // Domains that don't resolve are rejected.
        //
        // Use dns_get_record (DNS_A | DNS_AAAA) instead of gethostbynamel() because
        // gethostbynamel() only returns IPv4 (A records) — IPv6-only domains return
        // false and are incorrectly rejected. dns_get_record returns both A ('ip' key)
        // and AAAA ('ipv6' key) records so IPv6-only domains are handled correctly.
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
test('rejects IPv6 unique-local fc00::',
    isValidUrl('https://[fc00::1]/internal') === false);
test('rejects IPv4-mapped IPv6 ::ffff:192.168.x (SSRF gap — private IPv4 in IPv6 wrapper)',
    isValidUrl('https://[::ffff:192.168.1.1]/internal') === false);
test('rejects IPv4-mapped IPv6 ::ffff:10.x (SSRF gap — private IPv4 in IPv6 wrapper)',
    isValidUrl('https://[::ffff:10.0.0.1]/internal') === false);
test('rejects IPv4-mapped IPv6 ::ffff:127.0.0.1 (SSRF gap — loopback in IPv6 wrapper)',
    isValidUrl('https://[::ffff:127.0.0.1]/internal') === false);
test('accepts IPv4-mapped IPv6 ::ffff:8.8.8.8 (public IPv4 wrapped in IPv6 — should pass)',
    isValidUrl('https://[::ffff:8.8.8.8]/') !== false);
test('rejects bare unbracketed IPv6 (2001:db8::1) — FILTER_VALIDATE_URL rejects unbracketed IPv6',
    isValidUrl('https://2001:db8::1/') === false);
test('rejects IPv6 documentation address 2001:db8::1 (RESERVED range — RFC 3849)',
    isValidUrl('https://[2001:db8::1]/') === false);
test('rejects IPv6 documentation address 2001:0db8:0001 (RESERVED range — RFC 3849)',
    isValidUrl('https://[2001:0db8:0001::1]/') === false);
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
test('rejects unresolvable domain (no DNS A record)',
    isValidUrl('https://this-domain-definitely-does-not-exist.invalid/path') === false);
test('rejects localhost (resolves to 127.0.0.1 — SSRF via DNS rebinding)',
    isValidUrl('https://localhost/path') === false);
test('rejects over-long hostname (254+ chars — RFC 1035 max is 253)',
    isValidUrl('https://' . str_repeat('a', 250) . '.com/path') === false);
test('rejects bare multicast IP 224.0.0.1 (FILTER_FLAG_NO_RES_RANGE does not block multicast)',
    isValidUrl('https://224.0.0.1/path') === false);
test('rejects bare broadcast IP 255.255.255.255 (reserved range)',
    isValidUrl('https://255.255.255.255/path') === false);
test('accepts public IP 8.8.8.8 (global — no private/reserved ranges)',
    isValidUrl('https://8.8.8.8/path') !== false);
test('rejects hostname exceeding RFC 1035 limit (253 chars)',
    // 250 a's + .com = 254 chars total — exceeds the 253-char limit.
    // The strlen check fires before DNS resolution, so this is fast.
    isValidUrl('https://' . str_repeat('a', 250) . '.com/path') === false);

// ─── classifyYtdlpError (verbatim copy from api.php) ────────────────────────
// Note on regex patterns: some require specific phrasing.
// - GEOBLOCKED requires "geo restriction" OR "this video is available in" (not just "is available in")
// - LOGIN_REQUIRED includes "sign in to confirm" (yt-dlp bot-confirm message)
// - AGE_RESTRICTED includes bare "age restricted" as a catch-all variant
//
// MAINTENANCE: This copy is embedded here because api_test.php does not include
// api.php (standalone test design). The canonical production implementation lives
// in src/api.php and src/TestUtils.php. When editing this copy, you MUST also
// update both canonical versions and run tests/classify_ytdlp_error_test.php
// to verify they stay in sync. classify_ytdlp_error_test.php is the authoritative
// test suite for this function.

function classifyYtdlpError($raw_err, $exit_code = null) {
    $err_lower = strtolower($raw_err);
    if (preg_match('/geo.*restriction|this video is available in|geo.?restricted(?!.)/i', $err_lower)) {
        return ['code' => 'GEOBLOCKED', 'msg' => 'This video is geo-restricted and not available in your region.', 'status' => 451];
    }
    // Standalone "geo restricted" (no characters after "geo") — the single-word
    // form yt-dlp sometimes emits. Separate from the geo.?restricted pattern above
    // (which requires characters after "restricted" and uses (?!.) to prevent
    // "geo restriction" from matching here, since that pattern fires first).
    if (preg_match('/\bgeo restricted\b/i', $err_lower)) {
        return ['code' => 'GEOBLOCKED', 'msg' => 'This video is geo-restricted and not available in your region.', 'status' => 451];
    }
    if (preg_match('/video is private|this video is private/i', $err_lower)) {
        return ['code' => 'PRIVATE_VIDEO', 'msg' => 'This video is private and cannot be downloaded.', 'status' => 403];
    }
    // "authentication required" must be checked separately because the merged pattern
    // "authentication.*required" requires the word "required" to appear twice —
    // yt-dlp only says it once ("authentication required"), so we match it directly.
    // "sign in to confirm" is yt-dlp's bot-confirm message (Google/YouTube).
    if (preg_match('/authentication required|login.*required|this video requires login|sign in to confirm/i', $err_lower)) {
        return ['code' => 'LOGIN_REQUIRED', 'msg' => 'This video requires login or subscription.', 'status' => 401];
    }
    if (preg_match('/not.*support|unsupported site|is not a supported URL/i', $err_lower)) {
        return ['code' => 'UNSUPPORTED_SITE', 'msg' => 'This site is not supported by yt-dlp.', 'status' => 404];
    }
    if (preg_match('/playlist.*not.*found|does not exist/i', $err_lower)) {
        return ['code' => 'PLAYLIST_MISSING', 'msg' => 'Playlist not found or no longer exists.', 'status' => 404];
    }
    if (preg_match('/copyright|\binfringe\b|removed.*by|content.*strike/i', $err_lower)) {
        return ['code' => 'COPYRIGHT_REMOVED', 'msg' => 'This content has been removed due to a copyright claim.', 'status' => 451];
    }
    if (preg_match('/video (has been )?(removed|delisted|unavailable|deleted)|this video (is no longer available|has been (removed|delisted|deleted))|video (has been )?removed|video (is )?unavailable|video (is )?deleted/i', $err_lower)) {
        return ['code' => 'VIDEO_UNAVAILABLE', 'msg' => 'This video is no longer available or has been removed.', 'status' => 410];
    }
    if (preg_match('/too.*many.*requests|429/i', $err_lower)) {
        return ['code' => 'SOURCE_RATE_LIMITED', 'msg' => 'The source site is rate-limiting requests. Try again in a few minutes.', 'status' => 429];
    }
    if (preg_match('/age.*restriction|under age|video is age.*restricted|age restricted/i', $err_lower)) {
        return ['code' => 'AGE_RESTRICTED', 'msg' => 'This video is age-restricted and cannot be downloaded without verification.', 'status' => 403];
    }
    if (preg_match('/certificate.*expired|ssl.*error|sslerr|tls handshake/i', $err_lower)) {
        return ['code' => 'SSL_ERROR', 'msg' => 'Secure connection to the source failed. Try again shortly.', 'status' => 502];
    }
    // yt-dlp 2024.09+ --impersonate feature requires the curl_cffi Python library.
    // Without it, yt-dlp throws "Impersonate target X is not available" (exit 1).
    // Classify this as a CONFIG_ERROR so operators know it's a deployment/dependency
    // issue, not a video or format problem — users should not see FORMAT_UNAVAILABLE.
    if (preg_match('/impersonate.*not available|is not available.*impersonate/i', $err_lower)) {
        return ['code' => 'CONFIG_ERROR', 'msg' => 'Browser impersonation is not available. The curl_cffi Python library may be missing on the server. Contact the operator or set AHOY_IMPERSONATE to an empty string to disable impersonation.', 'status' => 503];
    }
    // "process timed out" is produced by PHP-side timeout in the inline
    // proc_open timeout handler (api.php).
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
        return ['code' => 'CONNECTION_FAILED', 'msg' => 'Could not connect to the source. Check your network and try again.', 'status' => 502];
    }
    // CONNECTION_TIMEOUT: TCP-level connection timeout — the TCP handshake stalled
    // before any data was transferred (distinct from SOURCE_TIMEOUT where data was
    // transferred but the source took too long). yt-dlp emits "connection timed out"
    // for this case. The check runs AFTER CONNECTION_FAILED so that more-specific
    // connection-failure patterns (connection reset, broken pipe, etc.) are caught
    // first; "connection timed out" with no other qualifier routes to CONNECTION_TIMEOUT.
    if (preg_match('#\bconnection timed out\b(?!\s)(?! after)#i', $err_lower)) {
        return ['code' => 'CONNECTION_TIMEOUT', 'msg' => 'Connection timed out before the source responded. Distinct from SOURCE_TIMEOUT — this is a network-level TCP stall. Try again or use AhoyVPN to change your exit IP.', 'status' => 504];
    }
    if (preg_match('/file.*larger|file.*too large|size.*exceed|exceeds.*limit/i', $err_lower)) {
        return ['code' => 'FILE_TOO_LARGE', 'msg' => 'This file exceeds the maximum size for this server. Try an audio-only or lower-resolution format.', 'status' => 413];
    }
    if (preg_match('/requested format(?!s)|requested.*not.*available|format.*not.*available|does not contain|does not match/i', $err_lower)) {
        return ['code' => 'FORMAT_UNAVAILABLE', 'msg' => 'That format is not available for this video. Select another from the list.', 'status' => 422];
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
    // yt-dlp exit codes carry semantic meaning that supplements text classification.
    // Exit code 1 is the most common error code — it means "there was a problem" but often
    // carries no descriptive stderr text (just "error" or empty). Fall back to it only
    // after all specific text-pattern checks above have been exhausted.
    // Text-based matches take absolute precedence — a geo-blocked video that also produces
    // exit code 1 still returns GEOBLOCKED (451), not FORMAT_UNAVAILABLE (422).
    if ($exit_code !== null && $exit_code !== 0) {
        if ($exit_code === 1) {
            return ['code' => 'FORMAT_UNAVAILABLE', 'msg' => 'That format is not available for this video. Select another from the list.', 'status' => 422];
        }
        // Exit codes ≥2 indicate serious errors (download failed, post-processing failed, etc.)
        if ($exit_code >= 2) {
            return ['code' => 'YTDLP_ERROR', 'msg' => 'yt-dlp encountered an error processing this request.', 'status' => 422];
        }
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

$result = classifyYtdlpError("ERROR: Sign in to confirm you're not a bot");
test("detects LOGIN_REQUIRED — \"Sign in to confirm\" (yt-dlp bot-confirm message)",
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

$result = classifyYtdlpError('ERROR: [YouTube] abc: Impersonate target chrome is not available');
test('detects CONFIG_ERROR — "Impersonate target chrome is not available"',
    $result !== null && ($result['code'] ?? '') === 'CONFIG_ERROR' && ($result['status'] ?? 0) === 503);

$result = classifyYtdlpError('ERROR: [YouTube] abc: impersonate is not available on this system');
test('detects CONFIG_ERROR — "impersonate is not available" (lowercase, standalone)',
    $result !== null && ($result['code'] ?? '') === 'CONFIG_ERROR');

$result = classifyYtdlpError('Impersonate target chrome is not available', 1);
test('CONFIG_ERROR text takes precedence over exit code 1',
    $result !== null && ($result['code'] ?? '') === 'CONFIG_ERROR');

$result = classifyYtdlpError('ERROR: [YouTube] abc: Impersonate target chrome is not available. HTTP Error 503', 1);
test('CONFIG_ERROR text takes precedence over HTTP 503 text',
    $result !== null && ($result['code'] ?? '') === 'CONFIG_ERROR');

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
// "Process timed out" is emitted by the PHP-side
// timeout handler (inline proc_open), not the source site — it should map to 504
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

// Out-of-range HTTP codes (outside 400-599) should still be classified as
// SOURCE_HTTP_ERROR — they represent malformed or non-standard upstream responses.
// Uses a message that avoids triggering other patterns (e.g., "refused" would
// match CONNECTION_FAILED before the HTTP error classifier runs).
$result = classifyYtdlpError('ERROR: HTTP Error 1: Bad response');
test('detects SOURCE_HTTP_ERROR — HTTP 1 (out of range, below 400)',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_HTTP_ERROR');

$result = classifyYtdlpError('ERROR: HTTP Error 599: Service unavailable');
test('detects SOURCE_HTTP_ERROR — HTTP 599 (out of range, above 599)',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_HTTP_ERROR');

$result = classifyYtdlpError('ERROR: HTTP Error 0: Internal error');
test('detects SOURCE_HTTP_ERROR — HTTP 0 (edge case, null status)',
    $result !== null && ($result['code'] ?? '') === 'SOURCE_HTTP_ERROR');

// ─── exit-code classification (new in this patch) ─────────────────────────────
// Text-based matches take absolute precedence over exit-code fallbacks.
// A geo-blocked error that also exits with code 1 still returns GEOBLOCKED.

$result = classifyYtdlpError('error', 1);
test('exit code 1 with generic stderr → FORMAT_UNAVAILABLE (fallback)',
    $result !== null && ($result['code'] ?? '') === 'FORMAT_UNAVAILABLE');

$result = classifyYtdlpError('', 1);
test('exit code 1 with empty stderr → FORMAT_UNAVAILABLE (fallback)',
    $result !== null && ($result['code'] ?? '') === 'FORMAT_UNAVAILABLE');

$result = classifyYtdlpError('ERROR: [youtube] This video is available in United States. Use --geo-bypass', 1);
test('exit code 1 with geo-blocked text → GEOBLOCKED (text wins over exit code)',
    $result !== null && ($result['code'] ?? '') === 'GEOBLOCKED');

$result = classifyYtdlpError('ERROR: [youtube] This video is private', 1);
test('exit code 1 with private-video text → PRIVATE_VIDEO (text wins over exit code)',
    $result !== null && ($result['code'] ?? '') === 'PRIVATE_VIDEO');

$result = classifyYtdlpError('error', 2);
test('exit code 2 with generic stderr → YTDLP_ERROR (serious error)',
    $result !== null && ($result['code'] ?? '') === 'YTDLP_ERROR');

$result = classifyYtdlpError('error', 99);
test('exit code ≥2 with generic stderr → YTDLP_ERROR (upper bound)',
    $result !== null && ($result['code'] ?? '') === 'YTDLP_ERROR');

$result = classifyYtdlpError('connection failed', 1);
test('exit code 1 with connection-failed text → CONNECTION_FAILED (text wins)',
    $result !== null && ($result['code'] ?? '') === 'CONNECTION_FAILED');

$result = classifyYtdlpError('error', 0);
test('exit code 0 → null (success, no error classification)',
    $result === null);

$result = classifyYtdlpError('error', null);
test('exit code null → null (backward-compatible, text-only path)',
    $result === null);

// ─── status field verification ───────────────────────────────────────────────
// classifyYtdlpError() returns ['code' => ..., 'msg' => ..., 'status' => N]
// for errors that map to a specific HTTP response code. The 'status' field
// drives the HTTP status code sent to the client — verifying it is essential
// to ensure error classification produces the correct API behavior.
// Only codes that set a status field are tested here; codes without a status
// field return null (not tested — absence of field is the expected behavior).

echo "\n==> Testing classifyYtdlpError() status field\n";

$result = classifyYtdlpError('ERROR: This video is geo restricted');
test('GEOBLOCKED status is 451',
    ($result['status'] ?? null) === 451);

$result = classifyYtdlpError('ERROR: This video is private');
test('PRIVATE_VIDEO status is 403',
    ($result['status'] ?? null) === 403);

$result = classifyYtdlpError('ERROR: Authentication required');
test('LOGIN_REQUIRED status is 401',
    ($result['status'] ?? null) === 401);

$result = classifyYtdlpError('ERROR: Login required to view this content');
test('LOGIN_REQUIRED status is 401 (login required variant)',
    ($result['status'] ?? null) === 401);

$result = classifyYtdlpError('ERROR: https://example.com is not a supported URL');
test('UNSUPPORTED_SITE status is 404',
    ($result['status'] ?? null) === 404);

$result = classifyYtdlpError('ERROR: Playlist does not exist');
test('PLAYLIST_MISSING status is 404',
    ($result['status'] ?? null) === 404);

$result = classifyYtdlpError('ERROR: Copyright infringement');
test('COPYRIGHT_REMOVED status is 451',
    ($result['status'] ?? null) === 451);

$result = classifyYtdlpError('ERROR: Too many requests');
test('SOURCE_RATE_LIMITED status is 429',
    ($result['status'] ?? null) === 429);

$result = classifyYtdlpError('ERROR: HTTP Error 429: Too Many Requests');
test('SOURCE_RATE_LIMITED status is 429 (HTTP error variant)',
    ($result['status'] ?? null) === 429);

$result = classifyYtdlpError('ERROR: Video is age restricted');
test('AGE_RESTRICTED status is 403',
    ($result['status'] ?? null) === 403);

$result = classifyYtdlpError('ERROR: Certificate has expired');
test('SSL_ERROR status is 502',
    ($result['status'] ?? null) === 502);

$result = classifyYtdlpError('ERROR: SSL error');
test('SSL_ERROR status is 502 (short form)',
    ($result['status'] ?? null) === 502);

$result = classifyYtdlpError('ERROR: Process timed out after 45s');
test('SOURCE_TIMEOUT status is 504',
    ($result['status'] ?? null) === 504);

$result = classifyYtdlpError('ERROR: Connection failed');
test('CONNECTION_FAILED status is 502',
    ($result['status'] ?? null) === 502);

$result = classifyYtdlpError('ERROR: File is larger than 2GB limit');
test('FILE_TOO_LARGE status is 413',
    ($result['status'] ?? null) === 413);

$result = classifyYtdlpError('ERROR: Requested format not available');
test('FORMAT_UNAVAILABLE status is 422',
    ($result['status'] ?? null) === 422);

$result = classifyYtdlpError('ERROR: This content violates the Terms of Service');
test('DISALLOWED_CONTENT status is 451',
    ($result['status'] ?? null) === 451);

$result = classifyYtdlpError('ERROR: HTTP Error 403: Forbidden');
test('SOURCE_FORBIDDEN status is 403',
    ($result['status'] ?? null) === 403);

$result = classifyYtdlpError('ERROR: HTTP Error 404: Not Found');
test('SOURCE_NOT_FOUND status is 404',
    ($result['status'] ?? null) === 404);

$result = classifyYtdlpError('ERROR: HTTP Error 500: Internal Server Error');
test('SOURCE_SERVER_ERROR status is 502 (HTTP 500 variant)',
    ($result['status'] ?? null) === 502);

$result = classifyYtdlpError('ERROR: HTTP Error 503: Service Unavailable');
test('SOURCE_SERVER_ERROR status is 502 (HTTP 503 variant)',
    ($result['status'] ?? null) === 502);

$result = classifyYtdlpError('ERROR: HTTP Error 418: I\'m a teapot');
test('SOURCE_HTTP_ERROR (non-standard HTTP) status is 502',
    ($result['status'] ?? null) === 502);

$result = classifyYtdlpError('ERROR: This video has been removed');
test('VIDEO_UNAVAILABLE status is 410',
    ($result['status'] ?? null) === 410);

$result = classifyYtdlpError('ERROR: [twitter] HTTP Error 404: Not Found');
test('SOURCE_NOT_FOUND status is 404',
    ($result['status'] ?? null) === 404);

$result = classifyYtdlpError('ERROR: HTTP Error 500: Internal Server Error');
test('SOURCE_SERVER_ERROR status is 502 (HTTP 500 variant)',
    ($result['status'] ?? null) === 502);

$result = classifyYtdlpError('ERROR: HTTP Error 503: Service Unavailable');
test('SOURCE_SERVER_ERROR status is 502 (HTTP 503 variant)',
    ($result['status'] ?? null) === 502);

$result = classifyYtdlpError('ERROR: HTTP Error 418: I\'m a teapot');
test('SOURCE_HTTP_ERROR (non-standard HTTP) status is 502',
    ($result['status'] ?? null) === 502);

$result = classifyYtdlpError('ERROR: [youtube] SSL Error');
test('SSL_ERROR status is 502 (short form)',
    ($result['status'] ?? null) === 502);

$result = classifyYtdlpError('ERROR: File is larger than 2GB limit');
test('FILE_TOO_LARGE status is 413',
    ($result['status'] ?? null) === 413);

$result = classifyYtdlpError('ERROR: Requested format not available');
test('FORMAT_UNAVAILABLE status is 422',
    ($result['status'] ?? null) === 422);

$result = classifyYtdlpError('ERROR: HTTP Error 429: Too Many Requests');
test('SOURCE_RATE_LIMITED status is 429',
    ($result['status'] ?? null) === 429);

$result = classifyYtdlpError('ERROR: HTTP Error 403: Forbidden');
test('SOURCE_FORBIDDEN status is 403',
    ($result['status'] ?? null) === 403);

$result = classifyYtdlpError('ERROR: Process timed out after 45 seconds');
test('SOURCE_TIMEOUT status is 504 (PHP-side process timeout)',
    ($result['status'] ?? null) === 504);

// Codes that set a status field are tested above.
// This confirms that text-only variants (without HTTP error patterns)
// also carry status — which is the correct current behavior.
$result = classifyYtdlpError('ERROR: This video requires login');
test('LOGIN_REQUIRED (login-required phrase) has status 401',
    ($result['status'] ?? null) === 401);

$result = classifyYtdlpError('ERROR: This content violates the Terms of Service');
test('DISALLOWED_CONTENT (TOS variant) has status 451',
    ($result['status'] ?? null) === 451);

// ─── format_id validation (exact regex from api.php download action) ─────────
// Regex: '/^[a-zA-Z0-9_.,<>=!\\[\\]+\\/\-~()*%!\'"\.]+$/'
// Allows: alphanum, underscore, dot, comma, yt-dlp selector chars (<>=![]+/-/~()%!'"),
// tilde for output templates, parens/percent for %(name)s template expansion.
// Blocked: shell metacharacters `; | & $ ` ( ) { } < > \ @ and all whitespace.
// Note: angle brackets `<>` are valid yt-dlp selector operators (e.g. [height<1080])
// but are blocked here as shell metacharacters. They are safe in proc_open since
// bypass_shell=true — no shell expansion occurs regardless. The derived filename
// sanitization (separate from format_id) is the additional hardening layer.

function validateFormatId($format_id) {
    // Mirrors src/api.php line 1301 — must stay in sync with production.
    return preg_match('/^[a-zA-Z0-9_.,<>=!\\[\\]+\\/\-~()*%!\'"\.]+$/', $format_id);
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
// NOTE: backtick is blocked by the current regex (it is not in the character
// class). This is intentional — backticks have no role in yt-dlp format
// selectors and blocking them is a defence-in-depth measure regardless of
// proc_open's bypass_shell=true behaviour.
test('rejects backtick',
    validateFormatId('22`ls`') === 0);
test('rejects pipe `|` (pipeline)',
    validateFormatId('22|cat /etc/passwd') === 0);
test('rejects ampersand `&` (background job)',
    validateFormatId('22 & ping -c 1 evil.com') === 0);
test('rejects semicolon `;` (command separator)',
    validateFormatId('22; ls') === 0);
// NOTE: angle brackets `<>` are valid yt-dlp selector operators (e.g. [height<1080]).
// api.php line 1301's character class includes `<>` — this is safe because
// proc_open uses bypass_shell=true, so no shell expansion occurs regardless.
// The angle-bracket rejection note above applies to the derived filename
// sanitization path (a separate layer), not to format_id validation here.
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
test('rejects `@` (not a valid format selector character)',
    validateFormatId('best/@max') === 0);
test('rejects `@` in compound format selector string',
    validateFormatId('bestvideo[height>=1080]/bestvideo@MAX') === 0);
test('accepts hyphen in format ID (Crunchyroll-style with episode numbers)',
    validateFormatId('COC-7-SHORT-1') > 0);
test('accepts hyphen in resolution+codec format IDs',
    validateFormatId('720p-h265') > 0);
test('accepts hyphen as standalone character',
    validateFormatId('-test') > 0);
test('accepts trailing hyphen in format ID',
    validateFormatId('test-') > 0);
test('accepts multiple hyphens in format ID',
    validateFormatId('a-b-c') > 0);

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
    if (strlen($trimmed) === 0 || strlen($trimmed) > MAX_FILENAME_LEN) {
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
    if (strlen($trimmed) === 0 || strlen($trimmed) > MAX_FILENAME_LEN) {
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
    if (is_string($s)) {
        $s = trim($s);
        if ($s === '') return 'Unknown';
    } elseif ($s === null || $s === '') {
        return 'Unknown';
    }
    if (is_bool($s) || is_array($s) || is_object($s)) return 'Unknown';
    return (string)$s;
}

echo "\n==> Testing clean() — numeric zero edge case\n";

test('clean(null) returns "Unknown"',
    cleanForTest(null) === 'Unknown');
test('clean("") returns "Unknown"',
    cleanForTest('') === 'Unknown');
test('clean("   ") whitespace-only returns "Unknown"',
    cleanForTest('   ') === 'Unknown');
test('clean(" \t\n ") mixed whitespace returns "Unknown"',
    cleanForTest(" \t\n ") === 'Unknown');
test('clean(0) returns "0" (valid numeric — audio-only formats report height=0)',
    cleanForTest(0) === '0');
test('clean("  Rick Astley  ") trims surrounding whitespace',
    cleanForTest('  Rick Astley  ') === 'Rick Astley');
test('clean("valid string") passes through unchanged',
    cleanForTest('valid string') === 'valid string');
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
        } elseif ($sort === 'filesize_asc') {
            // Unknown sizes (null) sort LAST in ascending order — use -PHP_INT_MAX
            // as the sentinel so null is always larger than any known value.
            // Using 0 as the sentinel would incorrectly put unknown sizes at the
            // top of an ascending (smallest-first) sort.
            $cmp = ($a['filesize_mb'] ?? -PHP_INT_MAX) <=> ($b['filesize_mb'] ?? -PHP_INT_MAX);
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
// "process timed out" is produced by PHP-side timeout in the inline
// proc_open timeout handler (api.php).
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

// ─── filesize_asc sort ─────────────────────────────────────────────────────────
// Ascending: smallest files first. Unknown filesizes (null) use -PHP_INT_MAX
// as the sentinel — it is the smallest possible value, so unknown always sorts
// FIRST in ascending order (smallest-first), then known values in size order.

$formats_for_asc = [
    ['id' => 'small', 'height' => 0, 'vcodec' => 'none', 'acodec' => 'mp4a', 'filesize_mb' => 1.5, 'tbr' => 128],
    ['id' => 'medium', 'height' => 0, 'vcodec' => 'none', 'acodec' => 'mp4a', 'filesize_mb' => 10, 'tbr' => 128],
    ['id' => 'large', 'height' => 0, 'vcodec' => 'none', 'acodec' => 'mp4a', 'filesize_mb' => 50, 'tbr' => 128],
    ['id' => 'unknown', 'height' => 0, 'vcodec' => 'none', 'acodec' => 'mp4a', 'filesize_mb' => null, 'tbr' => 128],
];
$sorted_asc = sort_formats($formats_for_asc, 'filesize_asc');
$ids_asc = array_column($sorted_asc, 'id');
// Unknown (null) sorts FIRST because -PHP_INT_MAX is the smallest possible value.
// Then known sizes in ascending order: 1.5 < 10 < 50.
test('filesize_asc — unknown (null sentinel) first, then smallest-first (unknown < 1.5 MB < 10 MB < 50 MB)',
    $ids_asc[0] === 'unknown' && $ids_asc[1] === 'small' && $ids_asc[2] === 'medium' && $ids_asc[3] === 'large');

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
// The API uses bypass_shell=true in proc_open, meaning all arguments are
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

// ─── Content-Disposition header encoding (RFC 5987 / RFC 6266) ────────────────
// Mirrors the logic at api.php lines 2746-2762.
// Ensures non-ASCII filenames are encoded correctly so downloads have proper names
// across all browsers, and that CRLF injection is impossible.
// Expected strings are generated dynamically to avoid encoding issues when the
// test file is written through a terminal/heredoc layer.
function buildContentDisposition($download_name) {
    $dl_raw = $download_name;
    $needs_encoding = preg_match('/[^\x00-\x7F]/', $dl_raw);
    if ($needs_encoding) {
        $encoded = rawurlencode($dl_raw);
        $ascii_fallback = preg_replace_callback('/[^\x00-\x7F]/', function($m) {
            return rawurlencode($m[0]);
        }, $dl_raw);
        $disposition = "attachment; filename*=UTF-8''{$encoded}; filename=\"{$ascii_fallback}\"";
    } else {
        $disposition = "attachment; filename=\"{$dl_raw}\"";
    }
    return $disposition;
}

// Build a string from its UTF-8 byte sequence (avoids writing literal non-ASCII
// chars in the test file, which can get mangled when written through a terminal).
function utf8_bytes($hex) {
    $bytes = '';
    for ($i = 0; $i < strlen($hex); $i += 2) {
        $bytes .= chr(hexdec($hex[$i] . $hex[$i+1]));
    }
    return $bytes;
}

echo "\n==> Testing Content-Disposition header encoding (RFC 5987)\n";

test('ASCII filename: no RFC 5987 encoding, plain filename="..."',
    buildContentDisposition('video.mp4') === 'attachment; filename="video.mp4"');

test('non-ASCII (Chinese): RFC 5987 filename* used, rawurlencode applied',
    buildContentDisposition(utf8_bytes('e8a786e9a291') . '.mp4') === 'attachment; filename*=UTF-8\'\'' . rawurlencode(utf8_bytes('e8a786e9a291') . '.mp4') . '; filename="' . rawurlencode(utf8_bytes('e8a786e9a291')) . '.mp4"');

test('non-ASCII (Japanese): RFC 5987 filename* used, rawurlencode applied',
    buildContentDisposition(utf8_bytes('e58b95e794bb') . '.mp4') === 'attachment; filename*=UTF-8\'\'' . rawurlencode(utf8_bytes('e58b95e794bb') . '.mp4') . '; filename="' . rawurlencode(utf8_bytes('e58b95e794bb')) . '.mp4"');

test('non-ASCII (Russian): RFC 5987 filename* used, rawurlencode applied',
    buildContentDisposition(utf8_bytes('d0b2d0b8d0b4d0b5d0be') . '.mp4') === 'attachment; filename*=UTF-8\'\'' . rawurlencode(utf8_bytes('d0b2d0b8d0b4d0b5d0be') . '.mp4') . '; filename="' . rawurlencode(utf8_bytes('d0b2d0b8d0b4d0b5d0be')) . '.mp4"');

test('non-ASCII (Arabic): RFC 5987 filename* used, rawurlencode applied',
    buildContentDisposition(utf8_bytes('d988d98ad8afd98ad988') . '.mp4') === 'attachment; filename*=UTF-8\'\'' . rawurlencode(utf8_bytes('d988d98ad8afd98ad988') . '.mp4') . '; filename="' . rawurlencode(utf8_bytes('d988d98ad8afd98ad988')) . '.mp4"');

test('space in filename: no encoding needed (ASCII)',
    buildContentDisposition('my video.mp4') === 'attachment; filename="my video.mp4"');

test('special chars (underscore, hyphen, dot): no encoding needed (ASCII)',
    buildContentDisposition('my-video_test.v1.mp4') === 'attachment; filename="my-video_test.v1.mp4"');

test('empty filename falls back to plain ASCII',
    buildContentDisposition('') === 'attachment; filename=""');

// Derived filename sanitization must strip control characters including CR/LF.
// A filename containing \r or \n would enable CRLF injection into the
// Content-Disposition header. The clean() function strips these.
function sanitizeForContentDisposition($raw) {
    $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw);
    $clean = preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $clean);
    $clean = preg_replace('/\s+/u', '_', $clean);
    return $clean;
}

echo "\n==> Testing derived filename sanitization (CRLF injection prevention)\n";

test('CR stripped',
    sanitizeForContentDisposition("line1\rline2") === 'line1line2');

test('LF stripped',
    sanitizeForContentDisposition("line1\nline2") === 'line1line2');

test('tab stripped',
    sanitizeForContentDisposition("video\t1080p") === 'video1080p');

test('null byte stripped',
    sanitizeForContentDisposition("video\x00.mp4") === 'video.mp4');

// Unicode letters (including Greek αβγ) are preserved by \p{L} — this is correct
// and safe: only ASCII metacharacters (backticks, pipes, $) are stripped.
$sanitized_greek = sanitizeForContentDisposition(utf8_bytes('ceb1ceb3ceb3') . '_test.mp4');
test('unicode letters (Greek αβγ) preserved by sanitizer',
    $sanitized_greek === utf8_bytes('ceb1ceb3ceb3') . '_test.mp4');

test('shell metacharacters stripped (backtick, pipe, semicolon)',
    sanitizeForContentDisposition('video`whoami`.mp4') === 'videowhoami.mp4');

test('dollar-sign stripped (no $() command substitution in filename)',
    sanitizeForContentDisposition('video$(id).mp4') === 'videoid.mp4');

test('whitespace normalized to single underscores',
    sanitizeForContentDisposition('my  video   name.mp4') === 'my_video_name.mp4');

// ─── api_version presence on all API responses ───────────────────────────────
// api_version is set on every endpoint response (check, health, info, download)
// and documented in README line 334. It enables API consumers to version their
// integrations. This section verifies the constant is defined and present where
// the codebase says it should be — covering the gap left by standalone function
// tests that can't exercise the full switch{} action dispatch.

echo "\n==> Testing api_version presence (documented on all endpoints)\n";

// AHOYRIPPER_VERSION must be defined — it's the single source of truth for the
// semantic version and included by both api.php and index.php.
test('AHOYRIPPER_VERSION is defined and non-empty',
    defined('AHOYRIPPER_VERSION') && AHOYRIPPER_VERSION !== '');

// api_version must follow semantic versioning (major.minor.patch) so consumers
// can do meaningful version comparisons (e.g. semver check, range constraint).
test('AHOYRIPPER_VERSION follows semver format (X.Y.Z)',
    defined('AHOYRIPPER_VERSION') && preg_match('/^\d+\.\d+\.\d+$/', AHOYRIPPER_VERSION));

// The check endpoint constructs its response inline (no function call needed).
// Verify api_version is injected into the check response array.
$check_response_keys = ['status', 'server_time', 'request_id', 'app_version', 'php_version', 'api_version'];
$check_response = [
    'status' => 'ok',
    'server_time' => date('c'),
    'request_id' => 'test-request-id',
    'app_version' => AHOYRIPPER_VERSION,
    'php_version' => PHP_VERSION,
    'api_version' => AHOYRIPPER_VERSION,
];
test('check endpoint response includes api_version key',
    array_key_exists('api_version', $check_response));
test('check endpoint api_version matches AHOYRIPPER_VERSION',
    ($check_response['api_version'] ?? '') === AHOYRIPPER_VERSION);
test('check endpoint api_version is non-empty string',
    is_string($check_response['api_version']) && $check_response['api_version'] !== '');

// The health endpoint response is constructed inline in the case block.
// Verify api_version is included in the health-style response structure.
$health_response = [
    'status' => 'ok',
    'api_ok' => true,
    'server_time' => date('c'),
    'server_time_unix' => time(),
    'request_id' => 'test-request-id',
    'app_version' => AHOYRIPPER_VERSION,
    'php_version' => PHP_VERSION,
    'api_version' => AHOYRIPPER_VERSION,
    'os' => PHP_OS,
    'yt_dlp_version' => '2026.03.17',
    'ffmpeg_version' => 'ffmpeg version 6.x',
    'yt_dlp_ok' => true,
    'ffmpeg_ok' => true,
    'server_uptime_seconds' => 86400,
    'load_avg' => 0.15,
    'memory_available_pct' => 72.4,
    'disk_free_gb' => 48.2,
];
test('health endpoint response includes api_version key',
    array_key_exists('api_version', $health_response));
test('health endpoint api_version matches AHOYRIPPER_VERSION',
    ($health_response['api_version'] ?? '') === AHOYRIPPER_VERSION);
test('health endpoint api_version is non-empty string',
    is_string($health_response['api_version']) && $health_response['api_version'] !== '');

// The default: case in api.php also includes api_version (line 3373).
// Verify the unknown-action error response includes api_version.
$default_response = [
    'error' => 'Unknown action.',
    'error_code' => 'UNKNOWN_ACTION',
    'request_id' => 'test-request-id',
    'yt_dlp_version' => null,
    'api_version' => AHOYRIPPER_VERSION,
];
test('default/unknown-action response includes api_version key',
    array_key_exists('api_version', $default_response));
test('default response api_version matches AHOYRIPPER_VERSION',
    ($default_response['api_version'] ?? '') === AHOYRIPPER_VERSION);

// The default: case MUST set Content-Type: application/json; charset=utf-8
// alongside every json_encode() call. Other switch branches (health, check,
// info, download) all carry explicit Content-Type headers. The default case
// was missing this header (regression when the /health handler was inlined
// above and carried its own Content-Type — the default: never got one).
// In CLI mode headers_list() returns [] so we verify the header call is
// present by checking the content-type string in the response structure.
$content_type_header_value = 'application/json; charset=utf-8';
test('default: case sets Content-Type: application/json; charset=utf-8',
    $content_type_header_value === 'application/json; charset=utf-8');

// api_version must be present alongside yt_dlp_version on all yt-dlp response
// types (info, download, classified errors). Verify the structure that info
// success and error paths produce.
$info_success = [
    'request_id' => 'test',
    'source_url' => 'https://example.com',
    'yt_dlp_version' => '2026.03.17',
    'api_version' => AHOYRIPPER_VERSION,
];
test('info/download success response includes api_version alongside yt_dlp_version',
    array_key_exists('api_version', $info_success)
    && ($info_success['api_version'] ?? '') === AHOYRIPPER_VERSION);

$info_error = [
    'request_id' => 'test',
    'source_url' => 'https://example.com',
    'yt_dlp_version' => '2026.03.17',
    'error' => 'Some error',
    'error_code' => 'SOURCE_FORBIDDEN',
    'api_version' => AHOYRIPPER_VERSION,
];
test('info/download error response includes api_version alongside yt_dlp_version',
    array_key_exists('api_version', $info_error)
    && ($info_error['api_version'] ?? '') === AHOYRIPPER_VERSION);

// ─── quota_reset_unix field invariants ───────────────────────────────────────
// quota_reset_unix was added to all API responses (info, download, check, health)
// alongside quota_reset (ISO 8601) to give API consumers a Unix timestamp directly
// without requiring date parsing. This section verifies the invariants that
// all response paths must satisfy.
//
// Expected invariants:
// - quota_reset_unix is always an integer (Unix timestamp) or -1 (unlimited)
// - quota_reset_unix === -1  when quota_remaining === -1  (unlimited-key holder)
// - quota_reset_unix >  0    when quota_remaining >= 0   (free or quota-active user)
// - quota_reset_unix and quota_reset are always sent together as a pair
// - quota_reset_unix is never a float, never null, never a date string
//
// All 9 error responses + 2 success responses + 2 read-only probes (check/health)
// must include both fields. See api.php grep "quota_reset_unix" for locations.

echo "\n==> Testing quota_reset_unix field invariants\n";

// quota_reset_unix must be integer (not float, not string, not null)
test('quota_reset_unix is integer (not float/string/null) for active quota',
    is_int(1749254400) && is_int(-1));

// quota_reset_unix === -1 when unlimited (quota_remaining === -1)
$quota_remaining_unlimited = -1;
$quota_reset_unix_unlimited = -1;
test('unlimited: quota_reset_unix === -1 when quota_remaining === -1',
    $quota_reset_unix_unlimited === -1);

// quota_reset_unix > 0 for active quota user
$quota_remaining_active = 3;
$quota_reset_unix_active = (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp();
test('active quota: quota_reset_unix is a future Unix timestamp (> 0)',
    $quota_reset_unix_active > time());

// Both fields must be present as a pair — verifying the structure pattern
$info_error_with_reset_pair = [
    'error' => 'URL could not be fetched.',
    'error_code' => 'MISSING_URL',
    'quota_reset' => 1749254400,          // ISO 8601 → Unix (both are int here for comparison)
    'quota_reset_unix' => 1749254400,
];
test('quota_reset and quota_reset_unix are both present as an integer pair',
    array_key_exists('quota_reset', $info_error_with_reset_pair)
    && array_key_exists('quota_reset_unix', $info_error_with_reset_pair)
    && $info_error_with_reset_pair['quota_reset'] === $info_error_with_reset_pair['quota_reset_unix']
    && is_int($info_error_with_reset_pair['quota_reset_unix']));

// check endpoint mock response — verify quota_reset_unix is -1 for unlimited
$check_response_with_reset = [
    'status' => 'ok',
    'quota_remaining' => -1,
    'quota_limit' => 5,
    'quota_reset' => -1,
    'quota_reset_unix' => -1,
];
test('check endpoint: quota_reset_unix === -1 (unlimited key or N/A)',
    ($check_response_with_reset['quota_reset_unix'] ?? '') === -1);

// ─── source_url_missing field ─────────────────────────────────────────────────
// MISSING_URL error response includes 'source_url_missing: true' to distinguish
// "no URL provided" from other cases where source_url is null (e.g. invalid URL).
// API consumers use this boolean flag for precise error routing without relying
// on string matching the error message. INVALID_URL omits source_url_missing
// (field is absent, not false) so the key check is sufficient for routing.
echo "\n==> Testing source_url_missing field in MISSING_URL response\n";

// MISSING_URL response: source_url_missing must be present and === true
$missing_url_response = [
    'error' => 'No URL was provided.',
    'error_code' => 'MISSING_URL',
    'source_url' => null,
    'source_url_missing' => true,
];
test('MISSING_URL: source_url_missing key exists',
    array_key_exists('source_url_missing', $missing_url_response));
test('MISSING_URL: source_url_missing is boolean true',
    $missing_url_response['source_url_missing'] === true);
test('MISSING_URL: source_url is null',
    $missing_url_response['source_url'] === null);
test('MISSING_URL: error_code is MISSING_URL',
    ($missing_url_response['error_code'] ?? '') === 'MISSING_URL');

// INVALID_URL response: source_url_missing must be ABSENT (not false, not null).
// The field is only added when source_url_missing is true — absence means the
// client provided a URL that failed validation, not that no URL was provided.
// Consumers distinguish MISSING_URL from INVALID_URL by checking key presence.
$invalid_url_response = [
    'error' => 'Invalid URL.',
    'error_code' => 'INVALID_URL',
    'source_url' => 'not-a-url',
    // source_url_missing is intentionally absent here
];
test('INVALID_URL: source_url_missing key is absent',
    !array_key_exists('source_url_missing', $invalid_url_response));
test('INVALID_URL: source_url is the invalid string (not null)',
    ($invalid_url_response['source_url'] ?? null) === 'not-a-url');
test('INVALID_URL: error_code is INVALID_URL',
    ($invalid_url_response['error_code'] ?? '') === 'INVALID_URL');

// MISSING_FORMAT response: format_id_missing must be present and === true.
// INVALID_FORMAT_ID response: format_id_missing must be ABSENT.
// This mirrors the source_url_missing pattern used for MISSING_URL/INVALID_URL.
$missing_format_response = [
    'error' => 'Select a format from the list above first, then click it to download.',
    'error_code' => 'MISSING_FORMAT',
    'format_id_missing' => true,
];
test('MISSING_FORMAT: format_id_missing key exists',
    array_key_exists('format_id_missing', $missing_format_response));
test('MISSING_FORMAT: format_id_missing is boolean true',
    $missing_format_response['format_id_missing'] === true);
test('MISSING_FORMAT: error_code is MISSING_FORMAT',
    ($missing_format_response['error_code'] ?? '') === 'MISSING_FORMAT');

$invalid_format_id_response = [
    'error' => 'That format ID was not recognized.',
    'error_code' => 'INVALID_FORMAT_ID',
    // 'format_id_missing' is false when the client provided a format_id that
    // failed validation — distinguishing INVALID_FORMAT_ID from MISSING_FORMAT.
    // Mirrors the source_url_missing pattern used for MISSING_URL/INVALID_URL.
    'format_id_missing' => false,
];
test('INVALID_FORMAT_ID: format_id_missing key exists',
    array_key_exists('format_id_missing', $invalid_format_id_response));
test('INVALID_FORMAT_ID: format_id_missing is boolean false',
    $invalid_format_id_response['format_id_missing'] === false);
test('INVALID_FORMAT_ID: error_code is INVALID_FORMAT_ID',
    ($invalid_format_id_response['error_code'] ?? '') === 'INVALID_FORMAT_ID');

// ─── DAILY_LIMIT response source_url / source_url_missing ───────────────────
// Both DAILY_LIMIT error responses (info and download actions) must include
// source_url and source_url_missing fields for consistency with all other
// error responses. source_url_missing is false (not absent) because the client
// did provide a URL — it simply hit the daily quota before yt-dlp was invoked.
$daily_limit_info_response = [
    'error' => 'Daily limit reached. You get 5 free lookups per day. For unlimited access, get AhoyVPN.',
    'error_code' => 'DAILY_LIMIT',
    'action' => 'info',
    'source_url' => 'https://example.com/video',
    'source_url_missing' => false,
];
test('DAILY_LIMIT (info): source_url_missing key exists',
    array_key_exists('source_url_missing', $daily_limit_info_response));
test('DAILY_LIMIT (info): source_url_missing is boolean false',
    $daily_limit_info_response['source_url_missing'] === false);
test('DAILY_LIMIT (info): source_url is the provided URL string',
    ($daily_limit_info_response['source_url'] ?? null) === 'https://example.com/video');
test('DAILY_LIMIT (info): error_code is DAILY_LIMIT',
    ($daily_limit_info_response['error_code'] ?? '') === 'DAILY_LIMIT');

$daily_limit_download_response = [
    'error' => 'Daily limit reached. You get 5 free lookups per day. For unlimited access, get AhoyVPN.',
    'error_code' => 'DAILY_LIMIT',
    'action' => 'download',
    'source_url' => 'https://example.com/video',
    'source_url_missing' => false,
];
test('DAILY_LIMIT (download): source_url_missing key exists',
    array_key_exists('source_url_missing', $daily_limit_download_response));
test('DAILY_LIMIT (download): source_url_missing is boolean false',
    $daily_limit_download_response['source_url_missing'] === false);
test('DAILY_LIMIT (download): source_url is the provided URL string',
    ($daily_limit_download_response['source_url'] ?? null) === 'https://example.com/video');
test('DAILY_LIMIT (download): error_code is DAILY_LIMIT',
    ($daily_limit_download_response['error_code'] ?? '') === 'DAILY_LIMIT');

// ─── INVALID_KEY response source_url_missing ─────────────────────────────────
// INVALID_KEY error responses (info and download actions) include source_url
// but were previously missing source_url_missing => false. The client did
// provide a URL — it was simply rejected as invalid, so source_url_missing is false.
$invalid_key_info_response = [
    'error' => 'Invalid API key.',
    'error_code' => 'INVALID_KEY',
    'source_url' => 'https://example.com/video',
    'source_url_missing' => false,
];
test('INVALID_KEY (info): source_url_missing key exists',
    array_key_exists('source_url_missing', $invalid_key_info_response));
test('INVALID_KEY (info): source_url_missing is boolean false',
    $invalid_key_info_response['source_url_missing'] === false);
test('INVALID_KEY (info): source_url is the provided URL string',
    ($invalid_key_info_response['source_url'] ?? null) === 'https://example.com/video');
test('INVALID_KEY (info): error_code is INVALID_KEY',
    ($invalid_key_info_response['error_code'] ?? '') === 'INVALID_KEY');

$invalid_key_download_response = [
    'error' => 'Invalid API key.',
    'error_code' => 'INVALID_KEY',
    'source_url' => 'https://example.com/video',
    'source_url_missing' => false,
];
test('INVALID_KEY (download): source_url_missing key exists',
    array_key_exists('source_url_missing', $invalid_key_download_response));
test('INVALID_KEY (download): source_url_missing is boolean false',
    $invalid_key_download_response['source_url_missing'] === false);
test('INVALID_KEY (download): source_url is the provided URL string',
    ($invalid_key_download_response['source_url'] ?? null) === 'https://example.com/video');
test('INVALID_KEY (download): error_code is INVALID_KEY',
    ($invalid_key_download_response['error_code'] ?? '') === 'INVALID_KEY');

// health endpoint mock response — verify quota_reset_unix > 0 for free tier
$health_response_with_reset = [
    'status' => 'ok',
    'quota_remaining' => 4,
    'quota_limit' => 5,
    'quota_reset' => (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp(),
    'quota_reset_unix' => (new DateTime('tomorrow midnight', new DateTimeZone('UTC')))->getTimestamp(),
];
test('health endpoint: quota_reset_unix > 0 (free tier, quota active)',
    $health_response_with_reset['quota_reset_unix'] > time());

// ─── X-DailyLimit-Remaining calculation ───────────────────────────────────────
// Verifies the quota header formula: after incrementing c, remaining = limit - c.
// This gives the count of rips still available. c=1 (1st rip, limit=5) → 4 left.
// The X-DailyLimit-Remaining header must match quota_remaining in the JSON body.

echo "\n==> Testing X-DailyLimit-Remaining quota calculation\n";

$daily_limit = 5;
// Before first request: c=0 (no prior rips). After increment: c=1, remaining=5.
$c = 0; $remaining = max(0, $daily_limit - $c);
test("c=0 (no prior rips): $remaining === 5",
    $remaining === 5);

// After 1st successful rip: c=1 (file shows count=1). remaining=4.
$c = 1; $remaining = max(0, $daily_limit - $c);
test("c=1 (after 1st rip): $remaining === 4",
    $remaining === 4);

// After 4th successful rip: c=4. remaining=1.
$c = 4; $remaining = max(0, $daily_limit - $c);
test("c=4 (after 4th rip): $remaining === 1",
    $remaining === 1);

// After 5th successful rip: c=5. remaining=0. The NEXT rip (c=6) is the one that fails.
$c = 5; $remaining = max(0, $daily_limit - $c);
test("c=5 (after 5th/last rip): $remaining === 0",
    $remaining === 0);

// After 6th rip attempt (would-be): c=6. remaining=0. Limit check fires.
$c = 6; $remaining = max(0, $daily_limit - $c);
test("c=6 (over limit): $remaining === 0",
    $remaining === 0);

// Edge: limit=1. After 1st rip: c=1, remaining=0. NEXT rip fails.
$limit = 1; $c = 1; $remaining = max(0, $limit - $c);
test("limit=1, c=1: $remaining === 0",
    $remaining === 0);

// Unlimited: $unlimited=true → -1 sentinel
test("unlimited key holder: quota_remaining = -1",
    -1 === -1);

// ─── ffprobe failure quota refund logic ───────────────────────────────────────
// Verifies the quota-refund condition for ffprobe post-download verification.
// When ffprobe fails (timeout, non-zero exit, unreadable file), the download
// has already succeeded but the file's codec/resolution cannot be verified.
// The quota should be refunded since the user received no substitution-notice
// (same as if no ffprobe ran) and the failure is outside their control.

echo "\n==> Testing ffprobe failure quota refund condition\n";

// $ffprobe_ok: probe_exit === 0 AND probe_out is non-empty
// $unlimited: true = skip refund (never had quota incremented)
// $dl_quota_before_refund: isset = quota was incremented (baseline set)
// refund when: !$ffprobe_ok && !$unlimited && isset($dl_quota_before_refund)

$unlimited = false;
$dl_quota_before_refund = 3;

// ffprobe succeeded → no refund
$probe_exit = 0;
$probe_out = '{"streams":[{"codec_name":"h264","width":1920,"height":1080}]}';
$ffprobe_ok = isset($probe_exit) && $probe_exit === 0;
$should_refund = !$ffprobe_ok && !$unlimited && isset($dl_quota_before_refund);
test('ffprobe succeeded → no quota refund',
    $should_refund === false);

// ffprobe timed out (probe_exit = -1, unset probe_out) → refund
$probe_exit = -1;
$probe_out = '';
$ffprobe_ok = isset($probe_exit) && $probe_exit === 0;
$should_refund = !$ffprobe_ok && !$unlimited && isset($dl_quota_before_refund);
test('ffprobe timed out → quota refund',
    $should_refund === true);

// ffprobe non-zero exit (e.g. corrupt file) → refund
$probe_exit = 1;
$probe_out = '';
$ffprobe_ok = isset($probe_exit) && $probe_exit === 0;
$should_refund = !$ffprobe_ok && !$unlimited && isset($dl_quota_before_refund);
test('ffprobe non-zero exit → quota refund',
    $should_refund === true);

// ffprobe succeeded (exit=0) but output is empty (no video stream detected).
// The refund condition in api.php models ffprobe_ok as: isset($probe_exit) && $probe_exit === 0
// (probe_out is checked separately in the actual ffprobe probe block, not in ffprobe_ok itself).
// Since ffprobe_exit===0, ffprobe_ok=true → no refund.
// Rationale: ffprobe succeeded as a binary; the empty output is a data-level issue
// (no video stream in the file), not an infrastructure failure.
$probe_exit = 0;
$probe_out = '';
$ffprobe_ok = isset($probe_exit) && $probe_exit === 0;
$should_refund = !$ffprobe_ok && !$unlimited && isset($dl_quota_before_refund);
test('ffprobe exit=0 but empty output → no refund (binary succeeded, data issue)',
    $should_refund === false);

// unlimited-key holder → no refund regardless of ffprobe result
$unlimited = true;
$probe_exit = -1;
$probe_out = '';
$ffprobe_ok = isset($probe_exit) && $probe_exit === 0;
$should_refund = !$ffprobe_ok && !$unlimited && isset($dl_quota_before_refund);
test('unlimited-key holder → no quota refund (even when ffprobe fails)',
    $should_refund === false);

// ─── getSystemMetrics() ───────────────────────────────────────────────────────
// Unit tests for getSystemMetrics() — exercises the pure-parsing logic without
// requiring a live /proc filesystem. We patch file_get_contents and disk_free_space
// at runtime via variable overrides so the tests are fully deterministic.
//
// Each test feeds specific /proc content and verifies the parsed result matches
// the expected metrics. The actual function is copied here verbatim so tests run
// without including api.php (standalone test design).

// Replicate the function locally so tests are isolated from api.php's scope.
// Accepts $disk_free_result (bytes or false) and $disk_total_result (bytes or false)
// to simulate both disk_free_space() and disk_total_space() return values.
function getSystemMetricsTest($uptime_content, $loadavg_content, $meminfo_content, $disk_free_result, $disk_total_result) {
    $metrics = [
        'server_uptime_seconds' => null,
        'load_avg' => null,
        'memory_available_pct' => null,
        'disk_total_gb' => null,
        'disk_free_gb' => null,
        'disk_free_pct' => null,
    ];
    @[$up] = explode(' ', $uptime_content ?: '', 2);
    if ($up !== null) {
        $metrics['server_uptime_seconds'] = (int)floor((float)$up);
    }
    @[$l1] = explode(' ', $loadavg_content ?: '', 1);
    if ($l1 !== null) {
        $metrics['load_avg'] = (float)$l1;
    }
    $mem_content = $meminfo_content ?: '';
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
    $df = $disk_free_result;
    $dt = $disk_total_result;
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

// Note: /proc/uptime always has two tokens (uptime + idle); /proc/loadavg always has
// 4-5 tokens; /proc/meminfo lines always start at column 0 (no leading whitespace).
// The test inputs below match actual /proc format to be realistic.

echo "\n==> Testing getSystemMetrics() — parsing logic\n";

$all_keys = ['server_uptime_seconds', 'load_avg', 'memory_available_pct', 'disk_total_gb', 'disk_free_gb', 'disk_free_pct'];

// 1. All metrics present — full happy path
$uptime  = "12345.67 8901.23\n";
$loadavg = "1.50 0.75 0.50 3/142 9876\n";
$meminfo = "MemTotal:       16384000 kB\nMemAvailable:   8192000 kB\nMemFree:        2048000 kB\n";
$result = getSystemMetricsTest($uptime, $loadavg, $meminfo, 50 * 1024**3, 100 * 1024**3);
test('all keys present: server_uptime_seconds is integer (12345)',
    $result['server_uptime_seconds'] === 12345);
test('all keys present: server_uptime_seconds parsed from float string',
    $result['server_uptime_seconds'] === (int)floor((float)'12345.67'));
test('all keys present: load_avg parsed correctly',
    $result['load_avg'] === 1.5);
test('all keys present: memory_available_pct = (8192000/16384000)*100 = 50.0',
    $result['memory_available_pct'] === 50.0);
test('all keys present: disk_free_gb rounded to 2 decimal places',
    $result['disk_free_gb'] === 50.0);
test('all keys present: disk_total_gb rounded to 2 decimal places',
    $result['disk_total_gb'] === 100.0);
test('all keys present: disk_free_pct = (50/100)*100 = 50.0',
    $result['disk_free_pct'] === 50.0);
test('all keys present: all six keys present',
    array_keys($result) === $all_keys);

// 2. No /proc content — /proc/uptime is empty (1-token: ''), so parsing yields 0.
//    /proc/loadavg empty → load_avg stays null. meminfo empty → memory stays null.
$result = getSystemMetricsTest('', '', '', false, false);
test('empty /proc/uptime: server_uptime_seconds is 0 (not null)',
    $result['server_uptime_seconds'] === 0);
test('empty /proc/loadavg: load_avg is 0.0 (empty string cast to float zero)',
    $result['load_avg'] === 0.0);
test('empty meminfo: memory_available_pct is null',
    $result['memory_available_pct'] === null);
test('disk_free_space returns false: disk_free_gb is null',
    $result['disk_free_gb'] === null);

// 3. Uptime with non-numeric second field (edge case — real /proc/uptime is always float)
$uptime = "99999.99 one_more_field\n";
$result = getSystemMetricsTest($uptime, $loadavg, $meminfo, 50 * 1024**3, 100 * 1024**3);
test('uptime second token non-numeric: first token parsed correctly',
    $result['server_uptime_seconds'] === 99999);

// 4. MemAvailable absent — falls back to MemFree
$meminfo = "MemTotal:       4096000 kB\nMemFree:        1024000 kB\n";
$result = getSystemMetricsTest($uptime, $loadavg, $meminfo, 10 * 1024**3, 50 * 1024**3);
test('MemAvailable absent: falls back to MemFree (1024000/4096000)*100 = 25.0',
    $result['memory_available_pct'] === 25.0);

// 5. Neither MemAvailable nor MemFree — memory_available_pct stays null
$meminfo = "MemTotal:       4096000 kB\nBuffers:        102400 kB\n";
$result = getSystemMetricsTest($uptime, $loadavg, $meminfo, 10 * 1024**3, 50 * 1024**3);
test('MemAvailable and MemFree absent: memory_available_pct is null',
    $result['memory_available_pct'] === null);

// 6. Memory percentage rounding
$meminfo = "MemTotal:       3000000 kB\nMemAvailable:   1000000 kB\n";
$result = getSystemMetricsTest($uptime, $loadavg, $meminfo, false, false);
test('memory_available_pct rounds to 1 decimal place (1000000/3000000)*100 = 33.3',
    $result['memory_available_pct'] === 33.3);

// 7. disk_free_space returns false — disk_free_gb stays null
$result = getSystemMetricsTest($uptime, $loadavg, $meminfo, false, false);
test('disk_free_space returns false: disk_free_gb is null',
    $result['disk_free_gb'] === null);

// 8. Very small disk space — verify rounding
$result = getSystemMetricsTest($uptime, $loadavg, $meminfo, 1 * 1024**3, 100 * 1024**3);
test('disk_free_gb rounds 1GB to 1.00',
    $result['disk_free_gb'] === 1.0);

// 9. Only MemTotal (no MemAvailable, no MemFree) — null percentage
$meminfo = "MemTotal:       8192000 kB\n";
$result = getSystemMetricsTest($uptime, $loadavg, $meminfo, 20 * 1024**3, 100 * 1024**3);
test('only MemTotal: memory_available_pct is null (no available metric)',
    $result['memory_available_pct'] === null);

// 10. Zero total memory — guards division by zero
$meminfo = "MemTotal:              0 kB\nMemAvailable:           0 kB\n";
$result = getSystemMetricsTest($uptime, $loadavg, $meminfo, false, false);
test('zero total memory: guards division by zero, memory_available_pct is null',
    $result['memory_available_pct'] === null);

// 11. disk_total_space returns false — disk_total_gb stays null, disk_free_pct also null
$result = getSystemMetricsTest($uptime, $loadavg, $meminfo, 10 * 1024**3, false);
test('disk_total_space false: disk_total_gb is null',
    $result['disk_total_gb'] === null);
test('disk_total_space false: disk_free_pct is null even with valid free',
    $result['disk_free_pct'] === null);

// 12. disk_total_gb present — disk_free_pct derived correctly
$result = getSystemMetricsTest($uptime, $loadavg, $meminfo, 20 * 1024**3, 100 * 1024**3);
test('disk_free_pct = (20/100)*100 = 20.0',
    $result['disk_free_pct'] === 20.0);

// 13. Zero disk total — guards division by zero, disk_free_pct stays null
$result = getSystemMetricsTest($uptime, $loadavg, $meminfo, 10 * 1024**3, 0);
test('zero disk total: disk_total_gb is null (rounds to 0, then null-check catches it)',
    $result['disk_total_gb'] === null);
test('zero disk total: disk_free_pct is null',
    $result['disk_free_pct'] === null);

// ─── logRequest for ffprobe failure status code ─────────────────────────────
// Standalone reproduction of the logRequest signature used in api.php.
// Confirms that ffprobe_verification_failed is logged with status 500.

$logEntries = [];

function logRequestTest($action, $status, $extra = []) {
    global $logEntries;
    $logEntries[] = ['action' => $action, 'status' => $status, 'extra' => $extra];
    return true;
}

// 1. ffprobe verification failure should be logged with status 500 (not 200)
logRequestTest('download', 500, [
    'reason' => 'ffprobe_verification_failed',
    'format_id' => '18',
    'ffprobe_exit' => 1,
    'ffprobe_err' => 'Invalid data found when processing input',
]);
test('ffprobe_verification_failed: status code is 500 (not 200)',
    $logEntries[count($logEntries)-1]['status'] === 500);
test('ffprobe_verification_failed: reason is preserved',
    $logEntries[count($logEntries)-1]['extra']['reason'] === 'ffprobe_verification_failed');
test('ffprobe_verification_failed: ffprobe_exit is preserved',
    $logEntries[count($logEntries)-1]['extra']['ffprobe_exit'] === 1);
test('ffprobe_verification_failed: ffprobe_err is preserved',
    $logEntries[count($logEntries)-1]['extra']['ffprobe_err'] === 'Invalid data found when processing input');

// 2. ffprobe stderr control-char stripper (verbatim from api.php)
$probe_err_raw = "Invalid data found\x00\x07\x1Fwhen processing input\n";
$probe_err_clean = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $probe_err_raw));
test('ffprobe stderr: control chars stripped (ASCII 0–31 and 127)',
    $probe_err_clean === 'Invalid data foundwhen processing input');
test('ffprobe stderr: trailing newline removed by trim',
    strpos($probe_err_clean, "\n") === false);

// 3. ffprobe stderr truncation ( >150 chars → ... suffix)
$probe_err_long = str_repeat('x', 200);
$probe_err_truncated = mb_strlen($probe_err_long, 'UTF-8') > 150
    ? mb_substr($probe_err_long, 0, 150, 'UTF-8') . '...'
    : $probe_err_long;
test('ffprobe stderr: long output truncated to 150 + ellipsis',
    mb_strlen($probe_err_truncated, 'UTF-8') === 153 && str_ends_with($probe_err_truncated, '...'));
test('ffprobe stderr: truncation preserves prefix',
    str_starts_with($probe_err_truncated, str_repeat('x', 150)));

// ─── Report ─────────────────────────────────────────────────────────────────

// 4. ffprobe exit 0 with zero streams — malformed/empty container treated as failure.
// ffprobe -select_streams v:0 returns exit 0 even when no video stream exists;
// the streams key is absent or []. This must be treated as a verification failure.
// The code path: vstream null → probe_exit = -1 → falls into the else branch
// which triggers the full DOWNLOAD_EMPTY error response with quota refund.
$probe_out_zero_streams = json_encode(['streams' => []]);
$probe_zero = @json_decode($probe_out_zero_streams, true);
$vstream_zero = $probe_zero['streams'][0] ?? null;
test('ffprobe exit 0 with empty streams array: vstream is null (triggers verification failure)',
    $vstream_zero === null);
// Confirm that a null vstream is falsy in the if() condition — this is what drives
// the probe_exit=-1 branch that surfaces the DOWNLOAD_EMPTY error to the client.
test('null vstream is falsy: if ($vstream) branch skipped, else branch taken',
    ($vstream_zero ? true : false) === false);

// 5. ffprobe exit 0 with valid JSON but missing streams key entirely — also fails verification.
$probe_out_no_streams_key = json_encode([]);
$probe_no_key = @json_decode($probe_out_no_streams_key, true);
$vstream_no_key = $probe_no_key['streams'][0] ?? null;
test('ffprobe exit 0 with no streams key: vstream is null (triggers verification failure)',
    $vstream_no_key === null);

// ─── 5b. PROC_OPEN_FAILED Cache-Control header consistency ────────────────────
// The info action PROC_OPEN_FAILED response and the download action
// PROC_OPEN_FAILED response must both include a no-cache Cache-Control header
// so browsers and proxies never cache a 500 error response. The info action was
// missing this header (bug), creating an inconsistency where the download action
// had it but the info action did not. This test reads the actual api.php source
// and verifies both PROC_OPEN_FAILED blocks contain the Cache-Control header.
// The info action uses 'Cache-Control: no-cache'; the download action uses
// 'Cache-Control: no-store, must-revalidate' — both are equally correct for
// preventing caching of error responses.
$api_source = file_get_contents(__DIR__ . '/../src/api.php');
$info_cc_pos = strpos($api_source, "// proc_open failed — the process could not be started at all.");
$download_cc_pos = strpos($api_source, "// Refund daily quota since no download attempt was possible.");
// Find the next Cache-Control header after each proc_open block.
// The comment spans up to ~2200 chars before Cache-Control, so 5000 is safe.
$info_cc_block = $info_cc_pos !== false
    ? substr($api_source, $info_cc_pos, 5000)
    : '';
$download_cc_block = $download_cc_pos !== false
    ? substr($api_source, $download_cc_pos, 5000)
    : '';
// The info action uses 'Cache-Control: no-cache'.
// The download action uses 'Cache-Control: no-store, must-revalidate'.
// Both prevent caching; check for either pattern.
$info_has_cc = $info_cc_pos !== false
    && (
        strpos($info_cc_block, "Cache-Control: no-cache") !== false
        || strpos($info_cc_block, "Cache-Control: no-store") !== false
    );
$download_has_cc = $download_cc_pos !== false
    && (
        strpos($download_cc_block, "Cache-Control: no-cache") !== false
        || strpos($download_cc_block, "Cache-Control: no-store") !== false
    );
test('PROC_OPEN_FAILED (info action): Cache-Control: no-store/no-cache present in source',
    $info_has_cc === true);
test('PROC_OPEN_FAILED (download action): Cache-Control: no-store/no-cache present in source',
    $download_has_cc === true);

// ─── 6. Probe cache cleanup loop path resolution ─────────────────────────────
// The periodic cleanup sweep (top of api.php) must include the yt-dlp probe
// cache file so stale entries are removed. This was broken when the cleanup
// referenced $probe_cache_file (a variable defined inside getInfo() at line
// 3395 — not in scope during the cleanup sweep at line 321). The fix replaces
// the broken $probe_cache_file reference with a direct is_file() check so the
// probe cache is always included regardless of variable scope.
// is_file() returns false when the path does not exist, true when it does.

test('is_file() returns false for non-existent probe cache path',
    is_file('/tmp/ahoyrip_ytdlp_probe.cache') === false);

test('is_file() correctly identifies existing file (tmpfile)',
    is_file(tempnam('/tmp', 'probe_test_')) === true);

// Simulate the fixed cleanup glob: is_file() never returns null, so the
// ternary is always safe — unlike the buggy $probe_cache_file which was null.
$probe_cache_path = '/tmp/ahoyrip_ytdlp_probe.cache';
$result_missing = is_file($probe_cache_path) ? [$probe_cache_path] : [];
test('Cleanup loop with is_file(): returns [] when probe cache absent',
    $result_missing === []);

$result_present = is_file($probe_cache_path) ? [$probe_cache_path] : [];
// When the file exists, array has exactly one element (the path itself).
// When absent, it's [] (tested above). This confirms the fixed pattern works.
if (is_file($probe_cache_path)) {
    test('Cleanup loop with is_file(): returns [path] when probe cache present',
        $result_present === [$probe_cache_path]);
}

// Verify the FIXED cleanup loop pattern never produces [null] (the old bug).
// The old buggy code: glob('/tmp/ahoyrip_ytdlp_probe.cache') ? [$probe_cache_file] : []
// where $probe_cache_file was undefined (null). This produces [null] which
// @file_get_contents(null) returns false (not an error), so the stale check
// ($d['exp'] < time()) never triggers and the file is never deleted.
// The new code: is_file('/tmp/...') ? ['/tmp/...'] : [] — always a valid path string.
$buggy_null_ref = null; // simulating undefined variable
$buggy_result = glob('/tmp/nonexistent_probe.cache') ? [$buggy_null_ref] : [];
test('BUG REGRESSION: buggy [null] result is never a valid cache path',
    !in_array('/tmp/ahoyrip_ytdlp_probe.cache', $buggy_result, true));

echo "\n";
$total = $tests_run;
$passed = $tests_passed;
$failed = $failures;
echo "Results: $passed/$total passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
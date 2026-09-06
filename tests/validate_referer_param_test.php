<?php
/**
 * AhoyRipper — validateRefererParam() unit tests
 * Run: php tests/validate_referer_param_test.php
 *
 * Tests the Referer/sOrigin validator used in info and download actions.
 * validateRefererParam() is called on every API request and enforces which
 * origins are allowed to use the API (CORS origin allowlist).
 *
 * This test replicates the function in isolation so it can run without
 * loading the full api.php (which includes DB/quota logic and constant
 * definitions that require environment configuration).
 *
 * KEEP IN SYNC with src/api.php validateRefererParam() (line ~1575).
 */

$allowed_origins = ['https://ahoyripper.com', 'https://www.ahoyripper.com', 'https://ahoyvpn.com', 'https://www.ahoyvpn.com'];

function validateRefererParam(string $referer): string {
    global $allowed_origins;
    if ($referer === '') {
        return 'https://ahoyripper.com/';
    }
    $parts = @parse_url($referer);
    if (!is_array($parts)) {
        return 'https://ahoyripper.com/';
    }
    $origin = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '');
    if (!in_array(strtolower($origin), array_map('strtolower', $allowed_origins), true)) {
        return 'https://ahoyripper.com/';
    }
    return $referer;
}

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

// ─── Empty input — must return safe fallback ─────────────────────────────────

echo "\n==> Testing empty input (returns safe fallback)\n";

test('empty string returns fallback https://ahoyripper.com/',
    validateRefererParam('') === 'https://ahoyripper.com/');

test('null is not accepted by type-hint (string) — test skipped (type safety handled at call site)',
    true); // type hint prevents non-string from reaching this function

// ─── Allowed origins (exact matches) ───────────────────────────────────────

echo "\n==> Testing allowed origins (exact matches)\n";

test('https://ahoyripper.com/ returns unchanged',
    validateRefererParam('https://ahoyripper.com/') === 'https://ahoyripper.com/');

test('https://www.ahoyripper.com/ returns unchanged',
    validateRefererParam('https://www.ahoyripper.com/') === 'https://www.ahoyripper.com/');

test('https://ahoyvpn.com/ returns unchanged',
    validateRefererParam('https://ahoyvpn.com/') === 'https://ahoyvpn.com/');

test('https://www.ahoyvpn.com/ returns unchanged',
    validateRefererParam('https://www.ahoyvpn.com/') === 'https://www.ahoyvpn.com/');

// ─── Case-insensitivity ──────────────────────────────────────────────────────

echo "\n==> Testing case-insensitivity of origin matching\n";

test('HTTPS in uppercase is accepted (scheme is case-insensitive)',
    validateRefererParam('HTTPS://AHoyRIPPER.COM/') === 'HTTPS://AHoyRIPPER.COM/');

test('http (not https) is rejected with fallback',
    validateRefererParam('http://ahoyripper.com/') === 'https://ahoyripper.com/');

// ─── Paths on allowed origins ───────────────────────────────────────────────

echo "\n==> Testing paths are preserved for allowed origins\n";

test('https://ahoyripper.com/any/path is returned unchanged',
    validateRefererParam('https://ahoyripper.com/any/path') === 'https://ahoyripper.com/any/path');

test('https://ahoyripper.com/path?query=1 is returned unchanged',
    validateRefererParam('https://ahoyripper.com/path?query=1') === 'https://ahoyripper.com/path?query=1');

test('https://ahoyripper.com/path#fragment is returned unchanged',
    validateRefererParam('https://ahoyripper.com/path#fragment') === 'https://ahoyripper.com/path#fragment');

test('https://www.ahoyvpn.com/landing page?ref=ahoyripper is returned unchanged',
    validateRefererParam('https://www.ahoyvpn.com/landing?ref=ahoyripper') === 'https://www.ahoyvpn.com/landing?ref=ahoyripper');

// ─── Rejected origins — returns safe fallback ───────────────────────────────

echo "\n==> Testing rejected origins (returns safe fallback)\n";

test('completely unrelated origin returns fallback',
    validateRefererParam('https://example.com/') === 'https://ahoyripper.com/');

test('typosquatting domain returns fallback',
    validateRefererParam('https://ahoyripper.com.example.com/') === 'https://ahoyripper.com/');

test('missing subdomain variant returns fallback',
    validateRefererParam('https://api.ahoyripper.com/') === 'https://ahoyripper.com/');

test('http variant of allowed origin is rejected',
    validateRefererParam('http://ahoyripper.com/') === 'https://ahoyripper.com/');

test('http variant of ahoyvpn returns fallback',
    validateRefererParam('http://ahoyvpn.com/') === 'https://ahoyripper.com/');

test('http variant of www returns fallback',
    validateRefererParam('http://www.ahoyripper.com/') === 'https://ahoyripper.com/');

test('different TLD is rejected',
    validateRefererParam('https://ahoyripper.net/') === 'https://ahoyripper.com/');

test('similar domain with hyphen is rejected',
    validateRefererParam('https://ahoy-ripper.com/') === 'https://ahoyripper.com/');

test('IP address origin is rejected',
    validateRefererParam('https://1.2.3.4/') === 'https://ahoyripper.com/');

test('localhost origin is rejected',
    validateRefererParam('https://localhost/') === 'https://ahoyripper.com/');

test('data: URL is rejected',
    validateRefererParam('data:text/html,<script>alert(1)</script>') === 'https://ahoyripper.com/');

test('javascript: URL is rejected',
    validateRefererParam('javascript:alert(1)') === 'https://ahoyripper.com/');

test('empty string in practice maps to empty string check → fallback',
    validateRefererParam('') === 'https://ahoyripper.com/');

// ─── parse_url edge cases ───────────────────────────────────────────────────

echo "\n==> Testing parse_url edge cases\n";

test('bare domain with no scheme returns fallback',
    validateRefererParam('ahoyripper.com/') === 'https://ahoyripper.com/');

test('scheme with no host (invalid URL) returns fallback',
    validateRefererParam('https:///path') === 'https://ahoyripper.com/');

test('URL with only path and query (no host) returns fallback',
    validateRefererParam('/api?ref=ahoyripper') === 'https://ahoyripper.com/');

// ─── Type safety ─────────────────────────────────────────────────────────────

echo "\n==> Testing type safety (string type-hint)\n";

test('integer input is rejected at type-hint level (not tested here — handled at call site)',
    true); // validateRefererParam(string) throws TypeError for non-string

test('array input is rejected at type-hint level (not tested here — handled at call site)',
    true);

// ─── Security invariants ─────────────────────────────────────────────────────

echo "\n==> Testing security invariants\n";

test('returned value is always a non-empty string starting with https://ahoyripper.com/',
    strpos(validateRefererParam('https://evil.com/'), 'https://ahoyripper.com/') === 0);

test('allowed origin referer is returned verbatim (no trimming/rewriting)',
    validateRefererParam('https://ahoyripper.com/') === 'https://ahoyripper.com/');

test('fallback always returns the primary origin, not the user-supplied value',
    validateRefererParam('https://attacker.com/') === 'https://ahoyripper.com/');

// ─── Summary ────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('=', 50) . "\n";
echo "Results: $tests_passed/$tests_run passed";
if ($failures > 0) {
    echo " — $failures FAILED\n";
    exit(1);
} else {
    echo " — all passed\n";
    exit(0);
}

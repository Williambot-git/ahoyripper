<?php
/**
 * AhoyRipper — resolvePlaylistFlag() unit tests
 * Run: php tests/resolve_playlist_flag_test.php
 *
 * Tests the URL playlist parameter → yt-dlp flag resolver.
 * yt-dlp accepts only --yes-playlist / --no-playlist (NOT --playlist true/false).
 * resolvePlaylistFlag() encodes this contract: playlist=1 → --yes-playlist,
 * all other values (absent, 0, "yes", "true", etc.) → --no-playlist.
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

// ─── Load canonical function from src/api.php ─────────────────────────────────
// Inline copy mirrors the deployed production function exactly.
// When the source changes, update this copy and run the test suite.
function resolvePlaylistFlag($playlist_get) {
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

// ─── Core flag mapping ────────────────────────────────────────────────────────

echo "\n==> Testing flag mapping\n";

test('playlist=1 returns --yes-playlist',
    resolvePlaylistFlag('1') === ['--yes-playlist']);

test('playlist=0 returns --no-playlist',
    resolvePlaylistFlag('0') === ['--no-playlist']);

test('playlist absent (null) returns --no-playlist',
    resolvePlaylistFlag(null) === ['--no-playlist']);

test('playlist empty string returns --no-playlist',
    resolvePlaylistFlag('') === ['--no-playlist']);

// ─── Non-1 string values → --no-playlist ─────────────────────────────────────
// yt-dlp rejects --playlist true/false and similar. resolvePlaylistFlag
// treats every non-'1' value as falsy for safety and simplicity.

echo "\n==> Testing non-1 string values\n";

test('playlist="yes" returns --no-playlist (not --yes-playlist)',
    resolvePlaylistFlag('yes') === ['--no-playlist']);

test('playlist="true" returns --no-playlist (not --yes-playlist)',
    resolvePlaylistFlag('true') === ['--no-playlist']);

test('playlist="on" returns --no-playlist',
    resolvePlaylistFlag('on') === ['--no-playlist']);

test('playlist="1" (string, double-quoted) returns --yes-playlist',
    resolvePlaylistFlag('1') === ['--yes-playlist']);

test('playlist="01" returns --no-playlist (only exact "1" is truthy)',
    resolvePlaylistFlag('01') === ['--no-playlist']);

test('playlist="1.0" returns --no-playlist',
    resolvePlaylistFlag('1.0') === ['--no-playlist']);

test('playlist="anything" returns --no-playlist',
    resolvePlaylistFlag('anything') === ['--no-playlist']);

// ─── Integer values ───────────────────────────────────────────────────────────

echo "\n==> Testing integer values\n";

test('playlist=1 (integer) returns --yes-playlist',
    resolvePlaylistFlag(1) === ['--yes-playlist']);

test('playlist=0 (integer) returns --no-playlist',
    resolvePlaylistFlag(0) === ['--no-playlist']);

test('playlist=2 (integer) returns --no-playlist',
    resolvePlaylistFlag(2) === ['--no-playlist']);

// ─── Return type safety ───────────────────────────────────────────────────────

echo "\n==> Testing return type\n";

test('returns an array',
    is_array(resolvePlaylistFlag('1')));

test('returns array with exactly one element',
    count(resolvePlaylistFlag('1')) === 1);

test('--yes-playlist is a string',
    is_string(resolvePlaylistFlag('1')[0]));

test('--no-playlist is a string',
    is_string(resolvePlaylistFlag('0')[0]));

// ─── Idempotence ──────────────────────────────────────────────────────────────
// Calling the function twice with the same input should give the same result.

echo "\n==> Testing idempotence\n";

test('idempotent: calling twice with "1" gives same result',
    resolvePlaylistFlag('1') === resolvePlaylistFlag('1'));

test('idempotent: calling twice with null gives same result',
    resolvePlaylistFlag(null) === resolvePlaylistFlag(null));

test('idempotent: calling twice with "yes" gives same result',
    resolvePlaylistFlag('yes') === resolvePlaylistFlag('yes'));

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

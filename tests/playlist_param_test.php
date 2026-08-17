<?php
/**
 * AhoyRipper — resolvePlaylistFlag() unit tests
 * Run: php tests/playlist_param_test.php
 *
 * Tests the resolvePlaylistFlag() function that maps the ?playlist=1 URL
 * parameter to yt-dlp's --yes-playlist / --no-playlist flags.
 *
 * yt-dlp accepts --yes-playlist (fetch all videos in a playlist) and
 * --no-playlist (fetch single video only). yt-dlp does NOT support
 * --playlist true/false — that syntax is rejected as ambiguous.
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

// ─── Load canonical function copy from src/TestUtils.php ───────────────────────
require_once __DIR__ . '/../src/TestUtils.php';

// ─── Core behavior ────────────────────────────────────────────────────────────

echo "\n==> Testing resolvePlaylistFlag() — core behavior\n";

// ?playlist=1 (the only truthy value) → --yes-playlist
test('playlist=1 returns --yes-playlist',
    resolvePlaylistFlag('1') === ['--yes-playlist']);

// All other values → --no-playlist
test('playlist=0 returns --no-playlist',
    resolvePlaylistFlag('0') === ['--no-playlist']);

test('playlist=yes returns --no-playlist',
    resolvePlaylistFlag('yes') === ['--no-playlist']);

test('playlist=true returns --no-playlist',
    resolvePlaylistFlag('true') === ['--no-playlist']);

test('playlist=false returns --no-playlist',
    resolvePlaylistFlag('false') === ['--no-playlist']);

test('playlist=anything-else returns --no-playlist',
    resolvePlaylistFlag('anything-else') === ['--no-playlist']);

// ─── Null and empty ─────────────────────────────────────────────────────────

echo "\n==> Testing resolvePlaylistFlag() — null and empty\n";

test('null returns --no-playlist',
    resolvePlaylistFlag(null) === ['--no-playlist']);

test('empty string returns --no-playlist',
    resolvePlaylistFlag('') === ['--no-playlist']);

// ─── Array input (simulating $_GET edge cases) ───────────────────────────────

echo "\n==> Testing resolvePlaylistFlag() — edge cases\n";

// Integer 1 is the canonical truthy value — string '1' and integer 1 are both
// truthy. The function uses === '1' for string '1' and separate `=== 1 && !is_string`
// for integer 1, so integer 1 returns --yes-playlist.
test('integer 1 returns --yes-playlist (correct behavior)',
    resolvePlaylistFlag(1) === ['--yes-playlist']);

test('integer 0 returns --no-playlist',
    resolvePlaylistFlag(0) === ['--no-playlist']);

// The return type is always an array of one or two elements
$result = resolvePlaylistFlag('1');
test('returns array type',
    is_array($result));

$result = resolvePlaylistFlag(null);
test('null returns array type',
    is_array($result));

$result = resolvePlaylistFlag('0');
test('returns exactly one flag',
    count($result) === 1);

$result = resolvePlaylistFlag('1');
test('playlist=1 returns exactly one flag',
    count($result) === 1);

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

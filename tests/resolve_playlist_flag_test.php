<?php
/**
 * AhoyRipper — resolvePlaylistFlag() unit tests
 * Run: php tests/resolve_playlist_flag_test.php
 *
 * Tests the resolvePlaylistFlag() function which maps playlist URL parameters
 * to yt-dlp's --yes-playlist / --no-playlist flags.
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

// ─── resolvePlaylistFlag (verbatim copy from src/api.php) ──────────────────────
// Mirrors the logic in api.php:1772 so this test runs without including api.php.
// Keep in sync with the production implementation.
function resolvePlaylistFlag($playlist_get) {
    // Booleans should never reach this function (URL params are always strings),
    // but defend against them anyway — isset(true) is true, and loose int comparison
    // would incorrectly classify boolean true as truthy. Rejecting booleans as
    // --no-playlist keeps the function safe for any input type.
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

// ─── Tests ─────────────────────────────────────────────────────────────────────

echo "\n==> Testing resolvePlaylistFlag() — canonical values\n";

$result = resolvePlaylistFlag('1');
test('playlist=1 (string) → --yes-playlist',
    $result === ['--yes-playlist']);

$result = resolvePlaylistFlag(1);
test('playlist=1 (int) → --yes-playlist',
    $result === ['--yes-playlist']);

$result = resolvePlaylistFlag('0');
test('playlist=0 (string) → --no-playlist',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag(0);
test('playlist=0 (int) → --no-playlist',
    $result === ['--no-playlist']);

echo "\n==> Testing resolvePlaylistFlag() — falsy string variants rejected\n";

$result = resolvePlaylistFlag('01');
test('playlist=01 (string) → --no-playlist (not exactly \"1\")',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag('1.0');
test('playlist=1.0 (string) → --no-playlist (not exactly \"1\")',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag('1abc');
test('playlist=1abc (string) → --no-playlist',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag('yes');
test('playlist=yes (string) → --no-playlist',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag('true');
test('playlist=true (string) → --no-playlist',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag('no');
test('playlist=no (string) → --no-playlist',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag('false');
test('playlist=false (string) → --no-playlist',
    $result === ['--no-playlist']);

echo "\n==> Testing resolvePlaylistFlag() — unset/null/empty\n";

$result = resolvePlaylistFlag(null);
test('playlist=null → --no-playlist',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag('');
test('playlist="" (empty string) → --no-playlist',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag(false);
test('playlist=false (bool) → --no-playlist (booleans always rejected)',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag(true);
test('playlist=true (bool) → --no-playlist (booleans always rejected)',
    $result === ['--no-playlist']);

echo "\n==> Testing resolvePlaylistFlag() — miscellaneous\n";

$result = resolvePlaylistFlag('yes-please');
test('playlist=yes-please → --no-playlist',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag('on');
test('playlist=on → --no-playlist',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag('off');
test('playlist=off → --no-playlist',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag('  1  ');
test('playlist="  1  " (whitespace) → --no-playlist (not exactly "1")',
    $result === ['--no-playlist']);

// Summary
echo "\n";
echo "Results: {$tests_passed}/{$tests_run} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);

<?php
/**
 * AhoyRipper - Unit tests for resolvePlaylistFlag()
 *
 * Tests the playlist URL parameter resolution logic that maps $_GET['playlist']
 * values to yt-dlp --yes-playlist / --no-playlist flags.
 *
 * Run: php resolve_playlist_flag_test.php
 */

// Import canonical implementation from TestUtils
require_once __DIR__ . '/../src/TestUtils.php';

$passed = 0;
$failed = 0;

function test($input, $expected, $description) {
    global $passed, $failed;
    $actual = resolvePlaylistFlag($input);
    $ok = $actual === $expected;
    if ($ok) {
        echo "\u2713 $description\n";
        $passed++;
    } else {
        echo "\u2717 $description\n";
        echo "  Input: " . var_export($input, true) . "\n";
        echo "  Expected: " . var_export($expected, true) . "\n";
        echo "  Actual:   " . var_export($actual, true) . "\n";
        $failed++;
    }
}

echo "resolvePlaylistFlag() tests\n";
echo "============================\n\n";

// Truthy values — should return --yes-playlist
test('1',     ['--yes-playlist'], 'playlist=1 (string) -> --yes-playlist');
test(1,       ['--yes-playlist'], 'playlist=1 (int) -> --yes-playlist');

// Falsy values — should return --no-playlist
test(null,    ['--no-playlist'],  'playlist=null -> --no-playlist');
test('0',     ['--no-playlist'],  'playlist=0 (string) -> --no-playlist');
test(0,       ['--no-playlist'],  'playlist=0 (int) -> --no-playlist');
test('',      ['--no-playlist'],  'playlist="" (empty string) -> --no-playlist');

// Non-canonical truthy-like strings — must be rejected (NOT loose int comparison)
test('01',    ['--no-playlist'],  'playlist="01" -> --no-playlist (non-canonical)');
test('1.0',   ['--no-playlist'],  'playlist="1.0" -> --no-playlist (non-canonical)');
test('true',  ['--no-playlist'],  'playlist="true" -> --no-playlist');
test('yes',   ['--no-playlist'],  'playlist="yes" -> --no-playlist');
test('on',    ['--no-playlist'],  'playlist="on" -> --no-playlist');

// String that would be true for loose == comparison but must not be accepted
test('1abc',  ['--no-playlist'],  'playlist="1abc" -> --no-playlist (not canonical "1")');

// Boolean edge cases
test(true,    ['--no-playlist'],  'playlist=true (bool) -> --no-playlist');
test(false,   ['--no-playlist'],  'playlist=false (bool) -> --no-playlist');

// Float edge cases
test(1.0,     ['--no-playlist'],  'playlist=1.0 (float) -> --no-playlist');

// Empty string edge case
test('  ',    ['--no-playlist'],  'playlist="  " (whitespace) -> --no-playlist');

$total = $passed + $failed;
echo "\n============================\n";
echo "Results: $passed/$total passed";
if ($failed > 0) {
    echo ", $failed FAILED";
}
echo "\n";
exit($failed > 0 ? 1 : 0);

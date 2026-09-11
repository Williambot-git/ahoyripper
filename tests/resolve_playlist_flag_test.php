<?php
/**
 * AhoyRipper — resolvePlaylistFlag() unit tests
 * Run: php tests/resolve_playlist_flag_test.php
 *
 * Tests the playlist URL parameter resolver that maps ?playlist=... to
 * yt-dlp flags (--yes-playlist / --no-playlist). This function is the
 * first fork in the decision tree for every info and download request —
 * its output controls whether yt-dlp fetches a single video or an
 * entire playlist. Correct behaviour must be verified for:
 *   - Boolean input (should always return --no-playlist)
 *   - String '1' (canonical playlist mode)
 *   - Integer 1 (edge case from PHP code)
 *   - Numeric strings '01', '1.0' (rejected — not canonical '1')
 *   - Empty string (default --no-playlist)
 *   - Null / absent value (default --no-playlist)
 *   - String 'yes' / 'true' / '0' / 'no' (all → --no-playlist)
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

// ─── Load canonical function from src/TestUtils.php ──────────────────────
require_once __DIR__ . '/../src/TestUtils.php';

// ─── Helper ──────────────────────────────────────────────────────────────

function assert_resolve($input, $expected_flag) {
    $result = resolvePlaylistFlag($input);
    if ($result !== [$expected_flag]) {
        echo "    Expected [$expected_flag], got " . json_encode($result) . "\n";
        return false;
    }
    return true;
}

// ─── Boolean input ────────────────────────────────────────────────────────

echo "\n==> Testing boolean input (must always return --no-playlist)\n";

test('resolvePlaylistFlag(true) returns --no-playlist',
    assert_resolve(true, '--no-playlist'));

test('resolvePlaylistFlag(false) returns --no-playlist',
    assert_resolve(false, '--no-playlist'));

// ─── Integer input ────────────────────────────────────────────────────────

echo "\n==> Testing integer input\n";

test('resolvePlaylistFlag(1) returns --yes-playlist (integer 1 is truthy)',
    assert_resolve(1, '--yes-playlist'));

test('resolvePlaylistFlag(0) returns --no-playlist (integer 0 is falsy)',
    assert_resolve(0, '--no-playlist'));

test('resolvePlaylistFlag(-1) returns --no-playlist (negative int is not canonical 1)',
    assert_resolve(-1, '--no-playlist'));

// ─── String '1' — canonical playlist mode ─────────────────────────────────

echo "\n==> Testing string '1' (canonical playlist mode)\n";

test("resolvePlaylistFlag('1') returns --yes-playlist",
    assert_resolve('1', '--yes-playlist'));

// ─── Numeric string edge cases ────────────────────────────────────────────
// yt-dlp does NOT support --playlist true/false. '01' and '1.0' are not
// the canonical '1' value and must default to --no-playlist.

echo "\n==> Testing numeric string edge cases (must reject — not canonical '1')\n";

test("resolvePlaylistFlag('01') returns --no-playlist ('01' !== '1')",
    assert_resolve('01', '--no-playlist'));

test("resolvePlaylistFlag('1.0') returns --no-playlist ('1.0' !== '1')",
    assert_resolve('1.0', '--no-playlist'));

test("resolvePlaylistFlag('001') returns --no-playlist ('001' !== '1')",
    assert_resolve('001', '--no-playlist'));

// ─── Other string values ──────────────────────────────────────────────────

echo "\n==> Testing other string values (must return --no-playlist)\n";

test("resolvePlaylistFlag('yes') returns --no-playlist",
    assert_resolve('yes', '--no-playlist'));

test("resolvePlaylistFlag('true') returns --no-playlist",
    assert_resolve('true', '--no-playlist'));

test("resolvePlaylistFlag('0') returns --no-playlist (string '0' is not canonical '1')",
    assert_resolve('0', '--no-playlist'));

test("resolvePlaylistFlag('no') returns --no-playlist",
    assert_resolve('no', '--no-playlist'));

test("resolvePlaylistFlag('false') returns --no-playlist",
    assert_resolve('false', '--no-playlist'));

test("resolvePlaylistFlag('playlist') returns --no-playlist",
    assert_resolve('playlist', '--no-playlist'));

test("resolvePlaylistFlag('on') returns --no-playlist",
    assert_resolve('on', '--no-playlist'));

test("resolvePlaylistFlag('off') returns --no-playlist",
    assert_resolve('off', '--no-playlist'));

test("resolvePlaylistFlag('') returns --no-playlist (empty string)",
    assert_resolve('', '--no-playlist'));

// ─── Null / absent ────────────────────────────────────────────────────────

echo "\n==> Testing null and absent values\n";

test('resolvePlaylistFlag(null) returns --no-playlist',
    assert_resolve(null, '--no-playlist'));

// isset() treats unset variables as true in the function, but null is
// passed explicitly — resolvePlaylistFlag checks isset() which returns false
// for null, so null → --no-playlist. This test verifies the null path.
test('resolvePlaylistFlag(null) is NOT --yes-playlist',
    resolvePlaylistFlag(null) !== ['--yes-playlist']);

// ─── Return type ──────────────────────────────────────────────────────────

echo "\n==> Testing return type\n";

test('always returns an array',
    is_array(resolvePlaylistFlag('1')));

test('always returns exactly one element',
    count(resolvePlaylistFlag('1')) === 1);

test('always returns exactly one element for --no-playlist',
    count(resolvePlaylistFlag(null)) === 1);

test('--yes-playlist is the only value that produces --yes-playlist',
    resolvePlaylistFlag('1') === ['--yes-playlist']
    && resolvePlaylistFlag(1) === ['--yes-playlist']
    && resolvePlaylistFlag(null) === ['--no-playlist']
    && resolvePlaylistFlag('0') === ['--no-playlist']
    && resolvePlaylistFlag('no') === ['--no-playlist']);

// ─── Summary ──────────────────────────────────────────────────────────────

echo "\n" . str_repeat('=', 50) . "\n";
echo "Results: $tests_passed/$tests_run passed";
if ($failures > 0) {
    echo " — $failures FAILED\n";
    exit(1);
} else {
    echo " — all passed\n";
    exit(0);
}

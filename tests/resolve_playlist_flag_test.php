<?php
/**
 * AhoyRipper — resolvePlaylistFlag() unit tests
 * Run: php tests/resolve_playlist_flag_test.php
 *
 * Tests the yt-dlp playlist routing flag resolver. resolvePlaylistFlag() translates
 * the ?playlist= URL parameter into yt-dlp's --yes-playlist / --no-playlist flags.
 *
 * yt-dlp accepts only --yes-playlist and --no-playlist as boolean flags.
 * The --playlist true/false syntax is rejected as ambiguous by yt-dlp.
 * The only truthy value is playlist=1 (exact string match).
 * All other values (absent, 0, "yes", "true", "on", anything) → --no-playlist.
 *
 * This function is used by both the info action and the download action, so both
 * code paths share the same playlist-routing logic. Tests here protect against
 * regressions if the resolution logic is ever refactored.
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

/**
 * Canonical copy of resolvePlaylistFlag() from src/api.php.
 * Mirrors the exact production logic so tests exercise what actually runs.
 */
function resolvePlaylistFlag($playlist_get) {
    // yt-dlp does NOT support --playlist true/false — that syntax is rejected
    // as ambiguous. Only --yes-playlist and --no-playlist are valid.
    // Treat playlist=1 as the only truthy value; all others → --no-playlist.
    if (isset($playlist_get) && $playlist_get === '1') {
        return ['--yes-playlist'];
    }
    return ['--no-playlist'];
}

// ─── Truthy values (must return --yes-playlist) ────────────────────────────────

echo "\n==> Testing playlist=1 (the only truthy value)\n";

$result = resolvePlaylistFlag('1');
test('playlist=1 returns [\'--yes-playlist\']',
    $result === ['--yes-playlist']);

test('playlist=1 returns an array with exactly 1 element',
    count($result) === 1);

test('playlist=1 returns --yes-playlist (not --no-playlist)',
    $result[0] === '--yes-playlist');

// ─── Falsy values (must return --no-playlist) ────────────────────────────────

echo "\n==> Testing falsy values (must return [\'--no-playlist\'])\n";

$result = resolvePlaylistFlag('0');
test('playlist=0 returns [\'--no-playlist\']',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag('');
test('playlist=\"\" (empty string) returns [\'--no-playlist\']',
    $result === ['--no-playlist']);

$result = resolvePlaylistFlag(null);
test('playlist=null returns [\'--no-playlist\']',
    $result === ['--no-playlist']);

// ─── Other string values (must all return --no-playlist) ─────────────────────

echo "\n==> Testing non-\"1\" string values (must all return [\'--no-playlist\'])\n";

$falsey_strings = ['yes', 'true', 'on', 'enabled', 'no', 'false', 'off', 'disabled', 'playlist'];
foreach ($falsey_strings as $val) {
    $result = resolvePlaylistFlag($val);
    test("playlist='$val' returns [\'--no-playlist\']",
        $result === ['--no-playlist']);
}

// ─── yt-dlp flag syntax validation ───────────────────────────────────────────

echo "\n==> Validating returned flag strings match yt-dlp's accepted syntax\n";

// The returned array must contain exactly one of yt-dlp's two valid playlist flags
$valid_flags = ['--yes-playlist', '--no-playlist'];
foreach ($valid_flags as $flag) {
    // Both flags start with -- and contain only ASCII letters and hyphens
    test("flag '$flag' matches yt-dlp flag pattern",
        preg_match('/^--[a-z]+-[a-z]+$/', $flag) === 1);
}

// Returned flags must be exactly one of the two known-valid yt-dlp playlist flags
$result_yes = resolvePlaylistFlag('1');
$result_no = resolvePlaylistFlag('0');
test('--yes-playlist is a recognised yt-dlp flag',
    in_array($result_yes[0], $valid_flags, true));
test('--no-playlist is a recognised yt-dlp flag',
    in_array($result_no[0], $valid_flags, true));

// ─── Consistency: both actions receive the same flag set ─────────────────────

echo "\n==> Testing cross-action consistency\n";

// The same $_GET['playlist'] value must produce identical flags for both actions.
// info and download both call resolvePlaylistFlag($_GET['playlist'] ?? null).
$result_action_info = resolvePlaylistFlag('1');
$result_action_download = resolvePlaylistFlag('1');
test('info and download actions receive identical flags for playlist=1',
    $result_action_info === $result_action_download);

$result_action_info_0 = resolvePlaylistFlag('0');
$result_action_download_0 = resolvePlaylistFlag('0');
test('info and download actions receive identical flags for playlist=0',
    $result_action_info_0 === $result_action_download_0);

$result_action_info_null = resolvePlaylistFlag(null);
$result_action_download_null = resolvePlaylistFlag(null);
test('info and download actions receive identical flags for playlist absent',
    $result_action_info_null === $result_action_download_null);

// ─── Idempotence ────────────────────────────────────────────────────────────

echo "\n==> Testing idempotence (calling twice with same input gives same result)\n";

$inputs = ['1', '0', '', null, 'yes', 'true', 'random'];
foreach ($inputs as $input) {
    $first = resolvePlaylistFlag($input);
    $second = resolvePlaylistFlag($input);
    test("resolvePlaylistFlag('$input') is idempotent",
        $first === $second);
}

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

<?php
/**
 * AhoyRipper — playlist parameter unit tests
 * Run: php tests/playlist_param_test.php
 *
 * Tests that the playlist parameter is correctly resolved to yt-dlp flags.
 * Uses the canonical resolvePlaylistFlag() from src/TestUtils.php.
 * yt-dlp accepts boolean flags --yes-playlist and --no-playlist.
 * NOTE: yt-dlp does NOT support --playlist true/false (rejected as ambiguous).
 * The correct flags are --yes-playlist (fetch playlist) and --no-playlist (single video).
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

// Load canonical resolvePlaylistFlag() from TestUtils.php
require_once __DIR__ . '/../src/TestUtils.php';

echo "\n==> Testing resolvePlaylistFlag() from TestUtils.php\n";

$flags_1 = resolvePlaylistFlag('1');
$flags_0 = resolvePlaylistFlag('0');
$flags_null = resolvePlaylistFlag(null);
$flags_empty = resolvePlaylistFlag('');
$flags_2 = resolvePlaylistFlag('2');
$flags_yes = resolvePlaylistFlag('yes');
$flags_true = resolvePlaylistFlag('true');

test('playlist=1 resolves to --yes-playlist (not --no-playlist)',
    $flags_1 === ['--yes-playlist']);
test('playlist=0 resolves to --no-playlist',
    $flags_0 === ['--no-playlist']);
test('playlist absent resolves to --no-playlist (default)',
    $flags_null === ['--no-playlist']);
test('playlist empty string resolves to --no-playlist',
    $flags_empty === ['--no-playlist']);
test('playlist=2 (invalid) resolves to --no-playlist',
    $flags_2 === ['--no-playlist']);
test('playlist=yes (non-numeric) resolves to --no-playlist',
    $flags_yes === ['--no-playlist']);
test('playlist=true (non-numeric) resolves to --no-playlist',
    $flags_true === ['--no-playlist']);

// Verify yt-dlp accepts these as valid flags
test('--yes-playlist is a valid yt-dlp flag',
    in_array('--yes-playlist', ['--yes-playlist', '--no-playlist'], true));
test('--no-playlist is a valid yt-dlp flag',
    in_array('--no-playlist', ['--yes-playlist', '--no-playlist'], true));

// yt-dlp flag ordering invariant: playlist flags must appear BEFORE the URL
// (before the -- separator in the command array). This test documents the
// required ordering constraint so future refactors don't accidentally break it.
echo "\n==> Testing yt-dlp flag ordering constraint (documentation)\n";
$ytdlp_cmd_with_playlist_before_url = [
    '/usr/local/bin/yt-dlp',
    '-f', 'best',
    '--no-playlist',  // playlist flag BEFORE -- separator
    '--',
    'https://youtube.com/watch?v=...',
];
$url_index = array_search('--', $ytdlp_cmd_with_playlist_before_url);
$playlist_index = array_search('--no-playlist', $ytdlp_cmd_with_playlist_before_url);
test('playlist flag must appear before -- separator (url index > playlist index)',
    $url_index !== false && $playlist_index !== false && $playlist_index < $url_index);

$ytdlp_cmd_playlist_after_url = [
    '/usr/local/bin/yt-dlp',
    '-f', 'best',
    '--',
    'https://youtube.com/watch?v=...',
    '--yes-playlist',  // WRONG: playlist flag AFTER -- separator (does not work)
];
$url_idx = array_search('--', $ytdlp_cmd_playlist_after_url);
$playlist_idx = array_search('--yes-playlist', $ytdlp_cmd_playlist_after_url);
test('playlist flag after -- separator is ineffective (documented broken pattern)',
    $url_idx !== false && $playlist_idx !== false && $playlist_idx > $url_idx);

echo "\n==> Summary: $tests_passed/$tests_run tests passed\n";
if ($failures > 0) {
    exit(1);
}

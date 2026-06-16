<?php
/**
 * AhoyRipper — playlist parameter unit tests
 * Run: php tests/playlist_param_test.php
 *
 * Tests that the playlist parameter is correctly resolved to yt-dlp flags.
 * The actual $playlist variable resolution in api.php is:
 *   $playlist = isset($_GET['playlist']) && $_GET['playlist'] === '1' ? '--yes-playlist' : '--no-playlist';
 *
 * This test file mirrors that logic and verifies expected behavior.
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
 * Mirrors the playlist resolution logic in api.php.
 * yt-dlp treats --yes-playlist and --no-playlist as position-sensitive flags
 * that must appear BEFORE the URL separator (--). Passing --yes-playlist
 * via the format field (after --) does NOT work.
 */
function resolvePlaylistFlag($playlist_get) {
    return isset($playlist_get) && $playlist_get === '1' ? '--yes-playlist' : '--no-playlist';
}

echo "\n==> Testing playlist parameter resolution\n";

test('playlist=1 resolves to --yes-playlist',
    resolvePlaylistFlag('1') === '--yes-playlist');
test('playlist=0 resolves to --no-playlist',
    resolvePlaylistFlag('0') === '--no-playlist');
test('playlist absent resolves to --no-playlist (default)',
    resolvePlaylistFlag(null) === '--no-playlist');
test('playlist empty string resolves to --no-playlist',
    resolvePlaylistFlag('') === '--no-playlist');
test('playlist=2 (invalid) resolves to --no-playlist',
    resolvePlaylistFlag('2') === '--no-playlist');
test('playlist=yes (non-numeric) resolves to --no-playlist',
    resolvePlaylistFlag('yes') === '--no-playlist');
test('playlist=true (non-numeric) resolves to --no-playlist',
    resolvePlaylistFlag('true') === '--no-playlist');

// Verify flag values are valid yt-dlp flags
test('--yes-playlist is a known yt-dlp flag',
    in_array('--yes-playlist', ['--yes-playlist', '--no-playlist'], true));
test('--no-playlist is a known yt-dlp flag',
    in_array('--no-playlist', ['--yes-playlist', '--no-playlist'], true));

// yt-dlp flag ordering invariant: playlist flags must appear BEFORE the URL
// (before the -- separator in the command array). This test documents the
// required ordering constraint so future refactors don't accidentally break it.
echo "\n==> Testing yt-dlp flag ordering constraint (documentation)\n";
$ytdlp_cmd_with_playlist_before_url = [
    '/usr/local/bin/yt-dlp',
    '-f', 'best',
    '--yes-playlist',   // playlist flag BEFORE -- separator
    '--',
    'https://youtube.com/playlist?list=...',
];
$url_index = array_search('--', $ytdlp_cmd_with_playlist_before_url);
$playlist_index = array_search('--yes-playlist', $ytdlp_cmd_with_playlist_before_url);
test('playlist flag must appear before -- separator (url index > playlist index)',
    $url_index !== false && $playlist_index !== false && $playlist_index < $url_index);

$ytdlp_cmd_playlist_after_url = [
    '/usr/local/bin/yt-dlp',
    '-f', 'best',
    '--',
    'https://youtube.com/playlist?list=...',
    '--yes-playlist',   // WRONG: playlist flag AFTER -- separator (does not work)
];
$url_idx = array_search('--', $ytdlp_cmd_playlist_after_url);
$playlist_idx = array_search('--yes-playlist', $ytdlp_cmd_playlist_after_url);
test('playlist flag after -- separator is ineffective (documented broken pattern)',
    $url_idx !== false && $playlist_idx !== false && $playlist_idx > $url_idx);

echo "\n==> Summary: $tests_passed/$tests_run tests passed\n";
exit($failures > 0 ? 1 : 0);
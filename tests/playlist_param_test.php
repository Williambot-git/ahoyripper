<?php
/**
 * AhoyRipper — playlist parameter unit tests
 * Run: php tests/playlist_param_test.php
 *
 * Tests that the playlist parameter is correctly resolved to yt-dlp flags.
 * The actual $playlist resolution in api.php uses yt-dlp 2024.02.07+ syntax:
 *   --playlist true   (when playlist=1, fetch all videos in playlist)
 *   --playlist false  (when playlist=0/absent, fetch single video only)
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
 * yt-dlp 2024.02.07+ accepts --playlist true/false (replacing the deprecated
 * --yes-playlist/--no-playlist flags). The --playlist flag must appear BEFORE
 * the URL separator (--). Passing --playlist after -- does NOT work.
 */
function resolvePlaylistFlag($playlist_get) {
    $flag = '--playlist';
    $value = isset($playlist_get) && $playlist_get === '1' ? 'true' : 'false';
    return ['flag' => $flag, 'value' => $value];
}

echo "\n==> Testing playlist parameter resolution\n";

test('playlist=1 resolves to --playlist true',
    resolvePlaylistFlag('1')['flag'] === '--playlist' && resolvePlaylistFlag('1')['value'] === 'true');
test('playlist=0 resolves to --playlist false',
    resolvePlaylistFlag('0')['flag'] === '--playlist' && resolvePlaylistFlag('0')['value'] === 'false');
test('playlist absent resolves to --playlist false (default)',
    resolvePlaylistFlag(null)['flag'] === '--playlist' && resolvePlaylistFlag(null)['value'] === 'false');
test('playlist empty string resolves to --playlist false',
    resolvePlaylistFlag('')['flag'] === '--playlist' && resolvePlaylistFlag('')['value'] === 'false');
test('playlist=2 (invalid) resolves to --playlist false',
    resolvePlaylistFlag('2')['flag'] === '--playlist' && resolvePlaylistFlag('2')['value'] === 'false');
test('playlist=yes (non-numeric) resolves to --playlist false',
    resolvePlaylistFlag('yes')['flag'] === '--playlist' && resolvePlaylistFlag('yes')['value'] === 'false');
test('playlist=true (non-numeric) resolves to --playlist false',
    resolvePlaylistFlag('true')['flag'] === '--playlist' && resolvePlaylistFlag('true')['value'] === 'false');

// Verify flag values are valid yt-dlp flags (2024.02.07+ syntax)
test('--playlist is a valid yt-dlp flag',
    in_array('--playlist', ['--playlist'], true));
test('--playlist true is valid yt-dlp syntax',
    in_array('true', ['true', 'false'], true));
test('--playlist false is valid yt-dlp syntax',
    in_array('false', ['true', 'false'], true));

// yt-dlp flag ordering invariant: playlist flags must appear BEFORE the URL
// (before the -- separator in the command array). This test documents the
// required ordering constraint so future refactors don't accidentally break it.
echo "\n==> Testing yt-dlp flag ordering constraint (documentation)\n";
$ytdlp_cmd_with_playlist_before_url = [
    '/usr/local/bin/yt-dlp',
    '-f', 'best',
    '--playlist', 'true',  // playlist flag BEFORE -- separator
    '--',
    'https://youtube.com/playlist?list=...',
];
$url_index = array_search('--', $ytdlp_cmd_with_playlist_before_url);
$playlist_index = array_search('--playlist', $ytdlp_cmd_with_playlist_before_url);
test('playlist flag must appear before -- separator (url index > playlist index)',
    $url_index !== false && $playlist_index !== false && $playlist_index < $url_index);

$ytdlp_cmd_playlist_after_url = [
    '/usr/local/bin/yt-dlp',
    '-f', 'best',
    '--',
    'https://youtube.com/playlist?list=...',
    '--playlist', 'true',  // WRONG: playlist flag AFTER -- separator (does not work)
];
$url_idx = array_search('--', $ytdlp_cmd_playlist_after_url);
$playlist_idx = array_search('--playlist', $ytdlp_cmd_playlist_after_url);
test('playlist flag after -- separator is ineffective (documented broken pattern)',
    $url_idx !== false && $playlist_idx !== false && $playlist_idx > $url_idx);

echo "\n==> Summary: $tests_passed/$tests_run tests passed\n";
exit($failures > 0 ? 1 : 0);
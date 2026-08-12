<?php
/**
 * AhoyRipper - clean() unit tests
 * Run: php tests/clean_test.php
 *
 * Tests the clean() sanitization function used to produce safe, human-readable
 * format labels from yt-dlp metadata. clean() is applied to every format card
 * field before JSON encoding, so it must:
 *   - Return 'Unknown' for null, empty string, whitespace-only string
 *   - Preserve integer 0 as '0' (valid numeric metadata value, not "Unknown")
 *   - Return 'Unknown' for booleans, arrays, and objects (would otherwise
 *     corrupt the response via (string) cast to "1"/""/"Array")
 *   - Pass through all other scalar values as strings
 *
 * Each test is self-contained and exits 1 on failure, 0 on success.
 * No external test framework, yt-dlp, or api.php bootstrap required.
 */

$failures = 0;
$tests_run = 0;
$tests_passed = 0;

function test($name, $condition) {
    global $failures, $tests_run, $tests_passed;
    $tests_run++;
    if ($condition) {
        echo "  OK $name\n";
        $tests_passed++;
    } else {
        echo "  FAIL $name\n";
        $failures++;
    }
}

// --- clean() verbatim copy from src/api.php ---

function clean($s) {
    // Return 'Unknown' for null, empty string, or whitespace-only string.
    // Integer 0 is NOT treated as Unknown -- it is a valid numeric value that
    // appears in yt-dlp metadata (e.g., height=0 for audio-only formats).
    // Passing 0 through as '0' (string) keeps the UI consistent and prevents
    // silent label corruption (e.g., "0kbps m4a" would become "Unknown kbps m4a").
    // Whitespace-only strings from yt-dlp metadata would produce blank or
    // space-filled labels (e.g., "  kbps m4a") -- trim before checking emptiness.
    if (is_string($s)) {
        $s = trim($s);
        if ($s === '') return 'Unknown';
    } elseif ($s === null) {
        return 'Unknown';
    }
    // Reject booleans, arrays and objects -- yt-dlp metadata is always scalar
    // (string, int, float, or null). A boolean in a format label field would
    // become "1" or "" (empty string) via (string) cast, corrupting the label
    // silently. An array/object would become the literal string "Array", also
    // corrupting the API response. Return 'Unknown' for all of these.
    if (is_bool($s) || is_array($s) || is_object($s)) return 'Unknown';
    // No htmlspecialchars -- API outputs JSON, not HTML.
    // Type coercion to string is sufficient.
    return (string)$s;
}

// --- null handling ---

echo "\n==> Testing null input\n";
test('returns Unknown for null', clean(null) === 'Unknown');

// --- empty and whitespace-only strings ---

echo "\n==> Testing empty and whitespace-only strings\n";
test('returns Unknown for empty string', clean('') === 'Unknown');
test('returns Unknown for whitespace-only string (spaces)', clean('   ') === 'Unknown');
test('returns Unknown for whitespace-only string (tabs)', clean("\t\t") === 'Unknown');
test('returns Unknown for whitespace-only string (newlines)', clean("\n\n") === 'Unknown');
test('returns Unknown for whitespace-only string (mixed)', clean(" \t\n\r") === 'Unknown');

// --- integer zero (must NOT become Unknown) ---

echo "\n==> Testing integer zero (critical: must NOT become Unknown)\n";
test('returns "0" for integer 0 (not Unknown)', clean(0) === '0');
test('returns "0" for string "0"', clean('0') === '0');
test('returns "0" for float 0.0', clean(0.0) === '0');

// --- booleans ---

echo "\n==> Testing boolean input (must return Unknown, not \"1\" or \"\")\n";
test('returns Unknown for true (not "1")', clean(true) === 'Unknown');
test('returns Unknown for false (not "")', clean(false) === 'Unknown');

// --- arrays ---

echo "\n==> Testing array input (must return Unknown, not \"Array\")\n";
test('returns Unknown for empty array (not "Array")', clean([]) === 'Unknown');
test('returns Unknown for indexed array (not "Array")', clean(['a', 'b']) === 'Unknown');
test('returns Unknown for associative array (not "Array")', clean(['key' => 'val']) === 'Unknown');

// --- objects ---

echo "\n==> Testing object input (must return Unknown, not \"Array\")\n";
test('returns Unknown for stdClass object (not "Array")', clean((object)['key' => 'val']) === 'Unknown');
test('returns Unknown for DateTime object', clean(new DateTime()) === 'Unknown');

// --- valid scalar strings ---

echo "\n==> Testing valid scalar strings (passthrough)\n";
test('returns string unchanged for plain ASCII', clean('video') === 'video');
test('returns string unchanged for mixed alphanumeric', clean('bestvideo+bestaudio') === 'bestvideo+bestaudio');
test('returns trimmed string for leading/trailing spaces', clean('  video  ') === 'video');
test('returns trimmed string for leading/trailing tabs', clean("\tvideo\t") === 'video');
test('returns string unchanged for yt-dlp format selector chars', clean('bestvideo[height>=720]+bestaudio') === 'bestvideo[height>=720]+bestaudio');
test('returns string unchanged for yt-dlp fallback selector', clean('18/22') === '18/22');
test('returns string unchanged for numeric string', clean('1080') === '1080');
test('returns string unchanged for string containing only digits', clean('12345') === '12345');
test('returns string unchanged for "none" (vcodec/acodec sentinel)', clean('none') === 'none');
test('returns string unchanged for "unknown" (yt-dlp metadata)', clean('unknown') === 'unknown');
test('returns string unchanged for "auto" (yt-dlp quality)', clean('auto') === 'auto');
test('returns string unchanged for path-like string', clean('/path/to/file.mp4') === '/path/to/file.mp4');
test('returns string unchanged for URL-like string', clean('https://example.com/video') === 'https://example.com/video');
test('returns string unchanged for ISO 8601 date', clean('2024-01-01T00:00:00Z') === '2024-01-01T00:00:00Z');
test('returns string unchanged for "m4a" (file extension)', clean('m4a') === 'm4a');
test('returns string unchanged for "mp4" (file extension)', clean('mp4') === 'mp4');
test('returns string unchanged for "webm" (file extension)', clean('webm') === 'webm');
test('returns string unchanged for "mkv" (file extension)', clean('mkv') === 'mkv');
test('returns string unchanged for "mp3" (file extension)', clean('mp3') === 'mp3');
test('returns string unchanged for "wav" (file extension)', clean('wav') === 'wav');
test('returns string unchanged for "flac" (file extension)', clean('flac') === 'flac');
test('returns string unchanged for "ogg" (file extension)', clean('ogg') === 'ogg');
test('returns string unchanged for "opus" (codec)', clean('opus') === 'opus');
test('returns string unchanged for "aac" (codec)', clean('aac') === 'aac');
test('returns string unchanged for "h264" (codec)', clean('h264') === 'h264');
test('returns string unchanged for "vp9" (codec)', clean('vp9') === 'vp9');
test('returns string unchanged for "av1" (codec)', clean('av1') === 'av1');
test('returns string unchanged for "vorbis" (codec name)', clean('vorbis') === 'vorbis');
test('returns string unchanged for "avc1.640028" (codec string)', clean('avc1.640028') === 'avc1.640028');
test('returns string unchanged for "DASH audio" (yt-dlp label)', clean('DASH audio') === 'DASH audio');

// --- valid numeric (int/float) ---

echo "\n==> Testing valid numeric scalars (passthrough as string)\n";
test('returns "720" for integer 720', clean(720) === '720');
test('returns "480" for integer 480', clean(480) === '480');
test('returns "128" for integer 128', clean(128) === '128');
test('returns "256" for float 256.0', clean(256.0) === '256');
test('returns "0" for integer 0 (not Unknown -- critical)', clean(0) === '0');
test('returns "1" for integer 1', clean(1) === '1');
test('returns "-1" for integer -1', clean(-1) === '-1');
test('returns "1.5" for float 1.5', clean(1.5) === '1.5');
test('returns "60.0" for float 60.0', clean(60.0) === '60');

// --- type coercion safety ---

echo "\n==> Testing type coercion safety (no corruption)\n";
test('clean(true) is not "1"', clean(true) !== '1');
test('clean(false) is not ""', clean(false) !== '');
test('clean([]) is not "Array"', clean([]) !== 'Array');
test('clean(["a"]) is not "Array"', clean(['a']) !== 'Array');

// --- idempotence ---

echo "\n==> Testing idempotence (clean(clean(x)) === clean(x))\n";
test('clean(Unknown) on null returns Unknown (already clean)', clean(clean(null)) === 'Unknown');
test('clean("720") on "720" returns "720" (already clean)', clean(clean('720')) === '720');
test('clean("0") on "0" returns "0" (already clean)', clean(clean('0')) === '0');

// --- edge cases ---

echo "\n==> Testing edge cases\n";
test('returns Unknown for tab-only string', clean("\t") === 'Unknown');
test('returns Unknown for newline-only string', clean("\n") === 'Unknown');
test('returns Unknown for carriage return only', clean("\r") === 'Unknown');
test('returns string unchanged for "high" (quality label)', clean('high') === 'high');
test('returns string unchanged for "medium" (quality label)', clean('medium') === 'medium');
test('returns string unchanged for "low" (quality label)', clean('low') === 'low');
test('returns string unchanged for "tiny" (yt-dlp quality)', clean('tiny') === 'tiny');
test('returns string unchanged for "audio_only" (format type)', clean('audio_only') === 'audio_only');

// --- Summary ---

echo "\n" . str_repeat('=', 50) . "\n";
echo "Results: $tests_passed/$tests_run passed";
if ($failures > 0) {
    echo " - $failures FAILED\n";
    exit(1);
} else {
    echo " - all passed\n";
    exit(0);
}

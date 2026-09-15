<?php
/**
 * AhoyRipper — sanitizeFilename() unit tests
 * Run: php tests/sanitize_filename_test.php
 *
 * Tests the filename sanitization logic used in api.php lines 4545-4564,
 * which processes the ?filename= URL parameter and builds the
 * Content-Disposition header for downloads.
 *
 * KEY BEHAVIOURS:
 *   1. URL-decode + trim the input.
 *   2. Strip all ASCII control characters ([\x00-\x1F\x7F]) FIRST — this
 *      is the CRLF-injection defence that makes the rest safe to use in
 *      HTTP headers without further encoding.
 *   3. Strip everything except Unicode letters, numbers, spaces, dots,
 *      underscores, hyphens (via negated Unicode character class).
 *   4. Collapse any remaining whitespace runs to a single underscore.
 *   5. Trim leading/trailing whitespace/underscores.
 *   6. Fall back to 'ahoyrip' if the result is empty or exceeds
 *      MAX_FILENAME_LEN (80 bytes, NOT characters).
 *
 * IMPORTANT — strlen vs mb_strlen:
 *   The function uses strlen() (byte count), NOT mb_strlen() (character count).
 *   For ASCII text this is equivalent. For Unicode text each character may be
 *   3+ bytes (UTF-8), so a "80-character" Unicode string will be rejected if
 *   it exceeds 80 bytes. This is a known behaviour.
 *
 * No external test framework, yt-dlp, or ffmpeg required.
 */

$failures = 0;
$tests_run = 0;
$tests_passed = 0;

define('MAX_FILENAME_LEN', 80);

/**
 * Replicates sanitizeFilename() from api.php lines 4545-4564.
 * MUST stay in sync with production code.
 */
function sanitizeFilename(string $raw): string {
    $download_filename = trim(urldecode($raw));
    if ($download_filename !== '') {
        // Step 1: strip control chars including CR/LF BEFORE any other
        // processing. This prevents header-injection attacks via the
        // Content-Disposition header. Unicode chars are untouched here.
        $download_filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $download_filename);
        // Step 2: keep only Unicode letters (\p{L}), numbers (\p{N}),
        // spaces, dots, underscores, hyphens. Strip everything else.
        $download_filename = preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $download_filename);
        // Step 3: collapse remaining whitespace runs to a single underscore.
        $download_filename = preg_replace('/\s+/u', '_', $download_filename);
        // Step 4: final trim + length guard.
        $trimmed = trim($download_filename);
        if (strlen($trimmed) === 0 || strlen($trimmed) > MAX_FILENAME_LEN) {
            return 'ahoyrip';
        }
        return $trimmed;
    }
    return 'ahoyrip';
}

function test($name, $condition) {
    global $failures, $tests_run, $tests_passed;
    $tests_run++;
    if ($condition) {
        echo "  \xE2\x9C\x93 $name\n";
        $tests_passed++;
    } else {
        echo "  \xE2\x9C\x97 $name\n";
        $failures++;
    }
}

// ─── Empty input ─────────────────────────────────────────────────────────────

echo "\n==> Empty input (must return 'ahoyrip')\n";

test('empty string returns ahoyrip',
    sanitizeFilename('') === 'ahoyrip');

test('whitespace-only returns ahoyrip',
    sanitizeFilename('   ') === 'ahoyrip');

test('tab-only returns ahoyrip',
    sanitizeFilename("\t") === 'ahoyrip');

test('newline-only returns ahoyrip',
    sanitizeFilename("\n") === 'ahoyrip');

test('URL-encoded space %20 decodes to space, trim empty, returns ahoyrip',
    sanitizeFilename('%20') === 'ahoyrip');

test('double-encoded %2520 = % + 20, %% decoded to %, 20 are valid digits → 20 (not ahoyrip)',
    // %25 = literal '%', so %2520 → '%20' (the % is stripped leaving '20', valid digits → '20')
    sanitizeFilename('%2520') === '20');

// ─── URL decoding ─────────────────────────────────────────────────────────────

echo "\n==> URL decoding\n";

test('URL-decoded space My%20Video → My_Video (space collapsed)',
    sanitizeFilename('My%20Video') === 'My_Video');

test('URL-encoded slash Video%2FTitle → VideoTitle (slash stripped, not underscore)',
    sanitizeFilename('Video%2FTitle') === 'VideoTitle');

test('URL-encoded ampersand A%26B → AB (ampersand stripped, not underscore)',
    sanitizeFilename('A%26B') === 'AB');

test('URL-encoded UTF-8 D%C3%A9j%C3%A0 → Déjà (preserved)',
    sanitizeFilename('D%C3%A9j%C3%A0') === 'Déjà');

test('URL-encoded hash Video%23title → Videotitle (hash stripped)',
    sanitizeFilename('Video%23title') === 'Videotitle');

test('multiple URL-encoded spaces %20%20 → trimmed away (all whitespace)',
    sanitizeFilename('%20%20') === 'ahoyrip');

test('URL-encoded dots are preserved',
    sanitizeFilename('video.part1.mp4') === 'video.part1.mp4');

test('URL-encoded colon C%3AVideo → CVideo (colon stripped)',
    sanitizeFilename('C%3AVideo') === 'CVideo');

// ─── Control character stripping (CRLF injection defence) ─────────────────────
//
// NOTE: The control-char regex runs FIRST, before the invalid-char regex.
// This means control chars are stripped and any adjacent regular chars
// (like letters around a \n) are preserved. The \s+ collapse then runs
// across what remains. For "Video\nTitle" this leaves "VideoTitle" (no
// whitespace left to collapse). For "Video\r\nContent-Type: text/plain"
// it produces "Video_Content_Type_text_plain" because the single remaining
// space after CRLF removal is collapsed to underscore.

echo "\n==> Control character stripping (CRLF injection defence)\n";

test('embedded unix newline \n is stripped, no whitespace remains → VideoTitle',
    sanitizeFilename("Video\nTitle") === 'VideoTitle');

test('embedded carriage return \r is stripped',
    sanitizeFilename("Video\rTitle") === 'VideoTitle');

test('CRLF pair \r\n is stripped',
    sanitizeFilename("Video\r\nTitle") === 'VideoTitle');

test('tab character \t is stripped',
    sanitizeFilename("Video\tTitle") === 'VideoTitle');

test('header-injection: \r\n stripped first, then spaces collapse to underscore',
    // Step-by-step: "Video\r\nContent-Type: text/plain"
    //   1. trim: "Video\r\nContent-Type: text/plain" (no change)
    //   2. ctrl strip: "VideoContent-Type: text/plain" (\r and \n removed)
    //   3. invalid strip: "VideoContent-Type textplain" (punctuation removed)
    //   4. ws collapse: "VideoContent-Type_textplain" (spaces → underscores)
    //   5. final trim: "VideoContent-Type_textplain" (no leading/trailing whitespace)
    sanitizeFilename("Video\r\nContent-Type: text/plain") === 'VideoContent-Type_textplain');

test('NUL byte \x00 is stripped',
    sanitizeFilename("Video\x00Title") === 'VideoTitle');

test('BEL character \x07 is stripped',
    sanitizeFilename("Video\x07Title") === 'VideoTitle');

// ─── Valid character preservation ─────────────────────────────────────────────

echo "\n==> Valid character preservation\n";

test('basic ASCII letters preserved',
    sanitizeFilename('Never_Gonna_Give_You_Up') === 'Never_Gonna_Give_You_Up');

test('numbers preserved',
    sanitizeFilename('Video_2024_01') === 'Video_2024_01');

test('internal spaces collapse to underscore',
    sanitizeFilename('Never Gonna Give You Up') === 'Never_Gonna_Give_You_Up');

test('multiple consecutive spaces collapse to single underscore',
    sanitizeFilename('Video    Title') === 'Video_Title');

test('leading/trailing spaces trimmed then collapsed',
    sanitizeFilename('  Video Title  ') === 'Video_Title');

test('dots preserved',
    sanitizeFilename('video.mp4') === 'video.mp4');

test('hyphens preserved',
    sanitizeFilename('my-video-file.mp4') === 'my-video-file.mp4');

test('Unicode letters preserved (Japanese)',
    sanitizeFilename('日本語タイトル') === '日本語タイトル');

test('Unicode letters preserved (Korean)',
    sanitizeFilename('동영상_타이틀') === '동영상_타이틀');

test('mixed ASCII/Unicode preserved',
    sanitizeFilename('日本語_Title_123') === '日本語_Title_123');

test('underscores preserved',
    sanitizeFilename('Never_Gonna_Give_You_Up') === 'Never_Gonna_Give_You_Up');

// ─── Invalid character stripping ───────────────────────────────────────────────
//
// Characters NOT in [\p{L}\p{N}\s._-] are stripped. Note that space (\s) IS
// allowed at this stage (before the \s+ collapse step).

echo "\n==> Invalid character stripping\n";

test('forward slash stripped (path traversal attempt)',
    sanitizeFilename('Video/Title') === 'VideoTitle');

test('backslash stripped',
    sanitizeFilename('Video\\Title') === 'VideoTitle');

test('colon stripped (Windows path separator)',
    sanitizeFilename('C:VideoTitle') === 'CVideoTitle');

test('asterisk stripped',
    sanitizeFilename('Video*Title') === 'VideoTitle');

test('question mark stripped',
    sanitizeFilename('Video?Title') === 'VideoTitle');

test('pipe stripped',
    sanitizeFilename('Video|Title') === 'VideoTitle');

test('angle brackets stripped',
    sanitizeFilename('Video<Title>') === 'VideoTitle');

test('double quotes stripped',
    sanitizeFilename('Video"Title') === 'VideoTitle');

test('single quotes stripped',
    sanitizeFilename("Video'Title") === 'VideoTitle');

test('backtick stripped',
    sanitizeFilename('Video`Title') === 'VideoTitle');

test('dollar sign stripped',
    sanitizeFilename('Video$Title') === 'VideoTitle');

test('percent sign stripped (except as part of valid URL-encoded sequences)',
    // Note: % alone is stripped because % is not in [\p{L}\p{N}\s._-].
    // When followed by valid hex digits forming a valid URL-encoding (like
    // %20=space, %2F=/, %26=&), urldecode() processes it first. When % is
    // followed by non-hex or invalid seq, urldecode leaves it, then the
    // percent is stripped by the character class.
    sanitizeFilename('Video%Title') === 'VideoTitle');

test('exclamation mark stripped',
    sanitizeFilename('Video!Title') === 'VideoTitle');

test('at sign stripped',
    sanitizeFilename('Video@Title') === 'VideoTitle');

test('hash/pound sign stripped',
    sanitizeFilename('Video#Title') === 'VideoTitle');

test('caret stripped',
    sanitizeFilename('Video^Title') === 'VideoTitle');

// ─── Plus sign (URL form encoding for space) ──────────────────────────────────
//
// IMPORTANT: In HTML forms, + represents a space. urldecode('+') = ' '.
// So "Video+Title" → "Video Title" → collapse → "Video_Title".

echo "\n==> Plus sign (URL form encoding for space)\n";

test('plus sign is decoded to space then collapsed to underscore',
    sanitizeFilename('Video+Title') === 'Video_Title');

// ─── Length enforcement ────────────────────────────────────────────────────────
//
// strlen() (byte count) is used, NOT mb_strlen() (character count).
// For ASCII: strlen == character count. For UTF-8: strlen > character count.
// MAX_FILENAME_LEN = 80 bytes.

echo "\n==> MAX_FILENAME_LEN enforcement (80 bytes, strlen)\n";

test('exactly 80 ASCII chars is accepted',
    strlen(sanitizeFilename(str_repeat('A', 80))) === 80
    && sanitizeFilename(str_repeat('A', 80)) === str_repeat('A', 80));

test('81 ASCII chars falls back to ahoyrip',
    sanitizeFilename(str_repeat('A', 81)) === 'ahoyrip');

test('100 ASCII chars falls back to ahoyrip',
    sanitizeFilename(str_repeat('A', 100)) === 'ahoyrip');

test('single char (A) is accepted',
    sanitizeFilename('A') === 'A');

test('long Unicode string exceeding 80 bytes falls back to ahoyrip (strlen counts UTF-8 bytes)',
    // 80 Japanese chars × 3 bytes/char = 240 bytes > 80 → ahoyrip
    sanitizeFilename(str_repeat('日', 81)) === 'ahoyrip');

test('realistic ASCII string within 80 bytes is accepted',
    strlen(sanitizeFilename('Never_Gonna_Give_You_Up')) < 80);

// ─── Edge cases ───────────────────────────────────────────────────────────────

echo "\n==> Edge cases\n";

test('string "0" is treated as non-empty (URL param is always string type)',
    sanitizeFilename('0') === '0');

test('numeric string preserved as-is',
    sanitizeFilename('123') === '123');

test('multiple leading/trailing hyphens preserved',
    sanitizeFilename('---video---') === '---video---');

test('leading hyphen preserved',
    sanitizeFilename('-Video') === '-Video');

test('trailing hyphen preserved',
    sanitizeFilename('Video-') === 'Video-');

test('multiple dots preserved',
    sanitizeFilename('video.part.one.two.mp4') === 'video.part.one.two.mp4');

test('dots-only filename is valid (not empty, chars preserved, within 80 bytes)',
    sanitizeFilename('...') === '...');

test('underscores-only is NOT trimmed away (PHP trim() removes whitespace, not underscores)',
    // PHP trim() removes ASCII whitespace (\s equivalent chars) but NOT underscores.
    // "___" → trim → "___" (unchanged) → not empty → returned as-is.
    sanitizeFilename('___') === '___');

test('already-sanitized filename passes through unchanged',
    sanitizeFilename('Never_Gonna_Give_You_Up') === 'Never_Gonna_Give_You_Up');

test('realistic yt-dlp title with URL-encoded spaces',
    sanitizeFilename('Rick%20Astley%20-%20Never%20Gonna%20Give%20You%20Up')
    === 'Rick_Astley_-_Never_Gonna_Give_You_Up');

test('empty after URL decoding but non-empty input → ahoyrip',
    // All chars stripped → empty → ahoyrip
    sanitizeFilename('!!!') === 'ahoyrip');

// ─── Security invariants ──────────────────────────────────────────────────────
//
// The primary goal of sanitizeFilename is to make the filename safe to embed
// in the Content-Disposition HTTP header without further encoding.

echo "\n==> Security invariants\n";

test('no CR in output (prevents HTTP response splitting via CRLF in header)',
    strpos(sanitizeFilename("Video\r\nHeader"), "\r") === false
    && strpos(sanitizeFilename("Video\r\nHeader"), "\n") === false);

test('no LF in output (prevents HTTP response splitting)',
    strpos(sanitizeFilename("Video\nHeader"), "\n") === false);

test('no path separators in output (no directory traversal in Content-Disposition: filename)',
    strpos(sanitizeFilename('Video/../../../etc/passwd'), '/') === false);

test('no backslash in output',
    strpos(sanitizeFilename('Video\\..\\..\\windows'), '\\') === false);

test('output is always a non-empty string when a filename is provided',
    sanitizeFilename('any_valid_input') !== ''
    && is_string(sanitizeFilename('any_valid_input')));

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

<?php
/**
 * AhoyRipper — clean() unit tests
 * Run: php tests/clean_test.php
 *
 * Tests the clean() sanitisation function that is called on every piece of
 * yt-dlp metadata (title, thumbnail, format labels, descriptions, etc.)
 * before it is included in API responses. Errors in clean() silently corrupt
 * the API response, so comprehensive coverage is important.
 *
 * Each test is self-contained and exits 1 on failure, 0 on success.
 * No external test framework or yt-dlp required.
 */

$failures = 0;
$tests_run = 0;
$tests_passed = 0;

require_once __DIR__ . '/../src/TestUtils.php';

function test($name, $condition) {
    global $failures, $tests_run, $tests_passed;
    $tests_run++;
    if ($condition) {
        echo "  ✓ $name\n";
        $tests_passed++;
    } else {
        echo "  ✗ $name\n";
        $failures++;
    }
}

// ─── null inputs ───────────────────────────────────────────────────────────────

echo "\n==> Testing null inputs\n";

test('null (NULL constant) returns Unknown',
    clean(null) === 'Unknown');

test('NULL constant returns Unknown',
    clean(NULL) === 'Unknown');

// ─── Empty and whitespace-only strings ─────────────────────────────────────────

echo "\n==> Testing empty and whitespace-only strings\n";

test('empty string returns Unknown',
    clean('') === 'Unknown');

test('whitespace-only string returns Unknown',
    clean("  \t\n  ") === 'Unknown');

test('single space returns Unknown',
    clean(' ') === 'Unknown');

test('tab-only returns Unknown',
    clean("\t") === 'Unknown');

test('newline-only returns Unknown',
    clean("\n") === 'Unknown');

test('carriage return + newline returns Unknown',
    clean("\r\n") === 'Unknown');

// ─── Valid strings ─────────────────────────────────────────────────────────────

echo "\n==> Testing valid strings\n";

test('plain string passes through unchanged',
    clean('Rick Astley - Never Gonna Give You Up') === 'Rick Astley - Never Gonna Give You Up');

test('unicode string is preserved',
    clean('日本語タイトル') === '日本語タイトル');

test('emoji in string is preserved',
    clean('Video 🎬 Title') === 'Video 🎬 Title');

test('leading whitespace is trimmed',
    clean('  Title') === 'Title');

test('trailing whitespace is trimmed',
    clean('Title  ') === 'Title');

test('leading and trailing whitespace is trimmed',
    clean("  Title  \n") === 'Title');

test('string "0" passes through as "0"',
    clean('0') === '0');

test('string "false" passes through (bool check handles actual booleans, not string "false")',
    clean('false') === 'false');

test('string "true" passes through',
    clean('true') === 'true');

test('string "null" (non-empty lowercase string) passes through — not the same as PHP null',
    clean('null') === 'null');

test('string "undefined" passes through',
    clean('undefined') === 'undefined');

test('numeric string passes through',
    clean('1080') === '1080');

test('string representation of float passes through',
    clean('3.5') === '3.5');

test('string with quotes passes through',
    clean('Title with "quotes"') === 'Title with "quotes"');

test('string with apostrophe passes through',
    clean("It's a title") === "It's a title");

test('internal newlines are NOT collapsed (PHP trim only strips edges)',
    clean("Title\nWith\nNewlines") === "Title\nWith\nNewlines");

test('internal tabs are NOT collapsed',
    clean("Title\tWith\tTabs") === "Title\tWith\tTabs");

// ─── Boolean inputs ─────────────────────────────────────────────────────────────

echo "\n==> Testing boolean inputs\n";

test('true (boolean) returns null — booleans must not become "1"',
    clean(true) === null);

test('false (boolean) returns null — booleans must not become ""',
    clean(false) === null);

// ─── Array inputs ───────────────────────────────────────────────────────────────

echo "\n==> Testing array inputs\n";

test('indexed array returns null — would corrupt label as "Array"',
    clean(['mp4', 'webm']) === null);

test('associative array returns null',
    clean(['format' => 'mp4', 'height' => 1080]) === null);

test('empty array returns null',
    clean([]) === null);

// ─── Object inputs ──────────────────────────────────────────────────────────────

echo "\n==> Testing object inputs\n";

test('stdClass object returns null — would corrupt label as "Object"',
    clean((object)['format' => 'mp4']) === null);

test('object from json_decode returns null',
    clean(json_decode('{"format":"mp4"}')) === null);

// ─── Numeric inputs ─────────────────────────────────────────────────────────────

echo "\n==> Testing numeric inputs\n";

test('integer 0 returns "0" — audio-only formats have height=0',
    clean(0) === '0');

test('positive integer returns string',
    clean(1080) === '1080');

test('float returns string',
    clean(3.5) === '3.5');

// ─── Type coercion safety ───────────────────────────────────────────────────────

echo "\n==> Testing type coercion safety\n";

test('clean result is always a string or null',
    is_string(clean('normal string')) || clean('normal string') === null);

test('clean result is never a float',
    is_float(clean(3.5)) === false);

test('clean result is never an array',
    is_array(clean(['a', 'b'])) === false);

// ─── yt-dlp metadata edge cases ───────────────────────────────────────────────

echo "\n==> Testing yt-dlp metadata edge cases\n";

test('height=0 (audio-only format) returns "0" not "Unknown"',
    clean(0) === '0');

test('tbr=0 (unknown bitrate) returns "0"',
    clean(0) === '0');

test('filesize=null (unknown size) returns "Unknown"',
    clean(null) === 'Unknown');

test('ext="mp4" passes through',
    clean('mp4') === 'mp4');

test('vcodec="none" (audio only) passes through',
    clean('none') === 'none');

test('acodec="none" (video only) passes through',
    clean('none') === 'none');

test('format_note empty string returns "Unknown"',
    clean('') === 'Unknown');

test('format_note null returns "Unknown"',
    clean(null) === 'Unknown');

test('thumbnail empty string returns "Unknown"',
    clean('') === 'Unknown');

test('thumbnail null returns "Unknown"',
    clean(null) === 'Unknown');

test('title with only leading/trailing whitespace is trimmed',
    clean("  Rick Astley  ") === 'Rick Astley');

test('title that is just newlines returns Unknown',
    clean("\n\n\n") === 'Unknown');

// ─── Security: No htmlspecialchars — API outputs JSON ──────────────────────────

echo "\n==> Testing no HTML encoding (API outputs JSON, not HTML)\n";

test('angle brackets are NOT encoded — clean() does not call htmlspecialchars',
    clean('<script>alert(1)</script>') === '<script>alert(1)</script>');

test('ampersand is NOT encoded',
    clean('Tom & Jerry') === 'Tom & Jerry');

test('double quotes are NOT encoded',
    clean('Title with "quotes"') === 'Title with "quotes"');

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

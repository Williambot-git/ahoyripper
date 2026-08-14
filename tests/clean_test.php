<?php
/**
 * Unit tests for clean() — format label sanitization helper.
 * Canonical implementation lives in src/api.php.
 */

function clean($s) {
    if (is_string($s)) {
        $s = trim($s);
        if ($s === '') return 'Unknown';
    } elseif ($s === null) {
        return 'Unknown';
    }
    if (is_bool($s) || is_array($s) || is_object($s)) return null;
    return (string)$s;
}

$passed = 0;
$failed = 0;

function assert_clean($input, $expected, $description) {
    global $passed, $failed;
    $actual = clean($input);
    if ($actual === $expected) {
        echo "  \u2713 $description\n";
        $passed++;
    } else {
        echo "  \u2717 $description — got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
        $failed++;
    }
}

echo "clean() tests\n";
echo str_repeat('-', 40) . "\n";

// Null handling
assert_clean(null, 'Unknown', 'null returns Unknown');
assert_clean('', 'Unknown', 'empty string returns Unknown');
assert_clean('  ', 'Unknown', 'whitespace-only string returns Unknown');
assert_clean("  \t\n", 'Unknown', 'mixed whitespace returns Unknown');

// Integer handling — 0 is valid, NOT Unknown
assert_clean(0, '0', 'integer 0 returns "0" (valid numeric)');
assert_clean(480, '480', 'positive integer passthrough');
assert_clean(1080, '1080', '1080 passthrough');

// String passthrough
assert_clean('1080p', '1080p', 'normal string passthrough');
assert_clean('mp4', 'mp4', 'format string passthrough');
assert_clean('720p60', '720p60', 'fps label passthrough');
assert_clean('Audio 128kbps', 'Audio 128kbps', 'audio label passthrough');

// Whitespace is trimmed
assert_clean('  mp4  ', 'mp4', 'string with surrounding whitespace is trimmed');

// Booleans — return null (not cast to string) so ternary in format builder works correctly
assert_clean(true, null, 'boolean true returns null (not "1")');
assert_clean(false, null, 'boolean false returns null (not "")');

// Arrays — return null (not "Array")
assert_clean(['a', 'b'], null, 'array returns null (not "Array")');
assert_clean([], null, 'empty array returns null');

// Objects — return null (not "Array")
assert_clean((object)['a' => 1], null, 'object returns null (not "Array")');

// Mixed edge cases
assert_clean('Unknown', 'Unknown', 'literal "Unknown" string passthrough');
assert_clean('  Unknown  ', 'Unknown', '"Unknown" with whitespace trims to "Unknown"');
assert_clean(' 0 ', '0', 'string " 0 " trims to "0"');
assert_clean(' 480 ', '480', 'string " 480 " trims to "480"');
assert_clean("\x00null", 'null', 'string with null byte — trim strips \\x00 as whitespace, leaving "null"');
assert_clean('0kbps', '0kbps', '0kbps passthrough (0 as part of label)');

echo str_repeat('-', 40) . "\n";
echo "Results: $passed/$passed passed";
if ($failed > 0) echo ", $failed FAILED";
echo "\n";
exit($failed > 0 ? 1 : 0);

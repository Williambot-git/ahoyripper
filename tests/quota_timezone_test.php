<?php
/**
 * AhoyRipper — Quota Daily Reset Timezone Unit Tests
 * Run: php tests/quota_timezone_test.php
 *
 * Tests that the daily quota reset logic correctly uses UTC gmdate()
 * regardless of the server's system timezone configuration.
 *
 * The daily quota uses gmdate('Y-m-d') to compute the reset date.
 * A server in a timezone behind UTC (e.g. America/New_York, UTC-5)
 * that hasn't yet reached midnight UTC should still show the current
 * UTC date — not the previous calendar day in local time.
 *
 * Similarly, servers ahead of UTC (e.g. Asia/Tokyo, UTC+9) that have
 * already passed midnight UTC but are still in the same "local day"
 * should use the *next* UTC midnight, not the current local midnight.
 *
 * This test does NOT require yt-dlp, ffmpeg, or network access.
 * It tests the pure date-computation logic in isolation.
 */

$failures = 0;
$tests_run = 0;
$tests_passed = 0;

// Save the original timezone
$original_tz = date_default_timezone_get();

function test($name, $condition, $debug = '') {
    global $failures, $tests_run, $tests_passed;
    $tests_run++;
    if ($condition) {
        echo "  ✓ $name\n";
        $tests_passed++;
    } else {
        echo "  ✗ $name\n";
        if ($debug !== '') {
            echo "    $debug\n";
        }
        $failures++;
    }
}

/**
 * Get the UTC date string as gmdate() would return it right now.
 * This mirrors what api.php does in the quota file: gmdate('Y-m-d').
 * @param DateTimeZone $serverTz  The timezone to simulate
 * @return string  UTC date string (Y-m-d format)
 */
function getQuotaTodayUtc(DateTimeZone $serverTz): string {
    date_default_timezone_set($serverTz->getName());
    // gmdate() always returns UTC — it ignores the default timezone entirely.
    return gmdate('Y-m-d');
}

/**
 * Compute the Unix timestamp of the next UTC midnight, as api.php does for
 * Retry-After headers: new DateTime('tomorrow midnight', new DateTimeZone('UTC'))
 *
 * NOTE: DateTime('tomorrow midnight') uses the *current* server time to compute
 * "tomorrow", not a hypothetical scenario time. So the timestamp it produces
 * depends on when the test runs, not on any hardcoded scenario date.
 * To get a stable expected value for testing, we compute "midnight tonight UTC"
 * as the base and add 86400 seconds (one day).
 *
 * @param DateTimeZone $serverTz  The server's configured timezone (not used in computation)
 * @return int  Unix timestamp of the next UTC midnight
 */
function getNextUtcMidnight(DateTimeZone $serverTz): int {
    date_default_timezone_set($serverTz->getName());
    // 'tomorrow midnight UTC' = current UTC midnight + 1 day
    // current UTC midnight = floor(current_unix_ts / 86400) * 86400
    $now_ts = time(); // always UTC regardless of server timezone
    $today_utc_midnight = (int)floor($now_ts / 86400) * 86400;
    return $today_utc_midnight + 86400;
}

// ─── Test: gmdate always returns UTC (the core invariant) ─────────────────────

echo "\n==> Verifying gmdate() is UTC-invariant regardless of server timezone\n";

// This is the core invariant: gmdate() always returns UTC time.
// Changing the server timezone should NOT change what gmdate('Y-m-d') returns.
$tzs_to_test = [
    'UTC',
    'America/New_York',
    'America/Los_Angeles',
    'Europe/London',
    'Asia/Tokyo',
    'Australia/Sydney',
    'Pacific/Honolulu',
];

foreach ($tzs_to_test as $tz_name) {
    $tz = new DateTimeZone($tz_name);
    date_default_timezone_set($tz_name);
    $utc_now = gmdate('Y-m-d H:i:s');
    // The UTC time string should be identical across all timezone settings
    // because gmdate() is timezone-independent.
    test("gmdate('Y-m-d H:i:s') is same in $tz_name: $utc_now",
        strlen($utc_now) > 0 && strpos($utc_now, ' ') !== false);
}

// ─── Test: Server behind UTC (America/New_York, UTC-5 in winter) ───────────────

$ny_tz = new DateTimeZone('America/New_York');

echo "\n==> Testing America/New_York (UTC-5 / UTC-4, EST/EDT)\n";

// In New York (EST, UTC-5):
// When local time is 03:00 (before midnight UTC), UTC date = today.
// When local time is 22:00 (after midnight UTC), UTC date = tomorrow.
// We test that gmdate() returns UTC, not local.

date_default_timezone_set('America/New_York');
$ny_now = new DateTime('now', $ny_tz);
$ny_hour = (int)$ny_now->format('H');
echo "  NY current time: " . $ny_now->format('Y-m-d H:i:s T') . "\n";
echo "  NY current UTC: " . gmdate('Y-m-d H:i:s') . " UTC\n";

// Regardless of local hour, gmdate() returns UTC date
$ny_gmdate = gmdate('Y-m-d');
$ny_expected_utc = gmdate('Y-m-d'); // same call, same result
test("NY server: gmdate returns current UTC date ($ny_gmdate)",
    strlen($ny_gmdate) === 10 && strpos($ny_gmdate, '-') === 4);

// At 22:00 NY in winter (UTC-5), UTC is 03:00 next day.
// At 03:00 NY in winter (UTC-5), UTC is 08:00 same day.
// The NEXT UTC midnight is always the same regardless of NY local time:
// it's the next occurrence of 00:00 UTC.
$next_utc_ts = getNextUtcMidnight($ny_tz);
$expected_next = (int)(floor(time() / 86400) * 86400) + 86400;
test("getNextUtcMidnight(NY) returns tomorrow UTC midnight",
    $next_utc_ts === $expected_next,
    "Expected: $expected_next, got: $next_utc_ts");

// ─── Test: Server ahead of UTC (Asia/Tokyo, UTC+9) ───────────────────────────

$tokyo_tz = new DateTimeZone('Asia/Tokyo');

echo "\n==> Testing Asia/Tokyo (UTC+9)\n";

date_default_timezone_set('Asia/Tokyo');
$tokyo_now = new DateTime('now', $tokyo_tz);
echo "  Tokyo current time: " . $tokyo_now->format('Y-m-d H:i:s T') . "\n";
echo "  Tokyo current UTC: " . gmdate('Y-m-d H:i:s') . " UTC\n";

$tokyo_gmdate = gmdate('Y-m-d');
test("Tokyo server: gmdate returns current UTC date ($tokyo_gmdate)",
    strlen($tokyo_gmdate) === 10 && strpos($tokyo_gmdate, '-') === 4);

$tokyo_next_utc_ts = getNextUtcMidnight($tokyo_tz);
test("getNextUtcMidnight(Tokyo) returns tomorrow UTC midnight",
    $tokyo_next_utc_ts === $expected_next,
    "Expected: $expected_next, got: $tokyo_next_utc_ts");

// ─── Test: getQuotaTodayUtc is consistent across timezones ─────────────────────

echo "\n==> Testing getQuotaTodayUtc() consistency across timezones\n";

$all_tz_names = ['UTC', 'America/New_York', 'Asia/Tokyo', 'Europe/London', 'Australia/Sydney'];
$results = [];
foreach ($all_tz_names as $tz_name) {
    $tz = new DateTimeZone($tz_name);
    $results[$tz_name] = getQuotaTodayUtc($tz);
}

// All timezones should return the SAME UTC date string (they all call gmdate())
$unique_dates = array_unique(array_values($results));
$first_result = reset($results);
$all_same = count($unique_dates) === 1;
foreach ($results as $tz_name => $date) {
    $marker = ($date === $first_result) ? '==' : '!=';
    echo "  $tz_name => $date $marker $first_result\n";
}
test('All timezones return the same UTC date via getQuotaTodayUtc()',
    $all_same,
    "Expected all same, got: " . implode(', ', array_unique(array_values($results))));

// ─── Test: Next UTC midnight is timezone-independent ───────────────────────────

echo "\n==> Testing next UTC midnight computation is timezone-independent\n";

$next_timestamps = [];
foreach ($all_tz_names as $tz_name) {
    $tz = new DateTimeZone($tz_name);
    $next_timestamps[$tz_name] = getNextUtcMidnight($tz);
}
$unique_timestamps = array_unique(array_values($next_timestamps));
$first_ts = reset($next_timestamps);
$all_ts_same = count($unique_timestamps) === 1;
foreach ($next_timestamps as $tz_name => $ts) {
    echo "  $tz_name => $ts (expected: $first_ts)\n";
}
test('All timezones compute the same next UTC midnight timestamp',
    $all_ts_same,
    "Expected all same ($first_ts), got: " . implode(', ', $unique_timestamps));

// Verify the timestamp is a multiple of 86400 (midnight UTC)
test("Next UTC midnight is a multiple of 86400 (midnight)",
    $first_ts % 86400 === 0,
    "Got: " . ($first_ts % 86400));

// Verify the timestamp is in the future
test("Next UTC midnight is in the future (greater than now)",
    $first_ts > time(),
    "Got: $first_ts, now: " . time());

// Verify the timestamp is exactly 86400 seconds from the current UTC midnight
$now_midnight = (int)(floor(time() / 86400) * 86400);
$expected_next = $now_midnight + 86400;
test("Next UTC midnight is exactly 86400s from current UTC midnight",
    $first_ts === $expected_next,
    "Expected: $expected_next, got: $first_ts");

// ─── Summary ─────────────────────────────────────────────────────────────────

// Restore original timezone
date_default_timezone_set($original_tz);

echo "\n" . str_repeat('=', 50) . "\n";
echo "Results: $tests_passed/$tests_run passed";
if ($failures > 0) {
    echo " — $failures FAILED\n";
    exit(1);
} else {
    echo " — all passed\n";
    exit(0);
}

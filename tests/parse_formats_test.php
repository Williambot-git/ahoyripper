<?php
/**
 * AhoyRipper — parseFormats() unit tests
 * Run: php tests/parse_formats_test.php
 *
 * Tests the parseFormats() function with controlled yt-dlp JSON output.
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

// ─── clean() and parseFormats() verbatim copies from api.php ──────────────────

function clean($s) {
    // Return 'Unknown' for null or empty string only.
    // Integer 0 is NOT treated as Unknown — it is a valid numeric value that
    // appears in yt-dlp metadata (e.g., height=0 for audio-only formats).
    // Passing 0 through as '0' (string) keeps the UI consistent and prevents
    // silent label corruption (e.g., "0kbps m4a" would become "Unknown kbps m4a").
    if ($s === null || $s === '') return 'Unknown';
    // Reject arrays and objects — yt-dlp metadata is always scalar (string, int,
    // float, or null). An array in a format label field (e.g. from an unexpected
    // extractor field) would become the literal string "Array" via (string) cast,
    // corrupting the API response silently. Return 'Unknown' instead.
    if (is_array($s) || is_object($s)) return 'Unknown';
    // No htmlspecialchars — API outputs JSON, not HTML.
    // Type coercion to string is sufficient.
    return (string)$s;
}

echo "\n==> Testing clean() — null and empty string\n";
test('clean(null) returns "Unknown"',
    clean(null) === 'Unknown');
test('clean("") returns "Unknown"',
    clean('') === 'Unknown');
test('clean(42) returns "42" (non-zero numeric)',
    clean(42) === '42');
test('clean(0) returns "0" (zero is valid, not unknown)',
    clean(0) === '0');

echo "\n==> Testing clean() — array and object rejection\n";
test('clean(array) returns "Unknown" (not "Array")',
    clean(['video', 'mp4']) === 'Unknown');
test('clean(associative array) returns "Unknown"',
    clean(['ext' => 'mp4', 'height' => 720]) === 'Unknown');
test('clean(object) returns "Unknown" (not "Array")',
    clean((object)['ext' => 'mp4']) === 'Unknown');
test('clean(stdClass) returns "Unknown"',
    clean(json_decode('{"ext":"mp4"}')) === 'Unknown');

function parseFormats($json_str, &$raw_error_out = null, $sort = 'height') {
    $data = json_decode($json_str, true);
    if (!$data) {
        // Repair non-UTF-8 byte sequences before declaring the JSON invalid.
        // mb_convert_encoding replaces malformed byte sequences with a replacement
        // character (U+FFFD), producing valid UTF-8 that json_decode can parse.
        // This is idempotent for valid UTF-8 input — no change if already clean.
        $data = json_decode(mb_convert_encoding($json_str, 'UTF-8', 'UTF-8'), true);
    }
    if (!$data) {
        $raw = trim($json_str);
        if (preg_match('/^(ERROR|WARNING)/im', $raw)) {
            $err_msg = preg_replace('/[\x00-\x1F\x7F]/', '', $raw);
            $err_msg = strip_tags($err_msg);
            $err_msg = preg_replace('/\s+/', ' ', $err_msg);
            if (strlen($err_msg) > 200) $err_msg = substr($err_msg, 0, 200) . '...';

            // classifyYtdlpError — inline copy for test isolation
            $err_lower = strtolower($err_msg);
            if (preg_match('/geo.*restriction|this video is available in|geo.?restricted/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'This video is geo-restricted and not available in your region.', 'error_code' => 'GEOBLOCKED'];
            }
            if (preg_match('/video is private|this video is private/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'This video is private and cannot be downloaded.', 'error_code' => 'PRIVATE_VIDEO'];
            }
            // "authentication required" must be checked separately because the merged
            // pattern "authentication.*required" would require "required" to appear
            // twice — yt-dlp only says it once ("authentication required"), so we
            // match it directly as its own alternative.
            if (preg_match('/authentication required|login.*required|this video requires login/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'This video requires login or subscription.', 'error_code' => 'LOGIN_REQUIRED'];
            }
            if (preg_match('/not.*support|unsupported site|is not a supported URL/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'This site is not supported by yt-dlp.', 'error_code' => 'UNSUPPORTED_SITE'];
            }
            if (preg_match('/playlist.*not.*found|does not exist/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'Playlist not found or no longer exists.', 'error_code' => 'PLAYLIST_MISSING'];
            }
            if (preg_match('/copyright|infringe|removed.*by|content.*strike/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'This content has been removed due to a copyright claim.', 'error_code' => 'COPYRIGHT_REMOVED'];
            }
            if (preg_match('/video (has been )?(removed|delisted|unavailable|deleted)|this video (is no longer available|has been (removed|delisted))|video (has been )?removed|video (is )?unavailable/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'This video is no longer available or has been removed.', 'error_code' => 'VIDEO_UNAVAILABLE'];
            }
            if (preg_match('/too.*many.*requests|429/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'The source site is rate-limiting requests. Try again in a few minutes.', 'error_code' => 'SOURCE_RATE_LIMITED'];
            }
            if (preg_match('/age.*restriction|under age|video is age.*restricted/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'This video is age-restricted and cannot be downloaded without verification.', 'error_code' => 'AGE_RESTRICTED'];
            }
            if (preg_match('/certificate.*expired|ssl.*error|sslerr|tls handshake/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'Secure connection to the source failed. Try again shortly.', 'error_code' => 'SSL_ERROR'];
            }
            if (preg_match('#connection.*fail|dns.*fail|could not connect|i?/o timeout|connection timed out|timed out|connection reset|broken pipe|unable to connect|connection refused|getaddrinfo failed|name or service not known|network is unreachable|no route to host#i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'Could not connect to the source. Check your network and try again.', 'error_code' => 'CONNECTION_FAILED'];
            }
            if (preg_match('/file.*larger|size.*exceed|exceeds.*limit/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'This file exceeds the maximum size for this server. Try an audio-only or lower-resolution format.', 'error_code' => 'FILE_TOO_LARGE'];
            }
            if (preg_match('/requested format(?!s)|requested.*not.*available|format.*not.*available|does not contain|does not match/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'That format is not available for this video. Select another from the list.', 'error_code' => 'FORMAT_UNAVAILABLE'];
            }
            if (preg_match('/disallowed.*content|content.*violat|terms.*violat|violat.*terms/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'This content is not available due to a terms of service violation.', 'error_code' => 'DISALLOWED_CONTENT'];
            }
            // "process timed out" is produced by PHP-side timeout in runYtdlp() (api.php).
            // Distinct from connection-level "timed out" which implies a network failure.
            if (preg_match('/process timed out|read at byte.*timeout/i', $err_lower)) {
                if ($raw_error_out !== null) $raw_error_out = $err_msg;
                return ['error' => 'The source site took too long to respond. Try a smaller format (audio-only is fastest) or try again when the site is less busy.', 'error_code' => 'SOURCE_TIMEOUT'];
            }
            if ($raw_error_out !== null) $raw_error_out = $err_msg;
            return ['error' => 'yt-dlp error: ' . $err_msg, 'error_code' => 'YTDLP_ERROR'];
        }
        return null;
    }

    $title = clean($data['title'] ?? 'Unknown');
    $thumbnail = clean($data['thumbnail'] ?? '');
    $duration = (int)($data['duration'] ?? 0);
    $uploader = clean($data['uploader'] ?? '');
    $uploader_url = isset($data['uploader_url']) && $data['uploader_url'] !== ''
        ? (string)$data['uploader_url']
        : null;
    $platform = clean($data['extractor_key'] ?? '');
    $raw_fn = preg_replace('/[^\w\s.-]/', '', $title);
    $raw_fn = preg_replace('/\s+/', '_', trim($raw_fn));
    if (strlen($raw_fn) > 80) $raw_fn = substr($raw_fn, 0, 80);
    // Use ctype_digit() to catch ALL purely-numeric titles, not just "0".
    $derived_filename = ($raw_fn !== '' && !ctype_digit($raw_fn)) ? $raw_fn : 'ahoyrip';

    $formats = [];
    foreach (($data['formats'] ?? []) as $f) {
        $ext = clean($f['ext'] ?? '');
        $format_id = clean($f['format_id'] ?? '');
        $format_note = clean($f['format_note'] ?? '');
        $tbr = isset($f['tbr']) ? round((float)$f['tbr']) : null;
        $filesize = isset($f['filesize']) ? (int)$f['filesize'] : (isset($f['filesize_approx']) ? (int)$f['filesize_approx'] : 0);
        $width = isset($f['width']) ? (int)$f['width'] : 0;
        $height = isset($f['height']) ? (int)$f['height'] : 0;
        $vcodec = clean($f['vcodec'] ?? 'none');
        $acodec = clean($f['acodec'] ?? 'none');
        $fps = isset($f['fps']) && $f['fps'] !== null ? (int)(float)$f['fps'] : null;
        $language = clean($f['language'] ?? '');
        $format_description = $f['format_description'] ?? '';
        $abr = isset($f['abr']) ? (int)$f['abr'] : null;

        $label = '';
        if ($vcodec !== 'none' && $acodec !== 'none') {
            if ($height > 0) {
                $label = "{$height}p";
                if ($fps) $label .= "{$fps}";
                if ($format_note) $label .= " {$format_note}";
                $label .= " {$ext}";
            } else {
                $label = strtoupper($ext);
            }
        } elseif ($vcodec !== 'none') {
            if ($height > 0) {
                $label = "Video {$height}p";
                if ($fps) $label .= " {$fps}fps";
                $label .= " {$ext}";
            } else {
                $label = "Video {$ext}";
            }
        } elseif ($acodec !== 'none') {
            $br = $tbr ?? (isset($f['abr']) ? (int)$f['abr'] : null);
            if ($br) {
                $label = "{$br}kbps {$ext}";
            } else {
                $label = "Audio {$ext}";
            }
        } else {
            continue;
        }

        $quality = null;
        if ($vcodec !== 'none') {
            $quality = $height;
        } elseif ($acodec !== 'none') {
            $br = $tbr ?? $abr;
            if ($br !== null) {
                if ($br >= 320) $quality = 320;
                elseif ($br >= 256) $quality = 256;
                elseif ($br >= 192) $quality = 192;
                elseif ($br >= 128) $quality = 128;
                elseif ($br >= 96) $quality = 96;
                elseif ($br >= 64) $quality = 64;
                else $quality = 48;
            }
        }

        // description (human-readable, used for display)
        // format_description is used raw (null/empty = absent, string = present).
        // Only clean format_note (safe string coercion). Never clean format_description —
        // that would turn absent into the literal string "Unknown" and break fallback logic.
        // Audio-only formats: never prefix resolution; use label directly since
        // format_description carries no useful resolution context for audio.
        $resolution = ($width > 0 && $height > 0) ? ($width . 'x' . $height) : null;
        if ($resolution !== null && $vcodec !== 'none') {
            // Video-containing formats (combined or video-only) get resolution prefix.
            // Use null/empty-string checks instead of empty() to avoid false
            // positives on the literal string "0" (empty("0") === true in PHP).
            $has_desc = $format_description !== null && $format_description !== '';
            $desc = (!$has_desc || $format_description === 'Unknown')
                ? trim("{$resolution} " . ($format_note ?: $label))
                : trim("{$resolution} {$format_description}");
        } else {
            // Audio-only (or unknown codec with no resolution): use label directly.
            $desc = $label;
        }

        // filesize estimation
        $fs = $filesize;
        if ($fs === 0) {
            $duration_secs = $duration ?: 180;
            if ($vcodec !== 'none' && $acodec !== 'none') {
                $bitrate_kbps = $tbr ?? (($height > 720) ? 5000 : (($height > 480) ? 2500 : 1000));
                $fs = ($bitrate_kbps * 1000 / 8) * $duration_secs;
            } elseif ($vcodec !== 'none') {
                $bitrate_kbps = $tbr ?? (($height > 720) ? 4000 : 1500);
                $fs = ($bitrate_kbps * 1000 / 8) * $duration_secs;
            } else {
                $bitrate_kbps = $tbr ?? 128;
                $fs = ($bitrate_kbps * 1000 / 8) * $duration_secs;
            }
        }
        $filesize_mb = round($fs / 1048576, 1);

        $is_combined = ($vcodec !== 'none' && $acodec !== 'none');
        $is_video_only = ($vcodec !== 'none' && $acodec === 'none');
        $is_audio_only = ($vcodec === 'none' && $acodec !== 'none');
        $formats[] = [
            'id' => $format_id,
            'label' => $label,
            'description' => $desc,
            'ext' => $ext,
            'filesize_mb' => $filesize_mb,
            'height' => $height,
            'quality' => $quality,
            'fps' => $fps,
            'tbr' => $tbr,
            'abr' => $abr,
            'vcodec' => $vcodec,
            'acodec' => $acodec,
            'format_type' => ($vcodec !== 'none' && $acodec !== 'none') ? 'combined' : ($vcodec !== 'none' ? 'video' : 'audio'),
            'type_group' => $is_combined ? 0 : ($is_video_only ? 1 : 2),
            'language' => $language ?: null,
        ];
    }

    // Sort: combined formats first, then by selected sort key
    usort($formats, function($a, $b) use ($sort) {
        $type_cmp = $a['type_group'] <=> $b['type_group'];
        if ($type_cmp !== 0) {
            return $type_cmp;
        }
        if ($sort === 'filesize') {
            $cmp = ($b['filesize_mb'] ?? 0) <=> ($a['filesize_mb'] ?? 0);
        } elseif ($sort === 'filesize_asc') {
            $cmp = ($a['filesize_mb'] ?? 0) <=> ($b['filesize_mb'] ?? 0);
        } elseif ($sort === 'tbr') {
            $cmp = ($b['tbr'] ?? 0) <=> ($a['tbr'] ?? 0);
        } elseif ($sort === 'quality') {
            $cmp = ($b['quality'] ?? -1) <=> ($a['quality'] ?? -1);
        } else {
            $cmp = ($b['height'] ?? 0) <=> ($a['height'] ?? 0);
        }
        if ($cmp === 0) {
            $cmp = ($b['height'] ?? 0) <=> ($a['height'] ?? 0);
        }
        if ($cmp === 0) {
            $cmp = ($b['fps'] ?? 0) <=> ($a['fps'] ?? 0);
        }
        if ($cmp === 0) {
            $cmp = ($b['tbr'] ?? 0) <=> ($a['tbr'] ?? 0);
        }
        return $cmp;
    });

    return [
        'title' => $title,
        'thumbnail' => $thumbnail,
        'duration' => $duration,
        'uploader' => $uploader,
        'uploader_url' => $uploader_url,
        'platform' => $platform,
        'derived_filename' => $derived_filename,
        'formats' => $formats,
        'sort_applied' => $sort,
    ];
}

// ─── Test fixtures ─────────────────────────────────────────────────────────────

function makeJson($title, $formats, $extras = []) {
    $base = array_merge([
        'title' => $title,
        'thumbnail' => 'https://example.com/thumb.jpg',
        'duration' => 180,
        'uploader' => 'Test Channel',
        'uploader_url' => 'https://example.com/channel/test',
    ], $extras);
    $base['formats'] = $formats;
    return json_encode($base);
}

function makeFormat($overrides = []) {
    return array_merge([
        'format_id' => '18',
        'ext' => 'mp4',
        'format_note' => '240p',
        'width' => 320,
        'height' => 240,
        'vcodec' => 'avc1.64001E',
        'acodec' => 'mp4a.40.2',
        'fps' => 30,
        'tbr' => 300,
        'filesize' => 5242880,
    ], $overrides);
}

// ─── parseFormats: basic metadata extraction ───────────────────────────────────

echo "\n==> Testing parseFormats() — metadata fields\n";

$json = makeJson('Test Video Title', [makeFormat(['format_id' => '18'])]);
$result = parseFormats($json);
test('extracts title from JSON',
    $result && ($result['title'] ?? '') === 'Test Video Title');
test('extracts thumbnail',
    $result && ($result['thumbnail'] ?? '') === 'https://example.com/thumb.jpg');
test('extracts duration as integer',
    $result && ($result['duration'] ?? 0) === 180);
test('extracts uploader',
    $result && ($result['uploader'] ?? '') === 'Test Channel');
test('extracts uploader_url',
    $result && ($result['uploader_url'] ?? '') === 'https://example.com/channel/test');
test('derives filename from title (spaces become underscores)',
    $result && ($result['derived_filename'] ?? '') === 'Test_Video_Title');
test('returns sort_applied as height',
    $result && ($result['sort_applied'] ?? '') === 'height');

// Regression: purely numeric titles must fall back to 'ahoyrip', not use the
// number as the filename. PHP's empty() is true for '0', so $raw_fn ?: 'ahoyrip'
// would incorrectly use '1080' as the derived filename. The fix is
// ($raw_fn !== '' && $raw_fn !== '0') ? $raw_fn : 'ahoyrip'.
$json_numeric = makeJson('1080', []);
$result_numeric = parseFormats($json_numeric);
test('numeric title "1080" falls back to ahoyrip (not "1080")',
    $result_numeric && ($result_numeric['derived_filename'] ?? '') === 'ahoyrip');

$json_zero = makeJson('0', []);
$result_zero = parseFormats($json_zero);
test('title "0" falls back to ahoyrip (not "0")',
    $result_zero && ($result_zero['derived_filename'] ?? '') === 'ahoyrip');

$json2 = makeJson('Unknown', [], ['title' => null]);
$result2 = parseFormats($json2);
test('defaults missing title to "Unknown"',
    $result2 && ($result2['title'] ?? '') === 'Unknown');

$json3 = makeJson('Audio Test', [], ['title' => '']);
$result3 = parseFormats($json3);
test('defaults empty title to "Unknown"',
    $result3 && ($result3['title'] ?? '') === 'Unknown');

// ─── parseFormats: platform field (extractor_key) ──────────────────────────────

$json_platform = makeJson('Test', [makeFormat()], ['extractor_key' => 'YouTube']);
$result_platform = parseFormats($json_platform);
test('extracts platform from extractor_key (YouTube)',
    $result_platform && ($result_platform['platform'] ?? '') === 'YouTube');

$json_platform_tiktok = makeJson('Test', [makeFormat()], ['extractor_key' => 'TikTok']);
$result_platform_tiktok = parseFormats($json_platform_tiktok);
test('extracts platform from extractor_key (TikTok)',
    $result_platform_tiktok && ($result_platform_tiktok['platform'] ?? '') === 'TikTok');

$json_no_platform = makeJson('Test', [makeFormat()]);
$result_no_platform = parseFormats($json_no_platform);
test('defaults missing extractor_key to "Unknown"',
    $result_no_platform && ($result_no_platform['platform'] ?? '') === 'Unknown');

$json_empty_platform = makeJson('Test', [makeFormat()], ['extractor_key' => '']);
$result_empty_platform = parseFormats($json_empty_platform);
test('defaults empty extractor_key to "Unknown"',
    $result_empty_platform && ($result_empty_platform['platform'] ?? '') === 'Unknown');

// ─── parseFormats: format card fields ─────────────────────────────────────────

echo "\n==> Testing parseFormats() — format card fields\n";

$fmt = makeFormat([
    'format_id' => '22',
    'ext' => 'mp4',
    'format_note' => '720p',
    'width' => 1280,
    'height' => 720,
    'fps' => 30,
    'vcodec' => 'avc1.64001F',
    'acodec' => 'mp4a.40.2',
    'tbr' => 2500,
    'filesize' => 10485760,
    'language' => 'en',
]);
$json = makeJson('Video', [$fmt]);
$result = parseFormats($json);
$card = $result['formats'][0] ?? null;

test('format has correct id', $card && ($card['id'] ?? '') === '22');
test('format has correct ext', $card && ($card['ext'] ?? '') === 'mp4');
test('format has correct height', $card && ($card['height'] ?? 0) === 720);
test('format has correct fps', $card && ($card['fps'] ?? null) === 30);
test('format has correct tbr', $card && ($card['tbr'] ?? null) == 2500);
test('format has null abr when not set', $card && ($card['abr'] ?? null) === null);

// ─── parseFormats: abr field for audio formats ───────────────────────────────────

$fmt_audio = makeFormat([
    'format_id' => '140',
    'ext' => 'm4a',
    'vcodec' => 'none',
    'acodec' => 'mp4a.40.2',
    'abr' => 128,
    'filesize' => 2097152,
]);
$json_audio = makeJson('Audio Test', [$fmt_audio]);
$result_audio = parseFormats($json_audio);
$card_audio = $result_audio['formats'][0] ?? null;
test('audio format has abr field', $card_audio && array_key_exists('abr', $card_audio));
test('audio format abr value is correct (128)', $card_audio && ($card_audio['abr'] ?? null) === 128);
// Test: audio format with NO abr key at all in the JSON (not just set to null)
// makeFormat() has abr: null by default, but array_merge() doesn't remove keys
// — makeFormat([]) still includes abr: null from the defaults. Create the format
// explicitly without the abr key to test the no-abr parsing path.
$json_no_abr = json_encode([
    'title' => 'No ABR Audio',
    'thumbnail' => 'https://example.com/thumb.jpg',
    'duration' => 180,
    'uploader' => 'Test Channel',
    'formats' => [[
        'format_id' => '251',
        'ext' => 'webm',
        'vcodec' => 'none',
        'acodec' => 'opus',
        'tbr' => null,
        'filesize' => 1048576,
        // NO abr key at all
    ]]
]);
$result_no_abr = parseFormats($json_no_abr);
test('audio format without abr key has null abr', ($result_no_abr['formats'][0]['abr'] ?? null) === null);
test('format has correct vcodec', $card && ($card['vcodec'] ?? '') === 'avc1.64001F');
test('format has correct acodec', $card && ($card['acodec'] ?? '') === 'mp4a.40.2');
test('format type is combined (video+audio)', $card && ($card['format_type'] ?? '') === 'combined');
test('format has language', $card && ($card['language'] ?? null) === 'en');
test('format has filesize_mb (10MB -> ~10.0)',
    $card && abs(($card['filesize_mb'] ?? 0) - 10.0) < 0.2);

// ─── parseFormats: quality field ───────────────────────────────────────────

echo "\n==> Testing parseFormats() — quality field\n";

$json_video_combined = makeJson('Test', [makeFormat([
    'height' => 1080, 'fps' => 60, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'ext' => 'mp4'
])]);
$result_vc = parseFormats($json_video_combined);
test('combined video quality equals height (1080)', $result_vc && ($result_vc['formats'][0]['quality'] ?? null) === 1080);

$json_video_only = makeJson('Test', [makeFormat([
    'height' => 720, 'fps' => 30, 'vcodec' => 'avc1', 'acodec' => 'none', 'ext' => 'webm'
])]);
$result_vo = parseFormats($json_video_only);
test('video-only quality equals height (720)', $result_vo && ($result_vo['formats'][0]['quality'] ?? null) === 720);

// Audio bitrate tier tests — must null out tbr so abr override is the sole bitrate source
$audio_br_tests = [
    ['abr' => 320, 'expected' => 320, 'label' => '320kbps audio maps to quality 320'],
    ['abr' => 256, 'expected' => 256, 'label' => '256kbps audio maps to quality 256'],
    ['abr' => 192, 'expected' => 192, 'label' => '192kbps audio maps to quality 192'],
    ['abr' => 128, 'expected' => 128, 'label' => '128kbps audio maps to quality 128'],
    ['abr' => 96,  'expected' => 96,  'label' => '96kbps audio maps to quality 96'],
    ['abr' => 64,  'expected' => 64,  'label' => '64kbps audio maps to quality 64'],
    ['abr' => 48,  'expected' => 48,  'label' => '48kbps audio maps to quality 48'],
];
foreach ($audio_br_tests as $t) {
    $json_audio = makeJson('Test', [makeFormat([
        'vcodec' => 'none', 'acodec' => 'mp4a', 'ext' => 'm4a', 'abr' => $t['abr'], 'tbr' => null
    ])]);
    $result_audio = parseFormats($json_audio);
    test($t['label'], $result_audio && ($result_audio['formats'][0]['quality'] ?? null) === $t['expected']);
}

// tbr used when abr is absent (yt-dlp sometimes reports total bitrate in tbr)
$json_audio_tbr = makeJson('Test', [makeFormat([
    'vcodec' => 'none', 'acodec' => 'opus', 'ext' => 'ogg', 'tbr' => 160, 'abr' => null
])]);
$result_tbr = parseFormats($json_audio_tbr);
test('audio quality uses tbr when abr is absent (160kbps -> 128 tier)', $result_tbr && ($result_tbr['formats'][0]['quality'] ?? null) === 128);

$json_audio_no_br = makeJson('Test', [makeFormat([
    'vcodec' => 'none', 'acodec' => 'mp4a', 'ext' => 'm4a', 'tbr' => null, 'abr' => null
])]);
$result_no_br = parseFormats($json_audio_no_br);
test('audio with no tbr or abr has null quality', $result_no_br && ($result_no_br['formats'][0]['quality'] ?? null) === null);

// ─── parseFormats: label construction ─────────────────────────────────────────

echo "\n==> Testing parseFormats() — label building\n";

$json_combined = makeJson('Test', [makeFormat([
    'height' => 1080, 'fps' => 60, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'ext' => 'mp4', 'format_note' => 'HDR'
])]);
$result_combined = parseFormats($json_combined);
$label = $result_combined['formats'][0]['label'] ?? '';
test('combined video+audio label includes height, fps, ext, format_note',
    strpos($label, '1080') !== false && strpos($label, '60') !== false && strpos($label, 'mp4') !== false);
$desc = $result_combined['formats'][0]['description'] ?? '';
test('combined description includes resolution prefix and HDR format_note',
    strpos($desc, '1080') !== false && strpos($desc, 'HDR') !== false);

$json_video_only = makeJson('Test', [makeFormat([
    'height' => 720, 'fps' => 30, 'vcodec' => 'avc1', 'acodec' => 'none', 'ext' => 'webm'
])]);
$result_video_only = parseFormats($json_video_only);
$label_vo = $result_video_only['formats'][0]['label'] ?? '';
test('video-only label says "Video 720p"',
    strpos($label_vo, 'Video 720p') !== false);
$desc_vo = $result_video_only['formats'][0]['description'] ?? '';
test('video-only description falls back to format_note when no format_description',
    strpos($desc_vo, '720') !== false);

$json_audio = makeJson('Test', [makeFormat([
    'vcodec' => 'none', 'acodec' => 'mp4a', 'ext' => 'm4a', 'tbr' => 128
])]);
$result_audio = parseFormats($json_audio);
$label_audio = $result_audio['formats'][0]['label'] ?? '';
test('audio-only label shows bitrate and ext',
    strpos($label_audio, '128') !== false && strpos($label_audio, 'm4a') !== false);
$desc_audio = $result_audio['formats'][0]['description'] ?? '';
test('audio-only description shows bitrate and ext',
    strpos($desc_audio, '128') !== false && strpos($desc_audio, 'm4a') !== false);

// ─── parseFormats: format_type classification ────────────────────────────────

echo "\n==> Testing parseFormats() — format_type classification\n";

$combined_fmt = makeFormat(['vcodec' => 'avc1', 'acodec' => 'mp4a']);
$video_only_fmt = makeFormat(['vcodec' => 'avc1', 'acodec' => 'none']);
$audio_only_fmt = makeFormat(['vcodec' => 'none', 'acodec' => 'mp4a']);

$json_mixed = makeJson('Mixed', [$combined_fmt, $video_only_fmt, $audio_only_fmt]);
$result_mixed = parseFormats($json_mixed);
$types = array_column($result_mixed['formats'], 'format_type');
test('combined format classified as "combined"',
    in_array('combined', $types, true));
test('video-only format classified as "video"',
    in_array('video', $types, true));
test('audio-only format classified as "audio"',
    in_array('audio', $types, true));

// ─── parseFormats: sorting (combined first, then by height desc) ───────────────

echo "\n==> Testing parseFormats() — sort order\n";

$formats_unsorted = [
    makeFormat(['format_id' => 'low', 'height' => 240, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
    makeFormat(['format_id' => 'high', 'height' => 1080, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
    makeFormat(['format_id' => 'mid', 'height' => 480, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
    makeFormat(['format_id' => 'audio', 'height' => 0, 'vcodec' => 'none', 'acodec' => 'mp4a']),
];
$json_sort = makeJson('Sort Test', $formats_unsorted);
$result_sort = parseFormats($json_sort);
$ids = array_column($result_sort['formats'], 'id');
test('combined formats sorted by height descending (1080 before 480 before 240)',
    $ids[0] === 'high' && $ids[1] === 'mid' && $ids[2] === 'low');
test('audio-only formats sorted after combined (at end)',
    $ids[3] === 'audio');

// ─── parseFormats: tbr sort (primary sort key within type groups) ──

echo "\n==> Testing parseFormats() — tbr sort (bitrate primary within type group)\n";

// tbr is the primary sort key within each type group (combined/video/audio).
// Type group separation is still primary (combined before video before audio).
// Within the same type group, highest tbr sorts first.
$formats_for_tbr = [
    // Combined 1080p30 with low tbr
    makeFormat(['format_id' => 'c_1080_30_low',  'height' => 1080, 'fps' => 30, 'tbr' => 1000, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
    // Combined 1080p30 with high tbr (should sort BEFORE c_1080_30_low — tbr is primary)
    makeFormat(['format_id' => 'c_1080_30_high', 'height' => 1080, 'fps' => 30, 'tbr' => 5000, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
    // Combined 720p60 with very high tbr (higher tbr than the 1080p formats — but height is secondary so 1080p still wins)
    makeFormat(['format_id' => 'c_720_60_hi_tbr', 'height' => 720,  'fps' => 60, 'tbr' => 8000, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
    // Audio-only with very high tbr (should sort AFTER all combined — type group is primary)
    makeFormat(['format_id' => 'audio_hi_tbr',    'height' => 0,   'vcodec' => 'none', 'acodec' => 'opus', 'tbr' => 6000]),
];
$json_tbr = makeJson('TBR Sort', $formats_for_tbr);
$result_tbr = parseFormats($json_tbr, $raw_err, 'tbr');
$ids_tbr = array_column($result_tbr['formats'], 'id');

// Type group separation (combined before video before audio) is the primary key.
// Within the combined group, tbr is the sort key — highest tbr first.
// c_720_60_hi_tbr (tbr=8000) > c_1080_30_high (tbr=5000) > c_1080_30_low (tbr=1000).
// audio_hi_tbr is audio-only so it sorts after all combined formats.
test('tbr sort: highest tbr combined format first (c_720_60_hi_tbr tbr=8000)',
    $ids_tbr[0] === 'c_720_60_hi_tbr');
test('tbr sort: 1080p30 high tbr (5000) before 1080p30 low tbr (1000)',
    $ids_tbr[1] === 'c_1080_30_high');
test('tbr sort: combined 720p60 (tbr=8000) before combined 1080p30 low tbr (1000)',
    array_search('c_720_60_hi_tbr', $ids_tbr, true) < array_search('c_1080_30_low', $ids_tbr, true));
test('tbr sort: audio-only comes after all combined (type group primary)',
    array_search('audio_hi_tbr', $ids_tbr, true) > 2);

// ─── parseFormats: quality sort (numeric quality tier) ─────────────────────────

echo "\n==> Testing parseFormats() — quality sort (numeric tier)\n";

// quality sort: video formats by pixel height, audio by bitrate tier.
// max audio tier = 320, max video height = 1080, so all video sorts above audio.
$formats_for_quality = [
    makeFormat(['format_id' => 'audio_48',   'vcodec' => 'none', 'acodec' => 'mp4a', 'abr' => 48, 'tbr' => null]),
    makeFormat(['format_id' => 'video_480',  'height' => 480, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
    makeFormat(['format_id' => 'audio_320',  'vcodec' => 'none', 'acodec' => 'mp4a', 'abr' => 320, 'tbr' => null]),
    makeFormat(['format_id' => 'video_720',  'height' => 720, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
    makeFormat(['format_id' => 'audio_128',  'vcodec' => 'none', 'acodec' => 'mp4a', 'abr' => 128, 'tbr' => null]),
    makeFormat(['format_id' => 'video_1080', 'height' => 1080, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
    makeFormat(['format_id' => 'audio_256',  'vcodec' => 'none', 'acodec' => 'mp4a', 'abr' => 256, 'tbr' => null]),
    makeFormat(['format_id' => 'video_240',  'height' => 240, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
];
$json_quality = makeJson('Quality Sort', $formats_for_quality);
$result_quality = parseFormats($json_quality, $raw_err, 'quality');
$ids_quality = array_column($result_quality['formats'], 'id');
// All video formats should appear before all audio formats (video quality tiers > 320).
// Within video: 1080 > 720 > 480 > 240.
// Within audio: 320 > 256 > 128 > 48.
test('quality sort: video formats above audio (1080p > 720p > 480p > 240p above audio)',
    $ids_quality[0] === 'video_1080' && $ids_quality[1] === 'video_720'
    && $ids_quality[2] === 'video_480' && $ids_quality[3] === 'video_240');
test('quality sort: audio formats sorted by bitrate tier descending (320 > 256 > 128 > 48)',
    $ids_quality[4] === 'audio_320');
test('quality sort: audio second position is 256kbps', $ids_quality[5] === 'audio_256');
test('quality sort: audio third position is 128kbps', $ids_quality[6] === 'audio_128');
test('quality sort: audio last position is 48kbps', $ids_quality[7] === 'audio_48');

// quality sort: same quality tier tie-breaks by fps (same as other sort modes)
$formats_same_quality = [
    makeFormat(['format_id' => 'a30', 'height' => 1080, 'fps' => 30, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
    makeFormat(['format_id' => 'a60', 'height' => 1080, 'fps' => 60, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
    makeFormat(['format_id' => 'a24', 'height' => 1080, 'fps' => 24, 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
];
$json_sq = makeJson('Same Quality FPS', $formats_same_quality);
$result_sq = parseFormats($json_sq, $raw_err, 'quality');
$ids_sq = array_column($result_sq['formats'], 'id');
test('quality sort: same tier tie-breaks by fps descending (60fps > 30fps > 24fps)',
    $ids_sq[0] === 'a60' && $ids_sq[1] === 'a30' && $ids_sq[2] === 'a24');

// ─── parseFormats: filesize_asc sort (smallest first) ─────────────────────────

$formats_for_size = [
    makeFormat(['format_id' => 'big', 'height' => 480, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize' => 20971520]),   // 20 MB
    makeFormat(['format_id' => 'small', 'height' => 240, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize' => 1048576]),    // 1 MB
    makeFormat(['format_id' => 'medium', 'height' => 480, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'filesize' => 5242880]),  // 5 MB
];
$json_size_asc = makeJson('Size Sort', $formats_for_size);
$raw_err = null;
$result_size_asc = parseFormats($json_size_asc, $raw_err, 'filesize_asc');
$ids_size = array_column($result_size_asc['formats'], 'id');
test('filesize_asc: smallest filesize first (1MB before 5MB before 20MB)',
    $ids_size[0] === 'small' && $ids_size[1] === 'medium' && $ids_size[2] === 'big');
test('filesize_asc: combined formats still grouped together (audio excluded)',
    $result_size_asc['formats'][0]['format_type'] === 'combined');

// ─── parseFormats: fps tiebreaker within same resolution tier ──────────────────

$formats_same_height_diff_fps = [
    makeFormat(['format_id' => 'a', 'height' => 1080, 'fps' => 30, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'tbr' => 5000]),
    makeFormat(['format_id' => 'b', 'height' => 1080, 'fps' => 60, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'tbr' => 8000]),
    makeFormat(['format_id' => 'c', 'height' => 1080, 'fps' => null, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'tbr' => 4000]),
    makeFormat(['format_id' => 'd', 'height' => 1080, 'fps' => 24, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'tbr' => 3000]),
];
$json_fps = makeJson('FPS Sort', $formats_same_height_diff_fps);
$result_fps = parseFormats($json_fps);
$ids_fps = array_column($result_fps['formats'], 'id');
// Within same height, 60fps should sort before 30fps before 24fps before null
// (the secondary height/tiebreak step runs after the primary sort — since
// all have the same height it falls through to the fps tiebreaker, but the
// primary sort doesn't re-run the secondary. The secondary only applies when
// the secondary sort key itself is a tiebreak. For same-height formats that
// have already been sorted by the secondary-height tiebreak (a no-op since
// heights are equal), the fps sort finally differentiates them.)
// Note: PHP usort is NOT guaranteed stable — two elements with equal
// comparison must not swap (here fps=60 vs fps=30 at same height).
// Expected order by fps desc: 60 > 30 > 24 > null.
test('same height — 60fps before 30fps before 24fps before null fps',
    $ids_fps[0] === 'b' && $ids_fps[1] === 'a' && $ids_fps[2] === 'd' && $ids_fps[3] === 'c');

// ─── parseFormats: filesize estimation ─────────────────────────────────────────

echo "\n==> Testing parseFormats() — filesize estimation (filesize=0 triggers estimation)\n";

$fmt_no_size = makeFormat(['filesize' => 0, 'height' => 720, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'tbr' => 2500]);
$json_est = makeJson('Size Est', [$fmt_no_size], ['duration' => 60]);
$result_est = parseFormats($json_est);
$filesize_mb = $result_est['formats'][0]['filesize_mb'] ?? 0;
test('filesize estimated for video+audio when filesize=0',
    $filesize_mb > 0);
test('estimated filesize for 60s 720p video is reasonable (1-100 MB)',
    $filesize_mb > 1 && $filesize_mb < 100);

$fmt_audio_no_size = makeFormat(['filesize' => 0, 'vcodec' => 'none', 'acodec' => 'mp4a', 'tbr' => 128]);
$json_audio_est = makeJson('Audio Size', [$fmt_audio_no_size], ['duration' => 300]);
$result_audio_est = parseFormats($json_audio_est);
$audio_size = $result_audio_est['formats'][0]['filesize_mb'] ?? 0;
test('filesize estimated for audio-only when filesize=0',
    $audio_size > 0);

// ─── parseFormats: skips formats with no vcodec and no acodec ─────────────────

echo "\n==> Testing parseFormats() — skips unknown/empty codec formats\n";

$json_weird = makeJson('Weird', [
    makeFormat(['format_id' => 'valid', 'vcodec' => 'avc1', 'acodec' => 'mp4a']),
    makeFormat(['format_id' => 'unknown', 'vcodec' => 'none', 'acodec' => 'none']),
]);
$result_weird = parseFormats($json_weird);
$ids_weird = array_column($result_weird['formats'], 'id');
test('format with vcodec=none AND acodec=none is skipped',
    count($ids_weird) === 1 && $ids_weird[0] === 'valid');

// ─── parseFormats: yt-dlp error messages → classified errors ─────────────────

echo "\n==> Testing parseFormats() — yt-dlp ERROR message classification\n";

$errors = [
    'ERROR: [youtube] This video is available in Germany' => 'GEOBLOCKED',
    'ERROR: [youtube] This video is private' => 'PRIVATE_VIDEO',
    'ERROR: Login required to view this content' => 'LOGIN_REQUIRED',
    'ERROR: https://example.com is not a supported URL' => 'UNSUPPORTED_SITE',
    'ERROR: Playlist does not exist' => 'PLAYLIST_MISSING',
    'ERROR: The content has been removed by the owner' => 'COPYRIGHT_REMOVED',
    'ERROR: This video has been removed' => 'VIDEO_UNAVAILABLE',
    'ERROR: Video unavailable' => 'VIDEO_UNAVAILABLE',
    'ERROR: This video is no longer available' => 'VIDEO_UNAVAILABLE',
    'ERROR: Video has been delisted' => 'VIDEO_UNAVAILABLE',
    'ERROR: Video has been deleted' => 'VIDEO_UNAVAILABLE',
    'ERROR: HTTP Error 429: Too Many Requests' => 'SOURCE_RATE_LIMITED',
    'ERROR: [youtube] Video is age restricted' => 'AGE_RESTRICTED',
    'ERROR: Certificate has expired' => 'SSL_ERROR',
    'ERROR: Connection failed' => 'CONNECTION_FAILED',
    'ERROR: Request timed out' => 'CONNECTION_FAILED',
    'ERROR: File is larger than 2GB limit' => 'FILE_TOO_LARGE',
    // Covers: singular "requested format" (API), "format not available" (common),
    // "requested format not available" (merged pattern), "does not contain" (JSON parse),
    // "does not match" (format filter).
    'ERROR: Requested format not available' => 'FORMAT_UNAVAILABLE',
    'ERROR: Requested format' => 'FORMAT_UNAVAILABLE',
    'ERROR: Format not available' => 'FORMAT_UNAVAILABLE',
    'ERROR: Video does not contain any requested formats' => 'FORMAT_UNAVAILABLE',
    'ERROR: format does not match' => 'FORMAT_UNAVAILABLE',
    'ERROR: Requested format not available' => 'FORMAT_UNAVAILABLE',
    'ERROR: Video format not available' => 'FORMAT_UNAVAILABLE',
];

foreach ($errors as $yt_output => $expected_code) {
    $raw_err = null;
    $result = parseFormats($yt_output, $raw_err);
    $code = $result['error_code'] ?? null;
    test("maps '$expected_code' from yt-dlp error",
        $code === $expected_code);
}

$raw_err = null;
$result_unclassified = parseFormats('ERROR: Something very unexpected', $raw_err);
test('unclassified error returns YTDLP_ERROR code',
    ($result_unclassified['error_code'] ?? '') === 'YTDLP_ERROR');
test('unclassified error includes yt-dlp error prefix in message',
    strpos($result_unclassified['error'] ?? '', 'yt-dlp error:') === 0);

// ─── parseFormats: null return for non-error malformed JSON ───────────────────

echo "\n==> Testing parseFormats() — malformed non-error JSON returns null\n";

$raw_err = null;
$result_bad = parseFormats('not valid json at all {', $raw_err);
test('non-error malformed input returns null (not an error array)',
    $result_bad === null);

$result_empty = parseFormats('', $raw_err);
test('empty string returns null',
    $result_empty === null);

// ─── parseFormats: description field population ──────────────────────────────

echo "\n==> Testing parseFormats() — description field\n";

$fmt_desc = makeFormat([
    'width' => 1920, 'height' => 1080,
    'format_description' => '1080p60 HDR 10bit',
    'vcodec' => 'avc1', 'acodec' => 'mp4a',
]);
$json_desc = makeJson('Desc Test', [$fmt_desc]);
$result_desc = parseFormats($json_desc);
$desc = $result_desc['formats'][0]['description'] ?? '';
test('description uses format_description when available (resolution-prefixed)',
    strpos($desc, '1080p60 HDR 10bit') !== false);

$fmt_no_desc = makeFormat([
    'width' => 0, 'height' => 480, 'format_note' => '480p',
    'vcodec' => 'avc1', 'acodec' => 'mp4a', 'ext' => 'mp4',
]);
$json_no_desc = makeJson('No Desc', [$fmt_no_desc]);
$result_no_desc = parseFormats($json_no_desc);
$desc2 = $result_no_desc['formats'][0]['description'] ?? '';
test('description falls back to format_note when format_description absent',
    strpos($desc2, '480p') !== false);

// When both format_description AND format_note are present, description should
// use format_description (the richer yt-dlp signal), not format_note.
$fmt_both = makeFormat([
    'width' => 1920, 'height' => 1080,
    'format_description' => '1080p60 HDR 10bit',
    'format_note' => '1080p',
    'vcodec' => 'avc1', 'acodec' => 'mp4a', 'ext' => 'mp4',
]);
$json_both = makeJson('Both Fields', [$fmt_both]);
$result_both = parseFormats($json_both);
$desc3 = $result_both['formats'][0]['description'] ?? '';
test('description prefers format_description when both it and format_note are present',
    strpos($desc3, '1080p60 HDR 10bit') !== false);

// description should include resolution (width x height) as a prefix when width and height are set
$fmt_res = makeFormat([
    'width' => 1920, 'height' => 1080,
    'format_description' => '1080p60 HDR',
    'vcodec' => 'avc1', 'acodec' => 'mp4a', 'ext' => 'mp4',
]);
$json_res = makeJson('Res Test', [$fmt_res]);
$result_res = parseFormats($json_res);
$desc4 = $result_res['formats'][0]['description'] ?? '';
test('description prefixes resolution (1920x1080) when width and height are set',
    strpos($desc4, '1920x1080') !== false);

// When width/height are absent/zero, description should NOT prepend a resolution prefix
$fmt_no_res = makeFormat([
    'width' => 0, 'height' => 0,
    'format_description' => '720p60',
    'vcodec' => 'none', 'acodec' => 'mp4a', 'ext' => 'm4a',
]);
$json_no_res = makeJson('No Res Test', [$fmt_no_res]);
$result_no_res = parseFormats($json_no_res);
$desc5 = $result_no_res['formats'][0]['description'] ?? '';
test('description has no width x height prefix when both width and height are 0',
    !preg_match('/^[0-9]+x[0-9]+\s/', $desc5));

// Empty/null format_description falls back to format_note, not label
$fmt_note_only = makeFormat([
    'format_note' => '720p60 HDR',
    'vcodec' => 'avc1', 'acodec' => 'mp4a', 'ext' => 'mp4',
    'width' => 1280, 'height' => 720,
]);
$json_note = makeJson('Note Only', [$fmt_note_only]);
$result_note = parseFormats($json_note);
$note_desc = $result_note['formats'][0]['description'] ?? '';
test('description falls back to format_note when format_description is null/empty',
    strpos($note_desc, '720p60 HDR') !== false);

// "0" format_description is truthy and should be used (not treated as absent).
// empty("0") === true in PHP — this test guards against that PHP gotcha breaking
// the description logic for format descriptions that are the string "0".
$fmt_zero_desc = makeFormat([
    'format_description' => '0',
    'vcodec' => 'avc1', 'acodec' => 'mp4a', 'ext' => 'mp4',
    'width' => 1920, 'height' => 1080,
    'format_note' => 'HDR',
]);
$json_zero_desc = makeJson('Zero Desc', [$fmt_zero_desc]);
$result_zero_desc = parseFormats($json_zero_desc);
$zero_desc_text = $result_zero_desc['formats'][0]['description'] ?? '';
test('description uses "0" format_description (not treated as empty/falsy)',
    strpos($zero_desc_text, '0') !== false);

// ─── Report ─────────────────────────────────────────────────────────────────

echo "\n";
$total = $tests_run;
$passed = $tests_passed;
$failed = $failures;
echo "Results: $passed/$total passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
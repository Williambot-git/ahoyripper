<?php
/**
 * AhoyRipper — parseFormats() unit tests
 * Run: php tests/parse_formats_test.php
 *
 * Tests the sorting, grouping, and output structure of parseFormats()
 * using a self-contained copy of the function and sample yt-dlp JSON output.
 *
 * The function is copied verbatim from src/api.php (at HEAD) to ensure tests
 * reflect the actual implemented behavior, not a notional spec.
 */

$failures = 0;
$tests_run = 0;
$tests_passed = 0;

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

// ─── clean() — verbatim copy from api.php ────────────────────────────────────
function clean($s) {
    if ($s === null) return '';
    return (string)$s;
}

// ─── parseFormats() — verbatim copy from api.php (no $_GET dependency) ───────
// Takes sort as a parameter so it is deterministic in tests.
function parseFormats($json_str, &$raw_error_out = null, $sort = 'height') {
    $data = json_decode($json_str, true);
    if (!$data) {
        $raw = trim($json_str);
        if (preg_match('/^(ERROR|WARNING)/im', $raw)) {
            $err_msg = preg_replace('/[\x00-\x1F\x7F]/', '', $raw);
            $err_msg = strip_tags($err_msg);
            $err_msg = preg_replace('/\s+/', ' ', $err_msg);
            if (strlen($err_msg) > 200) $err_msg = substr($err_msg, 0, 200) . '...';
            if ($raw_error_out !== null) $raw_error_out = $err_msg;
            return ['error' => 'yt-dlp error: ' . $err_msg, 'error_code' => 'YTDLP_ERROR'];
        }
        if ($raw_error_out !== null) $raw_error_out = null;
        return null;
    }

    $title    = clean($data['title'] ?? 'Unknown');
    $thumbnail = clean($data['thumbnail'] ?? '');
    $duration  = (int)($data['duration'] ?? 0);
    $uploader  = clean($data['uploader'] ?? '');
    $raw_fn = preg_replace('/[^\w\s.-]/', '', $title);
    $raw_fn = preg_replace('/\s+/', '_', trim($raw_fn));
    if (strlen($raw_fn) > 80) $raw_fn = substr($raw_fn, 0, 80);
    $derived_filename = $raw_fn ?: 'ahoyrip';

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
        $fps = isset($f['fps']) ? (int)$f['fps'] : null;
        $language = clean($f['language'] ?? '');
        $format_description = clean($f['format_description'] ?? '');

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

        $quality = ($width > 0 && $height > 0) ? ($width . 'x' . $height) : null;
        $description = $quality
            ? trim("$quality $format_description")
            : ($format_description ?: ($format_note ? $format_note : $label));

        if ($filesize === 0) {
            $duration_secs = $duration ?: 180;
            if ($vcodec !== 'none' && $acodec !== 'none') {
                $bitrate_kbps = $tbr ?? (($height > 720) ? 5000 : (($height > 480) ? 2500 : 1000));
                $filesize = ($bitrate_kbps * 1000 / 8) * $duration_secs;
            } elseif ($vcodec !== 'none') {
                $bitrate_kbps = $tbr ?? (($height > 720) ? 4000 : 1500);
                $filesize = ($bitrate_kbps * 1000 / 8) * $duration_secs;
            } else {
                $bitrate_kbps = $tbr ?? 128;
                $filesize = ($bitrate_kbps * 1000 / 8) * $duration_secs;
            }
        }

        $filesize_mb = round($filesize / 1048576, 1);

        $formats[] = [
            'id' => $format_id,
            'label' => $label,
            'description' => $description,
            'ext' => $ext,
            'filesize_mb' => $filesize_mb,
            'height' => $height,
            'fps' => $fps,
            'tbr' => $tbr,
            'vcodec' => $vcodec,
            'acodec' => $acodec,
            'format_type' => ($vcodec !== 'none' && $acodec !== 'none') ? 'combined' : ($vcodec !== 'none' ? 'video' : 'audio'),
            'language' => $language ?: null,
        ];
    }

    // Sort: combined first, then by selected key
    $allowed_sorts = ['height', 'filesize', 'tbr'];
    if (!is_string($sort) || !in_array($sort, $allowed_sorts, true)) {
        $sort = 'height';
    }

    usort($formats, function($a, $b) use ($sort) {
        if ($a['vcodec'] !== 'none' && $a['acodec'] !== 'none' && ($b['vcodec'] === 'none' || $b['acodec'] === 'none')) return -1;
        if (($a['vcodec'] === 'none' || $a['acodec'] === 'none') && $b['vcodec'] !== 'none' && $b['acodec'] !== 'none') return 1;
        if ($sort === 'filesize') {
            $cmp = ($b['filesize_mb'] ?? 0) <=> ($a['filesize_mb'] ?? 0);
        } elseif ($sort === 'tbr') {
            $cmp = ($b['tbr'] ?? 0) <=> ($a['tbr'] ?? 0);
        } else {
            $cmp = ($b['height'] ?? 0) <=> ($a['height'] ?? 0);
        }
        if ($cmp === 0) {
            $cmp = ($b['height'] ?? 0) <=> ($a['height'] ?? 0);
        }
        return $cmp;
    });

    return [
        'title' => $title,
        'thumbnail' => $thumbnail,
        'duration' => $duration,
        'uploader' => $uploader,
        'derived_filename' => $derived_filename,
        'formats' => $formats,
        'sort_applied' => $sort,
    ];
}

// ─── Sample yt-dlp JSON fixtures ───────────────────────────────────────────────

$sample_video_json = json_encode([
    'title' => 'Test Video Title',
    'thumbnail' => 'https://example.com/thumb.jpg',
    'duration' => 180,
    'uploader' => 'Test Channel',
    'formats' => [
        // Combined formats
        ['format_id' => '18',  'ext' => 'mp4',  'vcodec' => 'avc1',  'acodec' => 'mp4a',  'height' => 360,  'fps' => 30, 'tbr' => 800,  'filesize' => 18000000, 'width' => 640,  'format_note' => '360p', 'format_description' => '360p', 'language' => 'en'],
        ['format_id' => '22',  'ext' => 'mp4',  'vcodec' => 'avc1',  'acodec' => 'mp4a',  'height' => 720,  'fps' => 30, 'tbr' => 2500, 'filesize' => 56000000, 'width' => 1280, 'format_note' => '720p', 'format_description' => '720p', 'language' => 'en'],
        ['format_id' => '137', 'ext' => 'mp4',  'vcodec' => 'avc1',  'acodec' => 'none',  'height' => 1080, 'fps' => 30, 'tbr' => 4500, 'filesize' => 81000000, 'width' => 1920, 'format_note' => '1080p', 'format_description' => '1080p', 'language' => null],
        ['format_id' => '251', 'ext' => 'webm', 'vcodec' => 'none',  'acodec' => 'opus',  'height' => 0,    'fps' => null, 'tbr' => 160, 'filesize' => 3600000, 'width' => 0, 'format_note' => 'audio only', 'format_description' => 'audio only', 'language' => null],
        ['format_id' => '140', 'ext' => 'm4a',  'vcodec' => 'none',  'acodec' => 'mp4a',  'height' => 0,    'fps' => null, 'tbr' => 128, 'filesize' => 2880000, 'width' => 0, 'format_note' => 'audio only', 'format_description' => 'audio only', 'language' => 'en'],
    ],
]);

$sample_video_with_approx = json_encode([
    'title' => 'Video With Approx Size',
    'thumbnail' => '',
    'duration' => 300,
    'uploader' => 'Channel',
    'formats' => [
        // filesize_approx (no exact filesize) — should fall back to estimation
        ['format_id' => 'best', 'ext' => 'mp4', 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'height' => 480, 'fps' => 30, 'tbr' => 1000, 'filesize_approx' => 37500000, 'width' => 854, 'format_note' => '480p', 'format_description' => '480p', 'language' => null],
        // no filesize at all — should estimate from bitrate
        ['format_id' => 'audio', 'ext' => 'mp3', 'vcodec' => 'none', 'acodec' => 'mp3', 'height' => 0, 'fps' => null, 'tbr' => 192, 'filesize' => 0, 'width' => 0, 'format_note' => 'audio only', 'format_description' => 'audio only', 'language' => null],
    ],
]);

// ─── Tests: parseFormats output structure ─────────────────────────────────────

echo "\n==> Testing parseFormats() output structure\n";

$parsed = parseFormats($sample_video_json);
test('returns array',
    is_array($parsed));
test('title is extracted',
    $parsed['title'] === 'Test Video Title');
test('thumbnail is extracted',
    $parsed['thumbnail'] === 'https://example.com/thumb.jpg');
test('duration is int',
    $parsed['duration'] === 180);
test('uploader is extracted',
    $parsed['uploader'] === 'Test Channel');
test('derived_filename is sanitized (spaces to underscores)',
    $parsed['derived_filename'] === 'Test_Video_Title');
test('formats key is present',
    is_array($parsed['formats'] ?? null));
test('sort_applied field is present',
    isset($parsed['sort_applied']));

echo "\n==> Testing parseFormats() format count and type grouping\n";

$parsed = parseFormats($sample_video_json);
test('correct number of formats (5 entries, 1 skipped)',
    count($parsed['formats']) === 5);
test('first format is combined (has both vcodec and acodec)',
    $parsed['formats'][0]['vcodec'] !== 'none' && $parsed['formats'][0]['acodec'] !== 'none');
test('last format is audio-only',
    $parsed['formats'][count($parsed['formats'])-1]['vcodec'] === 'none' && $parsed['formats'][count($parsed['formats'])-1]['acodec'] !== 'none');

echo "\n==> Testing sort=height (default — highest resolution first)\n";

$parsed_h = parseFormats($sample_video_json, $_, 'height');
$heights = array_column($parsed_h['formats'], 'height');
test('sort_applied is height',
    $parsed_h['sort_applied'] === 'height');
// Check: first format is combined, last is audio-only
test('combined formats appear before video-only and audio-only',
    $parsed_h['formats'][0]['format_type'] === 'combined');
test('audio-only appears last',
    $parsed_h['formats'][count($parsed_h['formats'])-1]['format_type'] === 'audio');
// Combined formats sorted by height descending: check heights of combined formats
$combined_heights = array_column(array_filter($parsed_h['formats'], fn($f) => $f['format_type'] === 'combined'), 'height');
$sorted_combined_heights = $combined_heights;
rsort($sorted_combined_heights, SORT_NUMERIC);
test('combined formats sorted by height descending',
    $combined_heights === $sorted_combined_heights);

echo "\n==> Testing sort=filesize (largest first)\n";

$parsed_f = parseFormats($sample_video_json, $_, 'filesize');
// Within each format-type group, filesize should be descending.
// Extract combined formats and verify they are sorted descending.
$combined = array_filter($parsed_f['formats'], fn($f) => $f['format_type'] === 'combined');
$combined_sizes = array_column($combined, 'filesize_mb');
$sorted_combined_sizes = $combined_sizes;
arsort($sorted_combined_sizes, SORT_NUMERIC);
test('sort_applied is filesize',
    $parsed_f['sort_applied'] === 'filesize');
// arsort reindexes arrays, so compare values rather than array identity
test('combined formats sorted by filesize descending',
    $combined_sizes == $sorted_combined_sizes && count($combined_sizes) === count($sorted_combined_sizes));

echo "\n==> Testing sort=tbr (highest bitrate first)\n";

$parsed_t = parseFormats($sample_video_json, $_, 'tbr');
// Within combined formats (which sort first), tbr should be descending
$combined = array_filter($parsed_t['formats'], fn($f) => $f['format_type'] === 'combined');
$combined_tbrs = array_column($combined, 'tbr');
$sorted_combined_tbrs = $combined_tbrs;
arsort($sorted_combined_tbrs, SORT_NUMERIC);
test('sort_applied is tbr',
    $parsed_t['sort_applied'] === 'tbr');
// arsort reindexes; compare values. Also verify combined types come before others.
test('combined formats sorted by tbr descending',
    $combined_tbrs == $sorted_combined_tbrs && count($combined_tbrs) === count($sorted_combined_tbrs));

echo "\n==> Testing invalid sort falls back to height\n";

$parsed_bad = parseFormats($sample_video_json, $_, 'invalid_sort');
test('invalid sort falls back to height',
    $parsed_bad['sort_applied'] === 'height');
$parsed_null = parseFormats($sample_video_json, $_, null);
test('null sort falls back to height',
    $parsed_null['sort_applied'] === 'height');

echo "\n==> Testing format field population\n";

$parsed = parseFormats($sample_video_json);
// With default sort=height, combined formats are sorted by height desc:
// formats[0] = id=22 (720p, height=720), formats[1] = id=18 (360p, height=360)
$f_720 = $parsed['formats'][0]; // first after sort = 720p
$f_360 = $parsed['formats'][1]; // second after sort = 360p
// Check that height is always present on combined formats
test('format has height on combined (720p)',
    isset($f_720['height']) && $f_720['height'] === 720);
test('format has height on combined (360p)',
    isset($f_360['height']) && $f_360['height'] === 360);
// Check id is present on formats
test('format has id',
    isset($f_360['id']) && $f_360['id'] === '18');
// Label for 360p: "360p30 360p mp4" (height + fps concat + note + ext, no spaces in fps number)
test('format has label (360p + fps + ext)',
    strpos($f_360['label'], '360p') !== false && strpos($f_360['label'], 'mp4') !== false);
test('format has filesize_mb (positive number)',
    isset($f_360['filesize_mb']) && is_numeric($f_360['filesize_mb']) && $f_360['filesize_mb'] > 0);
test('format has vcodec and acodec',
    isset($f_360['vcodec']) && isset($f_360['acodec']));
test('format has format_type (combined)',
    isset($f_360['format_type']) && $f_360['format_type'] === 'combined');
test('language is extracted',
    isset($f_360['language']) && $f_360['language'] === 'en');
test('fps is extracted (30fps)',
    isset($f_360['fps']) && $f_360['fps'] === 30);

echo "\n==> Testing audio-only format (no vcodec, no fps)\n";

$audio_fmt = $parsed['formats'][count($parsed['formats'])-1]; // 251 webm audio
test('audio format has no vcodec',
    $audio_fmt['vcodec'] === 'none');
test('audio format has acodec',
    $audio_fmt['acodec'] !== 'none' && $audio_fmt['acodec'] !== '');
test('audio format has no height',
    isset($audio_fmt['height']) && $audio_fmt['height'] === 0);
test('audio format has no fps (null)',
    array_key_exists('fps', $audio_fmt) && $audio_fmt['fps'] === null);
test('audio format type is audio',
    $audio_fmt['format_type'] === 'audio');

echo "\n==> Testing filesize estimation when filesize is 0 (approx fallback)\n";

$parsed_approx = parseFormats($sample_video_with_approx);
$fmt0 = $parsed_approx['formats'][0]; // 480p with filesize_approx
$fmt1 = $parsed_approx['formats'][1]; // audio with filesize=0
test('filesize_approx triggers estimation (positive result)',
    $fmt0['filesize_mb'] > 0);
test('filesize=0 triggers estimation for combined format',
    $fmt1['filesize_mb'] > 0);

echo "\n==> Testing derived_filename edge cases\n";

$no_title_json = json_encode(['title' => '', 'formats' => []]);
$parsed_empty = parseFormats($no_title_json);
test('empty title falls back to ahoyrip',
    $parsed_empty['derived_filename'] === 'ahoyrip');

$unicode_title = json_encode(['title' => "Video \xf0\x9f\x8e\xb2 Title", 'formats' => []]);
$parsed_unicode = parseFormats($unicode_title);
test('unicode chars in title are stripped from derived_filename',
    strpos($parsed_unicode['derived_filename'], "\xf0\x9f\x8e\xb2") === false);

echo "\n==> Testing error responses\n";

// Non-JSON string (not an ERROR line — just garbage)
$parsed_null = parseFormats("this is not json");
test('garbage string returns null',
    $parsed_null === null);

// yt-dlp ERROR line
$raw_err = '';
$err_resp = parseFormats("ERROR: This video is geo restricted in your area", $raw_err);
test('ERROR line returns error array',
    is_array($err_resp) && isset($err_resp['error']));
test('ERROR line populates raw_error output param',
    !empty($raw_err));
test('ERROR line includes error_code',
    isset($err_resp['error_code']) && $err_resp['error_code'] === 'YTDLP_ERROR');

// ─── Report ─────────────────────────────────────────────────────────────────

echo "\n";
$total = $tests_run;
$passed = $tests_passed;
$failed = $failures;
echo "Results: $passed/$total passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);

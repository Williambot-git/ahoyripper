<?php
/**
 * AhoyRipper - API Endpoint
 * Handles: info extraction, format listing, and download serving
 */

// CORS headers for API access
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Content-Security-Policy: default-src \'none\'; script-src \'none\'; style-src \'none\'; img-src \'none\'; connect-src \'none\'; font-src \'none\'; frame-src \'none\'');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Request-ID: ' . bin2hex(random_bytes(8)));

// Rate limiting - atomic IP-based gate using flock
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_file = '/tmp/ahoyrip_rate_' . md5($ip);
$rate_limit = 30; // requests per minute
$rate_window = 60;

$fp = fopen($rate_file, 'c+');
if (!$fp) {
    http_response_code(503);
    echo json_encode(['error' => 'Service temporarily unavailable.']); // @codingStandardsIgnoreLine
    exit;
}
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(503);
    echo json_encode(['error' => 'Service temporarily unavailable.']); // @codingStandardsIgnoreLine
    exit;
}

$data = ['t' => time(), 'c' => 0];
$raw = fread($fp, 4096);
if ($raw) {
    $decoded = json_decode($raw, true);
    if ($decoded && is_array($decoded)) {
        $data = $decoded;
    }
}

if (time() - $data['t'] < $rate_window) {
    if ($data['c'] >= $rate_limit) {
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(429);
        header('Retry-After: 30');
        echo json_encode(['error' => 'Too many requests. Slow down.']); // @codingStandardsIgnoreLine
        exit;
    }
    $data['c']++;
} else {
    $data = ['t' => time(), 'c' => 1];
}

// Write back atomically
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($data));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

// Periodic cleanup of stale rate files (1% chance per request)
if (mt_rand(1, 100) === 1) {
    foreach (glob('/tmp/ahoyrip_rate_*') as $f) {
        $d = @json_decode(@file_get_contents($f), true);
        if (!$d || !is_array($d) || (time() - ($d['t'] ?? 0)) > $rate_window * 2) {
            @unlink($f);
        }
    }
}

// Only allow safe characters in URL
function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && preg_match('/^https?:\/\//', $url);
}

// Run yt-dlp with timeout and capture output
// $timeout = max seconds for the whole process; 0 = no limit
function runYtdlp($args, &$stdout, &$stderr, &$exit, $timeout = 0) {
    $cmd = '/usr/local/bin/yt-dlp ' . $args;
    $desc = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = null;
    $proc = proc_open($cmd . ' 2>&1', $desc, $pipes, '/tmp', [], ['bypass_shell' => true]);

    if (!$proc) return false;

    stream_set_timeout($pipes[1], 30);
    stream_set_timeout($pipes[2], 30);

    $stdout = '';
    $stderr = '';
    $start = time();

    while (!feof($pipes[1]) || !feof($pipes[2])) {
        if ($timeout > 0 && (time() - $start) > $timeout) {
            proc_terminate($proc, 9);
            $stderr .= "\nProcess timed out after {$timeout}s";
            $exit = -1;
            foreach ($pipes as $p) { if ($p) fclose($p); }
            proc_close($proc);
            return false;
        }
        $read = [];
        if (!feof($pipes[1])) $read[] = $pipes[1];
        if (!feof($pipes[2])) $read[] = $pipes[2];
        if (empty($read)) break;
        $w = $e = null;
        $changed = @stream_select($read, $w, $e, 1, 0);
        if ($changed === false || $changed === 0) {
            usleep(100000);
            continue;
        }
        foreach ($read as $p) {
            if ($p === $pipes[1]) {
                $s = fread($p, 8192);
                if ($s === false || $s === '') { feof($pipes[1]); continue; }
                $stdout .= $s;
            } elseif ($p === $pipes[2]) {
                $s = fread($p, 8192);
                if ($s === false || $s === '') { feof($pipes[2]); continue; }
                $stderr .= $s;
            }
        }
    }

    foreach ($pipes as $p) { if ($p) fclose($p); }
    $exit = proc_close($proc);
    return true;
}

// Sanitize string for JSON output
function clean($s) {
    if ($s === null) return '';
    // No htmlspecialchars — API outputs JSON, not HTML.
    // Type coercion to string is sufficient.
    return (string)$s;
}

// Parse yt-dlp output to extract formats
function parseFormats($json_str) {
    $data = json_decode($json_str, true);
    if (!$data) return null;

    $title = clean($data['title'] ?? 'Unknown');
    $thumbnail = clean($data['thumbnail'] ?? '');
    $duration = (int)($data['duration'] ?? 0);
    $uploader = clean($data['uploader'] ?? '');

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

        // Build label
        $label = '';
        if ($vcodec !== 'none' && $acodec !== 'none') {
            // Video+audio combined
            if ($height > 0) {
                $label = "{$height}p";
                if ($fps) $label .= "{$fps}";
                if ($format_note) $label .= " {$format_note}";
                $label .= " {$ext}";
            } else {
                $label = strtoupper($ext);
            }
        } elseif ($vcodec !== 'none') {
            // Video only
            if ($height > 0) {
                $label = "Video {$height}p";
                if ($fps) $label .= " {$fps}fps";
                $label .= " {$ext}";
            } else {
                $label = "Video {$ext}";
            }
        } elseif ($acodec !== 'none') {
            // Audio only
            $br = $tbr ?? (isset($f['abr']) ? (int)$f['abr'] : null);
            if ($br) {
                $label = "{$br}kbps {$ext}";
            } else {
                $label = "Audio {$ext}";
            }
        } else {
            continue; // skip unknown
        }

        // Estimate filesize if not available
        if ($filesize === 0) {
            $duration_secs = $duration ?: 180;
            if ($vcodec !== 'none' && $acodec !== 'none') {
                // Video+audio
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
            'ext' => $ext,
            'filesize_mb' => $filesize_mb,
            'height' => $height,
            'fps' => $fps,
            'tbr' => $tbr,
            'vcodec' => $vcodec,
            'acodec' => $acodec,
            'direct' => ($vcodec !== 'none' && $acodec !== 'none') ? true : false,
            'language' => $language ?: null,
        ];
    }

    // Sort: video+audio first, then by height/bitrate
    usort($formats, function($a, $b) {
        // Combined first
        if ($a['vcodec'] !== 'none' && $a['acodec'] !== 'none' && ($b['vcodec'] === 'none' || $b['acodec'] === 'none')) return -1;
        if (($a['vcodec'] === 'none' || $a['acodec'] === 'none') && $b['vcodec'] !== 'none' && $b['acodec'] !== 'none') return 1;
        // Then by height/quality
        if ($a['height'] !== $b['height']) return ($b['height'] ?? 0) - ($a['height'] ?? 0);
        return ($b['tbr'] ?? 0) - ($a['tbr'] ?? 0);
    });

    return [
        'title' => $title,
        'thumbnail' => $thumbnail,
        'duration' => $duration,
        'uploader' => $uploader,
        'formats' => $formats,
    ];
}

// ─── ROUTING ──────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'info': {
        // Get video info + formats
        $url = trim($_GET['url'] ?? $_POST['url'] ?? '');
        if (!$url || !isValidUrl($url)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid URL. Paste a valid link from YouTube, Twitter, SoundCloud, TikTok, Instagram, etc.']);
            exit;
        }

        $shell_url = escapeshellarg($url);
        runYtdlp("--dump-json --no-warnings --no-playlist -- $shell_url", $out, $err, $exit, 45);

        if ($exit !== 0 || !$out) {
            $err_msg = strip_tags(trim($err ?: $out));
            if (strlen($err_msg) > 200) $err_msg = substr($err_msg, 0, 200) . '...';
            http_response_code(422);
            echo json_encode(['error' => "Could not fetch that URL. $err_msg"]);
            exit;
        }

        $parsed = parseFormats($out);
        if (!$parsed) {
            http_response_code(422);
            echo json_encode(['error' => 'Could not parse video info. The site may not be supported.']);
            exit;
        }

        echo json_encode($parsed);
        break;
    }

    case 'download': {
        // ─── Download rate limiting (atomic via flock) ───
        $dl_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $dl_rate_file = '/tmp/ahoyrip_dl_' . md5($dl_ip);
        $dl_rate_limit = 10; // download requests per minute
        $dl_rate_window = 60;

        $dl_fp = fopen($dl_rate_file, 'c+');
        if (!$dl_fp) {
            http_response_code(503);
            echo json_encode(['error' => 'Service temporarily unavailable.']); // @codingStandardsIgnoreLine
            exit;
        }
        if (!flock($dl_fp, LOCK_EX)) {
            fclose($dl_fp);
            http_response_code(503);
            echo json_encode(['error' => 'Service temporarily unavailable.']); // @codingStandardsIgnoreLine
            exit;
        }

        $dl_data = ['t' => time(), 'c' => 0];
        $dl_raw = fread($dl_fp, 4096);
        if ($dl_raw) {
            $dl_decoded = json_decode($dl_raw, true);
            if ($dl_decoded && is_array($dl_decoded)) {
                $dl_data = $dl_decoded;
            }
        }

        if (time() - $dl_data['t'] < $dl_rate_window) {
            if ($dl_data['c'] >= $dl_rate_limit) {
                flock($dl_fp, LOCK_UN);
                fclose($dl_fp);
                http_response_code(429);
                header('Retry-After: 30');
                echo json_encode(['error' => 'Too many download requests. Slow down.']); // @codingStandardsIgnoreLine
                exit;
            }
            $dl_data['c']++;
        } else {
            $dl_data = ['t' => time(), 'c' => 1];
        }

        ftruncate($dl_fp, 0);
        rewind($dl_fp);
        fwrite($dl_fp, json_encode($dl_data));
        fflush($dl_fp);
        flock($dl_fp, LOCK_UN);
        fclose($dl_fp);

        // Serve a format for download
        $url = trim($_GET['url'] ?? '');
        $format_id = trim($_GET['format'] ?? '');
        if (!$url || !isValidUrl($url) || !$format_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing URL or format.']);
            exit;
        }

        // Validate format_id: alphanumeric + safe chars only, no shell injection
        if (!preg_match('/^[a-zA-Z0-9_.,-]+$/', $format_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid format ID.']);
            exit;
        }

        $f_shell = escapeshellarg($format_id);
        $tmp_dir = sys_get_temp_dir();
        $out_file = $tmp_dir . '/ahoyrip_' . bin2hex(random_bytes(8)) . '.tmp';

        // Build output template — use exec array to bypass shell entirely
        // URL is validated by isValidUrl(); bypass_shell prevents shell interpretation
        // but yt-dlp still sees options-like strings, so we pass it as -- separator arg
        $ytdlp_cmd = [
            '/usr/local/bin/yt-dlp',
            '-f', $format_id,
            '-o', $out_file,
            '--no-playlist',
            '--',
            $url,  // validated URL, bypass_shell=true skips shell expansion
        ];

        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = null;
        $proc = proc_open($ytdlp_cmd, $desc, $pipes, '/tmp', [], ['bypass_shell' => true]);

        if (!$proc) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to start download process.']);
            exit;
        }

        // Wait with timeout (stream the download to the user)
        $start = time();
        $timeout = 300; // 5 min max

        $done = false;
        $proc_killed = false;

        while (!feof($pipes[1]) && !feof($pipes[2])) {
            if (time() - $start > $timeout) {
                proc_terminate($proc, 9); // SIGKILL
                $proc_killed = true;
                if (file_exists($out_file)) unlink($out_file);
                http_response_code(504);
                echo json_encode(['error' => 'Download timed out. Try a smaller format.']);
                exit;
            }
            $read = [$pipes[1], $pipes[2]];
            $w = $e = null;
            $changed = @stream_select($read, $w, $e, 1, 0);
            if ($changed === false) break;

            // Check if file exists and has content
            if (!$done && file_exists($out_file) && filesize($out_file) > 0) {
                $done = true;
            }

            usleep(100000); // small sleep to prevent busy loop
        }

        if (!$proc_killed) {
            proc_close($proc);
        }

        if (!file_exists($out_file) || filesize($out_file) === 0) {
            http_response_code(500);
            echo json_encode(['error' => 'Download failed. The format may not be available.']);
            exit;
        }

        // Stream the file to user then delete
        $filesize = filesize($out_file);

        // Detect extension from file
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($out_file);
        $ext = pathinfo($out_file, PATHINFO_EXTENSION);
        $download_name = 'ahoyrip.' . ($ext ?: 'mp4');

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $filesize);
        header('Content-Disposition: attachment; filename="' . $download_name . '"');
        header('Cache-Control: no-cache');
        header('Connection: close'); // Prevent keep-alive during file streaming
        header('X-Content-Type-Options: nosniff');

        // Guard: even if client aborts, clean up the temp file
        ignore_user_abort(true);
        register_shutdown_function(function() use($out_file) {
            if (file_exists($out_file)) {
                @unlink($out_file);
            }
        });

        ini_set('memory_limit', '256M');

        $fp = fopen($out_file, 'rb');
        if (!$fp) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to read downloaded file.']); // @codingStandardsIgnoreLine
            exit;
        }
        while (!feof($fp) && !connection_aborted()) {
            echo fread($fp, 65536);
            flush();
        }
        fclose($fp);
        // Shutdown function handles unlink; call it explicitly on success
        if (file_exists($out_file)) {
            @unlink($out_file);
        }
        exit;
    }

    case 'progress': {
        // Lightweight ping to check yt-dlp is available
        $version = shell_exec('/usr/local/bin/yt-dlp --version 2>/dev/null');
        echo json_encode([
            'status' => 'ok',
            'yt_dlp_version' => trim($version ?: 'not installed'),
            'ffmpeg_version' => trim(shell_exec('ffmpeg -version 2>/dev/null | head -1')),
        ]);
        break;
    }

    default: {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Use ?action=info or ?action=download']);
    }
}
<?php
/**
 * AhoyRipper - API Endpoint
 * Handles: info extraction, format listing, and download serving
 */

// CORS headers for API access
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Content-Security-Policy: default-src \'none\'; script-src \'none\'; style-src \'none\'; img-src \'none\'; connect-src \'none\'; font-src \'none\'; frame-src \'none\';');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Download-Options: noopen');
header('X-Request-ID: ' . bin2hex(random_bytes(8)));
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
// Note: COEP removed — require-corp breaks cross-origin image loads (e.g. thumbnails
// from CDNs) which are common in media rippers. Omit unless you use SharedArrayBuffer
// or other COEP-locked features.

// Anti-hotlinking: validate origin for API requests
// Accept requests with no referer (direct) or from the same origin
$allowed_origins = ['https://ahoyripper.com', 'https://www.ahoyripper.com', 'https://ahoyvpn.com', 'https://www.ahoyvpn.com'];
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer) {
    $ref_parts = @parse_url($referer);
    // Guard against malformed URLs that cause parse_url to return false/null
    if (!is_array($ref_parts)) {
        $ref_parts = [];
    }
    $ref_origin = ($ref_parts['scheme'] ?? '') . '://' . ($ref_parts['host'] ?? '');
    if (!in_array(strtolower($ref_origin), array_map('strtolower', $allowed_origins), true)) {
        // Log and block suspicious cross-site requests
        error_log("AhoyRipper: blocked cross-site request from referer: $referer");
        http_response_code(403);
        echo json_encode(['error' => 'Requests must originate from ahoyripper.com or ahoyvpn.com.', 'error_code' => 'FORBIDDEN_ORIGIN']);
        exit;
    }
}

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

// Add rate limit response headers for client visibility
$reset = $data['t'] + $rate_window;
header('X-RateLimit-Limit: ' . $rate_limit);
header('X-RateLimit-Remaining: ' . max(0, $rate_limit - $data['c']));
header('X-RateLimit-Reset: ' . $reset);
header('X-RateLimit-Window: ' . $rate_window);

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
    $cleanup_cutoff = time() - ($rate_window * 3); // grace period of 2x window beyond expiry
    foreach (glob('/tmp/ahoyrip_rate_*') as $f) {
        $d = @json_decode(@file_get_contents($f), true);
        if (!$d || !is_array($d) || (time() - ($d['t'] ?? 0)) > $cleanup_cutoff) {
            @unlink($f);
        }
    }
}

// Only allow safe characters in URL
function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && preg_match('/^https?:\/\//', $url);
}

// Cache yt-dlp version in a global to avoid repeated subprocess calls within a request.
// Across requests a file-based cache keeps it efficient (avoids spawning a subprocess on every hit).
$version_cache_file = '/tmp/ahoyrip_ytdlp_ver.cache';
$GLOBALS['__ytdlp_version'] = null;
if ($version_cache_file && is_readable($version_cache_file)) {
    $cached = @json_decode(@file_get_contents($version_cache_file), true);
    if ($cached && is_array($cached) && ($cached['exp'] ?? 0) > time()) {
        $GLOBALS['__ytdlp_version'] = $cached['ver'] ?? null;
    }
}
if (!$GLOBALS['__ytdlp_version']) {
    // Note: yt-dlp uses -V (not --version) for version output
    $ver = trim(shell_exec('/usr/local/bin/yt-dlp -V 2>/dev/null') ?: '');
    $GLOBALS['__ytdlp_version'] = $ver;
    if ($version_cache_file) {
        @file_put_contents($version_cache_file, json_encode(['ver' => $ver, 'exp' => time() + 3600]));
    }
}

// Run yt-dlp with timeout and capture output
// $timeout = max seconds for the whole process; 0 = no limit
function runYtdlp($args, &$stdout, &$stderr, &$exit, $timeout = 0) {
    $cmd = '/usr/local/bin/yt-dlp ' . $args;
    $desc = [
        0 => ['pipe', 'r'],  // stdin — keep open but unref so proc can read if needed
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = null;
    $proc = proc_open($cmd . ' 2>&1', $desc, $pipes, '/tmp', [], ['bypass_shell' => true]);

    if (!$proc) {
        $exit = -1;
        return false;
    }

    // Close stdin immediately — yt-dlp doesn't need interactive input
    // and an unclosed stdin pipe can cause the process to hang
    fclose($pipes[0]);
    unset($pipes[0]);

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
        if ($changed === false) {
            // stream_select error — log and continue to avoid blocking on spurious failure
            usleep(100000);
            continue;
        }
        if ($changed === 0) {
            usleep(100000);
            continue;
        }
        foreach ($read as $p) {
            if ($p === $pipes[1]) {
                $s = fread($p, 8192);
                if ($s === false || $s === '') {
                    if (feof($pipes[1])) {
                        fclose($pipes[1]);
                        $pipes[1] = null;
                    }
                    continue;
                }
                $stdout .= $s;
            } elseif ($p === $pipes[2]) {
                $s = fread($p, 8192);
                if ($s === false || $s === '') {
                    if (feof($pipes[2])) {
                        fclose($pipes[2]);
                        $pipes[2] = null;
                    }
                    continue;
                }
                $stderr .= $s;
            }
        }
        // Exit when both pipes are closed
        if ($pipes[1] === null && $pipes[2] === null) break;
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

// Classify yt-dlp error messages into actionable error codes
function classifyYtdlpError($raw_err) {
    $err_lower = strtolower($raw_err);
    if (preg_match('/geo.*restriction|this video is available in/i', $err_lower)) {
        return ['code' => 'GEOBLOCKED', 'msg' => 'This video is geo-restricted and not available in your region.'];
    }
    if (preg_match('/video is private|this video is private/i', $err_lower)) {
        return ['code' => 'PRIVATE_VIDEO', 'msg' => 'This video is private and cannot be downloaded.'];
    }
    if (preg_match('/login.*required|authentication.*required|this video requires login/i', $err_lower)) {
        return ['code' => 'LOGIN_REQUIRED', 'msg' => 'This video requires login or subscription.'];
    }
    if (preg_match('/not.*support|unsupported site|is not a supported URL/i', $err_lower)) {
        return ['code' => 'UNSUPPORTED_SITE', 'msg' => 'This site is not supported by yt-dlp.'];
    }
    if (preg_match('/playlist.*not.*found|does not exist/i', $err_lower)) {
        return ['code' => 'PLAYLIST_MISSING', 'msg' => 'Playlist not found or no longer exists.'];
    }
    if (preg_match('/copyright|infringe|removed.*by|content.*strike/i', $err_lower)) {
        return ['code' => 'COPYRIGHT_REMOVED', 'msg' => 'This content has been removed due to a copyright claim.'];
    }
    if (preg_match('/too.*many.*requests|429/i', $err_lower)) {
        return ['code' => 'SOURCE_RATE_LIMITED', 'msg' => 'The source site is rate-limiting requests. Try again in a few minutes.'];
    }
    return null;
}

// Parse yt-dlp output to extract formats
function parseFormats($json_str, &$raw_error_out = null) {
    $data = json_decode($json_str, true);
    if (!$data) {
        // Differentiate yt-dlp errors from actual parsing failures
        $raw = trim($json_str);
        if (preg_match('/^(ERROR|WARNING)/im', $raw)) {
            // yt-dlp returned an error message — surface it clearly
            $err_msg = preg_replace('/[\x00-\x1F\x7F]/', '', $raw);
            $err_msg = strip_tags($err_msg);
            $err_msg = preg_replace('/\s+/', ' ', $err_msg);
            if (strlen($err_msg) > 200) $err_msg = substr($err_msg, 0, 200) . '...';

            // Classify into actionable categories
            $classified = classifyYtdlpError($err_msg);
            if ($classified) {
                if ($raw_error_out !== null) {
                    $raw_error_out = $err_msg;
                }
                return [
                    'error' => $classified['msg'],
                    'error_code' => $classified['code'],
                ];
            }
            if ($raw_error_out !== null) {
                $raw_error_out = $err_msg;
            }
            return ['error' => 'yt-dlp error: ' . $err_msg];
        }
        return null;
    }

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
            'format_type' => ($vcodec !== 'none' && $acodec !== 'none') ? 'combined' : ($vcodec !== 'none' ? 'video' : 'audio'),
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
        runYtdlp("--dump-json --no-playlist --no-warning -- $shell_url", $out, $err, $exit, 45);

        if ($exit !== 0 || !$out) {
            // Extract a clean, readable error from yt-dlp output
            // Strip HTML tags and control chars; truncate to a useful length
            $raw_err = trim($err ?: $out);
            $err_msg = preg_replace('/[\x00-\x1F\x7F]/', '', $raw_err); // remove control chars
            $err_msg = strip_tags($err_msg); // remove any HTML markup
            $err_msg = preg_replace('/\s+/', ' ', $err_msg); // collapse whitespace
            if (strlen($err_msg) > 200) $err_msg = substr($err_msg, 0, 200) . '...';
            $ytdlp_ver = $GLOBALS['__ytdlp_version'];
            $version_info = $ytdlp_ver ? " (yt-dlp $ytdlp_ver)" : '';
            http_response_code(422);
            echo json_encode(['error' => "Could not fetch that URL. $err_msg$version_info"]);
            exit;
        }

        $parsed = parseFormats($out, $raw_err);
        if (!$parsed) {
            http_response_code(422);
            echo json_encode(['error' => 'Could not parse video info. The site may not be supported.']);
            exit;
        }
        if (isset($parsed['error'])) {
            // parseFormats surfaced a yt-dlp error message — pass it through with 422
            http_response_code(422);
            $resp = ['error' => $parsed['error']];
            if (!empty($parsed['error_code'])) {
                $resp['error_code'] = $parsed['error_code'];
            }
            // Surface the raw yt-dlp output so the client can show diagnostic info
            if ($raw_err) {
                $resp['raw_error'] = $raw_err;
            }
            echo json_encode($resp);
            exit;
        }

        echo json_encode($parsed);
        break;
    }

    define('UNLIMITED_API_KEY', 'RIPPER2026');

    case 'download': {
        // ─── Check for unlimited API key ───
        $api_key = $_GET['key'] ?? $_POST['key'] ?? null;
        $unlimited = ($api_key === UNLIMITED_API_KEY);

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

// Add download rate limit response headers
        $dl_reset = $dl_data['t'] + $dl_rate_window;
        header('X-DL-RateLimit-Limit: ' . $dl_rate_limit);
        header('X-DL-RateLimit-Remaining: ' . max(0, $dl_rate_limit - $dl_data['c']));
        header('X-DL-RateLimit-Reset: ' . $dl_reset);
        header('X-DL-RateLimit-Window: ' . $dl_rate_window);

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

        // ─── Daily download quota (5 free per day, skip if unlimited key) ───
        if (!$unlimited) {
            $daily_file = '/tmp/ahoyrip_daily_' . md5($dl_ip);
            $daily_limit = 5;
            $daily_fp = fopen($daily_file, 'c+');
            if ($daily_fp) {
                flock($daily_fp, LOCK_EX);
                $daily_data = ['t' => date('Y-m-d'), 'c' => 0];
                $daily_raw = fread($daily_fp, 4096);
                if ($daily_raw) {
                    $decoded = json_decode($daily_raw, true);
                    if ($decoded && is_array($decoded)) {
                        $daily_data = $decoded;
                    }
                }
                $today = date('Y-m-d');
                if ($daily_data['t'] !== $today) {
                    $daily_data = ['t' => $today, 'c' => 0];
                }
                if ($daily_data['c'] >= $daily_limit) {
                    flock($daily_fp, LOCK_UN);
                    fclose($daily_fp);
                    http_response_code(429);
                    echo json_encode([
                        'error' => 'Daily limit reached. You get 5 free rips per day. For unlimited access, get AhoyVPN.',
                        'error_code' => 'DAILY_LIMIT',
                        'upgrade_url' => 'https://ahoyvpn.net',
                        'daily_limit' => $daily_limit
                    ]);
                    exit;
                }
                $daily_data['c']++;
                ftruncate($daily_fp, 0);
                rewind($daily_fp);
                fwrite($daily_fp, json_encode($daily_data));
                fflush($daily_fp);
                flock($daily_fp, LOCK_UN);
                fclose($daily_fp);
            }
        }

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

        // Build output template — use exec array to bypass shell entirely.
        // yt-dlp resolves the output path *before* downloading, so we use a
        // directory template with a known prefix so we can find the real file
        // after download (yt-dlp appends the real extension to the base name).
        $tmp_dir = sys_get_temp_dir();
        $out_base = 'ahoyrip_' . bin2hex(random_bytes(8));
        $out_template = $tmp_dir . '/' . $out_base . '.tmp';  // yt-dlp appends e.g. .mp4
        $out_file = $out_template; // reference used for cleanup

        $ytdlp_cmd = [
            '/usr/local/bin/yt-dlp',
            '-f', $format_id,
            '-o', $out_template,
            '--no-playlist',
            '--',
            $url,
        ];

        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = null;
        $proc = proc_open($ytdlp_cmd, $desc, $pipes, '/tmp', [], ['bypass_shell' => true]);

        if (!$proc) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to start download process.']);
            exit;
        }

        $start = time();
        $timeout = 300; // 5 min max
        $proc_killed = false;
        $proc_stdout = '';
        $proc_stderr = '';

        stream_set_timeout($pipes[1], 5);
        stream_set_timeout($pipes[2], 5);

        while (true) {
            if ($timeout > 0 && (time() - $start) > $timeout) {
                proc_terminate($proc, 9);
                $proc_killed = true;
                foreach (glob($tmp_dir . '/' . $out_base . '*') as $f) { @unlink($f); }
                http_response_code(504);
                echo json_encode(['error' => 'Download timed out. Try a smaller format.']);
                exit;
            }

            $read = [];
            if (!feof($pipes[1])) $read[] = $pipes[1];
            if (!feof($pipes[2])) $read[] = $pipes[2];

            if (empty($read)) {
                break;
            }

            $w = $e = null;
            $changed = @stream_select($read, $w, $e, 1, 0);
            if ($changed === false || $changed === 0) {
                usleep(100000);
                continue;
            }

            foreach ($read as $p) {
                $s = @fread($p, 65536);
                if ($s === false || $s === '') {
                    if (feof($p)) fclose($p);
                } else {
                    if ($p === $pipes[1]) {
                        $proc_stdout .= $s;
                    } elseif ($p === $pipes[2]) {
                        $proc_stderr .= $s;
                    }
                }
            }
        }

        proc_close($proc);

        // ─── Check exit code before streaming ───────────────────────────────────
        // yt-dlp can exit non-zero even while producing partial output (e.g.
        // format not available, network glitch, post-processing failure).
        // Surface the actual error to the client instead of letting them download
        // a corrupt/empty file.
        $actual_exit = $exit;
        unset($exit); // free the reference (intentional, clear the now-closed proc ref)
        if ($actual_exit !== 0) {
            foreach (glob($tmp_dir . '/' . $out_base . '*') as $f) { @unlink($f); }
            // Build a descriptive error from the captured stderr/stdout
            $proc_err = trim($proc_stderr ?? '');
            if (!$proc_err) {
                $proc_err = trim($proc_stdout ?? '');
            }
            $proc_err = preg_replace('/[\x00-\x1F\x7F]/', '', $proc_err);
            $proc_err = strip_tags($proc_err);
            $proc_err = preg_replace('/\s+/', ' ', $proc_err);
            if (strlen($proc_err) > 200) $proc_err = substr($proc_err, 0, 200) . '...';
            $err_classified = classifyYtdlpError($proc_err);
            if ($err_classified) {
                http_response_code(422);
                echo json_encode([
                    'error' => $err_classified['msg'],
                    'error_code' => $err_classified['code'],
                ]);
            } else {
                http_response_code(422);
                echo json_encode([
                    'error' => "Download failed" . ($proc_err ? ": $proc_err" : " (exit code $actual_exit)."),
                    'error_code' => 'YTDLP_ERROR',
                ]);
            }
            exit;
        }

        // Find the actual downloaded file — glob for the resolved extension
        $glob_pattern = $tmp_dir . '/' . $out_base . '.*';
        $matched = glob($glob_pattern);
        $actual_file = $matched[0] ?? null;

        if (!$actual_file || !is_file($actual_file) || @filesize($actual_file) === 0) {
            foreach (glob($glob_pattern) as $f) { @unlink($f); }
            http_response_code(500);
            echo json_encode(['error' => 'Download failed. The format may not be available.']);
            exit;
        }

        // Stream the file to user then delete
        $filesize = @filesize($actual_file);
        if ($filesize === false) $filesize = 0;

        // Detect extension and MIME from the actual downloaded file
        $ext = pathinfo($actual_file, PATHINFO_EXTENSION);
        $download_name = 'ahoyrip.' . ($ext ?: 'mp4');

        $mime = 'application/octet-stream';
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($actual_file);
        if ($detected !== false && strpos($detected, '/') !== false) {
            $mime = $detected;
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $filesize);
        header('Content-Disposition: attachment; filename="' . $download_name . '"');
        header('Cache-Control: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Download-Options: noopen');
        // Suppress PHP's automatic chunked transfer encoding for binary streams
        header('Transfer-Encoding: identity');

        ignore_user_abort(true);
        register_shutdown_function(function() use($glob_pattern) {
            foreach (glob($glob_pattern) as $f) { @unlink($f); }
        });

        ini_set('memory_limit', '256M');

        $fp = fopen($actual_file, 'rb');
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
        if ($actual_file && file_exists($actual_file)) {
            @unlink($actual_file);
        }
        exit;
    }

case 'progress':
    case 'health': {
        // Lightweight ping to check yt-dlp is available — returns JSON
        // Note: all security/rate-limit headers are already set at the top of the script
        // Add informational rate-limit headers so clients can track status consistently
        header('X-RateLimit-Limit: -1');
        header('X-RateLimit-Remaining: -1');
        header('X-RateLimit-Reset: -1');
        header('X-RateLimit-Window: -1');
        // Mirror download-specific rate headers for consistency
        header('X-DL-RateLimit-Limit: -1');
        header('X-DL-RateLimit-Remaining: -1');
        header('X-DL-RateLimit-Reset: -1');
        header('X-DL-RateLimit-Window: -1');

        $version = $GLOBALS['__ytdlp_version'] ?: 'not installed';
        $ffmpeg = trim(shell_exec('ffmpeg -version 2>/dev/null | head -1') ?: 'not installed');

        $response = [
            'status' => 'ok',
            'server_time' => date('c'),
            'yt_dlp_version' => $version,
            'ffmpeg_version' => $ffmpeg,
        ];

        // System resource metrics (Linux-only, gracefully omitted on other platforms)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load !== false) {
                $response['load_avg'] = array_map(fn($v) => round($v, 2), $load);
            }
        }

        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo) {
            // Match "MemAvailable:" (available since kernel 3.14) or fall back to MemFree
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $avail_m) &&
                preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $total_m)) {
                $response['memory_available_pct'] = round(($avail_m[1] / $total_m[1]) * 100, 1);
            }
        }

        $free = @disk_free_space('/');
        if ($free !== false) {
            $response['disk_free_gb'] = round($free / (1024 * 1024 * 1024), 2);
        }

        echo json_encode($response, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    }
    default: {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Use ?action=info, ?action=download, or ?action=health.'], JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    }
}
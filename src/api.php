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
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data: https://i.ytimg.com https://*.tikcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com; connect-src \'self\' https://ahoyripper.com; font-src \'self\' https://fonts.gstatic.com; frame-src \'none\'; object-src \'none\'; base-uri \'self\'; form-action \'self\';');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Download-Options: noopen');
$request_id = bin2hex(random_bytes(8));
header('X-Request-ID: ' . $request_id);

// Make request ID available to logRequest via a static global
$GLOBALS['__request_id'] = $request_id;
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
// Note: COEP removed — require-corp breaks cross-origin image loads (e.g. thumbnails
// from CDNs) which are common in media rippers. Omit unless you use SharedArrayBuffer
// or other COEP-locked features.

// Anti-hotlinking: validate origin for API requests.
// All legitimate traffic arrives as a browser navigation to the AhoyRipper page
// (which then calls the API via fetch from JS) — such calls always carry a referer.
// Cross-site resource loads (IMG embeds, iframes) won't have a referer set by the
// browser. Requests with no referer cannot be from the legitimate single-page app
// flow, so they are blocked. This also blocks direct API calls (curl, Postman, etc.)
// that lack a browser-context referer.
//
// Security note: if the fix ever needs to allow direct-API callers (non-browser clients),
// switch to validating the Origin header instead of Referer — Origin is always set by
// browsers on same-site fetch requests and CORS preflight requests.
//
// Allowed origins for browser-based API calls (SPA fetches land here with proper referer).
$allowed_origins = ['https://ahoyripper.com', 'https://www.ahoyripper.com', 'https://ahoyvpn.com', 'https://www.ahoyvpn.com'];
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$blocked = false;
$block_reason = '';

if ($referer) {
    $ref_parts = @parse_url($referer);
    // Guard against malformed URLs that cause parse_url to return false/null
    if (!is_array($ref_parts)) {
        $ref_parts = [];
    }
    $ref_origin = ($ref_parts['scheme'] ?? '') . '://' . ($ref_parts['host'] ?? '');
    if (!in_array(strtolower($ref_origin), array_map('strtolower', $allowed_origins), true)) {
        $blocked = true;
        $block_reason = 'invalid_origin';
    }
} else {
    // No referer — request did not originate from the AhoyRipper page.
    // This blocks direct API calls (curl, tools) and cross-site embeds.
    $blocked = true;
    $block_reason = 'missing_referer';
}

if ($blocked) {
    logRequest('cors_block', 403, ['reason' => $block_reason, 'referer' => $referer]);
    error_log("AhoyRipper: blocked request ($block_reason) from referer: " . ($referer ?: '(none)'));
    http_response_code(403);
    echo json_encode(['error' => 'Requests must originate from ahoyripper.com or ahoyvpn.com.', 'error_code' => 'FORBIDDEN_ORIGIN', 'request_id' => $request_id]);
    exit;
}

// Rate limiting applies to expensive actions only (info, download).
// Lightweight endpoints (health, progress) are exempt to allow frequent monitoring
// without burning the user's rate budget.
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$rate_limited_actions = ['info', 'download'];
$is_rate_limited = in_array($action, $rate_limited_actions, true);

// Rate limiting - atomic IP-based gate using flock
// $ip is used for both rate limiting and daily quota; declared early so it is
// available for both the rate-limit block and the daily-quota block (info action
// reads it at line 593, download action at line 818).
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_file = '/tmp/ahoyrip_rate_' . md5($ip);
$rate_limit = 30; // requests per minute
$rate_window = 60;

// $data is declared here so headers can be set outside the if block below,
// making rate-limit metadata available to all API responses (including
// unlimited-key users who still pass through this gate).
$data = ['t' => time(), 'c' => 0];

if ($is_rate_limited) {
    $fp = fopen($rate_file, 'c+');
    if (!$fp) {
        http_response_code(503);
        echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id]); // @codingStandardsIgnoreLine
        exit;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        http_response_code(503);
        echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id]); // @codingStandardsIgnoreLine
        exit;
    }

    $raw = fread($fp, 4096);
    if ($raw) {
        $decoded = json_decode($raw, true);
        if ($decoded && is_array($decoded)) {
            $data = $decoded;
        }
    }

    if (time() - $data['t'] < $rate_window) {
        if ($data['c'] >= $rate_limit) {
            $reset_timestamp = $data['t'] + $rate_window;
            flock($fp, LOCK_UN);
            fclose($fp);
            http_response_code(429);
            header('X-RateLimit-Limit: ' . $rate_limit);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . $reset_timestamp);
            header('X-RateLimit-Window: ' . $rate_window);
            header('Retry-After: ' . max(1, $reset_timestamp - time()));
            echo json_encode([
                'error' => 'Too many requests. Slow down.',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
                'upgrade_url' => 'https://ahoyvpn.com',
                'retry_after' => $reset_timestamp,
            ]); // @codingStandardsIgnoreLine
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
}

// Set rate limit headers unconditionally so they are present on every response,
// including unlimited-key requests that still pass through this gate.
// This gives clients (monitoring tools, load balancers) consistent metadata.
$reset = $data['t'] + $rate_window;
header('X-RateLimit-Limit: ' . $rate_limit);
header('X-RateLimit-Remaining: ' . max(0, $rate_limit - $data['c']));
header('X-RateLimit-Reset: ' . $reset);
header('X-RateLimit-Window: ' . $rate_window);

// Periodic cleanup of stale rate files (1% chance per request)
if (mt_rand(1, 100) === 1) {
    $cleanup_cutoff = time() - ($rate_window * 3); // grace period of 2x window beyond expiry
    foreach (glob('/tmp/ahoyrip_rate_*') as $f) {
        $d = @json_decode(@file_get_contents($f), true);
        if (!$d || !is_array($d) || (time() - ($d['t'] ?? 0)) > $cleanup_cutoff) {
            @unlink($f);
        }
    }
    // Clean up stale version cache files (yt-dlp and ffmpeg) — they expire after 1 hour
    // but the files themselves accumulate on long-running servers if not removed.
    // When the cache is cleared, also clear the in-memory global so the next request
    // fetches a fresh version rather than holding a stale entry across requests.
    foreach (['/tmp/ahoyrip_ytdlp_ver.cache', '/tmp/ahoyrip_ffmpeg_ver.cache', '/tmp/ahoyrip_ytdlp_probe.cache'] as $cache) {
        $d = @json_decode(@file_get_contents($cache), true);
        if (!$d || !is_array($d) || ($d['exp'] ?? 0) < time()) {
            @unlink($cache);
            if ($cache === '/tmp/ahoyrip_ytdlp_ver.cache') {
                $GLOBALS['__ytdlp_version'] = null;
            }
            if ($cache === '/tmp/ahoyrip_ffmpeg_ver.cache') {
                $GLOBALS['__ffmpeg_version'] = null;
            }
        }
    }
}

// Only allow safe characters in URL
function isValidUrl($url) {
    // Reject non-strings early — filter_var accepts various types and may coerce
    // them in unexpected ways (e.g. array → "Array", object → "object").
    // URL validation only makes sense for string input.
    if (!is_string($url)) {
        return false;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && preg_match('/^https?:\/\//', $url);
}

// yt-dlp version cache (declared early so periodic cleanup can reference it)
// Stores: ['ver' => string, 'hash' => string, 'exp' => int]
// 'hash' is MD5 of the binary — if the binary is replaced (new yt-dlp installed),
// the hash changes and the cached version is invalidated so we re-fetch the new version.
$version_cache_file = '/tmp/ahoyrip_ytdlp_ver.cache';
$GLOBALS['__ytdlp_version'] = null;
$GLOBALS['__ytdlp_probe'] = null;
if ($version_cache_file && is_readable($version_cache_file)) {
    $cached = @json_decode(@file_get_contents($version_cache_file), true);
    if ($cached && is_array($cached) && ($cached['exp'] ?? 0) > time()) {
        // Hash check: verify the binary hasn't been replaced since we cached it.
        // If the hash doesn't match, the binary was upgraded — invalidate and re-fetch.
        $current_hash = @md5_file('/usr/local/bin/yt-dlp');
        if ($current_hash !== false && isset($cached['hash']) && $current_hash === $cached['hash']) {
            $GLOBALS['__ytdlp_version'] = $cached['ver'] ?? null;
        }
    }
}
if (!$GLOBALS['__ytdlp_version']) {
    // Note: yt-dlp uses both -V and --version; --version is stable/canonical.
    $ver = trim(shell_exec('/usr/local/bin/yt-dlp --version 2>/dev/null') ?: '');
    $GLOBALS['__ytdlp_version'] = $ver;
    if ($version_cache_file) {
        $hash = @md5_file('/usr/local/bin/yt-dlp') ?: '';
        @file_put_contents($version_cache_file, json_encode(['ver' => $ver, 'hash' => $hash, 'exp' => time() + 3600]));
    }
}

// Cache ffmpeg version similarly — running `ffmpeg -version` on every health check
// is wasteful and adds latency under load. Tracks hash to invalidate on binary upgrade.
$ffmpeg_cache_file = '/tmp/ahoyrip_ffmpeg_ver.cache';
$GLOBALS['__ffmpeg_version'] = null;
if ($ffmpeg_cache_file && is_readable($ffmpeg_cache_file)) {
    $cached = @json_decode(@file_get_contents($ffmpeg_cache_file), true);
    if ($cached && is_array($cached) && ($cached['exp'] ?? 0) > time()) {
        $current_hash = @md5_file('/usr/bin/ffmpeg');
        if ($current_hash !== false && isset($cached['hash']) && $current_hash === $cached['hash']) {
            $GLOBALS['__ffmpeg_version'] = $cached['ver'] ?? null;
        }
    }
}
if (!$GLOBALS['__ffmpeg_version']) {
    $ffmpeg_ver = trim(shell_exec('ffmpeg -version 2>/dev/null | head -1') ?: '');
    $GLOBALS['__ffmpeg_version'] = $ffmpeg_ver ?: 'not installed';
    if ($ffmpeg_cache_file) {
        $hash = @md5_file('/usr/bin/ffmpeg') ?: '';
        @file_put_contents($ffmpeg_cache_file, json_encode(['ver' => $GLOBALS['__ffmpeg_version'], 'hash' => $hash, 'exp' => time() + 3600]));
    }
}

// runYtdlp runs yt-dlp with the given arguments and captures stdout/stderr.
// $timeout = max seconds (0 = no limit). The referer is always set to
// ahoyripper.com to avoid leaking the user's video URL to source sites.
function runYtdlp($args, &$stdout, &$stderr, &$exit, $timeout = 0) {
    // Defensive cap: even if a caller passes an unbounded timeout, cap it at 5 minutes
    // to prevent runaway processes. yt-dlp downloads are bounded by the format
    // selection; a 5-minute metadata-only operation covers all reasonable cases.
    static $MAX_YTDLP_TIMEOUT = 300;
    if ($timeout <= 0 || $timeout > $MAX_YTDLP_TIMEOUT) {
        $timeout = $MAX_YTDLP_TIMEOUT;
    }

    // Build the command as an array so bypass_shell works as intended.
    // Shell redirection ('2>&1') is unnecessary — we capture stderr via pipe.
    $ytdlp_bin = '/usr/local/bin/yt-dlp';
    // Split args preserving quoted strings (handles $shell_url = "'https://...'")
    $parts = preg_split('/\s+(?=(?:[^"\']|["\'][^"\']*["\'])*$)/', trim($args));
    $cmd = array_merge([$ytdlp_bin], $parts);
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = null;
    $proc = proc_open($cmd, $desc, $pipes, '/tmp', [], ['bypass_shell' => true]);

    if (!$proc) {
        $exit = -1;
        return false;
    }

    // Close stdin immediately — yt-dlp doesn't need interactive input
    // and an unclosed stdin pipe can cause the process to hang
    fclose($pipes[0]);
    unset($pipes[0]);

    // No per-iter stream_set_timeout — the global (time() - $start) > $timeout
    // check at the top of the loop is the authoritative timeout mechanism.
    // stream_set_timeout would set a per-fread read timeout on the pipe fd,
    // but when it fires (fread returns false), the current code does not break
    // the loop — it only closes the pipe and continues when feof is also true.
    // Since feof is not set until the process actually closes the pipe, a
    // stream_set_timeout expiry causes the loop to stall indefinitely waiting
    // for data on a pipe that will never produce more. The global timeout
    // (enforced below) is the correct and sufficient timeout mechanism.
    // Setting to 0 (infinite) makes the intent unambiguous.
    stream_set_timeout($pipes[1], 0);
    stream_set_timeout($pipes[2], 0);

    $stdout = '';
    $stderr = '';
    $start = time();

    while (!feof($pipes[1]) || !feof($pipes[2])) {
        if ($timeout > 0 && (time() - $start) > $timeout) {
            proc_terminate($proc, 9);
            $stderr .= "\nProcess timed out after {$timeout}s";
            $exit = -1; // convention: -1 = timeout
            // Close any remaining open pipes first, then proc_close to harvest the
            // exit code and release the process handle (avoids zombie on PHP shutdown).
            foreach ($pipes as $p) { if ($p) fclose($p); }
            $pipes = null;
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
    $pipes = null;
    $exit = proc_close($proc);
    return true;
}

// Sanitize string for JSON output
function clean($s) {
    if ($s === null || $s === '') return 'Unknown';
    // No htmlspecialchars — API outputs JSON, not HTML.
    // Type coercion to string is sufficient.
    return (string)$s;
}

// Classify yt-dlp error messages into actionable error codes
function classifyYtdlpError($raw_err) {
    $err_lower = strtolower($raw_err);
    if (preg_match('/geo.*restriction|this video is available in|geo restricted/i', $err_lower)) {
        return ['code' => 'GEOBLOCKED', 'msg' => 'This video is geo-restricted and not available in your region.'];
    }
    if (preg_match('/video is private|this video is private/i', $err_lower)) {
        return ['code' => 'PRIVATE_VIDEO', 'msg' => 'This video is private and cannot be downloaded.'];
    }
    // "authentication required" must be checked separately because the merged pattern
    // "authentication.*required" requires the word "required" to appear twice —
    // yt-dlp only says it once ("authentication required"), so we match it directly.
    if (preg_match('/authentication required|login.*required|this video requires login/i', $err_lower)) {
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
    if (preg_match('/video (has been )?(removed|delisted|unavailable|deleted)|this video (is no longer available|has been (removed|delisted))|video (has been )?removed|video (is )?unavailable/i', $err_lower)) {
        return ['code' => 'VIDEO_UNAVAILABLE', 'msg' => 'This video is no longer available or has been removed.'];
    }
    if (preg_match('/too.*many.*requests|429/i', $err_lower)) {
        return ['code' => 'SOURCE_RATE_LIMITED', 'msg' => 'The source site is rate-limiting requests. Try again in a few minutes.'];
    }
    if (preg_match('/age.*restriction|under age|video is age.*restricted/i', $err_lower)) {
        return ['code' => 'AGE_RESTRICTED', 'msg' => 'This video is age-restricted and cannot be downloaded without verification.'];
    }
    if (preg_match('/certificate.*expired|ssl.*error|sslerr|tls handshake/i', $err_lower)) {
        return ['code' => 'SSL_ERROR', 'msg' => 'Secure connection to the source failed. Try again shortly.'];
    }

    if (preg_match('#connection.*fail|dns.*fail|could not connect|i?/o timeout|connection timed out|timed out|connection reset|broken pipe|unable to connect|connection refused|getaddrinfo failed|name or service not known|network is unreachable|no route to host#i', $err_lower)) {
        return ['code' => 'CONNECTION_FAILED', 'msg' => 'Could not connect to the source. Check your network and try again.'];
    }
    if (preg_match('/file.*larger|size.*exceed|exceeds.*limit/i', $err_lower)) {
        return ['code' => 'FILE_TOO_LARGE', 'msg' => 'This file exceeds the maximum size for this server. Try an audio-only or lower-resolution format.'];
    }
    if (preg_match('/requested format|not.*available|does not contain|match/i', $err_lower)) {
        return ['code' => 'FORMAT_UNAVAILABLE', 'msg' => 'That format is not available for this video. Select another from the list.'];
    }
    if (preg_match('/disallowed.*content|content.*violat|terms.*violat|violat.*terms/i', $err_lower)) {
        return ['code' => 'DISALLOWED_CONTENT', 'msg' => 'This content is not available due to a terms of service violation.'];
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
            return ['error' => 'yt-dlp error: ' . $err_msg, 'error_code' => 'YTDLP_ERROR'];
        }
        return null;
    }

    $title = clean($data['title'] ?? 'Unknown');
    $thumbnail = clean($data['thumbnail'] ?? '');
    $duration = (int)($data['duration'] ?? 0);
    $uploader = clean($data['uploader'] ?? '');
    // Sanitize a derived filename from the title for use in Content-Disposition.
    // yt-dlp would name the file this way; we use it so the browser saves a
    // meaningful name instead of the generic "ahoyrip.mp4".
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
        // Use description (human-readable yt-dlp description) when available, else label.
        // description carries extra context like "720p60 HDR" or "audio only" that
        // label doesn't always capture — particularly for audio and alternative formats.
        // format_description (e.g. "720p60 HDR 10bit") is the richest yt-dlp signal.
        // format_note (e.g. "480p" or "720p60") is a good fallback when description is absent.
        // label is the final fallback for audio formats and edge cases.
        // Build description string:
        // - If we have resolution (width x height), prepend it to format_description.
        //   When format_description is null, PHP's string interpolation produces a
        //   trailing space ("1920x1080 ") — trim() removes it cleanly.
        //   When format_description is '', the concat naturally has no trailing space.
        // - When format_description is absent (empty or "Unknown"), fall back to
        //   format_note (e.g. "720p60") first, then the compact label as last resort.
        $quality = ($width > 0 && $height > 0) ? ($width . 'x' . $height) : null;
        $desc = $quality
            ? (empty($format_description) || $format_description === 'Unknown'
                ? ($format_note ?: $label)
                : trim("{$quality} {$format_description}"))
            : (empty($format_description) || $format_description === 'Unknown' ? ($format_note ?: $label) : $format_description);

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
            'description' => $desc,
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
    // Accepts: height (default), filesize, tbr
    // Whitelist only — invalid values are normalised to 'height' rather than
    // being passed to usort where they would trigger a comparison fatal or
    // be silently ignored depending on PHP version and error_reporting level.
    $allowed_sorts = ['height', 'filesize', 'tbr'];
    $sort = $_GET['sort'] ?? 'height';
    // DISALLOW_AUTHED is not set here (this is a public info API) so the sort param
    // IS validated against the whitelist for all requests — good, since sort
    // affects the format order and an attacker could use out-of-order formats
    // to bypass client-side size/quality checks. Invalid values fall back to 'height'.
    if (!is_string($sort) || !in_array($sort, $allowed_sorts, true)) {
        $sort = 'height';
    }
    usort($formats, function($a, $b) use ($sort) {
        // Combined first
        if ($a['vcodec'] !== 'none' && $a['acodec'] !== 'none' && ($b['vcodec'] === 'none' || $b['acodec'] === 'none')) return -1;
        if (($a['vcodec'] === 'none' || $a['acodec'] === 'none') && $b['vcodec'] !== 'none' && $b['acodec'] !== 'none') return 1;
        // Then by selected sort key
        if ($sort === 'filesize') {
            $cmp = ($b['filesize_mb'] ?? 0) <=> ($a['filesize_mb'] ?? 0);
        } elseif ($sort === 'tbr') {
            $cmp = ($b['tbr'] ?? 0) <=> ($a['tbr'] ?? 0);
        } else {
            $cmp = ($b['height'] ?? 0) <=> ($a['height'] ?? 0);
        }
        // Secondary: within same type group, sort by height descending for consistency.
        // When height is also equal, prefer higher fps (60fps > 30fps > 24fps) so
        // smoother formats appear first within the same resolution tier.
        if ($cmp === 0) {
            $cmp = ($b['height'] ?? 0) <=> ($a['height'] ?? 0);
        }
        if ($cmp === 0) {
            $cmp = ($b['fps'] ?? 0) <=> ($a['fps'] ?? 0);
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

// ─── Structured Request Logging ──────────────────────────────────────────
// Logs request metadata to /var/log/ahoyripper/requests.log for monitoring.
// Uses JSON Lines format (one JSON object per line) for easy grep/jq parsing.
// Requires: /var/log/ahoyripper/ to be created and writable by the web server.
// Falls back to error_log silently if the file is not writable.
function logRequest($action, $status, $extra = []) {
    static $log_dir = '/var/log/ahoyripper';
    static $log_file = '/var/log/ahoyripper/requests.log';
    static $log_init = false;

    // Attempt to create log dir on first call if it doesn't exist
    if (!$log_init) {
        $log_init = true;
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
    }

    $entry = [
        'ts' => date('c'),
        'req_id' => $GLOBALS['__request_id'] ?? '',
        'action' => $action,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        // Strip query string from REQUEST_URI to prevent video URL and API key
        // from appearing in logs. The action alone is sufficient for monitoring.
        'uri' => preg_replace('/\?.*/', '', $_SERVER['REQUEST_URI'] ?? ''),
        'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        'status' => $status,
    ];
    if ($extra) {
        foreach ($extra as $k => $v) {
            // Omit sensitive fields from extra
            if (in_array($k, ['api_key', 'key', 'url', 'filename'], true)) continue;
            $entry[$k] = is_string($v) ? substr($v, 0, 200) : $v;
        }
    }

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    if (@file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX) === false) {
        // Fallback to PHP error_log if file write fails
        @error_log("AhoyRipper [$action]: " . json_encode($entry, JSON_UNESCAPED_SLASHES));
    }
}

// ─── Shared validation helper ─────────────────────────────────────────
// DRY helper for URL and format validation. Used by both info and download
// actions to ensure consistent error codes and log messages.
// Keep outside the switch so both case blocks can reference it.
$validation = function(string $action) use($request_id) {
    $url = trim($_GET['url'] ?? $_POST['url'] ?? '');
    if (!$url) {
        http_response_code(400);
        logRequest($action, 400, ['reason' => 'missing_url']);
        echo json_encode([
            'error' => 'Invalid URL. Paste a valid link from YouTube, Twitter, SoundCloud, TikTok, Instagram, etc.',
            'error_code' => 'INVALID_URL',
            'request_id' => $request_id,
        ]);
        return false;
    }
    if (!isValidUrl($url)) {
        http_response_code(400);
        logRequest($action, 400, ['reason' => 'invalid_url']);
        echo json_encode([
            'error' => 'Invalid URL. Please paste a valid video link.',
            'error_code' => 'INVALID_URL',
            'request_id' => $request_id,
        ]);
        return false;
    }
    // Download-only: a format must be selected before downloading.
    // Info action does not require a format parameter.
    if ($action === 'download') {
        $format_id = trim($_GET['format'] ?? '');
        if (!$format_id) {
            http_response_code(400);
            logRequest($action, 400, ['reason' => 'missing_format']);
            echo json_encode([
                'error' => 'A format must be selected before downloading.',
                'error_code' => 'MISSING_FORMAT',
                'request_id' => $request_id,
            ]);
            return false;
        }
    }
    return $url;
};

// ─── CONSTANTS ──────────────────────────────────────────────
// Unlimited API key — read from environment variable in production.
// The env var takes precedence; falling back to a compile-time default
// only for local development / docker where env is not set.
// Keep the value in a single place to simplify rotation.
define('AHOY_UNLIMITED_KEY', getenv('AHOY_UNLIMITED_KEY') ?: 'RIPPER2026DEV');

// ─── ROUTING ────────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// $unlimited is set in the download case below after reading the API key.
// Default to false here so the info-action daily-quota check (which runs
// before the switch) has a safe fallback — it will be overwritten with the
// real value when action=download, which is the only place a key is sent.
$unlimited = false;

// Enforce GET for all API actions — POST is not used or documented.
// Rejecting wrong methods early gives a clear 405 instead of ambiguous behaviour.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed. Use GET.',
        'error_code' => 'METHOD_NOT_ALLOWED',
        'request_id' => $request_id,
    ]);
    exit;
}

// Verify the Accept header expects JSON — reject non-JSON requests
// to prevent the API from returning HTML/error pages to API clients.
// Allow */* (browsers/clients that accept anything) and application/json variants.
// Download action is exempt — it always returns the file regardless of Accept.
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$json_actions = ['info', 'health', 'progress'];
if (in_array($action, $json_actions, true) && $accept && $accept !== '*/*' && !preg_match('/application\/json/i', $accept)) {
    http_response_code(406);
    echo json_encode([
        'error' => 'Not acceptable. API only returns application/json.',
        'error_code' => 'NOT_ACCEPTABLE',
        'request_id' => $request_id,
    ]);
    exit;
}

switch ($action) {
    case 'info': {
        // Get video info + formats
        $url = trim($_GET['url'] ?? $_POST['url'] ?? '');

        // Check URL length first — reject pathologically long strings before
        // doing expensive URL format validation. This fails fast on abuse and
        // avoids burning a daily quota increment on obviously invalid requests.
        // The 2048-char limit covers all normal video URLs with tracking params.
        if (strlen($url) > 2048) {
            http_response_code(400);
            logRequest('info', 400, ['reason' => 'url_too_long', 'url_len' => strlen($url)]);
            echo json_encode([
                'error' => 'URL is too long. Please paste a shorter link.',
                'error_code' => 'INVALID_URL',
                'request_id' => $request_id,
            ]);
            exit;
        }

        $url = $validation('info');
        if ($url === false) {
            exit;
        }

        // ─── Check for unlimited API key ───
        // Prefer Authorization: Bearer header (keeps key out of URLs and server logs).
        // Fall back to GET/POST query param only for legacy clients that can't send headers.
        // Omit empty-string Bearer tokens — a misconfigured client sending
        // "Authorization: Bearer " (trailing space, no token) should fall through to key= param.
        $api_key = null;
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m) && trim($m[1]) !== '') {
            $api_key = trim($m[1]);
        }
        if ($api_key === null) {
            $api_key = $_GET['key'] ?? $_POST['key'] ?? null;
        }
        $unlimited = ($api_key === AHOY_UNLIMITED_KEY);

        // ─── Daily download quota (5 free per day, skip if unlimited key) ───
        // Enforce on info action too — yt-dlp is equally expensive here.
        if (!$unlimited) {
            // Use the same $ip variable declared above for the rate-limit gate.
            // Both info and download actions share the same daily-quota file so
            // that a user hitting 5 info calls has no download quota left.
            $daily_file = '/tmp/ahoyrip_daily_' . md5($ip);
            $daily_limit = 5;
            $daily_fp = fopen($daily_file, 'c+');
            if (!$daily_fp) {
                http_response_code(503);
                echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id]); // @codingStandardsIgnoreLine
                exit;
            }
            if (!flock($daily_fp, LOCK_EX)) {
                fclose($daily_fp);
                http_response_code(503);
                echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id]); // @codingStandardsIgnoreLine
                exit;
            }
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
                logRequest('info', 429, ['reason' => 'daily_limit_exceeded']);
                http_response_code(429);
                $reset_timestamp = strtotime('tomorrow midnight UTC');
                header('Retry-After: ' . ($reset_timestamp - time()));
                echo json_encode([
                    'error' => 'Daily limit reached. You get 5 free rips per day. For unlimited access, get AhoyVPN.',
                    'error_code' => 'DAILY_LIMIT',
                    'upgrade_url' => 'https://ahoyvpn.com',
                    'daily_limit' => $daily_limit,
                    'retry_after' => $reset_timestamp,
                ]);
                exit;
            }
            $daily_data['c']++;
            $daily_remaining = max(0, $daily_limit - $daily_data['c']);
            ftruncate($daily_fp, 0);
            rewind($daily_fp);
            fwrite($daily_fp, json_encode($daily_data));
            fflush($daily_fp);
            flock($daily_fp, LOCK_UN);
            fclose($daily_fp);  // explicitly close to release lock without waiting for GC
            $daily_fp = null;

            // Surface daily quota state so the client can display remaining rips.
            // Use the remaining count calculated BEFORE the increment so the value
            // is correct even when this is the user's last free rip.
            header('X-DailyLimit-Limit: ' . $daily_limit);
            header('X-DailyLimit-Remaining: ' . $daily_remaining);
            header('X-DailyLimit-Reset: ' . strtotime('tomorrow midnight UTC'));
            header('X-DailyLimit-Window: daily');
        } else {
            // Unlimited-key holder — quota does not apply, signal this to the
            // client with -1 so it can hide the "N free rips/day" UI element.
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
        }

        // URL is already validated by isValidUrl(); no shell metacharacters possible
        // when passed as a direct array element to proc_open (no shell involved).
        // Enforce a max URL length to prevent pathologically long URLs from reaching
        // yt-dlp. The limit of 2048 chars covers all reasonable video URLs with
        // tracking parameters while stopping abuse.
        $MAX_URL_LEN = 2048;
        if (strlen($url) > $MAX_URL_LEN) {
            http_response_code(400);
            logRequest('info', 400, ['reason' => 'url_too_long', 'url_len' => strlen($url)]);
            echo json_encode([
                'error' => 'URL is too long. Please paste a shorter link.',
                'error_code' => 'INVALID_URL',
                'request_id' => $request_id,
            ]);
            exit;
        }
        // Build yt-dlp args as an array — the download action already does this.
        // We call proc_open directly here (not via runYtdlp) so the URL is passed
        // as a single array element and cannot be split by preg_split whitespace
        // tokenization (which breaks URLs that contain spaces or option-like text).
        // With bypass_shell=true, proc_open parses the array into argv without a shell,
        // so no shell escaping is needed.
        $ytdlp_cmd = [
            '/usr/local/bin/yt-dlp',
            '--dump-json',
            '--no-playlist',
            '-q',
            '--skip-download',
            '--referer', 'https://ahoyripper.com/',
            '--',
            $url,
        ];
        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = null;
        $proc = proc_open($ytdlp_cmd, $desc, $pipes, '/tmp', [], ['bypass_shell' => true]);
        if (!$proc) {
            $exit = -1;
            $out = $err = '';
        } else {
            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_timeout($pipes[1], 45);
            stream_set_timeout($pipes[2], 45);
            $out = $err = '';
            $start = time();
            while (!feof($pipes[1]) || !feof($pipes[2])) {
                if ((time() - $start) > 45) {
                    proc_terminate($proc, 9);
                    $err .= "\nProcess timed out after 45s";
                    $exit = -1;
                    foreach ($pipes as $p) { if ($p) fclose($p); }
                    $pipes = null;
                    proc_close($proc);
                    $out = '';
                    break;
                }
                $read = [];
                if (!feof($pipes[1])) $read[] = $pipes[1];
                if (!feof($pipes[2])) $read[] = $pipes[2];
                if (empty($read)) break;
                $w = $e = null;
                $changed = @stream_select($read, $w, $e, 1, 0);
                if ($changed === false || $changed === 0) { usleep(100000); continue; }
                foreach ($read as $p) {
                    if ($p === $pipes[1]) {
                        $s = @fread($p, 8192);
                        if ($s === false || $s === '') { if (feof($pipes[1])) { fclose($pipes[1]); $pipes[1] = null; } continue; }
                        $out .= $s;
                    } elseif ($p === $pipes[2]) {
                        $s = @fread($p, 8192);
                        if ($s === false || $s === '') { if (feof($pipes[2])) { fclose($pipes[2]); $pipes[2] = null; } continue; }
                        $err .= $s;
                    }
                }
                if ($pipes[1] === null && $pipes[2] === null) break;
            }
            if ($pipes[1] === null && $pipes[2] === null) {
                foreach ($pipes as $p) { if ($p) fclose($p); }
                $pipes = null;
                $exit = proc_close($proc);
            } else {
                foreach ($pipes as $p) { if ($p) fclose($p); }
                $pipes = null;
                $exit = proc_close($proc);
            }
        }

        if ($exit !== 0 || !$out) {
            // The fetch failed — undo the quota increment so failed attempts don't
            // burn the user's daily limit. Only count successful info retrievals.
            if (!$unlimited) {
                // Use the same $ip variable declared at the top of the script so the undo
                // targets the correct daily-quota file regardless of which action ran.
                $undo_fp = fopen('/tmp/ahoyrip_daily_' . md5($ip), 'c+');
                if ($undo_fp && flock($undo_fp, LOCK_EX)) {
                    $undo_raw = fread($undo_fp, 4096);
                    $undo_data = ['t' => date('Y-m-d'), 'c' => 0];
                    if ($undo_raw) {
                        $decoded = json_decode($undo_raw, true);
                        if ($decoded && is_array($decoded)) $undo_data = $decoded;
                    }
                    // Only decrement if it's the current day's record
                    if ($undo_data['t'] === date('Y-m-d') && $undo_data['c'] > 0) {
                        $undo_data['c']--;
                        ftruncate($undo_fp, 0);
                        rewind($undo_fp);
                        fwrite($undo_fp, json_encode($undo_data));
                        fflush($undo_fp);
                    }
                    flock($undo_fp, LOCK_UN);
                    fclose($undo_fp);
                }
            }

            // Extract a clean, readable error from yt-dlp output
            // Strip HTML tags and control chars; truncate to a useful length
            $raw_err = trim($err ?: $out);
            $err_msg = preg_replace('/[\x00-\x1F\x7F]/', '', $raw_err); // remove control chars
            $err_msg = strip_tags($err_msg); // remove any HTML markup
            $err_msg = preg_replace('/\s+/', ' ', $err_msg); // collapse whitespace
            if (strlen($err_msg) > 200) $err_msg = substr($err_msg, 0, 200) . '...';
            $ytdlp_ver = $GLOBALS['__ytdlp_version'];
            $version_info = $ytdlp_ver ? " (yt-dlp $ytdlp_ver)" : '';
            logRequest('info', 422, ['reason' => 'ytdlp_fetch_failed', 'exit' => $exit, 'err_preview' => substr($err_msg, 0, 100)]);
            http_response_code(422);
            $resp = [
                'error' => "Could not fetch that URL. $err_msg$version_info",
                'error_code' => 'YTDLP_ERROR',
                'action' => 'info',
                'request_id' => $request_id,
            ];
            if ($raw_err) {
                $resp['raw_error'] = $raw_err;
            }
            echo json_encode($resp);
            exit;
        }

        $parsed = parseFormats($out, $raw_err);
        if (!$parsed) {
            // Undo the quota increment — parseFormats returned null means the content
            // could not be parsed; we don't burn the user's daily limit for this.
            if (!$unlimited) {
                $undo_fp = fopen('/tmp/ahoyrip_daily_' . md5($ip), 'c+');
                if ($undo_fp && flock($undo_fp, LOCK_EX)) {
                    $undo_raw = fread($undo_fp, 4096);
                    $undo_data = ['t' => date('Y-m-d'), 'c' => 0];
                    if ($undo_raw) {
                        $decoded = json_decode($undo_raw, true);
                        if ($decoded && is_array($decoded)) $undo_data = $decoded;
                    }
                    if ($undo_data['t'] === date('Y-m-d') && $undo_data['c'] > 0) {
                        $undo_data['c']--;
                        ftruncate($undo_fp, 0);
                        rewind($undo_fp);
                        fwrite($undo_fp, json_encode($undo_data));
                        fflush($undo_fp);
                    }
                    flock($undo_fp, LOCK_UN);
                    fclose($undo_fp);
                }
            }
            logRequest('info', 422, ['reason' => 'parse_formats_failed', 'exit' => $exit]);
            http_response_code(422);
            $resp = [
                'error' => 'Could not parse video info. The site may not be supported.',
                'error_code' => 'PARSE_ERROR',
                'request_id' => $request_id,
            ];
            // Surface yt-dlp's raw stderr so the user sees the actual reason
            if ($raw_err) {
                $resp['raw_error'] = $raw_err;
            }
            echo json_encode($resp);
            exit;
        }
        if (isset($parsed['error'])) {
            // parseFormats surfaced a yt-dlp error message — pass it through with 422
            $err_code = $parsed['error_code'] ?? 'PARSE_ERROR';
            logRequest('info', 422, ['reason' => 'parse_formats_ytdlp_error', 'err_code' => $err_code]);
            // Undo the quota increment — parseFormats succeeded (returned a classified error
            // like GEOBLOCKED/PRIVATE_VIDEO) but the content is not downloadable. We don't
            // burn the user's daily limit for content that simply can't be ripped.
            if (!$unlimited) {
                $undo_fp = fopen('/tmp/ahoyrip_daily_' . md5($ip), 'c+');
                if ($undo_fp && flock($undo_fp, LOCK_EX)) {
                    $undo_raw = fread($undo_fp, 4096);
                    $undo_data = ['t' => date('Y-m-d'), 'c' => 0];
                    if ($undo_raw) {
                        $decoded = json_decode($undo_raw, true);
                        if ($decoded && is_array($decoded)) $undo_data = $decoded;
                    }
                    if ($undo_data['t'] === date('Y-m-d') && $undo_data['c'] > 0) {
                        $undo_data['c']--;
                        ftruncate($undo_fp, 0);
                        rewind($undo_fp);
                        fwrite($undo_fp, json_encode($undo_data));
                        fflush($undo_fp);
                    }
                    flock($undo_fp, LOCK_UN);
                    fclose($undo_fp);
                }
            }
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

        $parsed['request_id'] = $request_id;
        header('Cache-Control: no-cache');
        echo json_encode($parsed, JSON_INVALID_UTF8_SUBSTITUTE);
        logRequest('info', 200, ['url_type' => 'single', 'format_count' => count($parsed['formats'] ?? [])]);
        break;
    }

    case 'download': {
        // ─── Validate required params first (before rate limiting or any I/O) ───
        // Rejecting early avoids burning rate-limit slots or opening temp files on bad input.
        // The shared $validation helper is defined before the switch and handles
        // URL validation (missing, invalid) for both info and download actions.
        // The format parameter check is only enforced for download (checked inside helper).
        $url = $validation('download');
        if ($url === false) {
            exit;
        }
        // Enforce a max URL length to prevent pathologically long URLs from reaching
        // yt-dlp. The limit of 2048 chars covers all reasonable video URLs with
        // tracking parameters while stopping abuse.
        if (strlen($url) > 2048) {
            http_response_code(400);
            logRequest('download', 400, ['reason' => 'url_too_long', 'url_len' => strlen($url)]);
            echo json_encode([
                'error' => 'URL is too long. Please paste a shorter link.',
                'error_code' => 'INVALID_URL',
                'request_id' => $request_id,
            ]);
            exit;
        }
        // Validate format_id — yt-dlp format selectors use characters like [ ] + =
        // for conditional selection (e.g. "bestvideo[height>=720]+bestaudio[ext=m4a]").
        // Block shell metacharacters that could be dangerous in proc_open calls:
        // $ ` ( ) ; | & < > \ and whitespace. Allow alphanum, _ . , - + [ ] < > = !
        // Note: $format_id is read directly from $_GET here (not via the $validation
        // closure) because PHP closures do not inherit outer scope for variables
        // assigned inside the closure — the closure's $format_id is scoped to it.
        $format_id = trim($_GET['format'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.,<>=![\]+\/-]+$/', $format_id)) {
            http_response_code(400);
            logRequest('download', 400, ['reason' => 'invalid_format_id']);
            echo json_encode([
                'error' => 'Invalid format ID.',
                'error_code' => 'INVALID_FORMAT_ID',
                'request_id' => $request_id,
            ]);
            exit;
        }

// ─── Check for unlimited API key ───
        // Prefer Authorization: Bearer header (keeps key out of URLs and server logs).
        // Fall back to GET/POST query param only for legacy clients that can't send headers.
        // Omit empty-string Bearer tokens — a misconfigured client sending
        // "Authorization: Bearer " (trailing space, no token) should fall through to key= param.
        $api_key = null;
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m) && trim($m[1]) !== '') {
            $api_key = trim($m[1]);
        }
        if ($api_key === null) {
            $api_key = $_GET['key'] ?? $_POST['key'] ?? null;
        }
        $unlimited = ($api_key === AHOY_UNLIMITED_KEY);

        // ─── Download rate limiting (atomic via flock) ───
        $dl_rate_limit = 10; // download requests per minute
        $dl_rate_window = 60;

        $dl_fp = fopen($rate_file, 'c+');
        if (!$dl_fp) {
            http_response_code(503);
            echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id]); // @codingStandardsIgnoreLine
            exit;
        }
        if (!flock($dl_fp, LOCK_EX)) {
            fclose($dl_fp);
            http_response_code(503);
            echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id]); // @codingStandardsIgnoreLine
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
                $dl_reset_ts = $dl_data['t'] + $dl_rate_window;
                header('X-DL-RateLimit-Remaining: 0');
                header('X-DL-RateLimit-Reset: ' . $dl_reset_ts);
                flock($dl_fp, LOCK_UN);
                fclose($dl_fp);
                http_response_code(429);
                header('Retry-After: ' . max(1, $dl_reset_ts - time()));
                echo json_encode([
                    'error' => 'Too many download requests. Slow down.',
                    'error_code' => 'RATE_LIMIT_EXCEEDED',
                    'upgrade_url' => 'https://ahoyvpn.com',
                ]); // @codingStandardsIgnoreLine
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
            // Use the same $ip variable declared at the top of the script for the
            // rate-limit gate. Both info and download share the daily-quota file.
            $daily_file = '/tmp/ahoyrip_daily_' . md5($ip);
            $daily_limit = 5;
            $daily_fp = fopen($daily_file, 'c+');
            if (!$daily_fp) {
                http_response_code(503);
                echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id]); // @codingStandardsIgnoreLine
                exit;
            }
            if (!flock($daily_fp, LOCK_EX)) {
                fclose($daily_fp);
                http_response_code(503);
                echo json_encode(['error' => 'Service temporarily unavailable.', 'request_id' => $request_id]); // @codingStandardsIgnoreLine
                exit;
            }
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
                logRequest('download', 429, ['reason' => 'daily_limit_exceeded']);
                http_response_code(429);
                $reset_timestamp = strtotime('tomorrow midnight UTC');
                header('Retry-After: ' . ($reset_timestamp - time()));
                header('X-DailyLimit-Limit: ' . $daily_limit);
                header('X-DailyLimit-Remaining: 0');
                header('X-DailyLimit-Reset: ' . $reset_timestamp);
                header('X-DailyLimit-Window: daily');
                echo json_encode([
                    'error' => 'Daily limit reached. You get 5 free rips per day. For unlimited access, get AhoyVPN.',
                    'error_code' => 'DAILY_LIMIT',
                    'upgrade_url' => 'https://ahoyvpn.com',
                    'daily_limit' => $daily_limit,
                    'retry_after' => $reset_timestamp,
                ]);
                exit;
            }
            $daily_data['c']++;
            $daily_remaining = max(0, $daily_limit - $daily_data['c']);
            ftruncate($daily_fp, 0);
            rewind($daily_fp);
            fwrite($daily_fp, json_encode($daily_data));
            fflush($daily_fp);
            flock($daily_fp, LOCK_UN);
            fclose($daily_fp);  // explicitly close to release lock without waiting for GC
            $daily_fp = null;

            // Surface daily quota state so the client can display remaining rips.
            // Use the remaining count calculated BEFORE the increment so the value
            // is correct even when this is the user's last free rip.
            header('X-DailyLimit-Limit: ' . $daily_limit);
            header('X-DailyLimit-Remaining: ' . $daily_remaining);
            header('X-DailyLimit-Reset: ' . strtotime('tomorrow midnight UTC'));
            header('X-DailyLimit-Window: daily');
        } else {
            // Unlimited-key holder — quota does not apply, signal this to the
            // client with -1 so it can hide the "N free rips/day" UI element.
            header('X-DailyLimit-Limit: -1');
            header('X-DailyLimit-Remaining: -1');
            header('X-DailyLimit-Reset: -1');
            header('X-DailyLimit-Window: unlimited');
        }

        // ─── Sanitize derived filename ───
        // allow only safe chars; fall back to generic name if empty/too long.
        $download_filename = trim($_GET['filename'] ?? '');
        if ($download_filename !== '') {
            $download_filename = preg_replace('/[^\w\s._-]/', '', $download_filename);
            $download_filename = preg_replace('/\s+/', '_', $download_filename);
            // Validate trimmed result — a filename that trims to empty is invalid.
            // Check this AFTER sanitization so inputs like "   " fall through to fallback.
            $trimmed = trim($download_filename);
            if (strlen($trimmed) === 0 || strlen($trimmed) > 80) {
                $download_filename = 'ahoyrip';
            } else {
                $download_filename = $trimmed;
            }
        } else {
            $download_filename = 'ahoyrip';
        }

        // Build output template — use exec array to bypass shell entirely.
        // yt-dlp resolves the output path *before* downloading, so we use a
        // directory template with a known prefix so we can find the real file
        // after download (yt-dlp appends the real extension to the base name).
        $tmp_dir = sys_get_temp_dir();
        $out_base = 'ahoyrip_' . bin2hex(random_bytes(8));
        $out_template = $tmp_dir . '/' . $out_base . '.tmp';  // yt-dlp appends e.g. .mp4

        // Register shutdown handler to clean up any temp files on unexpected exit.
        // Catches: fatal errors, connection aborts, timeout before normal cleanup.
        // The glob pattern is captured by PHP's closure semantics.
        register_shutdown_function(function() use($tmp_dir, $out_base) {
            foreach (glob($tmp_dir . '/' . $out_base . '*') as $f) { @unlink($f); }
        });

        // Prevent the user's video URL from leaking as HTTP Referer to the source.
        // yt-dlp sends the URL itself as referer by default; using the generic
        // ahoyripper.com referer hides the actual video URL from third-party servers.
        $referer = 'https://ahoyripper.com/';

        $ytdlp_cmd = [
            '/usr/local/bin/yt-dlp',
            '-f', $format_id,
            '-o', $out_template,
            '--no-playlist',
            '-q',
            '--newline',
            '--referer', $referer,
            '--',
            $url,
        ];

        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = null;
        $proc = proc_open($ytdlp_cmd, $desc, $pipes, '/tmp', [], ['bypass_shell' => true]);

        if (!$proc) {
            logRequest('download', 500, ['reason' => 'proc_open_failed']);
            http_response_code(500);
            echo json_encode(['error' => 'Failed to start download process.', 'request_id' => $request_id]);
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
                // Clean up process handle before exit to avoid zombie processes.
                // proc_terminate sends SIGKILL; proc_close waits for exit and releases
                // the resource. Do both even though proc_terminate already killed it —
                // proc_close is necessary to avoid leaving a zombie.
                proc_terminate($proc, 9);
                proc_close($proc);
                $proc_killed = true;
                // Use glob pattern — $out_file was never set in this scope.
                // $out_base was set above and holds the safe base name.
                foreach (glob($tmp_dir . '/' . $out_base . '*') as $f) { @unlink($f); }
                logRequest('download', 504, ['reason' => 'timeout', 'timeout_seconds' => $timeout]);
                http_response_code(504);
                echo json_encode([
                    'error' => 'Download timed out after ' . $timeout . ' seconds. The file may be too large or the source is slow. Try a smaller format.',
                    'error_code' => 'DOWNLOAD_TIMEOUT',
                    'retry_after' => $timeout,
                    'request_id' => $request_id,
                ]);
                exit;
            }

            $read = [];
            if ($pipes[1] !== null && !feof($pipes[1])) $read[] = $pipes[1];
            if ($pipes[2] !== null && !feof($pipes[2])) $read[] = $pipes[2];

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
                    if (feof($p)) {
                        fclose($p);
                        if ($p === $pipes[1]) $pipes[1] = null;
                        elseif ($p === $pipes[2]) $pipes[2] = null;
                    }
                } else {
                    if ($p === $pipes[1]) {
                        $proc_stdout .= $s;
                    } elseif ($p === $pipes[2]) {
                        $proc_stderr .= $s;
                    }
                }
            }
        }
        // Close any remaining open pipes
        if ($pipes[1] !== null) fclose($pipes[1]);
        if ($pipes[2] !== null) fclose($pipes[2]);

        // proc_close() returns the exit code — call it here to capture the status.
        // Capture it in a separate statement so it can't be mistaken for a void
        // discard. Pipe handles were already closed above.
        $actual_exit = proc_close($proc);
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

            // Refund daily quota for any download failure — classified or not.
            // Whether the error is GEOBLOCKED (content unavailable) or an unexpected
            // yt-dlp exit (e.g. network glitch, source timeout), the user didn't
            // successfully download anything, so the quota should not be burned.
            // Skip refund only for successful exits and when the user is on the
            // free tier ($unlimited is false) — unlimited-key holders never had
            // their quota incremented in the first place.
            if (!$unlimited) {
                $undo_fp = fopen('/tmp/ahoyrip_daily_' . md5($ip), 'c+');
                if ($undo_fp && flock($undo_fp, LOCK_EX)) {
                    $undo_raw = fread($undo_fp, 4096);
                    $undo_data = ['t' => date('Y-m-d'), 'c' => 0];
                    if ($undo_raw) {
                        $decoded = json_decode($undo_raw, true);
                        if ($decoded && is_array($decoded)) $undo_data = $decoded;
                    }
                    if ($undo_data['t'] === date('Y-m-d') && $undo_data['c'] > 0) {
                        $undo_data['c']--;
                        ftruncate($undo_fp, 0);
                        rewind($undo_fp);
                        fwrite($undo_fp, json_encode($undo_data));
                        fflush($undo_fp);
                    }
                    flock($undo_fp, LOCK_UN);
                    fclose($undo_fp);
                }
            }

            if ($err_classified) {
                logRequest('download', 422, ['reason' => 'ytdlp_error_classified', 'err_code' => $err_classified['code']]);
                http_response_code(422);
                echo json_encode([
                    'error' => $err_classified['msg'],
                    'error_code' => $err_classified['code'],
                    'request_id' => $request_id,
                ]);
                exit;
            } else {
                logRequest('download', 422, ['reason' => 'ytdlp_error', 'exit' => $actual_exit, 'err_preview' => substr($proc_err, 0, 100)]);
                http_response_code(422);
                echo json_encode([
                    'error' => "Download failed" . ($proc_err ? ": $proc_err" : " (exit code $actual_exit)."),
                    'error_code' => 'YTDLP_ERROR',
                    'request_id' => $request_id,
                ], JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }

        // Find the actual downloaded file — glob for the resolved extension
        $glob_pattern = $tmp_dir . '/' . $out_base . '.*';
        $matched = glob($glob_pattern);
        $actual_file = $matched[0] ?? null;

        if (!$actual_file || !is_file($actual_file) || @filesize($actual_file) === 0) {
            foreach (glob($glob_pattern) as $f) { @unlink($f); }
            logRequest('download', 500, ['reason' => 'empty_or_missing_file', 'format_id' => $format_id]);
            http_response_code(500);
            echo json_encode([
                'error' => 'Download failed. The format may not be available. Try another format from the list.',
                'error_code' => 'DOWNLOAD_FAILED',
                'request_id' => $request_id,
            ]);
            exit;
        }

        // Stream the file to user then delete
        $filesize = @filesize($actual_file);
        if ($filesize === false) $filesize = 0;

        // Detect extension and MIME from the actual downloaded file
        $ext = pathinfo($actual_file, PATHINFO_EXTENSION);
        // Use the sanitized derived filename from the URL param, falling back to
        // the generic "ahoyrip.<ext>" so the browser still proposes a useful name.
        $download_name = $download_filename . '.' . ($ext ?: 'mp4');

        $mime = 'application/octet-stream';
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($actual_file);
        if ($detected !== false && strpos($detected, '/') !== false) {
            $mime = $detected;
        }

        header('Content-Length: ' . $filesize);
        // Send RFC 5987 filename encoding so non-ASCII characters in the derived
        // filename are handled correctly across browsers (RFC 5987 = UTF-8 encoded
        // filename*=utf-8''...). The ascii-check prevents double-encoding plain ASCII.
        $dl_raw = $download_name;
        $needs_encoding = preg_match('/[^\x00-\x7F]/', $dl_raw);
        if ($needs_encoding) {
            $encoded = rawurlencode($dl_raw);
            $disposition = "attachment; filename*=UTF-8''{$encoded}; filename=\"{$dl_raw}\"";
        } else {
            $disposition = "attachment; filename=\"{$dl_raw}\"";
        }
        header('Content-Disposition: ' . $disposition);
        header('Cache-Control: no-cache');
        // Content-Type and X-Download-Options are set immediately before streaming
        // so that error response paths above (empty-file, timeout, proc failure)
        // return with the default Content-Type: application/json from the top of
        // the script rather than application/octet-stream.

        ignore_user_abort(true);

        $mem_set = ini_set('memory_limit', '256M');
        if ($mem_set === false) {
            error_log("AhoyRipper: ini_set('memory_limit', '256M') failed — check disable_functions or open_basedir restrictions");
        }

        // Set download-specific headers just before streaming — ensures error
        // responses above return JSON with default Content-Type, not binary.
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('X-Download-Options: noopen');
        // Suppress PHP's automatic chunked transfer encoding for binary streams.
        // PHP adds Transfer-Encoding: chunked for large responses; identity
        // forces raw bytes so the Content-Length header is respected.
        header('Transfer-Encoding: identity');
        // Explicitly close connection after this response to prevent keep-alive
        // issues where long-running downloads cause premature client cut-off.
        // This is set here (not earlier in the action) so that early-exit error
        // responses (429, 504, 500) are not affected — those must leave the
        // connection open so the client can read the full JSON error body.
        header('Connection: close');

        $fp = fopen($actual_file, 'rb');
        if (!$fp) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to read downloaded file.', 'request_id' => $request_id]); // @codingStandardsIgnoreLine
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
        logRequest('download', 200, ['filesize_bytes' => $filesize, 'format_id' => $format_id]);
        exit;
    }

case 'progress':
    case 'health': {
        // Health/progress — lightweight endpoints, no daily quota or rate limiting.
        // Note: all security headers are already set at the top of the script.

        $version = $GLOBALS['__ytdlp_version'] ?: 'not installed';
        $ffmpeg = $GLOBALS['__ffmpeg_version'] ?: 'not installed';

        $ytdlp_cache_ttl = null;
        $ytdlp_cache_expires_at = null;
        if ($version_cache_file && is_readable($version_cache_file)) {
            $cached = @json_decode(@file_get_contents($version_cache_file), true);
            if ($cached && is_array($cached)) {
                $exp = $cached['exp'] ?? 0;
                $ytdlp_cache_expires_at = date('c', $exp);
                $ytdlp_cache_ttl = max(0, $exp - time());
            }
        }

        $ffmpeg_cache_ttl = null;
        $ffmpeg_cache_expires_at = null;
        if ($ffmpeg_cache_file && is_readable($ffmpeg_cache_file)) {
            $cached = @json_decode(@file_get_contents($ffmpeg_cache_file), true);
            if ($cached && is_array($cached)) {
                $exp = $cached['exp'] ?? 0;
                $ffmpeg_cache_expires_at = date('c', $exp);
                $ffmpeg_cache_ttl = max(0, $exp - time());
            }
        }

        // Populate __ytdlp_probe from cache if available and probe is requested.
        // This ensures cached probe results are available when the response array
        // is built below without needing to run the probe again.
        $probe_cache_file = '/tmp/ahoyrip_ytdlp_probe.cache';
        $do_probe = isset($_GET['probe']) && $_GET['probe'] === '1';
        if ($do_probe && $probe_cache_file && is_readable($probe_cache_file)) {
            $cached = @json_decode(@file_get_contents($probe_cache_file), true);
            if ($cached && is_array($cached) && ($cached['exp'] ?? 0) > time()) {
                $GLOBALS['__ytdlp_probe'] = $cached['result'] ?? null;
            }
        }

        $yt_dlp_ok = !empty($version) && strpos($version, 'not installed') === false;
        $ffmpeg_ok = !empty($ffmpeg) && strpos($ffmpeg, 'not installed') === false;

        $out = [
            'status' => ($yt_dlp_ok && $ffmpeg_ok) ? 'ok' : 'degraded',
            'server_time' => date('c'),
            'request_id' => $request_id,
            'yt_dlp_version' => $version,
            'ffmpeg_version' => $ffmpeg,
            'yt_dlp_ok' => $yt_dlp_ok,
            'ffmpeg_ok' => $ffmpeg_ok,
            'yt_dlp_cache_expires_at' => $ytdlp_cache_expires_at,
            'yt_dlp_cache_ttl_seconds' => $ytdlp_cache_ttl,
            'ffmpeg_cache_expires_at' => $ffmpeg_cache_expires_at,
            'ffmpeg_cache_ttl_seconds' => $ffmpeg_cache_ttl,
        ];

        // yt-dlp live probe — disabled by default (add ?probe=1 to enable).
        // Running a real YouTube probe adds ~1-3s of latency per uncached health check
        // (proc_open + yt-dlp startup + network round-trip). The probe is useful when
        // a client wants to verify end-to-end connectivity, but adds unnecessary overhead
        // for routine load checks. The probe result is cached for 5 minutes regardless.
        if ($do_probe) {
            // Only run the probe if the cache did not already populate __ytdlp_probe
            // (the cache-read above set $GLOBALS['__ytdlp_probe'] when a cached result existed).
            if (!isset($GLOBALS['__ytdlp_probe'])) {
                // Use a fast, stable YouTube video for the probe — short, public,
                // unlikely to be geo-restricted. Timeout of 15s keeps health responsive.
                // --skip-download fetches metadata without downloading the full file,
                // saving bandwidth and keeping the health check lightweight.
                $probe_out = $probe_err = '';
                $probe_exit = -1;
                $probe_ok = runYtdlp('--dump-json --no-playlist -q --skip-download -- https://www.youtube.com/watch?v=dQw4w9WgXcQ', $probe_out, $probe_err, $probe_exit, 15);
                $probe_result = $probe_ok && $probe_exit === 0 && $probe_out
                    ? json_decode($probe_out, true)
                    : null;
                $GLOBALS['__ytdlp_probe'] = $probe_result
                    ? ['ok' => true, 'title' => substr($probe_result['title'] ?? '', 0, 80), 'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']
                    : ['ok' => false, 'error' => trim($probe_err ?: $probe_out), 'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'];
                if ($probe_cache_file) {
                    @file_put_contents($probe_cache_file, json_encode([
                        'result' => $GLOBALS['__ytdlp_probe'],
                        'exp' => time() + 300, // 5 min TTL
                    ]));
                }
            }
            // Always include probe result in response when ?probe=1 is set,
            // whether it came from cache or was just computed.
            $out['yt_dlp_probe'] = $GLOBALS['__ytdlp_probe'];
        }
        // When no probe is requested, the yt_dlp_probe field is intentionally
        // omitted from the response (not null, absent) so the response shape
        // is stable and clients can distinguish "probe disabled" from errors.

        // System resource metrics (Linux-only, gracefully omitted on other platforms)
        // Uptime — first line of /proc/uptime is uptime in seconds (format: "X.Y Y")
        $uptime_bytes = @file_get_contents('/proc/uptime');
        if ($uptime_bytes !== false) {
            $parts = preg_split('/\s+/', trim($uptime_bytes));
            if (isset($parts[0]) && is_numeric($parts[0])) {
                $out['server_uptime_seconds'] = (int)floor((float)$parts[0]);
            }
        }
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load !== false) {
                $out['load_avg'] = array_map(fn($v) => round($v, 2), $load);
            }
        }

        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo) {
            // Match "MemAvailable:" (available since kernel 3.14) or fall back to MemFree
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $avail_m) &&
                preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $total_m)) {
                $out['memory_available_pct'] = round(($avail_m[1] / $total_m[1]) * 100, 1);
            }
        }

        $free = @disk_free_space('/');
        if ($free !== false) {
            $out['disk_free_gb'] = round($free / (1024 * 1024 * 1024), 2);
        }

        header('Cache-Control: no-cache');
        echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    }
    default: {
        http_response_code(400);
        echo json_encode([
            'error' => 'Unknown action. Use ?action=info, ?action=download, ?action=progress, or ?action=health.',
            'error_code' => 'UNKNOWN_ACTION',
            'request_id' => $request_id,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    }
}
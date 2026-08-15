<?php
/**
 * AhoyRipper - Shared Test Utilities
 *
 * Canonical copies of the core functions under test, sourced from api.php.
 * Test files include this instead of duplicating function bodies, ensuring
 * tests always exercise the exact code deployed to production.
 *
 * If a function is updated in api.php, copy the new version here and run
 * the test suite — if tests pass with the new code, the copy here is correct.
 * If tests fail, the divergence reveals a behavioural change that needs review.
 *
 * NO business logic lives here — only verbatim copies of api.php functions.
 */

// Shared constant: maximum filename length in characters.
// Mirrors MAX_FILENAME_LEN from src/api.php.
define('MAX_FILENAME_LEN', 80);

/**
 * Sanitize a value for JSON output.
 * @param mixed $s
 * @return string
 */
function clean($s) {
    // Return 'Unknown' for null, empty string, or whitespace-only string.
    // Integer 0 is NOT treated as Unknown — it is a valid numeric value that
    // appears in yt-dlp metadata (e.g., height=0 for audio-only formats).
    // Passing 0 through as '0' (string) keeps the UI consistent and prevents
    // silent label corruption (e.g., "0kbps m4a" would become "Unknown kbps m4a").
    // Whitespace-only strings from yt-dlp metadata would produce blank or
    // space-filled labels (e.g., "  kbps m4a") — trim before checking emptiness.
    if (is_string($s)) {
        $s = trim($s);
        if ($s === '') return 'Unknown';
    } elseif ($s === null) {
        return 'Unknown';
    }
    // Reject booleans, arrays and objects — yt-dlp metadata is always scalar
    // (string, int, float, or null). A boolean in a format label field would
    // become "1" or "" (empty string) via (string) cast, corrupting the label
    // silently. An array/object would become the literal string "Array", also
    // corrupting the API response. Return null for all of these so the ternary
    // `$format_note ?: null` on the format array correctly produces null rather
    // than the truthy string 'Unknown' (which would cause the label builder to
    // append "Unknown" to the format label).
    if (is_bool($s) || is_array($s) || is_object($s)) return null;
    // No htmlspecialchars — API outputs JSON, not HTML.
    // Type coercion to string is sufficient.
    return (string)$s;
}

/**
 * Classify a yt-dlp error message into a structured API error code.
 * @param string $raw_err  Raw stderr output from yt-dlp
 * @param int|null $exit_code  yt-dlp process exit code
 * @return array|null  ['code' => string, 'msg' => string, 'status' => int] or null
 */
function classifyYtdlpError($raw_err, $exit_code = null) {
    $err_lower = strtolower($raw_err);
    if (preg_match('/geo.*restriction|this video is available in|geo.?restricted/i', $err_lower)) {
        return ['code' => 'GEOBLOCKED', 'msg' => 'This video is geo-restricted and not available in your region.', 'status' => 451];
    }
    if (preg_match('/video is private|this video is private/i', $err_lower)) {
        return ['code' => 'PRIVATE_VIDEO', 'msg' => 'This video is private and cannot be downloaded.', 'status' => 403];
    }
    // "authentication required" must be checked separately because the merged pattern
    // "authentication.*required" requires the word "required" to appear twice —
    // yt-dlp only says it once ("authentication required"), so we match it directly.
    // "sign in to confirm" is yt-dlp's bot-confirm message (Google/YouTube).
    if (preg_match('/authentication required|login.*required|this video requires login|sign in to confirm/i', $err_lower)) {
        return ['code' => 'LOGIN_REQUIRED', 'msg' => 'This video requires login or subscription.', 'status' => 401];
    }
    if (preg_match('/not.*support|unsupported site|is not a supported URL/i', $err_lower)) {
        return ['code' => 'UNSUPPORTED_SITE', 'msg' => 'This site is not supported by yt-dlp.', 'status' => 404];
    }
    if (preg_match('/playlist.*not.*found|does not exist/i', $err_lower)) {
        return ['code' => 'PLAYLIST_MISSING', 'msg' => 'Playlist not found or no longer exists.', 'status' => 404];
    }
    if (preg_match('/copyright|\binfringe\b|removed.*by|content.*strike/i', $err_lower)) {
        return ['code' => 'COPYRIGHT_REMOVED', 'msg' => 'This content has been removed due to a copyright claim.', 'status' => 451];
    }
    if (preg_match('/too.*many.*requests|429/i', $err_lower)) {
        return ['code' => 'SOURCE_RATE_LIMITED', 'msg' => 'The source site is rate-limiting requests. Try again in a few minutes.', 'status' => 429];
    }
    if (preg_match('/video (has been )?(removed|delisted|unavailable|deleted)|this video (is no longer available|has been (removed|delisted|deleted))|video (has been )?removed|video (is )?unavailable|video (is )?deleted/i', $err_lower)) {
        return ['code' => 'VIDEO_UNAVAILABLE', 'msg' => 'This video is no longer available or has been removed.', 'status' => 410];
    }
    if (preg_match('/age.*restriction|under age|video is age.*restricted|age restricted/i', $err_lower)) {
        return ['code' => 'AGE_RESTRICTED', 'msg' => 'This video is age-restricted and cannot be downloaded without verification.', 'status' => 403];
    }
    if (preg_match('/certificate.*expired|ssl.*error|sslerr|tls handshake/i', $err_lower)) {
        return ['code' => 'SSL_ERROR', 'msg' => 'Secure connection to the source failed. Try again shortly.', 'status' => 502];
    }

    // "process timed out" is produced by the PHP-side timeout in the inline
    // proc_open timeout handler (api.php).
    // Distinct from connection-level "timed out" which implies a network failure.
    // The PHP-side timeout fires when (time() - $start) > INFO_TIMEOUT (configurable
    // via YTDLP_TIMEOUT env var, default 45s) and terminates the yt-dlp process.
    // INFO_TIMEOUT (controlled by YTDLP_TIMEOUT) limits the info action;
    // DOWNLOAD_TIMEOUT (controlled by YTDLP_DOWNLOAD_TIMEOUT) limits the download action.
    // This means the server reached the source but it was too slow to respond within
    // the allowed window. Return 504 so the client distinguishes it from CONNECTION_FAILED
    // (502) which implies a network or DNS issue on our end.
    if (preg_match('/process timed out|read at byte.*timeout/i', $err_lower)) {
        return ['code' => 'SOURCE_TIMEOUT', 'msg' => 'The source site took too long to respond. Try a smaller format (audio-only is fastest) or try again when the site is less busy.', 'status' => 504];
    }

    // \b(?!process )timed out\b — "timed out" as a standalone word, NOT preceded
    // by "Process " (PHP-side timeout → SOURCE_TIMEOUT above) and NOT followed by
    // " after" (PHP timeout format: "Process timed out after 45s"). The negative
    // lookahead (?!) at word boundary rejects "Process timed out" at the word level
    // rather than relying solely on the (?<!Process ) lookbehind, making the intent
    // explicit and robust against future variations of the PHP timeout message.
    // \bi?/o timeout\b — IO timeout as a standalone word (handles "i/o timeout").
    if (preg_match('#connection.*fail|dns.*fail|could not connect|\bi?/o timeout\b|connection timed out|\b(?!process )timed out\b|connection reset|broken pipe|unable to connect|connection refused|getaddrinfo failed|name or service not known|network is unreachable|no route to host#i', $err_lower)) {
        return ['code' => 'CONNECTION_FAILED', 'msg' => 'Could not connect to the source. Check your network and try again.', 'status' => 502];
    }
    if (preg_match('/file.*larger|file.*too large|size.*exceed|exceeds.*limit/i', $err_lower)) {
        return ['code' => 'FILE_TOO_LARGE', 'msg' => 'This file exceeds the maximum size for this server. Try an audio-only or lower-resolution format.', 'status' => 413];
    }
    if (preg_match('/requested format(?!s)|requested.*not.*available|format.*not.*available|does not contain|does not match/i', $err_lower)) {
        return ['code' => 'FORMAT_UNAVAILABLE', 'msg' => 'That format is not available for this video. Select another from the list.', 'status' => 422];
    }
    // yt-dlp emits "content is not allowed" (with status 451 from some extractors) when
    // the source blocks content on legal/TOS grounds — distinct from HTTP 403 which
    // signals an IP ban (SOURCE_FORBIDDEN). Also catches explicit TOS-violation messages.
    // The 'disallowed.*content' check is kept separate from 'content.*violat' so that
    // a plain "disallowed content" (no violation language) is NOT classified here —
    // it falls through to SOURCE_FORBIDDEN (HTTP 403) if the message contains "content
    // is not allowed" specifically from yt-dlp, use the content-disallowed sentinel.
    // Negative lookahead (?!\s+content\b) prevents "disallowed content" (two separate words
    // where "content" immediately follows "disallowed") from matching — that pattern
    // fires for generic "disallowed content" errors that should route to SOURCE_FORBIDDEN.
    // (?<!\bdisallowed\s) prevents "content" preceded by "disallowed " from matching
    // (same intent as the negative lookahead above, belt-and-suspenders).
    if (preg_match('/\bdisallowed\b(?!\s+content\b)(?!.*\bTOS\b)(?!.*\bterms\b)|content-disallow(ed)?\b|TOS.*violat|terms.*of.*service.*violat|violat.*(TOS|terms.*of.*service)/i', $err_lower)) {
        return ['code' => 'DISALLOWED_CONTENT', 'msg' => 'This content is not available due to a terms of service or legal violation.', 'status' => 451];
    }
    // HTTP error responses from the source site (e.g. "HTTP Error 403: Forbidden").
    // yt-dlp emits these when the source returns a non-2xx status. The numeric
    // status is extracted from the message for classification; 403/404/429 are the
    // most common and map to existing error codes. Others fall through to a generic
    // upstream HTTP error response.
    if (preg_match('/http error (\d+)/i', $err_lower, $m)) {
        $code = (int)$m[1];
        if ($code === 403) {
            return ['code' => 'SOURCE_FORBIDDEN', 'msg' => 'The source site blocked this request (HTTP 403). Try a different format or use AhoyVPN to change your exit IP.', 'status' => 403];
        }
        if ($code === 401 || $code === 407) {
            return ['code' => 'LOGIN_REQUIRED', 'msg' => 'This content requires authentication. Sign in to the platform in your browser, or pass cookies to yt-dlp (see README).', 'status' => 401];
        }
        if ($code === 404) {
            return ['code' => 'SOURCE_NOT_FOUND', 'msg' => 'The source returned HTTP 404 — the content may have been moved or deleted.', 'status' => 404];
        }
        if ($code === 429) {
            return ['code' => 'SOURCE_RATE_LIMITED', 'msg' => 'The source site is rate-limiting requests. Try again in a few minutes.', 'status' => 429];
        }
        if ($code === 500 || $code === 502 || $code === 503) {
            return ['code' => 'SOURCE_SERVER_ERROR', 'msg' => "The source site returned HTTP $code and is having issues. Try again shortly.", 'status' => 502];
        }
        // Other HTTP errors — surface the status but give a generic message.
        return ['code' => 'SOURCE_HTTP_ERROR', 'msg' => "The source site returned HTTP $code. Try again shortly.", 'status' => 502];
    }
    // yt-dlp exit codes carry semantic meaning that supplements text classification.
    // Exit code 1 is the most common error code — it means "there was a problem" but often
    // carries no descriptive stderr text (just "error" or empty). Fall back to it only
    // after all specific text-pattern checks above have been exhausted.
    // Text-based matches take absolute precedence — a geo-blocked video that also produces
    // exit code 1 still returns GEOBLOCKED (451), not FORMAT_UNAVAILABLE (422).
    if ($exit_code !== null && $exit_code !== 0) {
        if ($exit_code === 1) {
            return ['code' => 'FORMAT_UNAVAILABLE', 'msg' => 'That format is not available for this video. Select another from the list.', 'status' => 422];
        }
        // Exit codes ≥2 indicate serious errors (download failed, post-processing failed, etc.)
        if ($exit_code >= 2) {
            return ['code' => 'YTDLP_ERROR', 'msg' => 'yt-dlp encountered an error processing this request.', 'status' => 422];
        }
    }
    return null;
}

/**
 * Resolve the playlist URL parameter to yt-dlp playlist flags.
 * Mirrors the canonical implementation in src/api.php.
 *
 * yt-dlp accepts --yes-playlist (fetch all videos in a playlist) and
 * --no-playlist (fetch single video only). yt-dlp does NOT support
 * --playlist true/false — that syntax is rejected as ambiguous.
 *
 * @param string|null $playlist_get  $_GET['playlist'] value
 * @return array  Array of flag strings, e.g. ['--yes-playlist'] or ['--no-playlist']
 */
function resolvePlaylistFlag($playlist_get) {
    if (isset($playlist_get) && $playlist_get === '1') {
        return ['--yes-playlist'];
    }
    return ['--no-playlist'];
}

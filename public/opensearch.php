<?php
/**
 * AhoyRipper OpenSearch Description
 *
 * Converts from static XML to PHP to support dynamic base URL via the
 * Host header. This enables correct OpenSearch auto-discovery for custom-domain
 * deployments where the search template URLs must point to the deployer's
 * own domain, not a hardcoded default.
 *
 * How it works:
 * - index.php references this file with <link rel="search" type="application/opensearchdescription+xml">
 * - nginx rewrites /opensearch.xml to this file via PHP-FPM
 * - nginx sets Content-Type: application/opensearchdescription+xml via location block
 * - PHP derives the canonical base URL from the incoming Host header
 *
 * Note: OpenSearch 1.1 does not define an <AdultContent> element.
 * The static XML previously included <AdultContent> which is not part of the
 * spec and is ignored by all browsers — it has been removed.
 */

error_reporting(0);
ini_set('display_errors', '0');

// ─── Security headers ────────────────────────────────────────────────────────
// Hardens the OpenSearch XML endpoint against the same class of attacks
// as api.php. Defense-in-depth: nginx sets most of these globally, but
// the PHP layer mirrors them here so the endpoint is protected even when
// served outside nginx (PHP built-in server, reverse proxy bypass, etc.).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('X-Robots-Tag: noindex, noai, noimage, noydir');
// X-Download-Options: noopen — defense-in-depth for consistency with index.php.
// Relevant if content type is ever misdetected as an attachment; harmless for XML.
header('X-Download-Options: noopen');
header_remove('X-Powered-By');
// Generate a request correlation ID — mirrors the X-Request-ID added by api.php
// so nginx access log, PHP error log, and client-side events can be correlated.
$page_request_id = bin2hex(random_bytes(8));
header('X-Request-ID: ' . $page_request_id);

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host_raw = $_SERVER['HTTP_HOST'] ?? '';
// Reject obviously malformed Host headers early — a nonsense value like "\x00<meta>"
// would produce an invalid URL template and confuse browser OpenSearch auto-discovery.
// Return a JSON error instead of falling through to XML output with a broken BASE_URL.
// Control chars (\x00-\x1F, \x7F) corrupt XML; \r\n enables CRLF injection into headers.
if ($host_raw === '' || strlen($host_raw) > 253 || preg_match('/[\x00-\x1F\x7F<>"\'\r\n]/', $host_raw)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'error' => 'Invalid or missing Host header.',
        'error_code' => 'INVALID_HOST',
        'request_id' => $page_request_id,
    ]);
    exit;
}
$host = htmlspecialchars($host_raw, ENT_QUOTES, 'UTF-8');
$BASE_URL = $scheme . '://' . $host;

/* ── Output starts with XML declaration — no whitespace before it ── */
$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">
  <ShortName>AhoyRipper</ShortName>
  <Description>Download video and audio from YouTube, TikTok, Twitter, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo and 1872+ other platforms. Free forever, no signup required, no ads.</Description>
  <Url type="text/html" method="get" template="{$BASE_URL}/?url={searchTerms}"/>
  <Url type="application/xhtml+xml" method="get" template="{$BASE_URL}/?url={searchTerms}"/>
  <Image width="16" height="16" type="image/png">{$BASE_URL}/favicon-180.png</Image>
  <Image width="64" height="64" type="image/png">{$BASE_URL}/favicon-512.png</Image>
  <Language>en-us</Language>
  <InputEncoding>UTF-8</InputEncoding>
  <OutputEncoding>UTF-8</OutputEncoding>
</OpenSearchDescription>
XML;

echo $xml;

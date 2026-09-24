<?php
/**
 * AhoyRipper Sitemap
 *
 * Dynamically generates the XML sitemap using the canonical base URL from the
 * Host header — enabling correct SEO for custom-domain deployments where the
 * sitemap must point to the deployer's own domain, not a hardcoded default.
 *
 * How it works:
 * - nginx rewrites /sitemap.xml to this file via PHP-FPM (same pattern as opensearch.php)
 * - nginx sets Content-Type: text/xml via location block
 * - PHP derives the canonical base URL from the incoming Host header
 */

error_reporting(0);
ini_set('display_errors', '0');

// ─── Security headers ────────────────────────────────────────────────────────
// Mirrors the security headers set by api.php and opensearch.php for consistency.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('X-Robots-Tag: index, follow');
// X-Download-Options: noopen — defense-in-depth for consistency with index.php.
// Relevant if content type is ever misdetected as an attachment; harmless for text/xml.
header('X-Download-Options: noopen');
header_remove('X-Powered-By');
// Generate a request correlation ID for support tickets and log correlation.
$request_id = bin2hex(random_bytes(8));
header('X-Request-ID: ' . $request_id);

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host_raw = $_SERVER['HTTP_HOST'] ?? '';
// Reject obviously malformed Host headers to prevent injection into the sitemap URL.
// A nonsense value would produce an invalid URL and confuse search engine crawlers.
if ($host_raw === '' || strlen($host_raw) > 253 || preg_match('/[\x00-\x1F\x7F<>"\'\r\n]/', $host_raw)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "400 Bad Request: Invalid Host header\n";
    exit;
}
$host = htmlspecialchars($host_raw, ENT_QUOTES, 'UTF-8');
$BASE_URL = $scheme . '://' . $host;

// Lastmod is today's date — sitemap should be re-fetched periodically by crawlers.
// Using a stable weekly cadence rather than a fixed date ensures the lastmod
// value doesn't go stale between deployments.
$lastmod = date('Y-m-d');

// ── Output starts with XML declaration — no whitespace before it ──
$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset
  xmlns="https://www.sitemaps.org/schemas/sitemap/0.9"
  xmlns:video="https://www.google.com/schemas/sitemap-video/1.1"
  xmlns:image="https://www.google.com/schemas/sitemap-image/1.1">
  <url>
    <loc>{$BASE_URL}/</loc>
    <lastmod>{$lastmod}</lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
    <image:image>
      <image:loc>{$BASE_URL}/og-image.webp</image:loc>
      <image:caption>Download video and audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo and 1872+ platforms — free, no signup required.</image:caption>
      <image:title>AhoyRipper - Free Online Media Ripper</image:title>
    </image:image>
    <image:image>
      <image:loc>{$BASE_URL}/og-image.png</image:loc>
      <image:caption>Download video and audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo and 1872+ platforms — free, no signup required.</image:caption>
      <image:title>AhoyRipper - Free Online Media Ripper</image:title>
    </image:image>
  </url>
</urlset>
XML;

echo $xml;

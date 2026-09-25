<?php
/**
 * AhoyRipper robots.txt
 *
 * Dynamically generates robots.txt using the canonical base URL from the Host header —
 * enabling correct SEO for custom-domain deployments where the Sitemap URL must point
 * to the deployer's own domain, not a hardcoded default.
 *
 * How it works:
 * - nginx rewrites /robots.txt to this file via PHP-FPM (same pattern as opensearch.php)
 * - nginx sets Content-Type: text/plain via location block
 * - PHP derives the canonical base URL from the incoming Host header
 */

error_reporting(0);
ini_set('display_errors', '0');

// ─── Security headers ────────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
// X-Download-Options: noopen — defense-in-depth for consistency with index.php.
// Relevant if content type is ever misdetected as an attachment; harmless for text/plain.
header('X-Download-Options: noopen');
header_remove('X-Powered-By');
$request_id = bin2hex(random_bytes(8));
header('X-Request-ID: ' . $request_id);

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host_raw = $_SERVER['HTTP_HOST'] ?? '';
if ($host_raw === '' || strlen($host_raw) > 253 || preg_match('/[\x00-\x1F\x7F<>"\'\r\n]/', $host_raw)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "400 Bad Request: Invalid Host header\n";
    exit;
}
$host = htmlspecialchars($host_raw, ENT_QUOTES, 'UTF-8');
$BASE_URL = $scheme . '://' . $host;

// Cache-Control: no-store — prevents bots and CDNs from caching this dynamic
// response which varies by Host header. Each custom-domain deployment must
// serve its own robots.txt reflecting its own domain. Harmless for text/plain.
header('Cache-Control: no-store');

$txt = <<<ROBOTS
User-agent: *
Allow: /

# Block AI training crawlers that ignore robots.txt conventions.
# These bots systematically scrape content for LLM / generative AI training.
User-agent: AdsBot
User-agent: AiBot
User-agent: Amazonbot
User-agent: Bytespider
User-agent: CCBot
User-agent: ChatGPT-User
User-agent: ClaudeBot
User-agent: Claudebot
User-agent: CohereBot
User-agent: Cometbot
User-agent: Diffbot
User-agent: Google-Extended
User-agent: GPTBot
User-agent: GoogleOther
User-agent: GrokBot
User-agent: DeepSeekBot
User-agent: SentiBot
User-agent: AnthropicBot
User-agent: ImagesiftBot
User-agent: KenjinBot
User-agent: Meta-ExternalAgent
User-agent: Meta-ExternalFetcher
User-agent: Omgilibot
User-agent: Omgili
User-agent: Omgili Media Bot
User-agent: PerplexityBot
User-agent: PetalBot
User-agent: SemrushBot-Crawler
User-agent: YouBot
# Applebot and FacebookBot are allowed — they are legitimate crawlers used for
# search indexing (Applebot: Spotlight/Siri) and social link previews (FacebookBot:
# Open Graph meta tags). They are NOT AI training scrapers. Blocking them would
# prevent AhoyRipper from appearing in Apple Spotlight search results and would
# break rich link previews when AhoyRipper URLs are shared on Facebook/Meta.
# Allow them to crawl the public-facing HTML pages (they respect the /src/ disallow).
# AhoyBot is our own crawler — allow it to crawl the site normally so our
# own SEO and indexing pipelines work correctly.
User-agent: AhoyBot
Allow: /

# General crawlers (User-agent: *) — applies to all bots not listed above.
# IMPORTANT: Disallow rules MUST come BEFORE Allow rules in the same
# User-agent block. crawlers use first-matching-rule precedence (not
# "most specific wins"), so "Allow: /" before "Disallow: /src/" means
# the API would be allowed for bots without specific blocks. Place more-specific
# rules first. Specific crawlers (GPTBot, ClaudeBot, etc.) have their own blocks
# above and do NOT fall through to this section.
User-agent: *
# Block all crawlers from the API endpoint — returns JSON, not crawlable.
# This must come BEFORE Allow: / so the disallow is evaluated first.
Disallow: /src/
# og-image.webp is referenced by index.php's og:image meta tag and used in
# social shares. Allow explicitly so crawlers can fetch the preview image.
Allow: /og-image.webp
# RFC 9116 §3 recommends security.txt be accessible to scanners even when
# general Disallow: rules are in effect. Adding an explicit Allow for the
# /.well-known/ path ensures security researchers and vulnerability scanners
# can discover the security policy regardless of other robots rules.
Allow: /.well-known/security.txt
# Allow all other paths — must come AFTER /src/ disallow so the API is blocked.
Allow: /

# Security contact — RFC 9116
Sitemap: {$BASE_URL}/sitemap.xml
ROBOTS;

echo $txt;

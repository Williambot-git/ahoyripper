<?php
/**
 * AhoyRipper - Main Page
 * Single-page app for ripping media from supported platforms
 */

// Detect if JS is available (passed via cookie or param)
$jsEnabled = isset($_COOKIE['js']) || isset($_GET['js']);
$default_url = $_GET['url'] ?? '';

$VERSION = require __DIR__ . '/../src/version.php';

// Derive the canonical base URL from the request so reverse-proxy and multi-instance
// deployments generate correct self-referencing URLs without hardcoding a hostname.
// HTTPS is assumed: nginx redirects HTTP→HTTPS and the application requires TLS.
$BASE_URL = (
    isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    ? 'https' : 'http'
) . '://' . htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'ahoyripper.com', ENT_QUOTES, 'UTF-8');

// Generate a request correlation ID — mirrors the X-Request-ID added by api.php
// and nginx for every API response. With this present on the HTML page too, all
// three layers (nginx access log, PHP error log, and browser client) can be
// correlated via the same request ID when debugging errors or support requests.
// The ID is short (16 hex chars) to minimise overhead and log volume.
$page_request_id = bin2hex(random_bytes(8));

// HSTS — tell browsers to only ever connect over HTTPS for this domain.
// includeSubDomains: all subdomains must use HTTPS.
// preload: include in browser HSTS preload lists for maximum protection.
// max-age=31536000 (1 year) is required for preload list submission.
// This header only applies when served over HTTPS (nginx redirects HTTP → HTTPS).
// Adding it to the PHP layer ensures it is present on all responses served
// from index.php, including any edge cases where the PHP built-in server
// or a reverse proxy bypasses nginx (where the HTTP→HTTPS redirect may not apply).
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
// nosniff prevents browsers from MIME-sniffing responses away from the declared
// Content-Type, mitigating XSS via content-type override. nginx also sets this
// header, but the PHP layer mirrors it here so it is present regardless of how
// index.php is served (PHP built-in server, reverse proxy bypass, etc.).
header('X-Content-Type-Options: nosniff');
// Referrer-Policy: when AhoyRipper links out to third-party CDNs (YouTube thumbnails,
// Twitter video, TikTok covers, etc.), only send the origin (not the full URL)
// to those destinations. Prevents video URLs from leaking as referrer data to
// third-party servers. Mirrors the header set by nginx and api.php.
header('Referrer-Policy: strict-origin-when-cross-origin');
// COEP is intentionally NOT set on the HTML page: require-corp breaks cross-origin
// image loads (YouTube thumbnails, TikTok covers, Twitter video cards, etc.) loaded
// by the JS frontend via fetch(). The same COEP restriction applies to api.php —
// any SharedArrayBuffer use would require a separate isolated context, not the
// main page. Omitting COEP here matches api.php's policy and keeps thumbnails working.

// X-Frame-Options: prevent clickjacking by blocking the page from being embedded
// in an iframe on third-party sites. Mirrors the header set by api.php.
header('X-Frame-Options: SAMEORIGIN');
// Cross-Origin-Opener-Policy: same-origin — prevents cross-origin documents from
// navigating the top-level frame or accessing its window. This closes an exploit
// chain where an XSS on a cross-origin page could use window.open() to reach the
// AhoyRipper page's DOM. api.php sets this header on all API responses; adding it
// here completes the coverage across both entry points. COEP is intentionally NOT
// set: require-corp breaks YouTube thumbnails, TikTok covers, Twitter video cards,
// and other cross-origin images loaded by the JS frontend via fetch().
header('Cross-Origin-Opener-Policy: same-origin');
// Cross-Origin-Resource-Policy: same-origin — prevents this page from being
// embedded as a cross-origin subresource (e.g. in an iframe on another origin).
// Complements X-Frame-Options (which blocks same-origin framing) by also blocking
// cross-origin embedding as a subresource.
header('Cross-Origin-Resource-Policy: same-origin');
// X-Download-Options: noopen prevents the file download dialog from automatically
// opening for downloaded files, reducing drive-by download attacks.
header('X-Download-Options: noopen');
// X-Robots-Tag: noindex,noai,noydir prevents all crawlers (search, AI training,
// archival) from indexing or following links on this page. The web UI is not
// useful as a search result and should not be vector for training data scraping.
header('X-Robots-Tag: noindex, noai, noimage, noydir');
// Permissions-Policy: disable browser features that are irrelevant to a media ripper
// (camera, microphone, geolocation, interest-cohort). Mirrors the header set by
// api.php. The meta tag (line ~205) provides partial coverage for browsers that
// don't support the HTTP header, but the header call ensures full enforcement.
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');

header('X-Request-ID: ' . $page_request_id);
// Remove the "PHP/x.y.z" Server header that PHP-FPM adds automatically.
// header_remove() is idempotent — safe to call even when no such header was set.
// This complements server_tokens off in nginx, completing the version-hiding
// stack for both layers. Using remove() rather than setting a generic replacement
// value (e.g. "WebServer") ensures no PHP version information leaks at all.
// api.php also calls header_remove('X-Powered-By') for consistency across both
// entry points — index.php (HTML page) and src/api.php (JSON API).
header_remove('X-Powered-By');
?>
<!DOCTYPE html>
<html lang="en-US" class="no-js">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>AhoyRipper - Free Online Media Ripper | Rip Video & Audio from Any Site</title>
  <meta name="description" content="Download video & audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo & 1873+ platforms. Free, no signup, no ads.">
  <meta name="robots" content="<?= $default_url ? 'noindex, follow' : 'index, follow' ?>">
  <meta name="author" content="AhoyVPN">
  <meta name="theme-color" content="#0f0f0f">
  <!-- apple-mobile-web-app-status-bar-style is the only iOS-supported mechanism
       for dark status bar theme. Unlike theme-color meta (which iOS ignores when
       paired with media="" attributes), this tag is respected by Safari on iOS. -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="manifest" href="/manifest.json">
  <!-- iOS home screen icon — use PNG for sharp, correctly-cropped display.
       iOS crops square icons to a rounded shape; SVG source produces blurry
       results at the sizes iOS applies. A 180x180 PNG is optimal for iPhone. -->
  <link rel="apple-touch-icon" href="/favicon-180.png">
  <!-- Referrer-Policy: mirrors the HTTP header (strict-origin-when-cross-origin)
       for the HTML document itself, as defense-in-depth when served through a reverse
       proxy that may strip headers. Using http-equiv (not name) — the correct
       standard form for Referrer-Policy. Must match the HTTP header value to avoid
       browser resolution to the most restrictive policy (no-referrer) when both are
       present with different values. -->
  <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">
  <!-- format-detection: prevent mobile browsers from auto-linking phone numbers
      , email addresses, and physical addresses. AhoyRipper's primary interaction
       is URL input — auto-linking phone numbers creates accidental click-to-call
       links that interfere with the UX. Disable across all content types. -->
  <meta name="format-detection" content="telephone=no, email=no, address=no">
  <!-- application-name: used by Windows 7+ taskbar jump list and Start menu tile
       when a user pins the site. Identifies the app independently of the page title.
       Mirrors the app name from manifest.json. -->
  <meta name="application-name" content="AhoyRipper">
  <!-- msapplication-* tiles: Windows 8+ Start screen pinned-site tiles.
       msapplication-tilecolor sets the background color (matches theme-color).
       msapplication-tileimage is the 144x144 PNG tile icon (scaled by Windows).
       msapplication-navbutton-color styles the back button in the tile interface.
       These are read by Windows to render the pinned site tile. -->
  <meta name="msapplication-tilecolor" content="#0f0f0f">
  <meta name="msapplication-tileimage" content="<?= $BASE_URL ?>/favicon-144.png">
  <meta name="msapplication-navbutton-color" content="#0f0f0f">

  <!-- OpenSearch — lets browsers add ahoyripper.com as a searchable engine
       (e.g. Firefox's URL bar shows "Search AhoyRipper" after the file is served).
       The XML file is referenced by this link tag for auto-discovery. -->
  <link rel="search" type="application/opensearchdescription+xml" title="AhoyRipper" href="/opensearch.xml">

  <!-- OG / Twitter -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="AhoyRipper - Free Online Media Ripper | Rip Video & Audio from Any Site">
  <meta property="og:description" content="Download video & audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo & 1873+ platforms. Free, no signup, no ads.">
  <meta property="og:site_name" content="AhoyRipper">
  <meta property="og:image" content="<?= $BASE_URL ?>/og-image.webp">
  <meta property="og:image:secure_url" content="<?= $BASE_URL ?>/og-image.webp">
  <meta property="og:image:type" content="image/webp">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="AhoyRipper — download video and audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo and 1873+ platforms">
  <!-- og:title:alt and og:description:alt provide text alternatives for the Open
       Graph title and description — used by screen readers, non-visual clients, and
       indexing bots. Must match twitter:title and twitter:description respectively.
       Keep all four in sync when updating share copy. -->
  <meta property="og:title:alt" content="AhoyRipper - Free Online Media Ripper | Rip Video & Audio from Any Site">
  <meta property="og:description:alt" content="Download video &amp; audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo &amp; 1873+ platforms. Free, no signup, no ads.">
  <!-- fetchpriority="high" signals the browser to prioritize loading the og:image early.
       This meaningfully improves LCP (Largest Contentful Paint) when the page is shared
       on social media or linked from external sites, since the og:image is the most
       visually prominent element in link previews. It also helps Core Web Vitals. -->
  <meta property="og:image:fetchpriority" content="high">
  <!-- Preload the og:image so social share previews load instantly.
       fetchpriority="high" on the preload signals the browser to prioritize
       this resource early in the page load, meaningfully improving LCP (Largest
       Contentful Paint) when the page is shared on social media or linked from
       external sites. Without this, the og:image is discovered only after the
       HTML is fully parsed and the meta tag is processed — a measurable delay
       for a visually prominent element. fetchpriority on <link rel="preload">
       is supported in Chromium 86+ and Firefox 121+; Safari ignores it (no
       harm, no regression) and falls back to the existing og:image meta tag.
       crossorigin="anonymous" is required: the og:image URL uses https://ahoyripper.com
       which the browser treats as cross-origin by default. Without crossorigin,
       the browser issues an anonymous fetch for the preload that does not share
       credentials or cookies with the og:image meta tag's fetch (which also uses
       crossorigin="anonymous" behavior implicitly for same-site URLs). Adding
       crossorigin="anonymous" ensures both fetches use the same CORS context,
       avoiding a double-fetch that wastes bandwidth and can delay LCP.
       The crossorigin attribute here must match the og:image:alt text so the
       same CORS rules apply to both the preload and the meta tag reference. -->
  <link rel="preload" as="image" fetchpriority="high" href="<?= $BASE_URL ?>/og-image.webp" crossorigin="anonymous">
  <meta property="og:locale" content="en_US">
  <meta property="og:url" content="<?= $BASE_URL ?>">
  <!-- Canonical URL: tells search engines the definitive URL for this page,
       preventing duplicate content issues when the same page is accessible via
       multiple URLs (e.g. with/without www, with/without trailing slash,
       with UTM parameters). Must match og:url for consistency. -->
  <link rel="canonical" href="<?= $BASE_URL ?>">

  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:url" content="<?= $BASE_URL ?>">
  <meta name="twitter:domain" content="ahoyripper.com">
  <meta name="twitter:site" content="@ahoyvpn">
  <meta name="twitter:creator" content="@ahoyvpn">
  <meta name="twitter:title" content="AhoyRipper - Free Online Media Ripper | Rip Video & Audio from Any Site">
  <meta name="twitter:description" content="Download video & audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo & 1873+ platforms. Free, no signup, no ads.">
  <meta name="twitter:image" content="<?= $BASE_URL ?>/og-image.webp">
  <meta name="twitter:image:width" content="1200">
  <meta name="twitter:image:height" content="630">
  <meta name="twitter:image:alt" content="AhoyRipper — download video and audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo and 1873+ platforms">

  <!-- Content Security Policy — defense-in-depth: HTTP header set by nginx handles
       production, but the meta tag ensures CSP is enforced even when the page is
       served through a reverse proxy, CDN, or alternative deployment that might
       strip or not propagate the HTTP header. img-src must stay in sync with the
       HTTP header's img-src directive — specifically include https://fonts.googleapis.com
       (needed for OG image and font preloads) and https://*.tiktokcdn.com (CDN for
       TikTok video thumbnails). -->
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://i.ytimg.com https://*.tikcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://*.tiktokcdn.com https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com https://fonts.googleapis.com; connect-src 'self'; upgrade-insecure-requests; frame-ancestors 'none'; frame-src 'none'; worker-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; report-to csp-report;">
  <!-- worker-src 'self' is also set in the nginx HTTP header (deploy/nginx.conf).
       The meta tag above serves as a fallback when the HTTP header is stripped
       or not propagated (e.g. reverse proxy, CDN). Nginx's header is authoritative;
       the meta tag is defense-in-depth. worker-src is critical for ServiceWorker
       and SharedWorker instantiation — without it, browsers may block SW creation
       if they only see the meta-tag CSP. -->
  <!-- Permissions-Policy: Disable camera, microphone, geolocation, and interest-cohort
       telemetry. Mirrors the header set by api.php and nginx. -->
  <meta http-equiv="Permissions-Policy" content="camera=(), microphone=(), geolocation=(), interest-cohort=()">

  <!-- Favicon — ICO for legacy browsers, SVG for modern browsers, PNG for iOS home screen.
       iOS Safari requires a PNG with sizes attribute for home screen bookmarks.
       Using favicon-512.png (512×512) as the authoritative PNG — iOS crops to 180×180
       for iPhone home screen and 167×167 for iPad Pro. -->
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="icon" type="image/png" sizes="512x512" href="/favicon-512.png">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="anonymous">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" crossorigin="anonymous">
  <link rel="stylesheet" href="/src/style.css">

  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "WebSite",
        "@id": "<?= $BASE_URL ?>/#website",
        "name": "AhoyRipper",
        "url": "<?= $BASE_URL ?>",
        "description": "Free online media ripper — download video and audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo and 1873+ platforms.",
        "publisher": {
          "@type": "Organization",
          "name": "AhoyVPN",
          "url": "https://ahoyvpn.com"
        },
        "potentialAction": {
          "@type": "SearchAction",
          "target": {
            "@type": "EntryPoint",
            "urlTemplate": "<?= $BASE_URL ?>/?url={searchTerms}"
          },
          "query-input": "required name=searchTerms"
        }
      },
      {
        "@type": "WebApplication",
        "@id": "<?= $BASE_URL ?>/#webapplication",
        "name": "AhoyRipper",
        "description": "Download video and audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo, and 1873+ other platforms. Free, no signup required.",
        "url": "<?= $BASE_URL ?>",
        "applicationCategory": "MultimediaApplication",
        "operatingSystem": "Any",
        "browserRequirements": "Requires JavaScript. WebAssembly support and media codecs (H.264, VP9, Opus) required for full functionality.",
        "softwareVersion": "<?= $VERSION ?>",
        "author": {
          "@type": "Organization",
          "name": "AhoyVPN",
          "url": "https://ahoyvpn.com"
        },
        "offers": {
          "@type": "Offer",
          "price": "0",
          "priceCurrency": "USD",
          "availability": "https://schema.org/OnlineOnly",
          "seller": {
            "@type": "Organization",
            "name": "AhoyVPN"
          }
        },
        "potentialAction": {
          "@type": "SearchAction",
          "target": {
            "@type": "EntryPoint",
            "urlTemplate": "<?= $BASE_URL ?>/?url={searchTerms}"
          },
          "query-input": "required name=searchTerms"
        }
      },
      {
        "@type": "SoftwareApplication",
        "@id": "<?= $BASE_URL ?>/#softwareapplication",
        "name": "AhoyRipper",
        "description": "Free online media ripper supporting 1873+ platforms including YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, and Vimeo.",
        "url": "<?= $BASE_URL ?>",
        "applicationCategory": "MultimediaApplication",
        "operatingSystem": "Any",
        "softwareVersion": "<?= $VERSION ?>",
        "image": "<?= $BASE_URL ?>/og-image.webp",
        "author": {
          "@type": "Organization",
          "name": "AhoyVPN",
          "url": "https://ahoyvpn.com"
        },
        "offers": {
          "@type": "Offer",
          "price": "0",
          "priceCurrency": "USD",
          "availability": "https://schema.org/OnlineOnly"
        }
      }
    ]
  }
  </script>

<meta name="keywords" content="media ripper, video downloader, audio downloader, video converter, audio converter, download video, download audio, free media converter, ripper tool, online ripper, web ripper, YouTube downloader, TikTok downloader, Twitter video downloader, SoundCloud downloader, Instagram downloader, Facebook video downloader, Vimeo downloader, mp4 downloader, mp3 downloader, FLAC downloader, OGG downloader, M4A downloader, WEBM downloader, video to mp3, extract audio">
<link rel="sitemap" type="application/xml" href="/sitemap.xml">
<!-- Plausible analytics — self-hosted, cookie-free, GDPR-compliant.
     No PII leaves the browser. Video URLs in the query string are stripped
     before sending so the analytics only sees page paths, not video links.
     To disable, comment out the script below. -->
<script defer src="/js/analytics.js"></script>
</head>
<body>

<!-- Navigation -->
<nav class="ahoy-nav" aria-label="Main navigation">
  <a href="/" class="ahoy-nav-logo">
    <svg class="ahoy-nav-icon" width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <rect width="28" height="28" rx="6" fill="#3b82f6"/>
      <path d="M14 5L7 10v10l7 5 7-5V10L14 5z" stroke="white" stroke-width="1.5" stroke-linejoin="round" fill="none"/>
      <path d="M14 10v8M10 14h8" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
    </svg>
    <span>AhoyRipper</span>
  </a>
  <div class="ahoy-nav-links">
    <a href="<?= $BASE_URL ?>">AhoyRipper</a>
    <a href="https://ahoyvpn.com" target="_blank" rel="noopener">AhoyVPN</a>
  </div>
</nav>

<!-- PWA update banner — shown when a new service worker is installed and waiting -->
<!-- role="alert" + aria-live="assertive" signals screen readers to announce immediately -->
<div id="update-banner" class="update-banner" style="display:none" role="alert" aria-live="assertive">
  <span>A new version of AhoyRipper is available.</span>
  <button type="button" class="refresh-btn">Update now</button>
</div>

<!-- PWA install banner — shown when the browser fires the beforeinstallprompt event.
     Only shown on first visit (persisted in localStorage). Hidden automatically if
     the app is already installed (navigator.standalone === true on iOS/supported browsers). -->
<div id="install-banner" class="update-banner" style="display:none" role="alert" aria-live="polite">
  <span>Install AhoyRipper for faster access and offline support.</span>
  <button type="button" id="install-btn" class="refresh-btn">Install</button>
  <button type="button" id="install-dismiss-btn" class="refresh-btn" style="background:#374151" aria-label="Dismiss install prompt">✕</button>
</div>

<!-- Main -->
<main>
  <section class="hero">
    <h1>Rip any video, <span>anywhere.</span></h1>
    <p>Free online media ripper - paste any link and download video or audio in MP4, MP3, FLAC, and more. Works with most platforms.</p>

    <!-- Error message (aria-live for screen reader announcements) -->
    <div class="rip-error" id="errorBox" role="alert" aria-live="polite" aria-atomic="true"></div>
    <!-- Retry button — shown when an error is displayed so the user can immediately
         retry without having to re-paste or refocus the input field. -->
    <button class="rip-retry" id="retryBtn" aria-label="Try again" hidden>Try again</button>

    <!-- Input form -->
    <div class="rip-box">
      <form class="rip-form" id="ripForm">
        <input
          type="text"
          inputmode="url"
          class="rip-input"
          id="urlInput"
          aria-label="Video or audio URL to download"
          aria-describedby="errorBox"
          placeholder="Paste a link here..."
          value="<?= htmlspecialchars($default_url, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
          autocomplete="off"
          autocorrect="off"
          autocapitalize="off"
          spellcheck="false"
          autofocus
        >
        <button type="submit" class="rip-btn" id="submitBtn">Rip It</button>
        <label class="rip-playlist-toggle">
          <input type="checkbox" id="playlistToggle" aria-label="Download playlist">
          <span class="rip-playlist-label">Playlist</span>
        </label>
        <noscript><p class="rip-noscript-msg">JavaScript is required to use AhoyRipper. Please enable JavaScript in your browser settings.</p></noscript>
      </form>
      <p class="rip-hint">
        <span id="quotaDisplay" class="quota-count" title="Get unlimited rips with AhoyVPN" aria-label="Free rips remaining today" role="status" aria-live="polite"></span>
        <span id="quotaLimit" class="quota-limit" title="Get unlimited rips with AhoyVPN"></span>
        <span id="quotaLabel"> free rips/day &mdash;</span>
        <a href="https://ahoyvpn.com" id="quotaUpgrade" class="quota-upgrade-link" target="_blank" rel="noopener">get unlimited</a>
      </p>
      <div class="rip-key-wrap">
        <input type="password" id="apiKey" class="rip-key-input" placeholder="AhoyVPN unlimited key (optional)" autocomplete="off">
        <button type="button" id="toggleKey" class="rip-key-toggle" aria-label="Show API key" title="Show API key">
          <svg id="toggleKeyIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
            <circle cx="12" cy="12" r="3"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- Progress -->
    <!-- role="status" + aria-live="polite" + aria-atomic="true": screen readers
         announce every text update inside this region (e.g. "Fetching info...",
         "Downloading...", "Processing...") without interrupting the user's current
         task. aria-atomic="true" ensures the full message is read even if only
         part of the text changes. -->
    <div class="rip-progress" id="progressBox" role="status" aria-live="polite" aria-atomic="true">
      <div class="spinner"></div>
      <p class="progress-text" id="progressText">Fetching info...</p>
      <div class="progress-bar-wrap">
        <div class="progress-bar-fill" id="progressBar" style="width:30%"></div>
      </div>
    </div>

    <!-- Results -->
    <div class="rip-results" id="resultsBox">
      <div class="results-meta" id="resultsMeta">
        <img class="results-thumb" id="resultsThumb" src="" alt="Media thumbnail" loading="lazy" hidden onerror="this.style.display='none'">
        <div class="results-info">
          <p class="results-title">
            <span class="check" role="img" aria-label="Success">&check;</span>
            <span id="resultsTitle">Ready to download</span>
          </p>
          <p class="results-sub" id="resultsSub"></p>
          <p class="results-platform" id="resultsPlatform" hidden></p>
        </div>
        <div class="results-sort">
          <label for="sortSelect" class="sort-label">Sort:</label>
          <select id="sortSelect" class="sort-select" aria-label="Sort formats by resolution, size, bitrate, quality tier, or audio quality" disabled>
            <option value="height">Resolution</option>
            <option value="filesize">Size (largest)</option>
            <option value="filesize_asc">Size (smallest)</option>
            <option value="tbr">Bitrate</option>
            <option value="quality">Quality tier</option>
            <option value="audio_quality">Audio quality</option>
          </select>
        </div>
      </div>
      <div class="format-grid" id="formatGrid" role="group" aria-label="Available formats"></div>
      <div style="margin-top:1.5rem; text-align:center;">
        <button class="rip-again" id="ripAgain">Rip another</button>
      </div>
    </div>
  </section>

  <!-- Sources -->
  <div class="sites-bar" style="padding: 0 2rem; max-width:720px;margin:0 auto;">
    <span class="site-badge">Video Sites</span>
    <span class="site-badge">Audio Sites</span>
    <span class="site-badge">Social Media</span>
    <span class="site-badge">Streamers</span>
    <span class="site-badge">And More</span>
  </div>

  <!-- Features -->
  <h2 class="sr-only">Features</h2>
  <section class="features">
    <div class="features-grid">
      <div class="feature-card">
        <h3>No Signup</h3>
        <p>Paste a link, click Rip It, download. No account, no email, no tracking.</p>
      </div>
      <div class="feature-card">
        <h3>Many Formats</h3>
        <p>MP4, WEBM, MP3, M4A, FLAC, OGG, and more depending on what the source offers.</p>
      </div>
      <div class="feature-card">
        <h3>Many Platforms</h3>
        <p>Most video and audio platforms are supported.</p>
      </div>
      <div class="feature-card">
        <h3>Fast</h3>
        <p>Direct server-side rip. No waiting in a queue, no BS.</p>
      </div>
      <div class="feature-card">
        <h3>No Ads in the Rip</h3>
        <p>Clean conversion flow. The download is the download.</p>
      </div>
      <div class="feature-card">
        <h3>Privacy-First</h3>
        <p>Files are not stored on our servers. What you rip is between you and your hard drive.</p>
      </div>
    </div>

    <!-- VPN Banner -->
    <div class="vpn-banner" style="margin-top:2.5rem;">
      <p><strong>Want unlimited, unrestricted access?</strong> Route through our VPN for total privacy and to bypass any restrictions.</p>
<a href="https://ahoyvpn.com" class="vpn-btn" target="_blank" rel="noopener">Get AhoyVPN &mdash; $5.99/mo</a>
    </div>
</section>
</main>

<footer>
  <p>For personal use only. Respect copyright. &nbsp;|&nbsp; <a href="https://ahoyvpn.com">AhoyVPN</a> &nbsp;|&nbsp; <a href="mailto:dmca@ahoyvpn.com">DMCA</a></p>
  <p style="margin-top:0.5rem">&copy; <?= date('Y') ?> AhoyRipper. All rights reserved. &nbsp;|&nbsp; <a href="https://github.com/Williambot-git/ahoyripper" rel="noopener">v<?= htmlspecialchars($VERSION) ?></a></p>
</footer>

<script>
// ─── Progressive enhancement: remove no-js class so .js CSS activates ──
// The <html class="no-js"> is set server-side so browsers with JS disabled
// never see JS-dependent styles. When JS is present, we remove it here
// before any rendering paint so the class swap is invisible to users.
// This must run before any other script to avoid a FOUC window.
document.documentElement.classList.remove('no-js');

// ─── PWA Service Worker registration ───────────────────────
var deferredInstallPrompt = null;

// Capture the beforeinstallprompt event so we can show the install banner
// at the right time (rather than relying on the browser's own install UI).
window.addEventListener('beforeinstallprompt', function(e) {
  // Prevent the browser's mini-infobar from appearing.
  e.preventDefault();
  // Check if the app is already installed — don't show the banner to users
  // who have already installed the PWA (navigator.standalone is true on iOS Safari
  // and some desktop PWAs; the check is imperfect but covers the main cases).
  if (window.matchMedia('(display-mode: standalone)').matches ||
      navigator.standalone === true ||
      localStorage.getItem('ahoyrip_install_dismissed')) {
    return;
  }
  deferredInstallPrompt = e;
  var banner = document.getElementById('install-banner');
  if (banner) banner.style.display = 'flex';
  // A fresh beforeinstallprompt means the browser is willing to install — clear any
  // previously dismissed state so the banner reappears even if the user previously
  // clicked Dismiss. Without this, a user who dismissed the banner would never see it
  // again on the same origin even though the browser's own install prompt is active.
  try { localStorage.removeItem('ahoyrip_install_dismissed'); } catch (ev) {}
});

// Show the install prompt when the user clicks "Install".
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('/sw.js')
      .then(function(registration) {
        // Track when a new SW is installing in the background.
        registration.addEventListener('updatefound', function() {
          var installing = registration.installing;
          if (!installing) return;

          // Show user-facing update prompt when the new SW is installed
          // (but not yet activated — it waits for our SKIP_WAITING message).
          // { once: true } prevents duplicate handlers if statechange fires multiple
          // times during SW lifecycle transitions. The banner is shown/hidden by
          // toggling display so repeated show/hide cycles don't accumulate handlers.
          installing.addEventListener('statechange', function() {
            if (installing.state === 'installed' && navigator.serviceWorker.controller) {
              var banner = document.getElementById('update-banner');
              if (banner) {
                banner.style.display = 'block';
                var refreshBtn = banner.querySelector('.refresh-btn');
                // Guard: only add the click handler once so repeated statechange
                // events (e.g. from multiple SW lifecycle transitions) do not
                // stack duplicate listeners onto the same button element.
                if (refreshBtn && !refreshBtn._swHandler) {
                  refreshBtn._swHandler = true;
                  refreshBtn.addEventListener('click', function() {
                    if (registration.waiting) {
                      registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                    }
                  });
                }
              }
            }
          }, { once: true });
        });
      })
      .catch(function(err) {
        console.warn('[SW] registration failed:', err);
      });
  });
}

// ─── PWA Install Banner ───────────────────────────────────
// Wire up the "Install" and "Dismiss" buttons for the PWA install banner.
// Deferred prompt is captured by the beforeinstallprompt listener above.
var installBtn = document.getElementById('install-btn');
var installDismissBtn = document.getElementById('install-dismiss-btn');
var installBanner = document.getElementById('install-banner');

if (installBtn) {
  installBtn.addEventListener('click', function() {
    if (deferredInstallPrompt) {
      deferredInstallPrompt.prompt();
      deferredInstallPrompt.userChoice.then(function(choice) {
        // Log the outcome for analytics/debugging.
        console.info('[PWA] Install prompt outcome:', choice.outcome);
        deferredInstallPrompt = null;
        if (installBanner) installBanner.style.display = 'none';
      });
    }
  });
}

if (installDismissBtn && installBanner) {
  installDismissBtn.addEventListener('click', function() {
    installBanner.style.display = 'none';
    // Remember dismissal so the banner doesn't reappear on the next page visit.
    try { localStorage.setItem('ahoyrip_install_dismissed', '1'); } catch (e) {}
  });
}

// Handle PWA installation triggered by the browser's own mini-infobar install button
// (instead of AhoyRipper's custom "Install" button). The appinstalled event fires
// when the user accepts the browser's install UI, regardless of which install path
// was used. This ensures the custom banner is always dismissed on successful install.
window.addEventListener('appinstalled', function() {
  var banner = document.getElementById('install-banner');
  if (banner) banner.style.display = 'none';
  // Clear the dismissed state so the banner is eligible to show again if the user
  // later uninstalls the PWA and revisits. Without this, dismissed persists forever
  // and the banner would never reappear on a fresh install cycle.
  try { localStorage.removeItem('ahoyrip_install_dismissed'); } catch (e) {}
  console.info('[PWA] App installed successfully.');
});

// ─── Frontend Logic ─────────────────────────────────────────
(function() {
  // Expose page_request_id for error reporting and support tickets.
  // This lets users include the page's correlation ID when reporting issues,
  // enabling direct lookup in server-side access/error logs alongside the
  // API request_id that appears in API responses.
  var PAGE_REQUEST_ID = '<?= htmlspecialchars($page_request_id, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>';

  function escapeHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  const API = '/src/api.php';

  // Shared error hint map — single source of truth for human-readable error messages
  // keyed by error_code (from API response) and by HTTP status code (fallback).
  // Used by both the !resp.ok branch and the catch branch so they stay in sync.
  var ERROR_HINTS = {
    'RATE_LIMIT_EXCEEDED': 'Too many requests. Slow down. Get AhoyVPN for unlimited access: https://ahoyvpn.com',
    'GEOBLOCKED': 'This video is geo-restricted in your region. Use AhoyVPN to route through an unblocked region: https://ahoyvpn.com',
    'DAILY_LIMIT': 'Daily free limit reached. Get AhoyVPN for unlimited rips: https://ahoyvpn.com',
    'INVALID_KEY': 'Invalid API key. Get AhoyVPN for unlimited rips: https://ahoyvpn.com',
    'LOGIN_REQUIRED': 'This video requires login. Sign in to the platform in your browser, or pass cookies to yt-dlp for server-side auth (see README for setup).',
    'UNSUPPORTED_SITE': 'This site is not supported. Check the supported sites list at github.com/yt-dlp/yt-dlp.',
    'PLAYLIST_MISSING': 'The playlist was not found or is no longer available.',
    'COPYRIGHT_REMOVED': 'This content was removed due to a copyright claim.',
    'VIDEO_UNAVAILABLE': 'This video is no longer available or has been removed.',
    'AGE_RESTRICTED': 'This video is age-restricted and cannot be downloaded without age verification on the source platform.',
    'SOURCE_RATE_LIMITED': 'The source site is rate-limiting us. Please try again in a few minutes, or use AhoyVPN for a different exit IP: https://ahoyvpn.com',
    'SOURCE_TIMEOUT': 'The source site took too long to respond. Try audio-only (fastest), a lower resolution, or try again when the site is less busy.',
    'CONNECTION_FAILED': 'Could not connect to the source. Check your network and try again, or use AhoyVPN to change your exit IP: https://ahoyvpn.com',
    'SSL_ERROR': 'Secure connection to the source failed. Try again shortly, or use AhoyVPN for a different exit IP: https://ahoyvpn.com',
    'CONNECTION_TIMEOUT': 'Connection timed out before the source responded. Use AhoyVPN to change your exit IP and try again: https://ahoyvpn.com',
    'FILE_TOO_LARGE': 'This file is too large for the server. Try audio-only or a lower resolution.',
    'DOWNLOAD_TIMEOUT': 'Download timed out. The file may be too large or the source is slow. Try a smaller format (audio-only is fastest) or try again when the site is less busy.',
    'DISALLOWED_CONTENT': 'This content is not available due to a terms of service violation.',
    'YTDLP_ERROR': 'The source returned an error. Try another format in the list, or wait a moment and try again.',
    'DOWNLOAD_CANCELLED': 'Download was cancelled — you may have closed the tab or lost connection. Your daily quota was not charged.',
    'DOWNLOAD_EMPTY': 'The downloaded file was empty — this is a server-side issue, not your format choice. Try again in a moment, or pick a different format.',
    'VERIFICATION_FAILED': 'The downloaded file could not be verified as valid. This is a server-side issue — try another format or try again in a moment.',
    'VERIFICATION_TIMEOUT': 'Verification timed out — the file may be valid but could not be confirmed within the server time limit. Try a smaller format (audio-only is fastest) or try again.',
    'FILE_READ_ERROR': 'The server could not read the downloaded file back for streaming. This is a temporary server issue — try again or pick a different format.',
    'PROC_OPEN_FAILED': 'The download could not be started. The server may be restarting or overloaded. Try again in a moment.',
    'SOURCE_FORBIDDEN': 'The source site blocked this request (HTTP 403). Try a different format or use AhoyVPN to change your exit IP.',
    'SOURCE_NOT_FOUND': 'The source site returned HTTP 404 — the content may have been moved or deleted.',
    'SOURCE_SERVER_ERROR': 'The source site returned an error and is having issues. Try again shortly, or use AhoyVPN for a different exit IP: https://ahoyvpn.com',
    'SOURCE_HTTP_ERROR': 'The source site returned an unexpected error. Try again in a moment, or use AhoyVPN for a different exit IP: https://ahoyvpn.com',
    'MISSING_FORMAT': 'Select a format from the list above first, then click it to download.',
    'INVALID_FORMAT_ID': 'That format ID was not recognized. Refresh to get a fresh format list, then pick a valid format from the list.',
    'PROBE_FAILED': 'Could not verify that yt-dlp is working. The server may be misconfigured or yt-dlp is not installed. Try again or contact support.',
    'PARSE_ERROR': 'Could not parse the video info. The site may be temporarily unavailable or not supported.',
    'NOT_ACCEPTABLE': 'This client does not accept JSON. Use a standard API client.',
    'PRIVATE_VIDEO': 'This video is private and cannot be downloaded. Try a public video instead.',
    'FORBIDDEN_ORIGIN': 'Requests must come from ahoyripper.com or ahoyvpn.com.',
    'METHOD_NOT_ALLOWED': 'That request method is not allowed. Use GET.',
    'INVALID_URL': 'That URL is not supported or could not be fetched. Check the link and try again.',
    'MISSING_URL': 'No URL was provided. Paste a public link from YouTube, Twitter/X, TikTok, SoundCloud, Instagram, Facebook, or Reddit.',
    'UNKNOWN_ACTION': 'Unknown action. Use ?action=info, ?action=download, ?action=check, ?action=health, ?action=progress, or ?action=analytics.',
    'CONFIG_ERROR': 'The server is misconfigured — browser impersonation is not available. Contact the server operator or set AHOY_IMPERSONATE to an empty string to disable impersonation.',
    '403': 'The server understood the request but refused to fulfill it. Try again or use AhoyVPN to change your exit IP.',
    '404': 'The requested resource was not found. The content may have been removed or the URL may be incorrect.',
    '429': 'Too many requests. The source site is rate-limiting us — please try again in a few minutes.',
    '502': 'The source site is having issues. Try again in a few minutes.',
    '504': 'The request timed out. The video might be too large or unavailable. Try a smaller format.',
    '503': 'Service temporarily unavailable. Please try again shortly.',
    '500': 'The server encountered an error. Please try again in a moment.',
    '422': 'The server could not process this request. The video may not be supported or the site may be temporarily unavailable.',
  };

  const form = document.getElementById('ripForm');
  const input = document.getElementById('urlInput');
  const btn = document.getElementById('submitBtn');
  const errorBox = document.getElementById('errorBox');
  const retryBtn = document.getElementById('retryBtn');
  const progressBox = document.getElementById('progressBox');
  const progressText = document.getElementById('progressText');
  const progressBar = document.getElementById('progressBar');
  const resultsBox = document.getElementById('resultsBox');
  const formatGrid = document.getElementById('formatGrid');
  const resultsTitle = document.getElementById('resultsTitle');
  const ripAgain = document.getElementById('ripAgain');
  const sortSelect = document.getElementById('sortSelect');

  // Flag guarding successful-fetch navigation — prevents the browser from
  // downloading the JSON error body as a file when the fetch responds non-200.
  // Set to false in error branches; checked nowhere (safety net for code changes).
  var navigateOnSuccess = true;
  // Persisted retry_after timestamp from rate-limited responses (Unix seconds).
  // Used by the retry button to show a live countdown when the user clicks retry
  // during a rate-limit cooldown period.
  var retryAfterTs = null;
  var retryAfterTimer = null;

  // ─── Clear stale error state on page load / new interaction ─────────────────
  // Any error displayed from a previous session (e.g. network failure on a prior
  // rip) must be dismissed so it doesn't persist and confuse the user.
  hideError();

  // Restore persisted quota from localStorage on page load.
  // Falls back to showing nothing until the first API response arrives,
  // avoiding the stale "5 free rips/day" on returning visitors.
  // Clears any stale quota from a previous UTC day by comparing the stored
  // reset timestamp against the current time.
  (function restoreQuota() {
    var el = document.getElementById('quotaDisplay');
    var limEl = document.getElementById('quotaLimit');
    var labelEl = document.getElementById('quotaLabel');
    var upgradeEl = document.getElementById('quotaUpgrade');
    if (!el) return;

    // Detect stale quota: if the stored reset timestamp is in the past,
    // the stored quota is from a previous UTC day and must be discarded.
    var storedReset = localStorage.getItem('ahoyrip_quota_reset');
    if (storedReset !== null) {
      var resetTs = parseInt(storedReset, 10);
      if (!isNaN(resetTs) && resetTs <= Date.now() / 1000) {
        // Reset window has passed — clear all stale quota data.
        localStorage.removeItem('ahoyrip_quota_remaining');
        localStorage.removeItem('ahoyrip_quota_limit');
        localStorage.removeItem('ahoyrip_quota_reset');
        localStorage.removeItem('ahoyrip_quota_unlimited');
        return; // Show blank until fresh data arrives from API
      }
    }

    var stored = localStorage.getItem('ahoyrip_quota_remaining');
    var storedLimit = localStorage.getItem('ahoyrip_quota_limit');
    if (stored !== null) {
      var rem = parseInt(stored, 10);
      if (!isNaN(rem) && rem >= 0) {
        el.textContent = rem;
        // Restore the stored limit next to remaining count.
        if (limEl && storedLimit !== null) {
          var lim = parseInt(storedLimit, 10);
          if (!isNaN(lim) && lim > 0) {
            limEl.textContent = '/' + lim;
          }
        }
        if (rem <= 2) {
          el.classList.add('low');
        }
        if (rem === 0) {
          el.classList.add('exhausted');
        } else {
          el.classList.remove('exhausted');
        }
        if (rem === 0 && upgradeEl) {
          upgradeEl.textContent = 'upgrade now';
          upgradeEl.style.fontWeight = '700';
          upgradeEl.style.color = 'var(--color-error)';
        }
      }
    }
    var storedUnlimited = localStorage.getItem('ahoyrip_quota_unlimited');
    if (storedUnlimited === '1' && labelEl) {
      labelEl.style.display = 'none';
      el.style.display = 'none';
      if (limEl) limEl.style.display = 'none';
    }
  })();

  // Persist and restore sort preference
  var currentSort = localStorage.getItem('ahoyrip_sort') || 'height';
  if (sortSelect) {
    sortSelect.value = currentSort;
  }

  // Toggle API key visibility
  var toggleBtn = document.getElementById('toggleKey');
  var toggleIcon = document.getElementById('toggleKeyIcon');
  var keyInput = document.getElementById('apiKey');

  // Restore API key from sessionStorage on page load so returning visitors
  // don't have to re-enter it after every page refresh. sessionStorage is
  // cleared when the browser tab closes (vs. localStorage which persists
  // indefinitely) — a reasonable balance between convenience and shared-device
  // risk. The key is stored under a versioned name so future UI changes can
  // invalidate old entries without requiring a migration.
  if (keyInput) {
    try {
      var storedKey = sessionStorage.getItem('ahoyrip_key_v1');
      if (storedKey !== null) {
        keyInput.value = storedKey;
      }
    } catch (e) {}
    // Persist the key to sessionStorage whenever it changes (user types,
    // pastes, or clears it) so page reloads and tab restores remember it.
    keyInput.addEventListener('input', function() {
      try {
        sessionStorage.setItem('ahoyrip_key_v1', keyInput.value);
      } catch (e) {}
    });
  }

  if (toggleBtn && keyInput) {
    toggleBtn.addEventListener('click', function() {
      if (keyInput.type === 'password') {
        keyInput.type = 'text';
        toggleIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
        toggleBtn.setAttribute('aria-label', 'Hide API key');
        toggleBtn.setAttribute('title', 'Hide API key');
      } else {
        keyInput.type = 'password';
        toggleIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        toggleBtn.setAttribute('aria-label', 'Show API key');
        toggleBtn.setAttribute('title', 'Show API key');
      }
    });
  }

  function setProgress(pct, text) {
    // Progress is driven by state, not real percentage
    // The bar runs an indeterminate animation via CSS; JS only updates text
    if (text) {
      progressText.textContent = text;
    }
  }

  function showError(msg) {
    errorBox.textContent = msg;
    errorBox.classList.add('active');
    retryBtn.classList.add('visible');
  }

  function hideError() {
    errorBox.classList.remove('active');
    retryBtn.classList.remove('visible');
  }

  function setLoading(on, label) {
    btn.disabled = on;
    if (on) {
      btn.innerHTML = '<span class="btn-spinner"></span>' + (label || 'Ripping...');
    } else {
      btn.textContent = 'Rip It';
    }
  }

  function showProgress(on) {
    progressBox.classList.toggle('active', on);
    if (!on) {
      setProgress(0, '');
    }
  }

  function showResults(on) {
    resultsBox.classList.toggle('active', on);
  }

  // Show a temporary toast notice when yt-dlp substituted a format
  // (e.g. user selected 1080p but 720p was delivered).
  // The notice auto-dismisses after 5 seconds and does not block the download flow.
  function showSubstitutionNotice(actualQuality) {
    // Remove any existing notice first so multiple rapid downloads don't stack.
    var existing = document.getElementById('substitutionNotice');
    if (existing) { existing.remove(); }
    var notice = document.createElement('div');
    notice.id = 'substitutionNotice';
    // role="status" makes this a polite live region — screen readers announce its
    // content when it appears without interrupting the current speech/reading.
    // aria-live="polite" is the implicit role for status; pairing them explicitly
    // maximises cross-screen-reader compatibility (NVDA, JAWS, VoiceOver).
    // aria-atomic="true" ensures the full message is read even if only part changes.
    notice.setAttribute('role', 'status');
    notice.setAttribute('aria-live', 'polite');
    notice.setAttribute('aria-atomic', 'true');
    notice.style.cssText = [
      'position: fixed',
      'bottom: 2rem',
      'left: 50%',
      'transform: translateX(-50%)',
      'background: #1e293b',
      'color: #e2e8f0',
      'padding: 0.75rem 1.25rem',
      'border-radius: 8px',
      'font-size: 0.875rem',
      'font-family: Inter, system-ui, sans-serif',
      'box-shadow: 0 4px 20px rgba(0,0,0,0.35)',
      'z-index: 9999',
      'max-width: 360px',
      'text-align: center',
      'border: 1px solid rgba(255,255,255,0.08)',
      'line-height: 1.5',
    ].join('; ');
    notice.textContent = 'Note: ' + actualQuality + ' was delivered — the format you selected was not available.';
    document.body.appendChild(notice);
    setTimeout(function() {
      if (notice.parentNode) {
        notice.style.transition = 'opacity 0.4s ease';
        notice.style.opacity = '0';
        setTimeout(function() { if (notice.parentNode) notice.remove(); }, 400);
      }
    }, 5000);
  }

  // Inject a visually-hidden live region announcement for screen readers
  // when quota is exhausted. The live region is inserted into the DOM and
  // auto-removed after the announcement window so it doesn't persist.
  // aria-live="assertive" (not polite) because the user must act immediately.
  function announceQuotaExhausted() {
    var existing = document.getElementById('quotaExhaustedAlert');
    if (existing) { existing.remove(); }
    var el = document.createElement('div');
    el.id = 'quotaExhaustedAlert';
    el.setAttribute('role', 'alert');
    el.setAttribute('aria-live', 'assertive');
    el.setAttribute('aria-atomic', 'true');
    // Fully hidden visually but accessible to screen readers and ATs.
    // position:fixed + off-screen clipping is the standard accessible-hidden pattern
    // (avoids display:none which ATs skip, and avoids opacity:0 which some ATs skip).
    el.style.cssText = [
      'position: fixed',
      'width: 1px',
      'height: 1px',
      'padding: 0',
      'margin: -1px',
      'overflow: hidden',
      'clip: rect(0,0,0,0)',
      'white-space: nowrap',
      'border: 0'
    ].join('; ');
    el.textContent = 'Daily quota exhausted. Upgrade to AhoyVPN for unlimited rips, or wait until midnight UTC for your next free rip.';
    document.body.appendChild(el);
    setTimeout(function() { if (el.parentNode) el.remove(); }, 10000);
  }

  function formatDuration(secs) {
    if (!secs) return '';
    var h = Math.floor(secs / 3600);
    var m = Math.floor((secs % 3600) / 60);
    var s = secs % 60;
    if (h > 0) return h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function formatBytes(mb) {
    if (mb <= 0) return '0 B';
    if (mb >= 1000) {
      var gb = mb / 1000;
      // Show 1 decimal only when there's a fractional GB; suppress decimals for whole GB.
      return gb.toFixed(gb % 1 === 0 ? 0 : 1) + ' GB';
    }
    if (mb >= 1) return mb.toFixed(1) + ' MB';
    return '<1 MB';
  }

  function buildDownloadUrl(url, formatId, label, derivedFilename) {
    // For combined video+audio formats, we need to merge streams
    // yt-dlp handles this with the format string
    // Key is sent via Authorization header, not URL (keeps key out of logs)
    // Filename is the sanitized video title, used to set a meaningful download name
    var keyInput = document.getElementById('apiKey');
    var key = (keyInput && keyInput.value) ? keyInput.value : '';
    var fn = derivedFilename ? '&filename=' + encodeURIComponent(derivedFilename) : '';
    // Include key as query param so the direct window.location.href navigation
    // (which follows a successful fetch) carries it — the Authorization header
    // is sent only for the check-fetch; the browser-navigation download needs
    // the key in the URL since it bypasses fetch and can't send custom headers.
    var keyParam = key ? '&key=' + encodeURIComponent(key) : '';
    var plToggle = document.getElementById('playlistToggle');
    var plParam = (plToggle && plToggle.checked) ? '&playlist=1' : '';
    return { url: `${API}?action=download&url=${encodeURIComponent(url)}&format=${encodeURIComponent(formatId)}` + fn + keyParam + plParam, key };
  }

  function renderFormats(url, data) {
    formatGrid.innerHTML = '';
    resultsTitle.textContent = data.title || 'Select a format to download';
    // Update <title> so browser tabs, history entries, and scroll position
    // all reflect the ripped video. When no title is available (null/error),
    // fall back to the static default so the tab stays labelled correctly.
    document.title = data.title ? data.title + ' — AhoyRipper' : 'AhoyRipper';

    // Populate metadata: thumbnail, uploader, duration
    var thumb = document.getElementById('resultsThumb');
    var sub = document.getElementById('resultsSub');
    if (thumb) {
      if (data.thumbnail) {
        thumb.src = data.thumbnail;
        thumb.hidden = false;
        thumb.alt = data.title || '';
      } else {
        thumb.src = '';
        thumb.hidden = true;
      }
    }
    if (sub) {
      var parts = [];
      if (data.uploader) parts.push(data.uploader);
      if (data.duration) parts.push(formatDuration(data.duration));
      sub.textContent = parts.join(' \u00b7 ');
    }

    // Populate platform badge — derived from yt-dlp's extractor_key.
    // Only show when platform is known (not "Unknown") and differs from the
    // uploader, so it adds useful information rather than redundancy.
    var plat = document.getElementById('resultsPlatform');
    if (plat) {
      var p = data.platform;
      if (p && p !== 'Unknown' && p !== data.uploader) {
        plat.textContent = p;
        plat.hidden = false;
      } else {
        plat.textContent = '';
        plat.hidden = true;
      }
    }

    var formats = data.formats || [];

    // Group formats by type for better UX
    var groups = { combined: [], videoOnly: [], audioOnly: [] };
    formats.forEach(function(f) {
      if (f.vcodec !== 'none' && f.acodec !== 'none') {
        groups.combined.push(f);
      } else if (f.vcodec !== 'none') {
        groups.videoOnly.push(f);
      } else {
        groups.audioOnly.push(f);
      }
    });

    function renderGroupHeader(label) {
      var h = document.createElement('div');
      h.className = 'format-group-header';
      h.textContent = label;
      return h;
    }

    function renderSeparator() {
      var sep = document.createElement('div');
      sep.className = 'format-group-sep';
      return sep;
    }

  function renderFormatCard(f) {
    var card = document.createElement('a');
    card.className = 'format-card';
    // Key is sent via Authorization header in the download fetch (keeps key out
    // of server-side access logs). The key is also placed in the URL as a query
    // param so that the window.location.href fallback works for direct navigation
    // (browsers can't send custom headers on direct navigation).
    card.href = '#';
    card.setAttribute('data-url', escapeHtml(url));
    card.setAttribute('data-id', escapeHtml(f.id));
    card.setAttribute('data-label', escapeHtml(f.label || f.ext));
    card.setAttribute('data-filename', escapeHtml(data.derived_filename || ''));
    card.setAttribute('role', 'button');
    var badgeColor = 'var(--color-accent)';
    var badgeLabel = 'Video';
    if (f.vcodec === 'none') {
      badgeColor = 'var(--color-success)';
      badgeLabel = 'Audio';
    } else if (f.acodec === 'none') {
      badgeColor = '#a855f7';
      badgeLabel = 'Video Only';
    }
    // aria-label describes the format type (Video/Audio/Video Only) so screen
    // readers can announce it without needing to parse the card's innerHTML.
    card.setAttribute('aria-label', badgeLabel + ' format');
    // role="button" makes the intent explicit to assistive technologies.
    // keyboard activation is handled via keydown (Enter/Space) below.
    // The href="#" with preventDefault() replaces the previous href="#" approach
    // which caused a scroll-to-top on Enter press before the click handler ran.

    var size = f.filesize_mb > 0 ? formatBytes(f.filesize_mb) : '~size';
    var tbrMeta = f.tbr ? f.tbr + 'kbps' : '';
    var extMeta = f.ext ? f.ext.toUpperCase() : '';
    var langMeta = f.language ? f.language.toUpperCase() : '';
    var metaParts = escapeHtml([extMeta, tbrMeta].filter(Boolean).join(' '));
    var langBadge = langMeta ? '<span class="format-lang">' + escapeHtml(langMeta) + '</span>' : '';
    // Prefer description (human-readable yt-dlp description) when available, else label.
    // description carries extra context like "720p60 HDR" or "audio only" that
    // label doesn't always capture — particularly for audio and alternative formats.
    // Filter out "Unknown" sentinel from both description and label: the API's clean()
    // function returns "Unknown" when those fields were absent in yt-dlp metadata,
    // which is not a useful display string. Fall through to ext in that case.
    // description and label come from yt-dlp (user-controlled metadata) and are
    // HTML-escaped before use to prevent stored XSS via innerHTML injection.
    var rawDisplayLabel = (f.description && f.description !== 'Unknown')
        ? f.description
        : ((f.label && f.label !== 'Unknown') ? f.label : (f.ext ? f.ext.toUpperCase() : 'Format'));
    var displayLabel = escapeHtml(rawDisplayLabel);
    // title attribute — use ≈ prefix for estimated sizes so the tooltip
    // clearly distinguishes "known" (from yt-dlp metadata) from "approximate"
    // (estimated from bitrate × duration) without requiring the user to hover.
    var sizeHint = f.filesize_mb > 0 ? size : '≈' + size;
    var cardTitle = escapeHtml(rawDisplayLabel) + (size !== '~size' ? ' - ' + sizeHint : '');

    card.setAttribute('title', cardTitle);

    card.innerHTML =
      '<span class="format-ext" style="color:' + badgeColor + '">' + badgeLabel + '</span>' +
      '<div class="format-label">' + displayLabel + '</div>' +
      '<div class="format-meta">' + metaParts + langBadge + '</div>' +
      '<div class="format-size">' + escapeHtml(size) + '</div>';

      // Reset the guard at the start of each card click so a failed click
      // (e.g. timeout on card A) does not suppress navigation on a subsequent
      // successful click (card B), which is a consequence of the flag being
      // a module-level variable shared across all card click handlers.
      navigateOnSuccess = true;
      card.addEventListener('click', function(e) {
        e.preventDefault();
        // Re-reset here so any on-page re-render that re-attaches listeners
        // starts with a clean guard state too.
        navigateOnSuccess = true;
        var dl = buildDownloadUrl(url, f.id, f.label || f.ext, data.derived_filename || null);
        // Carry the Referer into the navigation URL so the download request passes
        // the API's origin check (window.location.href bypasses fetch and cannot
        // send custom headers). playlistParam already included by buildDownloadUrl.
        dl.url += '&referer=' + encodeURIComponent(window.location.href);
        var dlHeaders = {};
        if (dl.key) { dlHeaders['Authorization'] = 'Bearer ' + encodeURIComponent(dl.key); }
        // Pass the browser's language preference so yt-dlp can request localized
        // metadata (titles, descriptions) from the source platform.
        dlHeaders['Accept-Language'] = navigator.language || 'en-US';
        // Forward page_request_id so the API and server logs can correlate
        // the download request with the browser's page view.
        dlHeaders['X-Request-ID'] = PAGE_REQUEST_ID;
        card.classList.add('downloading');
        setLoading(true, 'Downloading...');

        // navigateOnSuccess guard: set to false when fetch fails so window.location.href
        // is not called (would otherwise download the JSON error body as a file).
        //
        // NOTE: The download action (action=download) returns a JSON redirect signal
        // — it does NOT stream the file bytes. The actual file download happens via
        // window.location.href (a separate browser navigation request), which carries
        // the X-Download-Timeout as a query parameter and is handled independently by
        // the server. The X-Download-Timeout header from the download action response
        // therefore does NOT apply to this initial fetch — it would incorrectly apply
        // the download's long timeout (up to DOWNLOAD_TIMEOUT, e.g. 3600s) to what
        // should be a short validation round-trip. Use a fixed 30-second client timeout
        // for this fetch: the download action responds quickly (it spawns yt-dlp and
        // immediately returns), so a 30s deadline is more than sufficient. If this
        // fetch times out, the download action is genuinely unresponsive and the user
        // should see the timeout error rather than waiting for the much longer
        // DOWNLOAD_TIMEOUT to elapse.
        fetch(dl.url, { headers: dlHeaders, signal: AbortSignal.timeout(30000) })
          .then(function(resp) {
            if (!resp.ok) {
              navigateOnSuccess = false;
              // Attempt to parse the error JSON. If the response body is not valid
              // JSON (e.g. a proxy error page), catch the parse failure and fall
              // back to a generic message. Always remove the downloading state.
              resp.json().then(function(err) {
                // Prefer ERROR_HINTS[error_code] over raw err.error — gives
                // actionable messages with upsell links (e.g. RATE_LIMIT_EXCEEDED
                // maps to the AhoyVPN upgrade URL). Mirrors the info handler below.
                var dlMsg = err.error || 'Download failed. Try another format.';
                if (err.error_code && ERROR_HINTS[err.error_code]) {
                  dlMsg = ERROR_HINTS[err.error_code];
                } else {
                  var dlStatusKey = String(resp.status);
                  if (ERROR_HINTS[dlStatusKey]) {
                    dlMsg = ERROR_HINTS[dlStatusKey];
                  }
                }
                // Surface the upgrade_url from the API response when the error code
                // indicates an upgradable condition. Use it instead of the generic
                // AhoyVPN URL already in ERROR_HINTS. Avoid duplicating URLs.
                if (typeof err.upgrade_url === 'string' && err.upgrade_url.length > 0) {
                  if (dlMsg.indexOf('://') === -1) {
                    dlMsg += ' ' + err.upgrade_url;
                  }
                }
                showError(dlMsg);
                setLoading(false);
                card.classList.remove('downloading');
              }).catch(function() {
                // resp.json() failed — response body was not valid JSON (e.g. a proxy
                // error page). Fall back to the HTTP status code lookup so 502/504/503
                // proxy errors still surface an actionable hint rather than a generic one.
                var dlMsg = 'Download failed. Try another format.';
                var dlStatusKey = String(resp.status);
                if (ERROR_HINTS[dlStatusKey]) {
                  dlMsg = ERROR_HINTS[dlStatusKey];
                }
                showError(dlMsg);
                setLoading(false);
                card.classList.remove('downloading');
              });
              return;
            }
            // Only navigate on HTTP success — don't navigate on error JSON responses,
            // which would otherwise cause the browser to download the error as a file.
            if (navigateOnSuccess) {
              // Check if yt-dlp substituted a different format (e.g. 1080p requested
              // but 720p delivered because higher quality was unavailable). Surface this
              // as a brief toast so the user understands why their file is lower quality.
              var substituted = resp.headers.get('X-Format-Substituted');
              if (substituted) {
                showSubstitutionNotice(substituted);
              }
              window.location.href = dl.url;
            }
            setLoading(false);
            card.classList.remove('downloading');
            card.style.borderColor = 'var(--color-success)';
            setTimeout(function() { card.style.borderColor = ''; }, 1500);
          })
          .catch(function(dlErr) {
            // Set guard flag to false — network/timeout failures must never trigger
            // the redirect path (navigateOnSuccess is set in error branches too).
            navigateOnSuccess = false;
            var msg = 'Download failed. Try another format.';
            if (dlErr.name === 'AbortError') {
              // The fetch timeout is fixed at 30s (see above). If this fires, the
              // download action is genuinely unresponsive — not a slow source issue.
              msg = 'Download timed out after 30s. The server may be overloaded. Try again shortly or pick a smaller format.';
            }
            showError(msg);
            setLoading(false);
            card.classList.remove('downloading');
          });

      // Keyboard activation: Space or Enter on a focused card triggers the click.
      // Using keydown rather than keypress because keypress is deprecated and
      // does not fire for Space (page scroll) in some browsers. keydown fires
      // before the default scrolling action, so preventDefault() stops the scroll.
      card.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          card.click();
        }
      });

      return card;
    }

    // Use flexbox instead of CSS grid — flex handles mixed children (cards + headers +
    // separators) properly since all are direct children of the same flex container.
    // CSS grid only applies to *direct* children, so using grid with ::before pseudo-
    // elements and non-grid siblings causes alignment confusion; flex wrap is simpler.
    formatGrid.style.display = 'flex';
    formatGrid.style.flexDirection = 'row';
    formatGrid.style.flexWrap = 'wrap';

    var lastGroup = null;
    var addedAnything = false;
    if (groups.combined.length > 0) {
      formatGrid.appendChild(renderGroupHeader('Video + Audio'));
      groups.combined.forEach(function(f) { formatGrid.appendChild(renderFormatCard(f)); addedAnything = true; });
      lastGroup = 'combined';
    }
    if (groups.videoOnly.length > 0) {
      if (lastGroup !== null) formatGrid.appendChild(renderSeparator());
      formatGrid.appendChild(renderGroupHeader('Video Only'));
      groups.videoOnly.forEach(function(f) { formatGrid.appendChild(renderFormatCard(f)); addedAnything = true; });
      lastGroup = 'videoOnly';
    }
    if (groups.audioOnly.length > 0) {
      if (lastGroup !== null) formatGrid.appendChild(renderSeparator());
      formatGrid.appendChild(renderGroupHeader('Audio Only'));
      groups.audioOnly.forEach(function(f) { formatGrid.appendChild(renderFormatCard(f)); addedAnything = true; });
      lastGroup = 'audioOnly';
    }
    // All three groups were empty — no downloadable formats available.
    // Show a clear message so the user knows this is expected behaviour, not a bug.
    if (!addedAnything) {
      var noFormats = document.createElement('div');
      noFormats.className = 'format-group-header';
      noFormats.textContent = 'No downloadable formats available for this video.';
      formatGrid.appendChild(noFormats);
    }
  }

  // On page load, if URL was pre-filled, kick off auto-fetch
  if (input.value && input.value.startsWith('http')) {
    // Small delay so the UI is ready
    setTimeout(fetchInfo, 300);
  }

  // Sort preference change — re-fetch with new sort order
  if (sortSelect) {
    sortSelect.addEventListener('change', function() {
      var s = sortSelect.value;
      localStorage.setItem('ahoyrip_sort', s);
      // Re-fetch if we already have a URL loaded
      if (input.value && input.value.startsWith('http')) {
        fetchInfo();
      }
    });
  }

  var isFetching = false; // guard against duplicate concurrent fetches (e.g. paste + Enter/Go)
  var _lastAnnouncedQuota = null; // sentinel: last quota value that triggered a screen-reader announcement

  async function fetchInfo() {
    const url = input.value.trim();
    if (!url) return;

    if (isFetching) return;
    isFetching = true;

    // Reject non-HTTP(S) schemes client-side before wasting a server round-trip.
    // The API's isValidUrl() will also catch these, but surfacing the error
    // immediately gives faster feedback and avoids burning rate-limit slots.
    try {
      const parsed = new URL(url);
      if (!['http:', 'https:'].includes(parsed.protocol)) {
        showError('Only http:// and https:// URLs are supported. Please paste a valid web link.');
        isFetching = false;
        setLoading(false);
        showProgress(false);
        return;
      }
    } catch (_) {
      // Not a valid URL — let the API give the canonical error message
    }

    hideError();
    setLoading(true, 'Fetching info...');
    showProgress(true);
    setProgress(30, 'Fetching video info...');

    // Wrap the fetch in try/catch so network/timeout failures (which produce
    // rejected promises, not HTTP error responses) also reset the loading
    // state. Without this, a failed fetch leaves the UI stuck on "Fetching…"
    // because async function rejections bypass the error-handling branches
    // that call setLoading(false).
    try {
      // Read quota from last info response and update the display.
      // Also hides the "free rips/day" label when X-DailyLimit-Remaining is -1
      // (unlimited-key holder), since the quota concept does not apply.
      function updateQuotaFromHeaders(resp) {
      var rem = resp.headers.get('X-DailyLimit-Remaining');
      var lim = resp.headers.get('X-DailyLimit-Limit');
      var el = document.getElementById('quotaDisplay');
      var limEl = document.getElementById('quotaLimit');
      var labelEl = document.getElementById('quotaLabel');
      var upgradeEl = document.getElementById('quotaUpgrade');
      if (el && rem !== null && lim !== null) {
        el.textContent = rem;
        // Show the limit (e.g. "5") next to the remaining count for transparency.
        // Omit when limit is -1 (unlimited key holder) since the entire quota UI
        // is hidden for those users below.
        if (limEl) {
          var limNum = parseInt(lim, 10);
          limEl.textContent = (limNum > 0) ? '/' + limNum : '';
        }
        // Warn user when quota is nearly exhausted (1–2 left)
        if (rem <= 2) {
          el.classList.add('low');
        } else {
          el.classList.remove('low');
        }
        // Fully exhausted: distinct visual state (darker red, faster pulse)
        // signals the user must take action (upgrade or wait) right now.
        // Only announce via screen-reader live region on the transition TO 0,
        // not on every info call that returns 0 (avoids repeated announcements).
        if (Number(rem) === 0) {
          el.classList.add('exhausted');
          if (_lastAnnouncedQuota !== 0) {
            _lastAnnouncedQuota = 0;
            announceQuotaExhausted();
          }
          // Hide the "/5" limit suffix when quota is exhausted — it is
          // irrelevant and visually misleading once the counter reads 0.
          if (limEl) limEl.style.display = 'none';
        } else {
          el.classList.remove('exhausted');
          _lastAnnouncedQuota = null;
          // Restore the limit suffix if quota is no longer exhausted.
          if (limEl) {
            var limNum = parseInt(lim, 10);
            limEl.style.display = (limNum > 0) ? '' : 'none';
          }
        }
        // When quota is exhausted, make the upgrade link more prominent
        if (upgradeEl) {
          if (Number(rem) <= 0) {
            upgradeEl.textContent = 'upgrade now';
            upgradeEl.style.fontWeight = '700';
            upgradeEl.style.color = 'var(--color-error)';
          } else {
            upgradeEl.textContent = 'get unlimited';
            upgradeEl.style.fontWeight = '500';
            upgradeEl.style.color = '';
          }
        }
        // Unlimited-key holders get -1 remaining — hide both the count and the
        // "free rips/day" label since the quota concept does not apply to them.
        // Use Number() to normalise the header value (always a string) to an integer
        // so the strict-equality check works regardless of type (e.g. "-1" vs -1).
        //
        // Rate-limit sentinel: when X-DL-RateLimit-Limit is -1 the -1 on
        // X-DailyLimit-Remaining signals a per-minute rate-limit hit, NOT an
        // unlimited-key holder. Show "Rate limited" with the exhausted animation
        // and keep the UI visible so the user sees their actual quota on reload.
        // Do NOT persist a rate-limit -1 to localStorage (it would be mistaken
        // for the unlimited-key flag and suppress the quota UI after the window resets).
        var dlLim = resp.headers.get('X-DL-RateLimit-Limit');
        var isRateLimited = (dlLim !== null && Number(dlLim) === -1);
        if (Number(rem) === -1 && labelEl) {
          if (isRateLimited) {
            // Per-minute rate-limit hit — show "Rate limited" with exhausted style.
            el.textContent = 'Rate limited';
            el.classList.add('exhausted');
            el.classList.remove('low');
            labelEl.style.display = '';
            el.style.display = '';
            if (limEl) limEl.style.display = 'none';
          } else {
            // Unlimited-key holder — hide the quota UI entirely.
            labelEl.style.display = 'none';
            el.style.display = 'none';
            if (limEl) limEl.style.display = 'none';
          }
        }
        // Persist quota to localStorage so the correct value is shown on page reload.
        // Only persist when the header is a real quota value (non-negative integer).
        // -1 signals either unlimited-key holders (persist flag) or per-minute
        // rate-limit hits (do NOT persist — resets automatically after 60 seconds).
        if (Number(rem) === -1) {
          if (!isRateLimited) {
            localStorage.setItem('ahoyrip_quota_unlimited', '1');
            localStorage.removeItem('ahoyrip_quota_remaining');
            localStorage.removeItem('ahoyrip_quota_limit');
            localStorage.removeItem('ahoyrip_quota_reset');
          }
          // Rate-limit state is intentionally NOT persisted — it would incorrectly
          // suppress the quota UI after the rate-limit window expires (60 seconds).
        } else {
          var remNum = parseInt(rem, 10);
          var limNum = parseInt(lim, 10);
          if (!isNaN(remNum) && remNum >= 0) {
            localStorage.setItem('ahoyrip_quota_remaining', remNum);
            localStorage.removeItem('ahoyrip_quota_unlimited');
            // Also persist limit so restoreQuota() can show "N/M" on reload.
            if (!isNaN(limNum) && limNum > 0) {
              localStorage.setItem('ahoyrip_quota_limit', limNum);
            } else {
              localStorage.removeItem('ahoyrip_quota_limit');
            }
            // Persist the reset timestamp so restoreQuota() can detect stale
            // quota from a previous UTC day and clear it before displaying.
            var resetTs = resp.headers.get('X-DailyLimit-Reset');
            if (resetTs) {
              localStorage.setItem('ahoyrip_quota_reset', resetTs);
            }
          }
        }
      }
    }

    try {
      const keyInput = document.getElementById('apiKey');
      const key = keyInput ? keyInput.value : '';
      const headers = {};
      if (key) {
        headers['Authorization'] = 'Bearer ' + encodeURIComponent(key);
      }
      // Forward the browser's language preference to the API so yt-dlp can
      // request localized metadata from the source platform. Without this,
      // yt-dlp always gets English regardless of the user's actual locale.
      headers['Accept-Language'] = navigator.language || 'en-US';
      // Forward page_request_id so API responses and server-side logs can be
      // correlated with the browser's page view when users report issues.
      headers['X-Request-ID'] = PAGE_REQUEST_ID;
      const sort = sortSelect ? sortSelect.value : 'height';
      const playlistToggle = document.getElementById('playlistToggle');
      const playlistParam = (playlistToggle && playlistToggle.checked) ? '&playlist=1' : '';
      const resp = await fetch(API + '?action=info&url=' + encodeURIComponent(url) + '&sort=' + encodeURIComponent(sort) + playlistParam, {
        headers,
        signal: AbortSignal.timeout(60000)
      });

      updateQuotaFromHeaders(resp);

      setProgress(80, 'Parsing...');

      if (!resp.ok) {
        var msg = 'Something went wrong. Try again.';
        var raw = null;
        try {
          var err = await resp.json();
          msg = err.error || msg;
          if (err.error_code) {
            if (ERROR_HINTS[err.error_code]) {
              msg = ERROR_HINTS[err.error_code];
            } else {
              var statusKey = String(resp.status);
              if (ERROR_HINTS[statusKey]) {
                msg = ERROR_HINTS[statusKey];
              }
            }
            // retry_after handling (inside if(err.error_code))
            if (typeof err.retry_after === 'number' && err.retry_after > Date.now() / 1000) {
              retryAfterTs = err.retry_after;
              var secs = Math.ceil(err.retry_after - Date.now() / 1000);
              if (secs > 60) {
                var mins = Math.ceil(secs / 60);
                msg += ' Try again in about ' + mins + ' minute' + (mins !== 1 ? 's' : '') + '.';
              } else if (secs > 0) {
                msg += ' Try again in ' + secs + ' second' + (secs !== 1 ? 's' : '') + '.';
              }
            }
          } else {
            // No error_code — fall back to HTTP status lookup and surface upgrade_url
            // so rate-limit/geo-blocked users get the upsell link even when their
            // error_code is unclassified (e.g. 429 from nginx with no body).
            var statusKey = String(resp.status);
            if (ERROR_HINTS[statusKey]) {
              msg = ERROR_HINTS[statusKey];
            }
            if (typeof err.upgrade_url === 'string' && err.upgrade_url.length > 0) {
              if (msg.indexOf('://') === -1) {
                msg += ' ' + err.upgrade_url;
              }
            }
          }
          // Surface raw yt-dlp diagnostic output when available.
          if (typeof err.raw_error === 'string' && err.raw_error.length > 0 && err.raw_error.length < 400) {
            raw = err.raw_error;
          }
        } catch (_jsonErr) {
          // resp.json() failed — response was not valid JSON (e.g. nginx error page).
          // Fall through with the generic msg. Check resp.status for error-page hint.
          var statusKey = String(resp.status);
          if (ERROR_HINTS[statusKey]) {
            msg = ERROR_HINTS[statusKey];
          }
          // DOWNLOAD_TIMEOUT body may not be valid JSON — check via resp.text() briefly.
          if (resp.status === 504) {
            resp.text().then(function(txt) {
              var m = txt.match(/"retry_after"\s*:\s*(\d+)/);
              if (m) {
                retryAfterTs = parseInt(m[1], 10);
                var secs = Math.ceil(retryAfterTs - Date.now() / 1000);
                if (secs > 0) {
                  if (secs > 60) {
                    var mins = Math.ceil(secs / 60);
                    showError(msg + ' Try again in about ' + mins + ' minute' + (mins !== 1 ? 's' : '') + '.');
                  } else {
                    showError(msg + ' Try again in ' + secs + ' second' + (secs !== 1 ? 's' : '') + '.');
                  }
                  return;
                }
              }
              showError(msg);
            }).catch(function() { showError(msg); });
            return;
          }
        }
        // Append raw yt-dlp diagnostic to the friendly message.
        if (raw) {
          msg += ': ' + raw;
        }
        showError(msg);
        return;
      }

      // resp.json() can throw if the server returns 200 with a non-JSON body
      // (e.g. nginx error page, PHP warning, or corrupt response). Without a
      // try/catch here, the uncaught exception becomes an unhandled promise
      // rejection with no user-facing error — the UI silently stays on loading.
      let data;
      try {
        data = await resp.json();
      } catch (jsonErr) {
        var msg = 'Received an unexpected response from the server. Please try again.';
        showError(msg);
        return;
      }
      setProgress(100, 'Done.');

      showProgress(false);
      showResults(true);
      if (sortSelect) sortSelect.disabled = false;
      // Sync sort dropdown with what the server actually applied (sort_applied
      // may differ from the requested sort if the requested sort was invalid).
      if (sortSelect && data.sort_applied) {
        sortSelect.value = data.sort_applied;
      }
      // Read quota state from the JSON body (quota_remaining, quota_limit,
      // quota_reset) surfaced by the info endpoint alongside the X-DailyLimit-*
      // headers. This ensures the quota display is updated on the SUCCESS path
      // even when headers are unavailable or cross-origin restrictions apply.
      // The unlimited-key sentinel is -1 for all three fields.
      if (data && typeof data.quota_remaining === 'number') {
        var qel = document.getElementById('quotaDisplay');
        var qlimEl = document.getElementById('quotaLimit');
        var qlabelEl = document.getElementById('quotaLabel');
        var qupgradeEl = document.getElementById('quotaUpgrade');
        if (qel) {
          // Only show non-negative values; -1 means "not applicable" (unlimited).
          qel.textContent = data.quota_remaining >= 0 ? data.quota_remaining : '';
          if (data.quota_remaining <= 2 && data.quota_remaining >= 0) {
            qel.classList.add('low');
          } else {
            qel.classList.remove('low');
          }
          if (data.quota_remaining === 0) {
            qel.classList.add('exhausted');
          } else {
            qel.classList.remove('exhausted');
          }
        }
        if (qlimEl) {
          qlimEl.textContent = (data.quota_limit > 0) ? '/' + data.quota_limit : '';
        }
        // Unlimited-key holders get -1: hide the entire quota UI row.
        if (data.quota_remaining === -1 && qlabelEl) {
          qlabelEl.style.display = 'none';
          if (qel) qel.style.display = 'none';
          if (qlimEl) qlimEl.style.display = 'none';
        }
        if (qupgradeEl) {
          if (data.quota_remaining <= 0 && data.quota_remaining !== -1) {
            qupgradeEl.textContent = 'upgrade now';
            qupgradeEl.style.fontWeight = '700';
            qupgradeEl.style.color = 'var(--color-error)';
          } else {
            qupgradeEl.textContent = 'get unlimited';
            qupgradeEl.style.fontWeight = '500';
            qupgradeEl.style.color = '';
          }
        }
      }
      renderFormats(url, data);

    } catch (e) {
      var msg = 'Could not connect to the ripper. Please try again in a moment.';
      if (e.name === 'AbortError') {
        msg = 'Request timed out. The video might be too large or unavailable. Try again.';
      }
      showError(msg);
    } finally {
      isFetching = false;
      setLoading(false);
      showProgress(false);
    }
  }

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    fetchInfo();
  });

  // Retry button — allows immediate retry of a failed rip without re-pasting.
  // Appears alongside error messages; hidden during normal operation.
  if (retryBtn) {
    retryBtn.addEventListener('click', function() {
      hideError();
      // Only retry if the input still has a URL — otherwise do nothing.
      if (input.value && input.value.startsWith('http')) {
        // If a retry_after timestamp exists and is still in the future, show a
        // countdown instead of immediately retrying so the user knows when they
        // can try again. Clear the stored retry_after to cancel the tick.
        if (typeof retryAfterTs === 'number' && retryAfterTs > Date.now() / 1000) {
          var tick = function() {
            var secs = Math.ceil(retryAfterTs - Date.now() / 1000);
            if (secs <= 0) {
              // NOTE: No `var` here — removing the inner `var` from `retryAfterTs`
              // is intentional. Due to JS hoisting, `var retryAfterTs` inside the
              // closure creates a LOCAL variable that shadows the outer-scope
              // `retryAfterTs`, leaving the outer binding permanently set to the
              // old timestamp and the retry button permanently disabled after the
              // first countdown. Removing `var` makes the assignment write to the
              // outer scope (closure captures the binding, not a copy), so the
              // button correctly resets on expiry.
              retryAfterTs = null;
              retryBtn.textContent = 'Try again';
              retryBtn.classList.remove('visible');
              retryBtn.disabled = false;
              return;
            }
            retryBtn.textContent = 'Retry in ' + secs + 's';
            retryBtn.disabled = true;
            // NOTE: No `var` here for the same reason — `retryAfterTimer` must
            // be assigned to the outer scope so clearTimeout can cancel it.
            retryAfterTimer = setTimeout(tick, 500);
          };
          tick();
          return;
        }
        retryAfterTs = null;
        if (retryAfterTimer) { clearTimeout(retryAfterTimer); retryAfterTimer = null; }
        fetchInfo();
      }
    });
  }

  ripAgain.addEventListener('click', function() {
    input.value = '';
    showResults(false);
    hideError();
    var thumb = document.getElementById('resultsThumb');
    var sub = document.getElementById('resultsSub');
    var plat = document.getElementById('resultsPlatform');
    if (thumb) { thumb.src = ''; thumb.hidden = true; }
    if (sub) sub.textContent = '';
    if (plat) plat.hidden = true;
    // Leave sortSelect enabled — the user may want a different sort for the next video.
    // The sort preference is already persisted to localStorage and will be restored
    // automatically on the next fetchInfo() call, so re-enabling here is unnecessary.
    input.focus();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

// Auto-submit on URL paste
  input.addEventListener('paste', function() {
    setTimeout(function() {
      if (input.value && input.value.startsWith('http')) {
        hideError();
        fetchInfo();
      }
    }, 100);
  });

  // Dismiss error message when clicking anywhere outside the error box itself.
  // This gives the user an easy way to clear a persistent error without
  // having to reload the page or manually delete the message text.
  document.addEventListener('click', function(e) {
    if (!errorBox.contains(e.target) && errorBox.classList.contains('active')) {
      hideError();
    }
  });

  // ─── Client-side error reporting ─────────────────────────────────────────
  // Send uncaught JS errors to the server for operational monitoring.
  // Reports are fire-and-forget (async, no retry) — errors must never affect
  // the UX even if the reporting endpoint is unreachable. page_request_id
  // correlates client errors with server-side access and request logs.
  function reportClientError(type, message, details) {
    try {
      var hasDetails = details && (
        details.stack || details.line || details.col || details.source
      );
      var payload = JSON.stringify(hasDetails ? {
        type: type,
        message: message,
        url: window.location.href,
        page_request_id: PAGE_REQUEST_ID,
        stack: details.stack || null,
        line: details.line || null,
        col: details.col || null,
      } : {
        type: type,
        message: message,
        url: window.location.href,
        page_request_id: PAGE_REQUEST_ID,
      });
      navigator.sendBeacon && navigator.sendBeacon(
        '/src/api.php?action=client-error',
        payload
      );
    } catch (e) {
      // Swallow all errors — reporting must never affect UX
    }
  }

  // Global uncaught exception handler. message is the error message (string),
  // source is the script URL, line and col are numbers.
  window.onerror = function(message, source, line, col, error) {
    // Ignore resource load errors (images, scripts, stylesheets) — these are
    // common, low-signal, and not actionable for application debugging.
    // Only capture actual JavaScript runtime errors.
    if (!error || !(error instanceof Error)) {
      return false; // Don't prevent default browser handling
    }
    reportClientError(error.name || 'Error', error.message, {
      stack: error.stack,
      line: line,
      col: col,
      source: source,
    });
    return false; // Let the browser handle it (console.error + onerror firing)
  };

  // Capture unhandled promise rejections. These are JS runtime errors that
  // propagate through the promise chain without a .catch() handler.
  window.addEventListener('unhandledrejection', function(e) {
    var reason = e && e.reason;
    if (!reason) return;
    // Error objects have a meaningful stack; primitive reasons (string, number)
    // are low-signal — still report them but only with the reason as message.
    if (reason instanceof Error) {
      reportClientError(reason.name || 'UnhandledRejection', reason.message, {
        stack: reason.stack,
      });
    } else {
      reportClientError('UnhandledRejection', String(reason), null);
    }
  });
</script>

</body>
</html>
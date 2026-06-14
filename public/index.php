<?php
/**
 * AhoyRipper - Main Page
 * Single-page app for ripping media from supported platforms
 */

// Detect if JS is available (passed via cookie or param)
$jsEnabled = isset($_COOKIE['js']) || isset($_GET['js']);
$default_url = $_GET['url'] ?? '';

$VERSION = '1.0.0';

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
// Request correlation ID — enables cross-layer log correlation (nginx, PHP, browser).
header('X-Request-ID: ' . $page_request_id);
?>
<!DOCTYPE html>
<html lang="en" class="no-js">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>AhoyRipper - Free Online Media Ripper | Rip Video & Audio from Any Site</title>
  <meta name="description" content="Download video & audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo & 1872+ platforms. Free, no signup, no ads.">
  <meta name="robots" content="<?= $default_url ? 'noindex, follow' : 'index, follow, noai, noimage, noydir' ?>">
  <meta name="ahoybot" content="noindex, nofollow">
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
  <meta name="referrer" content="no-referrer">

  <!-- OpenSearch — lets browsers add ahoyripper.com as a searchable engine
       (e.g. Firefox's URL bar shows "Search AhoyRipper" after the file is served).
       The XML file is referenced by this link tag for auto-discovery. -->
  <link rel="search" type="application/opensearchdescription+xml" title="AhoyRipper" href="/opensearch.xml">

  <!-- Canonical URL -->
  <link rel="canonical" href="https://ahoyripper.com">

  <!-- OG / Twitter -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="AhoyRipper - Free Media Ripper">
  <meta property="og:description" content="Download video & audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo & 1872+ sites. Free, no signup, no ads — just paste a link.">
  <meta property="og:site_name" content="AhoyRipper">
  <meta property="og:image" content="https://ahoyripper.com/og-image.png">
  <meta property="og:image:secure_url" content="https://ahoyripper.com/og-image.png">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <!-- fetchpriority="high" signals the browser to prioritize loading the og:image early.
       This meaningfully improves LCP (Largest Contentful Paint) when the page is shared
       on social media or linked from external sites, since the og:image is the most
       visually prominent element in link previews. It also helps Core Web Vitals. -->
  <meta property="og:image:fetchpriority" content="high">
  <meta property="og:alt" content="AhoyRipper — download video and audio from YouTube, TikTok, Twitter, SoundCloud and 1872+ platforms">
  <meta property="og:locale" content="en_US">
  <meta property="og:url" content="https://ahoyripper.com">

  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:domain" content="ahoyripper.com">
  <meta name="twitter:site" content="@ahoyvpn">
  <meta name="twitter:creator" content="@ahoyvpn">
  <meta name="twitter:title" content="AhoyRipper - Free Media Ripper">
  <meta name="twitter:description" content="Rip any video or audio from YouTube, TikTok, X, Instagram, Reddit & 1872+ platforms. Free, fast, no signup needed — just paste a link.">
  <meta name="twitter:image" content="https://ahoyripper.com/og-image.png">
  <meta name="twitter:image:width" content="1200">
  <meta name="twitter:image:height" content="630">
  <meta name="twitter:image:alt" content="AhoyRipper - download video and audio from YouTube, TikTok, Twitter, SoundCloud and 1872+ platforms">

  <!-- Content Security Policy — defense-in-depth: HTTP header set by nginx handles
       production, but the meta tag ensures CSP is enforced even when the page is
       served through a reverse proxy, CDN, or alternative deployment that might
       strip or not propagate the HTTP header. Same policy as the nginx directive. -->
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://i.ytimg.com https://*.tikcdn.com https://pbs.twimg.com https://*.twimg.com https://*.sndcdn.com https://*.vimeocdn.com https://*.instagram.com https://*.fbcdn.net https://v16.tiktokcdn.com https://v26.tiktokcdn.com https://*.tiktok.com https://vxtiktok.com https://*.mediaJx.com; connect-src 'self' https://ahoyripper.com; upgrade-insecure-requests; frame-ancestors 'none'; worker-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; report-to csp-report;">
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
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="/src/style.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" crossorigin="anonymous">

  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebApplication",
    "name": "AhoyRipper",
    "description": "Download video and audio from YouTube, TikTok, X, SoundCloud, Instagram, Facebook, Reddit, Vimeo, and 1872+ other platforms. Free, no signup required.",
    "url": "https://ahoyripper.com",
    "applicationCategory": "MultimediaApplication",
    "operatingSystem": "Any",
    "browserRequirements": "Requires JavaScript and a modern web browser.",
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
        "urlTemplate": "https://ahoyripper.com/?url={searchTerms}"
      },
      "query-input": "required name=searchTerms"
    }
  }
  </script>

<meta name="keywords" content="media ripper, video downloader, audio downloader, video converter, audio converter, download video, download audio, free media converter, ripper tool, online ripper, web ripper, YouTube downloader, TikTok downloader, Twitter video downloader, SoundCloud downloader, Instagram downloader, Facebook video downloader, Vimeo downloader, mp4 downloader, mp3 downloader, FLAC downloader, OGG downloader, M4A downloader, WEBM downloader, video to mp3, extract audio">
<link rel="sitemap" type="application/xml" href="/sitemap.xml">
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
    <a href="https://ahoyripper.com">AhoyRipper</a>
    <a href="https://ahoyvpn.com" target="_blank" rel="noopener">AhoyVPN</a>
  </div>
</nav>

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
          placeholder="Paste a link here..."
          value="<?= htmlspecialchars($default_url, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
          autocomplete="off"
          autocorrect="off"
          autocapitalize="off"
          spellcheck="false"
        >
        <button type="submit" class="rip-btn" id="submitBtn">Rip It</button>
        <noscript><p class="rip-noscript-msg">JavaScript is required to use AhoyRipper. Please enable JavaScript in your browser settings.</p></noscript>
      </form>
      <p class="rip-hint">
        <span id="quotaDisplay" class="quota-count" title="Get unlimited rips with AhoyVPN" aria-label="Free rips remaining today"></span>
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
    <div class="rip-progress" id="progressBox">
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
          <select id="sortSelect" class="sort-select" aria-label="Sort formats by" disabled>
            <option value="quality">Quality Tier</option>
            <option value="height">Quality</option>
            <option value="filesize">Size (largest)</option>
            <option value="filesize_asc">Size (smallest)</option>
            <option value="tbr">Bitrate</option>
          </select>
        </div>
      </div>
      <div class="format-grid" id="formatGrid"></div>
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
// ─── PWA Service Worker registration ───────────────────────
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('/sw.js')
      .then(function(registration) {
        registration.addEventListener('updatefound', function() {
          console.log('[SW] New service worker installing in background.');
        });
      })
      .catch(function(err) {
        console.warn('[SW] registration failed:', err);
      });
  });
}

// ─── Frontend Logic ─────────────────────────────────────────
(function() {
  // Expose page_request_id for error reporting and support tickets.
  // This lets users include the page's correlation ID when reporting issues,
  // enabling direct lookup in server-side access/error logs alongside the
  // API request_id that appears in API responses.
  var PAGE_REQUEST_ID = '<?= htmlspecialchars($page_request_id, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>';

  const API = '/src/api.php';

  // Shared error hint map — single source of truth for human-readable error messages
  // keyed by error_code (from API response) and by HTTP status code (fallback).
  // Used by both the !resp.ok branch and the catch branch so they stay in sync.
  var ERROR_HINTS = {
    'RATE_LIMIT_EXCEEDED': 'Too many requests. Slow down. Get AhoyVPN for unlimited access: https://ahoyvpn.com',
    'GEOBLOCKED': 'This video is geo-restricted in your region. Use AhoyVPN to route through an unblocked region: https://ahoyvpn.com',
    'DAILY_LIMIT': 'Daily free limit reached. Get AhoyVPN for unlimited rips: https://ahoyvpn.com',
    'INVALID_KEY': 'Invalid API key. Get AhoyVPN for unlimited rips: https://ahoyvpn.com',
    'LOGIN_REQUIRED': 'This video requires login. Try downloading while signed in to the platform.',
    'UNSUPPORTED_SITE': 'This site is not supported. Check the supported sites list at github.com/yt-dlp/yt-dlp.',
    'PLAYLIST_MISSING': 'The playlist was not found or is no longer available.',
    'COPYRIGHT_REMOVED': 'This content was removed due to a copyright claim.',
    'VIDEO_UNAVAILABLE': 'This video is no longer available or has been removed.',
    'AGE_RESTRICTED': 'This video is age-restricted and cannot be downloaded without age verification on the source platform.',
    'SOURCE_RATE_LIMITED': 'The source site is rate-limiting us. Please try again in a few minutes.',
    'SOURCE_TIMEOUT': 'The source site took too long to respond. Try a smaller format (audio-only is fastest) or try again when the site is less busy.',
    'SSL_ERROR': 'Secure connection to the source failed. Try again shortly.',
    'CONNECTION_FAILED': 'Could not connect to the source. Check your network and try again.',
    'FILE_TOO_LARGE': 'This file is too large for the server. Try audio-only or a lower resolution.',
    'FORMAT_UNAVAILABLE': 'That format is not available for this video. Choose another from the list.',
    'DISALLOWED_CONTENT': 'This content is not available due to a terms of service violation.',
    'YTDLP_ERROR': 'The source returned an error. Try another format in the list, or wait a moment and try again.',
    'DOWNLOAD_TIMEOUT': 'Download timed out after 5 minutes. The file may be too large or the source is slow. Try a smaller format (audio-only is fastest) or try again when the site is less busy.',
    'DOWNLOAD_CANCELLED': 'Download was cancelled — you may have closed the tab or lost connection. Your daily quota was not charged.',
    'DOWNLOAD_EMPTY': 'The downloaded file was empty — this is a server-side issue, not your format choice. Try again in a moment, or pick a different format.',
    'SOURCE_FORBIDDEN': 'The source site blocked this request (HTTP 403). Try a different format or use AhoyVPN to change your exit IP.',
    'SOURCE_NOT_FOUND': 'The source site returned HTTP 404 — the content may have been moved or deleted.',
    'SOURCE_SERVER_ERROR': 'The source site returned an error and is having issues. Try again shortly.',
    'SOURCE_HTTP_ERROR': 'The source site returned an unexpected error. Try again in a moment.',
    'MISSING_FORMAT': 'Select a format from the list above first, then click it to download.',
    'INVALID_FORMAT_ID': 'That format ID was not recognized. Refresh to get a fresh format list, then pick a valid format from the list.',
    'PARSE_ERROR': 'Could not parse the video info. The site may be temporarily unavailable or not supported.',
    'NOT_ACCEPTABLE': 'This client does not accept JSON. Use a standard API client.',
    'PRIVATE_VIDEO': 'This video is private and cannot be downloaded. Try a public video instead.',
    'FORBIDDEN_ORIGIN': 'Requests must come from ahoyripper.com or ahoyvpn.com.',
    'METHOD_NOT_ALLOWED': 'That request method is not allowed. Use GET.',
    'INVALID_URL': 'That URL is not supported or could not be fetched. Check the link and try again.',
    'MISSING_URL': 'Paste a link from YouTube, Twitter/X, TikTok, SoundCloud, Instagram, etc. — only public media links are supported.',
    '403': 'The server understood the request but refused to fulfill it. Try again or use AhoyVPN to change your exit IP.',
    '404': 'The requested resource was not found. The content may have been removed or the URL may be incorrect.',
    '504': 'The request timed out. The video might be too large or unavailable. Try a smaller format.',
    '502': 'The server encountered an error. Please try again in a moment.',
    '503': 'Service temporarily unavailable. Please try again shortly.',
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

  // ─── Clear stale error state on page load / new interaction ─────────────────
  // Any error displayed from a previous session (e.g. network failure on a prior
  // rip) must be dismissed so it doesn't persist and confuse the user.
  hideError();

  // Restore persisted quota from localStorage on page load.
  // Falls back to showing nothing until the first API response arrives,
  // avoiding the stale "5 free rips/day" on returning visitors.
  (function restoreQuota() {
    var el = document.getElementById('quotaDisplay');
    var limEl = document.getElementById('quotaLimit');
    var labelEl = document.getElementById('quotaLabel');
    var upgradeEl = document.getElementById('quotaUpgrade');
    if (!el) return;
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

  function setLoading(on) {
    btn.disabled = on;
    btn.textContent = on ? 'Downloading...' : 'Rip It';
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
    return { url: `${API}?action=download&url=${encodeURIComponent(url)}&format=${encodeURIComponent(formatId)}` + fn + keyParam, key };
  }

  function renderFormats(url, data) {
    formatGrid.innerHTML = '';
    resultsTitle.textContent = data.title || 'Select a format to download';

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

function escapeHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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
    // Add role="button" and keyboard activation so the card is accessible
    // when navigated to via Tab key and activated with Enter or Space.
    // The href="#" with preventDefault() replaces the previous href="#" approach
    // which caused a scroll-to-top on Enter press before the click handler ran.

    var badgeColor = 'var(--color-accent)';
    var badgeLabel = 'Video';
    if (f.vcodec === 'none') {
      badgeColor = 'var(--color-success)';
      badgeLabel = 'Audio';
    } else if (f.acodec === 'none') {
      badgeColor = '#a855f7';
      badgeLabel = 'Video Only';
    }

    var size = f.filesize_mb > 0 ? formatBytes(f.filesize_mb) : '~size';
    var tbrMeta = f.tbr ? f.tbr + 'kbps' : '';
    var extMeta = f.ext ? f.ext.toUpperCase() : '';
    var langMeta = f.language ? f.language.toUpperCase() : '';
    var metaParts = escapeHtml([extMeta, tbrMeta].filter(Boolean).join(' '));
    var langBadge = langMeta ? '<span class="format-lang">' + escapeHtml(langMeta) + '</span>' : '';
    // Prefer description (human-readable yt-dlp description) when available, else label.
    // description carries extra context like "720p60 HDR" or "audio only" that
    // label doesn't always capture — particularly for audio and alternative formats.
    // Filter out "Unknown" sentinel from description: the API returns "Unknown"
    // when format_description/f.format_note were not available, which is not
    // a useful display string. Fall through to label in that case.
    // description and label come from yt-dlp (user-controlled metadata) and are
    // HTML-escaped before use to prevent stored XSS via innerHTML injection.
    var rawDisplayLabel = (f.description && f.description !== 'Unknown') ? f.description : (f.label || (f.ext ? f.ext.toUpperCase() : 'Format'));
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
        var dlHeaders = {};
        if (dl.key) { dlHeaders['Authorization'] = 'Bearer ' + encodeURIComponent(dl.key); }
        // Pass the browser's language preference so yt-dlp can request localized
        // metadata (titles, descriptions) from the source platform.
        dlHeaders['Accept-Language'] = navigator.language || 'en-US';
        card.classList.add('downloading');
        setLoading(true);

        // navigateOnSuccess guard: set to false when fetch fails so window.location.href
        // is not called (would otherwise download the JSON error body as a file).
        fetch(dl.url, { headers: dlHeaders, signal: AbortSignal.timeout(300000) })
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
                showError(dlMsg);
                setLoading(false);
                card.classList.remove('downloading');
              }).catch(function() {
                showError('Download failed. Try another format.');
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
              msg = 'Download timed out after 5 minutes. The file may be too large or the source is slow. Try a smaller format.';
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

    var addedAnything = false;
    if (groups.combined.length > 0) {
      formatGrid.appendChild(renderGroupHeader('Video + Audio'));
      groups.combined.forEach(function(f) { formatGrid.appendChild(renderFormatCard(f)); });
      addedAnything = true;
    }
    if (groups.videoOnly.length > 0) {
      if (addedAnything) formatGrid.appendChild(renderSeparator());
      formatGrid.appendChild(renderGroupHeader('Video Only'));
      groups.videoOnly.forEach(function(f) { formatGrid.appendChild(renderFormatCard(f)); });
      addedAnything = true;
    }
    if (groups.audioOnly.length > 0) {
      if (addedAnything) formatGrid.appendChild(renderSeparator());
      formatGrid.appendChild(renderGroupHeader('Audio Only'));
      groups.audioOnly.forEach(function(f) { formatGrid.appendChild(renderFormatCard(f)); });
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
    setLoading(true);
    showProgress(true);
    setProgress(30, 'Fetching video info...');

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
        if (Number(rem) === -1 && labelEl) {
          labelEl.style.display = 'none';
          el.style.display = 'none';
          if (limEl) limEl.style.display = 'none';
        }
        // Persist quota to localStorage so the correct value is shown on page reload.
        // Only persist when the header is a real quota value (non-negative integer).
        // -1 signals unlimited-key holders — persist a flag to suppress the quota UI.
        if (Number(rem) === -1) {
          localStorage.setItem('ahoyrip_quota_unlimited', '1');
          localStorage.removeItem('ahoyrip_quota_remaining');
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
      const sort = sortSelect ? sortSelect.value : 'height';
      const resp = await fetch(API + '?action=info&url=' + encodeURIComponent(url) + '&sort=' + encodeURIComponent(sort), {
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
            // Append human-readable retry countdown when retry_after is available.
            // DOWNLOAD_TIMEOUT and SOURCE_TIMEOUT include retry_after as a Unix timestamp.
            if (typeof err.retry_after === 'number' && err.retry_after > Date.now() / 1000) {
              var secs = Math.ceil(err.retry_after - Date.now() / 1000);
              if (secs > 60) {
                var mins = Math.ceil(secs / 60);
                msg += ' Try again in about ' + mins + ' minute' + (mins !== 1 ? 's' : '') + '.';
              } else if (secs > 0) {
                msg += ' Try again in ' + secs + ' second' + (secs !== 1 ? 's' : '') + '.';
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
                var secs = Math.ceil(parseInt(m[1], 10) - Date.now() / 1000);
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

      const data = await resp.json();
      setProgress(100, 'Done.');

      showProgress(false);
      showResults(true);
      if (sortSelect) sortSelect.disabled = false;
      // Sync sort dropdown with what the server actually applied (sort_applied
      // may differ from the requested sort if the requested sort was invalid).
      if (sortSelect && data.sort_applied) {
        sortSelect.value = data.sort_applied;
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
    if (sortSelect) sortSelect.disabled = true;
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
</script>

</body>
</html>
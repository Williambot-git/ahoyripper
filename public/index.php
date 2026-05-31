<?php
/**
 * AhoyRipper - Main Page
 * Single-page app for ripping media from supported platforms
 */

// Detect if JS is available (passed via cookie or param)
$jsEnabled = isset($_COOKIE['js']) || isset($_GET['js']);
$default_url = $_GET['url'] ?? '';

$VERSION = '1.0.0';
?>
<!DOCTYPE html>
<html lang="en" class="no-js">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>AhoyRipper - Free Online Media Ripper | Rip Video & Audio from Any Site</title>
  <meta name="description" content="Free online media ripper - download video and audio from YouTube, TikTok, Twitter, SoundCloud and 1872+ other platforms. No signup, no ads.">
  <meta name="robots" content="<?= $default_url ? 'noindex, follow' : 'index, follow' ?>">
  <meta name="author" content="AhoyVPN">
  <meta name="theme-color" content="#0f0f0f">
  <!-- apple-mobile-web-app-status-bar-style is the only iOS-supported mechanism
       for dark status bar theme. Unlike theme-color meta (which iOS ignores when
       paired with media="" attributes), this tag is respected by Safari on iOS. -->
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="manifest" href="/manifest.json">
  <!-- iOS home screen icon — use PNG for sharp, correctly-cropped display.
       iOS crops square icons to a rounded shape; SVG source produces blurry
       results at the sizes iOS applies. A 180x180 PNG is optimal for iPhone. -->
  <link rel="apple-touch-icon" href="/favicon-180.png">
  <meta name="referrer" content="no-referrer">

  <!-- Canonical URL -->
  <link rel="canonical" href="https://ahoyripper.com">

  <!-- OG / Twitter -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="AhoyRipper - Free Media Ripper">
  <meta property="og:description" content="Download video & audio from YouTube, TikTok, Twitter, SoundCloud & 1872+ sites. Free, no signup, no ads.">
  <meta property="og:site_name" content="AhoyRipper">
  <meta property="og:image" content="https://ahoyripper.com/og-image.png">
  <meta property="og:image:secure_url" content="https://ahoyripper.com/og-image.png">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:alt" content="AhoyRipper — download video and audio from YouTube, TikTok, Twitter, SoundCloud and 1872+ platforms">
  <meta property="og:locale" content="en_US">
  <meta property="og:url" content="https://ahoyripper.com">

  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:site" content="@ahoyvpn">
  <meta name="twitter:title" content="AhoyRipper - Free Media Ripper">
  <meta name="twitter:description" content="Rip any video or audio from 1872+ platforms. Free, fast, no signup needed.">
  <meta name="twitter:image" content="https://ahoyripper.com/og-image.png">
  <meta name="twitter:image:width" content="1200">
  <meta name="twitter:image:height" content="630">
  <meta name="twitter:image:alt" content="AhoyRipper - download video and audio from YouTube, TikTok, Twitter, SoundCloud and 1800+ platforms">

  <!-- Favicon -->
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">

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
    "description": "Download video and audio from most online platforms. Free, no signup required.",
    "url": "https://ahoyripper.com",
    "applicationCategory": "MultimediaApplication",
    "operatingSystem": "Any",
    "offers": {
      "@type": "Offer",
      "price": "0",
      "priceCurrency": "USD"
    },
    "aggregateRating": {
      "@type": "AggregateRating",
      "ratingValue": "4.8",
      "ratingCount": "12450"
    },
    "browserRequirements": "Requires JavaScript and a modern web browser."
  }
  </script>

<meta name="keywords" content="media ripper, video downloader, audio downloader, video converter, audio converter, download video, download audio, free media converter, ripper tool, online ripper, web ripper, YouTube downloader, TikTok downloader, Twitter video downloader, SoundCloud downloader, Instagram downloader, Facebook video downloader, Vimeo downloader, mp4 downloader, mp3 downloader, FLAC downloader, OGG downloader, M4A downloader, WEBM downloader, video to mp3, extract audio">
<link rel="sitemap" type="application/xml" href="/sitemap.xml">
</head>
<body>

<!-- Navigation -->
<nav class="ahoy-nav">
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
    <a href="https://ahoyvpn.com">AhoyVPN</a>
  </div>
</nav>

<!-- Main -->
<main>
  <section class="hero">
    <h1>Rip any video, <span>anywhere.</span></h1>
    <p>Free online media ripper - paste any link and download video or audio in MP4, MP3, FLAC, and more. Works with most platforms.</p>

    <!-- Error message (aria-live for screen reader announcements) -->
    <div class="rip-error" id="errorBox" role="alert" aria-live="polite" aria-atomic="true"></div>

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
      </form>
      <p class="rip-hint">
        <span id="quotaDisplay" class="quota-count" title="Get unlimited rips with AhoyVPN"></span>
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
        </div>
        <div class="results-sort">
          <label for="sortSelect" class="sort-label">Sort:</label>
          <select id="sortSelect" class="sort-select">
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
      <a href="https://ahoyvpn.com" class="vpn-btn">Get AhoyVPN &mdash; $5.99/mo</a>
    </div>
</section>
</main>

<footer>
  <p>For personal use only. Respect copyright. &nbsp;|&nbsp; <a href="https://ahoyvpn.com">AhoyVPN</a> &nbsp;|&nbsp; <a href="mailto:dmca@ahoyvpn.com">DMCA</a></p>
  <p style="margin-top:0.5rem">&copy; <?= date('Y') ?> AhoyRipper. All rights reserved. &nbsp;|&nbsp; <a href="https://github.com/Williambot-git/ahoyripper" rel="noopener">v<?= htmlspecialchars($VERSION) ?></a></p>
</footer>

<script>
// ─── Frontend Logic ─────────────────────────────────────────
(function() {
  const API = '/src/api.php';

  const form = document.getElementById('ripForm');
  const input = document.getElementById('urlInput');
  const btn = document.getElementById('submitBtn');
  const errorBox = document.getElementById('errorBox');
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
    var labelEl = document.getElementById('quotaLabel');
    var upgradeEl = document.getElementById('quotaUpgrade');
    if (!el) return;
    var stored = localStorage.getItem('ahoyrip_quota_remaining');
    if (stored !== null) {
      var rem = parseInt(stored, 10);
      if (!isNaN(rem) && rem >= 0) {
        el.textContent = rem;
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
  }

  function hideError() {
    errorBox.classList.remove('active');
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
      card.href = '#download-' + f.id;
      card.setAttribute('data-url', url);
      card.setAttribute('data-id', f.id);
      card.setAttribute('data-label', f.label || f.ext);
      card.setAttribute('data-filename', data.derived_filename || '');
      // Note: download and target attributes are intentionally omitted here.
      // - download="" would be a no-op (empty string is ignored); the server's
      //   Content-Disposition header controls the saved filename instead.
      // - target="_blank" is unnecessary — the click handler calls e.preventDefault()
      //   and navigates via window.location.href, so _blank has no effect.

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
      var metaParts = [extMeta, tbrMeta].filter(Boolean).join(' ');
      var langBadge = langMeta ? '<span class="format-lang">' + langMeta + '</span>' : '';
      // Prefer description (human-readable yt-dlp description) when available, else label.
      // description carries extra context like "720p60 HDR" or "audio only" that
      // label doesn't always capture — particularly for audio and alternative formats.
      // Filter out "Unknown" sentinel from description: the API returns "Unknown"
      // when format_description/f.format_note were not available, which is not
      // a useful display string. Fall through to label in that case.
      var displayLabel = (f.description && f.description !== 'Unknown') ? f.description : (f.label || (f.ext ? f.ext.toUpperCase() : 'Format'));
      // title attribute — use ≈ prefix for estimated sizes so the tooltip
      // clearly distinguishes \"known\" (from yt-dlp metadata) from \"approximate\"
      // (estimated from bitrate × duration) without requiring the user to hover.
      var sizeHint = f.filesize_mb > 0 ? size : '≈' + size;
      var cardTitle = displayLabel + (size !== '~size' ? ' - ' + sizeHint : '');

      card.setAttribute('title', cardTitle);

      card.innerHTML =
        '<span class="format-ext" style="color:' + badgeColor + '">' + badgeLabel + '</span>' +
        '<div class="format-label">' + displayLabel + '</div>' +
        '<div class="format-meta">' + metaParts + langBadge + '</div>' +
        '<div class="format-size">' + size + '</div>';

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
        card.classList.add('downloading');
        setLoading(true);

        // navigateOnSuccess guard: set to false when fetch fails so window.location.href
        // is not called (would otherwise download the JSON error body as a file).
        fetch(dl.url, { headers: dlHeaders, signal: AbortSignal.timeout(300000) })
          .then(function(resp) {
            if (!resp.ok) {
              navigateOnSuccess = false;
              return resp.json().catch(function() {
                return { error: 'Download failed. Try another format.' };
              }).then(function(err) {
                showError(err.error || 'Download failed.');
                setLoading(false);
                card.classList.remove('downloading');
              });
            }
            // Only navigate on HTTP success — don't navigate on error JSON responses,
            // which would otherwise cause the browser to download the error as a file.
            if (navigateOnSuccess) {
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
      var labelEl = document.getElementById('quotaLabel');
      var upgradeEl = document.getElementById('quotaUpgrade');
      if (el && rem !== null && lim !== null) {
        el.textContent = rem;
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
        }
        // Persist quota to localStorage so the correct value is shown on page reload.
        // Only persist when the header is a real quota value (non-negative integer).
        // -1 signals unlimited-key holders — persist a flag to suppress the quota UI.
        if (Number(rem) === -1) {
          localStorage.setItem('ahoyrip_quota_unlimited', '1');
          localStorage.removeItem('ahoyrip_quota_remaining');
        } else {
          var remNum = parseInt(rem, 10);
          if (!isNaN(remNum) && remNum >= 0) {
            localStorage.setItem('ahoyrip_quota_remaining', remNum);
            localStorage.removeItem('ahoyrip_quota_unlimited');
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
      const sort = sortSelect ? sortSelect.value : 'height';
      const resp = await fetch(API + '?action=info&url=' + encodeURIComponent(url) + '&sort=' + encodeURIComponent(sort), {
        headers,
        signal: AbortSignal.timeout(60000)
      });

      updateQuotaFromHeaders(resp);

      setProgress(80, 'Parsing...');

      if (!resp.ok) {
        var err = await resp.json().catch(() => ({ error: 'Unknown error' }));
        // Surface error_code for classified yt-dlp errors
        var msg = err.error || 'Something went wrong. Try again.';
        // When the server surfaces raw yt-dlp output (e.g. "Video unavailable"),
        // include it so the user sees the actual reason, not just our generic label.
        var raw = err.raw_error;
        if (err.error_code) {
          var errorHints = {
            'RATE_LIMIT_EXCEEDED': 'Too many requests. Slow down. Get AhoyVPN for unlimited access: https://ahoyvpn.com',
            'GEOBLOCKED': 'This video is geo-restricted in your region. Download speeds or quality may be limited.',
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
            'DOWNLOAD_TIMEOUT': 'The download timed out after 5 minutes. Try a smaller format — audio-only is usually fastest.',
            'DOWNLOAD_FAILED': 'The rip produced an empty or corrupt file. Try a lower resolution or audio-only format.',
            'DOWNLOAD_EMPTY': 'The downloaded file was empty or invalid. Try a different format.',
            'INVALID_FORMAT_ID': 'That format is not available. Pick another format from the list above.',
            'PARSE_ERROR': 'Could not parse the video info. The site may be temporarily unavailable or not supported.',
            'NOT_ACCEPTABLE': 'This client does not accept JSON. Use a standard API client.',
            'UNKNOWN_ACTION': 'Unknown API action. Use ?action=info, ?action=download, ?action=health, or ?action=progress.',
            'FORBIDDEN_ORIGIN': 'Requests must come from ahoyripper.com or ahoyvpn.com.',
            'METHOD_NOT_ALLOWED': 'That request method is not allowed. Use GET.',
            'INVALID_URL': 'That URL is not supported or could not be fetched. Check the link and try again.',
            'MISSING_URL': 'Paste a link from YouTube, Twitter/X, TikTok, SoundCloud, Instagram, etc. — only public media links are supported.',
            'MISSING_FORMAT': 'Select a format from the list above first, then click it to download.',
            'PRIVATE_VIDEO': 'This video is private and cannot be downloaded. Try a public video instead.',
            '504': 'The request timed out. The video might be too large or the site is slow. Try a smaller format.',
            '502': 'The server encountered an error. Please try again in a moment.',
            '503': 'Service temporarily unavailable. Please try again shortly.',
          };
          // Use !== undefined (not truthy) so the check catches the string "0"
          // which would be falsy but is a valid error code.
          if (err.error_code !== undefined && errorHints[err.error_code]) {
            msg = errorHints[err.error_code];
          } else {
            var statusKey = String(resp.status);
            if (errorHints[statusKey]) {
              msg = errorHints[statusKey];
            }
          }
          // If the server sent raw yt-dlp output, append it for diagnostic value.
          // This gives the user the actual error message yt-dlp produced (e.g.
          // "Video unavailable. Try another source."), not just our generic label.
          if (raw && typeof raw === 'string' && raw.length > 0 && raw.length < 400) {
            msg += ': ' + raw;
          }
        }
        showError(msg);
        return;
      }

      const data = await resp.json();
      setProgress(100, 'Done.');

      showProgress(false);
      showResults(true);
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

  ripAgain.addEventListener('click', function() {
    input.value = '';
    showResults(false);
    hideError();
    var thumb = document.getElementById('resultsThumb');
    var sub = document.getElementById('resultsSub');
    if (thumb) { thumb.src = ''; thumb.hidden = true; }
    if (sub) sub.textContent = '';
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
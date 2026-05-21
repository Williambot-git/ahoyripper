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
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AhoyRipper - Free Online Media Ripper | Rip Video & Audio from Any Site</title>
  <meta name="description" content="Free online media ripper - download video and audio from YouTube, TikTok, Twitter, SoundCloud and 1800+ other platforms. No signup, no ads.">
  <meta name="robots" content="<?= $default_url ? 'noindex, follow' : 'index, follow' ?>">
  <meta name="author" content="AhoyVPN">
  <meta name="theme-color" content="#0f0f0f">

  <!-- Security headers -->
  <meta http-equiv="X-Content-Type-Options" content="nosniff">
  <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
  <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">
  <meta http-equiv="Permissions-Policy" content="camera=(), microphone=(), geolocation=()">
  <meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'none'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; frame-src 'none'; object-src 'none'; base-uri 'none';">

  <!-- Canonical URL -->
  <link rel="canonical" href="https://ahoyripper.com">

  <!-- OG / Twitter -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="AhoyRipper - Free Media Ripper">
  <meta property="og:description" content="Free online media ripper. Download video and audio from most platforms. No signup required.">
  <meta property="og:site_name" content="AhoyRipper">
  <meta property="og:image" content="https://ahoyripper.com/og-image.svg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:url" content="https://ahoyripper.com">

  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:site" content="@ahoyvpn">
  <meta name="twitter:title" content="AhoyRipper - Free Media Ripper">
  <meta name="twitter:description" content="Free online media ripper. Download video and audio from most platforms. No signup required.">
  <meta name="twitter:image" content="https://ahoyripper.com/og-image.svg">

  <!-- SVG Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%233b82f6'/><text x='50%25' y='55%25' dominant-baseline='middle' text-anchor='middle' font-family='Arial,sans-serif' font-weight='bold' font-size='20' fill='white'>R</text></svg>">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/src/style.css">

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

<meta name="keywords" content="media ripper, video downloader, audio ripper, download video, download audio, free media converter, ripper tool, online ripper">
<link rel="sitemap" type="application/xml" href="/sitemap.xml">
</head>
<body>

<!-- Navigation -->
<nav class="ahoy-nav">
  <a href="/" class="ahoy-nav-logo">
    <img src="/AhoyMonthly_transparent.png" alt="AhoyRipper logo" onerror="this.style.display='none'">
    <span>AhoyRipper</span>
  </a>
  <div class="ahoy-nav-links">
    <a href="https://ahoyvpn.net">AhoyVPN</a>
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
          type="url"
          class="rip-input"
          id="urlInput"
          placeholder="Paste a link here..."
          value="<?= htmlspecialchars($default_url, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
          autocomplete="off"
          autocorrect="off"
          autocapitalize="off"
          spellcheck="false"
          required
        >
        <button type="submit" class="rip-btn" id="submitBtn">Rip It</button>
      </form>
      <p class="rip-hint">
        Supports most platforms &mdash; 5 free rips/day
      </p>
      <div class="rip-key-wrap">
        <input type="password" id="apiKey" class="rip-key-input" placeholder="AhoyVPN unlimited key (optional)" autocomplete="off">
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
        <img class="results-thumb" id="resultsThumb" src="" alt="Media thumbnail" hidden>
        <div class="results-info">
          <p class="results-title">
            <span class="check">&#10003;</span>
            <span id="resultsTitle">Ready to download</span>
          </p>
          <p class="results-sub" id="resultsSub"></p>
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
    <div class="vpn-banner">
      <p><strong>Want unlimited, unrestricted access?</strong> Route through our VPN for total privacy and to bypass any restrictions.</p>
      <a href="https://ahoyvpn.net" class="vpn-btn">Get AhoyVPN &mdash; $5.99/mo</a>
    </div>
  
    <!-- VPN Upsell -->
    <div class="vpn-banner" style="margin-top:2rem; text-align:center; padding:2rem; background:linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius:12px; border:1px solid #3b82f6;">
      <h2 style="color:#fff; margin-bottom:0.5rem; font-size:1.5rem;">Unlimited Rips with AhoyVPN</h2>
      <p style="color:#ccc; margin-bottom:1.5rem;">10 free rips per day &mdash; or get unlimited access with our VPN plan for just $5.99/month. No logs, no tracking, cancel anytime.</p>
      <a href="https://ahoyvpn.net" class="vpn-btn" style="font-size:1.1rem; padding:0.75rem 2rem;">Get AhoyVPN &mdash; $5.99/mo</a>
    </div>
</section>
</main>

<footer>
  <p>For personal use only. Respect copyright. &nbsp;|&nbsp; <a href="https://ahoyvpn.net">AhoyVPN</a> &nbsp;|&nbsp; <a href="mailto:dmca@ahoyvpn.com">DMCA</a></p>
  <p style="margin-top:0.5rem">&copy; <?= date('Y') ?> AhoyRipper. All rights reserved.</p>
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
    btn.textContent = on ? 'Ripping...' : 'Rip It';
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
    if (mb >= 1000) return (mb / 1000).toFixed(1) + ' GB';
    if (mb >= 1) return mb + ' MB';
    return '<1 MB';
  }

  function buildDownloadUrl(url, formatId, label) {
    // For combined video+audio formats, we need to merge streams
    // yt-dlp handles this with the format string
    // Key is sent via Authorization header, not URL (keeps key out of logs)
    var keyInput = document.getElementById('apiKey');
    var key = (keyInput && keyInput.value) ? keyInput.value : '';
    return { url: `${API}?action=download&url=${encodeURIComponent(url)}&format=${encodeURIComponent(formatId)}`, key };
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

    function renderGroupheader(label) {
      var h = document.createElement('div');
      h.className = 'format-group-header';
      h.textContent = label;
      return h;
    }

    function renderFormatCard(f) {
      var card = document.createElement('a');
      card.className = 'format-card';
      card.href = buildDownloadUrl(url, f.id, f.label || f.ext);
      card.download = '';
      card.target = '_blank';
      card.rel = 'noopener noreferrer';

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
      var metaParts = [extMeta, tbrMeta].filter(Boolean).join(' ');

      card.innerHTML =
        '<span class="format-ext" style="color:' + badgeColor + '">' + badgeLabel + '</span>' +
        '<div class="format-label">' + (f.label || (f.ext ? f.ext.toUpperCase() : 'Format')) + '</div>' +
        '<div class="format-meta">' + metaParts + '</div>' +
        '<div class="format-size">' + size + '</div>';

      card.addEventListener('click', function(e) {
        e.preventDefault();
        var dl = buildDownloadUrl(url, f.id, f.label || f.ext);
        var dlHeaders = {};
        if (dl.key) { dlHeaders['Authorization'] = 'Bearer ' + encodeURIComponent(dl.key); }
        card.classList.add('downloading');
        setLoading(true);
        fetch(dl.url, { headers: dlHeaders, signal: AbortSignal.timeout(300000) })
          .then(function(resp) {
            if (!resp.ok) {
              return resp.json().catch(function() {
                return { error: 'Download failed. Try another format.' };
              }).then(function(err) {
                showError(err.error || 'Download failed.');
                setLoading(false);
                card.classList.remove('downloading');
              });
            }
            return resp.blob().then(function(blob) {
              var a = document.createElement('a');
              a.href = URL.createObjectURL(blob);
              // Let the browser use the filename from Content-Disposition header
              a.download = '';
              a.click();
              URL.revokeObjectURL(a.href);
              card.style.borderColor = 'var(--color-success)';
              setTimeout(function() { card.style.borderColor = ''; }, 1500);
              setLoading(false);
              card.classList.remove('downloading');
            });
          })
          .catch(function() {
            showError('Download failed. Try another format.');
            setLoading(false);
            card.classList.remove('downloading');
          });
      });

      return card;
    }

    var renderedSomething = false;
    if (groups.combined.length > 0) {
      formatGrid.appendChild(renderGroupheader('Video + Audio'));
      groups.combined.forEach(function(f) { formatGrid.appendChild(renderFormatCard(f)); });
      renderedSomething = true;
    }
    if (groups.videoOnly.length > 0) {
      if (renderedSomething) {
        var sep = document.createElement('div');
        sep.className = 'format-group-sep';
        formatGrid.appendChild(sep);
      }
      formatGrid.appendChild(renderGroupheader('Video Only'));
      groups.videoOnly.forEach(function(f) { formatGrid.appendChild(renderFormatCard(f)); });
      renderedSomething = true;
    }
    if (groups.audioOnly.length > 0) {
      if (renderedSomething) {
        var sep = document.createElement('div');
        sep.className = 'format-group-sep';
        formatGrid.appendChild(sep);
      }
      formatGrid.appendChild(renderGroupheader('Audio Only'));
      groups.audioOnly.forEach(function(f) { formatGrid.appendChild(renderFormatCard(f)); });
    }
  }

  // On page load, if URL was pre-filled, kick off auto-fetch
  if (input.value && input.value.startsWith('http')) {
    // Small delay so the UI is ready
    setTimeout(fetchInfo, 300);
  }

  async function fetchInfo() {
    const url = input.value.trim();
    if (!url) return;

    hideError();
    setLoading(true);
    showProgress(true);
    setProgress(30, 'Fetching video info...');

    try {
      const key = document.getElementById('apiKey') && document.getElementById('apiKey').value;
      const headers = {};
      if (key) {
        headers['Authorization'] = 'Bearer ' + encodeURIComponent(key);
      }
      const resp = await fetch(API + '?action=info&url=' + encodeURIComponent(url), {
        headers,
        signal: AbortSignal.timeout(60000)
      });

      setProgress(80, 'Parsing...');

      if (!resp.ok) {
        const err = await resp.json().catch(() => ({ error: 'Unknown error' }));
        // Surface error_code for classified yt-dlp errors
        var msg = err.error || 'Something went wrong. Try again.';
        if (err.error_code) {
          var errorHints = {
            'GEOBLOCKED': 'This video is geo-restricted. Using a VPN like AhoyVPN may help: https://ahoyvpn.net',
            'PRIVATE_VIDEO': 'This video is private and cannot be downloaded.',
            'LOGIN_REQUIRED': 'This video requires login. Try downloading while signed in to the platform.',
            'UNSUPPORTED_SITE': 'This site is not supported. Check the list at yt-dlp.github.io/supported-sites.',
            'PLAYLIST_MISSING': 'The playlist was not found or is no longer available.',
            'COPYRIGHT_REMOVED': 'This content was removed due to a copyright claim.',
            'SOURCE_RATE_LIMITED': 'The source site is rate-limiting us. Please try again in a few minutes.',
            'FORBIDDEN_ORIGIN': 'Requests must come from ahoyripper.com or ahoyvpn.com.',
            'DAILY_LIMIT': 'Daily free limit reached. Get AhoyVPN for unlimited rips: https://ahoyvpn.net',
            'INVALID_URL': 'That URL isn\'t supported or could not be fetched. Check the link and try again.',
          };
          if (err.error_code && errorHints[err.error_code]) {
            msg = errorHints[err.error_code];
          } else if (resp.status === 504) {
            msg = 'The download timed out. Try a smaller format (audio or lower resolution).';
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
        fetchInfo();
      }
    }, 100);
  });
</script>

</body>
</html>
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
  <title>AhoyRipper - Free Media Ripper | YouTube, Twitter, TikTok & More</title>
  <meta name="description" content="Rip video and audio from YouTube, X/Twitter, SoundCloud, TikTok, Instagram, and more. Free, fast, no signup required.">
  <meta name="robots" content="noindex">
  <meta name="author" content="AhoyVPN">
  <meta name="theme-color" content="#0f0f0f">

  <!-- Security headers -->
  <meta http-equiv="X-Content-Type-Options" content="nosniff">
  <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
  <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">
  <meta http-equiv="Permissions-Policy" content="camera=(), microphone=(), geolocation=()">

  <!-- Canonical URL -->
  <link rel="canonical" href="https://ahoyripper.com">

  <!-- OG / Twitter -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="AhoyRipper - Free Media Ripper">
  <meta property="og:description" content="Download video and audio from YouTube, Twitter, TikTok, SoundCloud and more. No signup, no ads, no bullshit.">
  <meta property="og:site_name" content="AhoyRipper">
  <meta property="og:image" content="https://ahoyripper.com/og-image.png">
  <meta property="og:url" content="https://ahoyripper.com">

  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:site" content="@ahoyvpn">
  <meta name="twitter:title" content="AhoyRipper - Free Media Ripper">
  <meta name="twitter:description" content="Download video and audio from YouTube, Twitter, TikTok, SoundCloud and more. No signup, no ads.">
  <meta name="twitter:image" content="https://ahoyripper.com/og-image.png">

  <!-- SVG Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%233b82f6'/><text x='50%25' y='55%25' dominant-baseline='middle' text-anchor='middle' font-family='Arial,sans-serif' font-weight='bold' font-size='20' fill='white'>R</text></svg>">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/src/style.css">
</head>
<body>

<!-- Navigation -->
<nav class="ahoy-nav">
  <a href="/" class="ahoy-nav-logo">
    <img src="/AhoyMonthly_transparent.png" alt="AhoyRipper logo" onerror="this.style.display='none'">
    <span>AhoyRipper</span>
  </a>
  <div class="ahoy-nav-links">
    <a href="https://ahoyvpn.com">AhoyVPN</a>
  </div>
</nav>

<!-- Main -->
<main>
  <section class="hero">
    <h1>Rip any video, <span>anywhere.</span></h1>
    <p>Paste a link from YouTube, Twitter, SoundCloud, TikTok, Instagram, or one of dozens of other platforms. We'll rip it. Free, no signup, no tracked.</p>

    <!-- Error message -->
    <div class="rip-error" id="errorBox"></div>

    <!-- Input form -->
    <div class="rip-box">
      <form class="rip-form" id="ripForm">
        <input
          type="url"
          class="rip-input"
          id="urlInput"
          placeholder="Paste a link here..."
          value="<?= htmlspecialchars($default_url) ?>"
          autocomplete="off"
          autocorrect="off"
          autocapitalize="off"
          spellcheck="false"
          required
        >
        <button type="submit" class="rip-btn" id="submitBtn">Rip It</button>
      </form>
      <p class="rip-hint">
        Supported: YouTube, X/Twitter, SoundCloud, TikTok, Instagram, Facebook, Vimeo & many more
      </p>
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
      <p class="results-title">
        <span class="check">&#10003;</span>
        <span id="resultsTitle">Ready to download</span>
      </p>
      <div class="format-grid" id="formatGrid"></div>
      <div style="margin-top:1.5rem; text-align:center;">
        <button class="rip-again" id="ripAgain">Rip another</button>
      </div>
    </div>
  </section>

  <!-- Supported sites -->
  <div class="sites-bar" style="padding: 0 2rem; max-width:720px;margin:0 auto;">
    <span class="site-badge">YouTube</span>
    <span class="site-badge">X/Twitter</span>
    <span class="site-badge">SoundCloud</span>
    <span class="site-badge">TikTok</span>
    <span class="site-badge">Instagram</span>
    <span class="site-badge">Facebook</span>
    <span class="site-badge">Vimeo</span>
    <span class="site-badge">+1800 more</span>
  </div>

  <!-- Features -->
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
        <p>YouTube, Twitter/X, SoundCloud, TikTok, Instagram, Facebook, Vimeo and 1800+ other sites.</p>
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
      <p><strong>Stay private online</strong> &mdash; support this tool and get a fast, zero-log VPN from <strong>AhoyVPN</strong>.</p>
      <a href="https://ahoyvpn.com" class="vpn-btn">Get AhoyVPN &mdash; $5.99/mo</a>
    </div>
  </section>
</main>

<footer>
  <p>For personal use only. Respect copyright. &nbsp;|&nbsp; <a href="https://ahoyvpn.com">AhoyVPN</a> &nbsp;|&nbsp; <a href="mailto:dmca@ahoyvpn.com">DMCA</a></p>
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
    // Show a smooth indeterminate animation when we have an active step
    const bar = progressBar;
    if (text) {
      progressText.textContent = text;
      // Animate bar with a pulsing indeterminate look
      bar.style.width = '80%';
      bar.style.transition = 'width 1.5s ease';
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

  function formatBytes(mb) {
    if (mb >= 1000) return (mb / 1000).toFixed(1) + ' GB';
    if (mb >= 1) return mb + ' MB';
    return '<1 MB';
  }

  function buildDownloadUrl(url, formatId, label) {
    // For combined video+audio formats, we need to merge streams
    // yt-dlp handles this with the format string
    return `${API}?action=download&url=${encodeURIComponent(url)}&format=${encodeURIComponent(formatId)}`;
  }

  function renderFormats(url, data) {
    formatGrid.innerHTML = '';
    resultsTitle.textContent = data.title || 'Select a format to download';

    const formats = data.formats || [];

    formats.forEach(function(f) {
      const card = document.createElement('a');
      card.className = 'format-card';

      // Label: use the pretty label
      const label = f.label || (f.ext ? f.ext.toUpperCase() : 'Format');

      // Type badge
      let badge = '';
      if (f.vcodec !== 'none' && f.acodec !== 'none') {
        badge = '<span class="format-ext">Video</span>';
      } else if (f.vcodec !== 'none') {
        badge = '<span class="format-ext" style="color:#a855f7">Video Only</span>';
      } else {
        badge = '<span class="format-ext" style="color:#22c55e">Audio</span>';
      }

      const size = f.filesize_mb > 0 ? formatBytes(f.filesize_mb) : '~size';

      card.href = buildDownloadUrl(url, f.id, label);
      card.download = '';
      card.target = '_blank';
      card.rel = 'noopener noreferrer';

      card.innerHTML = `
        ${badge}
        <div class="format-label">${label}</div>
        <div class="format-meta">${f.ext ? f.ext.toUpperCase() : ''} ${f.tbr ? f.tbr + 'kbps' : ''}</div>
        <div class="format-size">${size}</div>
      `;

      card.addEventListener('click', function(e) {
        e.preventDefault();
        // Navigate to download URL (triggers browser download)
        window.location.href = card.href;
        // Visual feedback that click was registered
        card.style.borderColor = 'var(--color-success)';
        setTimeout(() => { card.style.borderColor = ''; }, 1500);
      });

      formatGrid.appendChild(card);
    });
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
      const resp = await fetch(API + '?action=info&url=' + encodeURIComponent(url), {
        signal: AbortSignal.timeout(60000)
      });

      setProgress(80, 'Parsing...');

      if (!resp.ok) {
        const err = await resp.json().catch(() => ({ error: 'Unknown error' }));
        showError(err.error || 'Something went wrong. Try again.');
        return;
      }

      const data = await resp.json();
      setProgress(100, 'Done.');

      showProgress(false);
      showResults(true);
      renderFormats(url, data);

    } catch (e) {
      if (e.name === 'AbortError') {
        showError('Request timed out. The video might be too large or unavailable. Try again.');
      } else {
        showError('Could not connect to the ripper. Please try again in a moment.');
      }
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
})();
</script>

</body>
</html>
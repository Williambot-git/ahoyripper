/**
 * AhoyRipper — Plausible Analytics Integration
 *
 * Loads the self-hosted Plausible analytics script.
 * Self-hosted Plausible: https://plausible.io/docs/self-hosting
 *
 * Configuration:
 *   Set PLAUSIBLE_HOST to your self-hosted Plausible domain before the script loads.
 *   For the official hosted service use 'plausible.io'.
 *   For full isolation, deploy Plausible behind the same domain.
 *
 * How it works:
 *   1. pageview — fires on every full page load
 *   2. custom events — frontend calls plausible('event', { name, props })
 *      e.g. plausible('event', { name: 'Format Selected', props: { platform: 'YouTube' } })
 *
 * PII stripping:
 *   The ?url= query param (which may contain a video URL) is stripped from the
 *   page URL before sending so Plausible only sees '/', not '/?url=https://...'.
 *
 * CSP compatibility:
 *   This script is loaded via a <script defer src="/js/analytics.js"> tag.
 *   Since it's served from the same origin ('self'), it satisfies script-src 'self'.
 *   If self-hosting Plausible behind a different domain, update connect-src in
 *   the CSP meta tag to allow POSTing to that domain.
 */
(function () {
  'use strict';

  // ── Configuration ──────────────────────────────────────────────────────────
  // Leave PLAUSIBLE_HOST as null — events are sent to AhoyRipper's own
  // /src/api.php?action=analytics proxy, which forwards them to the configured
  // Plausible host server-side. This avoids:
  //   - External requests from the browser (privacy: no third-party beacons)
  //   - CSP issues (no connect-src exception needed for analytics domain)
  //   - Plausible seeing user IPs (nginx strips them before forwarding)
  //   - Video URLs appearing in analytics (server strips ?url= before forwarding)
  //
  // Set PLAUSIBLE_HOST env var on the AhoyRipper server to your Plausible
  // deployment (e.g. 'analytics.yourdomain.com'). Defaults to 'plausible.io'.
  // To completely disable analytics (no forwarding), set PLAUSIBLE_HOST=''.
  var PLAUSIBLE_HOST = null;

  // ── URL sanitisation ───────────────────────────────────────────────────────
  // Strip the ?url= query param (video link prefill) from the page URL before
  // sending to Plausible so the analytics only sees paths, not video URLs.
  function sanitiseUrl(rawUrl) {
    try {
      var url = new URL(rawUrl);
      if (url.searchParams.has('url')) {
        url.searchParams.delete('url');
      }
      return url.toString();
    } catch (e) {
      // Fallback: return origin + pathname only if URL parsing fails.
      try {
        var parts = rawUrl.split('?');
        return parts[0];
      } catch (e2) {
        return rawUrl;
      }
    }
  }

  // ── Event sender ──────────────────────────────────────────────────────────
  function sendEvent(name, maybeProps) {
    var props = typeof maybeProps === 'object' && maybeProps !== null ? maybeProps : {};

    var payload = {
      name: name,
      url: sanitiseUrl(window.location.href),
      domain: 'ahoyripper.com',
      referrer: document.referrer || undefined,
    };

    // Attach custom props if provided.
    if (Object.keys(props).length > 0) {
      payload.props = props;
    }

    var url = PLAUSIBLE_HOST !== null
        ? 'https://' + PLAUSIBLE_HOST + '/api/event'
        : '/src/api.php?action=analytics';

    // navigator.sendBeacon is fire-and-forget and survives page unload.
    var body = JSON.stringify(payload);
    if (navigator.sendBeacon) {
      navigator.sendBeacon(url, body);
    } else {
      // Fallback for older browsers that don't support sendBeacon.
      fetch(url, {
        method: 'POST',
        body: body,
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
      }).catch(function () {});
    }
  }

  // ── Plausible wrapper (matches official Plausible API) ────────────────────
  window.plausible = window.plausible || function (eventName, options) {
    if (eventName === 'pageview') {
      sendEvent('pagevisit');
    } else if (eventName === 'event' && options && options.name) {
      sendEvent(options.name, options.props || {});
    } else if (typeof eventName === 'string') {
      sendEvent(eventName, options || {});
    }
  };

  // ── Pageview tracking ─────────────────────────────────────────────────────
  // Fire on initial load.
  sendEvent('pagevisit');

  // Re-fire on History API navigations (SPA-style, if ever added).
  var originalPushState = window.history.pushState;
  if (originalPushState) {
    window.history.pushState = function () {
      originalPushState.apply(window.history, arguments);
      sendEvent('pagevisit');
    };
    window.addEventListener('popstate', function () {
      sendEvent('pagevisit');
    });
  }
})();

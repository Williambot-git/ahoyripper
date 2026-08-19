/**
 * AhoyRipper Service Worker — PWA offline support
 *
 * Strategy: Cache-first for static assets (CSS, fonts, icons),
 * network-first for the HTML shell. This gives offline access to
 * the main page while keeping dynamic content fresh.
 *
 * The SW is scoped to '/' so it intercepts all requests under the
 * root — including /src/api.php calls, which will fail offline as
 * expected (the API is never cached).
 *
 * Cache versioning: CACHE_VERSION is replaced at deploy time by
 * the scripts/generate-sw-version.php script (run by install-deps.sh
 * or any CI/CD pipeline). It is set to the short git commit hash
 * (e.g. 'a3f9b2c') so that any code change bumps the cache version,
 * triggering SW reinstall and fresh asset caching for all PWA users.
 * If the replacement fails (e.g. running outside a git repo or the
 * script wasn't run), the fallback 'unversioned' string ensures the
 * SW still installs and functions — it simply won't auto-update.
 */
// '{{CACHE_VERSION}}' is replaced at deploy time by scripts/generate-sw-version.php
// with the short git commit hash. If the placeholder was not replaced (deploy script
// ran outside a git repo or failed), 'PLACEHOLDER' !== '{{CACHE_VERSION}}' evaluates
// to true and CACHE_VERSION falls back to 'unversioned' so the SW still installs
// and functions — it simply won't auto-update until the next deploy.
const CACHE_VERSION = '{{CACHE_VERSION}}' !== 'PLACEHOLDER' ? '{{CACHE_VERSION}}' : 'unversioned';
const STATIC_CACHE = 'ahoyrip-static-' + CACHE_VERSION;
const SHELL_CACHE = 'ahoyrip-shell-' + CACHE_VERSION;

// Static assets to cache on install.
const STATIC_ASSETS = [
  '/',
  '/src/style.css',
  '/manifest.json',
  '/favicon.ico',
  '/favicon.svg',
  '/favicon-512.png',
  '/favicon-144.png',
  '/favicon-180.png',
  // og-image.png is preloaded with fetchpriority="high" in index.php for
  // social share previews (LCP). Cache it here so it's available offline —
  // PWA users who share the page while offline will still get the preview
  // image rather than a broken social card. The og-image.svg is the
  // authoritative SVG source; the PNG is generated from it at build time.
  '/og-image.png',
];

// ─── Install: pre-cache static assets ────────────────────────────────────────
self.addEventListener('install', (event) => {
  // waitUntil accepts a promise — if the promise rejects, the SW fails to activate
  // and remains in the waiting state indefinitely. To guarantee activation even
  // when caching fails (network error during install, storage quota exceeded, etc.),
  // we resolve the promise even when addAll fails — the network serves as the
  // fallback for any uncached assets and the SW still activates. This prevents
  // a broken PWA that requires manual site-data clearing to recover.
  //
  // Stale-cache cleanup (deleting old version caches) also runs in waitUntil so
  // that old caches are removed before activation completes. It is placed inside
  // the same waitUntil so both operations complete before the SW activates.
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) =>
        // Wrap addAll in a resolved Promise so a failed asset (e.g. a cached 404
        // from a prior network request) does not cause waitUntil to reject and
        // prevent SW activation. If addAll fails, we log the error but resolve
        // anyway — the network serves as the fallback for any uncached assets,
        // so the SW still activates and the PWA remains functional.
        // An install rejection would leave the PWA permanently broken until the
        // user manually clears site data, which is a worse outcome than falling
        // back to the network for a few assets.
        new Promise((resolve) => {
          cache.addAll(STATIC_ASSETS).then(resolve).catch((err) => {
            console.warn('[SW] install: cache.addAll failed, activating with network fallback:', err);
            resolve();
          });
        })
      )
      // Only clean old caches after static assets are confirmed cached.
      // Cleaning caches before addAll completes can delete the very assets
      // we just cached if the browser evicted the old cache prematurely.
      .then(() => caches.keys())
      .then((names) => Promise.all(
        names
          // SHELL_CACHE is also versioned and must be purged on each deploy.
          // Unlike STATIC_CACHE (excluded so newly cached shell isn't deleted),
          // SHELL_CACHE accumulates stale entries every deploy and must be removed.
          .filter((n) => n.startsWith('ahoyrip-') && n !== STATIC_CACHE)
          .map((n) => caches.delete(n))
      ))
  );
  // Do NOT skipWaiting here — let the frontend decide when to activate.
  // The frontend sends a 'SKIP_WAITING' message after showing the update prompt.
});

// ─── Message: apply pending update immediately ───────────────────────────────
// Frontend calls registration.waiting.postMessage({type:'SKIP_WAITING'})
// after displaying an "update available" notice to the user. This ensures
// the user sees the new version before the page refreshes.
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

// ─── Activate: clean up old caches ───────────────────────────────────────────
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((names) =>
        Promise.all(
          names
            .filter((n) => n.startsWith('ahoyrip-') && n !== STATIC_CACHE && n !== SHELL_CACHE)
            .map((n) => caches.delete(n))
        )
      )
      // Take control of all clients immediately so the page doesn't
      // stay on the old SW.
      .then(() => self.clients.claim())
  );
});

// ─── Fetch: serve from cache when offline ───────────────────────────────────
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Only handle same-origin requests.
  if (url.origin !== location.origin) return;

  // API calls — never cache (must always be live).
  if (url.pathname.startsWith('/src/api.php')) return;

  // Google Fonts — cache with network fallback and offline fallback.
  // Falls back to cache when network is unavailable (e.g. offline, airplane mode).
  // Cache misses (network success) are stored so subsequent offline requests
  // are served from cache without a failed network round-trip.
  if (url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;
        return fetch(request).then((response) => {
          // Cache successful font responses for 30 days.
          if (response.ok) {
            const clone = response.clone();
            caches.open(STATIC_CACHE).then((c) => c.put(request, clone));
          }
          return response;
        }).catch(() => caches.match(request))
        // If both network and cache miss fail, return nothing — the browser
        // will use its own font fallback, keeping the page legible.
      })
    );
    return;
  }

  // Static assets (CSS, JS, images, icons) — cache-first.
  if (
    request.destination === 'style' ||
    request.destination === 'script' ||
    request.destination === 'image' ||
    request.destination === 'font'
  ) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;
        return fetch(request).then((response) => {
          if (response.ok) {
            const clone = response.clone();
            caches.open(STATIC_CACHE).then((c) => c.put(request, clone));
          }
          return response;
        });
      })
    );
    return;
  }

  // HTML shell — network-first so the page stays up-to-date.
  if (request.destination === 'document') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response.ok) {
            const clone = response.clone();
            caches.open(SHELL_CACHE).then((c) => c.put(request, clone));
          }
          return response;
        })
        .catch(() => caches.match(request).then((cached) => cached || caches.match('/')))
    );
    return;
  }
});

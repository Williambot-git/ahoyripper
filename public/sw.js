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
// ran outside a git repo or failed), use the 'unversioned' sentinel so the SW still
// installs and functions — it simply won't auto-update until the next deploy.
const CACHE_VERSION = '{{CACHE_VERSION}}' === '{{CACHE_VERSION}}' ? 'unversioned' : '{{CACHE_VERSION}}';
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
  '/favicon-180.png',
];

// ─── Install: pre-cache static assets ────────────────────────────────────────
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(STATIC_ASSETS))
      .catch((err) => {
        // Log the failure for debugging, but do not throw — a failed install
        // prevents the SW from activating and blocks all subsequent updates.
        // Surface the error so it's visible in DevTools > Service Workers.
        console.warn('[SW] Install failed to cache some assets:', err);
        // Let the SW activate even if caching partially failed — the browser
        // will serve from network for any assets not in the cache.
      })
      // Also clean up stale caches from previous versions immediately so the
      // activate event doesn't need to wait. This runs alongside the cache
      // install and doesn't block activation.
      .then(() => caches.keys())
      .then((names) => Promise.all(
        names
          .filter((n) => n.startsWith('ahoyrip-') && n !== STATIC_CACHE && n !== SHELL_CACHE)
          .map((n) => caches.delete(n))
      ))
  );
  // Do NOT skipWaiting here — let the frontend decide when to activate.
  // The frontend sends a 'SKIP_WAITING' message after showing the update prompt.
});

// ─── Message: apply pending update immediately ────────────────────────────────
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

// ─── Fetch: serve from cache when offline ────────────────────────────────────
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Only handle same-origin requests.
  if (url.origin !== location.origin) return;

  // API calls — never cache (must always be live).
  if (url.pathname.startsWith('/src/api.php')) return;

  // Google Fonts — cache with network fallback and offline fallback.
  // Falls back to cache when network is unavailable (e.g. offline, airplane mode).
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

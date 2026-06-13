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
 */

const CACHE_VERSION = 'v1';
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
      // Skip waiting so the new SW activates immediately (no need to wait
      // for all tabs to close before taking control).
      .then(() => self.skipWaiting())
  );
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

  // Google Fonts — cache with network fallback.
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
        });
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

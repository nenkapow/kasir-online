<?php
// sw.js.php — Service Worker dynamic versioning
// Outputs JavaScript. Keep this file in web root.
// -------------------------------------------------------------------
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$files = [
  '/index.html',
  '/report.html',
  '/manifest.json',
  '/assets/css/style.css',
  '/assets/js/app.js',
  '/assets/js/report.js',
];

// build version hash from file mtimes (fallback to time() if missing)
$mtimes = '';
foreach ($files as $f) {
  $path = __DIR__ . $f;
  $mtimes .= is_file($path) ? filemtime($path) : '';
}
$hash = substr(hash('sha256', $mtimes ?: (string)time()), 0, 10);
$CACHE = "kasir-cache-v{$hash}";

// core files to precache (add query to bust CDN caches gracefully)
$core = array_map(fn($u) => $u . '?v=' . $hash, $files);

// For offline fallback we’ll cache index.html
$core[] = '/'; // some servers serve / as index
?>
/* Auto-generated Service Worker (version: <?= $hash ?>) */

const CACHE_NAME = '<?= $CACHE ?>';
const CORE = <?= json_encode($core, JSON_UNESCAPED_SLASHES) ?>;

// Utility: check if request is navigation (HTML)
function isNavigation(req) {
  return req.mode === 'navigate' ||
         (req.headers.get('accept') || '').includes('text/html');
}

// Install: pre-cache app shell
self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(CORE)).catch(() => {})
  );
});

// Activate: clean old caches
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(
      names.filter(n => n.startsWith('kasir-cache-v') && n !== CACHE_NAME)
           .map(n => caches.delete(n))
    );
    await self.clients.claim();
  })());
});

// Fetch strategy:
// - API (/api/...) → network only (bypass cache)
// - HTML/navigation → network-first, fallback to cache (offline safe)
// - Assets (css/js/img/fonts) → stale-while-revalidate
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Only handle GET
  if (req.method !== 'GET') return;

  // Never cache API requests
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(fetch(req).catch(() => new Response('{"ok":false,"error":"offline"}', {
      status: 503, headers: { 'Content-Type': 'application/json' }
    })));
    return;
  }

  // Navigation/HTML: network-first
  if (isNavigation(req)) {
    event.respondWith((async () => {
      try {
        const fresh = await fetch(req, { cache: 'no-store' });
        const cache = await caches.open(CACHE_NAME);
        cache.put(req, fresh.clone());
        return fresh;
      } catch (err) {
        const cache = await caches.open(CACHE_NAME);
        const cached = await cache.match(req) || await cache.match('/index.html?v=<?= $hash ?>') || await cache.match('/');
        return cached || new Response('<h1>Offline</h1>', { headers: { 'Content-Type': 'text/html' }});
      }
    })());
    return;
  }

  // Assets: stale-while-revalidate
  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(req);
    const fetchPromise = fetch(req).then((resp) => {
      // only cache ok responses
      if (resp && resp.status === 200 && resp.type !== 'opaque') {
        cache.put(req, resp.clone());
      }
      return resp;
    }).catch(() => null);

    return cached || (await fetchPromise) || new Response(null, { status: 504 });
  })());
});

// Optional: allow pages to trigger skipWaiting
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

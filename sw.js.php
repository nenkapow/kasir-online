<?php
// sw.js.php — Service Worker dengan auto-version berdasarkan mtime file
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

$mtimes = '';
foreach ($files as $f) {
  $path = __DIR__ . $f;
  $mtimes .= is_file($path) ? filemtime($path) : '';
}
$hash = substr(hash('sha256', $mtimes ?: (string) time()), 0, 10);
$CACHE = "kasir-cache-v{$hash}";
$core = array_map(fn($u) => $u . '?v=' . $hash, $files);
$core[] = '/'; // index
?>
/* Auto-generated Service Worker (version: <?= $hash ?>) */

const CACHE_NAME = '<?= $CACHE ?>';
const CORE = <?= json_encode($core, JSON_UNESCAPED_SLASHES) ?>;

function isNavigation(req) {
  return req.mode === 'navigate' ||
         (req.headers.get('accept') || '').includes('text/html');
}

self.addEventListener('install', (event) => {
  // langsung aktifkan SW baru
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(CORE)).catch(() => {})
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    // buang cache lama
    const names = await caches.keys();
    await Promise.all(
      names
        .filter(n => n.startsWith('kasir-cache-v') && n !== CACHE_NAME)
        .map(n => caches.delete(n))
    );
    // kuasai semua tab
    await self.clients.claim();
  })());
});

// Strategi fetch:
// - /api/*  : network only (biar data selalu fresh)
// - HTML    : network-first, fallback cache (offline)
// - assets  : stale-while-revalidate
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  if (req.method !== 'GET') return;

  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(req).catch(() =>
        new Response('{"ok":false,"error":"offline"}', {
          status: 503, headers: { 'Content-Type': 'application/json' }
        })
      )
    );
    return;
  }

  if (isNavigation(req)) {
    event.respondWith((async () => {
      try {
        const fresh = await fetch(req, { cache: 'no-store' });
        const cache = await caches.open(CACHE_NAME);
        cache.put(req, fresh.clone());
        return fresh;
      } catch (err) {
        const cache = await caches.open(CACHE_NAME);
        const cached =
          (await cache.match(req)) ||
          (await cache.match('/index.html?v=<?= $hash ?>')) ||
          (await cache.match('/'));
        return cached || new Response('<h1>Offline</h1>', {
          headers: { 'Content-Type': 'text/html' }
        });
      }
    })());
    return;
  }

  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(req);

    const fetching = fetch(req).then((resp) => {
      if (resp && resp.status === 200 && resp.type !== 'opaque') {
        cache.put(req, resp.clone());
      }
      return resp;
    }).catch(() => null);

    return cached || (await fetching) || new Response(null, { status: 504 });
  })());
});

// opsional: terima pesan "SKIP_WAITING" dari halaman
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

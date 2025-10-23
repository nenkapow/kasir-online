<?php
// sw.js.php — Service Worker dengan auto-version berdasar mtime file2 inti
// Letakkan di web root. Halaman sudah mendaftarkan ini di /sw.js.php
header('Content-Type: application/javascript; charset=utf-8');
// Pastikan file SW tidak di-cache oleh browser/edge/CDN
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$files = [
  '/index.html',
  '/purchases.html',
  '/report.html',
  '/manifest.json',
  '/assets/css/style.css',
  '/assets/js/app.js',
  '/assets/js/report.js',
];

$mtimes = '';
foreach ($files as $f) {
  $path = __DIR__ . $f;
  if (is_file($path)) $mtimes .= filemtime($path);
}
$hash  = substr(hash('sha256', $mtimes ?: (string)time()), 0, 10);
$CACHE = "kasir-cache-v{$hash}";

// tambahkan query ?v=hash supaya cache beda tiap versi
$core = array_map(fn($u) => $u . '?v=' . $hash, $files);
// fallback root (/) untuk SPA-ish navigation
$core[] = '/';
?>
/* Auto-generated Service Worker (version: <?= $hash ?>) */

const CACHE_NAME = '<?= $CACHE ?>';
const CORE = <?= json_encode($core, JSON_UNESCAPED_SLASHES) ?>;

// Deteksi request HTML (navigasi)
function isHTML(req) {
  return req.mode === 'navigate' ||
         (req.headers.get('accept') || '').includes('text/html');
}

// ===== Install: pre-cache core, langsung aktif =====
self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(CORE)).catch(() => {})
  );
});

// ===== Activate: hapus cache lama, klaim kontrol tab =====
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(
      names
        .filter(n => n.startsWith('kasir-cache-v') && n !== CACHE_NAME)
        .map(n => caches.delete(n))
    );
    await self.clients.claim();
  })());
});

// ===== Fetch strategy =====
// - /api/*     : network-only (agar data selalu terbaru)
// - HTML       : network-first (fallback cache → halaman offline)
// - Assets     : stale-while-revalidate (cepat & tetap update di belakang)
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  if (req.method !== 'GET') return;

  // API → Network only + offline JSON
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(req).catch(() =>
        new Response('{"ok":false,"error":"offline"}', {
          status: 503,
          headers: { 'Content-Type': 'application/json' }
        })
      )
    );
    return;
  }

  // HTML → network-first
  if (isHTML(req)) {
    event.respondWith((async () => {
      try {
        const fresh = await fetch(req, { cache: 'no-store' });
        const cache = await caches.open(CACHE_NAME);
        cache.put(req, fresh.clone());
        return fresh;
      } catch (err) {
        const cache = await caches.open(CACHE_NAME);
        // coba exact, lalu fallback ke index versi cache
        const cached =
          (await cache.match(req)) ||
          (await cache.match('/index.html?v=<?= $hash ?>')) ||
          (await cache.match('/'));
        return cached || new Response(
          '<!doctype html><meta charset="utf-8"><title>Offline</title><h1>Anda offline</h1><p>Halaman tidak ada di cache.</p>',
          { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        );
      }
    })());
    return;
  }

  // Assets → stale-while-revalidate
  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(req);

    const fetching = fetch(req).then((resp) => {
      // simpan hasil network yang OK (hindari opaque/cors error)
      if (resp && resp.status === 200 && resp.type !== 'opaque') {
        cache.put(req, resp.clone());
      }
      return resp;
    }).catch(() => null);

    // kembalikan cache dulu jika ada, sambil update di belakang
    return cached || (await fetching) || new Response(null, { status: 504 });
  })());
});

// Dengar pesan SKIP_WAITING dari halaman
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

<?php
// sw.js.php — Service Worker dengan auto-version dari mtime file
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Daftar file inti aplikasi (same-origin). Tambah/kurangi jika perlu.
$files = [
  '/index.html',
  '/report.html',
  '/purchases.html',
  '/manifest.json',
  '/assets/css/style.css',
  '/assets/js/app.js',
  '/assets/js/report.js',
  '/assets/js/purchases.js',
];

$mtimes = '';
foreach ($files as $f) {
  $path = __DIR__ . $f;
  $mtimes .= is_file($path) ? filemtime($path) : '';
}
$hash  = substr(hash('sha256', $mtimes ?: (string) time()), 0, 10);
$CACHE = "kasir-cache-v{$hash}";
$core  = array_map(fn($u) => $u . '?v=' . $hash, $files);
$core[] = '/'; // root / index
?>
/* Auto-generated Service Worker (version: <?= $hash ?>) */

const CACHE_NAME = '<?= $CACHE ?>';
const CORE = <?= json_encode($core, JSON_UNESCAPED_SLASHES) ?>;

// Bantu deteksi request navigasi (HTML)
function isNavigation(req) {
  return req.mode === 'navigate' ||
         (req.headers.get('accept') || '').includes('text/html');
}

self.addEventListener('install', (event) => {
  // SW baru langsung aktif
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(CORE)).catch(()=>{})
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    // Aktifkan navigation preload (lebih cepat di first hit)
    if ('navigationPreload' in self.registration) {
      try { await self.registration.navigationPreload.enable(); } catch {}
    }

    // Bersihkan cache lama
    const names = await caches.keys();
    await Promise.all(
      names
        .filter(n => n.startsWith('kasir-cache-v') && n !== CACHE_NAME)
        .map(n => caches.delete(n))
    );

    // Kuasai semua client
    await self.clients.claim();
  })());
});

// Strategy:
// - /api/*  : network-only (data selalu fresh, ada fallback JSON offline)
// - HTML    : network-first, fallback cache (offline) + navigationPreload
// - Assets  : stale-while-revalidate (cache duluan, update di belakang)
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  if (req.method !== 'GET') return;

  // 1) API: network only
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

  // 2) HTML: network-first (+ navigationPreload jika ada)
  if (isNavigation(req)) {
    event.respondWith((async () => {
      try {
        // pakai preload jika tersedia
        const preload = await event.preloadResponse;
        const fresh   = preload || await fetch(req, { cache: 'no-store' });
        const cache   = await caches.open(CACHE_NAME);
        cache.put(req, fresh.clone());
        return fresh;
      } catch {
        const cache = await caches.open(CACHE_NAME);
        // fallback: halaman yang sering dipakai
        return (await cache.match(req))
            || (await cache.match('/index.html?v=<?= $hash ?>'))
            || (await cache.match('/purchases.html?v=<?= $hash ?>'))
            || (await cache.match('/'))
            || new Response(
                 '<!doctype html><meta charset="utf-8"><title>Offline</title><h1>Offline</h1><p>Silakan cek koneksi internet.</p>',
                 { headers:{'Content-Type':'text/html; charset=utf-8'} }
               );
      }
    })());
    return;
  }

  // 3) Assets: stale-while-revalidate
  event.respondWith((async () => {
    const cache   = await caches.open(CACHE_NAME);
    const cached  = await cache.match(req);
    const fetching = fetch(req).then(resp => {
      // cache hanya response OK & bukan opaque (biar hemat quota)
      if (resp && resp.status === 200 && resp.type !== 'opaque') {
        cache.put(req, resp.clone());
      }
      return resp;
    }).catch(() => null);

    return cached || (await fetching) || new Response(null, { status: 504 });
  })());
});

// Opsional: izinkan halaman minta SW skipWaiting
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

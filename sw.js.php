<?php
// sw.js.php — Service Worker dengan auto-update
header('Content-Type: application/javascript; charset=utf-8');

// Versi cache = timestamp deploy (tiap render file ini berubah)
$VER = date('YmdHis');

// Daftar aset inti yang perlu di-cache untuk offline
$assets = [
  '/',                // landing (index.html)
  '/index.html',
  '/report.html',
  '/assets/css/style.css',
  '/assets/js/app.js',
  '/assets/js/report.js',
  '/manifest.json',
];

// Tambahkan query version ke semua aset biar pasti fresh tiap deploy
$ASSETS_WITH_VER = array_map(
  fn($p) => $p.(str_contains($p, '?') ? '' : ('?v='.$VER)),
  $assets
);
?>
// ====== Service Worker ======
const VERSION = 'v<?= $VER ?>';
const CORE_ASSETS = <?= json_encode($ASSETS_WITH_VER, JSON_UNESCAPED_SLASHES) ?>;

// Install: cache aset inti
self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(VERSION)
      .then((c) => c.addAll(CORE_ASSETS))
      .then(() => self.skipWaiting())
  );
});

// Activate: hapus cache lama
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter(k => k !== VERSION).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// Fetch: network-first untuk HTML, stale-while-revalidate untuk static
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Jangan ganggu endpoint API
  if (url.pathname.startsWith('/api/')) return;

  // HTML: network-first + fallback cache
  if (req.headers.get('accept')?.includes('text/html')) {
    e.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(VERSION).then((c) => c.put(req, copy));
          return res;
        })
        .catch(() => caches.match(req))
    );
    return;
  }

  // Static: stale-while-revalidate
  e.respondWith(
    caches.match(req).then((cached) => {
      const fetched = fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(VERSION).then((c) => c.put(req, copy));
          return res;
        })
        .catch(() => cached);
      return cached || fetched;
    })
  );
});

// Opsional: bisa dipanggil untuk ambil alih SW lebih cepat
self.addEventListener('message', (e) => {
  if (e.data === 'skipWaiting') self.skipWaiting();
});

<?php
// sw.js.php — Service Worker dengan versi otomatis
header('Content-Type: application/javascript; charset=utf-8');
// Jangan cache SW agar browser selalu cek versi terbaru
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Versi otomatis (timestamp deploy). Bisa juga ganti ke filemtime dari berkas penting.
$VERSION = gmdate('YmdHis');

// Daftar file inti yang perlu ada offline (VERSI ditanam biar cache busting otomatis)
$FILES = [
  '/',                    // kalau kamu pakai PHP server, ini akan kirim index.html
  '/index.html',
  '/report.html',
  '/assets/css/style.css',
  '/assets/js/app.js',
  '/assets/js/report.js',
  '/manifest.json',
];

// Tambahkan query ?v=VERSION agar berubah tiap deploy
$PRECACHE = array_map(fn($f) => $f . '?v=' . $VERSION, $FILES);

// Serialisasi untuk dipakai di JS di bawah
echo "const SW_VERSION = '$VERSION';\n";
echo "const PRECACHE_URLS = " . json_encode($PRECACHE, JSON_UNESCAPED_SLASHES) . ";\n";
?>

// ===== Service Worker code (JavaScript) =====
const CACHE_NAME = 'kasir-cache-' + SW_VERSION;

// Precache saat install
self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS))
  );
});

// Klaim kontrol segera
self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      // Hapus cache lama
      const keys = await caches.keys();
      await Promise.all(keys.map(k => (k !== CACHE_NAME ? caches.delete(k) : null)));
      await self.clients.claim();
      // Beritahu semua tab: versi baru aktif
      const clients = await self.clients.matchAll({ includeUncontrolled: true });
      clients.forEach(c => c.postMessage({ type: 'SW_ACTIVATED', version: SW_VERSION }));
    })()
  );
});

// Strategi fetch:
// - HTML: network-first (biar cepat dapat versi baru), fallback ke cache.
// - Assets (CSS/JS/IMG): cache-first, lalu revalidate di belakang.
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Lewatkan non-GET
  if (req.method !== 'GET') return;

  // HTML/documents => network-first
  if (req.headers.get('accept')?.includes('text/html')) {
    event.respondWith(
      (async () => {
        try {
          const fresh = await fetch(req, { cache: 'no-store' });
          // update cache
          const cache = await caches.open(CACHE_NAME);
          cache.put(req, fresh.clone());
          return fresh;
        } catch (e) {
          const cached = await caches.match(req);
          return cached || new Response('Offline', { status: 503 });
        }
      })()
    );
    return;
  }

  // Lainnya (CSS/JS/IMG) => cache-first + revalidate
  event.respondWith(
    (async () => {
      const cached = await caches.match(req);
      const fetchPromise = fetch(req).then((res) => {
        const resClone = res.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(req, resClone));
        return res;
      }).catch(() => null);
      return cached || fetchPromise || new Response('Offline', { status: 503 });
    })()
  );
});

KASIR ONLINE (PHP + MySQL) — dibuat 2025-10-17

Cara pakai (shared hosting cPanel):
1) Buat database MySQL baru (misal: kasir_db), user & passwordnya.
2) Import file api/db.sql ke database tersebut (via phpMyAdmin).
3) Edit api/config.php: isi DB_HOST, DB_NAME, DB_USER, DB_PASS sesuai hosting kamu.
   (Opsional) ganti APP_PIN di environment atau langsung di config.php (default '1234').
4) Upload semua file/folder ini ke public_html (atau subfolder).
5) Buka index.html di HP: tampil mobile-friendly. Masukkan PIN saat pertama kali pakai.
6) Tambah data produk lewat database (sementara). (Atau bisa tambahkan endpoint CRUD di products.php via POST — sudah ada).

Fitur:
- Cari & tambah ke keranjang, simpan transaksi.
- Stok otomatis berkurang saat transaksi.
- Laporan harian & produk terlaris (range tanggal), export CSV.
- PWA (bisa Add to Home Screen), ada cache offline untuk tampilan, namun transaksi butuh internet.
- Satu user via PIN sederhana di header (X-APP-PIN).

Catatan:
- Untuk keamanan, jangan share PIN. Untuk 1 user di warung kecil ini cukup.
- Jika trafik naik / multi-user, pindah ke VPS & tambah auth session.

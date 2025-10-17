<?php
require_once __DIR__.'/config.php';
$pdo = db();
$sql = file_get_contents(__DIR__.'/db.sql');
if ($sql === false) { die('Tidak menemukan db.sql'); }

// pecah per ';' + bersihkan baris kosong/komentar sederhana
$stmts = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql)));
try {
  $pdo->beginTransaction();
  foreach ($stmts as $s) {
    if ($s === '' || str_starts_with($s, '--')) continue;
    $pdo->exec($s);
  }
  $pdo->commit();
  echo "OK: Struktur tabel berhasil dibuat. Sekarang HAPUS file api/install.php demi keamanan.";
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo "Gagal: ".$e->getMessage();
}

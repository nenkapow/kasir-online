<?php
require __DIR__ . '/_init.php';
require_auth();

$id = $_POST['id'] ?? '';

if ($id === '') json(['ok'=>false,'error'=>'ID kosong'], 400);

try {
  $pdo = db();
  $st = $pdo->prepare("DELETE FROM products WHERE id=:id");
  $st->execute([':id'=>$id]);
  json(['ok'=>true]);
} catch (Throwable $e) {
  json(['ok'=>false,'error'=>$e->getMessage()], 500);
}

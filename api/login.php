<?php
require_once __DIR__ . '/_init.php';

try {
  // “silent login” – set session flag biar endpoint lain anggap sudah authed
  $_SESSION['authed'] = true;
  json(['ok' => true]);
} catch (Throwable $e) {
  json(['ok' => false, 'error' => $e->getMessage()], 500);
}

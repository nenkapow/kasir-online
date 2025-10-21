<?php
declare(strict_types=1);

// Selalu balas JSON & non-cache
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ===== KONFIG DATABASE =====
function db(): PDO {
  static $pdo;
  if ($pdo) return $pdo;

  $host = getenv('DB_HOST') ?: '127.0.0.1';
  $port = getenv('DB_PORT') ?: '3306';
  $name = getenv('DB_NAME') ?: 'railway';
  $user = getenv('DB_USER') ?: 'root';
  $pass = getenv('DB_PASS') ?: '';

  $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
  $opt = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ];
  $pdo = new PDO($dsn, $user, $pass, $opt);
  return $pdo;
}

// ===== UTIL JSON =====
function json($data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

// ===== AUTH =====
// APP_REQUIRE_PIN=0  => bebas (tanpa PIN)
// APP_REQUIRE_PIN=1  => butuh header X-APP-PIN atau ?pin=
function is_authed(): bool {
  if (!empty($_SESSION['authed'])) return true;

  $require = getenv('APP_REQUIRE_PIN') ?: ($_ENV['APP_REQUIRE_PIN'] ?? '0');
  $require = (int)$require;

  if ($require === 0) return true;

  $pinSupplied = $_SERVER['HTTP_X_APP_PIN'] ?? ($_GET['pin'] ?? null);
  $realPin     = getenv('APP_PIN') ?: ($_ENV['APP_PIN'] ?? '1234');

  return $pinSupplied !== null && hash_equals((string)$realPin, (string)$pinSupplied);
}

function require_auth(): void {
  if (!is_authed()) json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

// Backward compatibility untuk kode lama yang memanggil check_pin()
function check_pin(): bool { return is_authed(); }

<?php
// … koneksi $pdo kamu …
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_exception_handler(function($e){
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  exit;
});

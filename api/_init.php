<?php
declare(strict_types=1);

/**
 * _init.php
 * - Bootstrap untuk semua endpoint API (JSON only).
 * - Koneksi PDO ke MySQL (Railway compatible).
 * - Helper json(), db(), auth ringan, dan global $pdo.
 */

/* === HTTP headers umum untuk API JSON === */
if (!headers_sent()) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
}

/* === Session (untuk flag auth sederhana) === */
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/* === Helper baca env dengan beberapa kandidat nama === */
function env_any(array $keys, ?string $default = null): ?string {
  foreach ($keys as $k) {
    $v = getenv($k);
    if ($v !== false && $v !== '') return $v;
    if (isset($_ENV[$k]) && $_ENV[$k] !== '') return $_ENV[$k];
  }
  return $default;
}

/* === Koneksi PDO (singleton) === */
function db(): PDO {
  static $pdo;
  if ($pdo instanceof PDO) return $pdo;

  // Railway MySQL env (paling umum):
  // MYSQLHOST, MYSQLPORT, MYSQLDATABASE, MYSQLUSER, MYSQLPASSWORD
  $host = env_any(['MYSQLHOST','DB_HOST','RAILWAY_PRIVATE_DOMAIN'], '127.0.0.1');
  $port = env_any(['MYSQLPORT','DB_PORT'], '3306');
  $name = env_any(['MYSQLDATABASE','DB_NAME'], 'railway');
  $user = env_any(['MYSQLUSER','DB_USER'], 'root');
  $pass = env_any(['MYSQLPASSWORD','DB_PASS','MYSQL_ROOT_PASSWORD'], '');

  $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

  $opt = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode=''",
  ];

  $pdo = new PDO($dsn, $user, $pass, $opt);
  return $pdo;
}

/* === JSON responder === */
function json(array $data, int $code = 200): void {
  if (!headers_sent()) http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

/* === Auth sederhana via PIN (opsional) ===
 * APP_REQUIRE_PIN=0 → bebas
 * APP_REQUIRE_PIN=1 → butuh header X-APP-PIN atau ?pin=
 */
function is_authed(): bool {
  if (!empty($_SESSION['authed'])) return true;

  $require = (int) (env_any(['APP_REQUIRE_PIN'], '0') ?? '0');
  if ($require === 0) return true;

  $supplied = $_SERVER['HTTP_X_APP_PIN'] ?? ($_GET['pin'] ?? null);
  $realPin  = env_any(['APP_PIN'], '1234');

  return $supplied !== null && hash_equals((string)$realPin, (string)$supplied);
}

function require_auth(): void {
  if (!is_authed()) json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

/* Backward compatibility untuk kode lama */
function check_pin(): bool { return is_authed(); }

/* === Global PDO siap pakai di endpoint === */
$pdo = db();

/* === Error handler: balas JSON rapi ketimbang HTML notice === */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function(Throwable $e) {
  json(['ok' => false, 'error' => $e->getMessage()], 500);
});

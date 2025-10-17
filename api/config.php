<?php
// api/config.php
// Minimal config + helpers untuk kasir-online
// Pastikan environment variables di Railway: DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT
// Atau MYSQL_URL bisa dipakai jika tersedia.

if (session_status() === PHP_SESSION_NONE) session_start();

// helper response error
function fail($msg = 'error', $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// parse MYSQL_URL if present (Railway sometimes provides)
function get_db_dsn_and_creds() {
    $mysql_url = getenv('MYSQL_URL') ?: getenv('DATABASE_URL');
    if ($mysql_url) {
        // format: mysql://user:pass@host:port/dbname
        $u = parse_url($mysql_url);
        $host = $u['host'] ?? '127.0.0.1';
        $port = $u['port'] ?? 3306;
        $user = $u['user'] ?? 'root';
        $pass = $u['pass'] ?? '';
        $db = ltrim($u['path'] ?? '/railway', '/');
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        return [$dsn, $user, $pass];
    }

    // fallback to individual env vars
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: 3306;
    $db   = getenv('DB_NAME') ?: 'railway';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

    return [$dsn, $user, $pass];
}

function db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    list($dsn, $user, $pass) = get_db_dsn_and_creds();
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        // jangan bocorkan cred di produksi
        fail('Database connection error: '.$e->getMessage(), 500);
    }
}

/**
 * require_auth()
 * - jika session login ada -> izinkan
 * - else, jika APP_REQUIRE_PIN = 0 -> izinkan
 * - else periksa APP_PIN via header X-APP-PIN atau ?pin=
 */
function require_auth() {
    // session-based auth
    if (!empty($_SESSION['auth'])) return;

    // if APP_REQUIRE_PIN is explicitly 0/false -> allow (no pin)
    $require = getenv('APP_REQUIRE_PIN');
    if ($require === '0' || strtolower((string)$require) === 'false') {
        return;
    }

    // otherwise check PIN (legacy)
    $expected = trim((string)(getenv('APP_PIN') ?: '1234'));
    $hdr = $_SERVER['HTTP_X_APP_PIN'] ?? '';
    $got = trim((string)($hdr !== '' ? $hdr : ($_GET['pin'] ?? '')));

    if ($got !== $expected) {
        fail('Unauthorized: wrong PIN', 401);
    }
}

// CORS / security headers (adjust origin if perlu)
header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-APP-PIN');

// handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

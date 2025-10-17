<?php
// === Simple config for shared hosting / Railway ===
$mysqlUrl = getenv('MYSQL_URL');
if ($mysqlUrl) {
    // mysql://user:pass@host:port/dbname
    $u = parse_url($mysqlUrl);
    $DB_HOST = $u['host'] ?? 'localhost';
    $DB_PORT = $u['port'] ?? 3306;
    $DB_USER = $u['user'] ?? 'root';
    $DB_PASS = $u['pass'] ?? '';
    $DB_NAME = isset($u['path']) ? ltrim($u['path'], '/') : 'railway';
} else {
    // fallback ke DB_* manual
    $DB_HOST = getenv('DB_HOST') ?: 'localhost';
    $DB_PORT = getenv('DB_PORT') ?: 3306;
    $DB_NAME = getenv('DB_NAME') ?: 'kasir_db';
    $DB_USER = getenv('DB_USER') ?: 'kasir_user';
    $DB_PASS = getenv('DB_PASS') ?: 'password';
}

header('Content-Type: application/json; charset=utf-8');

function db() {
    static $pdo = null;
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;
    if ($pdo === null) {
        $dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    }
    return $pdo;
}

function json_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function ok($data = []) { http_response_code(200); echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }
function fail($msg, $code=400) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

// VERY SIMPLE pin auth for 1 user
function check_pin() {
    // ambil PIN expected dari ENV
    $expected = trim((string)(getenv('APP_PIN') ?: '1234'));

    // ambil PIN yang dikirim client: prioritas header, lalu query ?pin=
    $hdr = $_SERVER['HTTP_X_APP_PIN'] ?? '';
    // beberapa server menaruh header custom di key lain—antisipasi:
    if ($hdr === '' && isset($_SERVER['HTTP_X_APP_PIN'])) { $hdr = $_SERVER['HTTP_X_APP_PIN']; }

    $got = trim((string)($hdr !== '' ? $hdr : ($_GET['pin'] ?? '')));

    if ($got !== $expected) {
        fail('Unauthorized: wrong PIN', 401);
    }
}

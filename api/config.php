<?php
// === Simple config for shared hosting ===
// Update these 4 lines to match your hosting database credentials
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'kasir_db';
$DB_USER = getenv('DB_USER') ?: 'kasir_user';
$DB_PASS = getenv('DB_PASS') ?: 'password';

header('Content-Type: application/json; charset=utf-8');

function db() {
    static $pdo = null;
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    if ($pdo === null) {
        $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
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

function ok($data = []) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function fail($msg, $code=400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// VERY SIMPLE pin auth for 1 user
function check_pin() {
    $expected = getenv('APP_PIN') ?: '1234'; // change this in hosting env var or here
    $pin = $_SERVER['HTTP_X_APP_PIN'] ?? '';
    if ($pin !== $expected) {
        fail('Unauthorized: wrong PIN', 401);
    }
}
?>

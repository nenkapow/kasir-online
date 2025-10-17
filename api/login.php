<?php

require __DIR__ . '/_init.php';

// Tandai sesi sudah login agar is_authed() langsung true
$_SESSION['authed'] = true;
json(['ok' => true]);

// api/login.php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

// If auto-login flag set, create session immediately (same-origin)
$auto = getenv('APP_AUTOLOGIN');
if ($auto === '1' || strtolower((string)$auto) === 'true') {
    $_SESSION['auth'] = true;
    $_SESSION['user'] = getenv('ADMIN_USER') ?: 'admin';
    echo json_encode(['ok' => true, 'message' => 'Auto-login enabled (session started)']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true) ?? [];
$user = trim($data['username'] ?? '');
$pass = trim($data['password'] ?? '');

$ADMIN_USER = trim((string)(getenv('ADMIN_USER') ?: 'admin'));
$ADMIN_PASS = trim((string)(getenv('ADMIN_PASS') ?: 'secret'));

if ($user === $ADMIN_USER && $pass === $ADMIN_PASS) {
    $_SESSION['auth'] = true;
    $_SESSION['user'] = $ADMIN_USER;
    echo json_encode(['ok' => true, 'message' => 'Logged in']);
    exit;
} else {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid credentials']);
    exit;
}

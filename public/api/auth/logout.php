<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
require_csrf();
$user = current_user();
if ($user) {
    log_activity(db($config), (int) $user['id'], 'Logout', 'user', (int) $user['id']);
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();
json_response(['ok' => true]);

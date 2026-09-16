<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';
start_secure_session($config);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
require_csrf();
$user = current_user();
if ($user) {
    try {
        log_activity(db($config), (int) $user['id'], 'Logout', 'user', (int) $user['id']);
    } catch (Throwable $e) {
        error_log('Apex logout activity log failed: ' . $e->getMessage());
    }
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();
json_response(['ok' => true]);

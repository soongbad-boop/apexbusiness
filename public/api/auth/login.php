<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
require_csrf();
$payload = request_json();
$email = filter_var($payload['email'] ?? '', FILTER_VALIDATE_EMAIL);
$password = is_string($payload['password'] ?? null) ? $payload['password'] : '';

if (!$email || strlen($password) < 8) {
    json_response(['error' => 'Enter a valid email and password.'], 422);
}

$statement = db($config)->prepare('SELECT id, role_id, email, password_hash, display_name, status FROM users WHERE email = ? LIMIT 1');
$statement->execute([$email]);
$user = $statement->fetch();

if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
    if ($user) {
        log_activity(db($config), (int) $user['id'], 'Login failed', 'user', (int) $user['id'], 'failure');
    }
    json_response(['error' => 'Invalid credentials.'], 401);
}

session_regenerate_id(true);
$_SESSION['user'] = ['id' => (int) $user['id'], 'role_id' => (int) $user['role_id'], 'email' => $user['email'], 'display_name' => $user['display_name']];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$update = db($config)->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
$update->execute([$user['id']]);
log_activity(db($config), (int) $user['id'], 'Login', 'user', (int) $user['id']);
json_response(['user' => $_SESSION['user'], 'csrf_token' => $_SESSION['csrf_token']]);

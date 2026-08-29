<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
require_csrf();

// --- Rate Limiting: max 5 login attempts per IP per 15-minute window ---
$ipKey = 'login_attempts_' . preg_replace('/[^a-zA-Z0-9:]/', '_', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$windowSeconds = 900; // 15 minutes
$maxAttempts   = 5;
$now = time();
// Purge expired entry
if (isset($_SESSION[$ipKey]['expires']) && $now > $_SESSION[$ipKey]['expires']) {
    unset($_SESSION[$ipKey]);
}
if (!isset($_SESSION[$ipKey])) {
    $_SESSION[$ipKey] = ['count' => 0, 'expires' => $now + $windowSeconds];
}
if ($_SESSION[$ipKey]['count'] >= $maxAttempts) {
    $retryAfter = max(1, $_SESSION[$ipKey]['expires'] - $now);
    header('Retry-After: ' . $retryAfter);
    json_response(['error' => 'Too many login attempts. Please wait 15 minutes before trying again.'], 429);
}
$_SESSION[$ipKey]['count']++;
// --- End Rate Limiting ---

$payload  = request_json();
$email    = filter_var($payload['email'] ?? '', FILTER_VALIDATE_EMAIL);
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
// Clear the rate-limit counter on successful authentication
unset($_SESSION[$ipKey]);
$_SESSION['user'] = ['id' => (int) $user['id'], 'role_id' => (int) $user['role_id'], 'email' => $user['email'], 'display_name' => $user['display_name']];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$update = db($config)->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
$update->execute([$user['id']]);
log_activity(db($config), (int) $user['id'], 'Login', 'user', (int) $user['id']);
json_response(['user' => $_SESSION['user'], 'csrf_token' => $_SESSION['csrf_token']]);

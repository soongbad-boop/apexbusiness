<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
require_csrf();

$payload = request_json();
$emailRaw = strtolower(normalized_text($payload['email'] ?? '', 190));
$email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
$password = is_string($payload['password'] ?? null) ? $payload['password'] : '';

if (!$email || strlen($password) < 8 || strlen($password) > 4096) {
    json_response(['error' => 'Enter a valid email and password.'], 422);
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$ipBucket = 'login:ip:' . $ip;
$accountBucket = 'login:account:' . hash('sha256', $emailRaw);
$retryIp = consume_rate_limit($ipBucket, 8, 900);
$retryAccount = consume_rate_limit($accountBucket, 12, 900);
$retryAfter = max($retryIp, $retryAccount);
if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    json_response(['error' => 'Too many sign-in attempts. Please try again later.'], 429);
}

$pdo = db($config);
$statement = $pdo->prepare('SELECT id, role_id, email, password_hash, display_name, status FROM users WHERE email = ? LIMIT 1');
$statement->execute([$email]);
$user = $statement->fetch();

$valid = $user && $user['status'] === 'active' && password_verify($password, (string) $user['password_hash']);
if (!$valid) {
    // Equalize timing for unknown accounts so account enumeration is harder.
    if (!$user) {
        password_verify($password, '$2y$10$7EqJtq98hPqEX7fNZaFWoO5e1vGjE1h2i6TsJe8sM3YBqG9FRWg2K');
    } else {
        log_activity($pdo, (int) $user['id'], 'Login failed', 'user', (int) $user['id'], 'failure');
    }
    json_response(['error' => 'Invalid credentials.'], 401);
}

if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $rehash = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $rehash->execute([$newHash, $user['id']]);
}

session_regenerate_id(true);
clear_rate_limit($ipBucket);
clear_rate_limit($accountBucket);
$_SESSION['user'] = [
    'id' => (int) $user['id'],
    'role_id' => (int) $user['role_id'],
    'email' => (string) $user['email'],
    'display_name' => (string) $user['display_name'],
];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['last_activity'] = time();

$update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
$update->execute([$user['id']]);
log_activity($pdo, (int) $user['id'], 'Login', 'user', (int) $user['id']);
json_response(['user' => $_SESSION['user'], 'csrf_token' => $_SESSION['csrf_token']]);

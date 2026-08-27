<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/config.php';

if (!empty($config['app_debug'])) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
}

function db(array $config): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['database']['host'], $config['database']['port'], $config['database']['name']);
    $pdo = new PDO($dsn, $config['database']['user'], $config['database']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function start_secure_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name($config['session']['name']);
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $config['session']['secure'],
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!is_string($provided) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $provided)) {
        json_response(['error' => 'Invalid CSRF token.'], 419);
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['error' => 'Authentication required.'], 401);
    }
    return $user;
}

function require_permission(PDO $pdo, string $permission): array
{
    $user = require_login();
    $query = $pdo->prepare('SELECT 1 FROM role_permissions rp INNER JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? AND p.name = ? LIMIT 1');
    $query->execute([$user['role_id'], $permission]);
    if (!$query->fetchColumn()) {
        json_response(['error' => 'Permission denied.'], 403);
    }
    return $user;
}

function log_activity(PDO $pdo, ?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, string $status = 'success', array $metadata = []): void
{
    $query = $pdo->prepare('INSERT INTO activity_logs (user_id, action, entity_type, entity_id, status, metadata, ip_address) VALUES (?, ?, ?, ?, ?, ?, INET6_ATON(?))');
    $query->execute([$userId, $action, $entityType, $entityId, $status, json_encode($metadata), $_SERVER['REMOTE_ADDR'] ?? null]);
}

start_secure_session($config);

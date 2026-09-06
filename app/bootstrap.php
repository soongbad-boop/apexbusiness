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

if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function db(array $config): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['database']['host'],
        $config['database']['port'],
        $config['database']['name']
    );
    $pdo = new PDO($dsn, $config['database']['user'], $config['database']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
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
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 1_000_000) {
        json_response(['error' => 'Request body is too large.'], 413);
    }
    $data = json_decode($raw, true);
    if ($raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
        json_response(['error' => 'Invalid JSON payload.'], 400);
    }
    return is_array($data) ? $data : [];
}

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function start_secure_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetime = max(900, (int) ($config['session']['lifetime'] ?? 7200));
    $secure = !empty($config['session']['secure']) || is_https_request();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    session_name((string) $config['session']['name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();

    $now = time();
    if (!empty($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $lifetime) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = $now;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!is_string($provided) || empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $provided)) {
        json_response(['error' => 'Invalid CSRF token. Please refresh and try again.'], 419);
    }
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['error' => 'Authentication required.'], 401);
    }
    return $user;
}

function user_has_permission(PDO $pdo, array $user, string $permission): bool
{
    $query = $pdo->prepare('SELECT 1 FROM role_permissions rp INNER JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? AND p.name = ? LIMIT 1');
    $query->execute([(int) $user['role_id'], $permission]);
    return (bool) $query->fetchColumn();
}

function require_permission(PDO $pdo, string $permission): array
{
    $user = require_login();
    if (!user_has_permission($pdo, $user, $permission)) {
        json_response(['error' => 'Permission denied.'], 403);
    }
    return $user;
}

function log_activity(PDO $pdo, ?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, string $status = 'success', array $metadata = []): void
{
    if (!in_array($status, ['success', 'failure'], true)) {
        $status = 'failure';
    }
    $query = $pdo->prepare('INSERT INTO activity_logs (user_id, action, entity_type, entity_id, status, metadata, ip_address) VALUES (?, ?, ?, ?, ?, ?, INET6_ATON(?))');
    $query->execute([
        $userId,
        mb_strimwidth($action, 0, 190, ''),
        $entityType !== null ? mb_strimwidth($entityType, 0, 80, '') : null,
        $entityId,
        $status,
        json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/**
 * Cross-session file-backed rate limiter suitable for shared-hosting deployments.
 * Returns 0 when allowed, otherwise seconds until the bucket resets.
 */
function consume_rate_limit(string $bucket, int $maxAttempts, int $windowSeconds): int
{
    $directory = dirname(__DIR__) . '/storage/rate_limits';
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        return 0; // Fail open if storage is unavailable; auth still has password/CSRF controls.
    }

    $path = $directory . '/' . hash('sha256', $bucket) . '.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return 0;
    }

    $now = time();
    try {
        if (!flock($handle, LOCK_EX)) {
            return 0;
        }
        rewind($handle);
        $raw = stream_get_contents($handle) ?: '';
        $state = json_decode($raw, true);
        if (!is_array($state) || (int) ($state['reset'] ?? 0) <= $now) {
            $state = ['count' => 0, 'reset' => $now + $windowSeconds];
        }

        if ((int) $state['count'] >= $maxAttempts) {
            return max(1, (int) $state['reset'] - $now);
        }

        $state['count'] = (int) $state['count'] + 1;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state));
        fflush($handle);
        return 0;
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function clear_rate_limit(string $bucket): void
{
    $path = dirname(__DIR__) . '/storage/rate_limits/' . hash('sha256', $bucket) . '.json';
    if (is_file($path)) {
        @unlink($path);
    }
}

function normalized_text(mixed $value, int $maxLength = 255): string
{
    $value = trim((string) $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $maxLength);
}

function valid_http_url_or_path(mixed $value, int $maxLength = 500): ?string
{
    $value = normalized_text($value, $maxLength);
    if ($value === '') {
        return null;
    }
    if (str_starts_with($value, '/')) {
        return $value;
    }
    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return null;
    }
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $value : null;
}

/**
 * Allow-list sanitizer for CMS rich text. Removes scripts, inline event handlers,
 * dangerous URL schemes, embedded objects/forms and style attributes.
 */
function sanitize_rich_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $allowedTags = [
        'p', 'br', 'h2', 'h3', 'h4', 'h5', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i',
        'blockquote', 'a', 'hr', 'code', 'pre', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'figure', 'figcaption', 'img'
    ];
    $dropCompletely = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'option', 'meta', 'link', 'base'];

    if (!class_exists('DOMDocument')) {
        return nl2br(e(strip_tags($html)));
    }

    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $wrapperId = 'apex-content-root-' . bin2hex(random_bytes(4));
    $doc->loadHTML('<?xml encoding="utf-8" ?><div id="' . $wrapperId . '">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $doc->getElementById($wrapperId);
    if (!$root) {
        return '';
    }

    $walk = function (DOMNode $parent) use (&$walk, $allowedTags, $dropCompletely): void {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, $dropCompletely, true)) {
                    $parent->removeChild($node);
                    $node = $next;
                    continue;
                }
                if (!in_array($tag, $allowedTags, true)) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                    $node = $next;
                    continue;
                }

                $allowedAttrs = match ($tag) {
                    'a' => ['href', 'title', 'target', 'rel'],
                    'img' => ['src', 'alt', 'title', 'loading', 'width', 'height'],
                    'th', 'td' => ['colspan', 'rowspan'],
                    default => [],
                };

                foreach (iterator_to_array($node->attributes ?? []) as $attr) {
                    $name = strtolower($attr->nodeName);
                    if (!in_array($name, $allowedAttrs, true) || str_starts_with($name, 'on') || $name === 'style') {
                        $node->removeAttribute($name);
                    }
                }

                if ($tag === 'a' && $node->hasAttribute('href')) {
                    $href = trim($node->getAttribute('href'));
                    $safe = str_starts_with($href, '/') || str_starts_with($href, '#') || preg_match('#^(https?://|mailto:|tel:)#i', $href);
                    if (!$safe) {
                        $node->removeAttribute('href');
                    }
                    if (strtolower($node->getAttribute('target')) === '_blank') {
                        $node->setAttribute('rel', 'noopener noreferrer');
                    }
                }

                if ($tag === 'img' && $node->hasAttribute('src')) {
                    $src = trim($node->getAttribute('src'));
                    $safe = str_starts_with($src, '/') || preg_match('#^https?://#i', $src);
                    if (!$safe) {
                        $node->removeAttribute('src');
                    }
                    $node->setAttribute('loading', 'lazy');
                }

                $walk($node);
            }
            $node = $next;
        }
    };
    $walk($root);

    $output = '';
    foreach ($root->childNodes as $child) {
        $output .= $doc->saveHTML($child);
    }
    return trim($output);
}

start_secure_session($config);

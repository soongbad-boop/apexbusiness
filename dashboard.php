<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
start_secure_session($config);

$user = current_user();
if (!$user) {
    header('Location: /login', true, 302);
    exit;
}

try {
    $pdo = db($config);
    if (!user_has_permission($pdo, $user, 'dashboard.view')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        echo 'Access denied.';
        exit;
    }
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    echo 'The CMS is temporarily unavailable.';
    exit;
}

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow, noarchive');
readfile(__DIR__ . '/dashboard.html');

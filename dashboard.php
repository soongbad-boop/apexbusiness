<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
start_secure_session($config);

if (!current_user()) {
    header('Location: login.php', true, 302);
    exit;
}

try {
    require_permission(db($config), 'dashboard.view');
} catch (Throwable $e) {
    header('Location: login.php', true, 302);
    exit;
}

header('Cache-Control: no-store, private');
readfile(__DIR__ . '/dashboard.html');
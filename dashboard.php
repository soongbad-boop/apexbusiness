<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (!current_user()) {
    header('Location: login.php', true, 302);
    exit;
}

require_permission(db($config), 'dashboard.view');
header('Cache-Control: no-store, private');
readfile(__DIR__ . '/dashboard.html');
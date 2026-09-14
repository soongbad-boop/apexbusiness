<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 2) . '/config/config.php';
$basePath = rtrim((string) parse_url((string) ($config['app_url'] ?? ''), PHP_URL_PATH), '/');

header('Location: ' . ($basePath !== '' ? $basePath : '') . '/login.php', true, 302);
exit;

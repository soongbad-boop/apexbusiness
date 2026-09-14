<?php
declare(strict_types=1);

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = rtrim(str_replace('\\', '/', dirname(dirname(dirname($scriptName)))), '/.');

header('Location: ' . ($basePath !== '' ? $basePath : '') . '/login.php', true, 302);
exit;

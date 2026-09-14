<?php
declare(strict_types=1);

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$legacyPath = '/Apexco_Professional_With_Email_OTP_2FA/Apexco_Professional_Final/login.php';
$basePath = str_ends_with($scriptName, $legacyPath)
    ? substr($scriptName, 0, -strlen($legacyPath))
    : rtrim(str_replace('\\', '/', dirname(dirname($scriptName))), '/');

header('Location: ' . ($basePath !== '' ? $basePath : '') . '/login.php', true, 302);
exit;

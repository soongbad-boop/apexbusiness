<?php
declare(strict_types=1);

// Run once from a protected server shell after configuring DB_* environment variables.
if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$config = require dirname(__DIR__) . '/config/config.php';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['database']['host'], $config['database']['port'], $config['database']['name']);
$pdo = new PDO($dsn, $config['database']['user'], $config['database']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$email = trim((string) readline('Admin email: '));
$name = trim((string) readline('Display name: '));
$password = trim((string) readline('Password (12+ characters): '));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || $name === '') {
    exit("Invalid input.\n");
}
$role = $pdo->query("SELECT id FROM roles WHERE name = 'Super Admin' LIMIT 1")->fetchColumn();
$query = $pdo->prepare('INSERT INTO users (role_id, email, password_hash, display_name) VALUES (?, ?, ?, ?)');
$query->execute([$role, $email, password_hash($password, PASSWORD_DEFAULT), $name]);
echo "Administrator created. Remove this script from the server.\n";

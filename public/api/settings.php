<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = db($config);
// All settings operations (read and write) require authentication.
$user = require_permission($pdo, 'settings.manage');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query('SELECT setting_key, setting_value, value_type FROM settings ORDER BY setting_key')->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['value_type'] === 'json' ? json_decode($row['setting_value'], true) : $row['setting_value'];
    }
    json_response($settings);
}

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
require_csrf();
$user = require_permission($pdo, 'settings.manage');
$payload = request_json();
$allowedTypes = ['string', 'integer', 'boolean', 'json'];
$statement = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, value_type, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_by = VALUES(updated_by)');
foreach ($payload as $key => $value) {
    if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_.-]{1,189}$/', $key)) {
        json_response(['error' => 'Invalid setting key.'], 422);
    }
    $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : (is_array($value) ? 'json' : 'string'));
    if (!in_array($type, $allowedTypes, true)) {
        json_response(['error' => 'Invalid setting type.'], 422);
    }
    $serialized = $type === 'json' ? json_encode($value) : (string) $value;
    $statement->execute([$key, $serialized, $type, $user['id']]);
}
log_activity($pdo, (int) $user['id'], 'Settings updated', 'settings');
json_response(['ok' => true]);

json_response(['error' => 'Unsupported request.'], 400);

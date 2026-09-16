<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
start_secure_session($config);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $pdo = db($config);
    } catch (Throwable $e) {
        error_log('Apex settings database unavailable: ' . $e->getMessage());
        json_response(['site_settings' => ['settings' => [], 'cms' => []], 'degraded' => true]);
    }
    $rows = $pdo->query("SELECT setting_key, setting_value, value_type FROM settings WHERE setting_key = 'site_settings' OR setting_key IN ('site_name', 'tagline', 'favicon', 'support_email', 'support_phone', 'footer_copy')")->fetchAll();
    $settings = ['settings' => [], 'cms' => []];
    foreach ($rows as $row) {
        $value = $row['value_type'] === 'json' ? json_decode($row['setting_value'], true) : $row['setting_value'];
        if ($row['setting_key'] === 'site_settings' && is_array($value)) {
            $settings = array_replace_recursive($settings, $value);
        }
        $legacyMap = [
            'site_name' => ['settings', 'name'],
            'tagline' => ['settings', 'tagline'],
            'favicon' => ['settings', 'favicon'],
            'support_email' => ['settings', 'email'],
            'support_phone' => ['settings', 'phone'],
            'footer_copy' => ['settings', 'footer'],
        ];
        if (isset($legacyMap[$row['setting_key']])) {
            [$group, $key] = $legacyMap[$row['setting_key']];
            $settings[$group][$key] ??= $value;
        }
    }
    json_response(['site_settings' => $settings]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
require_csrf();
try {
    $pdo = db($config);
} catch (Throwable $e) {
    error_log('Apex settings database unavailable: ' . $e->getMessage());
    json_response(['error' => 'The CMS database is temporarily unavailable.'], 503);
}
$user = require_permission($pdo, 'settings.manage');
$payload = request_json();
$payload = ['site_settings' => $payload['site_settings'] ?? null];
if (!is_array($payload['site_settings'])) {
    json_response(['error' => 'Site settings payload is required.'], 422);
}
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

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = db($config);
$user = require_permission($pdo, 'media.manage');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
require_csrf();
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_response(['error' => 'A valid image upload is required.'], 422);
}
$file = $_FILES['file'];
if ((int) $file['size'] > $config['uploads']['max_bytes']) {
    json_response(['error' => 'The image exceeds the configured size limit.'], 422);
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
    json_response(['error' => 'Only valid JPEG, PNG, WebP, or GIF images are allowed.'], 422);
}
$directory = rtrim($config['uploads']['directory'], DIRECTORY_SEPARATOR);
if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
    json_response(['error' => 'Upload storage is unavailable.'], 500);
}
$storageName = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
$destination = $directory . DIRECTORY_SEPARATOR . $storageName;
if (!move_uploaded_file($file['tmp_name'], $destination)) {
    json_response(['error' => 'Upload could not be stored.'], 500);
}
$publicUrl = rtrim($config['uploads']['public_path'], '/') . '/' . $storageName;
$query = $pdo->prepare('INSERT INTO media (storage_name, original_name, public_url, alt_text, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
$query->execute([$storageName, basename((string) $file['name']), $publicUrl, trim((string) ($_POST['alt_text'] ?? '')), $mime, (int) $file['size'], $user['id']]);
$id = (int) $pdo->lastInsertId();
log_activity($pdo, (int) $user['id'], 'Media uploaded', 'media', $id);
json_response(['ok' => true, 'id' => $id, 'url' => $publicUrl], 201);

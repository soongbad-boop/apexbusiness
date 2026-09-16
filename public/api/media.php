<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
start_secure_session($config);

try {
    $pdo = db($config);
} catch (Throwable $e) {
    error_log('Apex media database unavailable: ' . $e->getMessage());
    json_response(['error' => 'The CMS database is temporarily unavailable.'], 503);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
require_csrf();
$user = require_permission($pdo, 'media.manage');

if (!isset($_FILES['file']) || !is_array($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_response(['error' => 'A valid image upload is required.'], 422);
}
$file = $_FILES['file'];
$size = (int) ($file['size'] ?? 0);
if ($size <= 0 || $size > (int) $config['uploads']['max_bytes']) {
    json_response(['error' => 'The image exceeds the configured size limit or is empty.'], 422);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$imageInfo = @getimagesize($file['tmp_name']);
if (!isset($allowed[$mime]) || $imageInfo === false) {
    json_response(['error' => 'Only valid JPEG, PNG, WebP, or GIF images are allowed.'], 422);
}
[$width, $height] = $imageInfo;
if ($width < 1 || $height < 1 || $width > 12000 || $height > 12000 || ($width * $height) > 40_000_000) {
    json_response(['error' => 'The image dimensions are not supported.'], 422);
}

$directory = rtrim((string) $config['uploads']['directory'], DIRECTORY_SEPARATOR);
$directoryReady = is_dir($directory);
if (!$directoryReady) {
    foreach ([0750, 0755] as $mode) {
        if ((@mkdir($directory, $mode, true) || is_dir($directory)) && is_dir($directory)) {
            @chmod($directory, $mode);
            $directoryReady = true;
            break;
        }
    }
}
if (!$directoryReady) {
    json_response(['error' => 'Upload storage is unavailable.'], 500);
}

$storageName = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
$destination = $directory . DIRECTORY_SEPARATOR . $storageName;
if (!move_uploaded_file($file['tmp_name'], $destination)) {
    json_response(['error' => 'Upload could not be stored.'], 500);
}
@chmod($destination, 0640);

$publicUrl = rtrim((string) $config['uploads']['public_path'], '/') . '/' . $storageName;
$originalName = mb_substr(basename((string) ($file['name'] ?? 'image')), 0, 255);
$altText = normalized_text($_POST['alt_text'] ?? '', 255);

try {
    $query = $pdo->prepare('INSERT INTO media (storage_name, original_name, public_url, alt_text, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $query->execute([$storageName, $originalName, $publicUrl, $altText ?: null, $mime, $size, (int) $user['id']]);
    $id = (int) $pdo->lastInsertId();
    log_activity($pdo, (int) $user['id'], 'Media uploaded', 'media', $id, 'success', ['mime' => $mime, 'bytes' => $size, 'width' => $width, 'height' => $height]);
    json_response(['ok' => true, 'id' => $id, 'url' => $publicUrl], 201);
} catch (Throwable $e) {
    @unlink($destination);
    json_response(['error' => 'Upload metadata could not be saved.'], 500);
}

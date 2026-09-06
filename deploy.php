<?php
declare(strict_types=1);

/**
 * Apex Business Consultancy — CLI-only deployment & migration runner.
 *
 * Run from cPanel Terminal / SSH:
 *   php deploy.php
 *
 * HTTP execution is deliberately disabled so deployment credentials and
 * migration actions cannot be triggered through a public URL.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

echo "========================================================\n";
echo "  APEX BUSINESS CONSULTANCY — DEPLOYMENT & SYNC RUNNER  \n";
echo "========================================================\n\n";

$configFile = __DIR__ . '/config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "[ERROR] config/config.php not found.\n");
    exit(1);
}
$config = require $configFile;

if (($config['app_env'] ?? 'production') === 'production' && empty($config['database']['password'])) {
    fwrite(STDERR, "[ERROR] Production database password is empty. Configure .env before deployment.\n");
    exit(1);
}

echo "[1/4] Configuration loaded.\n";
echo "  Environment: " . ($config['app_env'] ?? 'production') . "\n";
echo "  App URL: " . ($config['app_url'] ?? 'not configured') . "\n";

$storageDir = __DIR__ . '/storage';
$directories = [$storageDir, $storageDir . '/media', $storageDir . '/logs', $storageDir . '/rate_limits'];
echo "\n[2/4] Preparing storage...\n";
foreach ($directories as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        fwrite(STDERR, "[ERROR] Could not create {$dir}.\n");
        exit(1);
    }
    @chmod($dir, 0750);
    echo "  ✓ " . basename($dir) . "\n";
}

echo "\n[3/4] Checking database and running safe seed/migrations...\n";
try {
    $db = $config['database'];
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->query('SELECT 1');
    echo "  ✓ Connected to MySQL database [{$db['name']}].\n";

    $seedFile = __DIR__ . '/database/seed.php';
    if (is_file($seedFile)) {
        include $seedFile;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] Database migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n[4/4] Deployment checks complete.\n";
echo "========================================================\n";
echo "  DEPLOYMENT COMPLETED SUCCESSFULLY                     \n";
echo "========================================================\n";

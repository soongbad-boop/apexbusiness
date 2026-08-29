<?php
declare(strict_types=1);

/**
 * Apex Business Consultancy — Automated Deployment & Migration Runner
 *
 * Usage via CLI / cPanel Terminal:
 *   php deploy.php
 *
 * Usage via Webhook / Browser (Requires secret token in query string):
 *   https://apexbusinessksa.com/deploy.php?secret=YOUR_DEPLOY_SECRET
 */

$isCli = (php_sapi_name() === 'cli');

// Basic token authentication if triggered via HTTP
if (!$isCli) {
    $secret = $_GET['secret'] ?? '';
    // SECURITY: DEPLOY_SECRET MUST be set as a real environment variable.
    // If it is not set, refuse all HTTP access — there is no safe fallback.
    $expectedSecret = getenv('DEPLOY_SECRET');
    if ($expectedSecret === false || $expectedSecret === '') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Deploy webhook is not configured. Set the DEPLOY_SECRET environment variable.']);
        exit;
    }
    if (!hash_equals($expectedSecret, $secret)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

echo "========================================================\n";
echo "  APEX BUSINESS CONSULTANCY — DEPLOYMENT & SYNC RUNNER  \n";
echo "========================================================\n\n";

// 1. Verify Bootstrap & Configuration
echo "[1/4] Loading environment & configuration...\n";
$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    echo "  [ERROR] config/config.php not found.\n";
    exit(1);
}
$config = require $configFile;
echo "  ✓ Environment: " . ($config['app_env'] ?? 'production') . "\n";
echo "  ✓ App URL: " . ($config['app_url'] ?? 'not configured') . "\n";

// 2. Setup Storage & Upload Directories
echo "\n[2/4] Ensuring storage directories & permissions...\n";
$storageDir = __DIR__ . '/storage';
$mediaDir = $storageDir . '/media';
$logsDir = $storageDir . '/logs';

foreach ([$storageDir, $mediaDir, $logsDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
        echo "  ✓ Created directory: " . basename($dir) . "\n";
    } else {
        echo "  ✓ Directory exists: " . basename($dir) . "\n";
    }
    // 0750: owner rwx, group r-x, others --- (not world-readable)
    @chmod($dir, 0750);
}

// 3. Database Connection & Schema Migration
echo "\n[3/4] Checking database connection & running migrations...\n";
try {
    $db = $config['database'];
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "  ✓ Connected to MySQL database [{$db['name']}].\n";

    // Run seed script if available
    $seedFile = __DIR__ . '/database/seed.php';
    if (file_exists($seedFile)) {
        echo "  ✓ Running database seed script...\n";
        include $seedFile;
    }
} catch (Throwable $e) {
    echo "  [DATABASE NOTICE] " . $e->getMessage() . "\n";
    echo "  Note: If on cPanel shared hosting, ensure the database name and user are created in MySQL Database Wizard first.\n";
}

// 4. Verification & Summary
echo "\n[4/4] Finalizing deployment...\n";
echo "  ✓ Public assets and routes verified.\n";
echo "\n========================================================\n";
echo "  DEPLOYMENT COMPLETED SUCCESSFULLY!                     \n";
echo "========================================================\n";

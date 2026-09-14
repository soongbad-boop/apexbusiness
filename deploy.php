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

function ensure_directory(string $path): bool
{
    foreach ([0750, 0755] as $mode) {
        if ((is_dir($path) || @mkdir($path, $mode, true)) && is_dir($path)) {
            @chmod($path, $mode);
            return true;
        }
    }

    return false;
}

function schema_table_exists(PDO $pdo, string $table): bool
{
    $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $query->execute([$table]);
    return (int) $query->fetchColumn() > 0;
}

function schema_table_count(PDO $pdo): int
{
    $query = $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
    return (int) $query->fetchColumn();
}

function missing_required_tables(PDO $pdo): array
{
    $required = [
        'roles',
        'permissions',
        'role_permissions',
        'users',
        'categories',
        'media',
        'posts',
        'post_categories',
        'pages',
        'menus',
        'menu_items',
        'settings',
        'inquiries',
        'activity_logs',
    ];

    return array_values(array_filter($required, static fn (string $table): bool => !schema_table_exists($pdo, $table)));
}

function import_schema(PDO $pdo, string $schemaPath): void
{
    $sql = file_get_contents($schemaPath);
    if ($sql === false) {
        throw new RuntimeException('Unable to read database/mysql-schema.sql.');
    }
    if (preg_match('/\b(?:DELIMITER|CREATE\s+(?:TRIGGER|PROCEDURE|FUNCTION|EVENT))\b/i', $sql) === 1) {
        throw new RuntimeException('database/mysql-schema.sql must contain only simple DDL/DML statements when used by deploy.php.');
    }

    $sql = preg_replace('/^\s*CREATE DATABASE\b.*?;\s*/mi', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*USE\b.*?;\s*/mi', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*#.*$/m', '', $sql) ?? $sql;
    $statements = [];
    $statement = '';
    $quote = null;
    $escaped = false;
    $inBlockComment = false;

    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        if ($inBlockComment) {
            if ($char === '*' && ($sql[$i + 1] ?? '') === '/') {
                $i++;
                $inBlockComment = false;
            }
            continue;
        }

        if ($quote === null && $char === '/' && ($sql[$i + 1] ?? '') === '*') {
            if (($sql[$i + 2] ?? '') === '!') {
                throw new RuntimeException('database/mysql-schema.sql cannot contain executable MySQL comments when used by deploy.php.');
            }
            $i++;
            $inBlockComment = true;
            continue;
        }

        $statement .= $char;

        if ($escaped) {
            $escaped = false;
            continue;
        }

        if ($char === '\\') {
            $escaped = true;
            continue;
        }

        if ($quote !== null) {
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if (in_array($char, ["'", '"', '`'], true)) {
            $quote = $char;
            continue;
        }

        if ($char === ';') {
            $statements[] = $statement;
            $statement = '';
        }
    }
    if (trim($statement) !== '') {
        $statements[] = $statement;
    }

    foreach ($statements as $statement) {
        $statement = trim($statement);
        $statement = rtrim($statement, ';');
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
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
    if (!ensure_directory($dir)) {
        fwrite(STDERR, "[ERROR] Could not create {$dir}.\n");
        exit(1);
    }
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

    $schemaPath = __DIR__ . '/database/mysql-schema.sql';
    $tableCount = schema_table_count($pdo);
    if ($tableCount === 0) {
        if (!is_file($schemaPath)) {
            throw new RuntimeException('database/mysql-schema.sql not found.');
        }
        import_schema($pdo, $schemaPath);
        echo "  ✓ Imported base database schema.\n";
    } else {
        $missingTables = missing_required_tables($pdo);
        if ($missingTables !== []) {
            throw new RuntimeException('Database is partially initialized. Missing required tables: ' . implode(', ', $missingTables) . '. Import database/mysql-schema.sql or restore the missing tables before rerunning deploy.php.');
        }
    }

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

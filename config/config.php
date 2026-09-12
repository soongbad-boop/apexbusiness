<?php
declare(strict_types=1);

if (!function_exists('env_value')) {
    function env_value(string $key, ?string $default = null): ?string
    {
        static $envLoaded = false;
        if (!$envLoaded) {
            $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines !== false) {
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || str_starts_with($line, '#')) {
                            continue;
                        }
                        $parts = explode('=', $line, 2);
                        if (count($parts) === 2) {
                            $name = trim($parts[0]);
                            $val = trim($parts[1], " \t\n\r\0\x0B\"'");
                            if (!array_key_exists($name, $_ENV)) {
                                $_ENV[$name] = $val;
                                putenv("{$name}={$val}");
                            }
                        }
                    }
                }
            }
            $envLoaded = true;
        }

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return ($value === false || $value === null) ? $default : (string) $value;
    }
}

$appEnv = strtolower((string) env_value('APP_ENV', 'development'));
$appDebugRequested = env_value('APP_DEBUG', 'false') === 'true' || env_value('APP_DEBUG', '0') === '1';

return [
    'app_name' => env_value('APP_NAME', 'Apex Business Consultancy'),
    'app_env' => $appEnv,
    // Never expose PHP error details in production even if APP_DEBUG is accidentally enabled.
    'app_debug' => $appEnv !== 'production' && $appDebugRequested,
    'app_url' => rtrim((string) env_value('APP_URL', 'http://localhost/Apexco'), '/'),
    'database' => [
        'host' => env_value('DB_HOST', '127.0.0.1'),
        'port' => env_value('DB_PORT', '3306'),
        'name' => env_value('DB_NAME', 'apex_business'),
        'user' => env_value('DB_USER', 'root'),
        'password' => env_value('DB_PASSWORD', ''),
    ],
    'session' => [
        'name' => env_value('SESSION_NAME', 'apex_session'),
        'secure' => env_value('SESSION_SECURE', '0') === '1',
        'lifetime' => (int) env_value('SESSION_LIFETIME', '7200'),
    ],
    'uploads' => [
        'directory' => env_value('UPLOAD_DIRECTORY', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'media'),
        'public_path' => env_value('UPLOAD_PUBLIC_PATH', '/Apexco/storage/media'),
        'max_bytes' => (int) env_value('UPLOAD_MAX_BYTES', '5242880'),
    ],
    'mail' => [
        'driver' => env_value('MAIL_MAILER', 'smtp'),
        'host' => env_value('MAIL_HOST', 'smtp.mailtrap.io'),
        'port' => (int) env_value('MAIL_PORT', '2525'),
        'username' => env_value('MAIL_USERNAME', ''),
        'password' => env_value('MAIL_PASSWORD', ''),
        'encryption' => env_value('MAIL_ENCRYPTION', 'tls'),
        'from_address' => env_value('MAIL_FROM_ADDRESS', 'info@apexbusinessksa.com'),
        'from_name' => env_value('MAIL_FROM_NAME', 'Apex Business Consultancy'),
        'to_address' => env_value('MAIL_TO_ADDRESS', 'info@apexbusinessksa.com'),
    ],
];

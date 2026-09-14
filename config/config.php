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

if (!function_exists('detected_base_path')) {
    function detected_base_path(): string
    {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = trim(dirname($scriptName), '/');
        if ($basePath === '.' || $basePath === '/') {
            return '';
        }
        if ($basePath === 'public') {
            return '';
        } elseif (str_ends_with($basePath, '/public')) {
            $basePath = substr($basePath, 0, -7);
        }

        return $basePath !== '' ? '/' . $basePath : '';
    }
}

if (!function_exists('trusted_proxy_addresses')) {
    function trusted_proxy_addresses(): array
    {
        $raw = env_value('TRUSTED_PROXY_IPS', '') ?? '';
        if ($raw === '') {
            return [];
        }

        $trusted = [];
        foreach (explode(',', $raw) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $trusted[] = $candidate;
            }
        }

        return array_values(array_unique($trusted));
    }
}

if (!function_exists('trusted_forwarded_proto')) {
    function trusted_forwarded_proto(): bool
    {
        $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $remoteAddr !== '' && in_array($remoteAddr, trusted_proxy_addresses(), true);
    }
}

if (!function_exists('default_app_url')) {
    function default_app_url(): string
    {
        $scheme = 'http';
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            $scheme = 'https';
        } elseif (trusted_forwarded_proto() && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            $scheme = 'https';
        }
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return 'http://localhost';
        }

        return $scheme . '://' . $host . detected_base_path();
    }
}

if (!function_exists('default_upload_public_path')) {
    function default_upload_public_path(): string
    {
        return detected_base_path() . '/storage/media';
    }
}

if (!function_exists('upload_directory_path')) {
    function upload_directory_path(): string
    {
        $configured = env_value('UPLOAD_DIRECTORY');
        if ($configured === null || trim($configured) === '') {
            return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'media';
        }

        $configured = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($configured));
        $isAbsolute = DIRECTORY_SEPARATOR === '\\'
            ? preg_match('/^[A-Za-z]:\\\\/', $configured) === 1
            : str_starts_with($configured, DIRECTORY_SEPARATOR);

        if ($isAbsolute) {
            return $configured;
        }

        return dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($configured, DIRECTORY_SEPARATOR);
    }
}

$appEnv = strtolower((string) env_value('APP_ENV', 'development'));
$appDebugRequested = env_value('APP_DEBUG', 'false') === 'true' || env_value('APP_DEBUG', '0') === '1';
$defaultAppUrl = default_app_url();

return [
    'app_name' => env_value('APP_NAME', 'Apex Business Consultancy'),
    'app_env' => $appEnv,
    // Never expose PHP error details in production even if APP_DEBUG is accidentally enabled.
    'app_debug' => $appEnv !== 'production' && $appDebugRequested,
    'app_url' => rtrim((string) env_value('APP_URL', $defaultAppUrl), '/'),
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
        'directory' => upload_directory_path(),
        'public_path' => env_value('UPLOAD_PUBLIC_PATH', default_upload_public_path()),
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

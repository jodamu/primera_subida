<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$envFile = $root . '/.env';

if (is_file($envFile)) {
    Dotenv::createImmutable($root)->load();
}

$timezone = $_ENV['APP_TIMEZONE'] ?? 'America/Bogota';
date_default_timezone_set($timezone);

function app_config(string $key, ?string $default = null): ?string
{
    return $_ENV[$key] ?? $default;
}

function app_db(): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = app_config('DB_HOST');
    $port = app_config('DB_PORT', '3306');
    $name = app_config('DB_NAME');
    $user = app_config('DB_USER');
    $password = app_config('DB_PASSWORD');
    $charset = app_config('DB_CHARSET', 'utf8mb4');

    if (!$host || !$name || !$user || $password === null) {
        throw new RuntimeException('Falta configuración de base de datos en .env.');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);
    $connection = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}

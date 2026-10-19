<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Lagos');

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
session_name('iceblock_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function database(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('ICEBLOCK_DB_HOST') ?: '127.0.0.1';
    $port = getenv('ICEBLOCK_DB_PORT') ?: '3306';
    $name = getenv('ICEBLOCK_DB_NAME') ?: 'ice_block_advertisement_system';
    $user = getenv('ICEBLOCK_DB_USER') ?: 'root';
    $password = getenv('ICEBLOCK_DB_PASSWORD') ?: '';
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

    $connection = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec("SET time_zone = '+00:00'");

    return $connection;
}

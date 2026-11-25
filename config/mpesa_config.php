<?php

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
$envPath = __DIR__ . '/../.env';

if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    if (class_exists('Dotenv\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->safeLoad();
    }
} elseif (file_exists($envPath)) {
    // Lightweight native .env loader (no Composer required)
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

return [
    'environment'     => $_ENV['MPESA_ENV'] ?? 'sandbox',
    'consumer_key'    => $_ENV['MPESA_CONSUMER_KEY'] ?? '',
    'consumer_secret' => $_ENV['MPESA_CONSUMER_SECRET'] ?? '',
    'shortcode'       => $_ENV['MPESA_SHORTCODE'] ?? '',
    'passkey'         => $_ENV['MPESA_PASSKEY'] ?? '',
    'callback_url'    => $_ENV['MPESA_CALLBACK_URL'] ?? ''
];
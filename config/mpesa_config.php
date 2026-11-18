
<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

return [
    'environment' => $_ENV['MPESA_ENV'] ?? 'sandbox',
    'consumer_key' => $_ENV['MPESA_CONSUMER_KEY'] ?? '',
    'consumer_secret' => $_ENV['MPESA_CONSUMER_SECRET'] ?? '',
    'shortcode' => $_ENV['MPESA_SHORTCODE'] ?? '',
    'passkey' => $_ENV['MPESA_PASSKEY'] ?? '',
    'callback_url' => $_ENV['MPESA_CALLBACK_URL'] ?? ''
];

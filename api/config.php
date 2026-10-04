<?php
declare(strict_types=1);

$defaults = [
    'app_env' => getenv('APP_ENV') ?: 'development',
    'app_url' => rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/'),
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'atelier_noura',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ],
    'session' => ['name' => 'noura_session', 'secure' => filter_var(getenv('COOKIE_SECURE') ?: '0', FILTER_VALIDATE_BOOLEAN)],
    'payment' => [
        'driver' => getenv('PAYMENT_DRIVER') ?: 'sandbox',
        'merchant_id' => getenv('PAYMENT_MERCHANT_ID') ?: '',
        'request_url' => getenv('PAYMENT_REQUEST_URL') ?: '',
        'verify_url' => getenv('PAYMENT_VERIFY_URL') ?: '',
    ],
];
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) $defaults = array_replace_recursive($defaults, $local);
}
return $defaults;

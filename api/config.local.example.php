<?php
return [
    'app_env' => 'production',
    'app_url' => 'https://shop.example.com',
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'hostinguser_noura_shop',
        'user' => 'hostinguser_noura_user',
        'pass' => 'CHANGE_ME',
    ],
    'session' => ['secure' => true],
    'payment' => ['driver' => 'sandbox'],
];

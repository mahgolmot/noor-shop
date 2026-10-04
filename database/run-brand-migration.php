<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/api/config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$check = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='brand'");
if (!(int) $check->fetchColumn()) {
    $pdo->exec("ALTER TABLE products ADD COLUMN brand VARCHAR(100) NOT NULL DEFAULT 'bilumiere' AFTER image_path, ADD INDEX idx_products_brand_active (brand,active)");
}
echo "brand-column-ready\n";

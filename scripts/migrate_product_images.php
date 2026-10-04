<?php
declare(strict_types=1);
require __DIR__ . '/../api/bootstrap.php';
$column = db()->query("SHOW COLUMNS FROM products LIKE 'image_path'")->fetch();
if (!$column) {
    db()->exec('ALTER TABLE products ADD COLUMN image_path VARCHAR(255) NULL AFTER description');
}
$longDescription = db()->query("SHOW COLUMNS FROM products LIKE 'long_description'")->fetch();
if (!$longDescription) {
    db()->exec('ALTER TABLE products ADD COLUMN long_description MEDIUMTEXT NULL AFTER description');
}
db()->exec("CREATE TABLE IF NOT EXISTS product_images (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_id BIGINT UNSIGNED NOT NULL,path VARCHAR(255) NOT NULL,sort_order INT NOT NULL DEFAULT 0,FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,INDEX(product_id,sort_order)) ENGINE=InnoDB");
db()->exec("CREATE TABLE IF NOT EXISTS favorites (user_id BIGINT UNSIGNED NOT NULL,product_id BIGINT UNSIGNED NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,product_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE) ENGINE=InnoDB");
db()->exec("CREATE TABLE IF NOT EXISTS reviews (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,product_id BIGINT UNSIGNED NOT NULL,rating TINYINT UNSIGNED NOT NULL,body TEXT NOT NULL,approved BOOLEAN NOT NULL DEFAULT TRUE,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY(user_id,product_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,INDEX(product_id,approved)) ENGINE=InnoDB");
db()->exec("CREATE TABLE IF NOT EXISTS addresses (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,title VARCHAR(80) NOT NULL,recipient VARCHAR(120) NOT NULL,phone VARCHAR(20) NOT NULL,province VARCHAR(80) NOT NULL,city VARCHAR(80) NOT NULL,address TEXT NOT NULL,postal_code VARCHAR(10) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,INDEX(user_id)) ENGINE=InnoDB");
db()->exec("CREATE TABLE IF NOT EXISTS coupons (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,code VARCHAR(40) NOT NULL UNIQUE,type ENUM('percent','fixed') NOT NULL,value BIGINT UNSIGNED NOT NULL,min_order BIGINT UNSIGNED NOT NULL DEFAULT 0,active BOOLEAN NOT NULL DEFAULT TRUE,expires_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
db()->exec("CREATE TABLE IF NOT EXISTS settings (`key` VARCHAR(80) PRIMARY KEY,`value` TEXT NOT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
db()->exec("INSERT IGNORE INTO settings(`key`,`value`) VALUES('store_name','bilumiere'),('support_phone','02100000000'),('standard_shipping','0'),('express_shipping','120000'),('return_days','7')");
$discountColumn = db()->query("SHOW COLUMNS FROM orders LIKE 'discount'")->fetch();
if (!$discountColumn) db()->exec("ALTER TABLE orders ADD COLUMN discount BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER shipping, ADD COLUMN coupon_code VARCHAR(40) NULL AFTER discount");
echo "Product image migration is ready.\n";

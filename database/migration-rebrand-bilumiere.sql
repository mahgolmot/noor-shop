UPDATE settings
SET `value` = 'bilumiere'
WHERE `key` = 'store_name' AND LOWER(`value`) = 'noura';

UPDATE products
SET brand = 'bilumiere'
WHERE LOWER(brand) = 'noura';

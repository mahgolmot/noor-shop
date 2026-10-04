<?php
declare(strict_types=1);
require __DIR__.'/../api/bootstrap.php';
$pdo=db();$phone='09990000002';$sku='CODEX-E2E-001';
if(($argv[1]??'')==='cleanup'){
 $u=$pdo->prepare('SELECT id FROM users WHERE phone=?');$u->execute([$phone]);$uid=$u->fetchColumn();
 $p=$pdo->prepare('SELECT product_id FROM product_variants WHERE sku=?');$p->execute([$sku]);$pid=$p->fetchColumn();
 if($uid){$orders=$pdo->prepare('SELECT id FROM orders WHERE user_id=?');$orders->execute([$uid]);$ids=$orders->fetchAll(PDO::FETCH_COLUMN);foreach($ids as $id){$pdo->prepare('DELETE FROM inventory_movements WHERE order_id=?')->execute([$id]);$pdo->prepare('DELETE FROM payments WHERE order_id=?')->execute([$id]);$pdo->prepare('DELETE FROM orders WHERE id=?')->execute([$id]);}$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);}
 if($pid)$pdo->prepare('DELETE FROM products WHERE id=?')->execute([$pid]);
 $pdo->prepare('DELETE FROM coupons WHERE code="E2ETEST10"')->execute();echo "clean\n";exit;
}
$pdo->prepare('INSERT INTO products(name,slug,description,long_description,category,active)VALUES("عطر تست سرتاسری",?,"توضیح کوتاه تست","توضیحات بلند تست فروشگاه عطر","unisex",1)')->execute(['e2e-'.bin2hex(random_bytes(4))]);$pid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO product_variants(product_id,sku,color,size,price,stock)VALUES(?, ?, "eau-de-parfum", "100ml", 100000, 5)')->execute([$pid,$sku]);$vid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO coupons(code,type,value,min_order,active)VALUES("E2ETEST10","percent",10,0,1) ON DUPLICATE KEY UPDATE active=1')->execute();echo json_encode(['product_id'=>$pid,'variant_id'=>$vid]);

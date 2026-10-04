<?php
declare(strict_types=1);
require __DIR__.'/../api/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$pdo=db();$pdo->beginTransaction();
try{
 $orders=$pdo->query('SELECT id FROM orders WHERE status="pending" AND payment_status="unpaid" AND created_at < DATE_SUB(NOW(),INTERVAL 20 MINUTE) FOR UPDATE')->fetchAll();
 foreach($orders as $order){$stmt=$pdo->prepare('SELECT variant_id,quantity FROM order_items WHERE order_id=?');$stmt->execute([$order['id']]);foreach($stmt->fetchAll() as $item){$pdo->prepare('UPDATE product_variants SET stock=stock+? WHERE id=?')->execute([$item['quantity'],$item['variant_id']]);$pdo->prepare('INSERT INTO inventory_movements(variant_id,order_id,quantity,type,note)VALUES(?,?,?,"return","Expired unpaid order")')->execute([$item['variant_id'],$order['id'],$item['quantity']]);}$pdo->prepare('UPDATE orders SET status="cancelled",payment_status="failed" WHERE id=?')->execute([$order['id']]);$pdo->prepare('UPDATE payments SET status="failed" WHERE order_id=? AND status="pending"')->execute([$order['id']]);}
 $pdo->commit();fwrite(STDOUT,count($orders)." expired orders released.\n");
}catch(Throwable $e){$pdo->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(1);}

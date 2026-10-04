<?php
declare(strict_types=1);
function adminOrderDetailsRoute(string $path,string $method): void {
    if($method!=='GET'||!preg_match('#^/admin/orders/(\d+)$#',$path,$m))return;
    requireUser('admin');storeManagementTables();$q=db()->prepare('SELECT o.*,COALESCE((SELECT r.is_ready FROM order_ready r WHERE r.order_id=o.id),0) is_ready,u.name customer,COALESCE((SELECT d.phone FROM deleted_user_phones d WHERE d.user_id=u.id),u.phone) customer_phone FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');$q->execute([(int)$m[1]]);$o=$q->fetch();if(!$o)fail('سفارش پیدا نشد.',404);
    $q=db()->prepare('SELECT oi.product_id,oi.variant_id,oi.sku,oi.unit_price,oi.quantity,p.name,p.image_path,v.color,v.size FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id LEFT JOIN product_variants v ON v.id=oi.variant_id WHERE oi.order_id=? ORDER BY oi.id');$q->execute([$o['id']]);$o['items']=$q->fetchAll();
    $q=db()->prepare('SELECT id,amount,status,reference_id,created_at,paid_at FROM payments WHERE order_id=? ORDER BY id DESC');$q->execute([$o['id']]);$o['payments']=$q->fetchAll();$o['shipping_address']=json_decode($o['shipping_address'],true)?:[];respond(['ok'=>true,'order'=>$o]);
}

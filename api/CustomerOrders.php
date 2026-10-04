<?php
declare(strict_types=1);
function customerOrderTables(): void {
    readyOrderTables();
    db()->exec('CREATE TABLE IF NOT EXISTS customer_order_hidden(order_id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
}
function customerOrderEditable(array $order): bool {return in_array($order['status'],['pending','confirmed'],true)&&!in_array($order['payment_status'],['failed','refunded'],true);}
function customerOrderCancelable(array $order): bool {return $order['status']==='pending'&&in_array($order['payment_status'],['unpaid','failed'],true);}
function customerOrderRoutes(string $path,string $method): void {
    if($path==='/orders'&&$method==='GET'){
        $u=requireUser();customerOrderTables();$q=db()->prepare('SELECT o.id,o.order_number,CASE WHEN o.status="processing" AND EXISTS(SELECT 1 FROM order_ready r WHERE r.order_id=o.id AND r.is_ready=1) THEN "ready" ELSE o.status END status,o.payment_status,o.total,o.created_at,(SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi WHERE oi.order_id=o.id) items_count FROM orders o WHERE o.user_id=? AND NOT EXISTS(SELECT 1 FROM customer_order_hidden h WHERE h.order_id=o.id AND h.user_id=o.user_id) ORDER BY o.created_at DESC,o.id DESC');$q->execute([$u['id']]);respond(['ok'=>true,'orders'=>$q->fetchAll()]);
    }
    if(!preg_match('#^/orders/(\d+)$#',$path,$m)||!in_array($method,['GET','PATCH','DELETE'],true))return;
    if($method!=='GET')requireCsrf();$u=requireUser();$pdo=db();$id=(int)$m[1];customerOrderTables();
    if($method==='GET'){
        $q=$pdo->prepare('SELECT id,order_number,status,payment_status,subtotal,shipping,discount,coupon_code,total,shipping_address,created_at,updated_at FROM orders WHERE id=? AND user_id=?');$q->execute([$id,$u['id']]);$o=$q->fetch();if(!$o)fail('سفارش پیدا نشد.',404);
        $q=$pdo->prepare('SELECT oi.product_id,oi.variant_id,oi.sku,oi.unit_price,oi.quantity,p.name,p.image_path,v.color,v.size FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id LEFT JOIN product_variants v ON v.id=oi.variant_id WHERE oi.order_id=? ORDER BY oi.id');$q->execute([$id]);$o['items']=$q->fetchAll();
        $q=$pdo->prepare('SELECT id,amount,status,reference_id,created_at FROM payments WHERE order_id=? ORDER BY id DESC');$q->execute([$id]);$o['payments']=$q->fetchAll();$o['shipping_address']=json_decode($o['shipping_address'],true)?:[];
        $q=$pdo->prepare('SELECT is_ready FROM order_ready WHERE order_id=?');$q->execute([$id]);if($o['status']==='processing'&&(int)$q->fetchColumn()===1)$o['status']='ready';$o['can_edit']=customerOrderEditable($o);$o['can_cancel']=customerOrderCancelable($o);$o['can_hide']=in_array($o['status'],['cancelled','delivered'],true);respond(['ok'=>true,'order'=>$o]);
    }
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT * FROM orders WHERE id=? AND user_id=? FOR UPDATE');$q->execute([$id,$u['id']]);$o=$q->fetch();if(!$o){$pdo->rollBack();fail('سفارش پیدا نشد.',404);}
        if($method==='PATCH'){
            if(!customerOrderEditable($o)){$pdo->rollBack();fail('پس از شروع آماده‌سازی، ویرایش اطلاعات ارسال مجاز نیست؛ با پشتیبانی تماس بگیرید.',409);}
            $d=jsonBody();$a=$d['shipping']??null;if(!is_array($a)){$pdo->rollBack();fail('اطلاعات ارسال نامعتبر است.',422);}
            $clean=[];foreach(['name','phone','city','address','postal','note'] as $key)$clean[$key]=trim((string)($a[$key]??''));$clean['phone']=cleanPhone($clean['phone']);$clean['postal']=cleanPhone($clean['postal']);
            if(mb_strlen($clean['name'])<2||!preg_match('/^09\d{9}$/',$clean['phone'])||mb_strlen($clean['city'])<2||mb_strlen($clean['address'])<5||!preg_match('/^\d{10}$/',$clean['postal'])||mb_strlen($clean['note'])>1000||mb_strlen($clean['address'])>1000||mb_strlen($clean['name'])>100||mb_strlen($clean['city'])>100){$pdo->rollBack();fail('نام، موبایل، شهر، نشانی و کد پستی ده‌رقمی را کامل و درست وارد کنید.',422);}
            $old=json_decode($o['shipping_address'],true)?:[];$pdo->prepare('UPDATE orders SET shipping_address=? WHERE id=?')->execute([json_encode(array_merge($old,$clean),JSON_UNESCAPED_UNICODE),$id]);
        }else{
            if(customerOrderCancelable($o)){releaseIntegrationOrder($pdo,$id);}elseif(!in_array($o['status'],['cancelled','delivered'],true)){$pdo->rollBack();fail('این سفارش قابل حذف یا لغو نیست؛ با پشتیبانی تماس بگیرید.',409);}
            $pdo->prepare('INSERT IGNORE INTO customer_order_hidden(order_id,user_id) VALUES(?,?)')->execute([$id,$u['id']]);
        }
        $pdo->commit();audit($method==='PATCH'?'customer_order_shipping_updated':'customer_order_hidden','order',$id);respond(['ok'=>true]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

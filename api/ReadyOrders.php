<?php
declare(strict_types=1);
function readyOrderTables(): void {db()->exec('CREATE TABLE IF NOT EXISTS order_ready(order_id BIGINT UNSIGNED PRIMARY KEY,is_ready TINYINT NOT NULL DEFAULT 0,sms_state VARCHAR(20),rec_id VARCHAR(100),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');}
function sendOrderReadySms(array $order,array $settings,?callable $transport=null): array {
    $phone=cleanPhone((string)$order['customer_phone']);if(!preg_match('/^09\d{9}$/',$phone)||empty($order['customer_active']))throw new RuntimeException('حساب مشتری فعال یا شمارهٔ آن معتبر نیست.');
    $body=(string)($settings['sms_ready_body_id']??'');if(!preg_match('/^\d+$/',$body)||$body===(string)($settings['sms_otp_body_id']??$settings['sms_body_id']??''))throw new RuntimeException('پترن آماده ارسال باید جدا از پترن کد ورود ثبت شود.');
    return (new SmsGateway(['username'=>$settings['sms_username']??'','password'=>$settings['sms_password']??''],$transport))->sendPattern($phone,[$order['order_number']],$body);
}
function readyOrderRoutes(string $path,string $method): void {
    if($method!=='PATCH'||!preg_match('#^/admin/orders/(\d+)/status$#',$path,$m))return;
    requireCsrf();requireUser('admin');$status=(string)(jsonBody()['status']??'');if(!in_array($status,['pending','confirmed','processing','ready','sent','delivered','cancelled'],true))fail('وضعیت نامعتبر است.',422);
    readyOrderTables();integrationTables();$pdo=db();$id=(int)$m[1];$settings=settingsMap();$send=false;$pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT o.*,u.phone customer_phone,u.active customer_active FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=? FOR UPDATE');$q->execute([$id]);$o=$q->fetch();if(!$o){$pdo->rollBack();fail('سفارش پیدا نشد.',404);}
        if($status==='ready'&&($o['payment_status']!=='paid'||in_array($o['status'],['cancelled','delivered','sent'],true))){$pdo->rollBack();fail('آماده ارسال فقط برای سفارش پرداخت‌شده و ارسال‌نشده مجاز است.',409);}
        $pdo->prepare('INSERT IGNORE INTO order_ready(order_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT sms_state FROM order_ready WHERE order_id=? FOR UPDATE');$q->execute([$id]);$smsState=$q->fetchColumn();
        $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status==='ready'?'processing':$status,$id]);$pdo->prepare('UPDATE order_ready SET is_ready=? WHERE order_id=?')->execute([$status==='ready'?1:0,$id]);
        if($status==='ready'&&($settings['sms_ready_enabled']??'0')==='1'&&!$smsState){$pdo->prepare('UPDATE order_ready SET sms_state="sending" WHERE order_id=?')->execute([$id]);$send=true;}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $message='وضعیت سفارش ثبت شد.';$smsState=$send?'sending':($smsState?:'disabled');
    if($send){try{$r=sendOrderReadySms($o,$settings);$pdo->prepare('UPDATE order_ready SET sms_state="accepted",rec_id=? WHERE order_id=?')->execute([$r['rec_id'],$id]);smsLog($o['customer_phone'],'ready_order','accepted',$r['rec_id']);$smsState='accepted';$message.=' سامانه پیامک آماده ارسال را پذیرفت.';}catch(Throwable $e){$pdo->prepare('UPDATE order_ready SET sms_state="failed" WHERE order_id=?')->execute([$id]);smsLog($o['customer_phone'],'ready_order','failed',null,$e->getMessage());$smsState='failed';$message.=' پیامک تأیید نشد؛ تنظیمات و گزارش پیامک را بررسی کنید. برای جلوگیری از ارسال تکراری، تغییر دوبارهٔ وضعیت پیامک را تکرار نمی‌کند.';}}
    audit('order_status_changed','order',$id,['status'=>$status,'sms_state'=>$smsState]);respond(['ok'=>true,'message'=>$message,'sms_state'=>$smsState,'is_ready'=>$status==='ready']);
}

<?php
declare(strict_types=1);
function storeManagementTables(): void {
    readyOrderTables();
    db()->exec('CREATE TABLE IF NOT EXISTS deleted_user_phones(user_id BIGINT UNSIGNED PRIMARY KEY,phone VARCHAR(20) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    foreach(['CREATE TABLE IF NOT EXISTS admin_order_hidden(order_id BIGINT UNSIGNED PRIMARY KEY,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB','CREATE TABLE IF NOT EXISTS order_address_saved(order_id BIGINT UNSIGNED PRIMARY KEY,address_id BIGINT UNSIGNED,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB'] as $sql)db()->exec($sql);
}
function releaseDeletedPhone(string $phone): void {
    if(!preg_match('/^09\d{9}$/',$phone))return;$pdo=db();$owned=!$pdo->inTransaction();if($owned)$pdo->beginTransaction();
    try{$q=$pdo->prepare('SELECT id,phone FROM users WHERE phone=? AND active=0 FOR UPDATE');$q->execute([$phone]);$old=$q->fetch();if($old){$pdo->prepare('INSERT IGNORE INTO deleted_user_phones(user_id,phone) VALUES(?,?)')->execute([$old['id'],$old['phone']]);$pdo->prepare('UPDATE users SET phone=? WHERE id=? AND active=0')->execute(['x'.base_convert((string)$old['id'],10,36),$old['id']]);}if($owned)$pdo->commit();}catch(Throwable $e){if($owned&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function addressInput(array $d): array {
    $a=[];foreach(['title','recipient','phone','province','city','address','postal_code'] as $k)$a[$k]=trim((string)($d[$k]??''));$a['phone']=cleanPhone($a['phone']);$a['postal_code']=cleanPhone($a['postal_code']);
    if($a['title']===''||mb_strlen($a['recipient'])<2||!preg_match('/^09\d{9}$/',$a['phone'])||mb_strlen($a['city'])<2||mb_strlen($a['address'])<5||!preg_match('/^\d{10}$/',$a['postal_code']))throw new DomainException('نام، موبایل، شهر، نشانی و کد پستی ده‌رقمی را کامل کنید.');
    foreach($a as $k=>$v)if(mb_strlen($v)>($k==='address'?1000:100))throw new DomainException('اطلاعات آدرس بیش از حد طولانی است.');return $a;
}
function savePaidOrderAddress(PDO $pdo,int $id): void {
    $q=$pdo->prepare('SELECT user_id,payment_status,status,shipping_address FROM orders WHERE id=?');$q->execute([$id]);$o=$q->fetch();if(!$o||$o['payment_status']!=='paid'||$o['status']==='cancelled')return;
    $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND active=1 FOR UPDATE');$q->execute([$o['user_id']]);if(!$q->fetchColumn())return;
    $q=$pdo->prepare('SELECT order_id FROM order_address_saved WHERE order_id=?');$q->execute([$id]);if($q->fetchColumn())return;$s=json_decode($o['shipping_address'],true)?:[];
    try{$a=addressInput(['title'=>'آدرس خرید','recipient'=>$s['name']??$s['recipient']??'','phone'=>$s['phone']??'','province'=>$s['province']??'','city'=>$s['city']??'','address'=>$s['address']??'','postal_code'=>$s['postal']??$s['postal_code']??'']);}catch(DomainException $e){return;}
    $q=$pdo->prepare('SELECT id FROM addresses WHERE user_id=? AND recipient=? AND phone=? AND province=? AND city=? AND address=? AND postal_code=? LIMIT 1');$q->execute([$o['user_id'],$a['recipient'],$a['phone'],$a['province'],$a['city'],$a['address'],$a['postal_code']]);$addressId=$q->fetchColumn();
    if(!$addressId){$pdo->prepare('INSERT INTO addresses(user_id,title,recipient,phone,province,city,address,postal_code) VALUES(?,?,?,?,?,?,?,?)')->execute(array_merge([$o['user_id']],array_values($a)));$addressId=$pdo->lastInsertId();}
    $pdo->prepare('INSERT INTO order_address_saved(order_id,address_id) VALUES(?,?)')->execute([$id,$addressId]);
}
function storeManagementRoutes(string $path,string $method): void {
    if($path==='/admin/settings'&&$method==='PATCH'){requireCsrf();requireUser('admin');$d=jsonBody();if(isset($d['standard_shipping'])&&(!preg_match('/^\d{1,10}$/',(string)$d['standard_shipping'])||(float)$d['standard_shipping']>1000000000))fail('هزینه ارسال باید مبلغی بین صفر و یک میلیارد تومان باشد.',422);return;}
    if($path==='/account/addresses'&&$method==='POST'||preg_match('#^/account/addresses/(\d+)$#',$path,$m)&&in_array($method,['PATCH','DELETE'],true)){
        requireCsrf();$u=requireUser();$pdo=db();$id=(int)($m[1]??0);
        if($id){$q=$pdo->prepare('SELECT id FROM addresses WHERE id=? AND user_id=?');$q->execute([$id,$u['id']]);if(!$q->fetchColumn())fail('آدرس پیدا نشد.',404);}
        if($method==='DELETE'){$pdo->prepare('DELETE FROM addresses WHERE id=? AND user_id=?')->execute([$id,$u['id']]);respond(['ok'=>true]);}
        try{$a=addressInput(jsonBody());}catch(DomainException $e){fail($e->getMessage(),422);}
        if($id)$pdo->prepare('UPDATE addresses SET title=?,recipient=?,phone=?,province=?,city=?,address=?,postal_code=? WHERE id=? AND user_id=?')->execute(array_merge(array_values($a),[$id,$u['id']]));else{$pdo->prepare('INSERT INTO addresses(user_id,title,recipient,phone,province,city,address,postal_code) VALUES(?,?,?,?,?,?,?,?)')->execute(array_merge([$u['id']],array_values($a)));$id=(int)$pdo->lastInsertId();}respond(['ok'=>true,'id'=>$id],$method==='POST'?201:200);
    }
    if($path==='/admin/orders'&&$method==='GET'){requireUser('admin');storeManagementTables();respond(['ok'=>true,'orders'=>db()->query('SELECT o.*,COALESCE((SELECT r.is_ready FROM order_ready r WHERE r.order_id=o.id),0) is_ready,u.name customer FROM orders o JOIN users u ON u.id=o.user_id WHERE NOT EXISTS(SELECT 1 FROM admin_order_hidden h WHERE h.order_id=o.id) ORDER BY o.created_at DESC,o.id DESC')->fetchAll()]);}
    if(preg_match('#^/admin/orders/(\d+)$#',$path,$m)&&$method==='DELETE'){
        requireCsrf();requireUser('admin');storeManagementTables();$pdo=db();$pdo->beginTransaction();try{$q=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');$q->execute([(int)$m[1]]);$o=$q->fetch();if(!$o){$pdo->rollBack();fail('سفارش پیدا نشد.',404);}if(customerOrderCancelable($o))releaseIntegrationOrder($pdo,(int)$o['id']);$pdo->prepare('INSERT IGNORE INTO admin_order_hidden(order_id) VALUES(?)')->execute([$o['id']]);$pdo->commit();audit('admin_order_archived','order',(int)$o['id']);respond(['ok'=>true]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    if($path==='/admin/users'&&$method==='POST'){
        requireCsrf();requireUser('admin');$d=jsonBody();$name=trim((string)($d['name']??''));$phone=cleanPhone((string)($d['phone']??''));$password=(string)($d['password']??'');$role=(string)($d['role']??'customer');if(mb_strlen($name)<2||mb_strlen($name)>100||!preg_match('/^09\d{9}$/',$phone)||strlen($password)<8||strlen($password)>72||!in_array($role,['customer','admin'],true))fail('نام، موبایل، نقش و رمز ۸ تا ۷۲ کاراکتری را درست وارد کنید.',422);
        storeManagementTables();releaseDeletedPhone($phone);try{db()->prepare('INSERT INTO users(name,phone,password_hash,role,active) VALUES(?,?,?,?,1)')->execute([$name,$phone,password_hash($password,PASSWORD_DEFAULT),$role]);}catch(PDOException $e){if($e->getCode()==='23000')fail('این شماره متعلق به یک حساب فعال است.',409);throw $e;}$id=(int)db()->lastInsertId();audit('admin_user_created','user',$id,['role'=>$role]);respond(['ok'=>true,'id'=>$id],201);
    }
    if(preg_match('#^/admin/users/(\d+)$#',$path,$m)&&$method==='DELETE'){
        requireCsrf();$admin=requireUser('admin');storeManagementTables();$id=(int)$m[1];if($id===(int)$admin['id'])fail('حساب مدیر فعلی قابل حذف نیست.',422);$pdo=db();$pdo->beginTransaction();try{
            $admins=$pdo->query('SELECT id FROM users WHERE role="admin" AND active=1 ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);$q=$pdo->prepare('SELECT id,role,active,phone FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);$u=$q->fetch();if(!$u){$pdo->rollBack();fail('کاربر پیدا نشد.',404);}if($u['role']==='admin'&&(int)$u['active']&&count($admins)<=1){$pdo->rollBack();fail('حداقل یک مدیر فعال باید باقی بماند.',422);}$pdo->prepare('UPDATE users SET active=0 WHERE id=?')->execute([$id]);releaseDeletedPhone($u['phone']);$pdo->commit();audit('admin_user_deactivated','user',$id);respond(['ok'=>true]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}

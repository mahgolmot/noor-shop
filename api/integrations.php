<?php
declare(strict_types=1);
require_once __DIR__.'/OtpPolicy.php';
require_once __DIR__.'/CustomerOrders.php';
require_once __DIR__.'/AdminOrderDetails.php';
require_once __DIR__.'/StoreManagement.php';
require_once __DIR__.'/ReviewModeration.php';
require_once __DIR__.'/ReadyOrders.php';
require_once __DIR__.'/ProductCatalog.php';
function integrationTables(): void {
    storeManagementTables();
    db()->exec('CREATE TABLE IF NOT EXISTS sms_registration_data(challenge_id VARCHAR(64) PRIMARY KEY,name VARCHAR(100) NOT NULL,password_hash VARCHAR(255) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    db()->exec('DELETE FROM sms_registration_data WHERE created_at<NOW()-INTERVAL 1 DAY');
    foreach([
      'CREATE TABLE IF NOT EXISTS integration_payments(payment_id BIGINT UNSIGNED PRIMARY KEY,provider VARCHAR(30) NOT NULL,fee_mode VARCHAR(20) NOT NULL DEFAULT "merchant") ENGINE=InnoDB',
      'CREATE TABLE IF NOT EXISTS sms_challenges(id VARCHAR(64) PRIMARY KEY,phone VARCHAR(20) NOT NULL,purpose VARCHAR(20) NOT NULL,session_hash VARCHAR(64) NOT NULL,code_hash VARCHAR(255) NOT NULL,attempts INT NOT NULL DEFAULT 0,consumed TINYINT NOT NULL DEFAULT 0,expires_at DATETIME NOT NULL,created_at DATETIME NOT NULL,INDEX(phone,created_at)) ENGINE=InnoDB',
      'CREATE TABLE IF NOT EXISTS integration_limits(id VARCHAR(64) PRIMARY KEY,window_start BIGINT NOT NULL,hits INT NOT NULL,last_request BIGINT NOT NULL) ENGINE=InnoDB',
      'CREATE TABLE IF NOT EXISTS sms_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,phone VARCHAR(20) NOT NULL,purpose VARCHAR(30) NOT NULL,rec_id VARCHAR(100),status VARCHAR(30) NOT NULL,error_message VARCHAR(255),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB'
    ] as $sql)db()->exec($sql);
}
function integrationResult(string $state,string $number='',string $reference=''): void {
    global $config;$base=rtrim((string)(parse_url($config['app_url'],PHP_URL_PATH)??''),'/');
    header('Location: '.$base.'/payment-result.html?'.http_build_query(['state'=>$state,'order'=>$number,'reference'=>$reference]),true,303);exit;
}
function releaseIntegrationOrder(PDO $pdo,int $id): void {
    $q=$pdo->prepare('SELECT variant_id,quantity FROM order_items WHERE order_id=? ORDER BY variant_id');$q->execute([$id]);
    foreach($q->fetchAll() as $item){$pdo->prepare('UPDATE product_variants SET stock=stock+? WHERE id=?')->execute([$item['quantity'],$item['variant_id']]);$pdo->prepare('INSERT INTO inventory_movements(variant_id,order_id,quantity,type,note) VALUES(?,?,?,"return","Unpaid order cancelled")')->execute([$item['variant_id'],$id,$item['quantity']]);}
    $pdo->prepare('UPDATE orders SET status="cancelled",payment_status="failed" WHERE id=?')->execute([$id]);$pdo->prepare('UPDATE payments SET status="failed" WHERE order_id=? AND status="pending"')->execute([$id]);
}
function expireIntegrationOrders(): void {
    $pdo=db();$pdo->beginTransaction();try{$rows=$pdo->query('SELECT id FROM orders WHERE status="pending" AND payment_status="unpaid" AND created_at<NOW()-INTERVAL 20 MINUTE ORDER BY id FOR UPDATE')->fetchAll();foreach($rows as $r)releaseIntegrationOrder($pdo,(int)$r['id']);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function smsLog(string $phone,string $purpose,string $status,?string $rec=null,?string $error=null): void {
    db()->prepare('INSERT INTO sms_logs(phone,purpose,rec_id,status,error_message) VALUES(?,?,?,?,?)')->execute([$phone,$purpose,$rec,$status,$error]);
}
function integrationRoutes(string $path,string $method): void {
    productCatalogRoutes($path,$method);
    readyOrderRoutes($path,$method);
    reviewModerationRoutes($path,$method);
    storeManagementRoutes($path,$method);
    adminOrderDetailsRoute($path,$method);
    customerOrderRoutes($path,$method);
    if($path==='/orders'&&$method==='POST'){requireCsrf();requireUser();integrationTables();$shippingInput=jsonBody()['shipping']??[];try{addressInput(['title'=>'آدرس خرید','recipient'=>$shippingInput['name']??'','phone'=>$shippingInput['phone']??'','province'=>$shippingInput['province']??'','city'=>$shippingInput['city']??'','address'=>$shippingInput['address']??'','postal_code'=>$shippingInput['postal']??'']);}catch(DomainException $e){fail($e->getMessage(),422);}expireIntegrationOrders();return;}
    if($path==='/account/payment-result'&&$method==='GET'){
        $u=requireUser();$q=db()->prepare('SELECT o.order_number,o.status,o.payment_status,p.reference_id,p.status transaction_status FROM orders o LEFT JOIN payments p ON p.order_id=o.id WHERE o.order_number=? AND o.user_id=? ORDER BY p.id DESC LIMIT 1');$q->execute([(string)($_GET['order']??''),$u['id']]);$r=$q->fetch();if(!$r)fail('سفارش پیدا نشد.',404);
        $r['state']=$r['payment_status']==='paid'?($r['status']==='cancelled'?'review':'paid'):($r['transaction_status']==='failed'?'failed':'pending');respond(['ok'=>true,'payment'=>$r]);
    }
    if($path==='/account/payment-recheck'&&$method==='POST'){
        requireCsrf();$u=requireUser();$q=db()->prepare('SELECT p.authority FROM payments p JOIN orders o ON o.id=p.order_id WHERE o.order_number=? AND o.user_id=? ORDER BY p.id DESC LIMIT 1');$q->execute([(string)(jsonBody()['order']??''),$u['id']]);$token=$q->fetchColumn();if(!$token)fail('تراکنش پیدا نشد.',404);respond(['ok'=>true,'token'=>$token]);
    }
    if($path==='/admin/settings'&&$method==='GET'){
        requireUser('admin');$s=settingsMap();$s['sms_password_set']=!empty($s['sms_password']);$s['payment_api_key_set']=!empty($s['payment_api_key']);$s['payment_merchant_id_set']=!empty($s['payment_merchant_id']);
        unset($s['sms_password'],$s['payment_api_key'],$s['payment_merchant_id']);respond(['ok'=>true,'settings'=>$s]);
    }
    if($path==='/admin/integrations/settings'&&$method==='PATCH'){
        requireCsrf();requireUser('admin');$d=jsonBody();$allowed=['payment_driver','payment_api_key','payment_fee_mode','sms_username','sms_password','sms_otp_body_id','sms_otp_enabled','sms_ready_enabled','sms_ready_body_id'];
        if(isset($d['sms_ready_enabled'])&&!in_array((string)$d['sms_ready_enabled'],['0','1'],true))fail('وضعیت پیامک آماده ارسال نامعتبر است.',422);if(!empty($d['sms_ready_body_id'])&&!preg_match('/^\d+$/',(string)$d['sms_ready_body_id']))fail('کد پترن آماده ارسال باید عدد باشد.',422);
        $current=settingsMap();$candidate=array_merge($current,$d);if(($candidate['sms_ready_enabled']??'0')==='1'){if(empty($candidate['sms_ready_body_id'])||(string)$candidate['sms_ready_body_id']===(string)($candidate['sms_otp_body_id']??$candidate['sms_body_id']??''))fail('برای پیامک آماده ارسال، پترن عددی جدا از کد ورود ثبت کنید.',422);if(empty($candidate['sms_username'])||empty(trim((string)($d['sms_password']??'')))&&empty($current['sms_password']))fail('نام کاربری و APIKey ملی پیامک را کامل کنید.',422);}$changed=false;foreach(['payment_driver','payment_api_key','payment_fee_mode'] as $key)if(isset($d[$key])&&$d[$key]!==''&&(string)$d[$key]!==($current[$key]??''))$changed=true;
        if($changed&&!empty($current['payment_api_key'])){integrationTables();if((int)db()->query('SELECT COUNT(*) FROM payments p JOIN integration_payments m ON m.payment_id=p.id WHERE m.provider="vandar" AND p.status="pending" AND p.created_at>=NOW()-INTERVAL 20 MINUTE')->fetchColumn()>0)fail('تراکنش وندار در انتظار تأیید است؛ فعلاً تنظیمات درگاه را تغییر ندهید.',409);}
        if(isset($d['payment_driver'])&&!in_array($d['payment_driver'],['disabled','vandar'],true))fail('درگاه باید وندار یا غیرفعال باشد.',422);
        if(isset($d['payment_fee_mode'])&&!in_array($d['payment_fee_mode'],['merchant','payer'],true))fail('روش کارمزد معتبر نیست.',422);
        if(isset($d['sms_otp_body_id'])&&$d['sms_otp_body_id']!==''&&!preg_match('/^\d+$/',(string)$d['sms_otp_body_id']))fail('کد متن پترن باید عدد باشد.',422);
        if(isset($d['sms_otp_enabled'])&&!in_array((string)$d['sms_otp_enabled'],['0','1'],true))fail('وضعیت پیامک معتبر نیست.',422);
        $q=db()->prepare('INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
        foreach($allowed as $k)if(isset($d[$k])&&(!in_array($k,['payment_api_key','sms_password'],true)||trim((string)$d[$k])!==''))$q->execute([$k,trim((string)$d[$k])]);
        audit('integration_settings_updated','settings',null,['keys'=>array_values(array_intersect(array_keys($d),$allowed))]);respond(['ok'=>true]);
    }
    if($path==='/admin/settings/test-payment'&&$method==='POST'){
        requireCsrf();requireUser('admin');$p=paymentConfig();if(($p['driver']??'')!=='vandar'||empty($p['api_key']))fail('وندار یا کلید API تنظیم نشده است.',422);
        respond(['ok'=>true,'message'=>'اطلاعات ذخیره شده؛ اعتبار کلید و IP فقط با درخواست پرداخت آزمایشی وندار بررسی می‌شود. این دکمه تراکنش ایجاد نمی‌کند.']);
    }
    if($path==='/admin/settings/test-sms'&&$method==='POST'){
        requireCsrf();requireUser('admin');$r=(new SmsGateway(smsConfig()))->credit();respond(['ok'=>true,'message'=>'احراز هویت ملی پیامک موفق بود؛ این تست پیامک ارسال نمی‌کند.','credit'=>$r['credit']]);
    }
    if($path==='/admin/integrations/sms-logs'&&$method==='GET'){
        requireUser('admin');integrationTables();respond(['ok'=>true,'logs'=>db()->query('SELECT phone,purpose,rec_id,status,error_message,created_at FROM sms_logs ORDER BY id DESC LIMIT 30')->fetchAll()]);
    }
    if($path==='/auth/otp/request'&&$method==='POST'){
        requireCsrf();$d=jsonBody();$phone=cleanPhone((string)($d['phone']??''));$purpose=(string)($d['purpose']??'login');
        if(!preg_match('/^09\d{9}$/',$phone)||!in_array($purpose,['login','register','reset'],true))fail('شماره موبایل یا نوع درخواست معتبر نیست.',422);
        if($purpose==='register'){storeManagementTables();releaseDeletedPhone($phone);}
        $registration=null;if($purpose==='register'){$name=trim((string)($d['name']??''));$password=(string)($d['password']??'');if(mb_strlen($name)<2||mb_strlen($name)>100||strlen($password)<8||strlen($password)>72||($d['terms']??false)!==true)fail('نام، رمز ۸ تا ۷۲ کاراکتری و پذیرش قوانین الزامی است.',422);$q=db()->prepare('SELECT id FROM users WHERE phone=?');$q->execute([$phone]);if($q->fetchColumn())fail('این شماره قبلاً حساب داشته است؛ از ورود یا بازیابی رمز استفاده کنید.',409);$registration=['name'=>$name,'hash'=>password_hash($password,PASSWORD_DEFAULT)];}
        $sms=smsConfig();if(!$sms['otp_enabled']||empty($sms['otp_body_id']))fail('ورود پیامکی هنوز توسط مدیر فعال نشده است.',422);
        integrationTables();$pdo=db();$pdo->beginTransaction();$now=time();
        try{
            foreach([['phone:'.$phone,5,60],['ip:'.($_SERVER['REMOTE_ADDR']??''),20,0]] as $limit){
                $key=hash('sha256',$limit[0]);$pdo->prepare('INSERT IGNORE INTO integration_limits(id,window_start,hits,last_request) VALUES(?,?,0,0)')->execute([$key,$now]);
                $q=$pdo->prepare('SELECT * FROM integration_limits WHERE id=? FOR UPDATE');$q->execute([$key]);$row=$q->fetch();$hits=$now-(int)$row['window_start']>=600?0:(int)$row['hits'];
                if(OtpPolicy::throttled($hits,$limit[1],(int)$row['last_request'],$now,$limit[2]))throw new DomainException('درخواست‌ها زیاد است؛ کمی صبر کنید.');
                $pdo->prepare('UPDATE integration_limits SET window_start=?,hits=?,last_request=? WHERE id=?')->execute([$hits===0?$now:$row['window_start'],$hits+1,$now,$key]);
            }
            $id=bin2hex(random_bytes(24));$code=(string)random_int(100000,999999);
            $pdo->prepare('UPDATE sms_challenges SET consumed=1 WHERE phone=? AND purpose=? AND session_hash=? AND consumed=0')->execute([$phone,$purpose,hash('sha256',session_id())]);
            $pdo->prepare('INSERT INTO sms_challenges(id,phone,purpose,session_hash,code_hash,expires_at,created_at) VALUES(?,?,?,?,?,FROM_UNIXTIME(?),FROM_UNIXTIME(?))')->execute([$id,$phone,$purpose,hash('sha256',session_id()),password_hash($code,PASSWORD_DEFAULT),$now+300,$now]);
            if($registration)$pdo->prepare('INSERT INTO sms_registration_data(challenge_id,name,password_hash) VALUES(?,?,?)')->execute([$id,$registration['name'],$registration['hash']]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof DomainException)fail($e->getMessage(),429);throw $e;}
        try{$result=(new SmsGateway($sms))->sendPattern($phone,[$code],$sms['otp_body_id']);smsLog($phone,$purpose,'accepted',$result['rec_id']);}
        catch(Throwable $e){$pdo->prepare('UPDATE sms_challenges SET consumed=1 WHERE id=?')->execute([$id]);$pdo->prepare('DELETE FROM sms_registration_data WHERE challenge_id=?')->execute([$id]);smsLog($phone,$purpose,'failed',null,$e->getMessage());fail($e->getMessage(),502);}
        respond(['ok'=>true,'challenge'=>$id,'expires_in'=>300,'retry_after'=>60]);
    }
    if($path==='/auth/otp/verify'&&$method==='POST'){
        requireCsrf();integrationTables();$d=jsonBody();$id=(string)($d['challenge']??'');$code=cleanPhone((string)($d['code']??''));$pdo=db();$pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT *,UNIX_TIMESTAMP(expires_at) expires FROM sms_challenges WHERE id=? FOR UPDATE');$q->execute([$id]);$c=$q->fetch();
            if(!$c||!OtpPolicy::allowed($c,time(),session_id())){$pdo->commit();fail('کد منقضی یا نامعتبر است؛ کد جدید بگیرید.',422);}
            $pdo->prepare('UPDATE sms_challenges SET attempts=attempts+1 WHERE id=?')->execute([$id]);
            if(!OtpPolicy::matches($code,$c['code_hash'])){$pdo->commit();fail('کد تأیید نادرست است.',422);}
            $q=$pdo->prepare('SELECT id,name,phone,role,active FROM users WHERE phone=? FOR UPDATE');$q->execute([$c['phone']]);$u=$q->fetch();
            if($u&&$u['role']==='admin'){$pdo->prepare('UPDATE sms_challenges SET consumed=1 WHERE id=?')->execute([$id]);$pdo->commit();fail('مدیران باید با رمز عبور وارد شوند؛ بازیابی رمز مدیر پیامکی نیست.',403);}
            $password=(string)($d['password']??'');$name=trim((string)($d['name']??''));
            if($c['purpose']==='register'){
                $q=$pdo->prepare('SELECT name,password_hash FROM sms_registration_data WHERE challenge_id=? FOR UPDATE');$q->execute([$id]);$registration=$q->fetch();if($u||!$registration){$pdo->commit();fail('حساب قبلاً وجود دارد یا درخواست ثبت‌نام کامل نیست؛ از ابتدا ثبت‌نام کنید.',422);}
                $name=$registration['name'];$pdo->prepare('INSERT INTO users(name,phone,password_hash,role) VALUES(?,?,?,"customer")')->execute([$name,$c['phone'],$registration['password_hash']]);$u=['id'=>(int)$pdo->lastInsertId(),'name'=>$name,'phone'=>$c['phone'],'role'=>'customer','active'=>1];
                $pdo->prepare('DELETE FROM sms_registration_data WHERE challenge_id=?')->execute([$id]);
            }else{
                if(!$u||!(int)$u['active']){$pdo->commit();fail('حساب فعال پیدا نشد.',422);}
                if($c['purpose']==='reset'){if(strlen($password)<8||strlen($password)>72){$pdo->commit();fail('رمز جدید باید بین ۸ و ۷۲ کاراکتر باشد.',422);}$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$u['id']]);}
            }
            $pdo->prepare('UPDATE sms_challenges SET consumed=1 WHERE id=?')->execute([$id]);$pdo->commit();session_regenerate_id(true);unset($u['active']);$u['id']=(int)$u['id'];$_SESSION['user']=$u;audit('otp_'.$c['purpose'],'user',$u['id']);respond(['ok'=>true,'user'=>$u]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    if($path==='/payment/callback'&&$method==='GET'){
        integrationTables();$token=(string)($_GET['token']??$_GET['Authority']??'');if(!preg_match('/^[a-zA-Z0-9_-]{1,120}$/',$token))integrationResult('invalid');
        $pdo=db();$q=$pdo->prepare('SELECT order_id FROM payments WHERE authority=?');$q->execute([$token]);$orderId=$q->fetchColumn();if(!$orderId)integrationResult('invalid');
        $pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT id,order_number,status FROM orders WHERE id=? FOR UPDATE');$q->execute([$orderId]);$o=$q->fetch();
            $q=$pdo->prepare('SELECT p.*,m.provider,m.fee_mode FROM payments p LEFT JOIN integration_payments m ON m.payment_id=p.id WHERE p.authority=? FOR UPDATE');$q->execute([$token]);$p=$q->fetch();
            if($p['status']==='paid'){savePaidOrderAddress($pdo,(int)$orderId);$pdo->commit();integrationResult($o['status']==='cancelled'?'review':'paid',$o['order_number'],$p['reference_id']??'');}
            if(($p['provider']??'')!=='vandar'){$pdo->commit();integrationResult('test',$o['order_number']);}
            $config=paymentConfig();$config['driver']='vandar';$config['fee_mode']=$p['fee_mode'];
            $r=(new PaymentGateway($config))->verify($token,(int)$p['amount'],$o['order_number']);
            if($r['verified']){
                $pdo->prepare('UPDATE payments SET status="paid",reference_id=?,paid_at=NOW() WHERE id=?')->execute([$r['reference'],$p['id']]);
                $pdo->prepare('UPDATE orders SET payment_status="paid",status=IF(status="cancelled","cancelled","confirmed") WHERE id=?')->execute([$orderId]);
                savePaidOrderAddress($pdo,(int)$orderId);
            }else{$pdo->prepare('UPDATE payments SET status="failed" WHERE id=?')->execute([$p['id']]);if($o['status']==='pending')releaseIntegrationOrder($pdo,(int)$orderId);}
            $pdo->commit();integrationResult($r['verified']?($o['status']==='cancelled'?'review':'paid'):'failed',$o['order_number'],$r['reference']??'');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Payment verification failed for order '.(int)$orderId);integrationResult('pending',$o['order_number']??'');}
    }
}

<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
session_name($config['session']['name']);
session_set_cookie_params(['httponly'=>true,'secure'=>$config['session']['secure'],'samesite'=>'Lax','path'=>'/']);
session_start();

function db(): PDO {
    static $pdo = null;
    global $config;
    if (!$pdo) {
        $db = $config['db'];
        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    }
    return $pdo;
}
function settingsMap(): array {
    static $settings=null;
    if($settings===null)$settings=db()->query('SELECT `key`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $settings;
}
function paymentConfig(): array {
    global $config;$settings=settingsMap();$payment=$config['payment'];
    $payment['allow_sandbox']=$config['app_env']==='development';foreach(['driver','api_key','fee_mode','merchant_id','request_url','verify_url'] as $key)if(isset($settings['payment_'.$key])&&$settings['payment_'.$key]!=='')$payment[$key]=$settings['payment_'.$key];
    return $payment;
}
function smsConfig(): array {
    $settings=settingsMap();return ['enabled'=>($settings['sms_enabled']??'0')==='1','username'=>$settings['sms_username']??'','password'=>$settings['sms_password']??'','sender'=>$settings['sms_sender']??'','body_id'=>$settings['sms_body_id']??'','otp_body_id'=>$settings['sms_otp_body_id']??($settings['sms_body_id']??''),'otp_enabled'=>($settings['sms_otp_enabled']??'0')==='1'];
}
function jsonBody(): array { $data=json_decode(file_get_contents('php://input') ?: '{}', true); return is_array($data)?$data:[]; }
function respond(array $data,int $status=200): void { http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit; }
function fail(string $message,int $status=400,array $errors=[]): void { respond(['ok'=>false,'message'=>$message,'errors'=>$errors],$status); }
function csrfToken(): string { if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));return $_SESSION['csrf']; }
function requireCsrf(): void { $sent=$_SERVER['HTTP_X_CSRF_TOKEN']??'';if(!$sent||!hash_equals($_SESSION['csrf']??'',$sent))fail('درخواست نامعتبر است.',419); }
function user(): ?array { return $_SESSION['user']??null; }
function requireUser(?string $role=null): array { $current=user();if(!$current)fail('ابتدا وارد حساب شوید.',401);$stmt=db()->prepare('SELECT id,name,phone,role,active FROM users WHERE id=? LIMIT 1');$stmt->execute([(int)$current['id']]);$fresh=$stmt->fetch();if(!$fresh||!(int)$fresh['active']){$_SESSION=[];fail('حساب کاربری فعال نیست.',401);}$_SESSION['user']=['id'=>(int)$fresh['id'],'name'=>$fresh['name'],'phone'=>$fresh['phone'],'role'=>$fresh['role']];if($role&&$fresh['role']!==$role)fail('دسترسی کافی ندارید.',403);return $_SESSION['user']; }
function route(): string { $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';$position=strpos($path,'/api/');return '/'.ltrim($position===false?$path:substr($path,$position+5),'/'); }
function cleanPhone(string $phone): string { return preg_replace('/\D+/','',strtr($phone,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']))?:''; }
function saveProductImage(?array $file): ?string { if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;if($file['error']!==UPLOAD_ERR_OK||$file['size']>5*1024*1024)fail('آپلود تصویر ناموفق است یا حجم آن بیشتر از ۵ مگابایت است.',422);$info=@getimagesize($file['tmp_name']);$types=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp',IMAGETYPE_GIF=>'gif'];$type=$info[2]??0;if(!isset($types[$type]))fail('فرمت تصویر باید JPG، PNG، WEBP یا GIF باشد.',422);$dir=dirname(__DIR__).'/uploads/products';if(!is_dir($dir)&&!mkdir($dir,0775,true))fail('پوشه تصاویر ساخته نشد.',500);$name=bin2hex(random_bytes(12)).'.'.$types[$type];if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name))fail('ذخیره تصویر ناموفق بود.',500);return 'uploads/products/'.$name; }
function audit(string $action,?string $entity=null,?int $entityId=null,array $meta=[]): void { $actor=user();$stmt=db()->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,metadata,ip_address)VALUES(?,?,?,?,?,?)');$stmt->execute([$actor['id']??null,$action,$entity,$entityId,json_encode($meta,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]); }

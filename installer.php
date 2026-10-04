<?php
declare(strict_types=1);

session_name('noura_installer');
session_start();

$root = __DIR__;
$lockFile = $root . '/api/.installed';
$configFile = $root . '/api/config.local.php';
$installed = is_file($lockFile) && is_file($configFile);
$errors = [];
$success = false;

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function detectedUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/installer.php')));
    return ($https ? 'https' : 'http') . '://' . $host . rtrim($dir === '/' ? '' : $dir, '/');
}

function sqlStatements(string $sql): array {
    $statements = [];
    $buffer = '';
    $quote = null;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';
        if ($quote === null) {
            if ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
                while ($i < $length && $sql[$i] !== "\n") $i++;
                $buffer .= "\n";
                continue;
            }
            if ($char === '#') {
                while ($i < $length && $sql[$i] !== "\n") $i++;
                $buffer .= "\n";
                continue;
            }
            if ($char === '/' && $next === '*') {
                $i += 2;
                while ($i + 1 < $length && !($sql[$i] === '*' && $sql[$i + 1] === '/')) $i++;
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') $statements[] = $statement;
                $buffer = '';
                continue;
            }
        } else {
            if ($char === '\\' && $i + 1 < $length) {
                $buffer .= $char . $sql[++$i];
                continue;
            }
            if ($char === $quote) {
                if ($next === $quote && $quote !== '`') {
                    $buffer .= $char . $next;
                    $i++;
                    continue;
                }
                $quote = null;
            }
        }
        $buffer .= $char;
    }
    $statement = trim($buffer);
    if ($statement !== '') $statements[] = $statement;
    return $statements;
}

function writePhpConfig(string $path, array $config): void {
    $content = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n";
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException('نوشتن فایل تنظیمات ممکن نیست؛ دسترسی پوشه api را بررسی کنید.');
    }
    @chmod($temporary, 0640);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('فعال‌سازی فایل تنظیمات ممکن نشد.');
    }
}

if (empty($_SESSION['installer_csrf'])) $_SESSION['installer_csrf'] = bin2hex(random_bytes(24));
$defaults = [
    'app_url' => detectedUrl(), 'db_host' => 'localhost', 'db_port' => '3306',
    'db_name' => '', 'db_user' => '', 'store_name' => 'bilumiere',
    'admin_name' => 'مدیر فروشگاه', 'admin_phone' => ''
];
$values = array_merge($defaults, array_intersect_key($_POST, $defaults));

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals((string) $_SESSION['installer_csrf'], (string) ($_POST['csrf'] ?? ''))) throw new RuntimeException('نشست نصب منقضی شده؛ صفحه را تازه کنید.');
        foreach (['pdo_mysql', 'mbstring', 'curl', 'openssl'] as $extension) if (!extension_loaded($extension)) $errors[] = 'افزونه PHP لازم فعال نیست: ' . $extension;
        $appUrl = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');
        $dbHost = trim((string) ($_POST['db_host'] ?? ''));
        $dbPort = (int) ($_POST['db_port'] ?? 3306);
        $dbName = trim((string) ($_POST['db_name'] ?? ''));
        $dbUser = trim((string) ($_POST['db_user'] ?? ''));
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $storeName = trim((string) ($_POST['store_name'] ?? ''));
        $adminName = trim((string) ($_POST['admin_name'] ?? ''));
        $adminPhone = preg_replace('/\D+/', '', strtr((string) ($_POST['admin_phone'] ?? ''), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'])) ?: '';
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        if (!filter_var($appUrl, FILTER_VALIDATE_URL) || !in_array((string) parse_url($appUrl, PHP_URL_SCHEME), ['http', 'https'], true)) $errors[] = 'آدرس سایت معتبر نیست.';
        if ($dbHost === '' || $dbUser === '' || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $dbName) || $dbPort < 1 || $dbPort > 65535) $errors[] = 'مشخصات دیتابیس کامل یا معتبر نیست.';
        if (mb_strlen($storeName) < 2 || mb_strlen($adminName) < 2) $errors[] = 'نام فروشگاه و مدیر را کامل کنید.';
        if (!preg_match('/^09\d{9}$/', $adminPhone)) $errors[] = 'شماره موبایل مدیر باید ۱۱ رقمی و با 09 شروع شود.';
        if (strlen($adminPassword) < 8 || strlen($adminPassword) > 72) $errors[] = 'رمز مدیر باید بین ۸ و ۷۲ کاراکتر باشد.';
        if (!is_dir($root . '/api') || !is_writable($root . '/api')) $errors[] = 'پوشه api قابل نوشتن نیست.';
        if ($errors) throw new RuntimeException('اطلاعات فرم را اصلاح کنید.');

        $dsn = 'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $sqlFiles = [
            'database/schema.sql', 'database/migration-product-catalog.sql',
            'database/migration-auth-moderation.sql', 'database/migration-customer-orders.sql',
            'database/migration-integrations.sql', 'database/migration-ready-orders.sql',
            'database/migration-reusable-phone.sql', 'database/migration-review-rejections.sql',
            'database/migration-store-management.sql', 'database/migration-perfume-store.sql',
            'database/migration-rebrand-bilumiere.sql'
        ];
        foreach ($sqlFiles as $relative) {
            $path = $root . '/' . $relative;
            if (!is_file($path)) throw new RuntimeException('فایل دیتابیس پیدا نشد: ' . $relative);
            foreach (sqlStatements((string) file_get_contents($path)) as $statement) $pdo->exec($statement);
        }
        $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
        $admin = $pdo->prepare('INSERT INTO users(name,phone,password_hash,role,active) VALUES(?,?,?,"admin",1) ON DUPLICATE KEY UPDATE name=VALUES(name),password_hash=VALUES(password_hash),role="admin",active=1');
        $admin->execute([$adminName, $adminPhone, $hash]);
        $setting = $pdo->prepare('INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
        foreach (['store_name'=>$storeName, 'payment_driver'=>'disabled', 'sms_enabled'=>'0', 'sms_otp_enabled'=>'0', 'sms_ready_enabled'=>'0'] as $key=>$value) $setting->execute([$key, $value]);

        $secure = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)) === 'https';
        writePhpConfig($configFile, [
            'app_env'=>'production', 'app_url'=>$appUrl,
            'db'=>['host'=>$dbHost, 'port'=>$dbPort, 'name'=>$dbName, 'user'=>$dbUser, 'pass'=>$dbPass],
            'session'=>['name'=>'noura_session', 'secure'=>$secure],
            'payment'=>['driver'=>'disabled', 'api_key'=>'', 'fee_mode'=>'merchant']
        ]);
        $marker = json_encode(['installed_at'=>gmdate('c'), 'url'=>$appUrl, 'version'=>'perfume-easy-1'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($lockFile, $marker, LOCK_EX) === false) throw new RuntimeException('ساخت قفل نصب ممکن نشد.');
        @chmod($lockFile, 0640);
        $_SESSION['installer_csrf'] = bin2hex(random_bytes(24));
        $installed = $success = true;
    } catch (Throwable $error) {
        if (!$errors) $errors[] = $error->getMessage();
        error_log('Bilumiere installer: ' . $error->getMessage());
    }
}
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نصب فروشگاه عطر bilumiere</title><style>
:root{--gold:#d5bb66;--red:#fa0f16;--cream:#f5f1e8;--black:#0b0b0b}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at 12% 10%,#4a090b,transparent 28%),var(--black);color:var(--cream);font-family:Tahoma,Arial,sans-serif;padding:36px 16px}.shell{width:min(920px,100%);margin:auto}.brand{letter-spacing:.24em;font:bold 18px Arial;color:var(--gold)}.card{margin-top:28px;background:var(--cream);color:var(--black);border-radius:28px;padding:clamp(24px,5vw,56px);box-shadow:0 30px 100px #0008}.eyebrow{font:10px Arial;letter-spacing:.18em;color:#8c7224}.head{display:flex;justify-content:space-between;gap:24px;align-items:start;border-bottom:1px solid #d8cfbc;padding-bottom:26px}.head h1{font-size:clamp(34px,6vw,64px);margin:8px 0}.head p{line-height:2;font-size:12px;max-width:530px}.step{background:var(--red);color:white;border-radius:99px;padding:9px 15px;font-size:10px;white-space:nowrap}.grid{display:grid;grid-template-columns:1fr 1fr;gap:17px;margin-top:30px}.wide{grid-column:1/-1}.section{grid-column:1/-1;margin-top:14px;padding-top:20px;border-top:1px solid #d8cfbc}.section h2{margin:0 0 5px;font-size:20px}.section p{margin:0;color:#6d675d;font-size:10px}.field{display:grid;gap:7px}.field span{font-size:10px}.field input{width:100%;border:1px solid #cabfAA;background:#fffdf8;border-radius:12px;padding:14px;font:13px Tahoma;direction:ltr;text-align:left;outline:none}.field input:focus{border-color:var(--red);box-shadow:0 0 0 3px #fa0f1614}.field.rtl input{direction:rtl;text-align:right}.submit{grid-column:1/-1;border:0;background:var(--black);color:var(--cream);border-radius:15px;padding:17px;font:bold 13px Tahoma;cursor:pointer}.submit:hover{background:var(--red)}.errors{background:#fff0f0;border:1px solid #f4b1b3;color:#8b1116;border-radius:14px;padding:14px 18px;margin:20px 0;line-height:2;font-size:11px}.done{text-align:center;padding:25px 0}.done i{display:grid;place-items:center;width:68px;height:68px;margin:auto;border-radius:50%;background:var(--gold);color:var(--black);font:32px Arial}.done h1{font-size:42px}.done p{line-height:2;color:#625b4f}.actions{display:flex;justify-content:center;gap:10px;margin-top:25px}.actions a{background:var(--black);color:white;text-decoration:none;border-radius:99px;padding:13px 22px;font-size:11px}.actions a:last-child{background:var(--red)}.note{font-size:10px;line-height:2;color:#756d60;margin-top:22px}@media(max-width:650px){body{padding:14px}.card{border-radius:18px}.head{display:block}.step{display:inline-block}.grid{grid-template-columns:1fr}.wide,.section,.submit{grid-column:auto}}
</style></head><body><main class="shell"><div class="brand">bilumiere PARFUMS</div><section class="card">
<?php if ($installed): ?>
<div class="done"><i>✓</i><h1><?= $success ? 'نصب کامل شد' : 'سایت قبلاً نصب شده' ?></h1><p>اتصال دیتابیس، جداول فروشگاه و حساب مدیر آماده‌اند.<br>برای امنیت، نصب مجدد تا زمان حذف فایل <code>api/.installed</code> غیرفعال است.</p><div class="actions"><a href="index.html">مشاهده سایت</a><a href="admin.html">ورود به مدیریت</a></div></div>
<?php else: ?>
<div class="head"><div><span class="eyebrow">EASY INSTALLER / 01</span><h1>راه‌اندازی فروشگاه</h1><p>اطلاعات دیتابیس و حساب مدیر را وارد کنید. نصب‌کننده جداول را می‌سازد، تنظیمات اتصال را ذخیره می‌کند و سایت را آماده تحویل می‌دهد.</p></div><span class="step">یک مرحله تا نصب</span></div>
<?php if ($errors): ?><div class="errors"><?php foreach ($errors as $error): ?><div>• <?= e($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" class="grid" autocomplete="off"><input type="hidden" name="csrf" value="<?= e((string) $_SESSION['installer_csrf']) ?>">
<div class="section"><h2>آدرس و دیتابیس</h2><p>دیتابیس را ابتدا از کنترل‌پنل هاست بسازید و مشخصات آن را اینجا وارد کنید.</p></div>
<label class="field wide"><span>آدرس کامل سایت</span><input name="app_url" type="url" value="<?= e((string) $values['app_url']) ?>" required></label>
<label class="field"><span>هاست دیتابیس</span><input name="db_host" value="<?= e((string) $values['db_host']) ?>" required></label>
<label class="field"><span>پورت</span><input name="db_port" inputmode="numeric" value="<?= e((string) $values['db_port']) ?>" required></label>
<label class="field"><span>نام دیتابیس</span><input name="db_name" value="<?= e((string) $values['db_name']) ?>" required></label>
<label class="field"><span>نام کاربری دیتابیس</span><input name="db_user" value="<?= e((string) $values['db_user']) ?>" required></label>
<label class="field wide"><span>رمز دیتابیس</span><input name="db_pass" type="password"></label>
<div class="section"><h2>فروشگاه و مدیر</h2><p>با این شماره موبایل و رمز می‌توانید وارد پنل مدیریت شوید.</p></div>
<label class="field rtl"><span>نام فروشگاه</span><input name="store_name" value="<?= e((string) $values['store_name']) ?>" required></label>
<label class="field rtl"><span>نام مدیر</span><input name="admin_name" value="<?= e((string) $values['admin_name']) ?>" required></label>
<label class="field"><span>موبایل مدیر</span><input name="admin_phone" inputmode="numeric" value="<?= e((string) $values['admin_phone']) ?>" placeholder="09123456789" required></label>
<label class="field"><span>رمز مدیر (حداقل ۸ کاراکتر)</span><input name="admin_password" type="password" minlength="8" maxlength="72" required></label>
<button class="submit">بررسی اتصال و نصب کامل سایت</button></form><p class="note">نصب روی دیتابیس خالی توصیه می‌شود. اطلاعات درگاه و پیامک پس از ورود از بخش تنظیمات مدیریت ثبت می‌شوند.</p>
<?php endif; ?>
</section></main></body></html>

<?php
declare(strict_types=1);
require __DIR__.'/../api/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$name=$argv[1]??'مدیر bilumiere';$phone=cleanPhone($argv[2]??'');$password=$argv[3]??'';
if(!preg_match('/^09\d{9}$/',$phone)||strlen($password)<10){fwrite(STDERR,"Usage: php scripts/create_admin.php \"Name\" 09121234567 StrongPassword\n");exit(1);}
$stmt=db()->prepare('INSERT INTO users(name,phone,password_hash,role)VALUES(?,?,?,"admin") ON DUPLICATE KEY UPDATE name=VALUES(name),password_hash=VALUES(password_hash),role="admin",active=1');
$stmt->execute([$name,$phone,password_hash($password,PASSWORD_DEFAULT)]);fwrite(STDOUT,"Admin account is ready.\n");

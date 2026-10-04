<?php
declare(strict_types=1);
final class IntegrationHttp {
    public static function post(string $url,array $payload,bool $form=false): array {
        if(!function_exists('curl_init'))throw new RuntimeException('افزونه cURL روی هاست فعال نیست.');
        $c=curl_init($url);
        curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json'),'Accept: application/json'],CURLOPT_POSTFIELDS=>$form?http_build_query($payload):json_encode($payload,JSON_UNESCAPED_UNICODE)]);
        $body=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
        if($body===false||$status===0||$status>=500)throw new RuntimeException('ارتباط با سرویس برقرار نشد؛ دوباره تلاش کنید.');
        $data=json_decode($body,true);
        if(!is_array($data))throw new RuntimeException('پاسخ سرویس معتبر نیست.');
        return $data;
    }
}

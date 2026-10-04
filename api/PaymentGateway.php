<?php
declare(strict_types=1);
require_once __DIR__.'/IntegrationHttp.php';
require_once __DIR__.'/VandarGateway.php';
final class PaymentGateway {
    private $config;
    public function __construct(array $config){$this->config=$config;}
    public function request(int $amount,string $callback,string $description,string $factor='',string $phone=''): array {
        $driver=$this->config['driver']??'disabled';
        if($driver==='vandar')return (new VandarGateway($this->config['api_key']??'',$this->config['fee_mode']??'merchant'))->request($amount,$callback,$description,$factor,$phone);
        if($driver==='sandbox'&&!empty($this->config['allow_sandbox'])){$authority='SANDBOX-'.bin2hex(random_bytes(16));return ['authority'=>$authority,'redirect'=>$callback.'?Status=OK&Authority='.urlencode($authority)];}
        throw new RuntimeException('درگاه فعال نیست؛ وندار و کلید API را در تنظیمات انتخاب کنید.');
    }
    public function verify(string $authority,int $amount,string $factor=''): array {
        if(($this->config['driver']??'')==='vandar')return (new VandarGateway($this->config['api_key']??'',$this->config['fee_mode']??'merchant'))->verify($authority,$amount,$factor);
        if(($this->config['driver']??'')==='sandbox'&&!empty($this->config['allow_sandbox']))return ['verified'=>false,'reference'=>null];
        throw new RuntimeException('درگاه ثبت‌شده برای این تراکنش فعال نیست.');
    }
}

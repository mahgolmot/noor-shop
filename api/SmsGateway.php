<?php
declare(strict_types=1);
require_once __DIR__.'/IntegrationHttp.php';
final class SmsGateway {
    private $config;private $transport;
    public function __construct(array $config,?callable $transport=null){$this->config=$config;$this->transport=$transport;}
    private function post(string $method,array $fields): array {
        if(empty($this->config['username'])||empty($this->config['password']))throw new RuntimeException('نام کاربری و APIKey ملی پیامک کامل نیست.');
        $fields['username']=$this->config['username'];$fields['password']=$this->config['password'];
        $url='https://rest.payamak-panel.com/api/SendSMS/'.$method;
        $r=$this->transport?($this->transport)($url,$fields):IntegrationHttp::post($url,$fields,true);
        if((string)($r['RetStatus']??'')!=='1'||!isset($r['Value']))throw new RuntimeException('ملی پیامک درخواست را نپذیرفت؛ اطلاعات حساب و دسترسی وب‌سرویس را بررسی کنید.');
        return $r;
    }
    public function credit(): array {
        $r=$this->post('GetCredit',[]);
        if(!is_numeric($r['Value'])||(float)$r['Value']<0)throw new RuntimeException('احراز هویت یا دریافت اعتبار ملی پیامک ناموفق است.');
        return ['credit'=>(float)$r['Value']];
    }
    private function sent(array $r): array {
        if(!preg_match('/^\d{5,}$/',(string)$r['Value']))throw new RuntimeException('ملی پیامک ارسال را رد کرد؛ کد خطا: '.preg_replace('/[^0-9-]/','',(string)$r['Value']));
        return ['rec_id'=>(string)$r['Value'],'status'=>'accepted'];
    }
    public function sendPattern(string $phone,array $values,?string $bodyId=null): array {
        $bodyId=$bodyId??($this->config['otp_body_id']??$this->config['body_id']??'');
        if(!preg_match('/^\d+$/',$bodyId)||!$values)throw new RuntimeException('شناسه پترن یا متغیرهای پیامک کامل نیست.');
        foreach($values as $v)if(strpos((string)$v,';')!==false)throw new RuntimeException('متغیر پترن معتبر نیست.');
        return $this->sent($this->post('BaseServiceNumber',['to'=>$phone,'bodyId'=>$bodyId,'text'=>implode(';',$values)]));
    }
    public function send(string $phone,string $text): array {
        if(empty($this->config['sender']))throw new RuntimeException('شماره فرستنده وارد نشده است.');
        return $this->sent($this->post('SendSMS',['to'=>$phone,'from'=>$this->config['sender'],'text'=>$text,'isFlash'=>'false']));
    }
}

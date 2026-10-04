<?php
declare(strict_types=1);
final class VandarGateway {
    private $key;private $feeMode;private $transport;
    public function __construct(string $key,string $feeMode='merchant',?callable $transport=null){$this->key=$key;$this->feeMode=$feeMode;$this->transport=$transport;}
    private function post(string $method,array $data): array {
        if($this->key==='')throw new RuntimeException('کلید API وندار ثبت نشده است.');
        $data['api_key']=$this->key;$url='https://ipg.vandar.io/api/v3/'.$method;
        return $this->transport?($this->transport)($url,$data):IntegrationHttp::post($url,$data);
    }
    public function request(int $toman,string $callback,string $description,string $factor,string $phone=''): array {
        if($toman<100||$toman>intdiv(PHP_INT_MAX,10))throw new DomainException('مبلغ پرداخت وندار معتبر نیست.');
        if(strpos($callback,'https://')!==0)throw new DomainException('آدرس بازگشت درگاه باید HTTPS باشد.');
        $r=$this->post('send',['amount'=>$toman*10,'callback_url'=>$callback,'description'=>$description,'factorNumber'=>$factor,'mobile_number'=>$phone]);
        if((string)($r['status']??'')!=='1'||!is_string($r['token']??null)||!preg_match('/^[a-zA-Z0-9_-]{1,120}$/',$r['token']))throw new RuntimeException('وندار درخواست پرداخت را نپذیرفت؛ کلید API، دامنه و IP هاست را در پنل وندار بررسی کنید.');
        return ['authority'=>$r['token'],'redirect'=>'https://ipg.vandar.io/v3/'.rawurlencode($r['token'])];
    }
    private function check(array $r,int $toman,string $factor,bool $preflight=false): array {
        foreach(['amount','wage'] as $field)if(isset($r[$field])&&!preg_match('/^\d+(?:\.0+)?$/',(string)$r[$field]))throw new RuntimeException('مبلغ پاسخ وندار معتبر نیست.');
        $amount=(int)($r['amount']??0);
        if($this->feeMode==='payer'&&!$preflight&&!isset($r['wage']))throw new RuntimeException('جزئیات کارمزد برای تطبیق قطعی مبلغ موجود نیست.');
        if($this->feeMode==='payer'&&!$preflight)$amount-=(int)$r['wage'];
        $validAmount=$this->feeMode==='payer'&&$preflight?$amount>=$toman*10:$amount===$toman*10;
        if(!$validAmount||(string)($r['factorNumber']??'')!==$factor||empty($r['transId']))throw new RuntimeException('مبلغ یا شماره سفارش پاسخ وندار با سفارش مطابقت ندارد.');
        return ['verified'=>true,'reference'=>(string)$r['transId']];
    }
    public function verify(string $token,int $toman,string $factor): array {
        $info=$this->post('transaction',['token'=>$token]);
        if((string)($info['status']??'')!=='1')throw new RuntimeException('استعلام تراکنش وندار ناموفق بود؛ دوباره بررسی کنید.');
        $code=(string)($info['code']??'');
        if($code==='2')return $this->check($info,$toman,$factor);
        if(in_array($code,['3','4'],true))return ['verified'=>false,'reference'=>null];
        if($code!=='1')throw new RuntimeException('تراکنش هنوز قابل تأیید نیست.');
        $this->check($info,$toman,$factor,true);
        $r=$this->post('verify',['token'=>$token]);
        if((string)($r['status']??'')==='1')return $this->check($r,$toman,$factor);
        if((string)($r['status']??'')==='2'){$again=$this->post('transaction',['token'=>$token]);if((string)($again['status']??'')==='1'&&(string)($again['code']??'')==='2')return $this->check($again,$toman,$factor);}
        if((string)($r['status']??'')==='3')return ['verified'=>false,'reference'=>null];
        throw new RuntimeException('تأیید وندار ناموفق بود؛ نتیجه قطعی هنوز ثبت نشده است.');
    }
}

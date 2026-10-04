<?php
declare(strict_types=1);
final class OtpPolicy {
    public static function allowed(array $challenge,int $now,string $session): bool {
        return !(int)($challenge['consumed']??1)&&(int)($challenge['expires']??0)>=$now&&(int)($challenge['attempts']??5)<5&&hash_equals((string)($challenge['session_hash']??''),hash('sha256',$session));
    }
    public static function matches(string $code,string $hash): bool {return preg_match('/^\d{6}$/',$code)===1&&password_verify($code,$hash);}
    public static function throttled(int $hits,int $max,int $last,int $now,int $cooldown): bool {return $hits>=$max||$now-$last<$cooldown;}
}

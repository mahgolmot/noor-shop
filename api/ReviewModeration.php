<?php
declare(strict_types=1);
function reviewModerationTables(): void {db()->exec('CREATE TABLE IF NOT EXISTS review_rejections(review_id BIGINT UNSIGNED PRIMARY KEY,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');}
function reviewModerationRoutes(string $path,string $method): void {
    if($path==='/auth/register'&&$method==='POST'){requireCsrf();fail('ساخت حساب فقط پس از تأیید کد پیامک انجام می‌شود.',409);}
    if(preg_match('#^/products/(\\d+)/extras$#',$path)&&$method==='GET'){reviewModerationTables();return;}
    if(preg_match('#^/products/(\\d+)/reviews$#',$path,$m)&&$method==='POST'){
        requireCsrf();$u=requireUser();reviewModerationTables();$q=db()->prepare('SELECT r.id FROM reviews r JOIN review_rejections x ON x.review_id=r.id WHERE r.user_id=? AND r.product_id=?');$q->execute([$u['id'],(int)$m[1]]);if($q->fetchColumn())fail('این دیدگاه رد شده است و قابل ویرایش یا انتشار دوباره نیست.',409);return;
    }
    if($path==='/admin/reviews'&&$method==='GET'){
        requireUser('admin');reviewModerationTables();$q=db()->query('SELECT r.id,r.rating,r.body,r.approved,r.created_at,u.name customer,p.name product,p.id product_id,EXISTS(SELECT 1 FROM review_rejections x WHERE x.review_id=r.id) rejected FROM reviews r JOIN users u ON u.id=r.user_id JOIN products p ON p.id=r.product_id ORDER BY r.approved ASC,r.created_at DESC,r.id DESC');respond(['ok'=>true,'reviews'=>$q->fetchAll()]);
    }
    if(!preg_match('#^/admin/reviews/(\\d+)(/reject)?$#',$path,$m)||!in_array($method,['PATCH','POST','DELETE'],true))return;
    $reject=isset($m[2])&&$m[2]==='/reject';if($reject&&$method!=='POST'||!$reject&&!in_array($method,['PATCH','DELETE'],true))return;
    requireCsrf();requireUser('admin');reviewModerationTables();$id=(int)$m[1];$d=jsonBody();if($method==='PATCH'&&(!array_key_exists('approved',$d)||!is_bool($d['approved'])))fail('وضعیت انتشار نامعتبر است.',422);
    $pdo=db();$pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT id FROM reviews WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetchColumn()){$pdo->rollBack();fail('دیدگاه پیدا نشد.',404);}
        if($method==='DELETE'){$pdo->prepare('DELETE FROM review_rejections WHERE review_id=?')->execute([$id]);$pdo->prepare('DELETE FROM reviews WHERE id=?')->execute([$id]);$action='review_deleted';}
        elseif($reject){$pdo->prepare('INSERT IGNORE INTO review_rejections(review_id) VALUES(?)')->execute([$id]);$pdo->prepare('UPDATE reviews SET approved=0 WHERE id=?')->execute([$id]);$action='review_rejected';}
        else{$q=$pdo->prepare('SELECT review_id FROM review_rejections WHERE review_id=?');$q->execute([$id]);if($d['approved']&&$q->fetchColumn()){$pdo->rollBack();fail('دیدگاه ردشده قابل پذیرش دوباره نیست.',409);}$pdo->prepare('UPDATE reviews SET approved=? WHERE id=?')->execute([$d['approved']?1:0,$id]);$action='review_moderated';}
        $pdo->commit();audit($action,'review',$id);respond(['ok'=>true]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

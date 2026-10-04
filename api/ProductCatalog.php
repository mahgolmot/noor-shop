<?php
declare(strict_types=1);

function applyBilumiereRebrand(PDO $pdo): void {
    $check=$pdo->prepare('SELECT `value` FROM settings WHERE `key`="brand_rebrand_bilumiere_v1" LIMIT 1');$check->execute();
    if($check->fetchColumn()==='1')return;
    $pdo->beginTransaction();
    try{
        $pdo->exec('UPDATE settings SET `value`="bilumiere" WHERE `key`="store_name" AND LOWER(`value`)="noura"');
        $pdo->exec('UPDATE products SET brand="bilumiere" WHERE LOWER(brand)="noura"');
        $old=$pdo->query('SELECT id FROM catalog_brands WHERE LOWER(name)="noura" LIMIT 1')->fetchColumn();
        if($old){
            $new=$pdo->query('SELECT id FROM catalog_brands WHERE LOWER(name)="bilumiere" LIMIT 1')->fetchColumn();
            if($new&&$new!==$old){
                $move=$pdo->prepare('INSERT IGNORE INTO product_catalog_brand(product_id,brand_id) SELECT product_id,? FROM product_catalog_brand WHERE brand_id=?');$move->execute([(int)$new,(int)$old]);
                $pdo->prepare('DELETE FROM product_catalog_brand WHERE brand_id=?')->execute([(int)$old]);
                $pdo->prepare('DELETE FROM catalog_brands WHERE id=?')->execute([(int)$old]);
            }else{
                $rename=$pdo->prepare('UPDATE catalog_brands SET name="bilumiere",slug="bilumiere" WHERE id=?');$rename->execute([(int)$old]);
            }
        }
        $pdo->prepare('INSERT INTO settings(`key`,`value`) VALUES("brand_rebrand_bilumiere_v1","1") ON DUPLICATE KEY UPDATE `value`="1"')->execute();
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function productCatalogTables(): void {
    $pdo=db();
    foreach([
        'CREATE TABLE IF NOT EXISTS catalog_brands(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,slug VARCHAR(150) NOT NULL UNIQUE,description TEXT,active TINYINT NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(name)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS catalog_categories(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,parent_id BIGINT UNSIGNED NULL,name VARCHAR(120) NOT NULL,slug VARCHAR(150) NOT NULL UNIQUE,description TEXT,sort_order INT NOT NULL DEFAULT 0,active TINYINT NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,FOREIGN KEY(parent_id) REFERENCES catalog_categories(id) ON DELETE SET NULL,INDEX(parent_id,sort_order),INDEX(name)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS catalog_tags(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,slug VARCHAR(130) NOT NULL UNIQUE,description TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(name)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS catalog_attributes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,slug VARCHAR(130) NOT NULL UNIQUE,input_type ENUM("select","text") NOT NULL DEFAULT "select",sort_order INT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(sort_order,name)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS catalog_attribute_values(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,attribute_id BIGINT UNSIGNED NOT NULL,value VARCHAR(120) NOT NULL,slug VARCHAR(150) NOT NULL,sort_order INT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY(attribute_id,slug),FOREIGN KEY(attribute_id) REFERENCES catalog_attributes(id) ON DELETE CASCADE,INDEX(attribute_id,sort_order)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS product_catalog_brand(product_id BIGINT UNSIGNED PRIMARY KEY,brand_id BIGINT UNSIGNED NOT NULL,FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,FOREIGN KEY(brand_id) REFERENCES catalog_brands(id) ON DELETE CASCADE,INDEX(brand_id)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS product_catalog_categories(product_id BIGINT UNSIGNED NOT NULL,category_id BIGINT UNSIGNED NOT NULL,is_primary TINYINT NOT NULL DEFAULT 0,PRIMARY KEY(product_id,category_id),FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,FOREIGN KEY(category_id) REFERENCES catalog_categories(id) ON DELETE CASCADE,INDEX(category_id),INDEX(product_id,is_primary)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS product_catalog_tags(product_id BIGINT UNSIGNED NOT NULL,tag_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(product_id,tag_id),FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,FOREIGN KEY(tag_id) REFERENCES catalog_tags(id) ON DELETE CASCADE,INDEX(tag_id)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS product_catalog_attributes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_id BIGINT UNSIGNED NOT NULL,attribute_id BIGINT UNSIGNED NOT NULL,value_id BIGINT UNSIGNED NULL,custom_value VARCHAR(180) NULL,visible TINYINT NOT NULL DEFAULT 1,sort_order INT NOT NULL DEFAULT 0,FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,FOREIGN KEY(attribute_id) REFERENCES catalog_attributes(id) ON DELETE CASCADE,FOREIGN KEY(value_id) REFERENCES catalog_attribute_values(id) ON DELETE CASCADE,INDEX(product_id,sort_order),INDEX(attribute_id),INDEX(value_id)) ENGINE=InnoDB'
    ] as $sql)$pdo->exec($sql);
    applyBilumiereRebrand($pdo);
    $check=$pdo->prepare('SELECT `value` FROM settings WHERE `key`="product_catalog_legacy_imported" LIMIT 1');$check->execute();
    if($check->fetchColumn()!=='1'){
        $existing=(int)$pdo->query('SELECT (SELECT COUNT(*) FROM catalog_categories)+(SELECT COUNT(*) FROM catalog_brands)+(SELECT COUNT(*) FROM product_catalog_categories)+(SELECT COUNT(*) FROM product_catalog_brand)')->fetchColumn();
        if($existing>0){$pdo->prepare('INSERT INTO settings(`key`,`value`) VALUES("product_catalog_legacy_imported","1") ON DUPLICATE KEY UPDATE `value`="1"')->execute();return;}
        $defaults=['women'=>'عطر زنانه','men'=>'عطر مردانه','unisex'=>'عطر یونی‌سکس','niche'=>'عطر نیش'];
        $insert=$pdo->prepare('INSERT IGNORE INTO catalog_categories(name,slug,sort_order) VALUES(?,?,?)');$order=0;
        foreach($defaults as $slug=>$name)$insert->execute([$name,$slug,$order++]);
        $pdo->exec('INSERT IGNORE INTO catalog_brands(name,slug) SELECT DISTINCT brand,LOWER(REPLACE(TRIM(brand)," ","-")) FROM products WHERE brand IS NOT NULL AND TRIM(brand)<>""');
        $pdo->exec('INSERT IGNORE INTO product_catalog_brand(product_id,brand_id) SELECT p.id,b.id FROM products p JOIN catalog_brands b ON b.name=p.brand WHERE p.brand IS NOT NULL AND TRIM(p.brand)<>""');
        $pdo->exec('INSERT IGNORE INTO product_catalog_categories(product_id,category_id,is_primary) SELECT p.id,c.id,1 FROM products p JOIN catalog_categories c ON c.slug=p.category');
        $pdo->prepare('INSERT INTO settings(`key`,`value`) VALUES("product_catalog_legacy_imported","1") ON DUPLICATE KEY UPDATE `value`="1"')->execute();
    }
}
function catalogSlug(string $value): string {
    $value=mb_strtolower(trim($value));$value=preg_replace('/[^\p{L}\p{N}]+/u','-',$value)??'';$value=trim($value,'-');
    return mb_substr($value,0,140);
}
function catalogText(array $d,string $key,int $min=1,int $max=120): string {
    $value=trim((string)($d[$key]??''));if(mb_strlen($value)<$min||mb_strlen($value)>$max)throw new DomainException('مقدار «'.$key.'» معتبر نیست.');return $value;
}
function catalogIds($value): array {
    if(!is_array($value))return [];$ids=[];foreach($value as $id){$id=(int)$id;if($id>0)$ids[$id]=$id;}return array_values($ids);
}
function updateCatalogBrand(int $id,string $name,string $slug,string $description,int $active): void {
    $pdo=db();$pdo->beginTransaction();try{$q=$pdo->prepare('UPDATE catalog_brands SET name=?,slug=?,description=?,active=? WHERE id=?');$q->execute([$name,$slug,$description,$active,$id]);if(!$q->rowCount()){ $exists=$pdo->prepare('SELECT id FROM catalog_brands WHERE id=?');$exists->execute([$id]);if(!$exists->fetchColumn())throw new DomainException('برند پیدا نشد.');}$pdo->prepare('UPDATE products p JOIN product_catalog_brand pb ON pb.product_id=p.id SET p.brand=? WHERE pb.brand_id=?')->execute([$name,$id]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function deleteCatalogBrand(int $id): void {
    $pdo=db();$pdo->beginTransaction();try{$pdo->prepare('UPDATE products p JOIN product_catalog_brand pb ON pb.product_id=p.id SET p.brand="" WHERE pb.brand_id=?')->execute([$id]);$pdo->prepare('DELETE FROM catalog_brands WHERE id=?')->execute([$id]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function catalogSnapshot(bool $admin=true): array {
    productCatalogTables();$pdo=db();$active=$admin?'':' WHERE active=1';
    $brands=$pdo->query('SELECT b.*,COUNT(pb.product_id) product_count FROM catalog_brands b LEFT JOIN product_catalog_brand pb ON pb.brand_id=b.id'.$active.' GROUP BY b.id ORDER BY b.name')->fetchAll();
    $categories=$pdo->query('SELECT c.*,p.name parent_name,COUNT(pc.product_id) product_count FROM catalog_categories c LEFT JOIN catalog_categories p ON p.id=c.parent_id LEFT JOIN product_catalog_categories pc ON pc.category_id=c.id'.($admin?'':' WHERE c.active=1').' GROUP BY c.id ORDER BY c.sort_order,c.name')->fetchAll();
    $tags=$pdo->query('SELECT t.*,COUNT(pt.product_id) product_count FROM catalog_tags t LEFT JOIN product_catalog_tags pt ON pt.tag_id=t.id GROUP BY t.id ORDER BY t.name')->fetchAll();
    $attributes=$pdo->query('SELECT a.*,COUNT(DISTINCT pa.product_id) product_count FROM catalog_attributes a LEFT JOIN product_catalog_attributes pa ON pa.attribute_id=a.id GROUP BY a.id ORDER BY a.sort_order,a.name')->fetchAll();
    $values=$pdo->query('SELECT v.*,COUNT(pa.id) product_count FROM catalog_attribute_values v LEFT JOIN product_catalog_attributes pa ON pa.value_id=v.id GROUP BY v.id ORDER BY v.attribute_id,v.sort_order,v.value')->fetchAll();$by=[];foreach($values as $v)$by[(int)$v['attribute_id']][]=$v;foreach($attributes as &$a)$a['values']=$by[(int)$a['id']]??[];unset($a);
    return ['brands'=>$brands,'categories'=>$categories,'tags'=>$tags,'attributes'=>$attributes];
}
function productCatalogData(int $id): array {
    productCatalogTables();$pdo=db();$q=$pdo->prepare('SELECT brand_id FROM product_catalog_brand WHERE product_id=?');$q->execute([$id]);$brand=$q->fetchColumn();
    $q=$pdo->prepare('SELECT category_id,is_primary FROM product_catalog_categories WHERE product_id=? ORDER BY is_primary DESC,category_id');$q->execute([$id]);$categories=$q->fetchAll();
    $q=$pdo->prepare('SELECT tag_id FROM product_catalog_tags WHERE product_id=? ORDER BY tag_id');$q->execute([$id]);$tags=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $q=$pdo->prepare('SELECT pa.id,pa.attribute_id,pa.value_id,pa.custom_value,pa.visible,a.name attribute_name,a.input_type,v.value FROM product_catalog_attributes pa JOIN catalog_attributes a ON a.id=pa.attribute_id LEFT JOIN catalog_attribute_values v ON v.id=pa.value_id WHERE pa.product_id=? ORDER BY pa.sort_order,pa.id');$q->execute([$id]);
    return ['brand_id'=>$brand?(int)$brand:null,'category_ids'=>array_map(function($r){return (int)$r['category_id'];},$categories),'primary_category_id'=>$categories?(int)$categories[0]['category_id']:null,'tag_ids'=>$tags,'attributes'=>$q->fetchAll()];
}
function saveProductCatalog(int $id,array $d): void {
    productCatalogTables();$pdo=db();$exists=$pdo->prepare('SELECT id FROM products WHERE id=?');$exists->execute([$id]);if(!$exists->fetchColumn())throw new DomainException('محصول پیدا نشد.');
    $brand=(int)($d['brand_id']??0);$categories=catalogIds($d['category_ids']??[]);$primary=(int)($d['primary_category_id']??0);$tags=catalogIds($d['tag_ids']??[]);$attrs=is_array($d['attributes']??null)?$d['attributes']:[];
    if($primary&&!in_array($primary,$categories,true))$categories[]=$primary;
    $pdo->beginTransaction();try{
        $pdo->prepare('DELETE FROM product_catalog_brand WHERE product_id=?')->execute([$id]);if($brand){$q=$pdo->prepare('SELECT name FROM catalog_brands WHERE id=? AND active=1');$q->execute([$brand]);$name=$q->fetchColumn();if(!$name)throw new DomainException('برند انتخاب شده معتبر نیست.');$pdo->prepare('INSERT INTO product_catalog_brand(product_id,brand_id) VALUES(?,?)')->execute([$id,$brand]);$pdo->prepare('UPDATE products SET brand=? WHERE id=?')->execute([$name,$id]);}
        $pdo->prepare('DELETE FROM product_catalog_categories WHERE product_id=?')->execute([$id]);$legacy=null;foreach($categories as $category){$q=$pdo->prepare('SELECT slug FROM catalog_categories WHERE id=? AND active=1');$q->execute([$category]);$slug=$q->fetchColumn();if(!$slug)throw new DomainException('یکی از دسته بندی ها معتبر نیست.');$isPrimary=$category===$primary||(!$primary&&$legacy===null);$pdo->prepare('INSERT INTO product_catalog_categories(product_id,category_id,is_primary) VALUES(?,?,?)')->execute([$id,$category,$isPrimary?1:0]);if($isPrimary&&in_array($slug,['women','men','unisex','niche'],true))$legacy=$slug;}
        if($legacy)$pdo->prepare('UPDATE products SET category=? WHERE id=?')->execute([$legacy,$id]);
        $pdo->prepare('DELETE FROM product_catalog_tags WHERE product_id=?')->execute([$id]);foreach($tags as $tag)$pdo->prepare('INSERT INTO product_catalog_tags(product_id,tag_id) SELECT ?,id FROM catalog_tags WHERE id=?')->execute([$id,$tag]);
        $pdo->prepare('DELETE FROM product_catalog_attributes WHERE product_id=?')->execute([$id]);$sort=0;foreach($attrs as $row){if(!is_array($row))continue;$attribute=(int)($row['attribute_id']??0);$value=(int)($row['value_id']??0);$custom=trim((string)($row['custom_value']??''));if(!$attribute||(!$value&&$custom===''))continue;$q=$pdo->prepare('SELECT input_type FROM catalog_attributes WHERE id=?');$q->execute([$attribute]);$type=$q->fetchColumn();if(!$type)throw new DomainException('یکی از ویژگی ها معتبر نیست.');if($value){$q=$pdo->prepare('SELECT id FROM catalog_attribute_values WHERE id=? AND attribute_id=?');$q->execute([$value,$attribute]);if(!$q->fetchColumn())throw new DomainException('مقدار ویژگی با ویژگی انتخاب شده هماهنگ نیست.');}$pdo->prepare('INSERT INTO product_catalog_attributes(product_id,attribute_id,value_id,custom_value,visible,sort_order) VALUES(?,?,?,?,?,?)')->execute([$id,$attribute,$value?:null,$value?null:mb_substr($custom,0,180),!empty($row['visible'])?1:0,$sort++]);}
        $pdo->commit();audit('product_catalog_updated','product',$id,['brand_id'=>$brand,'categories'=>$categories,'tags'=>$tags]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function productCatalogRoutes(string $path,string $method): void {
    if($path==='/catalog'&&$method==='GET')respond(['ok'=>true,'catalog'=>catalogSnapshot(false)]);
    if(preg_match('#^/products/(\d+)/catalog$#',$path,$m)&&$method==='GET')respond(['ok'=>true,'catalog'=>productCatalogData((int)$m[1])]);
    if($path==='/admin/catalog'&&$method==='GET'){requireUser('admin');respond(['ok'=>true,'catalog'=>catalogSnapshot(true)]);}
    if(preg_match('#^/admin/products/(\d+)/catalog$#',$path,$m)&&$method==='GET'){requireUser('admin');respond(['ok'=>true,'catalog'=>productCatalogData((int)$m[1])]);}
    if(preg_match('#^/admin/products/(\d+)/catalog$#',$path,$m)&&$method==='PATCH'){requireCsrf();requireUser('admin');try{saveProductCatalog((int)$m[1],jsonBody());respond(['ok'=>true]);}catch(DomainException $e){fail($e->getMessage(),422);}}
    if(preg_match('#^/admin/catalog/(brands|categories|tags|attributes)$#',$path,$m)&&$method==='POST'){
        requireCsrf();requireUser('admin');productCatalogTables();$d=jsonBody();try{$name=catalogText($d,'name',2,120);$slug=catalogSlug((string)($d['slug']??$name));if($slug==='')throw new DomainException('نامک معتبر نیست.');$type=$m[1];$pdo=db();
            if($type==='brands'){$q=$pdo->prepare('INSERT INTO catalog_brands(name,slug,description,active) VALUES(?,?,?,?)');$q->execute([$name,$slug,trim((string)($d['description']??'')),!isset($d['active'])||$d['active']?1:0]);}
            elseif($type==='categories'){$parent=(int)($d['parent_id']??0);$q=$pdo->prepare('INSERT INTO catalog_categories(parent_id,name,slug,description,sort_order,active) VALUES(?,?,?,?,?,?)');$q->execute([$parent?:null,$name,$slug,trim((string)($d['description']??'')),(int)($d['sort_order']??0),!isset($d['active'])||$d['active']?1:0]);}
            elseif($type==='tags'){$q=$pdo->prepare('INSERT INTO catalog_tags(name,slug,description) VALUES(?,?,?)');$q->execute([$name,$slug,trim((string)($d['description']??''))]);}
            else{$input=in_array(($d['input_type']??'select'),['select','text'],true)?$d['input_type']:'select';$q=$pdo->prepare('INSERT INTO catalog_attributes(name,slug,input_type,sort_order) VALUES(?,?,?,?)');$q->execute([$name,$slug,$input,(int)($d['sort_order']??0)]);}
            $id=(int)$pdo->lastInsertId();audit('catalog_created',$type,$id);respond(['ok'=>true,'id'=>$id],201);
        }catch(DomainException $e){fail($e->getMessage(),422);}catch(PDOException $e){if($e->getCode()==='23000')fail('نامک تکراری است یا والد انتخاب شده معتبر نیست.',409);throw $e;}}
    if(preg_match('#^/admin/catalog/(brands|categories|tags|attributes)/(\d+)$#',$path,$m)&&$method==='PATCH'){
        requireCsrf();requireUser('admin');productCatalogTables();$d=jsonBody();try{$name=catalogText($d,'name',2,120);$slug=catalogSlug((string)($d['slug']??$name));$type=$m[1];$id=(int)$m[2];$pdo=db();
            if($type==='brands')updateCatalogBrand($id,$name,$slug,trim((string)($d['description']??'')),!isset($d['active'])||$d['active']?1:0);
            elseif($type==='categories'){$parent=(int)($d['parent_id']??0);if($parent===$id)throw new DomainException('یک دسته نمی تواند والد خودش باشد.');$pdo->prepare('UPDATE catalog_categories SET parent_id=?,name=?,slug=?,description=?,sort_order=?,active=? WHERE id=?')->execute([$parent?:null,$name,$slug,trim((string)($d['description']??'')),(int)($d['sort_order']??0),!isset($d['active'])||$d['active']?1:0,$id]);}
            elseif($type==='tags')$pdo->prepare('UPDATE catalog_tags SET name=?,slug=?,description=? WHERE id=?')->execute([$name,$slug,trim((string)($d['description']??'')),$id]);
            else{$input=in_array(($d['input_type']??'select'),['select','text'],true)?$d['input_type']:'select';$pdo->prepare('UPDATE catalog_attributes SET name=?,slug=?,input_type=?,sort_order=? WHERE id=?')->execute([$name,$slug,$input,(int)($d['sort_order']??0),$id]);}
            audit('catalog_updated',$type,$id);respond(['ok'=>true]);
        }catch(DomainException $e){fail($e->getMessage(),422);}catch(PDOException $e){if($e->getCode()==='23000')fail('نامک تکراری است یا والد انتخاب شده معتبر نیست.',409);throw $e;}}
    if(preg_match('#^/admin/catalog/(brands|categories|tags|attributes)/(\d+)$#',$path,$m)&&$method==='DELETE'){requireCsrf();requireUser('admin');productCatalogTables();$tables=['brands'=>'catalog_brands','categories'=>'catalog_categories','tags'=>'catalog_tags','attributes'=>'catalog_attributes'];$id=(int)$m[2];$pdo=db();if($m[1]==='brands')deleteCatalogBrand($id);else $pdo->prepare('DELETE FROM '.$tables[$m[1]].' WHERE id=?')->execute([$id]);audit('catalog_deleted',$m[1],$id);respond(['ok'=>true]);}
    if(preg_match('#^/admin/catalog/attributes/(\d+)/values$#',$path,$m)&&$method==='POST'){requireCsrf();requireUser('admin');productCatalogTables();$d=jsonBody();try{$value=catalogText($d,'value',1,120);$slug=catalogSlug((string)($d['slug']??$value));$q=db()->prepare('INSERT INTO catalog_attribute_values(attribute_id,value,slug,sort_order) VALUES(?,?,?,?)');$q->execute([(int)$m[1],$value,$slug,(int)($d['sort_order']??0)]);respond(['ok'=>true,'id'=>(int)db()->lastInsertId()],201);}catch(DomainException $e){fail($e->getMessage(),422);}catch(PDOException $e){if($e->getCode()==='23000')fail('این مقدار تکراری است.',409);throw $e;}}
    if(preg_match('#^/admin/catalog/attribute-values/(\d+)$#',$path,$m)&&$method==='PATCH'){requireCsrf();requireUser('admin');$d=jsonBody();try{$value=catalogText($d,'value',1,120);db()->prepare('UPDATE catalog_attribute_values SET value=?,slug=?,sort_order=? WHERE id=?')->execute([$value,catalogSlug((string)($d['slug']??$value)),(int)($d['sort_order']??0),(int)$m[1]]);respond(['ok'=>true]);}catch(DomainException $e){fail($e->getMessage(),422);}}
    if(preg_match('#^/admin/catalog/attribute-values/(\d+)$#',$path,$m)&&$method==='DELETE'){requireCsrf();requireUser('admin');db()->prepare('DELETE FROM catalog_attribute_values WHERE id=?')->execute([(int)$m[1]]);respond(['ok'=>true]);}
}

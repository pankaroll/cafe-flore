<?php
declare(strict_types=1);
namespace CafeFlore;
use PDO;
use Throwable;

final class Installer {
    public function __construct(public string $privatePath){}
    public function installed():bool{return is_file($this->privatePath.'/config.php')||is_file($this->privatePath.'/installed.lock');}
    public static function canonicalUrl(string $value):string{
        $value=rtrim(trim($value),'/');$p=parse_url($value);
        if(!$p||!isset($p['scheme'],$p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!in_array($p['scheme'],['http','https'],true))throw new ApiError(400,'Wpisz pełny adres aplikacji, np. https://zadania.twojadomena.pl.');
        if($p['scheme']!=='https'&&!in_array($p['host'],['localhost','127.0.0.1'],true))throw new ApiError(400,'Dla domeny aplikacji wymagany jest adres HTTPS. Włącz SSL w OVHcloud.');
        if(isset($p['path'])&&!preg_match('#^(/[a-zA-Z0-9._~-]+)*$#D',$p['path']))throw new ApiError(400,'Nieprawidłowa ścieżka adresu aplikacji.');
        return $value;
    }
    public function validatePrivatePath():void{
        $private=realpath($this->privatePath);$root=realpath($_SERVER['DOCUMENT_ROOT']??'');
        if(!$private||!$root||$private===$root||str_starts_with($private,$root.DIRECTORY_SEPARATOR))throw new ApiError(400,'Katalog private musi znajdować się poza katalogiem publicznym domeny. Ustaw katalog domeny na cafe-flore/public.');
        if(!is_writable($this->privatePath))throw new ApiError(400,'Katalog private musi być zapisywalny przez PHP podczas instalacji.');
        foreach(['pdo_mysql','openssl','mbstring'] as $extension)if(!extension_loaded($extension))throw new ApiError(400,'Na hostingu brakuje rozszerzenia PHP: '.$extension);
    }
    public function verifyKey(string $key):void{
        $expected=trim((string)@file_get_contents($this->privatePath.'/install-key.txt'));
        if(strlen($expected)<20||!hash_equals($expected,trim($key)))throw new ApiError(403,'Nieprawidłowy kod instalacyjny. Skopiuj go z private/install-key.txt.');
    }
    public function decrypt(string $password):array{
        $path=$this->privatePath.'/migration.enc.json';
        if(!is_file($path))throw new ApiError(400,'Brak pliku migration.enc.json w katalogu private.');
        $encrypted=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if(($encrypted['format']??'')!=='cafe-flore-encrypted'||($encrypted['version']??0)!==1||($encrypted['iterations']??0)!==210000)throw new ApiError(400,'Nieobsługiwany format kopii.');
        $salt=base64_decode((string)($encrypted['salt']??''),true);$iv=base64_decode((string)($encrypted['iv']??''),true);$tag=base64_decode((string)($encrypted['tag']??''),true);$ciphertext=base64_decode((string)($encrypted['ciphertext']??''),true);
        if($salt===false||strlen($salt)!==16||$iv===false||strlen($iv)!==12||$tag===false||strlen($tag)!==16||$ciphertext===false)throw new ApiError(400,'Uszkodzony plik kopii danych.');
        $key=hash_pbkdf2('sha256',$password,$salt,210000,32,true);
        $json=openssl_decrypt($ciphertext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'cafe-flore-migration-v1');
        if($json===false)throw new ApiError(400,'Nieprawidłowe hasło kopii danych.');
        $data=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        if(($data['format']??'')!=='cafe-flore-data'||($data['version']??0)!==1||!is_array($data['tables']??null)||!is_string($data['pin_pepper']??null)||strlen($data['pin_pepper'])<32)throw new ApiError(400,'Kopia nie zawiera kompletnych danych migracji.');
        return $data;
    }
    private function schema(PDO $db):void{
        $sql=(string)file_get_contents($this->privatePath.'/schema.sql');
        foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement)$db->exec($statement);
        foreach(App::TABLES as $table)if((int)$db->query('SELECT COUNT(*) FROM flore_'.$table)->fetchColumn()>0)throw new ApiError(409,'Ta baza zawiera już dane Cafe Flore. Instalator nie nadpisuje istniejącej instalacji.');
    }
    private function import(App $app,array $backup):array{
        $columns=[
            'members'=>['id','name','role','pin_hash','active','created_at'],
            'settings'=>['id','cafe_name'],
            'routines'=>['id','title','description','time','category','priority','assignee_id','weekdays','starts_on','ends_on','active','version','created_by','created_at','updated_at','checklist_meta'],
            'routine_rules'=>['id','routine_id','title','description','time','category','priority','assignee_id','weekdays','starts_on','ends_on','active','valid_from','valid_until','created_by','created_at','checklist_meta'],
            'tasks'=>['id','title','description','day','time','category','priority','assignee_id','status','completed_by','completed_at','note','version','created_by','created_at','deleted','recurrence_id','rule_id','checklist_meta','values_json'],
            'events'=>['id','task_id','actor_id','action','title','note','created_at'],
        ];
        $counts=[];$hasAdmin=false;
        foreach($columns as $table=>$fields){
            $rows=$backup['tables'][$table]??null;if(!is_array($rows)||count($rows)>1000000)throw new ApiError(400,'Brak tabeli w kopii: '.$table);
            $sql='INSERT INTO flore_'.$table.'('.implode(',',$fields).') VALUES('.implode(',',array_fill(0,count($fields),'?')).')';
            foreach($rows as $row){
                if(!is_array($row))throw new ApiError(400,'Uszkodzony rekord w kopii.');
                if($table==='members'){
                    if(!in_array($row['role']??'',['admin','employee'],true)||!preg_match('/^[a-f0-9]{64}$/D',(string)($row['pin_hash']??'')))throw new ApiError(400,'Uszkodzone konto w kopii danych.');
                    if($row['role']==='admin'&&!empty($row['active']))$hasAdmin=true;
                }
                $args=[];foreach($fields as $field){if(!array_key_exists($field,$row))throw new ApiError(400,'Niekompletny rekord '.$table.'.'.$field);$args[]=$row[$field];}
                $app->run($sql,$args);
            }$counts[$table]=count($rows);
        }
        if(!$hasAdmin)throw new ApiError(400,'Kopia nie zawiera aktywnego właściciela.');
        $settings=$app->one('SELECT cafe_name FROM flore_settings WHERE id=1');if(!$settings)throw new ApiError(400,'Brak ustawień kawiarni.');
        return $counts;
    }
    public function install(array $data):array{
        if($this->installed())throw new ApiError(409,'Aplikacja jest już zainstalowana.');
        $this->validatePrivatePath();$this->verifyKey((string)($data['install_key']??''));
        $lock=fopen($this->privatePath.'/.install-running','c');if(!$lock||!flock($lock,LOCK_EX))throw new ApiError(503,'Nie można zablokować instalacji.');
        try{
            if($this->installed())throw new ApiError(409,'Aplikacja jest już zainstalowana.');
            $backup=null;$ownerName=null;$ownerPin=null;$mode=$data['mode']??'migrate';
            if(!in_array($mode,['migrate','fresh'],true))throw new ApiError(400,'Wybierz tryb instalacji.');
            if($mode==='migrate')$backup=$this->decrypt((string)($data['backup_password']??''));
            else{$ownerName=App::text($data['owner_name']??'Właściciel','nazwa właściciela',80);$ownerPin=App::pin($data['owner_pin']??null,true);}
            $config=['database'=>['host'=>trim((string)($data['db_host']??'')),'port'=>(int)($data['db_port']??3306),'name'=>trim((string)($data['db_name']??'')),'user'=>trim((string)($data['db_user']??'')),'password'=>(string)($data['db_password']??'')],'public_url'=>self::canonicalUrl((string)($data['public_url']??'')),'pin_pepper'=>$backup['pin_pepper']??bin2hex(random_bytes(32))];
            $app=new App($config);$this->schema($app->db);
            $result=$app->transaction(function()use($app,$backup,$ownerName,$ownerPin){
                if($backup)return $this->import($app,$backup);
                $now=App::now();$app->run("INSERT INTO flore_members(id,name,role,pin_hash,active,created_at) VALUES('owner',?,'admin',?,1,?)",[$ownerName,$app->pinHash($ownerPin),$now]);$app->run("INSERT INTO flore_settings(id,cafe_name) VALUES(1,'Cafe Flore')");
                $owner=['id'=>'owner','name'=>$ownerName,'role'=>'admin','active'=>1];
                $tasks=json_decode((string)file_get_contents($this->privatePath.'/seed-tasks.json'),true,32,JSON_THROW_ON_ERROR);foreach($tasks as $task)$app->routines('POST',[...$task,'startsOn'=>App::today()],$owner);
                return ['members'=>1,'routines'=>count($tasks),'tasks'=>0,'events'=>count($tasks)];
            });
            $configuration="<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";
            $temporary=$this->privatePath.'/config.'.bin2hex(random_bytes(5)).'.tmp';$oldMask=umask(0077);
            try{if(file_put_contents($temporary,$configuration,LOCK_EX)===false||!rename($temporary,$this->privatePath.'/config.php'))throw new ApiError(500,'Dane zostały zapisane, ale nie udało się zapisać config.php. Sprawdź prawa katalogu private.');file_put_contents($this->privatePath.'/installed.lock',App::now(),LOCK_EX);}
            finally{umask($oldMask);if(is_file($temporary))unlink($temporary);}
            return ['counts'=>$result,'url'=>$config['public_url'],'mode'=>$mode];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}

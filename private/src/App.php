<?php
declare(strict_types=1);
namespace CafeFlore;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

final class ApiError extends \RuntimeException {
    public function __construct(public int $status,string $message){parent::__construct($message);}
}

final class App {
    public const TABLES=['members','settings','routines','routine_rules','tasks','events'];
    public const CATEGORIES=['opening','day','handover','closing','weekly_cleaning'];
    public const TEMPERATURES=['Lodówka produkcyjna 1','Lodówka produkcyjna 2','Zamrażarka'];
    public PDO $db;
    public function __construct(public array $config,?PDO $db=null){
        $this->db=$db??self::connect($config['database']);
    }
    public static function connect(array $c):PDO{
        $host=$c['host']??'';$port=(int)($c['port']??3306);$name=$c['name']??'';
        if(!is_string($host)||!preg_match('/^[a-zA-Z0-9._-]+$/D',$host)||$port<1||$port>65535||!is_string($name)||!preg_match('/^[a-zA-Z0-9_$-]+$/D',$name))throw new ApiError(400,'Sprawdź host, port i nazwę bazy danych.');
        return new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",(string)$c['user'],(string)$c['password'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_TIMEOUT=>10,
        ]);
    }
    public function run(string $sql,array $args=[]):\PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function one(string $sql,array $args=[]):?array{$r=$this->run($sql,$args)->fetch();return $r===false?null:$r;}
    public function all(string $sql,array $args=[]):array{return $this->run($sql,$args)->fetchAll();}
    public function transaction(callable $callback):mixed{
        if($this->db->inTransaction())return $callback();
        for($attempt=0;;$attempt++){
            $this->db->beginTransaction();
            try{$result=$callback();$this->db->commit();return $result;}
            catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&$attempt<2&&in_array((int)($e->errorInfo[1]??0),[1205,1213],true)){usleep(20000*($attempt+1));continue;}throw $e;}
        }
    }
    public static function now():string{return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');}
    public static function millis():int{return (int)floor(microtime(true)*1000);}
    public static function today():string{return (new DateTimeImmutable('now',new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');}
    public static function tomorrow():string{return (new DateTimeImmutable('tomorrow',new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');}
    public static function uuid():string{$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
    public static function text(mixed $v,string $name,int $max=150,bool $required=true):string{
        if(!is_string($v)||mb_strlen($v,'UTF-8')>$max||($required&&trim($v)===''))throw new ApiError(400,"Sprawdź pole: $name.");return trim($v);
    }
    public static function date(mixed $v):string{
        if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v))throw new ApiError(400,'Wybierz poprawną datę.');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC'));
        if(!$d||$d->format('Y-m-d')!==$v)throw new ApiError(400,'Wybierz poprawną datę.');return $v;
    }
    public static function pin(mixed $v,bool $owner=false):string{
        if(!is_string($v)||!preg_match($owner?'/^\d{6,8}$/D':'/^\d{3,8}$/D',$v))throw new ApiError(400,$owner?'Kod właściciela musi mieć 6–8 cyfr.':'Kod pracownika musi mieć 3–8 cyfr.');return $v;
    }
    public function pinHash(string $pin):string{return hash_hmac('sha256','cafe-flore:pin:'.$pin,$this->config['pin_pepper']);}
    public static function json(mixed $v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function metadata(mixed $v):?string{
        if($v===null)return null;if(!is_array($v)||array_is_list($v))throw new ApiError(400,'Nieprawidłowe ustawienia checklisty.');
        foreach(['required','requiresValue','requiresNoteOnFail'] as $key)if(isset($v[$key])&&!is_bool($v[$key]))throw new ApiError(400,'Nieprawidłowe ustawienia checklisty.');
        if(!empty($v['requiresValue'])&&($v['valueType']??null)!=='temperature')throw new ApiError(400,'Nieobsługiwany rodzaj pomiaru.');
        $order=$v['sortOrder']??0;if(!is_int($order)||$order<0||$order>100000)throw new ApiError(400,'Nieprawidłowa kolejność zadania.');
        return self::json(['required'=>($v['required']??true)!==false,'section'=>self::text($v['section']??'','sekcja',80,false),'sortOrder'=>$order,'sourceId'=>self::text($v['sourceId']??'','identyfikator importu',100,false),'requiresValue'=>!empty($v['requiresValue']),'valueType'=>!empty($v['requiresValue'])?'temperature':null,'requiresNoteOnFail'=>!empty($v['requiresNoteOnFail'])]);
    }
    public static function taskFields(array $d):array{
        $f=['title'=>self::text($d['title']??null,'nazwa zadania'),'description'=>self::text($d['description']??'','opis',1500,false),'time'=>self::text($d['time']??'','godzina',5,false),'category'=>$d['category']??'','priority'=>$d['priority']??'normal','assignee_id'=>empty($d['assigneeId'])?null:self::text($d['assigneeId'],'pracownik',80),'checklist_meta'=>self::metadata($d['checklist']??null)];
        if($f['time']!==''&&!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$f['time']))throw new ApiError(400,'Nieprawidłowa godzina.');
        if(!in_array($f['category'],self::CATEGORIES,true))throw new ApiError(400,'Wybierz kategorię.');
        if(!in_array($f['priority'],['normal','high'],true))throw new ApiError(400,'Wybierz priorytet.');return $f;
    }
    public function validateAssignee(?string $id):void{if($id&&!$this->one('SELECT id FROM flore_members WHERE id=? AND active=1',[$id]))throw new ApiError(400,'Ten pracownik jest nieaktywny.');}
    public function event(string $actor,string $action,string $title,?string $task=null,string $note=''):void{
        $this->run('INSERT INTO flore_events(id,task_id,actor_id,action,title,note,created_at) VALUES(?,?,?,?,?,?,?)',[self::uuid(),$task,$actor,$action,$title,$note,self::now()]);
    }
    public static function origin(string $url):string{
        $p=parse_url($url);$scheme=strtolower($p['scheme']??'');$port=$p['port']??null;
        if(($scheme==='https'&&$port===443)||($scheme==='http'&&$port===80))$port=null;
        return $scheme.'://'.strtolower($p['host']??'').($port!==null?':'.$port:'');
    }
    public function validateRequest():void{
        if(in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true))return;
        if(($_SERVER['HTTP_ORIGIN']??'')!==self::origin($this->config['public_url']))throw new ApiError(403,'Nieprawidłowe źródło żądania.');
        if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))throw new ApiError(415,'Wymagany format JSON.');
    }
    public static function body():array{
        if((int)($_SERVER['CONTENT_LENGTH']??0)>12000)throw new ApiError(413,'Zbyt dużo danych.');
        $raw=file_get_contents('php://input',false,null,0,12001);if($raw===false||strlen($raw)>12000)throw new ApiError(413,'Zbyt dużo danych.');
        try{$object=json_decode($raw,false,32,JSON_THROW_ON_ERROR);if(!$object instanceof \stdClass)throw new \Exception();return json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){throw new ApiError(400,'Nieprawidłowe dane formularza.');}
    }
    public function cookie(string $token,bool $remove=false):void{
        $path=rtrim(parse_url($this->config['public_url'],PHP_URL_PATH)?:'','/').'/';
        setcookie('flore_session',$remove?'':$token,['expires'=>$remove?time()-3600:time()+43200,'path'=>$path,'secure'=>str_starts_with($this->config['public_url'],'https://'),'httponly'=>true,'samesite'=>'Strict']);
    }
    public function currentMember():?array{
        $token=$_COOKIE['flore_session']??'';if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;
        return $this->one('SELECT m.id,m.name,m.role,m.active FROM flore_sessions s JOIN flore_members m ON m.id=s.member_id WHERE s.token_hash=? AND s.expires_at>? AND m.active=1',[hash('sha256',$token),self::millis()]);
    }
    public function user():array{$u=$this->currentMember();if(!$u)throw new ApiError(401,'Zaloguj się swoim kodem PIN.');return $u;}
    public static function admin(array $u):void{if($u['role']!=='admin')throw new ApiError(403,'Ta czynność jest dostępna dla właściciela.');}
    public function auth(string $method,array $d):array{
        if($method==='GET')return ['user'=>$this->currentMember(),'cafeName'=>$this->one('SELECT cafe_name FROM flore_settings WHERE id=1')['cafe_name']??'Cafe Flore','publicUrl'=>$this->config['public_url']];
        if($method==='DELETE'){$token=$_COOKIE['flore_session']??'';if($token!=='')$this->run('DELETE FROM flore_sessions WHERE token_hash=?',[hash('sha256',$token)]);$this->cookie('',true);return ['ok'=>true];}
        $now=self::millis();$key=hash('sha256','login:'.($_SERVER['REMOTE_ADDR']??'unknown'));
        $limit=$this->transaction(function()use($now,$key){
            $this->run('INSERT INTO flore_login_attempts(`key`,`count`,resets_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE `count`=IF(resets_at<=?,1,`count`+1),resets_at=IF(resets_at<=?,?,resets_at)',[$key,$now+600000,$now,$now,$now+600000]);
            return $this->one('SELECT `count`,resets_at FROM flore_login_attempts WHERE `key`=? FOR UPDATE',[$key]);
        });
        if($limit['count']>10){header('Retry-After: '.max(1,(int)ceil(($limit['resets_at']-$now)/1000)));throw new ApiError(429,'Za dużo prób. Poczekaj 10 minut i spróbuj ponownie.');}
        $pin=self::pin($d['pin']??null);$member=$this->one('SELECT id,name,role,active FROM flore_members WHERE pin_hash=? AND active=1',[$this->pinHash($pin)]);
        if(!$member)throw new ApiError(401,'Nieprawidłowy kod PIN. Spróbuj ponownie.');$token=bin2hex(random_bytes(32));
        $this->transaction(function()use($token,$member,$now,$key){$this->run('INSERT INTO flore_sessions(token_hash,member_id,expires_at) VALUES(?,?,?)',[hash('sha256',$token),$member['id'],$now+43200000]);$this->run('DELETE FROM flore_login_attempts WHERE `key`=? OR resets_at<?',[$key,$now]);$this->run('DELETE FROM flore_sessions WHERE expires_at<?',[$now]);});
        $this->cookie($token);return ['user'=>$member];
    }
    public function ensureRecurringTasks(string $date):void{
        $bit=1<<(int)(new DateTimeImmutable($date,new DateTimeZone('UTC')))->format('w');
        $this->transaction(function()use($date,$bit){
            $rules=$this->all('SELECT * FROM flore_routine_rules WHERE active=1 AND valid_from<=? AND (valid_until IS NULL OR valid_until>?) AND starts_on<=? AND (ends_on IS NULL OR ends_on>=?) AND (weekdays & ?)<>0',[$date,$date,$date,$date,$bit]);
            $condition="status='todo' AND deleted=0 AND NOT(rule_id<=>VALUES(rule_id))";
            $updates=[];foreach(['title','description','time','category','priority','assignee_id','checklist_meta'] as $column)$updates[]="$column=IF($condition,VALUES($column),$column)";
            $updates[]="version=IF($condition,version+1,version)";$updates[]="rule_id=IF($condition,VALUES(rule_id),rule_id)";
            $sql='INSERT INTO flore_tasks(id,title,description,day,time,category,priority,assignee_id,created_by,created_at,recurrence_id,rule_id,checklist_meta,note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE '.implode(',',$updates);
            foreach($rules as $r)$this->run($sql,['r_'.$r['routine_id'].'_'.$date,$r['title'],$r['description'],$date,$r['time'],$r['category'],$r['priority'],$r['assignee_id'],$r['created_by'],self::now(),$r['routine_id'],$r['id'],$r['checklist_meta'],'']);
        });
    }
    public static function scheduledPredicate():string{return "(t.recurrence_id IS NULL OR t.status<>'todo' OR EXISTS(SELECT 1 FROM flore_routine_rules rr WHERE rr.routine_id=t.recurrence_id AND rr.active=1 AND rr.valid_from<=t.day AND (rr.valid_until IS NULL OR rr.valid_until>t.day) AND rr.starts_on<=t.day AND (rr.ends_on IS NULL OR rr.ends_on>=t.day) AND (rr.weekdays & (1 << (DAYOFWEEK(t.day)-1)))<>0))";}
    public function data(array $u,string $date):array{
        $date=self::date($date);$this->ensureRecurringTasks($date);$admin=$u['role']==='admin';
        $tasks=$this->all('SELECT t.*,a.name AS assignee_name,c.name AS completed_name FROM flore_tasks t LEFT JOIN flore_members a ON a.id=t.assignee_id LEFT JOIN flore_members c ON c.id=t.completed_by WHERE t.deleted=0 AND t.day=? AND '.self::scheduledPredicate().($admin?'':' AND (t.assignee_id=? OR t.assignee_id IS NULL)')." ORDER BY t.time,COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(t.checklist_meta,'$.sortOrder')) AS UNSIGNED),0),t.created_at",$admin?[$date]:[$date,$u['id']]);
        $members=$this->all($admin?'SELECT id,name,role,active,created_at FROM flore_members ORDER BY role,name':'SELECT id,name,role,active FROM flore_members WHERE active=1 ORDER BY name');
        $events=$this->all('SELECT e.*,m.name AS actor_name FROM flore_events e JOIN flore_members m ON m.id=e.actor_id'.($admin?'':' WHERE e.actor_id=?').' ORDER BY e.created_at DESC LIMIT 300',$admin?[]:[$u['id']]);
        $routines=$admin?$this->all("SELECT r.*,m.name AS assignee_name FROM flore_routines r LEFT JOIN flore_members m ON m.id=r.assignee_id ORDER BY r.active DESC,r.category,r.time,COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.checklist_meta,'$.sortOrder')) AS UNSIGNED),0),r.created_at"):[];
        return ['user'=>$u,'tasks'=>$tasks,'members'=>$members,'events'=>$events,'routines'=>$routines,'cafeName'=>$this->one('SELECT cafe_name FROM flore_settings WHERE id=1')['cafe_name']??'Cafe Flore'];
    }
    public static function version(array $d,array $row,string $name='Zadanie'):void{if(!isset($d['version'])||!is_int($d['version'])||$d['version']!==(int)$row['version'])throw new ApiError(409,"$name zostało zmienione. Odśwież listę i spróbuj ponownie.");}
    public function completion(?string $raw,string $status,string $note,mixed $values):array{
        $meta=$raw?json_decode($raw,true):null;
        if($status==='issue'&&$note==='')throw new ApiError(400,'Opisz problem w notatce.');
        if(empty($meta['requiresValue'])||!in_array($status,['done','issue'],true)||($status==='issue'&&$values===null))return [$note,null];
        if(!is_array($values)||count($values)!==3||!array_is_list($values))throw new ApiError(400,'Podaj trzy temperatury w °C.');
        foreach($values as $v)if((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v< -100||$v>100)throw new ApiError(400,'Podaj trzy temperatury w °C (od −100 do 100).');
        $texts=[];foreach(self::TEMPERATURES as $i=>$label)$texts[]=$label.': '.$values[$i].' °C';$temperatures=implode('; ',$texts);
        return [$temperatures.($note!==''?"\n".$note:''),self::json($values)];
    }
    public function tasks(string $method,array $d,array $u):array{
        if($method==='POST'){
            self::admin($u);$f=self::taskFields($d);$date=self::date($d['day']??null);$this->validateAssignee($f['assignee_id']);$id=self::uuid();
            return $this->transaction(function()use($u,$f,$date,$id){$this->run('INSERT INTO flore_tasks(id,title,description,day,time,category,priority,assignee_id,created_by,created_at,checklist_meta,note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$f['title'],$f['description'],$date,$f['time'],$f['category'],$f['priority'],$f['assignee_id'],$u['id'],self::now(),$f['checklist_meta'],'']);$this->event($u['id'],'created',$f['title'],$id);return ['id'=>$id];});
        }
        $id=self::text($d['id']??null,'zadanie',80);
        if($method==='PATCH'){$occurrence=$this->one('SELECT day FROM flore_tasks WHERE id=? AND recurrence_id IS NOT NULL',[$id]);if($occurrence)$this->ensureRecurringTasks($occurrence['day']);}
        return $this->transaction(function()use($method,$d,$u,$id){
            $t=$this->one('SELECT t.* FROM flore_tasks t WHERE t.id=? AND t.deleted=0'.($method==='PATCH'?' AND '.self::scheduledPredicate():'').' FOR UPDATE',[$id]);if(!$t)throw new ApiError(404,'Zadanie już nie istnieje.');self::version($d,$t);
            if($method==='DELETE'){self::admin($u);$this->run('UPDATE flore_tasks SET deleted=1,version=version+1 WHERE id=?',[$id]);$this->event($u['id'],'deleted',$t['title'],$id);return ['ok'=>true];}
            if(($d['action']??'')==='status'){
                if($u['role']!=='admin'&&$t['assignee_id']&&$t['assignee_id']!==$u['id'])throw new ApiError(403,'To zadanie należy do innej osoby.');
                if($u['role']!=='admin'&&$t['status']==='done'&&$t['completed_by']!==$u['id'])throw new ApiError(403,'Tylko autor wpisu lub właściciel może cofnąć to zadanie.');
                $status=$d['status']??'';if(!in_array($status,['todo','doing','done','issue'],true))throw new ApiError(400,'Nieprawidłowy status.');
                if($status===$t['status'])return ['ok'=>true];
                [$note,$values]=$this->completion($t['checklist_meta'],$status,self::text($d['note']??'','notatka',1500,false),$d['values']??null);
                $recorded=in_array($status,['done','issue'],true);$this->run('UPDATE flore_tasks SET status=?,completed_by=?,completed_at=?,note=?,values_json=?,version=version+1 WHERE id=?',[$status,$recorded?$u['id']:null,$recorded?self::now():null,$note,$values,$id]);
                $this->event($u['id'],match($status){'done'=>'completed','issue'=>'problem_reported','doing'=>'started',default=>'reopened'},$t['title'],$id,$note);return ['ok'=>true];
            }
            self::admin($u);$f=self::taskFields($d);$date=self::date($d['day']??null);$this->validateAssignee($f['assignee_id']);
            if($t['recurrence_id']&&$date!==$t['day'])throw new ApiError(400,'Datę zadania cyklicznego zmień w harmonogramie cyklu.');
            $meta=array_key_exists('checklist',$d)?$f['checklist_meta']:$t['checklist_meta'];
            $this->run('UPDATE flore_tasks SET title=?,description=?,day=?,time=?,category=?,priority=?,assignee_id=?,checklist_meta=?,version=version+1 WHERE id=?',[$f['title'],$f['description'],$date,$f['time'],$f['category'],$f['priority'],$f['assignee_id'],$meta,$id]);$this->event($u['id'],'updated',$f['title'],$id);return ['ok'=>true];
        });
    }
    public static function routineFields(array $d):array{
        $f=self::taskFields($d);$f['starts_on']=self::date($d['startsOn']??null);$f['ends_on']=empty($d['endsOn'])?null:self::date($d['endsOn']);$f['weekdays']=$d['weekdays']??0;
        if($f['ends_on']&&$f['ends_on']<$f['starts_on'])throw new ApiError(400,'Data końcowa nie może być wcześniejsza od początkowej.');
        if(!is_int($f['weekdays'])||$f['weekdays']<1||$f['weekdays']>127)throw new ApiError(400,'Wybierz przynajmniej jeden dzień tygodnia.');return $f;
    }
    public function insertRule(array $r,string $from,string $actor):void{
        $this->run('INSERT INTO flore_routine_rules(id,routine_id,title,description,time,category,priority,assignee_id,weekdays,starts_on,ends_on,active,valid_from,created_by,created_at,checklist_meta) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[self::uuid(),$r['id'],$r['title'],$r['description'],$r['time'],$r['category'],$r['priority'],$r['assignee_id'],$r['weekdays'],$r['starts_on'],$r['ends_on'],$r['active'],$from,$actor,self::now(),$r['checklist_meta']]);
    }
    public function routines(string $method,array $d,array $u):array{
        self::admin($u);
        if($method==='POST'){
            $f=self::routineFields($d);$this->validateAssignee($f['assignee_id']);if($f['starts_on']<self::today())throw new ApiError(400,'Nowy cykl może zaczynać się najwcześniej dzisiaj.');
            $meta=$f['checklist_meta']?json_decode($f['checklist_meta'],true):null;$source=$meta['sourceId']??'';$id=$source!==''?'imp_'.substr(hash('sha256','cafe-flore:'.$source),0,32):self::uuid();
            return $this->transaction(function()use($f,$source,$id,$u){
                if($source!==''){$existing=$this->one("SELECT id FROM flore_routines WHERE id=? OR JSON_UNQUOTE(JSON_EXTRACT(checklist_meta,'$.sourceId'))=?",[$id,$source]);if($existing)return ['id'=>$existing['id'],'created'=>false];}
                $now=self::now();$s=$this->run('INSERT IGNORE INTO flore_routines(id,title,description,time,category,priority,assignee_id,weekdays,starts_on,ends_on,active,created_by,created_at,updated_at,checklist_meta) VALUES(?,?,?,?,?,?,?,?,?,?,1,?,?,?,?)',[$id,$f['title'],$f['description'],$f['time'],$f['category'],$f['priority'],$f['assignee_id'],$f['weekdays'],$f['starts_on'],$f['ends_on'],$u['id'],$now,$now,$f['checklist_meta']]);
                if($s->rowCount()===0)return ['id'=>$id,'created'=>false];$this->insertRule([...$f,'id'=>$id,'active'=>1],self::today(),$u['id']);$this->event($u['id'],'routine_created',$f['title'],null,'Od '.$f['starts_on']);return ['id'=>$id,'created'=>true];
            });
        }
        $id=self::text($d['id']??null,'cykl',80);
        return $this->transaction(function()use($id,$d,$u){
            $old=$this->one('SELECT * FROM flore_routines WHERE id=? FOR UPDATE',[$id]);if(!$old)throw new ApiError(404,'Nie znaleziono zadania cyklicznego.');self::version($d,$old,'Zadanie cykliczne');
            $toggle=($d['action']??'')==='toggle';$f=$toggle?$old:self::routineFields($d);if(!$toggle)$this->validateAssignee($f['assignee_id']);
            if($toggle||!array_key_exists('checklist',$d))$f['checklist_meta']=$old['checklist_meta'];$active=$toggle?($old['active']?0:1):(int)$old['active'];$effective=self::tomorrow();
            $this->run('UPDATE flore_routines SET title=?,description=?,time=?,category=?,priority=?,assignee_id=?,weekdays=?,starts_on=?,ends_on=?,active=?,updated_at=?,checklist_meta=?,version=version+1 WHERE id=?',[$f['title'],$f['description'],$f['time'],$f['category'],$f['priority'],$f['assignee_id'],$f['weekdays'],$f['starts_on'],$f['ends_on'],$active,self::now(),$f['checklist_meta'],$id]);
            $this->run('UPDATE flore_routine_rules SET valid_until=? WHERE routine_id=? AND valid_until IS NULL',[$effective,$id]);$this->insertRule([...$f,'id'=>$id,'active'=>$active],$effective,$u['id']);
            $this->event($u['id'],$toggle?($active?'routine_resumed':'routine_paused'):'routine_updated',$f['title'],null,'Zmiana od '.$effective);return ['ok'=>true,'effectiveFrom'=>$effective];
        });
    }
    public function members(string $method,array $d,array $u):array{
        self::admin($u);
        return $this->transaction(function()use($method,$d,$u){
            if($method==='POST'){
                $name=self::text($d['name']??null,'imię i nazwisko',80);$hash=$this->pinHash(self::pin($d['pin']??null));if($this->one('SELECT id FROM flore_members WHERE pin_hash=?',[$hash]))throw new ApiError(409,'Ten kod jest już zajęty. Wybierz inny.');
                $id=self::uuid();$this->run("INSERT INTO flore_members(id,name,role,pin_hash,active,created_at) VALUES(?,?,'employee',?,1,?)",[$id,$name,$hash,self::now()]);$this->event($u['id'],'member_added',$name);return ['id'=>$id];
            }
            $id=self::text($d['id']??null,'pracownik',80);$m=$this->one('SELECT * FROM flore_members WHERE id=? FOR UPDATE',[$id]);if(!$m)throw new ApiError(404,'Nie znaleziono pracownika.');
            if(($d['action']??'')==='toggle'){
                if($m['role']==='admin')throw new ApiError(400,'Nie możesz dezaktywować właściciela.');$active=$m['active']?0:1;$this->run('UPDATE flore_members SET active=? WHERE id=?',[$active,$id]);$this->run('DELETE FROM flore_sessions WHERE member_id=?',[$id]);$this->event($u['id'],$active?'member_enabled':'member_disabled',$m['name']);return ['ok'=>true,'relogin'=>false];
            }
            $name=self::text($d['name']??null,'imię i nazwisko',80);$newPin=($d['pin']??'')!=='';
            if($newPin){$hash=$this->pinHash(self::pin($d['pin'],$m['role']==='admin'));if($this->one('SELECT id FROM flore_members WHERE pin_hash=? AND id<>?',[$hash,$id]))throw new ApiError(409,'Ten kod jest już zajęty. Wybierz inny.');$this->run('UPDATE flore_members SET pin_hash=? WHERE id=?',[$hash,$id]);$this->run('DELETE FROM flore_sessions WHERE member_id=?',[$id]);}
            $this->run('UPDATE flore_members SET name=? WHERE id=?',[$name,$id]);$this->event($u['id'],'member_updated',$name);return ['ok'=>true,'relogin'=>$id===$u['id']&&$newPin];
        });
    }
    public function export():array{
        return $this->transaction(function(){ $tables=[];foreach(self::TABLES as $table)$tables[$table]=$this->all('SELECT * FROM flore_'.$table);return ['format'=>'cafe-flore-data','version'=>1,'exported_at'=>self::now(),'tables'=>$tables];});
    }
    public function dispatch(string $route,string $method,array $d):array{
        $allowed=['auth'=>['GET','POST','DELETE'],'data'=>['GET'],'tasks'=>['POST','PATCH','DELETE'],'members'=>['POST','PATCH'],'routines'=>['POST','PATCH'],'settings'=>['PATCH'],'export'=>['GET']];
        if(!isset($allowed[$route]))throw new ApiError(404,'Nie znaleziono strony.');if(!in_array($method,$allowed[$route],true)){header('Allow: '.implode(', ',$allowed[$route]));throw new ApiError(405,'Niedozwolona metoda.');}
        if($route==='auth')return $this->auth($method,$d);$u=$this->user();
        if($route==='data')return $this->data($u,(string)($_GET['day']??''));
        if($route==='tasks')return $this->tasks($method,$d,$u);if($route==='routines')return $this->routines($method,$d,$u);if($route==='members')return $this->members($method,$d,$u);
        self::admin($u);if($route==='export')return $this->export();
        $name=self::text($d['cafeName']??null,'nazwa kawiarni',80);return $this->transaction(function()use($u,$name){$this->run('UPDATE flore_settings SET cafe_name=? WHERE id=1',[$name]);$this->event($u['id'],'settings_updated',$name);return ['ok'=>true];});
    }
}

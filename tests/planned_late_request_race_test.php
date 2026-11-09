<?php
require_once dirname(__DIR__).'/core/Services/ClockHealth.php';
/** Exercise actual session handlers with isolated transactions and deterministic interleavings. */
if (!isset($argv[1])) {
    foreach (['hr','checkin'] as $app) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($app),$code);
        if ($code) exit($code);
    }
    echo "Planned-late request/cancel race fixtures passed.\n";exit;
}
$app=$argv[1];if(!in_array($app,['hr','checkin'],true))throw new RuntimeException('Invalid fixture');
$path=$app==='hr'?dirname(__DIR__).'/api/attendance.php':(getenv('TP_CAPTURE_CHECKIN_ROOT')?:dirname(__DIR__,2).'/tp-checkin').'/api/attendance.php';
$source=file_get_contents($path);
function raceFunction(string $source,string $name):string {
    $start=strpos($source,'function '.$name.'(');if($start===false)throw new RuntimeException('Missing handler');
    $tokens=token_get_all('<?php '.substr($source,$start));$result='';$depth=0;$started=false;
    foreach(array_slice($tokens,1) as $token){$part=is_array($token)?$token[1]:$token;$result.=$part;if($part==='{'){$depth++;$started=true;}if($part==='}'&&--$depth===0&&$started)return $result;}
    throw new RuntimeException('Incomplete handler');
}
// The sole injected dependency is the bridge path; an empty fixture bridge calls only the stubs below.
$root=sys_get_temp_dir().'/planned-late-race-'.bin2hex(random_bytes(6));mkdir($root.'/api',0700,true);mkdir($root.'/core',0700,true);file_put_contents($root.'/core/CrmLineNotifierBridge.php',"<?php\n");
register_shutdown_function(static function()use($root):void{unlink($root.'/core/CrmLineNotifierBridge.php');rmdir($root.'/core');rmdir($root.'/api');rmdir($root);});
foreach(['handleLateStartRequest','cancelLateStartRequest'] as $fn)eval(str_replace('__DIR__',var_export($root.'/api',true),raceFunction($source,$fn)));
class RaceSuccess extends Exception {public function __construct(public array $data){parent::__construct('success');}}
class RaceError extends RuntimeException {}
function apiError(string $message,int $status=400):never{throw new RaceError($message,$status);}
function apiSuccess(array $data,string $message=''):never{global $activeFixture;if($activeFixture->inTransaction())throw new LogicException('Response before commit');throw new RaceSuccess($data);}
function tpHrLogException(...$args):void{}
function tpCheckinLogException(...$args):void{}
$windowCalls=0;$closeWindow=false;$notifications=[];$activeFixture=null;
function canRequestLateStart(PDO $pdo,string $date,?int $now=null,?int $uid=null):array{global $windowCalls,$closeWindow;$windowCalls++;return ['ok'=>!($closeWindow&&$windowCalls>1),'message'=>'fixture cutoff','reason'=>'after_cutoff','fallback'=>null];}
function validatePlannedStartTime(string $time,?array $shift):array{return ['ok'=>true];}
class Auth {public static function log(...$args):void{global $activeFixture;if(!$activeFixture->inTransaction())throw new LogicException('Audit outside transaction');$activeFixture->prepare('INSERT INTO fixture_audit(action) VALUES(?)')->execute([$args[0]]);}}
function notifyFixture(string $kind,PDO $pdo):void{global $notifications;if($pdo->inTransaction()||(int)$pdo->query('SELECT COUNT(*) FROM fixture_audit')->fetchColumn()!==1)throw new LogicException('Notification before audited commit');$notifications[]=$kind;}
function crm_line_notify_planned_late_request(PDO $pdo,...$args):void{notifyFixture('request',$pdo);}
function crm_line_notify_planned_late_cancelled(PDO $pdo,...$args):void{notifyFixture('cancel',$pdo);}
class RaceStatement extends PDOStatement {
    protected function __construct(private RacePDO $fixture) {}
    public function rowCount():int {return $this->fixture->forceZeroChanged && str_contains($this->queryString,'UPDATE hr_attendances') ? 0 : parent::rowCount();}
}
class RacePDO extends PDO {
    public $beforeBegin=null;public $beforeWrite=null;public bool $forceZeroChanged=false;public int $locks=0;public array $guardedWrites=[];
    public function __construct(){parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[RaceStatement::class,[$this]]);$this->sqliteCreateFunction('NOW',fn()=>'2026-11-10 06:00:00');}
    public function beginTransaction():bool{if($this->beforeBegin){$callback=$this->beforeBegin;$this->beforeBegin=null;$callback($this);}return parent::beginTransaction();}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        if(str_contains($query,'FOR UPDATE')){if(!$this->inTransaction())throw new LogicException('Lock outside transaction');$this->locks++;}
        if(preg_match('/^\s*UPDATE hr_attendances/',$query)){$this->guardedWrites[]=$query;if($this->beforeWrite){$callback=$this->beforeWrite;$this->beforeWrite=null;$callback($this);}}
        return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);
    }
}
function fixture(string $status='PENDING',bool $existing=true):RacePDO{
    global $activeFixture,$windowCalls,$closeWindow,$notifications;
    $db=new RacePDO();$activeFixture=$db;$windowCalls=0;$closeWindow=false;$notifications=[];
    $db->exec("CREATE TABLE hr_work_shifts(id INTEGER PRIMARY KEY,name TEXT,start_time TEXT,end_time TEXT,grace_period_minutes INTEGER,is_default INTEGER,is_active INTEGER);INSERT INTO hr_work_shifts VALUES(1,'fixture','08:30:00','17:30:00',15,1,1);
    CREATE TABLE hr_attendances(id INTEGER PRIMARY KEY,user_id INTEGER,attendance_date TEXT,shift_id INTEGER,check_in_time TEXT,planned_start_time TEXT,planned_reason TEXT,planned_status TEXT,planned_requested_at TEXT,planned_requested_by INTEGER,planned_reviewed_by INTEGER,planned_reviewed_at TEXT,planned_review_note TEXT,created_at TEXT,updated_at TEXT,late_minutes INTEGER DEFAULT 0,UNIQUE(user_id,attendance_date));CREATE TABLE fixture_audit(action TEXT)");
    if($existing)$db->prepare("INSERT INTO hr_attendances(id,user_id,attendance_date,planned_start_time,planned_reason,planned_status,planned_requested_at,planned_requested_by,late_minutes) VALUES(1,77,'2026-11-10','09:30:00','original reason',?,'2026-11-09 06:00:00',77,60)")->execute([$status]);
    return $db;
}
function callHandler(string $kind,RacePDO $db,int $uid=77,bool $isTest=false):RaceSuccess|RaceError{
    try{($kind==='request'?'handleLateStartRequest':'cancelLateStartRequest')($db,['id'=>$uid,'is_test_account'=>$isTest?1:0],['target_date'=>'2026-11-10','planned_start_time'=>'10:00','reason'=>'updated fixture reason']);}catch(RaceSuccess|RaceError $e){return $e;}throw new LogicException('Missing response');
}
$checks=0;
function verify(bool $ok,string $label):void{global $app,$checks;if(!$ok)throw new RuntimeException($app.': '.$label);$checks++;echo "[OK] $app $label\n";}
function attendance(PDO $db):array{return $db->query('SELECT * FROM hr_attendances ORDER BY id')->fetchAll();}
foreach(['request','cancel'] as $kind){
    $db=fixture();$result=callHandler($kind,$db);$row=attendance($db)[0];
    verify($result instanceof RaceSuccess&&!$db->inTransaction()&&$db->locks===1,$kind.' locks and commits before response');
    verify($row['planned_status']===($kind==='request'?'PENDING':'CANCELLED')&&$row['check_in_time']===null&&$row['late_minutes']===60,$kind.' preserves captured/payroll fields');
    verify((int)$db->query('SELECT COUNT(*) FROM fixture_audit')->fetchColumn()===1&&$notifications===[$kind],$kind.' audit committed before notification stub');
    verify(str_contains($db->guardedWrites[0],'user_id = ?')&&str_contains($db->guardedWrites[0],'check_in_time IS NULL')&&str_contains($db->guardedWrites[0],'COALESCE(planned_status'),$kind.' guards owner, capture and expected status in SQL');
    foreach(['checkin','approval','replacement'] as $race){
        $db=fixture();$expected=[];
        $db->beforeBegin=function(RacePDO $db)use($race,&$expected):void{
            $db->exec(match($race){'checkin'=>"UPDATE hr_attendances SET check_in_time='2026-11-10 08:45:00'",'approval'=>"UPDATE hr_attendances SET planned_status='APPROVED',planned_reviewed_by=2,planned_reviewed_at='2026-11-10 06:00:00',late_minutes=0",default=>"UPDATE hr_attendances SET planned_start_time='11:00:00',planned_reason='concurrent replacement'"});$expected=attendance($db);
        };
        $result=callHandler($kind,$db);
        verify($result instanceof RaceError&&$result->getCode()===409&&!$db->inTransaction()&&attendance($db)===$expected,$kind.' rejects concurrent '.$race.' without changing winner');
        verify((int)$db->query('SELECT COUNT(*) FROM fixture_audit')->fetchColumn()===0&&$notifications===[],$kind.' conflict '.$race.' sends no notification/audit');
    }
    $db=fixture();$before=attendance($db);$closeWindow=true;$result=callHandler($kind,$db);
    verify($result instanceof RaceError&&$result->getCode()===400&&attendance($db)===$before&&!$db->inTransaction(),$kind.' rechecks cutoff after lock and rolls back');
    $db=fixture();$before=attendance($db);$result=callHandler($kind,$db,78);
    verify(attendance($db)[0]===$before[0]&&($kind==='request'?$result instanceof RaceSuccess:$result instanceof RaceError),$kind.' cannot modify another employee row');
    $db=fixture();$before=attendance($db);$db->exec("CREATE TRIGGER fail_write BEFORE UPDATE ON hr_attendances BEGIN SELECT RAISE(ABORT,'fixture failure'); END");$result=callHandler($kind,$db);
    verify($result instanceof RaceError&&$result->getCode()===500&&attendance($db)===$before&&!$db->inTransaction()&&$notifications===[],$kind.' database failure rolls back and sends no notification');
    $db=fixture();$db->beforeWrite=fn(RacePDO $db)=>$db->exec("UPDATE hr_attendances SET check_in_time='2026-11-10 08:45:00'");$before=attendance($db);$result=callHandler($kind,$db);
    verify($result instanceof RaceError&&$result->getCode()===409&&attendance($db)===$before,$kind.' zero matched guarded update is rejected and rolled back');
}
$db=fixture();$db->forceZeroChanged=true;$result=callHandler('request',$db);verify($result instanceof RaceSuccess&&attendance($db)[0]['planned_start_time']==='10:00:00','zero changed rows accepts only verified identical desired request');
$db=fixture('APPROVED');$result=callHandler('request',$db);verify($result instanceof RaceSuccess&&attendance($db)[0]['planned_status']==='PENDING','intentional pre-checkin rerequest policy retained');
$db=fixture('APPROVED');$before=attendance($db);$result=callHandler('cancel',$db);verify($result instanceof RaceError&&attendance($db)===$before,'already approved plan cannot be cancelled');
$db=fixture('PENDING',false);$result=callHandler('request',$db);verify($result instanceof RaceSuccess&&count(attendance($db))===1&&attendance($db)[0]['user_id']===77,'new plan insert is transactional and employee scoped');
$db=fixture('PENDING',false);$db->beforeBegin=fn(RacePDO $db)=>$db->exec("INSERT INTO hr_attendances(user_id,attendance_date,check_in_time) VALUES(77,'2026-11-10','2026-11-10 08:45:00')");$result=callHandler('request',$db);verify($result instanceof RaceError&&$result->getCode()===409&&attendance($db)[0]['planned_start_time']===null,'concurrent first capture beats missing-row request snapshot');
if($app==='checkin'){$db=fixture();$result=callHandler('request',$db,77,true);verify($result instanceof RaceSuccess&&$notifications===[],'test-account notifier suppression preserved');}
echo "$app: $checks race checks passed.\n";

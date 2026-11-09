<?php
/** Execute the real v1 receiver with generated photo/SQLite fixtures, never bootstrap/live DB. */
$cases=['checkin'=>201,'checkout'=>201,'approved-plan'=>201,'duplicate-in'=>409,'duplicate-out'=>409,
    'outside'=>409,'inactive-location'=>409,'missing-location'=>409,'disabled-location-policy'=>201,
    'missing-setting-default'=>409,'wfh'=>201,'stale-gps'=>422,'missing-photo'=>422,'black-photo'=>422,
    'backdate'=>422,'metadata-failure'=>500,'clock-after-lock'=>503,'gps-after-lock'=>422,'scoped-other-user'=>403];
if (($argv[1]??'')!=='--fixture') {
    $root=sys_get_temp_dir().'/hr-v1-receiver-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$failed=0;
    try {
        foreach($cases as $name=>$expected){
            $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --fixture '.escapeshellarg($name).' '.escapeshellarg($root.'/'.$name);
            $raw=[];exec($cmd.' 2>&1',$raw,$code);$decoded=json_decode(end($raw)?:'',true);
            $ok=$code===0&&is_array($decoded)&&($decoded['status']??0)===$expected&&($decoded['transaction_open']??true)===false;
            if($expected===201){$row=$decoded['rows'][0]??[];$side=$name==='checkout'?'out':'in';$ok=$ok&&!empty($row['check_'.$side.'_time'])&&!empty($row['check_'.$side.'_photo'])&&!empty($row['check_'.$side.'_gps_captured_at'])&&($row['check_'.$side.'_device_info']??'')==='receiver fixture device'&&($row['check_'.$side.'_gps_accuracy']??null)==6.25&&($row['check_'.$side.'_server_received_at']??null)===($decoded['body']['data']['time']??null);}
            else{$ok=$ok&&($decoded['rows']??null)===($decoded['before']??null);}
            if($name==='wfh')$ok=$ok&&($decoded['rows'][0]['status']??'')==='WFH';
            if($name==='approved-plan')$ok=$ok&&($decoded['rows'][0]['planned_status']??'')==='APPROVED'&&($decoded['rows'][0]['planned_start_time']??'')==='23:59:00';
            echo ($ok?'PASS ':'FAIL ')."v1 receiver $name ($expected)\n";
            if(!$ok){$failed++;echo implode("\n",$raw)."\n";}
        }
    }finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p)$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());rmdir($root);}
    echo 'Actual v1 receiver: '.(count($cases)-$failed).'/'.count($cases)." scenarios passed. No HTTP, LINE or production data.\n";exit($failed?1:0);
}
$name=$argv[2];if(!isset($cases[$name]))throw new RuntimeException('Unknown fixture');$root=$argv[3];mkdir($root,0700,true);
date_default_timezone_set('Asia/Bangkok');define('CHECKIN_STORAGE_PATH',$root.'/storage');define('TP_CLOCK_HEALTH_PATH',$root.'/clock.json');
$repo=dirname(__DIR__);
// Load the same production helpers/services as bootstrap; intentionally do not define getHrBoolSetting.
foreach(['AttendanceScope','WorkdayCalculator'] as $class)require_once dirname($repo).'/tp-common/src/Hr/'.$class.'.php';
foreach(['SettingsService','AttendanceService','PayrollService'] as $class)require_once $repo.'/core/Services/'.$class.'.php';
require_once $repo.'/core/Helpers.php';require_once __DIR__.'/support/attendance_photo_fixtures.php';
function receiverFunction(string $source,string $name):string{
    $at=strpos($source,'function '.$name.'(');if($at===false)throw new RuntimeException('Missing bootstrap helper');$tokens=token_get_all('<?php '.substr($source,$at));$result='';$depth=0;$started=false;
    foreach(array_slice($tokens,1)as$token){$part=is_array($token)?$token[1]:$token;$result.=$part;if($part==='{'){$depth++;$started=true;}if($part==='}'&&--$depth===0&&$started)return$result;}throw new RuntimeException('Incomplete helper');
}
$bootstrap=(string)file_get_contents($repo.'/bootstrap.php');
foreach(['apiKeyServiceUserId','apiKeyResolveScopedUserId','apiKeyHasReadAllScope','apiKeyRequireServiceUserOrReadAllScope','tp_hr_is_attendance_exempt']as$fn)eval(receiverFunction($bootstrap,$fn));
if(function_exists('getHrBoolSetting'))throw new RuntimeException('Session-only helpers must not mask receiver dependencies');
class ReceiverPDO extends PDO{
    public $onLock=null;public int $lockDelay=0;
    public function __construct(){parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        if(str_contains($query,'FOR UPDATE')){if($this->onLock){$callback=$this->onLock;$this->onLock=null;$callback();}if($this->lockDelay)usleep($this->lockDelay);}
        return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);
    }
}
$db=new ReceiverPDO();
$db->exec("CREATE TABLE roles(id INTEGER PRIMARY KEY,name TEXT);INSERT INTO roles VALUES(1,'Staff');
 CREATE TABLE users(id INTEGER PRIMARY KEY,employee_code TEXT,work_mode TEXT,attendance_exempt INTEGER,role_id INTEGER,is_active INTEGER);INSERT INTO users VALUES(77,'TPE-RECEIVER-QA','OFFICE',0,1,1);
 CREATE TABLE hr_employee_schedules(user_id INTEGER,day_off INTEGER);INSERT INTO hr_employee_schedules VALUES(77,0);
 CREATE TABLE hr_work_shifts(id INTEGER PRIMARY KEY,start_time TEXT,end_time TEXT,grace_period_minutes INTEGER,break_minutes INTEGER,work_hours_per_day REAL,is_default INTEGER,is_active INTEGER);INSERT INTO hr_work_shifts VALUES(1,'08:30:00','17:30:00',15,60,8,1,1);
 CREATE TABLE hr_checkin_locations(id INTEGER PRIMARY KEY,is_active INTEGER,latitude REAL,longitude REAL,radius_meters INTEGER);INSERT INTO hr_checkin_locations VALUES(1,1,13.75,100.5,100);
 CREATE TABLE hr_settings(`key` TEXT PRIMARY KEY,`value` TEXT,`type` TEXT);INSERT INTO hr_settings VALUES('enforce_location_checkin','1','BOOL');
 CREATE TABLE hr_attendances(id INTEGER PRIMARY KEY,user_id INTEGER,attendance_date TEXT,shift_id INTEGER,check_in_time TEXT,check_out_time TEXT,check_in_type TEXT,check_out_type TEXT,check_in_latitude REAL,check_in_longitude REAL,check_out_latitude REAL,check_out_longitude REAL,check_in_photo TEXT,check_out_photo TEXT,check_in_ip TEXT,check_out_ip TEXT,check_in_location_id INTEGER,check_out_location_id INTEGER,is_offsite INTEGER,offsite_status TEXT,check_in_outside_status TEXT,check_outside_status TEXT,status TEXT,late_minutes INTEGER,work_minutes INTEGER,break_minutes INTEGER,ot_minutes INTEGER,early_leave_minutes INTEGER,remarks TEXT,planned_start_time TEXT,planned_status TEXT,check_in_gps_captured_at TEXT,check_out_gps_captured_at TEXT,check_in_gps_accuracy REAL,check_out_gps_accuracy REAL,check_in_server_received_at TEXT,check_out_server_received_at TEXT,check_in_device_info TEXT,check_out_device_info TEXT,UNIQUE(user_id,attendance_date))");
$action=in_array($name,['checkout','duplicate-out'],true)?'checkout':'checkin';$side=$action==='checkin'?'in':'out';$type=$action==='checkin'?'checkin':'checkout';$today=date('Y-m-d');
if(in_array($name,['checkout','duplicate-in','duplicate-out','approved-plan'],true)){
    $db->prepare("INSERT INTO hr_attendances(id,user_id,attendance_date,shift_id,check_in_time,check_out_time,planned_start_time,planned_status,status,late_minutes)VALUES(1,77,?,1,?,?,?,?, 'PENDING',45)")->execute([$today,$name==='approved-plan'?null:$today.' 08:30:00',$name==='duplicate-out'?$today.' 17:30:00':null,$name==='approved-plan'?'23:59:00':null,$name==='approved-plan'?'APPROVED':null]);
}
if($name==='wfh')$db->exec("UPDATE users SET work_mode='WFH'");
if($name==='inactive-location')$db->exec('UPDATE hr_checkin_locations SET is_active=0');
if($name==='disabled-location-policy')$db->exec("UPDATE hr_settings SET value='0'");
if($name==='missing-setting-default')$db->exec('DELETE FROM hr_settings');
if($name==='metadata-failure')$db->exec("CREATE TRIGGER fail_metadata BEFORE UPDATE OF check_in_gps_captured_at ON hr_attendances BEGIN SELECT RAISE(ABORT,'fixture metadata failure'); END");
$photo='photos/'.$type.'/'.date('Y/m').'/'.$type.'_77_'.date('YmdHis').'_'.bin2hex(random_bytes(3)).'.jpg';mkdir(dirname(CHECKIN_STORAGE_PATH.'/'.$photo),0700,true);file_put_contents(CHECKIN_STORAGE_PATH.'/'.$photo,photoFixture(320,240,$name==='black-photo'?'black':'detail','jpeg'));
$input=['user_id'=>77,'latitude'=>13.75,'longitude'=>100.5,'gps_captured_at'=>(int)round(microtime(true)*1000),'accuracy'=>6.25,'location_id'=>1,'photo_path'=>$photo,'device_info'=>'receiver fixture device'];
if(in_array($name,['missing-location','disabled-location-policy','missing-setting-default','wfh'],true))unset($input['location_id']);
if($name==='outside')$input['latitude']=14.75;
if($name==='stale-gps')$input['gps_captured_at']-=120001;
if($name==='missing-photo')unset($input['photo_path']);
if($name==='backdate')$input['time']='2000-01-01 08:30:00';
if($name==='scoped-other-user')$input['user_id']=78;
if($name==='clock-after-lock')$db->onLock=static function():void{file_put_contents(TP_CLOCK_HEALTH_PATH,json_encode(['checked_at'=>time(),'status'=>'critical','ntp_active'=>false,'synchronized'=>false,'offset_ms'=>5000]));chmod(TP_CLOCK_HEALTH_PATH,0600);};
if($name==='gps-after-lock'){$input['gps_captured_at']-=119500;$db->lockDelay=600000;}
function getDB():PDO{global$db;return$db;}
$errors=[];function tpHrLogException(Throwable $e,string $context):void{global$errors;$errors[]=$context.': '.$e->getMessage();}
$response=null;
final class ApiAuth{
    public static function requireMethod(array $methods):string{return'POST';}
    public static function require(array $scopes):void{}
    public static function currentKey():array{return['service_user_id'=>77,'scopes'=>'["attendance.write"]'];}
    public static function input():array{global$input;return$input;}
    public static function fail(int $status,string $message):never{global$response;$response=['status'=>$status,'body'=>['error'=>$message]];exit;}
    public static function success(array $data,int $status=200):never{global$response;$response=['status'=>$status,'body'=>$data];exit;}
}
$before=$db->query('SELECT * FROM hr_attendances ORDER BY id')->fetchAll();
register_shutdown_function(static function()use($db,$before):void{global$response,$errors;$rows=$db->query('SELECT * FROM hr_attendances ORDER BY id')->fetchAll();echo json_encode(($response??['status'=>0])+['rows'=>$rows,'before'=>$before,'transaction_open'=>$db->inTransaction(),'logged_errors'=>$errors],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";});
$segments=['attendance',$action];require $repo.'/api/v1/attendance.php';

<?php
/** Actual capture functions, isolated SQLite/filesystem fixtures; no bootstrap/HTTP/LINE. */
if (!isset($argv[1])) {
    $root=sys_get_temp_dir().'/capture-fixture-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$failed=0;
    foreach (['hr','checkin'] as $app) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($app).' '.escapeshellarg($root.'/'.$app), $code);
        if ($code !== 0) {$failed=$code;break;}
    }
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item) { $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname()); }rmdir($root);
    if($failed)exit($failed);
    echo "Capture evidence transaction fixtures passed.\n";
    exit;
}
$app = $argv[1];
if (!in_array($app, ['hr','checkin'], true)) throw new RuntimeException('Invalid fixture');
$api = $app === 'hr' ? dirname(__DIR__).'/api/attendance.php' : (getenv('TP_CAPTURE_CHECKIN_ROOT') ?: dirname(__DIR__,2).'/tp-checkin').'/api/attendance.php';
$source = file_get_contents($api);
function productionFunction(string $source, string $name): string {
    $tokens = token_get_all('<?php '.substr($source, strpos($source,'function '.$name.'(')));
    $result='';$depth=0;$started=false;
    foreach (array_slice($tokens,1) as $token) {
        $part=is_array($token)?$token[1]:$token;
        $result.=$part;
        if ($part==='{') {$depth++;$started=true;}
        if ($part==='}' && --$depth===0 && $started) return $result;
        if ($part==='}' && !$started) throw new RuntimeException('Invalid function extraction');
    }
    throw new RuntimeException('Missing function '.$name);
}
$prefix=$app==='hr'?'hr':'checkin';
$stamp=$app==='hr'?'stampPendingOutsideAttendance':'checkinStampPendingOutsideAttendance';
foreach ([$prefix.'DiscardUnreferencedAttendancePhoto',$prefix.'TrackAttendancePhoto','createOutsideLocationRequest',$stamp] as $fn) eval(productionFunction($source,$fn));
require dirname(__DIR__).'/core/Services/AttendanceCapturePolicy.php';
require dirname(__DIR__).'/core/Services/ClockHealth.php';
$storage=$argv[2] ?? sys_get_temp_dir().'/capture-fixture-'.bin2hex(random_bytes(6));
mkdir($storage,0700,true);define('STORAGE_PATH',$storage);define('TP_CLOCK_HEALTH_PATH',$storage.'/clock-health.json');
function photoFixture(string $type='checkin', int $uid=77): string {
    global $app;
    $path=$app==='hr'?'uploads/attendance/'.date('Y/m').'/'.$type.'_'.$uid.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.jpg'
        :'photos/'.$type.'/'.date('Y/m').'/'.$type.'_'.$uid.'_'.date('YmdHis').'_'.bin2hex(random_bytes(3)).'.jpg';
    if (!is_dir(dirname(STORAGE_PATH.'/'.$path))) mkdir(dirname(STORAGE_PATH.'/'.$path),0700,true);
    file_put_contents(STORAGE_PATH.'/'.$path,'fixture-not-a-real-person');
    return $path;
}
function savePhoto(string $data,int $uid,string $type): string { return photoFixture($type,$uid); }
function apiError(string $message,int $status=400): never { throw new RuntimeException($message,$status); }
class Auth { public static function log(...$args): void {} }
class CaptureFixturePDO extends PDO {
    public int $lockWaitMicros=0;
    public $onLock=null;
    public int $locks=0;
    public function __construct() {
        parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        if (str_contains($query,'FOR UPDATE')) {
            $this->locks++;
            if ($this->onLock) { $callback=$this->onLock; $this->onLock=null; $callback(); }
            if ($this->lockWaitMicros) usleep($this->lockWaitMicros);
        }
        return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);
    }
}
function fixture():CaptureFixturePDO {
    $pdo=new CaptureFixturePDO();
    $pdo->exec("CREATE TABLE hr_work_shifts(id INTEGER PRIMARY KEY,is_default INTEGER,is_active INTEGER); INSERT INTO hr_work_shifts VALUES(1,1,1);
    CREATE TABLE hr_attendances(id INTEGER PRIMARY KEY,user_id INTEGER,attendance_date TEXT,shift_id INTEGER,check_in_time TEXT,check_out_time TEXT,check_in_type TEXT,check_out_type TEXT,check_in_latitude REAL,check_in_longitude REAL,check_out_latitude REAL,check_out_longitude REAL,check_in_photo TEXT,check_out_photo TEXT,check_in_ip TEXT,check_out_ip TEXT,check_in_location_id INTEGER,check_out_location_id INTEGER,is_offsite INTEGER,offsite_status TEXT,check_in_outside_status TEXT,check_outside_status TEXT,offsite_reason TEXT,status TEXT,updated_at TEXT,check_in_gps_captured_at TEXT,check_out_gps_captured_at TEXT,check_in_gps_accuracy REAL,check_out_gps_accuracy REAL,check_in_server_received_at TEXT,check_out_server_received_at TEXT,check_in_device_info TEXT,check_out_device_info TEXT,UNIQUE(user_id,attendance_date));
    CREATE TABLE hr_attendance_outside_requests(id INTEGER PRIMARY KEY,user_id INTEGER,attendance_id INTEGER,request_type TEXT,request_date TEXT,request_time TEXT,latitude REAL,longitude REAL,photo_path TEXT,reason TEXT,status TEXT,request_ip TEXT)");
    return $pdo;
}
$count=0;
function check(bool $valid,string $label):void { global $count,$app;if(!$valid)throw new RuntimeException($app.': '.$label);$count++;echo "[OK] $app $label\n"; }
function gps():array { return ['latitude'=>0.1,'longitude'=>0.1,'gps_captured_at'=>(int)round(microtime(true)*1000),'accuracy'=>6.25]; }
function pending(PDO $pdo,string $side,array $input):array {
    return createOutsideLocationRequest($pdo,['id'=>77,'is_test_account'=>1],$side,$side==='CHECK_OUT'?1:null,$input['latitude'],$input['longitude'],'fixture','fixture outside approval',$input,'fixture device');
}
$pdo=fixture();$input=gps();$in=pending($pdo,'CHECK_IN',$input);$row=$pdo->query('SELECT * FROM hr_attendances')->fetch();
check($pdo->locks>=2,'pending entry locks before committing evidence');
check($row['check_in_gps_captured_at']===date('Y-m-d H:i:s',(int)floor($input['gps_captured_at']/1000)) && (float)$row['check_in_gps_accuracy']===6.25,'pending entry retains fresh GPS time and accuracy');
check($row['check_in_server_received_at']===$in['request_time'] && $row['check_in_device_info']==='fixture device','pending entry retains server time and device');
check($row['check_in_photo']===$in['photo_path'] && $row['check_in_outside_status']==='PENDING' && $row['status']==='PENDING','outside location remains pending with atomic photo');
$discard=$prefix.'DiscardUnreferencedAttendancePhoto';$track=$prefix.'TrackAttendancePhoto';
$discard($pdo,77,$in['photo_path']);check(is_file(STORAGE_PATH.'/'.$in['photo_path']),'attendance proof retained');
$pendingOnly=photoFixture();$pdo->prepare('INSERT INTO hr_attendance_outside_requests(photo_path) VALUES(?)')->execute([$pendingOnly]);$discard($pdo,77,$pendingOnly);
check(is_file(STORAGE_PATH.'/'.$pendingOnly),'pending request only proof retained');
$orphan=photoFixture();$discard($pdo,77,$orphan);check(!is_file(STORAGE_PATH.'/'.$orphan),'unreferenced photo removed');
$other=photoFixture('checkin',78);$discard($pdo,77,$other);check(is_file(STORAGE_PATH.'/'.$other),'another account photo retained');
$uncertain=photoFixture();$unknown=new PDO('sqlite::memory:');$unknown->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$discard($unknown,77,$uncertain);check(is_file(STORAGE_PATH.'/'.$uncertain),'database uncertainty retains photo');
$pdo->exec("CREATE TRIGGER fail_metadata BEFORE UPDATE OF check_out_gps_captured_at ON hr_attendances BEGIN SELECT RAISE(ABORT,'fixture metadata failure'); END");
$before=$pdo->query('SELECT * FROM hr_attendances')->fetch();$requests=(int)$pdo->query('SELECT COUNT(*) FROM hr_attendance_outside_requests')->fetchColumn();
try {pending($pdo,'CHECK_OUT',gps());throw new LogicException('Expected metadata failure');} catch(RuntimeException $e) {if($e instanceof LogicException)throw $e;}
check($pdo->query('SELECT * FROM hr_attendances')->fetch()===$before && (int)$pdo->query('SELECT COUNT(*) FROM hr_attendance_outside_requests')->fetchColumn()===$requests,'metadata failure rolls back both timestamp and pending request');
$pdo->exec('DROP TRIGGER fail_metadata');$out=pending($pdo,'CHECK_OUT',gps());$after=$pdo->query('SELECT * FROM hr_attendances')->fetch();
check($after['check_in_gps_captured_at']===$before['check_in_gps_captured_at'] && $after['check_out_gps_captured_at']!==null && $after['check_out_photo']===$out['photo_path'],'pending checkout adds its evidence and preserves entry');
$expired=fixture();$expired->lockWaitMicros=600000;$old=gps();$old['gps_captured_at']-=119500;
try { pending($expired,'CHECK_IN',$old);throw new LogicException('Expected expired GPS'); } catch(RuntimeException $e) { check($e->getCode()===422,'expired GPS returns validation status after rollback'); }
check((int)$expired->query('SELECT COUNT(*) FROM hr_attendances')->fetchColumn()===0 && (int)$expired->query('SELECT COUNT(*) FROM hr_attendance_outside_requests')->fetchColumn()===0,'GPS expiring during lock wait leaves no timestamp or pending request');
$clockFailure=fixture();$clockFailure->onLock=static function():void {
    file_put_contents(TP_CLOCK_HEALTH_PATH,json_encode(['checked_at'=>time(),'status'=>'critical','ntp_active'=>false,'synchronized'=>false,'offset_ms'=>5000]));chmod(TP_CLOCK_HEALTH_PATH,0600);
};
try { pending($clockFailure,'CHECK_IN',gps());throw new LogicException('Expected unsafe clock'); } catch(RuntimeException $e) {check($e->getCode()===503,'clock becoming unsafe during lock wait returns HTTP503');}
check((int)$clockFailure->query('SELECT COUNT(*) FROM hr_attendances')->fetchColumn()===0 && (int)$clockFailure->query('SELECT COUNT(*) FROM hr_attendance_outside_requests')->fetchColumn()===0 && !$clockFailure->inTransaction(),'unsafe clock after lock leaves no capture or pending request');
unlink(TP_CLOCK_HEALTH_PATH);
// Exercise the actual shutdown callback against an on-disk SQLite DB in a child.
foreach (['rollback','committed-pending'] as $outcome) {
    $path=photoFixture();$db=STORAGE_PATH.'/'.str_replace('-','_',$outcome).'.sqlite';
    $disk=new PDO('sqlite:'.$db);$disk->exec('CREATE TABLE hr_attendances(check_in_photo TEXT,check_out_photo TEXT); CREATE TABLE hr_attendance_outside_requests(photo_path TEXT)');$disk=null;
    $child=STORAGE_PATH.'/child.php';
    $body="<?php\n".productionFunction($source,$prefix.'DiscardUnreferencedAttendancePhoto')."\n".productionFunction($source,$prefix.'TrackAttendancePhoto')."\n";
    $body.='define("STORAGE_PATH",'.var_export(STORAGE_PATH,true).');$pdo=new PDO('.var_export('sqlite:'.$db,true).');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);'.$track.'($pdo,77,'.var_export($path,true).');$pdo->beginTransaction();$pdo->prepare("INSERT INTO hr_attendance_outside_requests VALUES(?)")->execute(['.var_export($path,true).']);';
    if($outcome==='committed-pending')$body.='$pdo->commit();';
    $body.='exit;';file_put_contents($child,$body);
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($child),$code);if($code)throw new RuntimeException('Shutdown child failed');
    check(is_file(STORAGE_PATH.'/'.$path)===($outcome==='committed-pending'),'shutdown '.$outcome.' has safe photo outcome');
    $disk=new PDO('sqlite:'.$db);check((int)$disk->query('SELECT COUNT(*) FROM hr_attendance_outside_requests')->fetchColumn()===($outcome==='committed-pending'?1:0),'shutdown '.$outcome.' has safe database outcome');
}
echo "$app: $count checks passed (temporary fixture tree $storage).\n";
// All callbacks run after this process exits; parent invocation owns fixture cleanup.

register_shutdown_function(static function() use($storage):void {if(!is_dir($storage))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($storage);});

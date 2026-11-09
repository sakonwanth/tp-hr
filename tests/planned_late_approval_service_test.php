<?php
/** Real HR calculation/decision code, isolated SQLite only. FOR UPDATE is retained in
 * production and stripped by this fixture; concurrency requires the MySQL release test. */
date_default_timezone_set('Asia/Bangkok');
$root=dirname(__DIR__);if(is_file($root.'/vendor/autoload.php'))require_once $root.'/vendor/autoload.php';
foreach(['AttendanceScope','WorkdayCalculator'] as $name)if(!class_exists('TpCommon\\Hr\\'.$name))require_once dirname($root).'/tp-common/src/Hr/'.$name.'.php';
foreach(['SettingsService','AttendanceService','PayrollService','PlannedLateApprovalService','HrOperationsService','HrLineApprovalService'] as $name)require_once $root.'/core/Services/'.$name.'.php';
function getShiftDefaults(?array $shift=null):array{return ['grace_period_minutes'=>(int)($shift['grace_period_minutes']??15),'break_minutes'=>60,'work_hours_per_day'=>8];}
final class PlannedLateFixture extends PDO {
    public array $locks=[];
    public function __construct(){parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$this->sqliteCreateFunction('NOW',fn()=>'2026-11-09 12:00:00');$this->sqliteCreateFunction('CONCAT_WS',fn($separator,...$args)=>implode($separator,array_filter($args,fn($v)=>$v!==null)));}
    public function prepare(string $sql,array $options=[]):PDOStatement|false{if(str_contains($sql,'FOR UPDATE'))$this->locks[]=$sql;return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options);}
}
function plFixture(array $overrides=[]):array {
    static $next=100;$id=++$next;$d=new PlannedLateFixture();
    $d->exec("CREATE TABLE roles(id INTEGER PRIMARY KEY,name TEXT);INSERT INTO roles VALUES(1,'Staff'),(2,'HR');
    CREATE TABLE users(id INTEGER PRIMARY KEY,employee_code TEXT,work_mode TEXT,attendance_exempt INTEGER,role_id INTEGER,is_active INTEGER,first_name_th TEXT,last_name_th TEXT,hire_date TEXT,termination_date TEXT);
    CREATE TABLE hr_employee_schedules(user_id INTEGER,day_off INTEGER);
    CREATE TABLE hr_work_shifts(id INTEGER PRIMARY KEY,start_time TEXT,end_time TEXT,grace_period_minutes INTEGER,break_minutes INTEGER,work_hours_per_day REAL,is_active INTEGER,is_default INTEGER);
    INSERT INTO hr_work_shifts VALUES(1,'09:00:00','18:00:00',15,60,8,1,1);
    CREATE TABLE hr_attendances(id INTEGER PRIMARY KEY,user_id INTEGER,attendance_date TEXT,shift_id INTEGER,check_in_time TEXT,planned_start_time TEXT,planned_requested_at TEXT,planned_status TEXT,planned_reason TEXT,planned_reviewed_by INTEGER,planned_reviewed_at TEXT,planned_review_note TEXT,check_in_outside_status TEXT,status TEXT,late_minutes INTEGER,updated_at TEXT);
    CREATE TABLE payroll_runs(id INTEGER PRIMARY KEY,payroll_month TEXT,pay_day INTEGER,status TEXT,updated_at TEXT);
    CREATE TABLE payroll_slips(id INTEGER PRIMARY KEY,payroll_run_id INTEGER,user_id INTEGER);
    CREATE TABLE hr_audit_logs(id INTEGER PRIMARY KEY,user_id INTEGER,action TEXT,module TEXT,table_name TEXT,record_id INTEGER,old_values TEXT,new_values TEXT);");
    $d->prepare("INSERT INTO users VALUES(?,'TPE-QA','OFFICE',0,1,1,'Fixture','Employee','2026-01-01',NULL)")->execute([$id]);
    $d->exec("INSERT INTO users VALUES(2,'TPE-HR','OFFICE',0,2,1,'Fixture','Approver','2020-01-01',NULL);INSERT INTO users VALUES(3,'TPE-OTHER','OFFICE',0,1,1,'Other','Staff','2020-01-01',NULL);INSERT INTO users VALUES(4,'TPE-DISABLED','OFFICE',0,2,0,'Disabled','HR','2020-01-01',NULL)");
    $d->prepare('INSERT INTO hr_employee_schedules VALUES(?,0)')->execute([$id]);
    $row=array_replace(['id'=>1,'user_id'=>$id,'attendance_date'=>'2026-11-09','shift_id'=>1,'check_in_time'=>'2026-11-09 10:15:00','planned_start_time'=>'10:00:00','planned_requested_at'=>'2026-11-09 08:50:00','planned_status'=>'PENDING','planned_reason'=>'Fixture only','status'=>'LATE','late_minutes'=>75,'check_in_outside_status'=>null],$overrides);
    $d->prepare('INSERT INTO hr_attendances('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return [$d,$id];
}
function plCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function plRow(PDO $d):array{return $d->query('SELECT * FROM hr_attendances WHERE id=1')->fetch();}
function plReject(PDO $d,callable $call,string $label):void{$before=plRow($d);$audit=(int)$d->query('SELECT COUNT(*) FROM hr_audit_logs')->fetchColumn();try{$call();}catch(DomainException|InvalidArgumentException $e){plCheck(plRow($d)===$before&&(int)$d->query('SELECT COUNT(*) FROM hr_audit_logs')->fetchColumn()===$audit&&!$d->inTransaction(),$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
function plRun(PDO $d,int $uid,string $status,string $month='2026-11-01',int $runId=1):void{$d->prepare('INSERT INTO payroll_runs VALUES(?,?,25,?,NULL)')->execute([$runId,$month,$status]);$d->prepare('INSERT INTO payroll_slips VALUES(?,?,?)')->execute([$runId,$runId,$uid]);}

[$d,$id]=plFixture();$r=(new PlannedLateApprovalService($d))->decide(1,2,'APPROVED','  fixture note  ');
plCheck($r['planned_status']==='APPROVED'&&$r['status']==='PRESENT'&&(int)$r['late_minutes']===0,'approval after captured arrival applies approved planned start');
plCheck($r['check_in_time']==='2026-11-09 10:15:00'&&$r['planned_review_note']==='fixture note'&&$r['planned_reviewed_at']==='2026-11-09 12:00:00','capture timestamp preserved; fresh decision context returned');
plCheck(count($d->locks)>=3,'actor attendance and payroll decision boundaries request row locks');
plReject($d,fn()=>(new PlannedLateApprovalService($d))->decide(1,2,'REJECTED'),'duplicate decision leaves original result untouched');

foreach(['web','line','legacy'] as $route){[$db,$uid]=plFixture();$r=match($route){'line'=>(new HrLineApprovalService($db))->decide('planned_late',1,2,'approve'),'legacy'=>(new HrOperationsService($db))->decidePlannedLate(1,2,'APPROVED'),default=>(new PlannedLateApprovalService($db))->decide(1,2,'APPROVED')};plCheck($r['status']==='PRESENT'&&(int)$r['late_minutes']===0&&$r['planned_status']==='APPROVED',$route.' uses canonical decision policy');}
[$d,$id]=plFixture();$r=(new PlannedLateApprovalService($d))->decide(1,2,'REJECTED');plCheck($r['status']==='LATE'&&(int)$r['late_minutes']===75,'rejection retains actual-arrival late calculation');
foreach([3,4] as $actor){[$d,$id]=plFixture();plReject($d,fn()=>(new PlannedLateApprovalService($d))->decide(1,$actor,'APPROVED'),'unauthorized or inactive approver rejected '.$actor);}
[$d,$id]=plFixture();$d->exec('UPDATE users SET role_id=2 WHERE id='.$id);plReject($d,fn()=>(new PlannedLateApprovalService($d))->decide(1,$id,'APPROVED'),'self approval rejected even with HR role');
foreach([null,'2026-11-09 10:16:00','invalid'] as $requested){[$d,$id]=plFixture(['planned_requested_at'=>$requested]);plReject($d,fn()=>(new PlannedLateApprovalService($d))->decide(1,2,'APPROVED'),'missing invalid or retrospective request rejected');}
foreach(['approved','paid'] as $status){[$d,$id]=plFixture();plRun($d,$id,$status);plReject($d,fn()=>(new PlannedLateApprovalService($d))->decide(1,2,'APPROVED'),'finalized '.$status.' payroll protects captured attendance');plCheck($d->query('SELECT status FROM payroll_runs')->fetchColumn()===$status,'finalized payroll status unchanged');}
[$d,$id]=plFixture();plRun($d,$id,'calculated');$r=(new PlannedLateApprovalService($d))->decide(1,2,'APPROVED');plCheck($d->query('SELECT status FROM payroll_runs')->fetchColumn()==='draft','affected calculated payroll must recalculate before approval');$a=$d->query("SELECT * FROM hr_audit_logs WHERE action='PAYROLL_RECALC_REQUIRED'")->fetch();$j=json_decode($a['new_values'],true);plCheck((int)$a['user_id']===2&&$j['reason']==='planned_late_decision'&&$j['attendance_id']===1,'payroll invalidation records approver reason and attendance');
[$d,$id]=plFixture(['attendance_date'=>'2026-11-26','check_in_time'=>'2026-11-26 10:15:00','planned_requested_at'=>'2026-11-26 08:50:00']);plRun($d,$id,'approved','2026-12-01');plReject($d,fn()=>(new PlannedLateApprovalService($d))->decide(1,2,'APPROVED'),'26th attendance is protected by next-month payroll cycle');
[$d,$id]=plFixture(['attendance_date'=>'2026-11-26','check_in_time'=>'2026-11-26 10:15:00','planned_requested_at'=>'2026-11-26 08:50:00']);$d->exec("UPDATE users SET termination_date='2026-11-30' WHERE id=$id");plRun($d,$id,'paid');plReject($d,fn()=>(new PlannedLateApprovalService($d))->decide(1,2,'APPROVED'),'resignation month-end payroll protects the tail after cutoff');
[$d,$id]=plFixture(['check_in_time'=>null]);plRun($d,$id,'calculated');$r=(new PlannedLateApprovalService($d))->decide(1,2,'APPROVED');plCheck($r['check_in_time']===null&&$r['planned_status']==='APPROVED','pre-arrival approval creates no capture timestamp');
[$d,$id]=plFixture(['check_in_outside_status'=>'PENDING']);$r=(new PlannedLateApprovalService($d))->decide(1,2,'APPROVED');plCheck($r['status']==='LATE'&&(int)$r['late_minutes']===75&&$r['check_in_outside_status']==='PENDING','planned approval does not approve pending outside attendance');
[$d,$id]=plFixture(['check_in_time'=>'2026-11-09 10:45:00']);$r=(new PlannedLateApprovalService($d))->decide(1,2,'APPROVED');plCheck($r['status']==='LATE'&&(int)$r['late_minutes']===45,'approval does not forgive arrival beyond planned grace');
[$d,$id]=plFixture(['attendance_date'=>'2026-11-26','check_in_time'=>'2026-11-26 10:15:00','planned_requested_at'=>'2026-11-26 08:50:00']);plRun($d,$id,'approved');$r=(new PlannedLateApprovalService($d))->decide(1,2,'APPROVED');plCheck($r['planned_status']==='APPROVED'&&$d->query('SELECT status FROM payroll_runs')->fetchColumn()==='approved','non-overlapping finalized cycle does not block or change');
[$d,$id]=plFixture();plRun($d,3,'calculated');$r=(new PlannedLateApprovalService($d))->decide(1,2,'APPROVED');plCheck($d->query('SELECT status FROM payroll_runs')->fetchColumn()==='calculated','another employee payroll run remains unchanged');
// Force audit failure after mutations: the entire decision and draft invalidation must roll back.
[$d,$id]=plFixture();plRun($d,$id,'calculated');$before=plRow($d);$d->exec('DROP TABLE hr_audit_logs');try{(new PlannedLateApprovalService($d))->decide(1,2,'APPROVED');throw new RuntimeException('Expected audit failure');}catch(PDOException $e){plCheck(plRow($d)===$before&&$d->query('SELECT status FROM payroll_runs')->fetchColumn()==='calculated','audit failure rolls back attendance and payroll invalidation');}
echo "Planned-late isolated behavioral regression passed. No HTTP, LINE, or production data used.\n";

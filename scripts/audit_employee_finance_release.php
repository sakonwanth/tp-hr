<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
// Configuration/autoload only: application bootstrap can initialize sessions or
// attendance hooks. No application services or jobs run during a release audit.
require getcwd().'/vendor/autoload.php';
TpCommon\Env\Env::load(getcwd().'/.env');
$host=(string)(getenv('DB_HOST') ?: 'localhost');
$port=(string)(getenv('DB_PORT') ?: '3306');
$name=(string)(getenv('DB_NAME') ?: '');
if($name==='')throw new RuntimeException('Database configuration unavailable');
try {
    $db=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4',(string)getenv('DB_USER'),(string)getenv('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
} catch(Throwable $e) { throw new RuntimeException('Read-only release audit connection unavailable'); }
$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
try {
    $required=[
        'erp_expense_saved_banks'=>['id','owner_user_id','bank_id','dedupe_hash','bank_name','bank_account_no','bank_account_name'],
        'hr_employee_loans'=>['interest_rate_pct','term_months','schedule_snapshot_json','first_due_month'],
        'hr_loan_repayments'=>['installment_no','paid_amount','payroll_run_id','status'],
        'hr_employee_finance_payroll_links'=>['link_status','source_type','source_id'],
        'hr_employee_finance_audit_logs'=>['event_type','payload_json','actor_user_id'],
        'erp_expense_workflow_events'=>['payload_json','event_type','actor_user_id'],
    ];
    foreach($required as $table=>$columns){$q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);if(array_diff($columns,$q->fetchAll(PDO::FETCH_COLUMN)))throw new RuntimeException('Employee-finance schema contract unavailable: '.$table.'; no migration was attempted');}
    $q=$db->prepare('SELECT * FROM line_expense_requests WHERE request_code IN (?,?,?,?) ORDER BY id');
    $q->execute(['EXP-2026-0129','EXP-2026-0130','EXP-2026-0140','EXP-2026-0146']);
    $requests=$q->fetchAll(PDO::FETCH_ASSOC);if(count($requests)!==4)throw new RuntimeException('Protected request set incomplete');
    $ids=implode(',',array_map('intval',array_column($requests,'id')));$snapshot=['requests'=>$requests];
    foreach(['erp_expense_request_approvals'=>'expense_request_id','line_expense_attachments'=>'request_id','line_expense_request_items'=>'request_id'] as $table=>$fk) $snapshot[$table]=$db->query("SELECT * FROM $table WHERE $fk IN ($ids) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)).PHP_EOL;$db->rollBack();
} catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}

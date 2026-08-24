<?php

require_once dirname(__DIR__) . '/core/Services/EmployeeFinanceRepaymentService.php';

function financeAssert($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "[FAIL] {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
    echo "[OK] {$label}" . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$pdo->exec("
CREATE TABLE line_expense_requests (id INTEGER PRIMARY KEY,status TEXT);
CREATE TABLE hr_salary_advances (id INTEGER PRIMARY KEY,user_id INTEGER,expense_request_id INTEGER,amount REAL,status TEXT);
CREATE TABLE hr_employee_loans (id INTEGER PRIMARY KEY,user_id INTEGER,expense_request_id INTEGER,total_payable REAL,status TEXT,closed_at TEXT);
CREATE TABLE hr_loan_repayments (id INTEGER PRIMARY KEY,loan_id INTEGER,installment_no INTEGER,due_date TEXT,due_amount REAL,paid_amount REAL,status TEXT,paid_at TEXT);
CREATE TABLE hr_employee_finance_receipt_sequences (receipt_year INTEGER PRIMARY KEY,last_number INTEGER,updated_at TEXT);
CREATE TABLE hr_employee_finance_repayments_received (id INTEGER PRIMARY KEY AUTOINCREMENT,receipt_number TEXT,finance_type TEXT,finance_id INTEGER,user_id INTEGER,amount REAL,payment_method TEXT,received_at TEXT,received_by INTEGER,reference_number TEXT,evidence_path TEXT,notes TEXT,idempotency_key TEXT UNIQUE,status TEXT,voided_at TEXT,voided_by INTEGER,void_reason TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE hr_employee_finance_repayment_allocations (id INTEGER PRIMARY KEY AUTOINCREMENT,repayment_received_id INTEGER,loan_repayment_id INTEGER,allocated_amount REAL);
CREATE TABLE hr_employee_finance_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,finance_type TEXT,finance_id INTEGER,event_type TEXT,actor_user_id INTEGER,payload_json TEXT,created_at TEXT);
CREATE TABLE hr_employee_finance_accounting_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT,repayment_received_id INTEGER,payload_json TEXT,status TEXT,available_at TEXT);
");
$pdo->exec("INSERT INTO line_expense_requests VALUES (10,'completed'),(20,'completed')");
$pdo->exec("INSERT INTO hr_salary_advances VALUES (1,7,10,1000,'pending_deduction')");
$pdo->exec("INSERT INTO hr_employee_loans VALUES (2,8,20,1200,'active',NULL)");
$pdo->exec("INSERT INTO hr_loan_repayments VALUES (21,2,1,'2026-08-31',600,0,'scheduled',NULL),(22,2,2,'2026-09-30',600,0,'scheduled',NULL)");

$service = new EmployeeFinanceRepaymentService($pdo);
$first = $service->receive('salary_advance', 1, 400, 'cash', '2026-08-24 10:00:00', 99, '', 'first cash', 'key-1');
financeAssert(400.0, (float)$first['paid_total'], 'salary advance partial payment total');
financeAssert(600.0, (float)$first['remaining_amount'], 'salary advance remaining balance');
financeAssert('partial', (string)$pdo->query('SELECT status FROM hr_salary_advances WHERE id=1')->fetchColumn(), 'salary advance lifecycle marks partial repayment');
$same = $service->receive('salary_advance', 1, 400, 'cash', '2026-08-24 10:00:00', 99, '', 'first cash', 'key-1');
financeAssert((int)$first['receipt_id'], (int)$same['receipt_id'], 'idempotent retry returns original receipt');
financeAssert(1, (int)$pdo->query("SELECT COUNT(*) FROM hr_employee_finance_repayments_received WHERE finance_type='salary_advance'")->fetchColumn(), 'idempotent retry creates no duplicate');
$salaryFinal = $service->receive('salary_advance', 1, 600, 'cash', '2026-08-24 10:30:00', 99, '', '', 'key-1-final');
financeAssert(0.0, (float)$salaryFinal['remaining_amount'], 'salary advance final payment closes balance');
financeAssert('repaid', (string)$pdo->query('SELECT status FROM hr_salary_advances WHERE id=1')->fetchColumn(), 'salary advance lifecycle marks fully repaid');

$loan = $service->receive('employee_loan', 2, 900, 'transfer', '2026-08-24 11:00:00', 99, 'BANK-1', '', 'key-2');
financeAssert(300.0, (float)$loan['remaining_amount'], 'loan partial payment remaining balance');
$installments = $pdo->query('SELECT paid_amount,status FROM hr_loan_repayments ORDER BY installment_no')->fetchAll(PDO::FETCH_ASSOC);
financeAssert(['paid_amount' => 600.0, 'status' => 'paid'], ['paid_amount' => (float)$installments[0]['paid_amount'], 'status' => $installments[0]['status']], 'oldest installment paid first');
financeAssert(['paid_amount' => 300.0, 'status' => 'partial'], ['paid_amount' => (float)$installments[1]['paid_amount'], 'status' => $installments[1]['status']], 'remainder allocated to next installment');

try {
    $service->receive('employee_loan', 2, 301, 'cash', '2026-08-24 12:00:00', 99, '', '', 'key-3');
    financeAssert(true, false, 'overpayment rejected');
} catch (RuntimeException $e) {
    financeAssert(true, str_contains($e->getMessage(), 'เกินยอดคงเหลือ'), 'overpayment rejected');
}

$final = $service->receive('employee_loan', 2, 300, 'cash', '2026-08-24 12:30:00', 99, '', '', 'key-4');
financeAssert(0.0, (float)$final['remaining_amount'], 'final payment closes balance');
financeAssert('closed', (string)$pdo->query('SELECT status FROM hr_employee_loans WHERE id=2')->fetchColumn(), 'loan lifecycle closes after full payment');
financeAssert(4, (int)$pdo->query('SELECT COUNT(*) FROM hr_employee_finance_accounting_outbox')->fetchColumn(), 'one accounting event per posted receipt');

echo "Employee finance repayment service tests passed." . PHP_EOL;

<?php
/** HR-owned UI compatibility for the migrated payroll/profile surfaces. */
if (!defined('HR_OWNER_PATH')) define('HR_OWNER_PATH', dirname(__DIR__));
function hrTransportCrmRoot(): string {
    foreach ([dirname(HR_OWNER_PATH).'/crm.tp-asset.com',dirname(HR_OWNER_PATH).'/tp-crm'] as $root) if (is_file($root.'/core/Services/LineNotifier.php')) return $root;
    throw new RuntimeException('Shared transport unavailable');
}
if (!function_exists('crm_user_can_access_payroll_web')) {
    function crm_user_can_access_payroll_web(): bool {
        $q=getDB()->prepare('SELECT r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1');$q->execute([(int)Auth::id()]);
        return in_array($q->fetchColumn(),['HR','Admin','Chairman','CEO'],true);
    }
}
if (!function_exists('crm_user_can_access_payroll_integration_api')) {function crm_user_can_access_payroll_integration_api():bool{return crm_user_can_access_payroll_web();}}
if (!function_exists('csrf_token')) {function csrf_token():string{return csrfToken();}}
if (!function_exists('csrf_field')) {function csrf_field():string{return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars(csrfToken(),ENT_QUOTES,'UTF-8').'">';}}
if (!function_exists('verify_csrf_token')) {function verify_csrf_token(?string $token=null):bool{return verifyCsrfToken($token ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? $_POST['_token'] ?? ''));}}
if (!function_exists('require_api_auth')) {
    function require_api_auth(bool $output=true,bool $csrf=true):bool {
        if (!Auth::check()) {http_response_code(401);exit(json_encode(['success'=>false,'message'=>'กรุณาเข้าสู่ระบบ']));}
        if ($csrf && in_array($_SERVER['REQUEST_METHOD']??'', ['POST','PUT','PATCH','DELETE'],true) && !verify_csrf_token()) {http_response_code(403);exit(json_encode(['success'=>false,'message'=>'CSRF token ไม่ถูกต้อง']));}
        return true;
    }
}
if (!function_exists('tp_crm_person_user_condition_sql')) {function tp_crm_person_user_condition_sql(string $alias='u'):string{return \TpCommon\User\TestAccountScope::productionPersonFilterSql($alias);}}
if (!function_exists('tp_crm_attendance_scope_filter_sql')) {function tp_crm_attendance_scope_filter_sql(string $alias='u'):string{return tp_hr_attendance_scope_filter_sql($alias);}}
if (!function_exists('crm_safe_content_disposition_filename')) {function crm_safe_content_disposition_filename(string $name,string $default='download'):string{return preg_replace('/[^A-Za-z0-9._-]/','_',basename($name)) ?: $default;}}
if (!function_exists('tpCrmLogException')) {function tpCrmLogException(Throwable $e,string $context=''):void{tpHrLogException($e,$context);}}
if (!function_exists('tpCrmUserErrorFromException')) {function tpCrmUserErrorFromException(Throwable $e,string $context='',bool $generic=true):string{tpHrLogException($e,$context);return $e instanceof DomainException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'ไม่สามารถดำเนินการได้ กรุณาตรวจสอบข้อมูลหรือติดต่อ HR';}}
if (!function_exists('log_security_event')) {function log_security_event(string $event,array $data=[],string $level='info'):void{error_log('[HR security] '.$event);}}
require_once HR_OWNER_PATH.'/core/Services/PayrollUiService.php';

// Prevent CRM's full helper/bootstrap from being loaded by the shared LINE transport.
if (!function_exists('http_curl_timeout_options')) {function http_curl_timeout_options(int $seconds,?int $connect=null):array{return [CURLOPT_TIMEOUT=>max(1,$seconds),CURLOPT_CONNECTTIMEOUT=>max(1,min($seconds,$connect??10))];}}

if (!function_exists('crm_tp_erp_url')) {function crm_tp_erp_url():string{return rtrim((string)($_ENV['TP_ERP_URL'] ?? 'https://erp.tp-asset.com'),'/');}}

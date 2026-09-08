<?php

require_once dirname(__DIR__, 2).'/core/Services/HrLineApprovalService.php';
require_once dirname(__DIR__, 2).'/core/Services/AttendanceAdjustmentService.php';
require_once dirname(__DIR__, 2).'/core/Services/OutsideAttendanceService.php';

$method = ApiAuth::requireMethod(['POST']);
ApiAuth::require(['line_approvals.approve']);
apiKeyForbidServiceScoped();
$pdo = getDB();
$body = ApiAuth::input();
$workflow = strtolower(trim((string)($body['workflow'] ?? '')));
$decision = strtolower(trim((string)($body['decision'] ?? '')));
$id = (int)($body['id'] ?? 0);
$roles = in_array($workflow, ['adjustment','dayoff','holiday_work'], true) ? CEO_ROLES : MANAGER_ROLES;
$actorId = apiKeyResolveActorForApi($pdo, ApiAuth::currentKey(), $body, 'actor_id', $roles);

try {
    $data = (new HrLineApprovalService($pdo))->decide($workflow,$id,$actorId,$decision);
    ApiAuth::success(['data'=>$data]);
} catch (DomainException $e) {
    ApiAuth::fail(409,$e->getMessage());
} catch (InvalidArgumentException $e) {
    ApiAuth::fail(400,$e->getMessage());
} catch (Throwable $e) {
    tpHrLogException($e,'api/v1/line-approvals');
    ApiAuth::fail(500,'Internal server error');
}

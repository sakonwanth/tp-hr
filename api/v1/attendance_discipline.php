<?php

require_once dirname(__DIR__, 2) . '/core/Services/AttendanceDisciplineService.php';

ApiAuth::requireMethod(['POST']);
ApiAuth::requireAny(['leave.approve','leave.write_all']);
apiKeyForbidServiceScoped();
$input = ApiAuth::input();
$action = strtolower(trim((string)($input['action'] ?? '')));
$lineUserId = trim((string)($input['line_user_id'] ?? ''));
if ($lineUserId === '') ApiAuth::fail(422, 'line_user_id is required');

try {
    $service = new AttendanceDisciplineService(getDB());
    if ($action === 'context') {
        $data = $service->publicContext(trim((string)($input['token'] ?? '')), $lineUserId);
    } elseif ($action === 'pdf') {
        $data = $service->pdf(trim((string)($input['token'] ?? '')), $lineUserId);
    } elseif ($action === 'respond') {
        $data = $service->acknowledge(
            trim((string)($input['token'] ?? '')),
            $lineUserId,
            trim((string)($input['response'] ?? '')),
            trim((string)($input['note'] ?? '')),
            isset($input['signature']) ? (string)$input['signature'] : null,
            ($input['accepted'] ?? false) === true,
            (string)($input['ip_address'] ?? ''),
            mb_substr((string)($input['user_agent'] ?? ''), 0, 500)
        );
    } else {
        ApiAuth::fail(422, 'Unsupported action');
    }
    ApiAuth::success(['data'=>$data]);
} catch (InvalidArgumentException $e) {
    ApiAuth::fail(422,$e->getMessage());
} catch (DomainException $e) {
    ApiAuth::fail(409,$e->getMessage());
} catch (Throwable $e) {
    tpHrLogException($e,'api/v1/attendance-discipline');
    ApiAuth::fail(500,'Internal server error');
}

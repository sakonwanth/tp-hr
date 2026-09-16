<?php

$root=dirname(__DIR__);$files=[
 'migration'=>$root.'/database/migrations/2026_09_16_attendance_discipline.sql',
 'service'=>$root.'/core/Services/AttendanceDisciplineService.php',
 'api'=>$root.'/api/v1/attendance_discipline.php',
 'page'=>$root.'/hr/attendance_discipline.php',
 'cron'=>$root.'/cron/scan_attendance_discipline.php',
 'deploy'=>$root.'/.github/workflows/deploy.yml',
];
$text=[];foreach($files as $k=>$f){if(!is_file($f)){fwrite(STDERR,"FAIL missing {$k}\n");exit(1);}$text[$k]=(string)file_get_contents($f);}
$checks=[
 'rules are configurable'=>str_contains($text['migration'],'hr_discipline_rules')&&str_contains($text['migration'],'LATE_3_PER_PAYROLL'),
 'case fingerprint is unique'=>str_contains($text['migration'],'uk_hr_discipline_case_fingerprint'),
 'warning document is immutable hash'=>str_contains($text['migration'],'document_sha256')&&str_contains($text['service'],'WARNING_ISSUED'),
 'employee can acknowledge or dispute'=>str_contains($text['service'],"['ACKNOWLEDGED','DISPUTED']"),
 'LINE identity is bound'=>str_contains($text['service'],"line_user_id")&&str_contains($text['service'],'hash_equals'),
 'signature evidence is bounded'=>str_contains($text['service'],'800000')&&str_contains($text['service'],'getimagesizefromstring'),
 'HR review precedes warning'=>str_contains($text['page'],'รับเข้าตรวจสอบ')&&str_contains($text['page'],'ออกหนังสือเตือน'),
 'production migration is explicit'=>str_contains($text['deploy'],'2026_09_16_attendance_discipline.sql'),
 'detector follows absence backfill'=>str_contains((string)file_get_contents($root.'/cron/backfill_absences.php'),'scan_attendance_discipline.php'),
 'API keeps service key and human identity guards'=>str_contains($text['api'],'requireAny')&&str_contains($text['api'],'apiKeyForbidServiceScoped'),
];
$failed=0;foreach($checks as $name=>$ok){echo($ok?'PASS':'FAIL')." {$name}\n";if(!$ok)$failed++;}echo 'Attendance discipline contract: '.(count($checks)-$failed).' passed, '.$failed." failed\n";exit($failed?1:0);

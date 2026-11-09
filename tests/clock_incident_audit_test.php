<?php
declare(strict_types=1);
define('HR_CLOCK_INCIDENT_TESTING',true);
require dirname(__DIR__).'/scripts/audit_clock_incident.php';
function clockAuditAssert(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$c=hr_clock_incident_config([]);
clockAuditAssert($c['fixed_at']==='2026-11-09 10:13:42'&&$c['server_was_behind_seconds']===5251.39095,'exact known incident parameters');
clockAuditAssert($c['true_incident_onset']===null&&!$c['onset_known'],'lookback is not promoted to an incident-onset finding');
$base=['id'=>1,'user_id'=>2,'attendance_date'=>'2026-11-09','check_in_time'=>'2026-11-09 07:33:03','check_out_time'=>'2026-11-09 09:00:00','status'=>'PRESENT','late_minutes'=>0,'work_minutes'=>86,'ot_minutes'=>0];
$r=hr_clock_incident_attendance($base,$c);
clockAuditAssert($r['check_in_time']===$base['check_in_time']&&$r['status']===$base['status']&&$r['work_minutes']===86,'stored timestamps/status/work totals are preserved');
clockAuditAssert($r['classification']==='review_candidate_not_confirmed_wrong'&&$r['no_recalculation_performed'],'candidates are explicitly uncertain and no recalculation is represented');
clockAuditAssert($r['scenario_only']['both_before_fix_common_offset_preserves_elapsed_time']===true&&$r['scenario_only']['mixed_clock_elapsed_may_be_overstated_seconds']===null,'same-side constant offset preserves elapsed duration scenario');
$mixed=hr_clock_incident_attendance(array_replace($base,['check_out_time'=>'2026-11-09 18:00:00']),$c);
clockAuditAssert($mixed['stored_times_straddle_correction']&&$mixed['scenario_only']['mixed_clock_elapsed_may_be_overstated_seconds']===5251.39095,'mixed before/after stored clocks flag a possible duration effect');
clockAuditAssert($mixed['scenario_only']['shifted_check_out_not_authoritative']===null,'already-after-correction checkout is not shifted even in the scenario');
$cross=hr_clock_incident_attendance(array_replace($base,['attendance_date'=>'2026-11-08','check_in_time'=>'2026-11-08 23:30:00','check_out_time'=>null]),$c);
clockAuditAssert($cross['scenario_only']['shifted_in_crosses_business_date']&&$cross['attendance_date']==='2026-11-08','cross-midnight scenario flags review without altering the business date');
clockAuditAssert($cross['recorded_elapsed_seconds']===null&&!$cross['stored_times_straddle_correction'],'incomplete attendance does not invent a duration');
clockAuditAssert($cross['scenario_only']['open_entry_may_mix_clocks_later'],'open pre-correction entry retains a later mixed-clock review flag');
clockAuditAssert(hr_clock_incident_scenario('2026-11-01 07:00:00',$c)===null,'scenario does not extend beyond bounded lookback');
clockAuditAssert(hr_clock_incident_month('2026-11-25',25)==='2026-11-01'&&hr_clock_incident_month('2026-11-26',25)==='2026-12-01','payroll review month follows observed cutoff');
clockAuditAssert(hr_clock_incident_month('2026-02-28',31)==='2026-02-01','month end is clamped for short payroll months');
foreach([['fixed-at'=>'2026-02-31 10:00:00'],['days'=>0],['drift-seconds'=>-10],['drift-seconds'=>'5251xyz'],['days'=>'7xyz']] as $bad){try{hr_clock_incident_config($bad);throw new RuntimeException('Invalid configuration accepted');}catch(InvalidArgumentException $expected){}}
clockAuditAssert(true,'invalid correction/window parameters are rejected');
ob_start();hr_clock_incident_output(['attendance_candidates'=>[$r]],'csv');$csv=ob_get_clean();
clockAuditAssert(str_contains($csv,'scenario_only_shifted_in_not_authoritative')&&str_contains($csv,'review_candidate_not_confirmed'),'CSV keeps uncertainty in its column labels');
echo "Clock-incident read-only scenario tests passed.\n";

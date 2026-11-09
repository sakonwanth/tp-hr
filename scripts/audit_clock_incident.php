<?php
declare(strict_types=1);

/**
 * Read-only clock-incident review; does not repair timestamps or recalculate pay.
 * php scripts/audit_clock_incident.php --format=json
 * php scripts/audit_clock_incident.php --format=csv
 *
 * The lookback is a review window, not an asserted incident-onset date.
 * No names, reasons, coordinates, photos, salaries or financial amounts are read.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

function hr_clock_incident_config(array $options): array
{
    $zone = new DateTimeZone('Asia/Bangkok');
    $fixedAt = (string)($options['fixed-at'] ?? '2026-11-09 10:13:42');
    $fixed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fixedAt, $zone);
    if (!$fixed || $fixed->format('Y-m-d H:i:s') !== $fixedAt) throw new InvalidArgumentException('Invalid fixed-at');
    $rawDrift=$options['drift-seconds']??5251.39095;$rawDays=$options['days']??7;
    if(!is_numeric($rawDrift)||!preg_match('/^\d+$/D',(string)$rawDays))throw new InvalidArgumentException('Invalid numeric parameters');
    $drift = (float)$rawDrift;
    $days = (int)$rawDays;
    if (!is_finite($drift) || $drift <= 0 || $drift > 86400 || $days < 1 || $days > 31) throw new InvalidArgumentException('Invalid drift/window');
    return ['timezone'=>'Asia/Bangkok', 'fixed_at'=>$fixedAt, 'fixed_epoch'=>$fixed->getTimestamp(),
        'server_was_behind_seconds'=>$drift, 'lookback_days'=>$days,
        'review_window_start'=>$fixed->modify('-'.$days.' days')->format('Y-m-d H:i:s'),
        'review_window_start_epoch'=>$fixed->modify('-'.$days.' days')->getTimestamp(),
        'true_incident_onset'=>null, 'onset_known'=>false];
}

function hr_clock_incident_epoch(?string $value, array $config): ?int
{
    if ($value === null || $value === '' || str_starts_with($value, '0000-')) return null;
    try { return (new DateTimeImmutable($value, new DateTimeZone($config['timezone'])))->getTimestamp(); }
    catch (Throwable $ignored) { return null; }
}

function hr_clock_incident_scenario(?string $value, array $config): ?string
{
    $epoch = hr_clock_incident_epoch($value, $config);
    if ($epoch === null || $epoch >= $config['fixed_epoch'] || $epoch < $config['review_window_start_epoch']) return null;
    // Counterfactual only: this value must never be used as an authoritative repair.
    return (new DateTimeImmutable('@'.($epoch + (int)round($config['server_was_behind_seconds']))))
        ->setTimezone(new DateTimeZone($config['timezone']))->format('Y-m-d H:i:s');
}

function hr_clock_incident_attendance(array $row, array $config): array
{
    $in = hr_clock_incident_epoch($row['check_in_time'] ?? null, $config);
    $out = hr_clock_incident_epoch($row['check_out_time'] ?? null, $config);
    $before = fn(?int $time): bool => $time !== null && $time >= $config['review_window_start_epoch'] && $time < $config['fixed_epoch'];
    $shiftedIn = hr_clock_incident_scenario($row['check_in_time'] ?? null, $config);
    $shiftedOut = hr_clock_incident_scenario($row['check_out_time'] ?? null, $config);
    $bothBefore = $before($in) && $before($out);
    $straddles = $before($in) && $out !== null && $out >= $config['fixed_epoch'];
    $storedDuration = $in !== null && $out !== null ? $out - $in : null;
    $dateChanges = $shiftedIn !== null && substr($shiftedIn, 0, 10) !== (string)$row['attendance_date'];
    return $row + [
        'classification'=>'review_candidate_not_confirmed_wrong',
        'stored_times_straddle_correction'=>$straddles,
        'recorded_elapsed_seconds'=>$storedDuration,
        'scenario_only'=>[
            'assumption'=>'A constant measured offset applied at this event; actual onset and event time are unproven.',
            'shifted_check_in_not_authoritative'=>$shiftedIn,
            'shifted_check_out_not_authoritative'=>$shiftedOut,
            'both_before_fix_common_offset_preserves_elapsed_time'=>$bothBefore,
            'mixed_clock_elapsed_may_be_overstated_seconds'=>$straddles ? $config['server_was_behind_seconds'] : null,
            'open_entry_may_mix_clocks_later'=>$before($in) && $out === null,
            'shifted_in_crosses_business_date'=>$dateChanges,
            'late_status_requires_independent_arrival_evidence'=>$before($in),
            'work_or_OT_review_needed'=>$straddles || $dateChanges,
        ],
        'no_recalculation_performed'=>true,
    ];
}

function hr_clock_incident_month(string $date, int $cutoff): string
{
    $day = new DateTimeImmutable($date, new DateTimeZone('Asia/Bangkok'));
    $periodEnd = min($cutoff, (int)$day->format('t'));
    return ((int)$day->format('d') <= $periodEnd ? $day : $day->modify('first day of next month'))->format('Y-m-01');
}

function hr_clock_incident_scope(): string
{
    if (class_exists(\TpCommon\User\TestAccountScope::class)) return \TpCommon\User\TestAccountScope::productionPersonFilterSql('u').' AND u.id NOT IN (1,12)';
    return "u.account_type='PERSON' AND u.is_employee=1 AND u.is_test_account=0 AND (u.employee_code IS NULL OR u.employee_code NOT LIKE 'QAE-%') AND u.id NOT IN (1,12) AND u.username NOT IN ('ci_staff','ci_chairman','ci_ceo','erp_qa_acct','qa_ceo','qa_chairman','qa_manager','account1')";
}

function hr_clock_incident_collect(PDO $db, array $config): array
{
    $scope = hr_clock_incident_scope();
    $window = ['start'=>$config['review_window_start'], 'fixed'=>$config['fixed_at'],
        'start_date'=>substr($config['review_window_start'],0,10), 'fixed_date'=>substr($config['fixed_at'],0,10)];
    // Choose only a fixed allow-list of audit fields; never SELECT a.* or u.*.
    $q = $db->prepare("SELECT a.id,a.user_id,a.attendance_date,a.shift_id,a.check_in_time,a.check_out_time,
        a.check_in_type,a.check_out_type,a.status,a.late_minutes,a.work_minutes,a.break_minutes,a.ot_minutes,
        a.planned_start_time,a.planned_status,a.created_at,a.updated_at
        FROM hr_attendances a JOIN users u ON u.id=a.user_id
        WHERE $scope AND a.attendance_date BETWEEN ? AND ? AND
        ((a.check_in_time>=? AND a.check_in_time<?) OR (a.check_out_time>=? AND a.check_out_time<?)
         OR (a.created_at>=? AND a.created_at<?)) ORDER BY a.attendance_date,a.id");
    $q->execute([$window['start_date'],$window['fixed_date'],$window['start'],$window['fixed'],
        $window['start'],$window['fixed'],$window['start'],$window['fixed']]);
    $attendance = array_map(fn($row)=>hr_clock_incident_attendance($row,$config),$q->fetchAll(PDO::FETCH_ASSOC));
    $q = $db->prepare("SELECT l.id,l.user_id,l.leave_type_id,l.start_date,l.end_date,l.status,l.created_at,l.updated_at,l.final_approved_at
        FROM hr_leave_requests l JOIN users u ON u.id=l.user_id
        WHERE $scope AND l.created_at>=? AND l.created_at<? ORDER BY l.id");
    $q->execute([$window['start'],$window['fixed']]);$leaves=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($leaves as &$leave) {
        $leave['classification']='review_candidate_not_confirmed_wrong';
        $leave['scenario_only_created_at_not_authoritative']=hr_clock_incident_scenario($leave['created_at'],$config);
        $leave['business_leave_dates_must_not_be_shifted']=true;
    } unset($leave);
    $cutoff = function_exists('getSetting') ? (int)getSetting('payroll_cutoff_day','25') : 25;
    if($cutoff<1 || $cutoff>31)$cutoff=25;
    $months=[];foreach($attendance as $row) {
        $months[hr_clock_incident_month($row['attendance_date'],$cutoff)]=true;
        $shifted=$row['scenario_only']['shifted_check_in_not_authoritative'];
        if($shifted!==null)$months[hr_clock_incident_month(substr($shifted,0,10),$cutoff)]=true;
    }
    $monthList=array_keys($months);sort($monthList);$runs=[];$slips=[];
    if($monthList) {
        $placeholders=implode(',',array_fill(0,count($monthList),'?'));
        $q=$db->prepare("SELECT id,payroll_month,status,created_at,updated_at,approved_at FROM payroll_runs WHERE payroll_month IN ($placeholders) ORDER BY id");$q->execute($monthList);$runs=$q->fetchAll(PDO::FETCH_ASSOC);
        $userIds=array_values(array_unique(array_map(fn($r)=>(int)$r['user_id'],$attendance)));
        $userPlaceholders=implode(',',array_fill(0,count($userIds),'?'));
        $q=$db->prepare("SELECT s.id,s.user_id,s.payroll_run_id,s.created_at,r.payroll_month,r.status AS run_status,
            s.absent_days,s.late_count_30,s.late_count_60
            FROM payroll_slips s JOIN payroll_runs r ON r.id=s.payroll_run_id JOIN users u ON u.id=s.user_id
            WHERE $scope AND r.payroll_month IN ($placeholders) AND s.user_id IN ($userPlaceholders) ORDER BY s.payroll_run_id,s.id");$q->execute(array_merge($monthList,$userIds));$slips=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    $latest=$db->query('SELECT id,payroll_month,status FROM payroll_runs ORDER BY payroll_month DESC,id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
    $summary=['attendance_review_candidates'=>count($attendance),'leave_review_candidates'=>count($leaves),
        'stored_late_rows'=>count(array_filter($attendance,fn($r)=>(int)$r['late_minutes']>0)),
        'late_classification_sensitive_candidates'=>count(array_filter($attendance,fn($r)=>$r['scenario_only']['late_status_requires_independent_arrival_evidence'])),
        'mixed_clock_elapsed_review_candidates'=>count(array_filter($attendance,fn($r)=>$r['stored_times_straddle_correction'])),
        'open_entries_captured_before_correction'=>count(array_filter($attendance,fn($r)=>$r['scenario_only']['open_entry_may_mix_clocks_later'])),
        'correction_day_open_entry_ids'=>array_values(array_map(fn($r)=>(int)$r['id'],array_filter($attendance,fn($r)=>$r['scenario_only']['open_entry_may_mix_clocks_later'] && $r['attendance_date']===substr($config['fixed_at'],0,10)))),
        'counterfactual_date_crossings'=>count(array_filter($attendance,fn($r)=>$r['scenario_only']['shifted_in_crosses_business_date'])),
        'potential_payroll_months'=>$monthList,'existing_related_payroll_runs'=>count($runs),'existing_related_payroll_slips'=>count($slips),
        'protected_approved_or_paid_related_runs'=>count(array_filter($runs,fn($r)=>in_array(strtolower($r['status']),['approved','paid'],true)))];
    return ['read_only'=>true,'generated_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format(DATE_ATOM),
        'incident'=>$config,'evidence_limits'=>[
            'Correction time/offset were supplied from the operator repair record; a prior NTP panic proves loss of synchronization but not the original drift cause or true onset.',
            'Candidates were selected from stored timestamps in a bounded seven-day review window, not proven to have occurred while the clock was wrong.',
            'Constant-offset timestamp examples are counterfactual review aids only; no timestamp, leave date, late classification, work/OT total, or payroll amount was changed.',
            'Late/work/payroll effects require independent arrival/departure evidence and the applicable shift, approved plan, break and payroll-period rules.',
        ],'privacy'=>['excluded'=>'TEST/QAE/CI/system users','omitted'=>'names, reasons, contact details, coordinates, photo data, salaries and financial amounts'],
        'summary'=>$summary,'payroll_cutoff_day_observed'=>$cutoff,'latest_payroll_run_metadata'=>$latest,
        'attendance_candidates'=>$attendance,'leave_candidates'=>$leaves,'related_payroll_runs'=>$runs,'related_payroll_slips'=>$slips];
}

function hr_clock_incident_output(array $report,string $format):void
{
    if($format==='json') {echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;return;}
    if($format!=='csv')throw new InvalidArgumentException('Format must be json or csv');
    $out=fopen('php://output','w');
    fputcsv($out,['review_candidate_not_confirmed','attendance_id','user_id','attendance_date','stored_check_in','stored_check_out','stored_status',
        'stored_late_minutes','stored_work_minutes','stored_OT_minutes','recorded_elapsed_seconds','stored_times_straddle_fix',
        'scenario_only_shifted_in_not_authoritative','scenario_only_shifted_out_not_authoritative','scenario_only_date_crossing','open_entry_potential_mixed_clock','no_recalculation'],',','"','');
    foreach($report['attendance_candidates'] as $r) {
        fputcsv($out,[1,$r['id'],$r['user_id'],$r['attendance_date'],$r['check_in_time'],$r['check_out_time'],$r['status'],
            $r['late_minutes'],$r['work_minutes'],$r['ot_minutes'],$r['recorded_elapsed_seconds'],(int)$r['stored_times_straddle_correction'],
            $r['scenario_only']['shifted_check_in_not_authoritative'],$r['scenario_only']['shifted_check_out_not_authoritative'],
            (int)$r['scenario_only']['shifted_in_crosses_business_date'],(int)$r['scenario_only']['open_entry_may_mix_clocks_later'],1],',','"','');
    }
    fclose($out);
}

function hr_clock_incident_main():void
{
    $options=getopt('',['fixed-at::','drift-seconds::','days::','format::','bootstrap::']);
    $config=hr_clock_incident_config($options);$format=(string)($options['format']??'json');
    if(!in_array($format,['json','csv'],true))throw new InvalidArgumentException('Invalid format');
    require_once (string)($options['bootstrap']??dirname(__DIR__).'/bootstrap.php');
    $db=getDB();$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
    try{$report=hr_clock_incident_collect($db,$config);$db->rollBack();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    hr_clock_incident_output($report,$format);
}

if(!defined('HR_CLOCK_INCIDENT_TESTING')) {
    try{hr_clock_incident_main();}catch(Throwable $e){fwrite(STDERR,'Clock audit failed: '.get_class($e).PHP_EOL);exit(1);}
}

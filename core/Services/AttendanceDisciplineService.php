<?php

declare(strict_types=1);

final class AttendanceDisciplineService
{
    private const CONSENT_VERSION = 'hr-warning-v1';
    private const TOKEN_DAYS = 30;

    public function __construct(private PDO $pdo) {}

    public function listRules(): array{return $this->pdo->query('SELECT * FROM hr_discipline_rules ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);}

    public function updateRule(int $ruleId,int $threshold,bool $active): void
    {
        if($threshold<1||$threshold>30)throw new InvalidArgumentException('เกณฑ์ต้องอยู่ระหว่าง 1–30');
        $this->pdo->beginTransaction();try{$stmt=$this->pdo->prepare('SELECT * FROM hr_discipline_rules WHERE id=? FOR UPDATE');$stmt->execute([$ruleId]);$rule=$stmt->fetch(PDO::FETCH_ASSOC);if(!$rule)throw new RuntimeException('ไม่พบกฎ');$this->pdo->prepare('UPDATE hr_discipline_rules SET threshold_value=?,is_active=? WHERE id=?')->execute([$threshold,$active?1:0,$ruleId]);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** @return array{created:int,existing:int,cases:list<int>} */
    public function detect(?string $payrollMonth = null): array
    {
        require_once __DIR__ . '/PayrollService.php';
        $payroll = new PayrollService($this->pdo);
        $payrollMonth = $payrollMonth ?: $payroll->suggestPayrollMonth();
        if (!preg_match('/^\d{4}-\d{2}$/', $payrollMonth)) throw new InvalidArgumentException('รอบเงินเดือนไม่ถูกต้อง');
        $period = $payroll->attendancePeriodBounds($payrollMonth . '-01');
        $rules = $this->pdo->query("SELECT * FROM hr_discipline_rules WHERE is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $users = $this->pdo->query("SELECT id FROM users WHERE is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $created = 0; $existing = 0; $caseIds = [];

        foreach ($users as $rawUserId) {
            $userId = (int)$rawUserId;
            if (class_exists(\TpCommon\Hr\AttendanceScope::class) && \TpCommon\Hr\AttendanceScope::isUserExemptById($this->pdo, $userId)) continue;
            $summary = $payroll->computeAttendanceDeductions($userId, $payrollMonth . '-01');
            foreach ($rules as $rule) {
                $events = $this->eventsForRule($rule, $summary, $payroll, $userId, $period['start'], $period['end']);
                $threshold = (float)$rule['threshold_value'];
                if (count($events) < $threshold) continue;
                $fingerprint = hash('sha256', $rule['rule_code'] . '|' . $userId . '|' . implode(',', array_column($events, 'date')));
                $this->pdo->beginTransaction();
                try {
                    $find = $this->pdo->prepare('SELECT id FROM hr_discipline_cases WHERE user_id=? AND rule_id=? AND period_start=? AND period_end=? LIMIT 1 FOR UPDATE');
                    $find->execute([$userId,(int)$rule['id'],$period['start'],$period['end']]);
                    $caseId = (int)($find->fetchColumn() ?: 0);
                    if ($caseId > 0) {
                        $this->pdo->prepare('UPDATE hr_discipline_cases SET occurrence_count=?,evidence_fingerprint=? WHERE id=?')->execute([count($events),$fingerprint,$caseId]);
                        $eventInsert = $this->pdo->prepare("INSERT IGNORE INTO hr_discipline_case_events (case_id,attendance_id,event_date,event_type,late_minutes,detail_json) VALUES (?,?,?,?,?,?)");
                        foreach ($events as $event) $eventInsert->execute([$caseId,$event['attendance_id'] ?? null,$event['date'],$event['type'],$event['late_minutes'] ?? null,json_encode($event,JSON_UNESCAPED_UNICODE)]);
                        $existing++;
                    } else {
                        $caseNo = $this->nextNumber('DISC', 'hr_discipline_cases', 'case_no');
                        $insert = $this->pdo->prepare("INSERT INTO hr_discipline_cases (case_no,rule_id,user_id,payroll_month,period_start,period_end,occurrence_count,evidence_fingerprint,status) VALUES (?,?,?,?,?,?,?,?, 'DETECTED')");
                        $insert->execute([$caseNo,(int)$rule['id'],$userId,$payrollMonth,$period['start'],$period['end'],count($events),$fingerprint]);
                        $caseId = (int)$this->pdo->lastInsertId();
                        $eventInsert = $this->pdo->prepare("INSERT INTO hr_discipline_case_events (case_id,attendance_id,event_date,event_type,late_minutes,detail_json) VALUES (?,?,?,?,?,?)");
                        foreach ($events as $event) {
                            $eventInsert->execute([$caseId,$event['attendance_id'] ?? null,$event['date'],$event['type'],$event['late_minutes'] ?? null,json_encode($event,JSON_UNESCAPED_UNICODE)]);
                        }
                        $this->audit($caseId,null,'DETECTED',null,['rule_code'=>$rule['rule_code'],'events'=>$events],'detect:'.$caseId);
                        $created++;
                    }
                    $this->pdo->commit();
                    $caseIds[] = $caseId;
                } catch (Throwable $e) {
                    if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                    throw $e;
                }
            }
        }
        return ['created'=>$created,'existing'=>$existing,'cases'=>array_values(array_unique($caseIds))];
    }

    /** @return list<array<string,mixed>> */
    public function listCases(string $status = 'OPEN'): array
    {
        $where = $status === 'ALL' ? '1=1' : ($status === 'OPEN'
            ? "c.status IN ('DETECTED','UNDER_REVIEW','AWAITING_EXPLANATION','DRAFT','APPROVED','DELIVERED','DISPUTED')"
            : 'c.status=?');
        $sql = "SELECT c.*,r.rule_code,r.name_th,r.severity,u.employee_code,u.first_name_th,u.last_name_th,u.department,
                       w.id warning_id,w.warning_no,w.issued_at,w.delivered_at,w.acknowledged_at
                FROM hr_discipline_cases c
                JOIN hr_discipline_rules r ON r.id=c.rule_id JOIN users u ON u.id=c.user_id
                LEFT JOIN hr_warning_letters w ON w.case_id=c.id
                WHERE {$where}
                ORDER BY CASE r.severity WHEN 'URGENT' THEN 0 ELSE 1 END,c.detected_at DESC,c.id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(in_array($status,['ALL','OPEN'],true) ? [] : [$status]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $events=$this->pdo->prepare('SELECT event_date,event_type,late_minutes FROM hr_discipline_case_events WHERE case_id=? ORDER BY event_date,id');
        foreach($rows as &$row){$events->execute([(int)$row['id']]);$row['events']=$events->fetchAll(PDO::FETCH_ASSOC);}unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    public function getCase(int $caseId, bool $forUpdate = false): array
    {
        $stmt = $this->pdo->prepare("SELECT c.*,r.rule_code,r.name_th,r.rule_type,r.threshold_value,r.severity,r.config_json,u.employee_code,u.first_name_th,u.last_name_th,u.department,u.line_user_id,
                    w.id warning_id,w.warning_no,w.subject,w.body_text,w.policy_reference,w.document_sha256,w.public_id,w.issued_at,w.expires_at,w.delivered_at,w.acknowledged_at,w.disputed_at
             FROM hr_discipline_cases c JOIN hr_discipline_rules r ON r.id=c.rule_id JOIN users u ON u.id=c.user_id
             LEFT JOIN hr_warning_letters w ON w.case_id=c.id WHERE c.id=?" . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('ไม่พบเคสวินัยการเข้างาน');
        $ev = $this->pdo->prepare('SELECT * FROM hr_discipline_case_events WHERE case_id=? ORDER BY event_date,id');
        $ev->execute([$caseId]);
        $row['events'] = $ev->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    public function markReviewing(int $caseId, int $actorId, string $note = ''): void
    {
        $this->transition($caseId,['DETECTED'],'UNDER_REVIEW',$actorId,$note,'UNDER_REVIEW');
    }

    public function cancel(int $caseId, int $actorId, string $reason): void
    {
        if (trim($reason) === '') throw new InvalidArgumentException('กรุณาระบุเหตุผลที่ยกเลิก');
        $this->pdo->beginTransaction();
        try {
            $case = $this->getCase($caseId,true);
            if (in_array($case['status'],['ACKNOWLEDGED','CANCELLED','EXPIRED'],true)) throw new DomainException('สถานะปัจจุบันไม่สามารถยกเลิกได้');
            $stmt=$this->pdo->prepare("UPDATE hr_discipline_cases SET status='CANCELLED',hr_note=?,cancelled_by=?,cancelled_at=NOW() WHERE id=?");
            $stmt->execute([$reason,$actorId,$caseId]);
            $this->audit($caseId,(int)($case['warning_id']??0)?:null,'CANCELLED',$actorId,['reason'=>$reason],'cancel:'.$caseId);
            $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    /** @return array{case:array<string,mixed>,token:string} */
    public function issueWarning(int $caseId, int $actorId, string $subject, string $body, string $policyReference): array
    {
        $subject=trim($subject); $body=trim($body); $policyReference=trim($policyReference);
        if ($subject==='' || mb_strlen($body)<40) throw new InvalidArgumentException('กรุณาระบุหัวข้อและรายละเอียดหนังสือเตือนให้ครบถ้วน');
        $this->pdo->beginTransaction();
        try {
            $case=$this->getCase($caseId,true);
            if (!in_array($case['status'],['UNDER_REVIEW','AWAITING_EXPLANATION','DRAFT'],true)) throw new DomainException('ต้องรับเคสเข้าตรวจสอบก่อนออกหนังสือเตือน');
            $this->assertEvidenceStillValid($case);
            if (empty($case['line_user_id'])) throw new DomainException('พนักงานยังไม่ได้เชื่อมบัญชี LINE');
            if (!empty($case['warning_id'])) throw new DomainException('เคสนี้มีหนังสือเตือนแล้ว');
            $publicId=$this->uuidV4(); $rawToken=bin2hex(random_bytes(24));
            $warningNo=$this->nextNumber('WRN','hr_warning_letters','warning_no');
            $canonical=json_encode(['case_no'=>$case['case_no'],'warning_no'=>$warningNo,'user_id'=>(int)$case['user_id'],'subject'=>$subject,'body'=>$body,'policy_reference'=>$policyReference,'events'=>array_map(fn($e)=>[$e['event_date'],$e['event_type'],(int)($e['late_minutes']??0)],$case['events'])],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $documentHash=hash('sha256',(string)$canonical);
            $stmt=$this->pdo->prepare("INSERT INTO hr_warning_letters (case_id,warning_no,subject,body_text,policy_reference,consent_version,document_sha256,public_id,token_hash,issued_by,issued_at,valid_until,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),DATE_ADD(?,INTERVAL 1 YEAR),DATE_ADD(NOW(),INTERVAL " . self::TOKEN_DAYS . " DAY))");
            $stmt->execute([$caseId,$warningNo,$subject,$body,$policyReference,self::CONSENT_VERSION,$documentHash,$publicId,hash('sha256',$rawToken),$actorId,$case['period_end']]);
            $warningId=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare("UPDATE hr_discipline_cases SET status='APPROVED',reviewed_by=?,reviewed_at=NOW(),hr_note=? WHERE id=?")->execute([$actorId,$policyReference,$caseId]);
            $this->audit($caseId,$warningId,'WARNING_ISSUED',$actorId,['warning_no'=>$warningNo,'document_sha256'=>$documentHash],'issue:'.$warningId);
            $this->pdo->commit();
            return ['case'=>$this->getCase($caseId),'token'=>$publicId.'.'.$rawToken];
        } catch(Throwable $e){ if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }

    /** @return array<string,mixed> */
    public function publicContext(string $token, string $lineUserId): array
    {
        $row=$this->warningByToken($token);
        if (!hash_equals((string)$row['line_user_id'],trim($lineUserId))) throw new DomainException('บัญชี LINE นี้ไม่ใช่ผู้รับหนังสือเตือน');
        if (strtotime((string)$row['expires_at']) < time() && empty($row['acknowledged_at']) && empty($row['disputed_at'])) throw new DomainException('ลิงก์รับทราบหมดอายุ กรุณาติดต่อ HR');
        unset($row['line_user_id'],$row['token_hash']);
        $row['consent_text']=$this->consentText((string)$row['warning_no']);
        return $row;
    }

    /** @return array<string,mixed> */
    public function acknowledge(string $token,string $lineUserId,string $action,string $note,?string $signatureData,bool $accepted,string $ip,string $userAgent): array
    {
        $action=strtoupper($action);
        if (!in_array($action,['ACKNOWLEDGED','DISPUTED'],true)) throw new InvalidArgumentException('การดำเนินการไม่ถูกต้อง');
        if ($action==='ACKNOWLEDGED' && !$signatureData) throw new InvalidArgumentException('กรุณาลงลายมือชื่อก่อนรับทราบ');
        if ($action==='ACKNOWLEDGED' && !$accepted) throw new InvalidArgumentException('กรุณายืนยันว่าได้อ่านเอกสารแล้ว');
        if ($action==='DISPUTED' && trim($note)==='') throw new InvalidArgumentException('กรุณาระบุคำชี้แจงหรือเหตุผลที่คัดค้าน');
        $signatureBytes=$signatureData ? $this->decodeSignature($signatureData) : null;
        $this->pdo->beginTransaction();
        try {
            $row=$this->warningByToken($token,true);
            if (!hash_equals((string)$row['line_user_id'],trim($lineUserId))) throw new DomainException('บัญชี LINE นี้ไม่ใช่ผู้รับหนังสือเตือน');
            if (!empty($row['acknowledged_at']) || !empty($row['disputed_at'])) throw new DomainException('หนังสือเตือนฉบับนี้ได้รับการตอบกลับแล้ว');
            if (strtotime((string)$row['expires_at']) < time()) throw new DomainException('ลิงก์รับทราบหมดอายุ กรุณาติดต่อ HR');
            $consent=$this->consentText((string)$row['warning_no']);
            $stmt=$this->pdo->prepare("INSERT INTO hr_warning_acknowledgements (warning_id,user_id,action,employee_note,signature_png,signature_sha256,document_sha256,line_user_id_hash,consent_text,consent_version,ip_address_hash,user_agent_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([(int)$row['warning_id'],(int)$row['user_id'],$action,trim($note),$signatureBytes,$signatureBytes?hash('sha256',$signatureBytes):null,$row['document_sha256'],hash('sha256',$lineUserId),$consent,self::CONSENT_VERSION,hash('sha256',$ip),hash('sha256',$userAgent)]);
            $dateColumn=$action==='ACKNOWLEDGED'?'acknowledged_at':'disputed_at';
            $this->pdo->prepare("UPDATE hr_warning_letters SET {$dateColumn}=NOW() WHERE id=?")->execute([(int)$row['warning_id']]);
            $this->pdo->prepare('UPDATE hr_discipline_cases SET status=?,employee_explanation=? WHERE id=?')->execute([$action,trim($note),(int)$row['case_id']]);
            $this->audit((int)$row['case_id'],(int)$row['warning_id'],$action,null,['document_sha256'=>$row['document_sha256'],'note'=>trim($note)],'response:'.$row['warning_id'],hash('sha256',$lineUserId));
            $this->pdo->commit();
            return ['warning_no'=>$row['warning_no'],'status'=>$action,'responded_at'=>date('c')];
        } catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function markDelivered(int $warningId): void
    {
        $this->pdo->prepare("UPDATE hr_warning_letters w JOIN hr_discipline_cases c ON c.id=w.case_id SET w.delivered_at=COALESCE(w.delivered_at,NOW()),c.status=IF(c.status='APPROVED','DELIVERED',c.status) WHERE w.id=?")->execute([$warningId]);
    }

    /** @return array{case:array<string,mixed>,token:string} */
    public function refreshDeliveryToken(int $caseId,int $actorId): array
    {
        $this->pdo->beginTransaction();
        try{
            $case=$this->getCase($caseId,true);
            if(empty($case['warning_id']))throw new DomainException('ยังไม่มีหนังสือเตือนสำหรับส่งซ้ำ');
            if(!empty($case['acknowledged_at']))throw new DomainException('พนักงานรับทราบหนังสือเตือนแล้ว');
            $raw=bin2hex(random_bytes(24));
            $this->pdo->prepare("UPDATE hr_warning_letters SET token_hash=?,expires_at=DATE_ADD(NOW(),INTERVAL ".self::TOKEN_DAYS." DAY) WHERE id=?")->execute([hash('sha256',$raw),(int)$case['warning_id']]);
            $this->audit($caseId,(int)$case['warning_id'],'DELIVERY_TOKEN_REFRESHED',$actorId,[], 'resend:'.$case['warning_id'].':'.date('YmdHis'));
            $this->pdo->commit();
            return ['case'=>$this->getCase($caseId),'token'=>$case['public_id'].'.'.$raw];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** @return array{filename:string,sha256:string,base64:string} */
    public function pdf(string $token,string $lineUserId): array
    {
        $row=$this->warningByToken($token);
        if(!hash_equals((string)$row['line_user_id'],trim($lineUserId)))throw new DomainException('บัญชี LINE นี้ไม่ใช่ผู้รับหนังสือเตือน');
        $ack=$this->pdo->prepare('SELECT * FROM hr_warning_acknowledgements WHERE warning_id=? LIMIT 1');$ack->execute([(int)$row['warning_id']]);$response=$ack->fetch(PDO::FETCH_ASSOC)?:null;
        if(!class_exists(\Mpdf\Mpdf::class))throw new RuntimeException('ระบบสร้าง PDF ยังไม่พร้อมใช้งาน');
        $tmp=sys_get_temp_dir().'/tp-hr-mpdf';if(!is_dir($tmp)&&!mkdir($tmp,0700,true)&&!is_dir($tmp))throw new RuntimeException('ไม่สามารถเตรียมพื้นที่สร้าง PDF');
        $pdf=new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4','tempDir'=>$tmp,'default_font'=>'dejavusans','margin_left'=>18,'margin_right'=>18,'margin_top'=>16,'margin_bottom'=>16]);
        $sig='';if($response&&!empty($response['signature_png']))$sig='<img src="data:image/png;base64,'.base64_encode((string)$response['signature_png']).'" style="max-width:180px;max-height:70px">';
        $esc=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
        $status=$response?($response['action']==='ACKNOWLEDGED'?'รับทราบแล้ว':'คัดค้าน/ชี้แจง'):'รอการตอบรับ';
        $html='<style>body{font-family:dejavusans;font-size:12pt;line-height:1.65;color:#111827}h1{text-align:center;font-size:20pt}.meta{width:100%;border-collapse:collapse;margin:20px 0}.meta td{border:1px solid #cbd5e1;padding:8px}.label{color:#475569;width:28%}.box{border:1px solid #94a3b8;padding:14px;margin:14px 0}.foot{font-size:9pt;color:#64748b;margin-top:20px}</style><h1>หนังสือเตือนการเข้างาน</h1><table class="meta"><tr><td class="label">เลขที่</td><td>'.$esc($row['warning_no']).'</td></tr><tr><td class="label">วันที่ออก</td><td>'.$esc($row['issued_at']).'</td></tr><tr><td class="label">พนักงาน</td><td>'.$esc(trim($row['first_name_th'].' '.$row['last_name_th'])).' ('.$esc($row['employee_code']).')</td></tr><tr><td class="label">เรื่อง</td><td>'.$esc($row['subject']).'</td></tr></table><div class="box">'.nl2br($esc($row['body_text'])).'</div><p><strong>ข้อบังคับ/ระเบียบที่อ้างอิง:</strong> '.$esc($row['policy_reference']).'</p><div class="box"><strong>ผลการตอบรับ:</strong> '.$esc($status).'<br><strong>คำชี้แจง:</strong> '.$esc($response['employee_note']??'-').'<br>'.$sig.($response?'<br>วันที่ตอบรับ '.$esc($response['acknowledged_at']):'').'</div><p class="foot">Document SHA-256: '.$esc($row['document_sha256']).'<br>เอกสารอิเล็กทรอนิกส์สร้างจากระบบ TP-HR และหลักฐานการตอบรับผ่านบัญชี LINE ที่เชื่อมกับพนักงาน</p>';
        $pdf->WriteHTML($html);$bytes=$pdf->Output('',\Mpdf\Output\Destination::STRING_RETURN);
        return ['filename'=>$row['warning_no'].'.pdf','sha256'=>hash('sha256',$bytes),'base64'=>base64_encode($bytes)];
    }

    private function warningByToken(string $token,bool $forUpdate=false): array
    {
        if (!preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{48})$/i',trim($token),$m)) throw new DomainException('ลิงก์รับทราบไม่ถูกต้อง');
        $stmt=$this->pdo->prepare("SELECT w.id warning_id,w.*,c.case_no,c.status case_status,c.user_id,c.period_start,c.period_end,c.occurrence_count,r.name_th,r.rule_code,r.severity,u.employee_code,u.first_name_th,u.last_name_th,u.department,u.line_user_id FROM hr_warning_letters w JOIN hr_discipline_cases c ON c.id=w.case_id JOIN hr_discipline_rules r ON r.id=c.rule_id JOIN users u ON u.id=c.user_id WHERE w.public_id=?".($forUpdate?' FOR UPDATE':''));
        $stmt->execute([strtolower($m[1])]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row || !hash_equals((string)$row['token_hash'],hash('sha256',strtolower($m[2])))) throw new DomainException('ลิงก์รับทราบไม่ถูกต้อง');
        return $row;
    }

    private function eventsForRule(array $rule,array $summary,PayrollService $payroll,int $userId,string $start,string $end): array
    {
        if ($rule['rule_type']==='LATE_COUNT') {
            $stmt=$this->pdo->prepare("SELECT id,attendance_date,late_minutes,check_in_time,planned_start_time,planned_status FROM hr_attendances WHERE user_id=? AND attendance_date BETWEEN ? AND ? AND status='LATE' AND COALESCE(late_excused,0)=0 ORDER BY attendance_date");
            $stmt->execute([$userId,$start,$end]);$ctx=$payroll->buildWorkdayContext($userId,$start,$end);$events=[];$grace=30;try{$q=$this->pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='payroll_planned_grace_minutes' LIMIT 1");$q->execute();$grace=max(0,(int)($q->fetchColumn()?:30));}catch(Throwable $e){}
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){if(!$payroll->isPayrollWorkday($ctx,(string)$row['attendance_date']))continue;if(($row['planned_status']??'')==='APPROVED'&&!empty($row['planned_start_time'])&&!empty($row['check_in_time'])){$delta=(strtotime((string)$row['check_in_time'])-strtotime($row['attendance_date'].' '.$row['planned_start_time']))/60;if($delta<=$grace)continue;}$events[]=['date'=>$row['attendance_date'],'type'=>'LATE','late_minutes'=>(int)$row['late_minutes'],'attendance_id'=>(int)$row['id']];}
            return $events;
        }
        $all=[];
        foreach ($summary['breakdown'] ?? [] as $item) {
            $kind=(string)($item['kind']??'');
            if (str_starts_with($kind,'late_')) $all[]=['date'=>$item['date'],'type'=>'LATE','late_minutes'=>$this->lateMinutesFromNote((string)($item['note']??'')),'kind'=>$kind];
            if (in_array($kind,['absent','missing_attendance_absent'],true)) $all[]=['date'=>$item['date'],'type'=>$kind==='absent'?'ABSENT':'MISSING_ATTENDANCE','kind'=>$kind];
        }
        $abs=[]; foreach($all as $e) if(in_array($e['type'],['ABSENT','MISSING_ATTENDANCE'],true))$abs[$e['date']]=$e;
        $ctx=$payroll->buildWorkdayContext($userId,$start,$end); $streak=[];$best=[];
        for($ts=strtotime($start);$ts<=strtotime($end);$ts+=86400){$d=date('Y-m-d',$ts);if(!$payroll->isPayrollWorkday($ctx,$d))continue;if(isset($abs[$d])){$streak[]=$abs[$d];if(count($streak)>count($best))$best=$streak;}else{$streak=[];}}
        return count($best)>=(int)$rule['threshold_value']?$best:[];
    }

    private function assertEvidenceStillValid(array $case): void
    {
        require_once __DIR__.'/PayrollService.php';$payroll=new PayrollService($this->pdo);$summary=$payroll->computeAttendanceDeductions((int)$case['user_id'],(string)$case['payroll_month'].'-01');$events=$this->eventsForRule($case,$summary,$payroll,(int)$case['user_id'],(string)$case['period_start'],(string)$case['period_end']);
        if(count($events)<(int)$case['threshold_value'])throw new DomainException('หลักฐานการลงเวลาถูกแก้ไขแล้วและไม่ถึงเกณฑ์ กรุณายกเลิกเคสหรือสแกนใหม่');
        $current=hash('sha256',$case['rule_code'].'|'.$case['user_id'].'|'.implode(',',array_column($events,'date')));
        if(!hash_equals((string)$case['evidence_fingerprint'],$current))throw new DomainException('หลักฐานเปลี่ยนแปลงหลังสร้างเคส กรุณาสแกนใหม่ก่อนออกหนังสือเตือน');
    }

    private function transition(int $caseId,array $from,string $to,int $actorId,string $note,string $event): void
    {
        $this->pdo->beginTransaction();try{$case=$this->getCase($caseId,true);if(!in_array($case['status'],$from,true))throw new DomainException('สถานะเคสเปลี่ยนไปแล้ว กรุณาโหลดหน้าใหม่');$this->pdo->prepare('UPDATE hr_discipline_cases SET status=?,hr_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?')->execute([$to,trim($note),$actorId,$caseId]);$this->audit($caseId,null,$event,$actorId,['note'=>trim($note)],strtolower($event).':'.$caseId);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function audit(int $caseId,?int $warningId,string $event,?int $actorId,array $payload,string $key,?string $lineHash=null): void{$this->pdo->prepare('INSERT IGNORE INTO hr_discipline_audit_logs (case_id,warning_id,event_type,actor_user_id,actor_line_user_id_hash,payload_json,idempotency_key) VALUES (?,?,?,?,?,?,?)')->execute([$caseId,$warningId,$event,$actorId,$lineHash,json_encode($payload,JSON_UNESCAPED_UNICODE),$key]);}
    private function nextNumber(string $prefix,string $table,string $column): string{$base=$prefix.'-'.date('Ym').'-';$stmt=$this->pdo->prepare("SELECT {$column} FROM {$table} WHERE {$column} LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE");$stmt->execute([$base.'%']);$last=(string)($stmt->fetchColumn()?:'');$n=$last?(int)substr($last,-5)+1:1;return $base.str_pad((string)$n,5,'0',STR_PAD_LEFT);}
    private function uuidV4(): string{$d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}
    private function lateMinutesFromNote(string $note): int{return preg_match('/มาสาย\s+(\d+)\s+นาที/u',$note,$m)?(int)$m[1]:0;}
    private function consentText(string $warningNo): string{return 'ข้าพเจ้ายืนยันว่าได้เปิดอ่านหนังสือเตือนเลขที่ '.$warningNo.' ครบถ้วนแล้ว การลงลายมือชื่อเป็นการรับทราบว่าได้รับเอกสาร ไม่จำเป็นต้องหมายถึงการยอมรับข้อกล่าวหา และข้าพเจ้าสามารถบันทึกคำชี้แจงหรือคัดค้านได้';}
    private function decodeSignature(string $dataUrl): string{if(!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#',$dataUrl,$m))throw new InvalidArgumentException('รูปแบบลายมือชื่อไม่ถูกต้อง');$bytes=base64_decode($m[1],true);if($bytes===false||strlen($bytes)<200||strlen($bytes)>800000)throw new InvalidArgumentException('ขนาดลายมือชื่อไม่ถูกต้อง');$info=@getimagesizefromstring($bytes);if(!$info||($info['mime']??'')!=='image/png'||$info[0]<120||$info[1]<40)throw new InvalidArgumentException('ลายมือชื่อไม่ใช่ภาพ PNG ที่รองรับ');return $bytes;}
}

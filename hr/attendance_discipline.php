<?php

$page_title = 'วินัยการเข้างาน';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/Services/AttendanceDisciplineService.php';
require_once dirname(__DIR__) . '/core/CrmLineNotifierBridge.php';

Auth::requireLogin();
if (!hr_can_access_hr_dashboard() || !Auth::hasRole(['HR','Admin','Chairman','CEO'])) {
    flash('error','คุณไม่มีสิทธิ์จัดการวินัยการเข้างาน');
    redirect('/',302);
}
$pdo=Database::getInstance()->getConnection();
$service=new AttendanceDisciplineService($pdo);
$user=Auth::user();
$current_page='hr-attendance-discipline';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verifyCsrfToken($_POST['_token']??'')) { flash('error','เซสชันหมดอายุ กรุณาลองใหม่'); redirect('/hr/attendance_discipline.php',302); }
    try {
        $action=(string)($_POST['action']??''); $caseId=(int)($_POST['case_id']??0);
        if ($action==='scan') {
            $result=$service->detect(trim((string)($_POST['payroll_month']??''))?:null);
            flash('success','ตรวจสอบแล้ว: สร้างใหม่ '.(int)$result['created'].' เคส · มีอยู่แล้ว '.(int)$result['existing'].' เคส');
        } elseif ($action==='save_rule') {
            $service->updateRule((int)($_POST['rule_id']??0),(int)($_POST['threshold']??0),isset($_POST['is_active']));
            Auth::log('ATTENDANCE_DISCIPLINE_RULE_UPDATED','hr_discipline_rules',(int)($_POST['rule_id']??0),null,['threshold'=>(int)($_POST['threshold']??0),'is_active'=>isset($_POST['is_active'])]);
            flash('success','บันทึกเกณฑ์ตรวจจับแล้ว');
        } elseif ($action==='review') {
            $service->markReviewing($caseId,(int)$user['id'],trim((string)($_POST['note']??'')));
            flash('success','รับเคสเข้าตรวจสอบแล้ว');
        } elseif ($action==='cancel') {
            $service->cancel($caseId,(int)$user['id'],trim((string)($_POST['note']??'')));
            flash('success','ยกเลิกเคสพร้อมบันทึกเหตุผลแล้ว');
        } elseif ($action==='issue') {
            $result=$service->issueWarning($caseId,(int)$user['id'],trim((string)($_POST['subject']??'')),trim((string)($_POST['body_text']??'')),trim((string)($_POST['policy_reference']??'')));
            $sent=function_exists('hr_send_discipline_warning') && hr_send_discipline_warning($pdo,$result['case'],$result['token']);
            if ($sent) { $service->markDelivered((int)$result['case']['warning_id']); flash('success','ออกหนังสือเตือนและส่ง LINE ให้พนักงานแล้ว'); }
            else { flash('warning','ออกหนังสือเตือนแล้ว แต่ส่ง LINE ไม่สำเร็จ กรุณาตรวจการผูก LINE และการตั้งค่าแจ้งเตือน'); }
        } elseif ($action==='resend') {
            $result=$service->refreshDeliveryToken($caseId,(int)$user['id']);
            $sent=function_exists('hr_send_discipline_warning') && hr_send_discipline_warning($pdo,$result['case'],$result['token']);
            if($sent){$service->markDelivered((int)$result['case']['warning_id']);flash('success','ส่งหนังสือเตือนผ่าน LINE ซ้ำแล้ว');}
            else flash('error','ส่ง LINE ไม่สำเร็จ กรุณาตรวจการผูก LINE และการตั้งค่าแจ้งเตือน');
        } else throw new InvalidArgumentException('ไม่รองรับคำสั่งนี้');
    } catch(Throwable $e){ flash('error',$e->getMessage()); }
    redirect('/hr/attendance_discipline.php?status='.urlencode((string)($_GET['status']??'OPEN')),302);
}

$status=strtoupper((string)($_GET['status']??'OPEN'));
if(!in_array($status,['OPEN','DETECTED','UNDER_REVIEW','DELIVERED','ACKNOWLEDGED','DISPUTED','CANCELLED','ALL'],true))$status='OPEN';
$cases=$service->listCases($status);
$rules=$service->listRules();
$defaultMonth=(new PayrollService($pdo))->suggestPayrollMonth();
include dirname(__DIR__).'/templates/header.php';
?>
<main class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(1180px,100%)] mx-auto min-w-0" id="main-content">
  <header class="tp-ios-large-title-block mb-6">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb"><a href="/hr/index.php" class="tp-tap-48 hover:text-white">แดชบอร์ด HR</a><span class="mx-2">/</span><span class="text-white">วินัยการเข้างาน</span></nav>
    <h1 class="tp-ios-page-title">วินัยการเข้างานและหนังสือเตือน</h1>
    <p class="tp-ios-caption-muted mt-2 max-w-3xl">ระบบสร้างเพียงเคสรอตรวจสอบจากหลักฐานการลงเวลา HR ต้องตรวจข้อเท็จจริงก่อนออกหนังสือทุกครั้ง และพนักงานมีสิทธิ์ชี้แจงหรือคัดค้าน</p>
  </header>
  <?php if($m=flash('success')):?><div role="status" class="mb-4 rounded-2xl border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-emerald-100"><?=htmlspecialchars($m)?></div><?php endif;?>
  <?php if($m=flash('warning')):?><div role="status" class="mb-4 rounded-2xl border border-amber-500/30 bg-amber-500/15 px-4 py-3 text-amber-100"><?=htmlspecialchars($m)?></div><?php endif;?>
  <?php if($m=flash('error')):?><div role="alert" class="mb-4 rounded-2xl border border-red-500/30 bg-red-500/15 px-4 py-3 text-red-100"><?=htmlspecialchars($m)?></div><?php endif;?>

  <section class="native-card tp-native-card rounded-2xl p-5 mb-6" aria-labelledby="scan-title">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4"><div><h2 id="scan-title" class="text-lg font-semibold text-white">ตรวจรอบเงินเดือน</h2><p class="text-sm text-white/60 mt-1">ค่าเริ่มต้น: มาสายครบ 3 ครั้ง และขาดงาน 3 วันทำงานติดต่อกัน ระบบไม่ออกหนังสืออัตโนมัติ</p></div>
    <form method="post" class="flex flex-col sm:flex-row gap-2 sm:items-end" onsubmit="this.querySelector('button').disabled=true"><input type="hidden" name="_token" value="<?=htmlspecialchars(csrfToken())?>"><input type="hidden" name="action" value="scan"><label class="text-sm text-white/70">รอบเงินเดือน<input type="month" name="payroll_month" value="<?=htmlspecialchars($defaultMonth)?>" class="input-field tp-native-input min-h-[52px] mt-1" required></label><button class="min-h-[48px] px-5 rounded-xl bg-violet-600 hover:bg-violet-700 text-white font-semibold whitespace-nowrap touch-manipulation"><i class="fas fa-magnifying-glass mr-2" aria-hidden="true"></i>ตรวจหาเคส</button></form></div>
  </section>
  <details class="native-card tp-native-card rounded-2xl p-5 mb-6"><summary class="cursor-pointer min-h-[48px] flex items-center text-lg font-semibold text-white"><i class="fas fa-sliders mr-3 text-violet-300" aria-hidden="true"></i>ตั้งค่าเกณฑ์ตรวจจับ</summary><div class="grid gap-3 mt-4"><?php foreach($rules as $rule):?><form method="post" class="grid grid-cols-1 md:grid-cols-[1fr_130px_110px_auto] gap-3 items-end rounded-xl bg-white/5 border border-white/10 p-4"><input type="hidden" name="_token" value="<?=htmlspecialchars(csrfToken())?>"><input type="hidden" name="action" value="save_rule"><input type="hidden" name="rule_id" value="<?=(int)$rule['id']?>"><div><strong class="text-white"><?=htmlspecialchars($rule['name_th'])?></strong><p class="text-xs text-white/50 mt-1"><?=htmlspecialchars($rule['rule_code'])?> · <?=$rule['severity']==='URGENT'?'ต้องตรวจทางกฎหมาย':'ตรวจสอบปกติ'?></p></div><label class="text-sm text-white/70">จำนวนครั้ง/วัน<input type="number" name="threshold" min="1" max="30" value="<?=(int)$rule['threshold_value']?>" class="input-field tp-native-input min-h-[52px] mt-1" required></label><label class="min-h-[52px] flex items-center gap-2 text-white/80"><input type="checkbox" name="is_active" value="1" class="w-6 h-6" <?=(int)$rule['is_active']===1?'checked':''?>> เปิดใช้</label><button class="min-h-[48px] px-4 rounded-xl bg-slate-700 hover:bg-slate-600 text-white font-semibold">บันทึก</button></form><?php endforeach;?></div></details>

  <nav class="native-card tp-native-card p-3 mb-6 flex gap-2 overflow-x-auto" aria-label="กรองสถานะ">
    <?php foreach(['OPEN'=>'กำลังดำเนินการ','DETECTED'=>'ตรวจพบใหม่','UNDER_REVIEW'=>'กำลังตรวจสอบ','DELIVERED'=>'ส่งแล้ว','ACKNOWLEDGED'=>'รับทราบแล้ว','DISPUTED'=>'คัดค้าน','CANCELLED'=>'ยกเลิก','ALL'=>'ทั้งหมด'] as $k=>$label):?><a href="?status=<?=$k?>" class="min-h-[48px] inline-flex items-center px-4 rounded-xl whitespace-nowrap touch-manipulation <?=$status===$k?'bg-violet-600 text-white':'bg-white/5 text-white/75 hover:bg-white/10'?>"><?=htmlspecialchars($label)?></a><?php endforeach;?>
  </nav>

  <?php if(!$cases):?><section class="tp-native-empty-state rounded-2xl border border-dashed border-white/15 p-12 text-center"><i class="fas fa-shield-check text-4xl text-white/30" aria-hidden="true"></i><h2 class="text-white font-semibold mt-3">ไม่มีเคสในสถานะนี้</h2><p class="text-white/50 mt-1">เลือกตรวจรอบเงินเดือนเพื่อประเมินจากข้อมูลล่าสุด</p></section><?php else:?><div class="space-y-4">
  <?php foreach($cases as $c):$urgent=$c['severity']==='URGENT';?>
    <article class="native-card tp-native-card rounded-2xl p-5 border <?=$urgent?'border-red-500/35':'border-white/10'?>">
      <div class="flex flex-col md:flex-row md:justify-between gap-3"><div><div class="flex flex-wrap items-center gap-2"><h2 class="text-lg font-semibold text-white"><?=htmlspecialchars(trim($c['first_name_th'].' '.$c['last_name_th']))?></h2><?php if($urgent):?><span class="rounded-full bg-red-500/20 text-red-200 px-3 py-1 text-xs font-semibold">เคสด่วน</span><?php endif;?></div><p class="text-sm text-white/55 mt-1"><?=htmlspecialchars(($c['employee_code']?:'-').' · '.($c['department']?:'ไม่ระบุแผนก').' · '.$c['case_no'])?></p></div><span class="self-start rounded-full bg-white/10 text-white/80 px-3 py-1 text-sm font-semibold"><?=htmlspecialchars($c['status'])?></span></div>
      <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4 text-sm"><div><dt class="text-white/50">เหตุที่ตรวจพบ</dt><dd class="text-white mt-1"><?=htmlspecialchars($c['name_th'])?></dd></div><div><dt class="text-white/50">จำนวน</dt><dd class="text-white mt-1 tabular-nums"><?=htmlspecialchars((string)$c['occurrence_count'])?> ครั้ง/วัน</dd></div><div><dt class="text-white/50">ช่วงข้อมูล</dt><dd class="text-white mt-1 tabular-nums"><?=htmlspecialchars($c['period_start'].' – '.$c['period_end'])?></dd></div></dl>
      <div class="mt-4"><p class="text-xs font-semibold uppercase tracking-wide text-white/45">หลักฐานจากการลงเวลา</p><div class="flex flex-wrap gap-2 mt-2"><?php foreach(($c['events']??[]) as $event):?><span class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/80 tabular-nums"><?=htmlspecialchars((string)$event['event_date'])?> · <?=htmlspecialchars($event['event_type']==='LATE'?'มาสาย '.(int)$event['late_minutes'].' นาที':'ขาดงาน')?></span><?php endforeach;?></div></div>
      <?php if(!empty($c['warning_no'])):?><div class="mt-4 rounded-xl bg-violet-500/10 border border-violet-500/20 p-4 text-sm text-violet-100"><strong>หนังสือเตือน <?=htmlspecialchars($c['warning_no'])?></strong><?php if($c['acknowledged_at']):?> · รับทราบ <?=htmlspecialchars($c['acknowledged_at'])?><?php elseif($c['delivered_at']):?> · ส่ง LINE แล้ว <?=htmlspecialchars($c['delivered_at'])?><?php endif;?></div><?php endif;?>
      <?php if(!empty($c['warning_id']) && empty($c['acknowledged_at'])):?><form method="post" class="mt-3"><input type="hidden" name="_token" value="<?=htmlspecialchars(csrfToken())?>"><input type="hidden" name="action" value="resend"><input type="hidden" name="case_id" value="<?=(int)$c['id']?>"><button class="min-h-[48px] px-4 rounded-xl bg-violet-600 hover:bg-violet-700 text-white font-semibold"><i class="fas fa-rotate mr-2"></i>ส่ง LINE ซ้ำ</button></form><?php endif;?>
      <?php if(in_array($c['status'],['DETECTED','UNDER_REVIEW','DISPUTED'],true)):?>
      <div class="mt-5 grid gap-3">
        <?php if($c['status']==='DETECTED'):?><form method="post" class="flex gap-2"><input type="hidden" name="_token" value="<?=htmlspecialchars(csrfToken())?>"><input type="hidden" name="action" value="review"><input type="hidden" name="case_id" value="<?=(int)$c['id']?>"><button class="min-h-[48px] px-5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold whitespace-nowrap"><i class="fas fa-clipboard-check mr-2"></i>รับเข้าตรวจสอบ</button></form><?php endif;?>
        <?php if(in_array($c['status'],['UNDER_REVIEW','AWAITING_EXPLANATION','DRAFT'],true)):?><details class="rounded-xl bg-white/5 border border-white/10 p-4"><summary class="cursor-pointer min-h-[48px] flex items-center font-semibold text-white">ออกหนังสือเตือน</summary><form method="post" class="grid gap-3 mt-3" onsubmit="return confirm('ยืนยันออกหนังสือเตือนและส่งให้พนักงานทาง LINE?')"><input type="hidden" name="_token" value="<?=htmlspecialchars(csrfToken())?>"><input type="hidden" name="action" value="issue"><input type="hidden" name="case_id" value="<?=(int)$c['id']?>"><label class="text-sm text-white/70">หัวข้อ<input name="subject" maxlength="255" required value="หนังสือเตือนเรื่อง <?=htmlspecialchars($c['name_th'])?>" class="input-field tp-native-input min-h-[52px] mt-1"></label><label class="text-sm text-white/70">รายละเอียด<textarea name="body_text" rows="5" minlength="40" required class="input-field tp-native-input mt-1">ตามที่ระบบบันทึกการเข้างานตรวจพบว่า <?=htmlspecialchars($c['name_th'])?> ในช่วง <?=htmlspecialchars($c['period_start'].' ถึง '.$c['period_end'])?> บริษัทขอให้ท่านปฏิบัติตามระเบียบการทำงานอย่างเคร่งครัด หากมีข้อมูลหรือเหตุอันสมควรเพิ่มเติม กรุณาชี้แจงต่อฝ่ายทรัพยากรบุคคล</textarea></label><label class="text-sm text-white/70">ข้อบังคับ/ระเบียบที่อ้างอิง<input name="policy_reference" maxlength="500" required class="input-field tp-native-input min-h-[52px] mt-1" placeholder="เช่น ข้อบังคับการทำงาน หมวดการลงเวลา ข้อ ..."></label><button class="min-h-[48px] rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-semibold"><i class="fas fa-paper-plane mr-2"></i>ออกเอกสารและส่ง LINE</button></form></details><?php endif;?>
        <details class="rounded-xl bg-white/5 border border-white/10 p-4"><summary class="cursor-pointer min-h-[48px] flex items-center font-semibold text-white">ยกเลิกเคส</summary><form method="post" class="grid sm:grid-cols-[1fr_auto] gap-2 mt-3" onsubmit="return confirm('ยืนยันยกเลิกเคสนี้?')"><input type="hidden" name="_token" value="<?=htmlspecialchars(csrfToken())?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="case_id" value="<?=(int)$c['id']?>"><label class="sr-only" for="cancel-<?=(int)$c['id']?>">เหตุผลยกเลิก</label><input id="cancel-<?=(int)$c['id']?>" name="note" required maxlength="500" class="input-field tp-native-input min-h-[52px]" placeholder="เหตุผล เช่น มีใบลาอนุมัติย้อนหลัง"><button class="min-h-[48px] px-5 rounded-xl bg-red-700 hover:bg-red-800 text-white font-semibold">ยกเลิกเคส</button></form></details>
      </div><?php endif;?>
    </article>
  <?php endforeach;?></div><?php endif;?>
</main>
<?php include dirname(__DIR__).'/templates/footer.php'; ?>

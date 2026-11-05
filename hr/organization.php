<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/Services/OrganizationService.php';
Auth::requireLogin();
Auth::requireHR();
$pdo = getDB();
$service = new OrganizationService($pdo);
if (!hr_can_access_hr_dashboard() || !$service->canManage((int)Auth::id())) { http_response_code(403); exit('คุณไม่มีสิทธิ์จัดการโครงสร้างองค์กร'); }
$page_title = 'ตำแหน่งและสถานที่ทำงาน';
$current_page = 'hr-organization';
$tabs = ['positions'=>'ตำแหน่งงาน','workplaces'=>'สถานที่ทำงาน','locations'=>'จุดลงเวลา','assignments'=>'กำหนดให้พนักงาน'];
$tab = (string)($_GET['tab'] ?? 'positions');
if (!isset($tabs[$tab])) $tab = 'positions';
$id = max(0,(int)($_GET['id'] ?? 0));
$error = '';
$ready = $service->ready();
$row = null;
if ($ready && $id) {
    try { $row = $tab === 'assignments' ? $service->assignment($id) : $service->get($tab,$id); }
    catch (DomainException $e) { $error=$e->getMessage(); }
    if (!$row) { http_response_code(404); $error='ไม่พบรายการที่เลือก'; $id=0; }
}
$version = OrganizationService::token($row);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    try {
        if (!verifyCsrf()) throw new DomainException('เซสชันหมดอายุ กรุณาโหลดหน้าใหม่');
        if ($tab === 'assignments') {
            if (!$id) throw new DomainException('กรุณาเลือกพนักงาน');
            $service->assign((int)Auth::id(),$id,(int)($_POST['position_id'] ?? 0),(int)($_POST['workplace_id'] ?? 0),(string)($_POST['version'] ?? ''),!empty($_POST['refresh_title']));
        } else {
            $id = $service->save((int)Auth::id(),$tab,$id,(string)($_POST['version'] ?? ''),$_POST);
        }
        redirect('/hr/organization.php?tab='.$tab.'&id='.$id.'&saved=1',303);
        exit;
    } catch (DomainException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { tpHrLogException($e,'hr/organization save'); $error='บันทึกไม่สำเร็จ ข้อมูลยังไม่ถูกเปลี่ยน กรุณาลองใหม่'; }
    $row = array_replace($row ?? [],array_intersect_key($_POST,array_flip(['code','title','name','address','description','job_description','latitude','longitude','radius_meters','workplace_id','position_id'])));
    $row['is_active'] = empty($_POST['is_active']) ? 0 : 1;
    // Retain the submitted version: a conflict must not become a blind overwrite on retry.
    $version=(string)($_POST['version'] ?? '');
}
$esc = static fn($v)=>htmlspecialchars((string)($v ?? ''),ENT_QUOTES,'UTF-8');
require dirname(__DIR__) . '/templates/header.php';
?>
<div class="tp-hr-admin-stack tp-ios-master-screen w-full mx-auto min-w-0 space-y-6" style="max-width:1200px">
<header><a href="/hr/" class="inline-flex items-center min-h-[48px] text-violet-300">กลับแดชบอร์ด HR</a><h1 class="tp-ios-page-title">ตำแหน่งและสถานที่ทำงาน</h1><p class="text-white/70 mt-2">จัดการข้อมูลบริษัทและพื้นที่ลงเวลา ปิดใช้งานแทนการลบเพื่อเก็บประวัติเดิม</p></header>
<nav aria-label="หมวดโครงสร้างองค์กร" class="flex flex-wrap gap-2">
<?php foreach ($tabs as $key=>$label): ?><a href="?tab=<?= $key ?>" class="inline-flex items-center min-h-[48px] px-4 rounded-xl <?= $tab===$key ? 'bg-violet-600 text-white' : 'bg-white/10 text-white' ?>" <?= $tab===$key ? 'aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?>
</nav>
<?php if ($error): ?><div id="organization-error" tabindex="-1" role="alert" class="p-4 rounded-xl bg-red-500/15 text-red-200"><?= $esc($error) ?></div><?php endif; ?>
<?php if (isset($_GET['saved']) && !$error): ?><div role="status" class="p-4 rounded-xl bg-emerald-500/15 text-emerald-200">บันทึกข้อมูลและประวัติการเปลี่ยนแปลงแล้ว</div><?php endif; ?>
<?php if (!$ready): ?><div role="alert" class="native-card p-6">ยังไม่พร้อมใช้งาน กรุณาให้ผู้ดูแลระบบติดตั้งการปรับปรุงฐานข้อมูลโครงสร้างองค์กรก่อน</div>
<?php else:
$workplaces=$service->rows('workplaces');
$positions=$service->rows('positions');
?>
<?php if ($tab==='assignments'): ?>
<section class="native-card p-4 sm:p-6 space-y-4">
<h2 class="text-xl">เลือกพนักงาน</h2>
<form method="get" class="space-y-4"><input type="hidden" name="tab" value="assignments"><label class="block" for="employee">พนักงาน</label><select id="employee" name="id" class="input-field w-full min-h-[52px]" required><option value="">เลือกพนักงาน</option>
<?php $employees=$pdo->query('SELECT id, first_name_th, last_name_th FROM users WHERE is_active=1 AND is_employee=1 ORDER BY first_name_th')->fetchAll(PDO::FETCH_ASSOC); foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>" <?= $id===(int)$e['id']?'selected':'' ?>><?= $esc($e['first_name_th'].' '.$e['last_name_th']) ?></option><?php endforeach; ?></select><button class="btn-primary min-h-[48px] px-4">เปิดข้อมูลพนักงาน</button></form>
</section>
<?php if ($id && $row): ?>
<form method="post" class="native-card p-4 sm:p-6 space-y-4 org-save">
<?= csrfField() ?><input type="hidden" name="version" value="<?= $esc($version) ?>">
<p>ตำแหน่งที่แสดงในระบบปัจจุบัน: <strong><?= $esc($row['position_text'] ?? '') ?></strong></p>
<p class="text-white/70">การเลือกตำแหน่งใหม่จะเปลี่ยนชื่อตำแหน่งในข้อมูลพนักงาน แต่ไม่เปลี่ยนบทบาทหรือสิทธิ์ใช้งาน การยกเลิกการผูกจะยังเก็บข้อความตำแหน่งเดิมไว้</p>
<?php foreach (['position_id'=>['ตำแหน่งงาน',$positions,'title'],'workplace_id'=>['สถานที่ทำงานประจำ',$workplaces,'name']] as $field=>$config): ?>
<label class="block" for="<?= $field ?>"><?= $config[0] ?></label><select id="<?= $field ?>" name="<?= $field ?>" class="input-field w-full min-h-[52px]"><option value="0">ไม่ผูกกับรายการส่วนกลาง</option>
<?php foreach ($config[1] as $item): if (!$item['is_active'] && (int)($row[$field]??0)!==(int)$item['id']) continue; ?><option value="<?= (int)$item['id'] ?>" <?= (int)($row[$field]??0)===(int)$item['id']?'selected':'' ?>><?= $esc($item[$config[2]]) ?><?= !$item['is_active']?' (ปิดใช้งาน — ข้อมูลเดิม)':'' ?></option><?php endforeach; ?></select>
<?php endforeach; ?><label class="flex items-center gap-3 min-h-[48px]"><input type="checkbox" name="refresh_title" value="1"> ปรับข้อความตำแหน่งปัจจุบันให้ตรงกับรายการที่เลือก</label><p class="text-white/70">สถานที่ประจำใช้สำหรับข้อมูลบุคลากร จุดที่อนุญาตให้ลงเวลายังคงเป็นจุดเปิดใช้งานทั้งหมดตามนโยบายเดิม</p>
<div class="sticky bottom-4 p-3 rounded-xl native-card"><button class="btn-primary min-h-[48px] px-6" type="submit">บันทึกการกำหนด</button><span class="org-progress ml-3" role="status"></span></div></form>
<?php endif; ?>
<?php else: ?>
<section class="native-card p-4 sm:p-6 space-y-4">
<div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl"><?= $tabs[$tab] ?></h2><a class="btn-primary inline-flex items-center min-h-[48px] px-4" href="?tab=<?= $tab ?>#editor">เพิ่มรายการ</a></div>
<?php $items=$service->rows($tab); if (!$items): ?><p class="text-white/70">ยังไม่มีรายการ เริ่มเพิ่มได้จากแบบฟอร์มด้านล่าง</p><?php endif; ?>
<ul class="space-y-2"><?php foreach ($items as $item): ?><li class="border border-white/10 rounded-xl p-3 flex flex-wrap justify-between items-center gap-2"><div class="min-w-0 break-words"><strong><?= $esc($item['title']??$item['name']) ?></strong><div class="text-white/70"><?= $esc($item['code']) ?> · <?= $item['is_active']?'เปิดใช้งาน':'ปิดใช้งาน / เก็บประวัติ' ?></div><?php if ($tab==='locations'): ?><div class="text-white/70">รัศมี <?= $esc($item['radius_meters']) ?> เมตร</div><?php endif; ?></div><a class="inline-flex min-h-[48px] items-center px-4 bg-white/10 rounded-xl" href="?tab=<?= $tab ?>&amp;id=<?= (int)$item['id'] ?>#editor">ดู / แก้ไข</a></li><?php endforeach; ?></ul>
</section>
<form id="editor" method="post" class="native-card p-4 sm:p-6 space-y-4 org-save">
<h2 class="text-xl"><?= $id?'แก้ไขรายการ':'เพิ่มรายการ' ?>: <?= $tabs[$tab] ?></h2>
<?= csrfField() ?><input type="hidden" name="version" value="<?= $esc($version) ?>">
<?php $fields=['code'=>['รหัส','text',20],($tab==='positions'?'title':'name')=>['ชื่อ'.$tabs[$tab],'text',100]];
if ($tab!=='positions') $fields['address']=['ที่อยู่','textarea',5000];
if ($tab==='locations') $fields += ['latitude'=>['ละติจูด (-90 ถึง 90)','number',null],'longitude'=>['ลองจิจูด (-180 ถึง 180)','number',null],'radius_meters'=>['รัศมีที่อนุญาต (เมตร)','number',null]];
$fields[$tab==='positions'?'job_description':'description']=['รายละเอียด','textarea',5000];
foreach ($fields as $field=>$config): ?>
<div><label for="<?= $field ?>" class="block mb-2"><?= $config[0] ?></label>
<?php if ($config[1]==='textarea'): ?><textarea id="<?= $field ?>" name="<?= $field ?>" class="input-field w-full" maxlength="5000" rows="4"><?= $esc($row[$field]??'') ?></textarea>
<?php else: ?><input id="<?= $field ?>" name="<?= $field ?>" type="<?= $config[1] ?>" class="input-field w-full min-h-[52px]" value="<?= $esc($row[$field]??($field==='radius_meters'?100:'')) ?>" required <?= $config[2]?'maxlength="'.$config[2].'"':'' ?> <?= $field==='code'?'pattern="[A-Za-z0-9_-]+"':'' ?> <?= $field==='latitude'?'min="-90" max="90" step="any"':($field==='longitude'?'min="-180" max="180" step="any"':($field==='radius_meters'?'min="1" max="10000" step="1"':'')) ?>><?php endif; ?></div>
<?php endforeach; ?>
<?php if ($tab==='locations'): ?><label class="block" for="workplace_id">อยู่ในสถานที่ทำงาน</label><select id="workplace_id" name="workplace_id" class="input-field w-full min-h-[52px]"><option value="0">ยังไม่ผูกสถานที่ทำงาน</option><?php foreach ($workplaces as $w): if (!$w['is_active'] && (int)($row['workplace_id']??0)!==(int)$w['id']) continue; ?><option value="<?= (int)$w['id'] ?>" <?= (int)($row['workplace_id']??0)===(int)$w['id']?'selected':'' ?>><?= $esc($w['name']) ?><?= !$w['is_active']?' (ปิดใช้งาน)':'' ?></option><?php endforeach; ?></select><p class="text-white/70">พิกัดและรัศมีมีผลต่อการลงเวลาครั้งถัดไป เมื่อแก้ข้อมูลจุด ระบบจะเก็บรุ่นเดิมสำหรับประวัติลงเวลา ห้ามปิดจุดสุดท้ายก่อนเพิ่มจุดทดแทน</p><?php endif; ?>
<label class="flex items-center gap-3 min-h-[48px]"><input type="checkbox" name="is_active" value="1" <?= ($row['is_active']??1)?'checked':'' ?>> เปิดใช้งาน (เอาเครื่องหมายออกเพื่อปิดใช้งาน)</label>
<?php if ($tab==='positions'): ?><p class="text-white/70">เปลี่ยนชื่อรายการนี้จะไม่เปลี่ยนตำแหน่งเดิมของพนักงานโดยอัตโนมัติ และไม่มีผลต่อบทบาทหรือเงินเดือน</p><?php endif; ?>
<div class="sticky bottom-4 p-3 rounded-xl native-card flex flex-wrap gap-3 items-center"><button class="btn-primary min-h-[48px] px-6" type="submit">บันทึกข้อมูล</button><a class="inline-flex items-center min-h-[48px] px-4" href="?tab=<?= $tab ?>">ยกเลิก</a><span class="org-progress" role="status"></span></div>
</form>
<?php endif; ?>
<?php if ($id): $history=$service->history($tab,$id);
$historyLabels=['code'=>'รหัส','title'=>'ตำแหน่ง','name'=>'ชื่อสถานที่','address'=>'ที่อยู่','description'=>'รายละเอียด','job_description'=>'รายละเอียดงาน','latitude'=>'ละติจูด','longitude'=>'ลองจิจูด','radius_meters'=>'รัศมี (เมตร)','is_active'=>'สถานะ','position_text'=>'ตำแหน่งที่แสดง','position_catalog_title'=>'ตำแหน่งส่วนกลาง','workplace_name'=>'สถานที่ประจำ','replacement_id'=>'จุดลงเวลารุ่นใหม่'];
$historyValue=static function($field,$value) use ($positions,$workplaces) {
    if ($value===null || $value==='') return '—';
    if ($field==='is_active') return (int)$value?'เปิดใช้งาน':'ปิดใช้งาน';
    if ($field==='position_id' || $field==='workplace_id') {
        foreach ($field==='position_id'?$positions:$workplaces as $entry) if ((int)$entry['id']===(int)$value) return $entry['title']??$entry['name'];
    }
    return (string)$value;
}; ?>
<section class="native-card p-4 sm:p-6 space-y-3"><h2 class="text-xl">ประวัติการเปลี่ยนแปลง</h2>
<?php if (!$history): ?><p class="text-white/70">ยังไม่มีประวัติจากเมนูนี้ ข้อมูลเดิมยังถูกเก็บไว้</p><?php endif; ?>
<?php foreach ($history as $h): $before=json_decode($h['old_values']??'null',true)??[]; $after=json_decode($h['new_values'],true)??[]; ?>
<details class="border border-white/10 rounded-xl p-3"><summary class="min-h-[48px] cursor-pointer"><?= $esc($h['created_at']) ?> · ผู้แก้ไข #<?= (int)$h['actor_id'] ?></summary>
<dl class="space-y-3"><?php foreach ($historyLabels as $field=>$label): if (!array_key_exists($field,$after) || (array_key_exists($field,$before) && (string)$before[$field]===(string)$after[$field])) continue; ?>
<div class="break-words"><dt class="font-semibold"><?= $label ?></dt><dd>เดิม: <?= $esc($historyValue($field,$before[$field]??null)) ?></dd><dd>ใหม่: <?= $esc($historyValue($field,$after[$field])) ?></dd></div>
<?php endforeach; ?></dl></details>
<?php endforeach; ?></section><?php endif; ?>
<?php endif; ?>
</div>
<style>.org-save input,.org-save select,.org-save textarea { scroll-margin-block: 110px; } .org-save button:disabled { opacity: .6; cursor: wait; }</style>
<script>
document.getElementById('organization-error')?.focus();
document.querySelectorAll('.org-save').forEach(form => form.addEventListener('submit', () => {
    form.querySelector('button[type="submit"]').disabled = true;
    form.querySelector('.org-progress').textContent = 'กำลังบันทึก…';
}));
</script>
<?php require dirname(__DIR__) . '/templates/footer.php'; ?>

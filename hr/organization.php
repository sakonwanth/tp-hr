<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/Services/OrganizationService.php';
Auth::requireLogin();
Auth::requireHR();
$pdo = getDB();
$service = new OrganizationService($pdo);
if (!hr_can_access_hr_dashboard() || !$service->canManage((int)Auth::id())) { http_response_code(403); exit('คุณไม่มีสิทธิ์จัดการโครงสร้างองค์กร'); }
$page_title = 'สถานที่ทำงานและพิกัดลงเวลา';
$current_page = 'hr-organization';
$tabs = ['workplaces'=>'สถานที่ทำงาน','locations'=>'พิกัดลงเวลา'];
$tab = (string)($_GET['tab'] ?? 'workplaces');
if (!isset($tabs[$tab])) { redirect('/hr/organization.php?tab=workplaces',303); exit; }
$id = max(0,(int)($_GET['id'] ?? 0));
$error = '';
$ready = $service->ready();
$row = null;
if ($ready && $id) {
    try { $row = $service->get($tab,$id); }
    catch (DomainException $e) { $error=$e->getMessage(); }
    if (!$row) { http_response_code(404); $error='ไม่พบรายการที่เลือก'; $id=0; }
}
$version = OrganizationService::token($row);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    try {
        if (!verifyCsrf()) throw new DomainException('เซสชันหมดอายุ กรุณาโหลดหน้าใหม่');
        $id = $service->save((int)Auth::id(),$tab,$id,(string)($_POST['version'] ?? ''),$_POST);
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
<header><a href="/hr/" class="inline-flex items-center min-h-[48px] text-violet-300">กลับแดชบอร์ด HR</a><h1 class="tp-ios-page-title">สถานที่ทำงานและพิกัดลงเวลา</h1><p class="text-white/70 mt-2">กำหนดสถานที่ พิกัด GPS และรัศมีที่อนุญาตให้ลงเวลา ปิดใช้งานแทนการลบเพื่อเก็บประวัติเดิม</p></header>
<nav aria-label="หมวดสถานที่และพิกัด" class="flex flex-wrap gap-2">
<?php foreach ($tabs as $key=>$label): ?><a href="?tab=<?= $key ?>" class="inline-flex items-center min-h-[48px] px-4 rounded-xl <?= $tab===$key ? 'bg-violet-600 text-white' : 'bg-white/10 text-white' ?>" <?= $tab===$key ? 'aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?>
</nav>
<?php if ($error): ?><div id="organization-error" tabindex="-1" role="alert" class="p-4 rounded-xl bg-red-500/15 text-red-200"><?= $esc($error) ?></div><?php endif; ?>
<?php if (isset($_GET['saved']) && !$error): ?><div role="status" class="p-4 rounded-xl bg-emerald-500/15 text-emerald-200">บันทึกข้อมูลและประวัติการเปลี่ยนแปลงแล้ว</div><?php endif; ?>
<?php if (!$ready): ?><div role="alert" class="native-card p-6">ยังไม่พร้อมใช้งาน กรุณาให้ผู้ดูแลระบบติดตั้งการปรับปรุงฐานข้อมูลโครงสร้างองค์กรก่อน</div>
<?php else:
$workplaces=$service->rows('workplaces');
?>
<section class="native-card p-4 sm:p-6 space-y-4">
<div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl"><?= $tabs[$tab] ?></h2><a class="btn-primary inline-flex items-center min-h-[48px] px-4" href="?tab=<?= $tab ?>#editor">เพิ่มรายการ</a></div>
<?php $items=$service->rows($tab); if (!$items): ?><p class="text-white/70">ยังไม่มีรายการ เริ่มเพิ่มได้จากแบบฟอร์มด้านล่าง</p><?php endif; ?>
<ul class="space-y-2"><?php foreach ($items as $item): ?><li class="border border-white/10 rounded-xl p-3 flex flex-wrap justify-between items-center gap-2"><div class="min-w-0 break-words"><strong><?= $esc($item['title']??$item['name']) ?></strong><div class="text-white/70"><?= $esc($item['code']) ?> · <?= $item['is_active']?'เปิดใช้งาน':'ปิดใช้งาน / เก็บประวัติ' ?></div><?php if ($tab==='locations'): ?><div class="text-white/70">รัศมี <?= $esc($item['radius_meters']) ?> เมตร</div><?php endif; ?></div><a class="inline-flex min-h-[48px] items-center px-4 bg-white/10 rounded-xl" href="?tab=<?= $tab ?>&amp;id=<?= (int)$item['id'] ?>#editor">ดู / แก้ไข</a></li><?php endforeach; ?></ul>
</section>
<form id="editor" method="post" class="native-card p-4 sm:p-6 space-y-4 org-save">
<h2 class="text-xl"><?= $id?'แก้ไขรายการ':'เพิ่มรายการ' ?>: <?= $tabs[$tab] ?></h2>
<?= csrfField() ?><input type="hidden" name="version" value="<?= $esc($version) ?>">
<div><span class="block mb-2">รหัส (อัตโนมัติ)</span><p><?= $esc($row['code'] ?? 'ระบบจะสร้างรหัสเมื่อบันทึก') ?></p></div>
<?php if ($tab==='locations'): ?>
<section id="location-picker" class="space-y-3" aria-label="เลือกพิกัดลงเวลา">
<div><label for="latitude" class="block mb-2">ละติจูด (Latitude)</label><input type="number" id="latitude" name="latitude" min="-90" max="90" step="any" required inputmode="decimal" class="input-field w-full min-h-[52px]" value="<?= $esc($row['latitude'] ?? '') ?>"></div>
<div><label for="longitude" class="block mb-2">ลองจิจูด (Longitude)</label><input type="number" id="longitude" name="longitude" min="-180" max="180" step="any" required inputmode="decimal" class="input-field w-full min-h-[52px]" value="<?= $esc($row['longitude'] ?? '') ?>"></div>
<p>กรอกละติจูด–ลองจิจูดโดยตรง หรือแตะบนแผนที่และลากหมุดเพื่อเลือกพิกัด วงสีม่วงคือพื้นที่ที่อนุญาตให้ลงเวลา</p>
<div class="flex flex-wrap gap-3"><button type="button" id="use-current-location" class="btn-primary min-h-[48px] px-4">ใช้ตำแหน่งปัจจุบัน</button><button type="button" id="use-map-center" class="min-h-[48px] px-4 rounded-xl bg-white/10">เลือกจุดกึ่งกลางแผนที่</button></div>
<div id="location-map" role="region" aria-label="แผนที่เลือกพิกัด ใช้ลูกศรเลื่อนแผนที่แล้วกดเลือกจุดกึ่งกลาง" style="height:360px;max-height:60vh;min-height:240px;position:relative;z-index:0;border-radius:12px"></div>
<p id="location-summary" class="break-words"></p><p id="location-status" role="status" aria-live="polite" class="text-white/70"></p>
<noscript><p role="alert">กรุณาเปิด JavaScript เพื่อเลือกพิกัดและดูวงรัศมีลงเวลา</p></noscript>
</section>
<?php endif; ?>
<?php $fields=['name'=>['ชื่อ'.$tabs[$tab],'text',100]];
$fields['address']=['ที่อยู่','textarea',5000];
if ($tab==='locations') $fields += ['radius_meters'=>['รัศมีที่อนุญาต (เมตร)','number',null]];
$fields['description']=['รายละเอียด','textarea',5000];
foreach ($fields as $field=>$config): ?>
<div><label for="<?= $field ?>" class="block mb-2"><?= $config[0] ?></label>
<?php if ($config[1]==='textarea'): ?><textarea id="<?= $field ?>" name="<?= $field ?>" class="input-field w-full" maxlength="5000" rows="4"><?= $esc($row[$field]??'') ?></textarea>
<?php else: ?><input id="<?= $field ?>" name="<?= $field ?>" type="<?= $config[1] ?>" class="input-field w-full min-h-[52px]" value="<?= $esc($row[$field]??($field==='radius_meters'?100:'')) ?>" required <?= $config[2]?'maxlength="'.$config[2].'"':'' ?> <?= $field==='code'?'pattern="[A-Za-z0-9_-]+"':'' ?> <?= $field==='latitude'?'min="-90" max="90" step="any"':($field==='longitude'?'min="-180" max="180" step="any"':($field==='radius_meters'?'min="1" max="10000" step="1"':'')) ?>><?php endif; ?></div>
<?php endforeach; ?>
<?php if ($tab==='locations'): ?><label class="block" for="workplace_id">อยู่ในสถานที่ทำงาน</label><select id="workplace_id" name="workplace_id" class="input-field w-full min-h-[52px]"><option value="0">ยังไม่ผูกสถานที่ทำงาน</option><?php foreach ($workplaces as $w): if (!$w['is_active'] && (int)($row['workplace_id']??0)!==(int)$w['id']) continue; ?><option value="<?= (int)$w['id'] ?>" <?= (int)($row['workplace_id']??0)===(int)$w['id']?'selected':'' ?>><?= $esc($w['name']) ?><?= !$w['is_active']?' (ปิดใช้งาน)':'' ?></option><?php endforeach; ?></select><p class="text-white/70">พิกัดและรัศมีมีผลต่อการลงเวลาครั้งถัดไป เมื่อแก้ข้อมูลจุด ระบบจะเก็บรุ่นเดิมสำหรับประวัติลงเวลา ห้ามปิดจุดสุดท้ายก่อนเพิ่มจุดทดแทน</p><?php endif; ?>
<label class="flex items-center gap-3 min-h-[48px]"><input type="checkbox" name="is_active" value="1" <?= ($row['is_active']??1)?'checked':'' ?>> เปิดใช้งาน (เอาเครื่องหมายออกเพื่อปิดใช้งาน)</label>

<div class="sticky bottom-4 p-3 rounded-xl native-card flex flex-wrap gap-3 items-center"><button class="btn-primary min-h-[48px] px-6" type="submit">บันทึกข้อมูล</button><a class="inline-flex items-center min-h-[48px] px-4" href="?tab=<?= $tab ?>">ยกเลิก</a><span class="org-progress" role="status"></span></div>
</form>
<?php if ($id): $history=$service->history($tab,$id);
$historyLabels=['code'=>'รหัส','name'=>'ชื่อสถานที่','address'=>'ที่อยู่','description'=>'รายละเอียด','latitude'=>'ละติจูด','longitude'=>'ลองจิจูด','radius_meters'=>'รัศมี (เมตร)','is_active'=>'สถานะ','workplace_name'=>'สถานที่ประจำ','replacement_id'=>'จุดลงเวลารุ่นใหม่'];
$historyValue=static function($field,$value) use ($workplaces) {
    if ($value===null || $value==='') return '—';
    if ($field==='is_active') return (int)$value?'เปิดใช้งาน':'ปิดใช้งาน';
    if ($field==='workplace_id') {
        foreach ($workplaces as $entry) if ((int)$entry['id']===(int)$value) return $entry['title']??$entry['name'];
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
<?php if ($ready && $tab==='locations'): ?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/js/organization-map.js?v=20261106-1"></script>
<?php endif; ?>
<script>
document.getElementById('organization-error')?.focus();
document.querySelectorAll('.org-save').forEach(form => form.addEventListener('submit', () => {
    form.querySelector('button[type="submit"]').disabled = true;
    form.querySelector('.org-progress').textContent = 'กำลังบันทึก…';
}));
</script>
<?php require dirname(__DIR__) . '/templates/footer.php'; ?>

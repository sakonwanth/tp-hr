<?php
if (!defined('BASE_PATH')) {http_response_code(403);exit;}
/**
 * Employee Profile — view
 * Variables available: $target_user, $is_self, $is_hr_admin, $roles, $employees (nullable)
 */
$u = $target_user;
$readonly_self = $is_self && !$is_hr_admin;
$ro = $readonly_self ? 'readonly' : '';
$ro_select = $readonly_self ? 'disabled' : '';

function ep_val($u, $k) { return htmlspecialchars((string)($u[$k] ?? '')); }
?>
        <!-- Page Header (Design System: wrap + gap) -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6 sm:mb-8">
            <div class="min-w-0">
                <nav class="text-sm text-white/60 mb-2 min-w-0" aria-label="Breadcrumb">
                    <a href="index.php" class="hover:text-white touch-manipulation">หน้าแรก</a>
                    <span class="mx-2" aria-hidden="true">/</span>
                    <span class="text-white">โปรไฟล์พนักงาน</span>
                    <span class="mx-2" aria-hidden="true">/</span>
                    <span class="text-white/80 truncate inline-block max-w-[min(100%,11rem)] sm:max-w-md align-bottom"><?php echo ep_val($u, 'first_name_th') . ' ' . ep_val($u, 'last_name_th'); ?></span>
                </nav>
                <h1 class="text-xl sm:text-2xl font-bold truncate">
                    <i class="fas fa-id-card-alt text-purple-400 mr-2"></i>โปรไฟล์พนักงาน
                </h1>
                <p class="text-white/60 text-sm mt-1">
                    <?php echo ep_val($u, 'employee_code'); ?> ·
                    <?php echo htmlspecialchars($u['role_display_name'] ?? '-'); ?>
                    <?php if ($is_self): ?>
                        <span class="ml-2 px-2 py-0.5 bg-purple-500/20 text-purple-300 rounded text-xs">ข้อมูลของฉัน</span>
                    <?php endif; ?>
                    <?php if ($is_hr_admin): ?>
                        <span class="ml-2 px-2 py-0.5 bg-cyan-500/20 text-cyan-300 rounded text-xs">โหมดแก้ไขเต็ม (HR)</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <?php if ($is_hr_admin && $employees): ?>
                <select onchange="if(this.value) location.href='employee_profile.php?id='+this.value"
                        class="form-input whitespace-nowrap px-4 py-2.5 rounded-xl text-sm">
                    <option value="">-- เลือกพนักงาน --</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?php echo (int)$e['id']; ?>" <?php echo (int)$e['id'] === (int)$u['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($e['employee_code'] . ' — ' . $e['full_name']); ?>
                        <?php if (!$e['is_active']): ?> (ปิดใช้งาน)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <a href="index.php" class="touch-manipulation btn-app whitespace-nowrap bg-white/10 hover:bg-white/20 px-5 py-2.5 rounded-xl font-medium transition-all flex items-center min-h-[48px]">
                    <i class="fas fa-arrow-left mr-2"></i>กลับ
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)): ?>
        <div class="mb-6 p-4 bg-emerald-500/20 border border-emerald-500/30 rounded-xl flex items-center">
            <i class="fas fa-check-circle text-emerald-400 mr-3"></i>
            <span class="text-emerald-300"><?php echo htmlspecialchars($message); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
        <div class="mb-6 p-4 bg-rose-500/20 border border-rose-500/30 rounded-xl flex items-center">
            <i class="fas fa-exclamation-circle text-rose-400 mr-3"></i>
            <span class="text-rose-300"><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_profile">
            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">

            <!-- ข้อมูลการจ้างงาน (HR only) -->
            <?php if ($is_hr_admin): ?>
            <section class="glass-card rounded-2xl p-6 sm:p-8">
                <h2 class="text-lg font-bold mb-5 flex items-center gap-2">
                    <i class="fas fa-briefcase text-cyan-400"></i>ข้อมูลการจ้างงาน
                    <span class="text-xs font-normal text-white/40 ml-1">(HR เท่านั้น)</span>
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">รหัสพนักงาน</label>
                        <input type="text" name="employee_code" value="<?php echo ep_val($u,'employee_code'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">บทบาท (Role)</label>
                        <select name="role_id" class="form-input w-full px-4 py-3 rounded-xl">
                            <?php foreach ($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>" <?php echo (int)$u['role_id'] === (int)$r['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($r['display_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-center pt-8">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" name="is_active" value="1" <?php echo (int)$u['is_active'] === 1 ? 'checked' : ''; ?> class="w-5 h-5 rounded bg-white/10 border-white/20 text-purple-600 focus:ring-purple-500">
                            <span class="ml-3">เปิดใช้งาน</span>
                        </label>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">แผนก</label>
                        <input type="text" name="department" value="<?php echo ep_val($u,'department'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">ตำแหน่ง</label>
                        <input type="text" name="position" value="<?php echo ep_val($u,'position'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">วันเริ่มงาน</label>
                        <input type="date" name="hire_date" value="<?php echo ep_val($u,'hire_date'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">ประเภทการจ้าง</label>
                        <select name="employment_type" class="form-input w-full px-4 py-3 rounded-xl">
                            <?php $etypes = ['' => '-- ไม่ระบุ --', 'PROBATION' => 'ทดลองงาน', 'PERMANENT' => 'ประจำ', 'CONTRACT' => 'สัญญาจ้าง', 'PARTTIME' => 'พาร์ทไทม์'];
                            foreach ($etypes as $v => $l): ?>
                            <option value="<?php echo $v; ?>" <?php echo ($u['employment_type'] ?? '') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">รูปแบบการทำงาน</label>
                        <select name="work_mode" class="form-input w-full px-4 py-3 rounded-xl">
                            <?php $wms = ['OFFICE' => 'สำนักงาน (Office)', 'WFH' => 'ทำงานที่บ้าน (WFH)'];
                            foreach ($wms as $v => $l): ?>
                            <option value="<?php echo $v; ?>" <?php echo ($u['work_mode'] ?? 'OFFICE') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">จำนวนวันทดลองงาน</label>
                        <input type="number" name="probation_days" value="<?php echo ep_val($u,'probation_days'); ?>" min="0" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">วันครบทดลองงาน</label>
                        <input type="date" name="probation_end_date" value="<?php echo ep_val($u,'probation_end_date'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">วันผ่านทดลองงาน</label>
                        <input type="date" name="probation_passed_date" value="<?php echo ep_val($u,'probation_passed_date'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                </div>
            </section>

            <!-- เงินเดือน + ประกันสังคม + ธนาคาร (HR only) -->
            <section class="glass-card rounded-2xl p-6 sm:p-8">
                <h2 class="text-lg font-bold mb-5 flex items-center gap-2">
                    <i class="fas fa-money-check-alt text-emerald-400"></i>ค่าจ้าง / ประกันสังคม / ธนาคาร
                    <span class="text-xs font-normal text-white/40 ml-1">(HR เท่านั้น)</span>
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">เงินเดือน (ประจำ)</label>
                        <input type="number" step="0.01" name="salary" value="<?php echo ep_val($u,'salary'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">เงินเดือน (ทดลองงาน)</label>
                        <input type="number" step="0.01" name="probation_salary" value="<?php echo ep_val($u,'probation_salary'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                    <div class="flex items-center pt-8">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" name="has_other_employer_income" value="1" <?php echo (int)($u['has_other_employer_income'] ?? 0) === 1 ? 'checked' : ''; ?> class="w-5 h-5 rounded bg-white/10 border-white/20 text-purple-600 focus:ring-purple-500">
                            <span class="ml-3">มีรายได้จากนายจ้างอื่น</span>
                        </label>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">เลขประกันสังคม</label>
                        <input type="text" name="social_security_id" value="<?php echo ep_val($u,'social_security_id'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">วันเริ่มประกันสังคม</label>
                        <input type="date" name="social_security_start_date" value="<?php echo ep_val($u,'social_security_start_date'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">โรงพยาบาล (ประกันสังคม)</label>
                        <input type="text" name="social_security_hospital" value="<?php echo ep_val($u,'social_security_hospital'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">ธนาคาร</label>
                        <input type="text" name="bank_name" value="<?php echo ep_val($u,'bank_name'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-white/60 mb-2">เลขบัญชีธนาคาร</label>
                        <input type="text" name="bank_account" value="<?php echo ep_val($u,'bank_account'); ?>" class="form-input w-full px-4 py-3 rounded-xl">
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- ข้อมูลส่วนตัว -->
            <section class="glass-card rounded-2xl p-6 sm:p-8">
                <h2 class="text-lg font-bold mb-5 flex items-center gap-2">
                    <i class="fas fa-user text-purple-400"></i>ข้อมูลส่วนตัว
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">คำนำหน้า</label>
                        <select name="title" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro_select; ?>>
                            <?php $titles = ['นาย','นาง','นางสาว','Mr.','Mrs.','Ms.','ดร.'];
                            foreach ($titles as $t): ?>
                            <option value="<?php echo $t; ?>" <?php echo ($u['title'] ?? '') === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">ชื่อ (TH) <span class="text-rose-400">*</span></label>
                        <input type="text" name="first_name_th" required value="<?php echo ep_val($u,'first_name_th'); ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">นามสกุล (TH)</label>
                        <input type="text" name="last_name_th" value="<?php echo ep_val($u,'last_name_th'); ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">ชื่อ (EN)</label>
                        <input type="text" name="first_name_en" value="<?php echo ep_val($u,'first_name_en'); ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">นามสกุล (EN)</label>
                        <input type="text" name="last_name_en" value="<?php echo ep_val($u,'last_name_en'); ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">ชื่อเล่น</label>
                        <input type="text" name="nickname" value="<?php echo ep_val($u,'nickname'); ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">วันเกิด</label>
                        <input type="date" name="birth_date" value="<?php echo ep_val($u,'birth_date'); ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">เพศ</label>
                        <select name="gender" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro_select; ?>>
                            <option value="">-- ไม่ระบุ --</option>
                            <option value="M" <?php echo ($u['gender'] ?? '') === 'M' ? 'selected' : ''; ?>>ชาย</option>
                            <option value="F" <?php echo ($u['gender'] ?? '') === 'F' ? 'selected' : ''; ?>>หญิง</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">สถานะสมรส</label>
                        <select name="marital_status" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro_select; ?>>
                            <?php $ms = ['' => '-- ไม่ระบุ --', 'SINGLE' => 'โสด', 'MARRIED' => 'สมรส', 'WIDOWED' => 'หม้าย', 'DIVORCED' => 'หย่า', 'SEPARATED' => 'แยกกันอยู่'];
                            foreach ($ms as $v => $l): ?>
                            <option value="<?php echo $v; ?>" <?php echo ($u['marital_status'] ?? '') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">กรุ๊ปเลือด</label>
                        <input type="text" name="blood_type" value="<?php echo ep_val($u,'blood_type'); ?>" maxlength="5" placeholder="A / B / AB / O" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">ศาสนา</label>
                        <input type="text" name="religion" value="<?php echo ep_val($u,'religion'); ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">สถานะทหาร (ชาย)</label>
                        <select name="military_status" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro_select; ?>>
                            <?php $mils = ['' => '-- ไม่ระบุ --', 'NONE' => 'ยังไม่เกณฑ์', 'EXEMPTED' => 'ได้รับยกเว้น', 'COMPLETED' => 'ผ่านการเกณฑ์แล้ว', 'NOT_APPLICABLE' => 'ไม่เกี่ยวข้อง'];
                            foreach ($mils as $v => $l): ?>
                            <option value="<?php echo $v; ?>" <?php echo ($u['military_status'] ?? '') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">สัญชาติ</label>
                        <input type="text" name="nationality" value="<?php echo ep_val($u,'nationality') ?: 'ไทย'; ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">เลขบัตรประชาชน</label>
                        <input type="text" name="id_card" value="<?php echo ep_val($u,'id_card'); ?>" maxlength="13" pattern="\d{13}" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">วันหมดอายุบัตร ปชช.</label>
                        <input type="date" name="id_card_expiry" value="<?php echo ep_val($u,'id_card_expiry'); ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                </div>
            </section>

            <!-- ข้อมูลติดต่อ -->
            <section class="glass-card rounded-2xl p-6 sm:p-8">
                <h2 class="text-lg font-bold mb-5 flex items-center gap-2">
                    <i class="fas fa-address-book text-blue-400"></i>ข้อมูลติดต่อ
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">อีเมล</label>
                        <input type="email" name="email" value="<?php echo ep_val($u,'email'); ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">เบอร์โทรศัพท์</label>
                        <input type="text" name="phone" value="<?php echo ep_val($u,'phone'); ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-white/60 mb-2">ที่อยู่ปัจจุบัน</label>
                        <textarea name="address" rows="2" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>><?php echo ep_val($u,'address'); ?></textarea>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-white/60 mb-2">ที่อยู่ตามทะเบียนบ้าน</label>
                        <textarea name="registered_address" rows="2" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>><?php echo ep_val($u,'registered_address'); ?></textarea>
                    </div>
                </div>
            </section>

            <!-- ติดต่อกรณีฉุกเฉิน -->
            <section class="glass-card rounded-2xl p-6 sm:p-8">
                <h2 class="text-lg font-bold mb-5 flex items-center gap-2">
                    <i class="fas fa-phone-alt text-rose-400"></i>ติดต่อกรณีฉุกเฉิน
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">ชื่อผู้ติดต่อ</label>
                        <input type="text" name="emergency_contact_name" value="<?php echo ep_val($u,'emergency_contact_name'); ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">เบอร์โทร</label>
                        <input type="text" name="emergency_contact_phone" value="<?php echo ep_val($u,'emergency_contact_phone'); ?>" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white/60 mb-2">ความสัมพันธ์</label>
                        <input type="text" name="emergency_contact_relation" value="<?php echo ep_val($u,'emergency_contact_relation'); ?>" placeholder="พ่อ / แม่ / คู่สมรส ฯลฯ" class="form-input w-full px-4 py-3 rounded-xl" <?php echo $ro; ?>>
                    </div>
                </div>
            </section>

            <!-- Form Actions -->
            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-form bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 px-6 py-2.5 rounded-xl font-medium transition-all">
                    <i class="fas fa-save mr-2"></i>บันทึกข้อมูล
                </button>
                <?php if ($is_hr_admin): ?>
                <a href="hr/employees.php" class="btn-form bg-white/10 hover:bg-white/20 px-6 py-2.5 rounded-xl font-medium transition-all">
                    <i class="fas fa-user-cog mr-2"></i>ไปหน้าจัดการพนักงาน
                </a>
                <?php endif; ?>
            </div>
        </form>


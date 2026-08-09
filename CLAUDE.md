# CLAUDE.md — TP-HR

ระบบบริหารทรัพยากรบุคคล (HR / payroll / attendance) ของ TP-Asset Development หนึ่งใน 7 โปรเจกต์ของ TP ecosystem

## Stack
- PHP 8.1+ pure PHP (ไม่มี framework), Composer
- MySQL 8.0 ผ่าน PDO — ใช้ DB **`tp_crm`** ร่วมกับ tp-crm, tp-erp, tp-checkin, tp-asset
- Frontend: HTML + Tailwind/Bootstrap + vanilla JS
- Apache + `.htaccess` (mod_rewrite); ขึ้นกับ shared library `tpasset/tp-common` (Composer path symlink `../tp-common`)

## โครงสร้างหลัก
- `api/` — REST API รวม `api/v1` (เปิดให้ tp-crm/tp-checkin เรียกผ่าน API key + scopes)
- `modules/`, `hr/` — โดเมน HR (พนักงาน, การลา, payroll, เอกสาร)
- `core/`, `config/` — bootstrap, การตั้งค่า, DB connection
- `cron/`, `scripts/` — งาน batch; `tests/`, `playwright/` — เทสต์

## บทบาทใน ecosystem
- เป็น **owner ของตาราง `hr_*`** (เช่น `hr_attendances`)
- tp-crm อ่านข้อมูล HR ผ่าน `HrService` (dual mode: `HrClient` ไป `/api/v1` เมื่อมี `TP_HR_API_KEY` ไม่งั้น direct SQL)
- tp-checkin เขียน `hr_attendances` ตรงใน DB เดียวกัน
- ส่ง notification กลับ tp-crm ผ่าน `CrmLineNotifierBridge`
- SSO ภายในใช้ `TpCommon\Session\SharedSession` (cookie `tp_session`)

## เริ่มงาน
1. `composer install` (จะ symlink tp-common)
2. `cp .env.example .env` แล้วตั้งค่า DB/HTTP keys
3. เปิดผ่าน Apache → `http://localhost/tp-hr/`

## Convention สำคัญ
- ทุกไฟล์ต้อง `define()` access guard ก่อน include config
- งานที่แตะ UI/หน้าตา/ฟอร์ม: อ่าน `../tp-common/UI_RULES.md`, `UI_UX_STANDARD.md` ก่อน (มาตรฐาน TP ชนะ skill ภายนอก)
- หลังแก้โค้ด: commit + push `main` อัตโนมัติ → GitHub Actions deploy production
- เปลี่ยน API contract ต้อง sync กับ tp-crm/tp-checkin (ดู safe change order ใน `../tp-common/PROJECT_RELATION_MAP.md`)

## เอกสารที่ต้องอ่าน (ใน tp-common)
- `TP_ECOSYSTEM_AI_CONTEXT.md`, `PROJECT_RELATION_MAP.md`, `WORKSPACE_MASTER_MAP.md` — ภาพรวม + การเชื่อมต่อ
- `DATABASE_STANDARD.md`, `AUTH_AND_ROLE_STANDARD.md`, `API_STANDARD.md`, `STANDARDS_INDEX.md`

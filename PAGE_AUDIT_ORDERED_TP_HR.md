# TP-HR — รายการหน้าทั้งระบบ + ไล่ audit UX/UI ตามลำดับ

**อัปเดต:** จากสแกนไฟล์ PHP ที่เป็น **หน้า UI เว็บ** (มี HTML ให้ผู้ใช้)  
**ข้อจำกัด:** ไม่นับ API (`api/*`), tests, cron, scripts แบ็กเอนด์, `logout.php` (redirect เฉย ๆ), `webhook.php`

---

## สรุปจำนวน

| ประเภท | จำนวน |
|--------|-------:|
| หน้าหลัก — employee / ทั่วไป (ใช้ shell + แถบแท็บมือถือเมื่อเป็น employee route) | 10 ไฟล์ (ในตารางเป็น 11 แถว รวม `leave.php?action=request` ด้านล่าง) |
| HR admin (`hr-*`) | 13 |
| Standalone UI | 4 |
| **รวม unique หน้า (route/view)** | **27** |
| พาร์เชียลฝัง (ขอไว้เป็น sub-step) | 2 (`request_form.php`, `print_template.php`) |

จำนวน **ไฟล์หน้าเต็ม** (ไม่ซ้ำไฟล์): **≈26 entry point** + 1 มีสองโหมด (`leave` = list + request)

---

## ลำดับ audit (ที่ใช้ดำเนินการทีละขั้นตามแผน)

### ชุดที่ 1 — Employee / ทั่วไป (`tp-with-tab-nav`)

| ลำดับ | Route | ไฟล์ | หมายเหตุ shell / เมนูล่าง |
|:-----:|-------|------|---------------------------|
| 1 | `/` | `index.php` | แท็บ: หน้าแรก ✓ • `tp-native-page--home` + sticky ลงเวลามือถือ |
| 2 | `/checkin.php` | `checkin.php` | แท็บ: ลงเวลา ✓ • หลาย modal — ควรเลื่อนพ้นแท็บ |
| 3 | `/leave.php` | `leave.php` | แท็บ: ลา ✓ |
| 4 | `/leave.php?action=request` | `leave.php` + `modules/employee/leaves/request_form.php` | โหมดฟอร์ม — ฟอร์มใน partial • ปุ่มยืนยัน `btn-primary` |
| 5 | `/payslip.php` | `payslip.php` | แท็บ: สลิป ✓ |
| 6 | `/certificate.php` | `certificate.php` | แท็บ 5 เมนู: **ไม่มีแท็บตรง** (ไฟล์ `certificate`) — พฤติกรรม: ไม่มี tab active (ยอมรับได้) |
| 7 | `/dayoff_schedule.php` | `dayoff_schedule.php` | เหมือนข้อ 6 — `current_page=dayoff` ไม่อยู่ใน 5 แท็บ |
| 8 | `/profile.php` | `profile.php` | แท็บ: ฉัน ✓ • แท็บย่อย profile เป็นต้นไปภายใน |
| 9 | `/attendance_history.php` | `attendance_history.php` | `$current_page=checkin` → ไฮไลต์แท็บ **ลงเวลา** (สอดคล้องฟีเจอร์) |
| 10 | `/leave_history.php` | `leave_history.php` | `$current_page=leave` → แท็บ **ลา** |

### ชุดที่ 2 — HR (`tp-with-tab-nav` ปิด — ไม่มีแถบแท็บล่าง)

| ลำดับ | Route | ไฟล์ | `$current_page` |
|:-----:|-------|------|-----------------|
| 11 | `/hr/index.php` | `hr/index.php` | `hr-dashboard` |
| 12 | `/hr/employees.php` | `hr/employees.php` | `hr-employees` |
| 13 | `/hr/employee_view.php` | `hr/employee_view.php` | `hr-employees` |
| 14 | `/hr/employee_form.php` | `hr/employee_form.php` | `hr-employees` |
| 15 | `/hr/employee_attendance.php` | `hr/employee_attendance.php` | `hr-attendance` |
| 16 | `/hr/leaves.php` | `hr/leaves.php` | `hr-leaves` |
| 17 | `/hr/attendance.php` | `hr/attendance.php` | `hr-attendance` |
| 18 | `/hr/documents.php` | `hr/documents.php` | `hr-documents` |
| 19 | `/hr/document_templates.php` | `hr/document_templates.php` | `hr-document-templates` |
| 20 | `/hr/api_keys.php` | `hr/api_keys.php` | `hr-api-keys` (ไม่มีรายการ sidebar ใน `header.php` — เข้าได้จากลิงก์ภายในหรือ bookmark) |
| 21 | `/hr/reports.php` | `hr/reports.php` | `hr-reports` |
| 22 | `/hr/settings.php` | `hr/settings.php` | `hr-settings` |
| 23 | `/hr/dayoff_approvals.php` | `hr/dayoff_approvals.php` | `hr-dayoff` |

### ชุดที่ 3 — Standalone

| ลำดับ | Route | ไฟล์ |
|:-----:|-------|------|
| 24 | `/login.php` | `login.php` |
| 25 | `/verify_document.php` | `verify_document.php` (สาธารณะ) |
| 26 | POST `/certificate_print.php` | `certificate_print.php` |
| 27 | พิมพ์สลิป (embed) | `modules/employee/payslip/print_template.php` |

---

## ผลการ audit (สั้นๆ + ความขัดแย้งที่รู้)

| ID | หัวข้อ | ผล |
|----|--------|-----|
| Shell / padding | หลังแก้ `UI_CASCADE_BUGFIX.md` | `main` ได้ขอบ + buffer จาก `native-shell.css?v=4` — **PASS** |
| แถบเมนูล่างมือถือ | มี 5 แท็บเท่านั้น | หน้า **certificate**, **dayoff_schedule** — ไม่มีแท็บตรงกับ `$current_page` ⇒ ไม่มีรายการ active (ยอมรับได้ / อยากให้เห็น “อยู่ส่วนพนักงานทั่วไป” อาจออกแบบเพิ่มภายหลัง) — **NOTICE** |
| ปุ่มสัมผัส | โครงการกำหนด 48–56px แล้ว | **PASS** ตามเกณฑ์ที่ rollout ไป |
| Sidebar HR | เมนูครบจาก `templates/header.php` | **PASS** |

---

## Actions จาก audit รอบนี้ (โค้ด)

1. เพิ่ม **`aria-current="page"`** และ **`aria-label`** ใน `<nav>` แถบแท็บมือถือ (`templates/footer.php`) เพื่อ screen reader และมาตรฐานความเข้าถึงขั้นพื้นฐาน  

(รายการอื่นที่เป็น NOTICE ไม่บังคับแก้ในคลิปนี้)

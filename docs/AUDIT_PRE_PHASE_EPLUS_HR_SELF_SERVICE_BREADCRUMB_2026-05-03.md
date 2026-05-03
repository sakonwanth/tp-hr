# Pre-phase audit — Phase E+ self-service breadcrumb parity — 2026-05-03

## 1. ขอบเขต

| รายการ | ค่า |
|--------|-----|
| **เป้าหมาย** | ให้เส้นทางพนักงานที่ traffic สูงมี **`<nav aria-label="Breadcrumb">`** แบบเดียวกับ `leave_history.php`, `attendance_history.php`, `dayoff_schedule.php` — ลด disorientation และสอดคล้อง [UI_UX_STANDARD.md](https://github.com/sakonwanth/tp-common/blob/main/UI_UX_STANDARD.md) Tier A |
| **หน้าที่ตรวจ** | `checkin.php`, `leave.php`, `leave_history.php`, `attendance_history.php`, `dayoff_schedule.php`, `profile.php`, `modules/employee/leaves/request_form.php`, `payslip.php` (list + detail) |
| **ไม่แตะ** | Logic, CSRF, DB, HR dashboard |

## 2. Preflight

- **คำสั่ง:** `php scripts/production_preflight.php --strict`
- **ผล (dev ตัวอย่าง):** `1 failure(s), 5 warning(s), 67 ok` — failure เดิม schema (`offsite_*`) ไม่เกี่ยวกับงาน UI

## 3. สถานะก่อนแก้ (survey)

| ไฟล์ | Breadcrumb ก่อนแก้ | ช่องว่าง |
|------|---------------------|----------|
| `checkin.php` | ไม่มี — มีแต่ chip วันที่ | เพิ่ม `หน้าแรก` → `ลงเวลา` |
| `leave.php` (dashboard) | ไม่มี | เพิ่ม `หน้าแรก` → `การลา` |
| `request_form.php` | `การลา` / `ยื่นขอลา` เท่านั้น | เติมราก `หน้าแรก` |
| `attendance_history.php` | `ลงเวลา` / `ประวัติ` | เติม `หน้าแรก` |
| `dayoff_schedule.php` | `ลงเวลา` / `วันหยุด` | เติม `หน้าแรก` |
| `payslip.php` (รายการ) | ไม่มี | เพิ่ม `หน้าแรก` → `สลิปเงินเดือน` |
| `payslip.php` (รายละเอียด) | `สลิป` / `รายละเอียด` | เติมราก `หน้าแรก` |
| `leave_history.php` | `การลา` / `ประวัติ` เท่านั้น | เติม `หน้าแรก` + ลิงก์ `การลา` |
| `profile.php` | ไม่มี | เพิ่ม `หน้าแรก` → `ข้อมูลส่วนตัว` |

รายการด้านบนเป็นสถานะ **ก่อน** merge; หลัง merge ทุกแถวด้านบนถูกปรับให้มีราก `หน้าแรก` ตามรูปแบบเดียวกัน

## 4. แผนการทำ

- ใช้รูปแบบเดียวกับที่มีในโปรเจกต์: `text-sm text-white/60 mb-2`, ลิงก์ `hover:text-white touch-manipulation`, หน้าปัจจุบัน `text-white` ไม่ใช่ลิงก์
- ลิงก์หน้าแรก: **`index.php`** (relative จากรากไซต์ — สอดคล้องกับลิงก์อื่นในแอป)
- **ไม่** bump `native-shell.css` เว้นแต่ต้องการ cache bust — ไม่จำเป็นสำหรับแค่ markup

## 5. ความเสี่ยง

- **ต่ำ** — เพิ่มเฉพาะ DOM ด้านบน header
- ตรวจ **แท็บ focus / screen reader**: โครงสร้าง breadcrumb ตรงกับหน้าอื่นแล้ว

## 6. หลัง merge (manual)

- [ ] เปิดแต่ละหน้า: breadcrumb แสดงถูกต้อง, ลิงก์ `หน้าแรก` กลับ dashboard ได้
- [ ] มุมมองขอลา (`leave.php?action=request`): สามระดับอ่านลำดับถูกต้อง

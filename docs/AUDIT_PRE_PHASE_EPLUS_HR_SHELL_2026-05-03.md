# Pre-phase audit — Phase E+ TP-HR shell (Tier A vs tp-checkin) — 2026-05-03

## 1. Authority และขอบเขต

- **มาตรฐานอ้างอิง:** [tp-common `UI_UX_STANDARD.md`](https://github.com/sakonwanth/tp-common/blob/main/UI_UX_STANDARD.md) **§0** (canonical shell — tp-checkin), **§0.2** Tier A  
- **คิวหน้าจอ:** [tp-common `UI_UX_AUDIT_REPORT.md` §2.7](https://github.com/sakonwanth/tp-common/blob/main/UI_UX_AUDIT_REPORT.md)  
- **ข้อจำกัด:** รายงานนี้เป็น **code/layout survey** — ไม่แทนการทดสอบบนอุปกรณ์จริงทุกรุ่น (ดู §6 QA)

## 2. Preflight / ความพร้อมของสภาพแวดล้อม

- **คำสั่ง:** `php scripts/production_preflight.php --strict`  
- **ผล (dev DB ตัวอย่าง):** `Summary: 1 failure(s), 5 warning(s), 67 ok` — failure เดิมเรื่องคอลัมน์ `hr_attendances` (สัญญา `offsite_*`) ตามรอบก่อน **ไม่เกี่ยวกับงาน shell**  
- **ข้อสรุป:** ไม่บล็อกการวางแผน UI; **ก่อน ship ใหญ่** ควรผ่าน preflight บน production จนสรุป `[FAIL]` = 0

## 3. สถาปัตย์ shell ร่วม (TP-HR)

| องค์ประกอบ | ที่อยู่ในโค้ด | การเทียบ tp-checkin §0.1 |
|-------------|----------------|---------------------------|
| **Gradient canvas + IBM Plex Thai** | `templates/header.php` (inline + Google Fonts) | สอดคล้อง |
| **`header-glass` / `glass-card`** | นิยามใน `templates/header.php` | สอดคล้องคำอธิบาย check-in |
| **`native-shell.css` + `app.css`** | ลิงก์ใน `<head>` | Tier A tokens / bottom tab (`--tp-ios-*`) |
| **`viewport-fit=cover` + safe-area** | meta + body padding | สอดคล้อง |
| **`theme-color` `#7c3aed`** | header | สอดคล้อง primary check-in |
| **Bottom navigation (พนักงาน)** | `templates/footer.php` | เฉพาะเส้นทางที่ไม่ใช่ HR-only desktop-first |

**หมายเหตุ:** หน้า **login** และ **verify_document** ไม่ใช้ `templates/header.php` แบบเต็ม — ออกแบบแยกตามบทบาท (auth / public verify) ซึ่ง **สอดคล้อง** กับกฎไม่บังคับ bottom bar ทุกหน้า

## 4. ตารางติดตาม — พนักงาน / self-service

คิวจาก §2.7 — สถานะ **shell** = การจัดวางกับ Tier A (header/footer, native cards, touch) ไม่ใช่ความสมบูรณ์ของฟีเจอร์ธุรกิจ

| หน้า | Audit (วันที่) | Shell | หมายเหตุสั้นๆ |
|------|----------------|-------|----------------|
| `index.php` | 2026-05-03 | **Aligned** | dashboard + CTA ลงเวลา; `btn-primary` เป็น token ใน header ไม่ใช่ Bootstrap |
| `login.php` | 2026-05-03 | **Partial** | โทนคล้าย CRM card; ใช้ `native-shell.css`; ไม่มี bottom nav — **ตั้งใจ** |
| `checkin.php` | 2026-05-03 | **Aligned** | `tp-native-*` / `--tp-ios-card-radius`; footer ร่วม |
| `dayoff_schedule.php` | 2026-05-03 | **Aligned** | กริดสัปดาห์ + scroll แคบ — Wave B+ |
| `leave.php` | 2026-05-03 | **Aligned** | รวม partial ขอลา |
| `leave_history.php` | 2026-05-03 | **Aligned** | card/table `md` |
| `attendance_history.php` | 2026-05-03 | **Aligned** | |
| `payslip.php` | 2026-05-03 | **Aligned** | toast ดาวน์โหลด — Wave B |
| `profile.php` | 2026-05-03 | **Aligned** | early-exit รวม footer แล้ว `exit`; path ปกติ footer ท้ายไฟล์ |
| `certificate.php` | 2026-05-03 | **Aligned** | |
| `certificate_print.php` | 2026-05-03 | **N/A** | โหมดพิมพ์; ไม่เป้าหมาย shell เดียวกับ PWA |
| `verify_document.php` | 2026-05-03 | **Partial** | โหลด `native-shell.css` โดยไม่มี sidebar แบบแอปหลัก — เหมาะสม public |
| `modules/employee/leaves/request_form.php` | 2026-05-03 | **Aligned** | partial ภายใต้ `leave.php`; native form tokens |

## 5. ตารางติดตาม — HR dashboard / `hr/`

| หน้า | Audit (วันที่) | Shell | หมายเหตุสั้นๆ |
|------|----------------|-------|----------------|
| `hr/index.php` | 2026-05-03 | **Aligned** | /widgets + document queue — ยังมีจุดทึบข้อมูลบน desktop ที่อาจปรับ chrome ได้ในรอบถัดไป |
| `hr/employees.php` | 2026-05-03 | **Partial** | รายการหลัก hybrid; โฟกัสถัดไป: ความสม่ำเสมอของหัวตาราง vs native title block |
| `hr/employee_view.php` | 2026-05-03 | **Partial** | มุมมองรายบุคคล — โครงสร้างตาราง/การ์ดต่างจาก check-in แต่ยังอยู่ใน token เดียวกัน |
| `hr/employee_form.php` | 2026-05-03 | **Aligned** | แท็บ + touch — Wave B+ |
| `hr/attendance.php` | 2026-05-03 | **Aligned** | card/table `md` |
| `hr/employee_attendance.php` | 2026-05-03 | **Aligned** | |
| `hr/leaves.php` | 2026-05-03 | **Aligned** | |
| `hr/dayoff_approvals.php` | 2026-05-03 | **Aligned** | |
| `hr/documents.php` | 2026-05-03 | **Aligned** | |
| `hr/document_templates.php` | 2026-05-03 | **Aligned** | native cards + modals |
| `hr/reports.php` | 2026-05-03 | **Aligned** | CEO รายงาน — tabs + empty states |
| `hr/api_keys.php` | 2026-05-03 | **Aligned** | |
| `hr/settings.php` | 2026-05-03 | **Partial** | ฟอร์ม/ตารางยาว; ใช้ shell ร่วม — อาจลดความหนาแน่นเป็นครั้งๆ |

**เส้นทางที่เกี่ยวข้องแต่ไม่ได้ระบุใน §2.7 (สำหรับรอบถัดไป):** `hr/attendance_adjustments.php`, `hr/outside_attendance.php` — ทั้งคู่ใช้ `header.php` / `footer.php` แล้ว

## 6. ช่องว่างเชิง UX (ไม่ใช่รายการแก้ในครั้งนี้)

1. **อุปกรณ์จริง:** รัน checklist มือ [UI_UX_AUDIT_REPORT.md §6](https://github.com/sakonwanth/tp-common/blob/main/UI_UX_AUDIT_REPORT.md) (iPhone Safari / Android Chrome / keyboard / font scaling)  
2. **โทนสีรอง:** ปุ่ม/สถานะย่อยยังต่างจาก check-in ในบางหน้า — ยอมรับได้จนกว่าจะ sync token ชุดเดียวกับ tp-checkin `tailwind.config`  
3. **ความหนาแน่นข้อมูล:** `hr/employees.php`, `hr/settings.php` — Tier A ไม่บังคับ “การ์ดทุกแถว” บน desktop; เป้าหมายคือ **ใช้งานมือถือได้ + touch 44px+**

## 7. ลำดับ implementation ที่แนะนำ (หลัง audit นี้)

สอดคล้อง [DEVELOPMENT_ROADMAP Phase E+](https://github.com/sakonwanth/tp-common/blob/main/DEVELOPMENT_ROADMAP.md) — **ทีละหน้า**

1. **Partial → Aligned:** ปรับหัวข้อ/hero block ของ `hr/employees.php` และ `hr/employee_view.php` ให้ใกล้ `tp-ios-large-title-block` + spacing เดียวกับ self-service สูงสุด  
2. **login:** (ถ้าต้องการ) ปรับ card/พื้นหลังให้ใช้ชุด class เดียวกับชุด employee dashboard มากขึ้น — ไม่เปลี่ยนพฤติกรรม auth  
3. **HR dashboard:** `hr/index.php` — จัด secondary blocks ใต้ breakpoint (คล้าย CRM dashboard accordion) **เฉพาะเมื่อ** ไม่ทำลายความเร็วของ HR power user

## 8. สรุป

- **Audit ครบทุกแถวในคิว §2.7** (พนักงาน + `hr/` ตามรายการมาตรฐาน)  
- **ไม่พบการพึ่งพา Bootstrap CSS** ในเส้นทางหลัก — ชุด `btn-primary` เป็น token ภายใน  
- **Shell หลัก (header/footer/native-shell)** ครอบคลุมแอปพลิเคชัน HR เกือบทั้งหมด  
- **Remediation DB** ยังต้องตาม preflight แยกต่างหาก  

เอกสารนี้แทนรายการ “shell audit” ใน Phase E+ ก่อนเริ่ม wave implementation ถัดไป

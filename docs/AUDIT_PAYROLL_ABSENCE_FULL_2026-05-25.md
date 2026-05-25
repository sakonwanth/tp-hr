# Full Audit: ทำไมสลิปเงินเดือนมี "ขาดงาน" (Absence Deduction)

**วันที่:** 2026-05-25 (อัปเดต 2026-05-25 — ลาป่วย + ประกันสังคม)
**กรณีอ้างอิง:** [payroll_print.php?slip_id=251](https://crm.tp-asset.com/payroll_print.php?slip_id=251)  
**เคสที่พบใน HR Summary:** วิจิตรา เสนคะ (TPE01004) — ลาป่วย 22 พ.ค. 2569 แต่ยังแสดงขาดงาน

---

## 1. สรุปผู้บริหาร (Executive Summary)

ระบบ TP มี **2 ความหมายของ "ขาดงาน"** ที่ไม่เหมือนกัน:

| มุมมอง | ระบบ | ความหมาย |
|--------|------|----------|
| **HR สรุปพนักงาน** | tp-hr `EmployeeSummaryService` | นับจาก `hr_attendances.status = ABSENT` หรือไม่มี record |
| **เงินเดือน (สลิป)** | tp-crm `payroll_compute_attendance_deductions()` | นับกฎหักขาด/มาสาย — **ลาป่วยอนุมัติไม่หักเงินเดือน** |

ดังนั้น **พนักงานอาจมีใบลาป่วยอนุมัติแล้ว แต่สลิปยังหักขาดงานได้** — ถ้ามี record `ABSENT` ที่ไม่ sync กับใบลา หรือวันขาดจริง

> **อัปเดต 2026-05-25:** ยกเลิกกฎ `sick_no_cert` — สอดคล้อง พ.ร.บ. คุ้มครองแรงงาน ม.32 เปลี่ยนเป็น warning `sick_missing_cert` เท่านั้น

---

## 2. สถาปัตยกรรม (System Map)

```
tp-checkin          → เขียน hr_attendances (check-in/out)
tp-hr               → อนุมัติลา, backfill ABSENT, EmployeeSummary
tp-crm payroll      → คำนวณสลิป → payroll_slips (absence_deduction, attendance_detail_json)
                      แสดง payroll_print.php
```

**แหล่งข้อมูลร่วม (MySQL `tp_crm`):**

- `hr_attendances` — สถานะรายวัน (PRESENT, LATE, ABSENT, LEAVE, WFH, …)
- `hr_leave_requests` + `hr_leave_types` — ใบลา + ประเภท (SICK, …)
- `payroll_slips` — ผลคำนวณที่ freeze ตอน calculate/approve รอบเงินเดือน
- `system_settings` — อัตราหัก (`payroll_absent_rate` default 600 บาท/วัน)

**Engine คำนวณเงินเดือน (สำคัญ):**

- Production CRM ใช้ **`modules/payroll/queries.php`** → `payroll_compute_attendance_deductions()` (local)
- tp-hr มี **`PayrollService::computeAttendanceDeductions()`** (canonical ตาม Phase 6.1)
- Flag `USE_TPHR_PAYROLL=1` ใน `system_settings` ยังไม่ได้เปิดใช้ทั่วทั้ง CRM → **logic สองชุดต้อง sync**

---

## 3. กฎการหักขาดงานบนสลิป (5 Rules + ลาป่วย)

อ้างอิง `tp-crm/modules/payroll/queries.php` และ `tp-hr/core/Services/PayrollService.php`:

| # | เงื่อนไข | kind ใน breakdown | หัก (default) |
|---|----------|---------------------|---------------|
| 1 | `hr_attendances.status = ABSENT` (ไม่มีลาอนุมัติครอบคลุม) | `absent` | 600/วัน |
| 2 | มาสาย 1–30 นาที | `late_30` | 150/ครั้ง |
| 3 | มาสาย 31–60 นาที | `late_60` | 300/ครั้ง |
| 4 | มาสาย > 60 นาที (`payroll_late_over60_as_absent=1`) | `late_over60_absent` | 600/วัน |
| 5 | วันทำงานไม่มี record เลย (ไม่มีลาครอบคลุม) | `missing_attendance_absent` | 600/วัน |

**ลาป่วยอนุมัติ:** ไม่หักเงินเดือน — ไม่มีใบรับรอง → warning `sick_missing_cert` (ไม่ใส่ breakdown)

**ไม่หัก:** วันหยุดนักขัตฤกษ์, วันหยุดประจำสัปดาห์, วันที่มีใบลา (pending/approved), WFH auto-stamp, `late_excused=1`

---

## 4. Root Cause Analysis — เคส slip_id=251 / TPE01004

### 4.1 สาเหตุที่เป็นไปได้ (เรียงตามความน่าจะเป็น)

#### A) ~~ลาป่วยไม่มีใบรับรองแพทย์~~ (ยกเลิก 2026-05-25)

- เดิม: กฎ `sick_no_cert` หักเป็นขาด — **ไม่สอดคล้องกฎหมายแรงงาน**
- ปัจจุบัน: ลาป่วยอนุมัติไม่หักเงินเดือน; ไม่มีใบรับรอง = แจ้งเตือน HR เท่านั้น
- สลิปเก่าที่ freeze ก่อน deploy ยังมี `sick_no_cert` ใน JSON — ต้อง **recalculate**

#### B) Record ABSENT ค้าง + ใบลาอนุมัติทับซ้อน (bug — แก้แล้ว 2026-05-25)

- Cron `backfill_absences.php` สร้าง `ABSENT` ก่อน HR อนุมัติลา
- `crm_line_sync_approved_leave_attendance()` ควรเปลี่ยนเป็น `LEAVE` แต่อาจล้มเหลว / รันไม่ครบ
- **ผล:** HR Summary แสดงทั้ง "ขาดงาน" และ "ลาป่วยอนุมัติ" วันเดียวกัน (เช่น 22 พ.ค.)
- **Fix tp-hr:** `EmployeeSummaryService` reconcile + ไม่นับขาดเมื่อมีลาอนุมัติ (`1349e3d`)
- **Fix tp-crm payroll:** sync skip ABSENT เมื่อมีลาอนุมัติ (commit วันเดียวกัน)

#### C) วันขาดจริง (เช่น 14 พ.ค.)

- ไม่มีใบลา / ไม่มี check-in → `ABSENT` ถูกต้อง
- หัก 600 บาท/วันตามกฎข้อ 1

#### D) สลิป freeze ค่าเก่า

- `payroll_slips` เก็บผลตอน calculate รอบนั้น
- แก้ attendance/ใบลาหลัง approve แล้ว **ต้อง recalculate** ถึงจะเปลี่ยนสลิป

### 4.2 วิธีตรวจ slip_id=251 บน production

```bash
# บน server (path tp-hr)
php scripts/audit_payroll_slip_absence.php --slip-id=251
```

Script จะแสดง:

- พนักงาน / เดือน / ค่า `absent_days`, `absence_deduction` ใน DB
- Breakdown สดจาก `PayrollService` (tp-hr)
- รายการ `hr_attendances` + ใบลาอนุมัติ + conflict ABSENT∩LEAVE

**ดู breakdown ในสลิป:** คอลัมน์ `payroll_slips.attendance_detail_json` → `breakdown[].kind` + `note`

---

## 5. Cross-System Integration Gaps (พบจาก Audit)

| ช่องว่าง | ผลกระทบ | สถานะ |
|---------|---------|--------|
| CRM payroll ไม่ skip ABSENT เมื่อมีลาอนุมัติ | หักซ้ำ / ขัดกับ HR Summary | **แก้ queries.php** |
| CRM ไม่มี `findMissingAbsentDates` | วันไม่มี record อาจไม่หัก (under-deduct) | **เพิ่มแล้ว** |
| tp-hr fix แต่ CRM ยังใช้ local engine | สลิป CRM ไม่ได้รับ fix จนกว่า sync CRM | **แก้แล้ว** |
| `USE_TPHR_PAYROLL=0` | Logic ซ้ำ 2 repo เสี่ยง drift | แนะนำเปิด API mode ระยะกลาง |
| ลาป่วยไม่มีใบรับรอง | ~~หักเป็นขาด~~ → warning เท่านั้น | **แก้แล้ว 2026-05-25** |
| backfill_absences ไม่ overwrite row ที่มีอยู่ | ABSENT ค้างหลังอนุมัติลา | reconcile ตอนอ่าน summary + sync ตอน approve |

---

## 6. Data Flow — วันลาป่วย 22 พ.ค. (เคส TPE01004)

```mermaid
sequenceDiagram
    participant Cron as backfill_absences
    participant Att as hr_attendances
    participant HR as HR อนุมัติลา
    participant Sync as sync_approved_leave
    participant Pay as payroll_compute

    Cron->>Att: INSERT status=ABSENT (ถ้ายังไม่มี row)
    HR->>Att: sync ควรเป็น LEAVE
    Note over Att: ถ้า sync ไม่รัน → ยัง ABSENT
    Pay->>Att: ข้าม ABSENT ถ้ามีลาอนุมัติ
    Pay->>HR: SICK ไม่มี cert → warning เท่านั้น (ไม่หัก)
```

**หลัง fix (2026-05-25):**

- Summary: นับเป็นลา ไม่ใช่ขาด (ถ้ามีใบลาอนุมัติ)
- Payroll CRM + tp-hr: ข้าม ABSENT ถ้ามีลาอนุมัติ; **ลาป่วยไม่หักเงินเดือน**

---

## 7. วิธีแก้ไข / ปรับข้อมูล (Runbook)

### 7.1 สลิปเก่ามี sick_no_cert (ก่อน deploy)

1. Deploy fix ล่าสุด (tp-hr + tp-crm)
2. Payroll → รอบที่เกี่ยวข้อง → **Recalculate** พนักงานที่ได้รับผลกระทบ
3. ตรวจ `attendance_detail_json` ว่าไม่มี `sick_no_cert` แล้ว

### 7.2 ถ้าขาดเพราะ ABSENT ค้าง + มีใบลาอนุมัติ

1. Deploy fix ล่าสุด (tp-hr + tp-crm)
2. เปิด HR Summary ครั้งหนึ่ง (auto-reconcile ABSENT→LEAVE)  
   หรือรัน approve sync ซ้ำ / manual UPDATE status=LEAVE
3. Recalculate สลิปใน CRM

### 7.3 ถ้าขาดจริง (ไม่มีลา ไม่มีเวลา)

1. ยืนยันที่ `/hr/attendance.php` วันนั้น
2. ถ้าพนักงานมีเหตุผล → แก้สถานะเป็น LEAVE/PRESENT ผ่าน HR
3. Recalculate สลิป

### 7.4 Recalculate สลิป (CRM)

- รอบ status `draft` / `calculated`: แก้ salary setup หรือ attendance แล้วระบบ recalc auto บาง flow
- รอบ `approved`: ต้องยกเลิกอนุมัติ / recalculate manual ตาม process องค์กร

---

## 8. Checklist Regression

- [ ] `audit_payroll_slip_absence.php --slip-id=251` บน production
- [ ] ตรวจ `attendance_detail_json` ของ slip 251 — แต่ละ `kind`
- [ ] TPE01004 เดือน 2026-05: วัน 14 และ 22 ใน HR Summary ตรงกับ payroll
- [ ] ลาป่วยอนุมัติ (มี/ไม่มีใบรับรอง) → ไม่มี `sick_no_cert` ใน breakdown ใหม่
- [ ] Recalculate แล้ว `absence_deduction` ตรง breakdown
- [ ] เปรียบเทียบ tp-hr vs CRM `computeAttendanceDeductions` ให้ผลเท่ากัน

---

## 9. แนะนำ Phase ถัดไป

1. **เปิด `USE_TPHR_PAYROLL=1`** — engine เดียว ลด drift
2. **Recalculate batch** รอบที่มี sick_no_cert หรือ SS ผิดเพดาน หลัง deploy
3. **Monitor** conflict ABSENT+LEAVE ด้วย cron รายวัน reconcile

---

## 10. ประกันสังคม — เพดานใหม่ ม.ค. 2569

| ช่วงเงินเดือน | ฐานสูงสุด | สมทบสูงสุด (5%) |
|---------------|-----------|-----------------|
| ก่อน 2026-01-01 | 15,000 | 750 |
| ตั้งแต่ 2026-01-01 | 17,500 | 875 |

ฟังก์ชัน: `payroll_ss_wage_ceiling()` / `PayrollService::ssWageCeiling()` — override ได้ผ่าน `system_settings.payroll_ss_max_base`

---

## 11. ไฟล์อ้างอิง

| ไฟล์ | บทบาท |
|------|--------|
| `tp-crm/modules/payroll/queries.php` | `payroll_compute_attendance_deductions()` |
| `tp-crm/payroll_print.php` | แสดงสลิป + absence/lateness rows |
| `tp-hr/core/Services/PayrollService.php` | Canonical engine |
| `tp-hr/core/Services/EmployeeSummaryService.php` | HR dashboard summary |
| `tp-hr/cron/backfill_absences.php` | สร้าง ABSENT ย้อนหลัง |
| `tp-hr/core/CrmLineNotifierBridge.php` | `crm_line_sync_approved_leave_attendance()` |
| `tp-hr/scripts/audit_payroll_slip_absence.php` | CLI diagnostic |

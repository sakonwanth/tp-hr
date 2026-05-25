# Audit: สรุปรายพนักงาน (Employee Summary) — 2026-05-25

## ปัญหาเดิม

- ข้อมูลกระจัดกระจาย: `employee_attendance.php` (ปฏิทิน), `reports.php` (CEO รายงานรวม), `employee_view.php` (สถิติรายปีเท่านั้น)
- HR dashboard (`hr/index.php`) คำนวณ `$monthlyStats` แต่ไม่แสดงผล
- ไม่มีหน้ารวม **รายพนักงาน × รายเดือน** สำหรับผู้บริหาร/HR ในที่เดียว

## สิ่งที่เพิ่ม

| รายการ | ไฟล์ | สิทธิ์ |
|--------|------|--------|
| Service รวม logic | `core/Services/EmployeeSummaryService.php` | — |
| หน้าสรุปทั้งทีม | `hr/employee_summaries.php` | `hr_can_access_hr_dashboard()` |
| สรุปรายเดือนในโปรfile | `hr/employee_view.php` + `modules/hr/employee_monthly_summary.php` | HR+ |
| KPI รายเดือนบน HR dashboard | `hr/index.php` | HR+ |
| เมนู sidebar/mobile | `templates/header.php` | HR+ |

## ข้อมูลที่สรุป (ต่อคน/ต่อเดือน)

- วันทำงานที่ควรมา (ไม่นับวันหยุดประจำ / นักขัตฤกษ์ / บริษัท)
- มาทำงาน, มาสาย, WFH, ลา (จาก attendance), ขาด (ไม่มี record หรือ status ABSENT)
- วันหยุดนักขัตฤกษ์/บริษัท, วันหยุดประจำสัปดาห์
- ใบลาอนุมัติ (วันรวม + แยกประเภท), สิทธิ์ลาคงเหลือ
- การเปลี่ยนวันหยุด (`hr_dayoff_requests`) ในเดือน
- ชั่วโมงทำงานรวม, ใบลารออนุมัติ

## แหล่งข้อมูล

- `hr_attendances`, `hr_leave_requests`, `hr_leave_entitlements`, `hr_leave_types`
- `hr_holidays`, `hr_employee_schedules`, `hr_dayoff_requests`

## ข้อจำกัด / หมายเหตุ

- สรุปเดือนปัจจุบันนับถึง **วันนี้** เท่านั้น (ไม่นับวันในอนาคต)
- ขาดงาน = วันทำงานที่ไม่มี row ใน `hr_attendances` หรือ status `ABSENT`
- Org summary วน per-employee — เหมาะกับ headcount ขนาด SME; ถ้าโตควร cache/batch ใน phase ถัดไป
- CEO reports (`hr/reports.php`) ยังคงอยู่สำหรับ export CSV ระดับองค์กร

## Regression checklist

- [ ] HR/CEO เข้า `/hr/employee_summaries.php` ได้
- [ ] พนักงานทั่วไปเข้าไม่ได้ (redirect หน้าแรก)
- [ ] `employee_view.php?id=X&month=YYYY-MM` แสดงสรุปถูกต้อง
- [ ] HR dashboard แสดง KPI และลิงก์ "ดูรายพนักงาน"
- [ ] ตัวเลขสอดคล้องกับ `employee_attendance.php` รายเดือนเดียวกัน
- [ ] กด "รายละเอียด" ในตาราง → แสดงวันที่มาสาย / ลา / ขาด / สลับวันหยุด รายวัน
- [ ] ใบลาแสดงช่วงวันที่ + chip รายวันในเดือน
- [ ] สลับวันหยุดแสดงวันที่เปลี่ยนจากหยุด↔ทำงาน

## อัปเดต 2026-05-25 (รายละเอียดรายวัน)

- `EmployeeSummaryService` เพิ่ม `details` + `leave_requests` + `affected_days` สำหรับ dayoff
- Partial ใหม่: `modules/hr/employee_monthly_summary_details.php`
- หน้า `employee_summaries.php`: expand รายแถว (desktop) / `<details>` (mobile)

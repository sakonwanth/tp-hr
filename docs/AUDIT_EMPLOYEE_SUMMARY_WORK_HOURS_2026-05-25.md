# Audit: คอลัมน์ 「ชม.ทำงาน」 ในหน้าสรุปรายพนักงาน — 2026-05-25

## คำถาม

- คอลัมน์ **ชม.** / **ชม.ทำงาน** ในตาราง `/hr/employee_summaries.php` หมายถึงอะไร?
- ทำไมมีแค่พนักงานบางคน (มักคนเดียว) ที่มีตัวเลข ส่วนใหญ่เป็น 0 หรือว่าง?

---

## นิยาม (หลังแก้ไข)

| รายการ | ความหมาย |
|--------|----------|
| **ชม.ทำงาน** | ผลรวมชั่วโมงทำงานจริงในเดือนที่เลือก |
| **สูตร** | `SUM( work_minutes ) / 60` โดย `work_minutes` = (เวลาออก − เวลาเข้า − พัก) เป็นหน่วยนาที |
| **นับวันไหน** | เฉพาะ **วันทำงานที่ควรมา** (ไม่นับวันหยุดประจำ / นักขัตฤกษ์) และสถานะ PRESENT / LATE / WFH / HALF_DAY |
| **เงื่อนไขสำคัญ** | ต้องมี **เวลาเข้าและเวลาออกครบ** — ถ้ามีแค่เข้า ชม. = 0 (แสดง 「รอออก N」) |

---

## สาเหตุที่ส่วนใหญ่เป็น 0 / ว่าง

### 1. ระบบคำนวณชม. ตอน **ลงเวลาออก** เท่านั้น

- `AttendanceService::summarizeWork()` คืน `work_minutes = 0` ถ้าไม่มี `check_out_time`
- Check-in API บันทึกแค่เข้า → `work_minutes` ใน DB ยังเป็น 0
- Check-out API ถึงจะ UPDATE `work_minutes`

**ผล:** พนักงานที่เข้างานแล้วแต่ลืม/ยังไม่ออก → คอลัมน์ชม. = 0 แม้คอลัมน์ 「มา」 จะมีตัวเลข

### 2. ข้อมูลเก่า (migrate จาก `attendance_logs`)

- Migration `2026_04_21_unify_hr_source_of_truth.sql` ใส่ `work_minutes` จาก `work_hours * 60` ของ legacy
- พนักงานที่มีประวัติเก่าครบ → อาจมีชม.สูง
- พนักงานที่เริ่มใช้ระบบใหม่หลัง migrate → ขึ้นกับ check-out จริง

### 3. Bug เดิมใน `EmployeeSummaryService` (แก้แล้ว)

- อ่าน `work_minutes` จาก DB ตรง ๆ ไม่ recalc จาก check_in/check_out
- รวม `work_minutes` แม้สถานะ LEAVE/ABSENT (ไม่ควรนับ)
- ไม่แจ้งว่ามีกี่วัน 「รอลงเวลาออก」

### 4. ความต่างจากหน้า `employee_attendance.php`

- หน้าปฏิทินรายคน: `SUM(work_minutes)` จาก **ทุก row ในเดือน** (รวมวันหยุดถ้ามี row)
- หน้าสรุปทีม: นับเฉพาะ **วันทำงานที่ควรมา** ตามปฏิทิน + ตารางเวร

---

## Flow ข้อมูล

```
ลงเวลาเข้า → hr_attendances (check_in_time, work_minutes=0)
     ↓
ลงเวลาออก → summarizeWork() → work_minutes, ot_minutes, ...
     ↓
EmployeeSummaryService → resolveWorkMinutes() → work_hours แสดงในตาราง
```

---

## การแก้ไขที่ deploy แล้ว

1. **`resolveWorkMinutes()`** — ถ้า DB = 0 แต่มีเข้า+ออก → คำนวณใหม่จาก `AttendanceService`
2. **นับเฉพาะสถานะทำงาน** — PRESENT, LATE, WFH, HALF_DAY
3. **ตัวชี้วัดใหม่** — `incomplete_checkout_days`, `days_with_work_hours`
4. **UI** — เปลี่ยนหัวคอลัมน์เป็น 「ชม.ทำงาน」, แสดง `—` / `รอออก N`, คำอธิบายใต้ตาราง
5. **Script backfill** — `php scripts/backfill_work_minutes.php [--dry-run]` สำหรับ row ที่มีเข้า+ออกแต่ work_minutes=0

---

## Regression checklist

- [ ] พนักงานที่ checkout ครบทุกวัน → ชม.ทำงาน ≈ (วันทำงาน × ~8) ลบพัก
- [ ] เข้าอย่างเดียวไม่ออก → แสดง 「รอออก N」 ไม่ใช่ตัวเลขชม.ปลอม
- [ ] หลัง backfill → row เก่าที่มีเข้า+ออกแต่ work_minutes=0 แสดงชม.ถูกต้อง
- [ ] สอดคล้องกับ `hr/attendance.php` รายวัน (ชม.ต่อวัน)

---

## คำแนะนำ HR

1. ให้พนักงาน **ลงเวลาออก** ทุกวัน — ไม่เช่นนั้นชม.จะไม่ขึ้น
2. รัน backfill ครั้งเดียวบน production ถ้ามีข้อมูลเก่าเข้า+ออกครบแต่ชม.=0
3. แก้เวลาย้อนหลัง → `/hr/attendance.php` (ใส่ทั้งเข้าและออก)

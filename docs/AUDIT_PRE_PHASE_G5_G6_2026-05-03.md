# Pre-phase audit — G5 + G6 — 2026-05-03

## 1. Production preflight (`scripts/production_preflight.php --strict`)

- **ผล (สภาพแวดล้อม dev):** `Summary: 1 failure(s), 5 warning(s), 67 ok` — ความล้มเหลวหลัก: `hr_attendances` ยังไม่ครบคอลัมน์ `offsite_*` ตามสัญญา strict (คงเหมือนรอบก่อน)
- **ข้อสรุป:** ไม่บล็อกการแก้ G5/G6; ต้องจัดการ schema บน DB เป้าหมายแยก

## 2. G5 — API rate limit (`core/ApiAuth.php`)

| หัวข้อ | ก่อนแก้ |
|--------|---------|
| ที่เก็บ bucket | `storage/api_ratelimit` เท่านั้น |
| `fopen` ล้มเหลว | `return` → **ไม่นับจำนวน** = **fail-open** (AUDIT_13 MED-003) |
| ความเสี่ยง | โฟลเดอร์ไม่ writable / permission → ไม่มี rate limit |

**แนวแก้:** ลองสร้าง/เขียน primary แล้ว fallback ไป `sys_get_temp_dir()/tp_hr_ratelimit_{hash}/`; ถ้ายังใช้งานไม่ได้ → ค่าเริ่มต้น **503** «Rate limit store unavailable» เว้นแต่ตั้ง `HR_API_RATELIMIT_FAIL_OPEN=1` (พฤติกรรมเดิมแบบ fail-open)

## 3. G6 — รายงาน HR (`hr/reports.php`)

| หัวข้อ | ก่อนแก้ |
|--------|---------|
| ช่วงวันที่ | รับจาก GET/POST โดยไม่จำกัดความยาวช่วง |
| ความเสี่ยง | query หนักช่วงปี+ (MED-004) |

**แนวแก้:** ตรวจ `Y-m-d`; สลับถ้าเริ่ม > สิ้น; จำกัดความยาวช่วงด้วย `HR_REPORT_MAX_RANGE_DAYS` (default **366**); เกินแล้ว **cap วันสิ้นสุด** + แจ้งผู้ใช้ในหน้า; แสดงข้อความช่วยจำในฟอร์มกรอง

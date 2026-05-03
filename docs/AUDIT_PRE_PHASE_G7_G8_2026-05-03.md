# Pre-phase audit — G7 + G8 — 2026-05-03

## 1. Production preflight (`--strict`)

- **ผล:** `Summary: 1 failure(s), 5 warning(s), 67 ok` — ความล้มเหลวเดิม (`hr_attendances.offsite_*` บน DB dev)
- **ข้อสรุป:** ไม่บล็อกงาน G7/G8

## 2. G7 — Check-in integration & health

| หัวข้อ | สถานะก่อนแก้ |
|--------|----------------|
| `CHECKIN_APP_URL` / `CHECKIN_STORAGE_PATH` | มีใน `config/app.php` + `Helpers.php` |
| Health endpoint | `api/health.php` ใช้ `TpCommon\HealthCheck` แต่**ไม่มี**สถานะ integration check-in |
| MED-005 | ต้องการ env ชัดใน production + การมองเห็นใน health/readiness |

**แนวทาง:** ขยาย `HealthCheck` ให้รับ callback integration (optional); tp-hr ส่งบล็อก `checkin` (สถานะ URL/storage + readable); เพิ่มเอกสาร `docs/HEALTH_AND_CHECKIN_INTEGRATION.md`

## 3. G8 — Request correlation ID

| หัวข้อ | สถานะก่อนแก้ |
|--------|----------------|
| Log / exception JSON | ไม่มี `request_id` |
| ลูกค้า / proxy | ไม่มี `X-Request-Id` สม่ำเสมอ |

**แนวทาง:** ตั้ง `$_SERVER['TP_REQUEST_ID']` ต้น bootstrap (รับ `HTTP_X_REQUEST_ID` ถ้าปลอดภัย); ส่ง header `X-Request-Id`; ฝังใน `ErrorHandler` (plain + JSONL + JSON error เมื่อมี)

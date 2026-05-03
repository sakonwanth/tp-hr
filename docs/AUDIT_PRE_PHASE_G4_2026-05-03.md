# Pre-phase audit — G4 (upload policy) — 2026-05-03

**ก่อนแก้:** `hr/document_templates.php` — `dt_handleUpload()` ใช้ `finfo` + `getimagesize` + `move_uploaded_file` เอง (AUDIT_11 MED-002: ไม่สอดคล้องกับ helper กลาง)

## 1. Production preflight (`--strict`)

- **คำสั่ง:** `php scripts/production_preflight.php --strict`
- **ผล (เครื่อง dev นี้):** **1 failure** — `hr_attendances` ขาดคอลัมน์ `offsite_reason`, `offsite_approved_by`, `offsite_approved_at`, `offsite_remarks`; **5 warnings** ตามสคริปต์ (optional columns, migrations, empty `hr_api_keys`)
- **ข้อสรุป:** ไม่เกี่ยวกับงานอัปโหลด; ต้องแก้ schema บน DB เป้าหมายแยกต่างหาก

## 2. Code review — เส้นทางอัปโหลดใน tp-hr

| ไฟล์ | วิธี | หมายเหตุ |
|------|------|-----------|
| `core/Helpers.php` → `uploadFile()` | MIME + signature + จำกัดนามสกุล + ขนาด | **แหล่งความจริง** ที่ควรใช้ |
| `hr/document_templates.php` → `dt_handleUpload()` | ตรรกะแยก + `move_uploaded_file` | **เป้าหมาย G4** — delegate ไป `uploadFile` |
| `api/leave.php`, `api/certificate.php` | เรียก `uploadFile` แล้ว | ไม่ต้องแก้รอบนี้ |

## 3. ความเสี่ยงที่ลดลงหลังแก้

- นโยบายชนิดไฟล์ / ตรวจสอบ MIME+โครงสร้างไฟล์ **หนึ่งที่** กับฟอร์มลา/ใบรับรอง
- ลดความเพี้ยนของ validation เมื่อแก้ `uploadFile` ครั้งเดียว

## 4. ข้อจำกัด / backward compatibility

- ต้องเก็บ **URL ที่เก็บใน settings** เป็น **`/uploads/{company|signatures}/...`** เหมือนเดิม (img src ใน UI/print)
- `uploadFile` จะเขียนลง `STORAGE_PATH/uploads/{subdir}/` ให้สอดคล้องกับ `UPLOAD_PATH` เดิม

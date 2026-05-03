# Pre-phase audit — G1 (deploy webhook) — 2026-05-03

**ขอบเขต:** ก่อนแก้ `webhook.php` ตาม Phase G ใน `tp-common/DEVELOPMENT_ROADMAP.md`

## 1. Production preflight (`scripts/production_preflight.php --strict`)

- **คำสั่ง:** `php scripts/production_preflight.php --strict` (เครื่อง dev ใน workspace นี้)
- **ผล:** **1 failure** — ตาราง `hr_attendances` บน DB ที่เชื่อม local **ไม่มีคอลัมน์** `offsite_reason`, `offsite_approved_by`, `offsite_approved_at`, `offsite_remarks` (สัญญา schema ตาม preflight ยังไม่ครบบน DB ตัวอย่าง)
- **คำเตือน:** optional columns / migration reconciliation / `hr_api_keys` ว่าง — ตามข้อความสคริปต์
- **ข้อสรุป:** ความล้มเหลวนี้บ่งชี้ **drift ของ DB บนเครื่องที่รัน audit** ไม่ใช่ regression จากงาน webhook; ก่อน deploy production ควรรัน preflight กับ DB เป้าหมายและแก้ migration ให้ผ่าน

## 2. Code review — `webhook.php` (ก่อนแก้)

| หัวข้อ | สถานะ | หมายเหตุ |
|--------|--------|----------|
| HMAC `X-Hub-Signature-256` | ดี | ตรวจ `hash_equals` |
| เฉพาะ event `push` + ref `main` | ดี | |
| `exec(git pull)` จาก endpoint สาธารณะ | เสี่ยงสูง | ถ้ารั่ว `WEBHOOK_SECRET` = เสียหายทันที |
| Replay | ไม่มี | body เดิมลงชื่อถูกต้องยิงซ้ำได้ → เพิ่ม `X-GitHub-Delivery` |
| `chown` หลัง pull | เสี่ยง/คลาดเคลื่อน | ทำเป็น opt-in ผ่าน env |
| `require bootstrap.php` | เกินจำเป็น | เปิด session + โหลดแอปทั้งก้อนในทุกคำขอ webhook |

## 3. การแก้ที่เลือกใช้ (หลัง audit)

- โหลดเฉพาะ Composer autoload + `Env` สำหรับ webhook (ไม่ session / ไม่ DB)
- บังคับ `X-GitHub-Delivery` และเก็บ delivery id ที่รับแล้ว (กันยิงซ้ำ)
- optional `WEBHOOK_GITHUB_REPO=owner/repo` ให้ตรงกับ `repository.full_name`
- `chown` เฉพาะเมื่อ `WEBHOOK_POST_PULL_CHOWN=1`
- ล็อกแบบ non-blocking ระหว่าง deploy กันซ้อน
- ตอบ JSON สั้น ๆ; รายละเอียดคำสั่งเขียน `storage/logs/deploy.log` เป็นหลัก

## 4. Gate หลัง deploy

- ตั้งค่า `WEBHOOK_GITHUB_REPO` ให้ตรง repo จริง
- ถ้าต้องการ chown แบบเดิม: ตั้ง `WEBHOOK_POST_PULL_CHOWN=1`
- รัน preflight บน production DB จนไม่มี failure ใน strict mode

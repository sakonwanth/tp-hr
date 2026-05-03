# Health check & TP-Checkin integration

## Endpoints

- **GET** `/api/health` — JSON health (ใช้ `TpCommon\HealthCheck`)
- Optional lockdown: `HEALTH_CHECK_TOKEN` + query `?token=` หรือ header `X-Health-Check-Token`
- Optional rate limit: `HEALTH_CHECK_RATE_LIMIT_PER_MIN`

เมื่อ `APP_DEBUG=true` หรือส่ง token ถูกต้อง จะได้รายละเอียด `checks` ครบ รวมบล็อก **`checkin`** (สถานะการตั้งค่า check-in)

## Environment — TP-Checkin

ตั้งใน `.env` ของ tp-hr (ดู `config/app.php`):

| ตัวแปร | ความหมาย |
|--------|-----------|
| `CHECKIN_APP_URL` | URL สาธารณะของแอป check-in (ลงท้ายไม่มี `/`) — ใช้สร้างลิงก์รูปเมื่อไม่ proxy ผ่าน HR |
| `CHECKIN_STORAGE_PATH` | path แบบ absolute ไปที่โฟลเดอร์ `storage` ของ tp-checkin บนเซิร์ฟเวอร์เดียวกัน — เปิดใช้ `/api/checkin_storage_image.php` (same-origin) |

**แนะนำ production:** ตั้ง `CHECKIN_APP_URL` ให้ชัดเสมอ หากโฮสต์ HR ไม่ได้ derive จาก `hr.*` → `checkin.*` ได้

`/api/health` ใน production จะให้ `checkin.status = warning` ถ้า `APP_ENV=production` และยังไม่ได้ตั้ง `CHECKIN_APP_URL` (ยังไม่ทำให้ HTTP 503 เอง) — หาก `CHECKIN_STORAGE_PATH` ชี้แล้วแต่โฟลเดอร์อ่านไม่ได้ จะเป็น `error` และสถานะรวม **degraded**

## Request correlation (G8)

ทุกคำขอ web ตั้ง header ตอบกลับ **`X-Request-Id`**. ส่งค่าเดิมต่อได้ด้วย header **`X-Request-Id`** จากฝั่ง client/proxy (รูปแบบ `[A-Za-z0-9._@-]{1,64}`); ถ้าไม่ส่งหรือไม่ผ่านรูปแบบ ระบบสร้างค่าใหม่

ค่าอยู่ใน `$_SERVER['TP_REQUEST_ID']` และถูกแนบใน log exception (`tp-common` ErrorHandler) เมื่อมีการบันทึก

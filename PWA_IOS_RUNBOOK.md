# PWA_IOS_RUNBOOK.md — ขั้นตอนที่ต้องทำเอง

คู่มือนี้ครอบคลุมเฉพาะงานที่ต้องใช้สิทธิ์ที่ผู้ช่วยไม่มี: เข้า server production,
แก้ DB จริง, และบัญชี Apple Developer

**ลำดับสำคัญ** — ข้าม Phase ไม่ได้ แต่ละ Phase ใช้งานได้จริงด้วยตัวเอง

---

## Phase 0 — Merge (5 นาที)

`composer.lock` ของ tp-hr อ้าง commit ของ tp-common ที่ยังอยู่บน branch
ถ้า merge tp-hr ก่อน จะได้ lock ที่ชี้ไป commit ที่ไม่มีบน main

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/tp-common && git checkout main && git merge claude/pwa-session-lifetime && git push
```

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/tp-hr && git checkout main && git merge claude/hr-system-ios-app-24c2f1 && git push
```

push ไป main = GitHub Actions deploy อัตโนมัติ **อย่าเพิ่ง push tp-hr จนกว่าจะทำ Phase 1 เสร็จ**

---

## Phase 1 — Nginx (ทำก่อน deploy, 10 นาที)

SSH เข้า server แล้วเพิ่ม 2 บรรทัดใน server block ของ `hr.tp-asset.com`:

```nginx
include /path/to/tp-hr/deploy/nginx-pwa.conf;
include /path/to/tp-hr/deploy/nginx-deny-internal-paths.conf;
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

**ทำไมข้ามไม่ได้:** ถ้า `/sw.js` ไม่มี `Cache-Control: no-store` เบราว์เซอร์จะยึด
service worker ตัวเก่าไว้ได้ถึง 24 ชม. อาการหลอกมาก — `curl` เห็นไฟล์ใหม่ แต่เครื่อง
พนักงานยังรันตัวเก่า (ผมเสียเวลาหลายรอบกับอาการนี้ตอนพัฒนา)

ตรวจหลัง reload — ต้องเห็น `no-store` และ 404:

```bash
curl -sI https://hr.tp-asset.com/sw.js | grep -i cache-control && curl -so /dev/null -w "%{http_code}\n" https://hr.tp-asset.com/ios-app/package.json
```

จากนั้น push tp-hr ไป main ได้ (Phase 0)

**ถึงตรงนี้: PWA ใช้งานได้เต็มรูปแบบ** ส่ง `https://hr.tp-asset.com/install.html`
ให้พนักงานติดตั้งได้เลย ไม่ต้องรอ Phase ถัดไป

---

## Phase 2 — Web Push (20 นาที)

### 2.1 Migration

DB `tp_crm` ใช้ร่วม 5 โปรเจกต์ — สำรองก่อนเสมอ

```bash
mysqldump -u root -p tp_crm > ~/tp_crm_backup_$(date +%F).sql
```

migration นี้ additive ล้วน (สร้างตารางใหม่ ไม่แตะของเดิม) ไม่มี down script
ถ้าจะย้อน: `DROP TABLE hr_push_subscriptions;`

```bash
mysql -u root -p tp_crm < database/migrations/2026_08_07_hr_push_subscriptions.sql
```

### 2.2 VAPID keys

รันบน server (ต้องมี `vendor/` แล้ว):

```bash
php scripts/generate_vapid_keys.php
```

ก๊อป 3 บรรทัดที่ได้ใส่ `.env` ของ production

⚠️ **private key เก็บให้ดี** ถ้า gen ใหม่ subscription ของพนักงานทุกคนใช้ไม่ได้ทันที ต้องกดเปิดแจ้งเตือนใหม่หมด

### 2.3 ตรวจ

เปิดแอปจากหน้าโฮมบนมือถือจริง → รอ ~4 วิ ควรมีการ์ดถามเปิดแจ้งเตือน →
กดเปิด → ให้ HR อนุมัติใบลาของคุณ → ต้องได้ทั้ง LINE และ push

ถ้าการ์ดไม่ขึ้น เช็คตามลำดับ:

```bash
curl -s "https://hr.tp-asset.com/api/push.php?action=config" -H "Cookie: tp_session=<session ของคุณ>"
```

`enabled: false` = ยังไม่ได้ใส่ VAPID key หรือยังไม่ได้รัน migration

---

## Phase 3 — iOS App (Ad Hoc)

จำเป็นเฉพาะเมื่ออยากได้ Face ID / กล้อง native / APNs ถ้า PWA พอแล้วข้าม Phase นี้ได้เลย

### 3.1 Apple Developer Program — $99/ปี

สมัครที่ developer.apple.com ในนามบริษัท (ใช้ D-U-N-S) รออนุมัติ 1–2 วัน

### 3.2 ลงทะเบียนเครื่อง (จำกัด 100 เครื่อง/ปี)

ขอ UDID จากพนักงานแต่ละคน: ต่อสาย Mac → Finder → คลิกชื่อเครื่องจนขึ้น UDID
หรือให้เปิด `https://udid.tech` จาก Safari บนมือถือ

เพิ่มที่ developer.apple.com → Devices → สร้าง **Ad Hoc provisioning profile**
ครอบ `com.tpasset.tphr` + เครื่องทั้งหมด

### 3.3 Build

```bash
cd ios-app && cp ExportOptions.plist.example ExportOptions.plist
```

แก้ `YOUR_TEAM_ID` เป็น Team ID จริง (developer.apple.com → Membership) แล้ว:

```bash
cd ios-app && npm install && npm run archive && npm run export:adhoc
```

ได้ `ios-app/build/ipa/App.ipa`

### 3.4 อัปโหลดขึ้น server

```bash
cp ios-app/build/ipa/App.ipa /path/to/tp-hr/ios/TP-HR.ipa && cp ios/manifest.plist.example /path/to/tp-hr/ios/manifest.plist
```

แก้ `bundle-version` ใน `manifest.plist` ให้ตรงเวอร์ชันที่ build

**ทั้ง .ipa และ manifest.plist ต้องเสิร์ฟผ่าน HTTPS ที่ iOS เชื่อถือ** — cert self-signed
จะขึ้นแค่ "Cannot connect" โดยไม่บอกสาเหตุ

### 3.5 ส่งให้พนักงาน

ส่งลิงก์ `https://hr.tp-asset.com/ios/` — หน้านี้มีปุ่มติดตั้งและขั้นตอนครบแล้ว

⚠️ ต้องเปิดด้วย **Safari** เท่านั้น เปิดจากในแอป LINE แล้วกดปุ่มจะไม่มีอะไรเกิดขึ้น
(เป็นเรื่องที่คนติดกันมากที่สุด หน้าเว็บเลยเตือนไว้ด้านบนสุด)

### 3.6 ตั้งเตือนต่ออายุ

**Ad Hoc provisioning profile หมดอายุใน 1 ปี — แอปจะเปิดไม่ขึ้นเฉย ๆ ไม่เตือนล่วงหน้า**

ตั้งเตือนปฏิทินล่วงหน้า 1 เดือน แล้ววน 3.3–3.5 ใหม่
เพิ่มเครื่องใหม่ระหว่างปีก็ต้อง regenerate profile แล้ว export ใหม่เหมือนกัน

---

## สิ่งที่ยังใช้ไม่ได้ในแอป (ตั้งใจ)

| เรื่อง | สถานะ |
|---|---|
| ดาวน์โหลด PDF สลิป | **พังในแอป** — WKWebView ดาวน์โหลดไฟล์ไม่ได้ ให้ใช้ Safari หรือ PWA แทน |
| Face ID, กล้อง native, GPS background, APNs | ยังไม่ได้ต่อ — ดู `ios-app/README.md` |

แจ้งเตือนตอนนี้ใช้ Web Push (Phase 2) ซึ่งทำงานทั้งใน PWA และในแอป บน iOS 16.4+

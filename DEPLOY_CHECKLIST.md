# DEPLOY_CHECKLIST.md — TP-HR

**Purpose:** repeatable checks before / after deploying **`tp-hr`** (especially after **`native-shell.css`** token or IOS26 markup changes).

**Rolling status:** Reset `[ ]` when you cut a new release tag or deploy bundle; stamp dates when PASS.

---

## Pre-deploy (repo)

- [ ] **Shell cache bust:** `/assets/css/native-shell.css` uses **`?v=15`** in —  
  `templates/header.php`, `login.php`, `verify_document.php`, `certificate_print.php`.  
  If you bump the file for real, bump **`?v=` everywhere** together.
- [ ] **`app.css`** still linked ahead of **`native-shell.css`** where both load (`header.php`, login, verify).
- [ ] **Regression scope:** confirm PHP-only deploy (no unintended `database/migrations/*` in the same commit unless that release includes DB work).
- [ ] **`npm run verify:static-ui`** passes locally **or** GitHub Actions **`ci.yml` / `deploy.yml` (validate)** green after **`npm ci`** — **`native-shell.css`** **`?v=`** เดียวกันทุก loader + หน้า ESS + **`hr/*.php`** ยังมี **`tp-ios-master-screen`** (รายละเอียด **`scripts/verify-*.sh`**).

**Authoritative IOS26 state:** [`06_IMPLEMENTATION_PROGRESS.md`](06_IMPLEMENTATION_PROGRESS.md) · **Manual QA:** [`07_SPACING_QA.md`](07_SPACING_QA.md) · [`08_VISUAL_QA_AFTER.md`](08_VISUAL_QA_AFTER.md) · **[`10_BROWSER_VIEWPORT_QA.md`](10_BROWSER_VIEWPORT_QA.md)** · static viewport/CSS evidence: [`AUDIT_04_VIEWPORT.md`](AUDIT_04_VIEWPORT.md) · master dashboard: [`03_MASTER_SCREEN_VISUAL_QA.md`](03_MASTER_SCREEN_VISUAL_QA.md) · **completion narrative:** [`09_COMPLETION_GATE.md`](09_COMPLETION_GATE.md).

---

## Post-deploy smoke (~15 min)

**ESS (logged-in employee, mobile width):**

- [ ] `/` dashboard — tabs + sticky CTA feel ok (see **`03`** if anything regresses).
- [ ] `/checkin.php` · `/leave.php` · `/profile.php` — one path each.
- [ ] `/payslip.php` list or empty state.

**HRA (HR user):**

- [ ] `/hr/index.php`
- [ ] One transactional page: e.g. `/hr/leaves.php` or `/hr/documents.php`

**Public / edge:**

- [ ] `/login.php` (unauth)
- [ ] `/verify_document.php` empty form (no leak of internal errors)

**Print (optional):** open certificate print preview from HR or ESS once; confirm toolbar + print dialog; A4 body still **Sarabun** on paper.

---

## PWA / Web Push

**One-time server setup (ต้องทำก่อน deploy รอบแรกที่มี `sw.js`):**

- [ ] Nginx includes [`deploy/nginx-pwa.conf`](deploy/nginx-pwa.conf) — `/sw.js` ต้องตอบ `Cache-Control: no-store`.
      ถ้าไม่ทำ เบราว์เซอร์จะยึด worker ตัวเก่าไว้ (สูงสุด 24 ชม.) แล้ว fix ที่ deploy ไปจะยังไม่ถึงเครื่องพนักงาน —
      อาการนี้หลอกมาก เพราะ `curl` เห็นไฟล์ใหม่แต่เบราว์เซอร์ยังรันตัวเก่า
- [ ] Nginx includes [`deploy/nginx-deny-internal-paths.conf`](deploy/nginx-deny-internal-paths.conf) — ต้อง block `/ios-app/` ด้วย
- [ ] รัน migration `database/migrations/2026_08_07_hr_push_subscriptions.sql` (additive, ตารางใหม่ล้วน)
- [ ] `php scripts/generate_vapid_keys.php` → ใส่ `VAPID_*` ใน `.env` production
      (ถ้าไม่ใส่ ระบบซ่อนฟีเจอร์ push เงียบ ๆ ไม่พัง — แต่ก็ไม่มีแจ้งเตือน)

**Every deploy that touches `sw.js`:**

- [ ] บั๊มพ์ `CACHE_VERSION` ใน [`sw.js`](sw.js) เมื่อแก้ precache list หรือกลยุทธ์ cache
- [ ] `php scripts/qa_pwa_push_contract.php` ผ่าน (CI รันให้อยู่แล้ว)

**Post-deploy smoke (มือถือจริง, เปิดจากไอคอนหน้าโฮม):**

- [ ] เปิดแอป → ไม่มีหน้า reload เด้งเอง (reload ต้องเกิดเฉพาะตอนกด "อัปเดต")
- [ ] เปิดโหมดเครื่องบิน แล้วกดเมนูสักหน้า → ต้องได้การ์ด **ไม่มีการเชื่อมต่ออินเทอร์เน็ต** ไม่ใช่หน้า error ของเบราว์เซอร์
- [ ] อนุมัติการลาให้ตัวเองสักใบ → ต้องได้ทั้ง LINE และ push
- [ ] ปิดแอปทิ้งไว้ข้ามคืน แล้วเปิดใหม่ → ยังล็อกอินอยู่ (`PWA_SESSION_LIFETIME`)

---

## Rollback

Revert the deploy commit that introduced CSS/markup regression; if only **`native-shell.css`** changed, restore previous file + matching **`?v=`** bump pattern.

# Playwright specs (`tests/e2e/`)

รันหลัก: [docs/E2E_PLAYWRIGHT.md](../../docs/E2E_PLAYWRIGHT.md)

## สรุปไฟล์

| ไฟล์ | บทบาท |
|------|--------|
| `health.spec.cjs` | `GET api/health.php` |
| `api-guest.spec.cjs` | `api/attendance`, `api/leave`, `api/profile` — 401 โดยไม่ล็อกอิน |
| `login.spec.cjs` | หน้า `login.php` |
| `protected-routes.spec.cjs` | guest → `login.php` |
| `public-verify.spec.cjs` | `verify_document.php` (public) |
| `auth.setup.cjs` | ล็อกอิน + `storageState` (เมื่อมี user/pass) |
| `authenticated.spec.cjs` | หลังล็อกอิน (phone + `tablet-auth`) |
| `visual-login.spec.cjs` / `visual-dashboard.spec.cjs` | snapshot (ต้อง `PLAYWRIGHT_VISUAL=1`) |

## ตัวแปรสิ่งแวดล้อมที่ใช้บ่อย

| ตัวแปร | ความหมาย |
|--------|-----------|
| `PLAYWRIGHT_BASE_URL` | Base URL ลงท้าย `/` เช่น `http://127.0.0.1/tp-hr/` |
| `PLAYWRIGHT_HR_USER` / `PLAYWRIGHT_HR_PASSWORD` | เปิดโปรเจกต์ auth + setup |
| `PLAYWRIGHT_HR_EXPECT_ADMIN` | `1` = รันเทส HR module / employee `?id=` |
| `PLAYWRIGHT_HR_EXPECT_CEO` | `1` = รันเทส CEO-only |
| `PLAYWRIGHT_HR_SAMPLE_EMPLOYEE_ID` | `users.id` สำหรับ `employee_view` / `employee_attendance` / `employee_form` (ค่าเริ่มต้น `1`) |
| `PLAYWRIGHT_SKIP_TABLET` | `1` = ไม่รันโปรเจกต์ tablet |
| `PLAYWRIGHT_VISUAL` | `1` = เปิด snapshot tests |

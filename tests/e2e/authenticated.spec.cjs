const { test, expect } = require('@playwright/test');

/** `?id=` for HR-only employee pages — must exist in DB for assertions to pass (default `"1"`). */
const HR_SAMPLE_EMPLOYEE_ID = (
  process.env.PLAYWRIGHT_HR_SAMPLE_EMPLOYEE_ID || '1'
).trim();

test.describe('Authenticated session', () => {
  test('dashboard shows greeting hero', async ({ page }) => {
    await page.goto('index.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1.dashboard-hero-title')).toBeVisible();
    await expect(page.locator('h1.dashboard-hero-title')).toContainText('สวัสดี');
  });

  test('check-in page title', async ({ page }) => {
    await page.goto('checkin.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/ลงเวลาเข้า-ออก/);
  });

  test('leave page title', async ({ page }) => {
    await page.goto('leave.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/การลา/);
  });

  test('profile page title', async ({ page }) => {
    await page.goto('profile.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/ข้อมูลส่วนตัว/);
  });

  test('payslip page title', async ({ page }) => {
    await page.goto('payslip.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/สลิปเงินเดือน/);
  });

  test('attendance history page title', async ({ page }) => {
    await page.goto('attendance_history.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/ประวัติการลงเวลา/);
  });

  test('leave history page title', async ({ page }) => {
    await page.goto('leave_history.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/ประวัติการลา/);
  });

  test('certificate request page title', async ({ page }) => {
    await page.goto('certificate.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/ขอหนังสือรับรอง/);
  });

  test('day-off schedule page title', async ({ page }) => {
    await page.goto('dayoff_schedule.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/วันหยุดประจำสัปดาห์/);
  });

  test('holiday work request page title', async ({ page }) => {
    await page.goto('holiday_work_request.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/ทำงานวันหยุด/);
  });

  test('holidays page links to holiday work request', async ({ page }) => {
    await page.goto('holidays.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('a[href="holiday_work_request.php"]')).toBeVisible();
  });

  test('hr admin index (requires HR-capable account)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_ADMIN !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_ADMIN=1 and use PLAYWRIGHT_HR_USER with hr dashboard access.',
    );
    await page.goto('hr/index.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/HR Dashboard/);
  });

  test('hr employees (requires HR-capable account)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_ADMIN !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_ADMIN=1 and use PLAYWRIGHT_HR_USER with hr dashboard access.',
    );
    await page.goto('hr/employees.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/จัดการพนักงาน/);
  });

  test('hr leaves management (requires HR-capable account)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_ADMIN !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_ADMIN=1 and use PLAYWRIGHT_HR_USER with hr dashboard access.',
    );
    await page.goto('hr/leaves.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/จัดการการลา/);
  });

  test('hr attendance management (requires HR-capable account)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_ADMIN !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_ADMIN=1 and use PLAYWRIGHT_HR_USER with hr dashboard access.',
    );
    await page.goto('hr/attendance.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/จัดการเวลาทำงาน/);
  });

  test('hr document requests (requires HR-capable account)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_ADMIN !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_ADMIN=1 and use PLAYWRIGHT_HR_USER with hr dashboard access.',
    );
    await page.goto('hr/documents.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/จัดการคำขอเอกสาร/);
  });

  test('hr document templates (requires HR-capable account)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_ADMIN !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_ADMIN=1 and use PLAYWRIGHT_HR_USER with hr dashboard access.',
    );
    await page.goto('hr/document_templates.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/ตั้งค่าเอกสารรับรอง/);
  });

  test('hr employee view — by id (requires HR + PLAYWRIGHT_HR_SAMPLE_EMPLOYEE_ID in DB)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_ADMIN !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_ADMIN=1 and PLAYWRIGHT_HR_SAMPLE_EMPLOYEE_ID to a real users.id.',
    );
    await page.goto(
      `hr/employee_view.php?id=${encodeURIComponent(HR_SAMPLE_EMPLOYEE_ID)}`,
      { waitUntil: 'domcontentloaded' },
    );
    await expect(page).toHaveTitle(/ดูข้อมูลพนักงาน/);
  });

  test('hr employee attendance — by id (requires HR + valid employee row)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_ADMIN !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_ADMIN=1 and PLAYWRIGHT_HR_SAMPLE_EMPLOYEE_ID to a real users.id.',
    );
    await page.goto(
      `hr/employee_attendance.php?id=${encodeURIComponent(HR_SAMPLE_EMPLOYEE_ID)}`,
      { waitUntil: 'domcontentloaded' },
    );
    await expect(page).toHaveTitle(/ประวัติลงเวลา/);
  });

  test('hr employee form — edit shell (requires HR + valid employee row)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_ADMIN !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_ADMIN=1 and PLAYWRIGHT_HR_SAMPLE_EMPLOYEE_ID to a real users.id.',
    );
    await page.goto(
      `hr/employee_form.php?action=edit&id=${encodeURIComponent(HR_SAMPLE_EMPLOYEE_ID)}`,
      { waitUntil: 'domcontentloaded' },
    );
    await expect(page).toHaveTitle(/แก้ไขข้อมูลพนักงาน/);
  });

  test('hr reports (requires CEO-level account)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_CEO !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_CEO=1 and use PLAYWRIGHT_HR_USER that passes isCEOOrAbove() (see hr/reports.php).',
    );
    await page.goto('hr/reports.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/รายงาน/);
  });

  test('hr settings (requires CEO-level account)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_CEO !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_CEO=1 and use PLAYWRIGHT_HR_USER that passes isCEOOrAbove() (see hr/settings.php).',
    );
    await page.goto('hr/settings.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/ตั้งค่าระบบ/);
  });

  test('hr day-off approvals (requires CEO-level account)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_CEO !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_CEO=1 and use PLAYWRIGHT_HR_USER that passes isCEOOrAbove() (see hr/dayoff_approvals.php).',
    );
    await page.goto('hr/dayoff_approvals.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/อนุมัติวันหยุด/);
  });

  test('hr holiday work approvals (requires CEO-level account)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_CEO !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_CEO=1 and use PLAYWRIGHT_HR_USER that passes isCEOOrAbove() (see hr/holiday_work_approvals.php).',
    );
    await page.goto('hr/holiday_work_approvals.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/อนุมัติทำงานวันหยุด/);
  });

  test('hr api keys (requires CEO-level account)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_CEO !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_CEO=1 and use PLAYWRIGHT_HR_USER that passes isCEOOrAbove() (see hr/api_keys.php).',
    );
    await page.goto('hr/api_keys.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/External API Keys/);
  });
});

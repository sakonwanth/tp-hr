const { test, expect } = require('@playwright/test');

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

  test('hr admin index (requires HR-capable account)', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_ADMIN !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_ADMIN=1 and use PLAYWRIGHT_HR_USER with hr dashboard access.',
    );
    await page.goto('hr/index.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/HR Dashboard/);
  });
});

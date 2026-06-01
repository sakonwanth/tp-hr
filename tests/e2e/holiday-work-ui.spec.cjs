const { test, expect } = require('@playwright/test');

/**
 * Holiday work UI smoke (guest + optional auth via storageState from auth.setup).
 * Production: PLAYWRIGHT_BASE_URL=https://hr.tp-asset.com/
 */
test.describe('Holiday work UI (guest)', () => {
  test('holiday_work_request.php redirects to login', async ({ page }) => {
    await page.goto('holiday_work_request.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/login\.php/i);
  });

  test('hr/holiday_work_approvals.php redirects to login', async ({ page }) => {
    await page.goto('hr/holiday_work_approvals.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/login\.php/i);
  });

  test('holidays.php redirects to login', async ({ page }) => {
    await page.goto('holidays.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/login\.php/i);
  });

  test('login page loads for HR', async ({ page }) => {
    await page.goto('login.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).toBeVisible();
    await expect(page).toHaveURL(/login\.php/i);
  });
});

test.describe('Holiday work UI (authenticated)', () => {
  test('employee holiday work form has year filter and cards', async ({ page }) => {
    test.skip(
      !process.env.PLAYWRIGHT_HR_USER && !process.env.E2E_HR_USERNAME,
      'Set PLAYWRIGHT_HR_USER + PLAYWRIGHT_HR_PASSWORD for authenticated UI test.',
    );
    await page.goto('holiday_work_request.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/ทำงานวันหยุด/);
    await expect(page.locator('#hw-year')).toBeVisible();
    await expect(page.locator('form[method="POST"]')).toBeVisible();
  });

  test('CEO holiday work approvals page shell', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_HR_EXPECT_CEO !== '1',
      'Set PLAYWRIGHT_HR_EXPECT_CEO=1 and CEO-capable PLAYWRIGHT_HR_USER.',
    );
    await page.goto('hr/holiday_work_approvals.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/อนุมัติทำงานวันหยุด/);
    await expect(page.locator('h1, .tp-ios26-page-title').first()).toBeVisible();
  });
});

const { test, expect } = require('@playwright/test');

test.describe('Public login', () => {
  test('login.php loads and shows submit control', async ({ page }) => {
    await page.goto('login.php');
    await expect(page.locator('body')).toBeVisible();
    await expect(page.getByRole('button', { name: /เข้าสู่ระบบ/i }).first()).toBeVisible();
  });
});

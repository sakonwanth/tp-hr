const { test, expect } = require('@playwright/test');

test.describe('Public pages', () => {
  test('verify_document.php renders (no login)', async ({ page }) => {
    await page.goto('verify_document.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/ตรวจสอบความถูกต้องของเอกสาร/);
  });
});

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

const hrUser = process.env.PLAYWRIGHT_HR_USER || process.env.E2E_HR_USERNAME || '';
const hrPass = process.env.PLAYWRIGHT_HR_PASSWORD || process.env.E2E_HR_PASSWORD || '';

/** Written for the `chromium-auth` project (`playwright/.auth/hr-user.json`). */
const storagePath = path.join(__dirname, '..', '..', 'playwright', '.auth', 'hr-user.json');

test('log in and save session storage', async ({ page }) => {
  if (!hrUser || !hrPass) {
    throw new Error(
      'Auth setup requires PLAYWRIGHT_HR_USER and PLAYWRIGHT_HR_PASSWORD (or E2E_HR_USERNAME / E2E_HR_PASSWORD).',
    );
  }

  fs.mkdirSync(path.dirname(storagePath), { recursive: true });

  await page.goto('login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="username"]').fill(hrUser);
  await page.locator('input[name="password"]').fill(hrPass);
  await page.getByRole('button', { name: /เข้าสู่ระบบ/i }).click();

  await expect(page.locator('h1.dashboard-hero-title')).toBeVisible({ timeout: 30000 });

  await page.context().storageState({ path: storagePath });
});

const { test, expect } = require('@playwright/test');

/**
 * App pages use Auth::requireLogin(); unauthenticated users are sent to
 * /tp-hr/login.php (no tp-common) or CRM login with sso_return (SSO).
 */
const PROTECTED_ROUTES = [
  { name: 'dashboard', path: 'index.php' },
  { name: 'check-in', path: 'checkin.php' },
  { name: 'leave', path: 'leave.php' },
  { name: 'hr admin', path: 'hr/index.php' },
];

test.describe('Protected routes (guest)', () => {
  for (const { name, path } of PROTECTED_ROUTES) {
    test(`${name} (${path}) sends unauthenticated visitor to a login page`, async ({ page }) => {
      await page.goto(path, { waitUntil: 'load' });
      await expect(page).toHaveURL(/login\.php/i);
    });
  }
});

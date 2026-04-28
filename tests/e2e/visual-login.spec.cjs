const { test, expect } = require('@playwright/test');

/**
 * Opt-in screenshot baseline for `login.php` (`PLAYWRIGHT_VISUAL=1`).
 * First run with a running server:
 *   PLAYWRIGHT_VISUAL=1 npx playwright test tests/e2e/visual-login.spec.cjs --update-snapshots
 */
test.describe('visual — login shell', () => {
  test('login card snapshot', async ({ page }) => {
    await page.goto('login.php', { waitUntil: 'domcontentloaded' });
    const card = page.locator('.login-card').first();
    await expect(card).toBeVisible();
    await expect(card).toHaveScreenshot('login-card.png', {
      animations: 'disabled',
      maxDiffPixels: 400,
      maxDiffPixelRatio: 0.06,
      scale: 'css',
    });
  });
});

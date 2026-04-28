const { test, expect } = require('@playwright/test');

/**
 * Post-login screenshot for the dashboard hero (PLAYWRIGHT_VISUAL=1 + auth env).
 * Baselines: tests/e2e/visual-dashboard.spec.cjs-snapshots/{visual-auth,visual-auth-tablet}/
 *
 *   PLAYWRIGHT_VISUAL=1 npx playwright test tests/e2e/visual-dashboard.spec.cjs --update-snapshots
 */
test.describe('visual — dashboard (authenticated)', () => {
  test('dashboard hero snapshot', async ({ page }) => {
    await page.goto('index.php', { waitUntil: 'domcontentloaded' });
    const hero = page.locator('.dashboard-hero').first();
    await expect(hero).toBeVisible({ timeout: 20000 });
    await expect(hero).toHaveScreenshot('dashboard-hero.png', {
      animations: 'disabled',
      maxDiffPixels: 600,
      maxDiffPixelRatio: 0.08,
      scale: 'css',
    });
  });
});

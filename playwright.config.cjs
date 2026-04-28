const { defineConfig, devices } = require('@playwright/test');

/** Trailing path matters: relative routes must NOT start with / or they resolve to site root. */
const baseURL =
  process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1/tp-hr/';

module.exports = defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    trace: 'on-first-retry',
    locale: 'th-TH',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Pixel 5'] },
    },
  ],
});

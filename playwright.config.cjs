const { defineConfig, devices } = require('@playwright/test');

/** Trailing path matters: relative routes must NOT start with / or they resolve to site root. */
const baseURL =
  process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1/tp-hr/';

const hasAuthCredentials = Boolean(
  (process.env.PLAYWRIGHT_HR_USER || process.env.E2E_HR_USERNAME) &&
    (process.env.PLAYWRIGHT_HR_PASSWORD || process.env.E2E_HR_PASSWORD),
);

const storageStateHR = 'playwright/.auth/hr-user.json';

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
    ...(hasAuthCredentials
      ? [
          {
            name: 'setup',
            testMatch: /auth\.setup\.cjs$/,
          },
        ]
      : []),
    {
      name: 'chromium',
      testMatch: '**/*.spec.cjs',
      testIgnore: ['**/auth.setup.cjs', '**/authenticated.spec.cjs'],
      use: { ...devices['Pixel 5'] },
    },
    ...(hasAuthCredentials
      ? [
          {
            name: 'chromium-auth',
            dependencies: ['setup'],
            testMatch: /authenticated\.spec\.cjs$/,
            use: {
              ...devices['Pixel 5'],
              storageState: storageStateHR,
            },
          },
        ]
      : []),
  ],
});

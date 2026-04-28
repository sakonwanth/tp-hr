const { defineConfig, devices } = require('@playwright/test');

/** Trailing path matters: relative routes must NOT start with / or they resolve to site root. */
const baseURL =
  process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1/tp-hr/';

const hasAuthCredentials = Boolean(
  (process.env.PLAYWRIGHT_HR_USER || process.env.E2E_HR_USERNAME) &&
    (process.env.PLAYWRIGHT_HR_PASSWORD || process.env.E2E_HR_PASSWORD),
);

const storageStateHR = 'playwright/.auth/hr-user.json';

/** Screenshot snapshots (`visual*.spec.cjs`) — enable with PLAYWRIGHT_VISUAL=1. */
const runVisualSnapshots = process.env.PLAYWRIGHT_VISUAL === '1';

/** Guest suite on tablet (iPad). Disable with PLAYWRIGHT_SKIP_TABLET=1 to save time locally/CI. */
const skipTablet = process.env.PLAYWRIGHT_SKIP_TABLET === '1';

/** Shared ignore list for smoke + tablet (auth + optional visual). */
const guestIgnore = [
  '**/auth.setup.cjs',
  '**/authenticated.spec.cjs',
  ...(runVisualSnapshots ? [] : ['**/visual*.spec.cjs']),
];

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
      testIgnore: guestIgnore,
      use: { ...devices['Pixel 5'] },
    },
    ...(!skipTablet
      ? [
          {
            name: 'tablet',
            testMatch: '**/*.spec.cjs',
            testIgnore: guestIgnore,
            use: { ...devices['iPad Mini'] },
          },
        ]
      : []),
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

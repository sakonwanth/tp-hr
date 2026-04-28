const { test, expect } = require('@playwright/test');

/**
 * App pages use Auth::requireLogin(); unauthenticated users are sent to
 * /tp-hr/login.php (no tp-common) or CRM login with sso_return (SSO).
 */
const PROTECTED_ROUTES = [
  { name: 'dashboard', path: 'index.php' },
  { name: 'check-in', path: 'checkin.php' },
  { name: 'leave', path: 'leave.php' },
  { name: 'profile', path: 'profile.php' },
  { name: 'payslip', path: 'payslip.php' },
  { name: 'attendance history', path: 'attendance_history.php' },
  { name: 'leave history', path: 'leave_history.php' },
  { name: 'certificate', path: 'certificate.php' },
  { name: 'day-off schedule', path: 'dayoff_schedule.php' },
  { name: 'hr leaves mgmt', path: 'hr/leaves.php' },
  { name: 'hr attendance mgmt', path: 'hr/attendance.php' },
  { name: 'hr documents', path: 'hr/documents.php' },
  { name: 'hr document templates', path: 'hr/document_templates.php' },
  { name: 'hr api keys', path: 'hr/api_keys.php' },
  { name: 'hr reports', path: 'hr/reports.php' },
  { name: 'hr settings', path: 'hr/settings.php' },
  { name: 'hr day-off approvals', path: 'hr/dayoff_approvals.php' },
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

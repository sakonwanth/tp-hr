const { test, expect } = require('@playwright/test');

/** Legacy `Auth::requireLogin()` emits JSON body only when this header is present. */
const xhr = { 'X-Requested-With': 'XMLHttpRequest' };

function assertLegacyUnauthorizedBody(j) {
  expect(
    typeof j.error === 'string' ||
      typeof j.sso_login_url === 'string' ||
      j.success === false,
  ).toBeTruthy();
}

function assertV1UnauthorizedBody(j) {
  expect(j).toMatchObject({ success: false });
  expect(typeof j.error).toBe('string');
}

test.describe('API — unauthenticated', () => {
  test('GET api/attendance.php returns 401 JSON when not logged in', async ({ request }) => {
    const res = await request.get('api/attendance.php');
    expect(res.status()).toBe(401);
    const ct = (res.headers()['content-type'] || '').toLowerCase();
    expect(ct).toContain('application/json');
    const j = await res.json();
    expect(j.success === false || typeof j.error === 'string').toBeTruthy();
  });

  test('GET api/certificate.php returns 401 JSON with XHR when not logged in', async ({ request }) => {
    const res = await request.get('api/certificate.php', { headers: xhr });
    expect(res.status()).toBe(401);
    assertLegacyUnauthorizedBody(await res.json());
  });

  test('GET api/leave.php returns 401 JSON with XHR when not logged in', async ({ request }) => {
    const res = await request.get('api/leave.php', { headers: xhr });
    expect(res.status()).toBe(401);
    assertLegacyUnauthorizedBody(await res.json());
  });

  test('GET api/payslip.php returns 401 JSON with XHR when not logged in', async ({ request }) => {
    const res = await request.get('api/payslip.php', { headers: xhr });
    expect(res.status()).toBe(401);
    assertLegacyUnauthorizedBody(await res.json());
  });

  test('GET api/profile.php returns 401 JSON with XHR when not logged in', async ({ request }) => {
    const res = await request.get('api/profile.php', { headers: xhr });
    expect(res.status()).toBe(401);
    assertLegacyUnauthorizedBody(await res.json());
  });

  test('GET api/v1/employees returns 401 JSON when no Bearer API key', async ({ request }) => {
    const res = await request.get('api/v1/employees');
    expect(res.status()).toBe(401);
    const ct = (res.headers()['content-type'] || '').toLowerCase();
    expect(ct).toContain('application/json');
    assertV1UnauthorizedBody(await res.json());
  });
});

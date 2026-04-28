const { test, expect } = require('@playwright/test');

test.describe('API health', () => {
  test('GET /api/health.php returns JSON with a status field', async ({ request }) => {
    const res = await request.get('api/health.php');
    expect([200, 503]).toContain(res.status());
    const j = await res.json();
    expect(j).toHaveProperty('status');
    expect(['ok', 'healthy', 'degraded']).toContain(j.status);
    expect(j).toHaveProperty('project');
    expect(j.project).toBe('tp-hr');
  });
});

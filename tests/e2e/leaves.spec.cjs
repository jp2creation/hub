const { test, expect } = require('@playwright/test');
const {
  authHeaders,
  expectOkJson,
  futureDate,
  issueMobileToken,
  skipWhenExternal,
} = require('./support/crm-e2e');

test.describe('HUB leaves E2E', () => {
  skipWhenExternal(test);

  test('mobile API completes leave create update delete flow', async ({ request }) => {
    const fixture = await issueMobileToken(request, 'Playwright Leaves');
    const headers = authHeaders(fixture.token);
    const startDate = futureDate(40);
    const endDate = futureDate(41);

    const bootstrap = await expectOkJson(
      await request.get(`/api/mobile/conges?action=bootstrap&siteId=${fixture.siteId}`, { headers }),
    );
    expect(bootstrap.employees.map((employee) => employee.id)).toContain(fixture.employeeId);

    const created = await expectOkJson(
      await request.post('/api/mobile/conges?action=save_leave', {
        headers,
        data: {
          employeeId: fixture.employeeId,
          siteId: fixture.siteId,
          startDate,
          endDate,
          type: 'conge',
          period: 'full',
          status: 'pending',
          notes: 'E2E create',
        },
      }),
    );

    const leaveId = created.leave.id;
    expect(created.leave.status).toBe('pending');
    expect(created.leave.startDate).toBe(startDate);

    const updated = await expectOkJson(
      await request.post('/api/mobile/conges?action=save_leave', {
        headers,
        data: {
          id: leaveId,
          employeeId: fixture.employeeId,
          siteId: fixture.siteId,
          startDate,
          endDate,
          type: 'conge',
          period: 'full',
          status: 'planned',
          notes: 'E2E update',
        },
      }),
    );

    expect(updated.leave.status).toBe('planned');

    const listing = await expectOkJson(
      await request.get(`/api/mobile/conges?action=bootstrap&siteId=${fixture.siteId}`, { headers }),
    );
    expect(listing.leaves.some((leave) => leave.id === leaveId && leave.status === 'planned')).toBe(true);

    await expectOkJson(
      await request.post('/api/mobile/conges?action=delete_leave', {
        headers,
        data: {
          id: leaveId,
          siteId: fixture.siteId,
        },
      }),
    );

    const afterDelete = await expectOkJson(
      await request.get(`/api/mobile/conges?action=bootstrap&siteId=${fixture.siteId}`, { headers }),
    );
    expect(afterDelete.leaves.some((leave) => leave.id === leaveId)).toBe(false);
  });
});

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

  test('team planning period controls update the calendar grid', async ({ page, request }) => {
    await page.setViewportSize({ width: 1440, height: 900 });

    const fixture = await issueMobileToken(request, 'Playwright Leaves UI');
    const sessionResponse = await request.post('/api/mobile/web-session', {
      headers: authHeaders(fixture.token),
      data: {
        redirectPath: '/conges',
        siteId: fixture.siteId,
        embed: false,
      },
    });

    expect(sessionResponse.status()).toBe(200);

    const session = await sessionResponse.json();
    await page.goto(session.url);
    await expect(page.locator('#crm-leaves-module')).toBeVisible();

    await page.locator('#crm-leaves-module').getByRole('button', { name: 'Mon équipe' }).click();
    await expect(page.locator('.leave-team-timeline')).toBeVisible();

    await page.locator('.leave-period-mode').getByRole('button', { name: 'Jour' }).click();
    await expect(page.locator('.leave-team-timeline')).toHaveClass(/is-day/);
    await expect(page.locator('col.leave-team-day-col')).toHaveCount(1);

    await page.locator('.leave-period-mode').getByRole('button', { name: 'Semaine' }).click();
    await expect(page.locator('.leave-team-timeline')).toHaveClass(/is-week/);
    await expect(page.locator('col.leave-team-day-col')).toHaveCount(7);

    const weekdayLabels = await page
      .locator('.leave-team-timeline thead tr:nth-child(2) th span')
      .allTextContents();
    expect(weekdayLabels.every((label) => /^[LMMJVSD]$/.test(label.trim()))).toBe(true);
    await expect(page.locator('.leave-team-timeline .is-alternate').first()).toBeVisible();

    await page.locator('.leave-period-mode').getByRole('button', { name: 'Mois' }).click();
    await expect(page.locator('.leave-team-timeline')).toHaveClass(/is-month/);
    expect(await page.locator('col.leave-team-day-col').count()).toBeGreaterThan(7);
  });

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

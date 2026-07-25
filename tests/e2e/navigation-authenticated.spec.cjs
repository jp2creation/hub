const { test, expect } = require('@playwright/test');
const { authHeaders, issueMobileToken, skipWhenExternal } = require('./support/crm-e2e');

test.describe('HUB authenticated navigation E2E', () => {
  skipWhenExternal(test);

  test('authenticated user can open critical module pages', async ({ page, request }) => {
    const fixture = await issueMobileToken(request, 'Playwright Navigation');
    const headers = authHeaders(fixture.token);
    const sessionResponse = await request.post('/api/mobile/web-session', {
      headers,
      data: {
        redirectPath: '/',
        siteId: fixture.siteId,
        embed: false,
      },
    });

    expect(sessionResponse.status()).toBe(200);

    const session = await sessionResponse.json();
    expect(session).toMatchObject({ ok: true });

    await page.goto(session.url);
    expect(new URL(page.url()).pathname).toMatch(/^\/(?:dashboard\/crm)?$/);
    await expect(page.locator('body')).toContainText(/Tableau de bord/i);

    const pages = [
      ['/reservations', /R[ée]servations v[ée]hicules/i, '#crm-reservations-module'],
      ['/locations-materiel', /Location mat[ée]riel/i, '#crm-equipment-rentals-module'],
      ['/conges', /Cong[ée]s/i, '#crm-leaves-module'],
      ['/pilotage-commercial', /Pilotage commercial/i, '#crm-sales-module'],
      ['/equipes', /[ÉE]quipe/i, '#crm-teams-module'],
      ['/controle-caisse', /Contr[oô]le caisse/i, '#crm-cash-control-module'],
      ['/demandes-acompte', /Acompte|Demande/i, '#crm-deposit-requests-module'],
      ['/remise-cheques', /Remise|ch[eè]ques/i, '#crm-check-remittance-module'],
      ['/documents/promo', /Documents/i, '#crm-documents-module'],
    ];

    for (const [route, heading, hostSelector] of pages) {
      await page.goto(route);
      if (hostSelector) {
        await expect(page.locator(hostSelector)).toBeVisible();
      }
      await expect(page.locator('body')).toContainText(heading);
      await expect(page.locator('body')).not.toContainText(/404 - Page non trouv[ée]e/i);
    }

    await page.goto('/conges');
    await expect(page.locator('body')).toContainText(/Cong[ée]s/i);
    await page.getByRole('link', { name: /Pilotage commercial/i }).click();
    await expect(page).toHaveURL(/\/pilotage-commercial/);
    await expect(page.locator('#crm-sales-module')).toBeVisible();
    await expect(page.locator('body')).toContainText(/Pilotage commercial/i);
    await expect(page.locator('body')).not.toContainText(/404 - Page non trouv[ée]e/i);
  });
});

const { test, expect } = require('@playwright/test');

test.describe('HUB smoke critical paths', () => {
  test('login page renders the HUB identity and sign-in form', async ({ page }) => {
    await page.goto('/login');

    await expect(page).toHaveTitle(/Martin Sols/i);
    await expect(page.getByRole('button', { name: /se connecter/i })).toBeVisible();
    await expect(page.locator('link[rel="manifest"]')).toHaveCount(1);
  });

  test('critical HUB modules are protected before authentication', async ({ page }) => {
    const protectedRoutes = [
      '/reservations',
      '/locations-materiel',
      '/controle-caisse',
      '/demandes-acompte',
      '/remise-cheques',
      '/documents/promo',
      '/tapis-romus',
    ];

    for (const route of protectedRoutes) {
      await page.goto(route);
      await expect(page).toHaveURL(/\/login$/);
    }
  });

  test('public health checks answer for critical APIs', async ({ request }) => {
    const healthEndpoints = [
      '/api/dashboard?action=health',
      '/api/administration?action=health',
      '/api/reservations?action=health',
      '/api/equipment-rentals?action=health',
      '/api/controle-caisse?action=health',
      '/api/demandes-acompte?action=health',
      '/api/remise-cheques?action=health',
      '/api/equipes?action=health',
      '/api/tournees-representants?action=health',
      '/api/documents?action=health',
    ];

    for (const endpoint of healthEndpoints) {
      const response = await request.get(endpoint);
      expect(response.ok(), endpoint).toBeTruthy();
      expect(response.headers()['content-type'], endpoint).toContain('application/json');
      expect(await response.json(), endpoint).toMatchObject({ ok: true });
    }
  });

  test('business APIs require an authenticated HUB user for bootstrap flows', async ({ request }) => {
    const protectedApis = [
      '/api/reservations?action=bootstrap',
      '/api/equipment-rentals?action=bootstrap',
      '/api/controle-caisse?action=bootstrap',
      '/api/demandes-acompte?action=bootstrap',
      '/api/conges?action=bootstrap',
      '/api/pages?action=bootstrap',
    ];

    for (const endpoint of protectedApis) {
      const response = await request.get(endpoint);
      expect(response.status(), endpoint).toBe(401);
      expect(await response.json(), endpoint).toMatchObject({ ok: false });
    }
  });
});

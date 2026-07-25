const { test, expect } = require('@playwright/test');
const {
  authHeaders,
  expectOkJson,
  futureDate,
  issueMobileToken,
  skipWhenExternal,
} = require('./support/crm-e2e');

test.describe('HUB equipment rentals E2E', () => {
  skipWhenExternal(test);

  test('mobile API completes equipment rental create update delete flow', async ({ request }) => {
    const fixture = await issueMobileToken(request, 'Playwright Equipment');
    const headers = authHeaders(fixture.token);
    const date = futureDate(35);
    const title = `E2E Location ${Date.now()}`;

    const bootstrap = await expectOkJson(
      await request.get('/api/mobile/equipment-rentals/bootstrap', { headers }),
    );
    expect(bootstrap.equipmentItems.map((item) => item.id)).toContain(fixture.equipmentItemId);

    const created = await expectOkJson(
      await request.post('/api/mobile/equipment-rentals?action=create_rental', {
        headers,
        data: {
          equipmentItemId: fixture.equipmentItemId,
          date,
          slot: 'morning',
          title,
          notes: 'E2E create',
        },
      }),
    );

    const rentalId = created.equipmentRental.id;
    expect(created.equipmentRental.title).toBe(title);
    expect(created.equipmentRental.slot).toBe('morning');

    const updatedTitle = `${title} modifiee`;
    const updated = await expectOkJson(
      await request.post('/api/mobile/equipment-rentals?action=update_rental', {
        headers,
        data: {
          id: rentalId,
          equipmentItemId: fixture.equipmentItemId,
          date,
          slot: 'afternoon',
          title: updatedTitle,
          notes: 'E2E update',
        },
      }),
    );

    expect(updated.equipmentRental.title).toBe(updatedTitle);
    expect(updated.equipmentRental.slot).toBe('afternoon');

    const listing = await expectOkJson(
      await request.get(`/api/mobile/equipment-rentals?from=${date}&to=${date}`, { headers }),
    );
    expect(
      listing.equipmentRentals.some(
        (rental) => rental.id === rentalId && rental.title === updatedTitle,
      ),
    ).toBe(true);

    await expectOkJson(
      await request.post('/api/mobile/equipment-rentals?action=delete_rental', {
        headers,
        data: { id: rentalId },
      }),
    );

    const afterDelete = await expectOkJson(
      await request.get(`/api/mobile/equipment-rentals?from=${date}&to=${date}`, { headers }),
    );
    expect(afterDelete.equipmentRentals.some((rental) => rental.id === rentalId)).toBe(false);
  });
});

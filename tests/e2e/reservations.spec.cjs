const { test, expect } = require('@playwright/test');
const {
  authHeaders,
  expectOkJson,
  futureDate,
  issueMobileToken,
  skipWhenExternal,
} = require('./support/crm-e2e');

test.describe('HUB reservations E2E', () => {
  skipWhenExternal(test);

  test('mobile API completes reservation create update delete flow', async ({ request }) => {
    const fixture = await issueMobileToken(request, 'Playwright Reservations');
    const headers = authHeaders(fixture.token);
    const date = futureDate(30);
    const title = `E2E Reservation ${Date.now()}`;

    const bootstrap = await expectOkJson(
      await request.get('/api/mobile/reservations/bootstrap', { headers }),
    );
    expect(bootstrap.vehicles.map((vehicle) => vehicle.id)).toContain(fixture.vehicleId);

    const created = await expectOkJson(
      await request.post('/api/mobile/reservations?action=create_reservation', {
        headers,
        data: {
          vehicleId: fixture.vehicleId,
          startAt: `${date}T08:00`,
          endAt: `${date}T09:00`,
          title,
          notes: 'E2E create',
        },
      }),
    );

    const reservationId = created.reservation.id;
    expect(created.reservation.title).toBe(title);
    expect(created.reservation.startAt).toBe(`${date}T08:00`);

    const updatedTitle = `${title} modifiee`;
    const updated = await expectOkJson(
      await request.post('/api/mobile/reservations?action=update_reservation', {
        headers,
        data: {
          id: reservationId,
          vehicleId: fixture.vehicleId,
          startAt: `${date}T09:00`,
          endAt: `${date}T10:00`,
          title: updatedTitle,
          notes: 'E2E update',
        },
      }),
    );

    expect(updated.reservation.title).toBe(updatedTitle);
    expect(updated.reservation.startAt).toBe(`${date}T09:00`);

    const listing = await expectOkJson(
      await request.get(`/api/mobile/reservations?from=${date}&to=${date}`, { headers }),
    );
    expect(
      listing.reservations.some(
        (reservation) => reservation.id === reservationId && reservation.title === updatedTitle,
      ),
    ).toBe(true);

    await expectOkJson(
      await request.post('/api/mobile/reservations?action=delete_reservation', {
        headers,
        data: { id: reservationId },
      }),
    );

    const afterDelete = await expectOkJson(
      await request.get(`/api/mobile/reservations?from=${date}&to=${date}`, { headers }),
    );
    expect(afterDelete.reservations.some((reservation) => reservation.id === reservationId)).toBe(false);
  });
});

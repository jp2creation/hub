const { expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const projectRoot = path.resolve(__dirname, '../../..');
let cachedFixture;

function isLocalE2eRun() {
  const baseUrl = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';

  return process.env.CRM_E2E_MUTATIONS === '1'
    || baseUrl.includes('127.0.0.1')
    || baseUrl.includes('localhost');
}

function skipWhenExternal(test) {
  if (!isLocalE2eRun()) {
    test.skip(true, 'Mutating HUB E2E tests are disabled for external PLAYWRIGHT_BASE_URL.');
  }
}

function seedCrmE2e() {
  if (!cachedFixture) {
    const output = execFileSync('php', ['tests/e2e/support/seed-crm-e2e.php'], {
      cwd: projectRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    cachedFixture = JSON.parse(output);
  }

  return cachedFixture;
}

async function issueMobileToken(request, deviceName = 'Playwright E2E') {
  const fixture = seedCrmE2e();
  const response = await request.post('/api/mobile/token', {
    data: {
      email: fixture.email,
      password: fixture.password,
      device_name: deviceName,
    },
  });

  expect(response.status()).toBe(200);

  const body = await response.json();
  expect(body).toMatchObject({ ok: true, tokenType: 'Bearer' });

  return {
    ...fixture,
    token: body.token,
    refreshToken: body.refreshToken,
    scopes: body.scopes,
  };
}

function authHeaders(token) {
  return {
    Authorization: `Bearer ${token}`,
  };
}

async function expectOkJson(response) {
  expect(response.ok(), `${response.url()} -> ${response.status()}`).toBeTruthy();

  const body = await response.json();
  expect(body.ok).toBe(true);

  return body;
}

function futureDate(offsetDays) {
  const date = new Date();
  date.setDate(date.getDate() + offsetDays);

  return date.toISOString().slice(0, 10);
}

module.exports = {
  authHeaders,
  expectOkJson,
  futureDate,
  issueMobileToken,
  seedCrmE2e,
  skipWhenExternal,
};

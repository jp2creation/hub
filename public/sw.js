const CACHE_VERSION = 'martin-sols-hub-v202607251155';
const STATIC_CACHE = `${CACHE_VERSION}:static`;
const OFFLINE_URL = '/offline.html';

const PRECACHE_URLS = [
  OFFLINE_URL,
  '/manifest.json',
  '/favicon.png',
  '/assets/logo/martin-sols-logo.png',
  '/modules/crm-core/brand-morph-loader.css',
  '/modules/crm-core/brand-morph-loader.js',
  '/modules/crm-core/brand-morph-loader-app.js',
  '/assets/pwa/apple-touch-icon.png',
  '/assets/pwa/icon-192.png',
  '/assets/pwa/icon-512.png',
  '/assets/pwa/maskable-192.png',
  '/assets/pwa/maskable-512.png'
];

const PRIVATE_PREFIXES = [
  '/api/',
  '/admin',
  '/filament',
  '/livewire',
  '/mobile/session',
  '/sanctum/'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => Promise.all(PRECACHE_URLS.map((url) => cache.add(url).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys
        .filter((key) => (
          key.startsWith('martin-sols-hub-')
          || key.startsWith('martin-sols-crm-')
        ) && key !== STATIC_CACHE)
        .map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
      .then(() => notifyClients('CRM_SW_ACTIVATED'))
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }

  if (event.data && event.data.type === 'GET_VERSION') {
    notifyClients('CRM_SW_VERSION');
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  if (url.origin !== self.location.origin || isPrivateRequest(url)) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(networkFirstNavigation(request));
    return;
  }

  if (isFreshAsset(request, url)) {
    event.respondWith(networkFirstAsset(request));
    return;
  }

  if (isStaticAsset(request, url)) {
    event.respondWith(staleWhileRevalidate(request));
  }
});

self.addEventListener('push', (event) => {
  const payload = parsePushPayload(event);

  event.waitUntil(
    self.registration.showNotification(payload.title || 'JP2 Hub', {
      body: payload.body || '',
      icon: payload.icon || '/assets/pwa/icon-192.png',
      badge: payload.badge || '/assets/pwa/maskable-192.png',
      tag: payload.tag || undefined,
      renotify: Boolean(payload.tag),
      data: {
        url: payload.url || '/'
      }
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const notificationData = event.notification.data || {};

  event.waitUntil(openNotificationUrl(notificationData.url || '/'));
});

function isPrivateRequest(url) {
  return PRIVATE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

function isStaticAsset(request, url) {
  return url.pathname.startsWith('/assets/')
    || url.pathname === '/favicon.png'
    || url.pathname === '/manifest.json'
    || ['style', 'script', 'image', 'font'].includes(request.destination);
}

function isFreshAsset(request, url) {
  return request.destination === 'script'
    || request.destination === 'style'
    || /\.(?:js|css)$/i.test(url.pathname);
}

async function networkFirstNavigation(request) {
  try {
    return await fetch(request, { cache: 'no-store' });
  } catch (error) {
    const cache = await caches.open(STATIC_CACHE);

    return await cache.match(OFFLINE_URL) || Response.error();
  }
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(STATIC_CACHE);
  const cached = await cache.match(request);
  const fetched = fetch(request)
    .then((response) => {
      if (response && response.ok) {
        cache.put(request, response.clone());
      }

      return response;
    })
    .catch(() => cached);

  return cached || fetched;
}

async function networkFirstAsset(request) {
  const cache = await caches.open(STATIC_CACHE);

  try {
    const response = await fetch(request, { cache: 'no-store' });

    if (response && response.ok) {
      cache.put(request, response.clone());
    }

    return response;
  } catch (error) {
    return await cache.match(request) || Response.error();
  }
}

function parsePushPayload(event) {
  if (!event.data) {
    return {};
  }

  try {
    return event.data.json();
  } catch (error) {
    return {
      body: event.data.text()
    };
  }
}

async function openNotificationUrl(targetUrl) {
  const fallback = new URL('/', self.location.origin);
  let url;

  try {
    url = new URL(targetUrl, self.location.origin);
  } catch (error) {
    url = fallback;
  }

  if (url.origin !== self.location.origin) {
    url = fallback;
  }

  const path = url.pathname + url.search + url.hash;
  const clients = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
  const matchingClient = clients.find((client) => {
    const clientUrl = new URL(client.url);

    return clientUrl.origin === self.location.origin && clientUrl.pathname === url.pathname;
  });

  if (matchingClient) {
    matchingClient.postMessage({ type: 'CRM_NOTIFICATION_CLICKED', url: path });

    return matchingClient.focus();
  }

  return self.clients.openWindow(path);
}

async function notifyClients(type) {
  const clients = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });

  clients.forEach((client) => {
    client.postMessage({ type, version: CACHE_VERSION });
  });
}

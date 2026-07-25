export type CrmHostRoute = {
  className: string;
  id: string;
  label: string;
  paths?: string[];
  prefix?: string;
};

export const hostRoutes: CrmHostRoute[] = [
  {
    className: 'crm-dashboard-module-host',
    id: 'crm-dashboard-module',
    label: 'Tableau de bord',
    paths: ['/', '/dashboard/crm'],
  },
  {
    className: 'crm-reservations-module-host',
    id: 'crm-reservations-module',
    label: 'Réservations véhicules',
    paths: ['/reservations'],
    prefix: '/reservations/',
  },
  {
    className: 'crm-equipment-rentals-module-host',
    id: 'crm-equipment-rentals-module',
    label: 'Location matériel',
    paths: ['/locations-materiel'],
    prefix: '/locations-materiel/',
  },
  {
    className: 'crm-administration-module-host',
    id: 'crm-administration-module',
    label: 'Administration',
    paths: ['/administration'],
    prefix: '/administration/',
  },
  {
    className: 'crm-leaves-module-host',
    id: 'crm-leaves-module',
    label: 'Congés',
    paths: ['/conges'],
  },
  {
    className: 'crm-sales-module-host',
    id: 'crm-sales-module',
    label: 'Pilotage commercial',
    paths: ['/pilotage-commercial'],
  },
  {
    className: 'crm-sales-tours-module-host',
    id: 'crm-sales-tours-module',
    label: 'Rapport de visite',
    paths: ['/rapport-visite', '/tournees-representants'],
  },
  {
    className: 'crm-documents-module-host',
    id: 'crm-documents-module',
    label: 'Documents',
    paths: ['/documents'],
    prefix: '/documents/',
  },
  {
    className: 'crm-teams-module-host',
    id: 'crm-teams-module',
    label: 'Équipe',
    paths: ['/equipes'],
  },
  {
    className: 'crm-cash-control-module-host',
    id: 'crm-cash-control-module',
    label: 'Contrôle caisse',
    paths: ['/controle-caisse'],
  },
  {
    className: 'crm-deposit-requests-module-host',
    id: 'crm-deposit-requests-module',
    label: "Demande d'acompte",
    paths: ['/demandes-acompte'],
  },
  {
    className: 'crm-check-remittance-module-host',
    id: 'crm-check-remittance-module',
    label: 'Remise de chèques',
    paths: ['/remise-cheques'],
    prefix: '/remise-cheques/',
  },
  {
    className: 'crm-pages-module-host',
    id: 'crm-pages-root',
    label: 'Pages HUB',
    paths: ['/pages-crm'],
    prefix: '/pages-crm/',
  },
  {
    className: 'crm-tapis-romus-module-host',
    id: 'crm-tapis-romus-module',
    label: 'Tapis ROMUS',
    paths: ['/tapis-romus'],
    prefix: '/tapis-romus/',
  },
];

const refreshStoragePrefix = 'crm:route-host-hard-refresh:v2:';
const missingHostRefreshStoragePrefix = 'crm:route-host-missing-refresh:v1:';
const maxRefreshAttempts = 2;
const missingHostRefreshDelayMs = 900;
let rootObserver: MutationObserver | null = null;
let rootObserverTimer: number | null = null;
let missingHostRefreshTimer: number | null = null;
let missingHostRefreshPath = '';

export function normalizedCrmPath(value = window.location.pathname): string {
  return value.replace(/\/+$/, '') || '/';
}

export function crmHostRouteForPath(pathname: string): CrmHostRoute | null {
  const path = normalizedCrmPath(pathname);
  return (
    hostRoutes.find((route) => {
      return (route.paths || []).includes(path) || (route.prefix ? path.startsWith(route.prefix) : false);
    }) || null
  );
}

function routeForCurrentPath(): CrmHostRoute | null {
  return crmHostRouteForPath(window.location.pathname);
}

function removeInactiveModuleHosts(activeRoute: CrmHostRoute | null): void {
  hostRoutes.forEach((route) => {
    if (route.id === activeRoute?.id) {
      return;
    }

    const host = document.getElementById(route.id);

    if (!host) {
      return;
    }

    host.dispatchEvent(new CustomEvent('crm:module-host-remove'));
    host.remove();
  });
}

function pageLooksLikeLegacyTemplate404(): boolean {
  const root = document.getElementById('root');
  const text = root?.textContent || '';

  return /\b404\b/.test(text) && /page non trouv|not found/i.test(text);
}

async function clearCrmRuntimeCaches(): Promise<void> {
  if (typeof caches !== 'undefined') {
    const keys = await caches.keys();

    await Promise.all(keys.filter((key) => key.startsWith('martin-sols-hub-')).map((key) => caches.delete(key)));
  }

  if (navigator.serviceWorker) {
    const registrations = await navigator.serviceWorker.getRegistrations();

    await Promise.all(registrations.map((registration) => registration.update().catch(() => undefined)));
    navigator.serviceWorker.controller?.postMessage({ type: 'SKIP_WAITING' });
  }
}

function refreshedRouteUrl(): string {
  const url = new URL(window.location.href);

  url.searchParams.set('_crm_refresh', String(Date.now()));

  return `${url.pathname}${url.search}${url.hash}`;
}

function clearMissingHostRefreshTimer(): void {
  if (!missingHostRefreshTimer) {
    return;
  }

  window.clearTimeout(missingHostRefreshTimer);
  missingHostRefreshTimer = null;
  missingHostRefreshPath = '';
}

function refreshStaleRouteOnce(): boolean {
  if (!pageLooksLikeLegacyTemplate404()) {
    return false;
  }

  const key = `${refreshStoragePrefix}${normalizedCrmPath()}`;

  try {
    const attempt = Number(sessionStorage.getItem(key) || '0');

    if (attempt >= maxRefreshAttempts) {
      return false;
    }

    sessionStorage.setItem(key, String(attempt + 1));
  } catch {
    return false;
  }

  clearCrmRuntimeCaches()
    .catch(() => undefined)
    .finally(() => {
      window.location.replace(refreshedRouteUrl());
    });

  return true;
}

function refreshMissingHostOnce(): boolean {
  if (pageLooksLikeLegacyTemplate404()) {
    return false;
  }

  const key = `${missingHostRefreshStoragePrefix}${normalizedCrmPath()}`;

  try {
    const attempt = Number(sessionStorage.getItem(key) || '0');

    if (attempt >= maxRefreshAttempts) {
      return false;
    }

    sessionStorage.setItem(key, String(attempt + 1));
  } catch {
    return false;
  }

  clearCrmRuntimeCaches()
    .catch(() => undefined)
    .finally(() => {
      window.location.replace(refreshedRouteUrl());
    });

  return true;
}

function scheduleMissingHostRefresh(route: CrmHostRoute): void {
  const path = normalizedCrmPath();

  if (missingHostRefreshTimer && missingHostRefreshPath === path) {
    return;
  }

  clearMissingHostRefreshTimer();
  missingHostRefreshPath = path;
  missingHostRefreshTimer = window.setTimeout(() => {
    missingHostRefreshTimer = null;

    const currentRoute = routeForCurrentPath();

    if (!currentRoute || currentRoute.id !== route.id || document.getElementById(route.id)) {
      missingHostRefreshPath = '';
      return;
    }

    refreshMissingHostOnce();
  }, missingHostRefreshDelayMs);
}

function ensureHost(): void {
  const route = routeForCurrentPath();

  document.documentElement.classList.toggle('crm-known-module-route', Boolean(route));
  removeInactiveModuleHosts(route);

  if (!route || document.getElementById(route.id)) {
    clearMissingHostRefreshTimer();
    return;
  }

  if (refreshStaleRouteOnce()) {
    clearMissingHostRefreshTimer();
    return;
  }

  scheduleMissingHostRefresh(route);
}

function scheduleEnsureHost(): void {
  window.setTimeout(ensureHost, 0);
  window.setTimeout(ensureHost, 120);
  window.setTimeout(ensureHost, 450);
  window.setTimeout(ensureHost, 1100);
}

function installRootObserver(): void {
  if (rootObserver) {
    return;
  }

  const target = document.getElementById('root') || document.documentElement;

  rootObserver = new MutationObserver(() => {
    if (rootObserverTimer) {
      window.clearTimeout(rootObserverTimer);
    }

    rootObserverTimer = window.setTimeout(() => {
      rootObserverTimer = null;
      ensureHost();
    }, 80);
  });

  rootObserver.observe(target, { childList: true, subtree: true });
}

export function installCrmModuleHostGuard(): void {
  scheduleEnsureHost();
  installRootObserver();

  document.addEventListener('DOMContentLoaded', scheduleEnsureHost, { once: true });
  document.addEventListener('DOMContentLoaded', installRootObserver, { once: true });
  window.addEventListener('load', scheduleEnsureHost);
  window.addEventListener('popstate', scheduleEnsureHost);
  window.addEventListener('crm:navigation', scheduleEnsureHost);
  window.addEventListener('crm:route-changed', scheduleEnsureHost);
}

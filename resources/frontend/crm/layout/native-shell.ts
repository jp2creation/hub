import { isLegacyTemplateRoute } from '../legacy/template-compat';
import { crmHostRouteForPath, type CrmHostRoute } from '../modules/hosts';
import type { CrmFallbackNavigation, CrmMenuGroup, CrmMenuItem, CrmModule } from '../types/global';

type CrmProfile = {
  displayName?: string;
  email?: string;
  firstName?: string;
  lastName?: string;
  name?: string;
  navigation?: CrmFallbackNavigation;
  photoUrl?: string;
  role?: string;
};

const nativeShellSelector = '[data-crm-native-shell]';
const profileStorageKey = 'martin-sols-hub-profile';
const sidebarStorageKey = 'martin-sols-hub-sidebar-collapsed';
let installed = false;
let profileLoaded = false;
let currentProfile: CrmProfile | undefined = readStoredProfile();

function rootElement(): HTMLElement | null {
  return document.getElementById('root');
}

function normalizedPath(value = window.location.pathname): string {
  return value.replace(/\/+$/, '') || '/';
}

function esc(value: unknown): string {
  return String(value ?? '').replace(/[&<>"']/g, (char) => {
    const entities: Record<string, string> = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    };

    return entities[char] || char;
  });
}

function logoUrl(): string {
  return window.MartinSolsCrmAssets?.logoUrl || '/assets/logo/martin-sols-logo.png';
}

function logoMarkUrl(): string {
  return '/assets/logo/logomark.png';
}

function navigation(): CrmFallbackNavigation {
  return (
    window.CRM_NAV_FALLBACK || {
      menuGroups: [],
      menuItems: [],
      modules: [],
    }
  );
}

function routeByItemKey(): Record<string, string> {
  const routes: Record<string, string> = {};

  navigation()
    .modules.filter((module: CrmModule) => module && module.active !== false)
    .forEach((module: CrmModule) => {
      routes[`module:${module.slug}`] = module.routePath || `/${module.slug}`;
    });

  return routes;
}

function isActivePath(path: string): boolean {
  if (/^https?:\/\//.test(path)) {
    return false;
  }

  const current = normalizedPath();
  const target = normalizedPath(path);

  if ((target === '/dashboard' || target === '/dashboard/crm') && current === '/') {
    return true;
  }

  return current === target || current.startsWith(`${target}/`);
}

function isAccountSettingsPath(): boolean {
  return normalizedPath() === '/pages/account-settings';
}

function isDesktopSidebarMode(): boolean {
  return window.matchMedia('(min-width: 1024px)').matches;
}

function iconSvg(path: string): string {
  return `<svg viewBox="0 0 24 24" aria-hidden="true">${path}</svg>`;
}

function iconForKey(iconKey?: string): string {
  const icons: Record<string, string> = {
    article: iconSvg('<path d="M7 3h7l4 4v14H7z"></path><path d="M14 3v5h5"></path><path d="M10 12h6M10 16h5"></path>'),
    banknote: iconSvg(
      '<rect x="3" y="6" width="18" height="12" rx="2"></rect><circle cx="12" cy="12" r="2.5"></circle><path d="M6 9h1M17 15h1"></path>',
    ),
    bus: iconSvg(
      '<rect x="5" y="3" width="14" height="14" rx="3"></rect><path d="M8 7h8M8 11h8M8 17v2M16 17v2"></path><circle cx="8.5" cy="14" r=".5"></circle><circle cx="15.5" cy="14" r=".5"></circle>',
    ),
    calendar: iconSvg('<rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 3v4M16 3v4M4 10h16"></path>'),
    category: iconSvg(
      '<rect x="4" y="4" width="6" height="6" rx="1.5"></rect><rect x="14" y="4" width="6" height="6" rx="1.5"></rect><rect x="4" y="14" width="6" height="6" rx="1.5"></rect><rect x="14" y="14" width="6" height="6" rx="1.5"></rect>',
    ),
    checklist: iconSvg(
      '<path d="m8 7 1.6 1.6L13 5"></path><path d="M16 7h4"></path><path d="m8 15 1.6 1.6L13 13"></path><path d="M16 15h4"></path><path d="M4 7h.01M4 15h.01"></path>',
    ),
    creditCard: iconSvg('<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M3 10h18M7 15h4"></path>'),
    dashboard: iconSvg(
      '<rect x="4" y="4" width="6" height="6" rx="1.5"></rect><rect x="14" y="4" width="6" height="6" rx="1.5"></rect><rect x="4" y="14" width="6" height="6" rx="1.5"></rect><rect x="14" y="14" width="6" height="6" rx="1.5"></rect>',
    ),
    assistant: iconSvg(
      '<path d="M12 8V4"></path><rect x="5" y="8" width="14" height="11" rx="3"></rect><path d="M8.5 12h.01M15.5 12h.01M9 16h6"></path>',
    ),
    fileText: iconSvg(
      '<path d="M7 3h7l4 4v14H7z"></path><path d="M14 3v5h5"></path><path d="M10 13h6M10 17h4"></path>',
    ),
    logout: iconSvg(
      '<path d="M10 17l5-5-5-5"></path><path d="M15 12H3"></path><path d="M21 19V5a2 2 0 0 0-2-2h-6"></path><path d="M13 21h6a2 2 0 0 0 2-2"></path>',
    ),
    package: iconSvg('<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"></path><path d="m4 7.5 8 4.5 8-4.5M12 12v9"></path>'),
    profile: iconSvg(
      '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"></path><path d="M4 21a8 8 0 0 1 16 0"></path>',
    ),
    ruler: iconSvg('<path d="M4 17 17 4l3 3L7 20z"></path><path d="m14 7 3 3M11 10l2 2M8 13l3 3"></path>'),
    settings: iconSvg(
      '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"></path><path d="M12 2v3M12 19v3M4.9 4.9 7 7M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"></path>',
    ),
    table: iconSvg('<path d="M4 6h16M4 12h16M4 18h16"></path><path d="M8 6v12M16 6v12"></path>'),
    truck: iconSvg(
      '<path d="M3 7h11v8H3z"></path><path d="M14 10h3l3 3v2h-6z"></path><circle cx="7" cy="18" r="2"></circle><circle cx="17" cy="18" r="2"></circle>',
    ),
    users: iconSvg(
      '<path d="M16 11a4 4 0 1 0-8 0"></path><path d="M4 21a8 8 0 0 1 16 0"></path><path d="M18 8a3 3 0 0 1 3 3M6 8a3 3 0 0 0-3 3"></path>',
    ),
  };

  return icons[iconKey || ''] || iconSvg('<circle cx="12" cy="12" r="8"></circle><path d="M12 8v4l3 2"></path>');
}

const failedProfileImageSources = new Set<string>();

function normalizePhotoUrl(src?: string): string {
  const value = String(src || '').trim();

  if (value.startsWith('/storage/assets/uploads/')) {
    return `/uploads/${value.slice('/storage/'.length)}`;
  }

  return value;
}

function readStoredProfile(): CrmProfile | undefined {
  try {
    const raw = window.sessionStorage?.getItem(profileStorageKey);
    const profile = raw ? (JSON.parse(raw) as CrmProfile) : undefined;

    if (!profile || typeof profile !== 'object') {
      return undefined;
    }

    return {
      displayName: profile.displayName,
      name: profile.name,
      photoUrl: normalizePhotoUrl(profile.photoUrl),
      role: profile.role,
    };
  } catch {
    return undefined;
  }
}

function readStoredSidebarCollapsed(): boolean {
  try {
    return window.localStorage?.getItem(sidebarStorageKey) === '1';
  } catch {
    return false;
  }
}

function storeProfile(profile: CrmProfile): void {
  try {
    window.sessionStorage?.setItem(
      profileStorageKey,
      JSON.stringify({
        displayName: profile.displayName,
        name: profile.name,
        photoUrl: normalizePhotoUrl(profile.photoUrl),
        role: profile.role,
      }),
    );
  } catch {
    // The profile cache is only used to avoid a visual flash in the shell.
  }
}

function storeSidebarCollapsed(collapsed: boolean): void {
  try {
    window.localStorage?.setItem(sidebarStorageKey, collapsed ? '1' : '0');
  } catch {
    // The sidebar state is a visual preference only.
  }
}

function iconFor(item: CrmMenuItem): string {
  return iconForKey(item.iconKey);
}

function chevronIcon(): string {
  return iconSvg('<path d="m6 9 6 6 6-6"></path>');
}

function moduleFor(item: CrmMenuItem): CrmModule | undefined {
  const slug = item.itemKey.startsWith('module:') ? item.itemKey.slice(7) : '';

  return navigation().modules.find((module: CrmModule) => module.slug === slug);
}

function badgeHtml(item: CrmMenuItem): string {
  const module = moduleFor(item);

  if (!module?.showMenuBadge || !module.menuBadge) {
    return '';
  }

  return `<span class="crm-native-nav-badge">${esc(module.menuBadge)}</span>`;
}

function isSubmenuGroup(group: CrmMenuGroup, items: CrmMenuItem[]): boolean {
  const sectionGroups = new Set(['home', 'apps', 'accounting', 'internal']);

  return items.length > 1 && !sectionGroups.has(group.menuKey);
}

function groupIconKey(group: CrmMenuGroup): string {
  const icons: Record<string, string> = {
    commercial: 'dashboard',
    documents: 'article',
  };

  return icons[group.menuKey] || 'category';
}

function menuGroupsHtml(): string {
  const routes = routeByItemKey();
  const groups = navigation()
    .menuGroups.filter((group: CrmMenuGroup) => group && group.active !== false)
    .sort((a: CrmMenuGroup, b: CrmMenuGroup) => Number(a.sortOrder || 0) - Number(b.sortOrder || 0));

  return groups
    .map((group: CrmMenuGroup) => {
      const items = navigation()
        .menuItems.filter((item: CrmMenuItem) => item && item.active !== false && item.groupKey === group.menuKey)
        .sort((a: CrmMenuItem, b: CrmMenuItem) => Number(a.sortOrder || 0) - Number(b.sortOrder || 0));

      if (items.length === 0) {
        return '';
      }

      if (isSubmenuGroup(group, items)) {
        return [
          '<section class="crm-native-nav-group crm-native-nav-group-nested">',
          submenuHtml(group, items, routes),
          '</section>',
        ].join('');
      }

      return [
        '<section class="crm-native-nav-group">',
        `<p class="crm-native-nav-title">${esc(group.title || group.menuKey || 'HUB')}</p>`,
        items.map((item: CrmMenuItem) => menuItemHtml(item, routes[item.itemKey] || '#')).join(''),
        '</section>',
      ].join('');
    })
    .join('');
}

function submenuHtml(group: CrmMenuGroup, items: CrmMenuItem[], routes: Record<string, string>): string {
  const active = items.some((item: CrmMenuItem) => isActivePath(routes[item.itemKey] || '#'));

  return [
    `<div class="crm-native-nav-submenu${active ? ' is-open' : ''}" data-crm-native-submenu>`,
    `<button class="crm-native-nav-link crm-native-nav-button${active ? ' is-active' : ''}" type="button" data-crm-native-submenu-toggle aria-expanded="${active ? 'true' : 'false'}">`,
    `<span class="crm-native-nav-icon">${iconForKey(groupIconKey(group))}</span>`,
    `<span class="crm-native-nav-label">${esc(group.title || group.menuKey || 'HUB')}</span>`,
    `<span class="crm-native-nav-chevron">${chevronIcon()}</span>`,
    '</button>',
    '<div class="crm-native-nav-subitems">',
    items.map((item: CrmMenuItem) => menuItemHtml(item, routes[item.itemKey] || '#', true)).join(''),
    '</div>',
    '</div>',
  ].join('');
}

function menuItemHtml(item: CrmMenuItem, path: string, nested = false): string {
  const external = /^https?:\/\//.test(path);
  const active = isActivePath(path);
  const target = external ? ' target="_blank" rel="noopener noreferrer"' : '';

  return [
    `<a class="crm-native-nav-link${nested ? ' crm-native-nav-subitem' : ''}${active ? ' is-active' : ''}" href="${esc(path)}"${target}>`,
    `<span class="crm-native-nav-icon">${iconFor(item)}</span>`,
    `<span class="crm-native-nav-label">${esc(item.label || 'HUB')}</span>`,
    badgeHtml(item),
    '</a>',
  ].join('');
}

function updateNavigationMarkup(): void {
  const nav = document.querySelector<HTMLElement>('.crm-native-nav');

  if (nav) {
    nav.innerHTML = menuGroupsHtml();
  }
}

function hostHtml(route: CrmHostRoute | null): string {
  if (isAccountSettingsPath()) {
    return '';
  }

  if (!route) {
    return [
      '<section class="crm-native-empty">',
      '<h1>Page non disponible</h1>',
      '<p>Cette page n’est pas encore reliée à la nouvelle coque HUB.</p>',
      '<a class="crm-native-button" href="/">Tableau de bord</a>',
      '</section>',
    ].join('');
  }

  return `<div id="${esc(route.id)}" class="${esc(route.className)}" aria-label="${esc(route.label)}"></div>`;
}

function routeNeedsHost(route: CrmHostRoute | null): route is CrmHostRoute {
  return Boolean(route);
}

function profileName(profile?: CrmProfile): string {
  return profile?.displayName || profile?.name || 'Jean-Philippe';
}

function profileRole(profile?: CrmProfile): string {
  const role = profile?.role || 'admin';

  return role === 'admin' ? 'Admin' : role.charAt(0).toUpperCase() + role.slice(1);
}

function profilePhoto(profile?: CrmProfile): string {
  return normalizePhotoUrl(profile?.photoUrl) || '/assets/logo/logomark.png';
}

function isMobileApp(): boolean {
  return (
    window.MartinSolsCrmConfig?.mobile.app === true ||
    Boolean(window.MartinSolsNativeApp) ||
    document.body.classList.contains('crm-mobile-app')
  );
}

function mobileAppSettingsMenuItemHtml(): string {
  if (!isMobileApp()) {
    return '';
  }

  return [
    '<button class="crm-native-user-menu-item" type="button" data-crm-mobile-settings-toggle role="menuitem">',
    `<span class="crm-native-user-menu-icon">${iconForKey('settings')}</span>`,
    '<span><strong>Paramètres de l’app</strong><small>Mises à jour et options mobile</small></span>',
    '</button>',
  ].join('');
}

function setTextContent(node: HTMLElement | null, value: string): void {
  if (node && node.textContent !== value) {
    node.textContent = value;
  }
}

function setImageSource(image: HTMLImageElement | null, src: string, alt: string): void {
  if (!image) {
    return;
  }

  const fallback = '/assets/logo/logomark.png';
  const requestedSrc = src || fallback;
  const nextSrc = failedProfileImageSources.has(requestedSrc) ? fallback : requestedSrc;

  image.onerror = () => {
    if (nextSrc !== fallback) {
      failedProfileImageSources.add(nextSrc);
    }

    image.onerror = null;
    setImageSource(image, fallback, alt);
  };

  if (image.dataset.crmImageSrc !== nextSrc && image.getAttribute('src') !== nextSrc) {
    image.src = nextSrc;
  }

  image.dataset.crmImageSrc = nextSrc;

  if (image.alt !== alt) {
    image.alt = alt;
  }
}

function headerHtml(profile?: CrmProfile): string {
  const shellProfile = profile || currentProfile;
  const sidebarCollapsed = isDesktopSidebarMode() && document.body.classList.contains('crm-native-sidebar-collapsed');

  return [
    '<header class="layout-header crm-native-header">',
    '<div class="layout-container layout-page crm-native-header-inner">',
    `<button class="crm-native-menu-button" type="button" data-crm-native-sidebar-toggle aria-label="${sidebarCollapsed ? 'Déployer le menu' : 'Rabattre le menu'}" aria-pressed="${sidebarCollapsed ? 'true' : 'false'}">`,
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"></path></svg>',
    '</button>',
    '<a class="crm-native-mobile-brand" href="/">',
    `<img class="crm-native-mobile-brand-mark" src="${esc(logoMarkUrl())}" alt="Martin Sols">`,
    `<img class="crm-native-mobile-brand-full" src="${esc(logoUrl())}" alt="Martin Sols">`,
    '</a>',
    '<div class="ms-auto crm-native-header-actions">',
    '<div class="header-actions-divider" aria-hidden="true"></div>',
    '<a class="header-icon-btn crm-native-bell" href="/pages/account-settings" aria-label="Notifications">',
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>',
    '<span></span>',
    '</a>',
    '<div class="crm-native-user-wrap" data-crm-native-user-wrap>',
    '<button class="crm-native-user" type="button" data-crm-native-user-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Menu du compte">',
    '<span class="crm-native-user-text">',
    `<strong data-crm-native-profile-name>${esc(profileName(shellProfile))}</strong>`,
    `<small data-crm-native-profile-role>${esc(profileRole(shellProfile))}</small>`,
    '</span>',
    '<span class="crm-native-avatar">',
    `<img data-crm-native-profile-photo src="${esc(profilePhoto(shellProfile))}" alt="${esc(profileName(shellProfile))}" onerror="this.onerror=null;this.src='/assets/logo/logomark.png'">`,
    '</span>',
    '</button>',
    '<div class="crm-native-user-menu" data-crm-native-user-menu hidden role="menu" aria-label="Menu utilisateur">',
    '<div class="crm-native-user-menu-head">',
    '<span class="crm-native-user-menu-avatar">',
    `<img data-crm-native-profile-photo src="${esc(profilePhoto(shellProfile))}" alt="${esc(profileName(shellProfile))}" onerror="this.onerror=null;this.src='/assets/logo/logomark.png'">`,
    '</span>',
    '<span class="crm-native-user-menu-meta">',
    `<strong data-crm-native-profile-name>${esc(profileName(shellProfile))}</strong>`,
    `<small data-crm-native-profile-role>${esc(profileRole(shellProfile))}</small>`,
    '</span>',
    '</div>',
    '<div class="crm-native-user-menu-separator" aria-hidden="true"></div>',
    '<a class="crm-native-user-menu-item" href="/pages/account-settings" role="menuitem">',
    `<span class="crm-native-user-menu-icon">${iconForKey('profile')}</span>`,
    '<span><strong>Paramètres</strong><small>Compte utilisateur</small></span>',
    '</a>',
    mobileAppSettingsMenuItemHtml(),
    '<button class="crm-native-user-menu-item" type="button" data-hub-assistant-open role="menuitem">',
    `<span class="crm-native-user-menu-icon">${iconForKey('assistant')}</span>`,
    '<span><strong>Assistant HUB</strong><small>Navigation rapide</small></span>',
    '</button>',
    '<button class="crm-native-user-menu-item crm-native-user-menu-danger" type="button" data-crm-native-logout role="menuitem">',
    `<span class="crm-native-user-menu-icon">${iconForKey('logout')}</span>`,
    '<span><strong>Se déconnecter</strong><small>Quitter le HUB</small></span>',
    '</button>',
    '</div>',
    '</div>',
    '</div>',
    '</div>',
    '</header>',
  ].join('');
}

function shellHtml(route: CrmHostRoute | null): string {
  return [
    '<div class="crm-native-shell" data-crm-native-shell>',
    '<aside class="layout-sidebar crm-native-sidebar" aria-label="Menu HUB">',
    '<a class="crm-native-brand" href="/">',
    `<img class="crm-native-brand-full" src="${esc(logoUrl())}" alt="Martin Sols">`,
    `<img class="crm-native-brand-mark" src="${esc(logoMarkUrl())}" alt="Martin Sols">`,
    '</a>',
    `<nav class="crm-native-nav">${menuGroupsHtml()}</nav>`,
    '<button class="crm-native-logout" type="button" data-crm-native-logout>',
    `<span class="crm-native-nav-icon">${iconForKey('logout')}</span>`,
    '<span class="crm-native-nav-label">Se déconnecter</span>',
    '</button>',
    '</aside>',
    '<button class="crm-native-backdrop" type="button" data-crm-native-sidebar-close aria-label="Fermer le menu"></button>',
    '<div class="crm-native-body">',
    headerHtml(currentProfile),
    '<main class="crm-native-main">',
    '<div class="layout-container layout-page crm-native-content">',
    hostHtml(route),
    '</div>',
    '</main>',
    '</div>',
    '</div>',
  ].join('');
}

function updateActiveLinks(): void {
  document.querySelectorAll<HTMLAnchorElement>('.crm-native-nav-link').forEach((link) => {
    link.classList.toggle('is-active', isActivePath(link.getAttribute('href') || '#'));
  });
}

function ensureHost(route: CrmHostRoute | null): void {
  const content = document.querySelector<HTMLElement>('.crm-native-content');

  if (!content) {
    return;
  }

  if (routeNeedsHost(route)) {
    if (!document.getElementById(route.id)) {
      content.innerHTML = hostHtml(route);
    }

    return;
  }

  if (isAccountSettingsPath()) {
    if (content.querySelector('.crm-native-empty')) {
      content.innerHTML = '';
    }

    return;
  }

  if (!content.querySelector('.crm-native-empty')) {
    content.innerHTML = hostHtml(null);
  }
}

function setNativeShellClasses(active: boolean): void {
  document.documentElement.classList.toggle('crm-native-shell-active', active);
  document.body.classList.toggle('crm-native-shell-active', active);
}

function renderNativeShell(): void {
  const root = rootElement();

  if (!root || isLegacyTemplateRoute()) {
    setNativeShellClasses(false);
    return;
  }

  const route = crmHostRouteForPath(window.location.pathname);

  if (!root.querySelector(nativeShellSelector)) {
    root.innerHTML = shellHtml(route);
  } else {
    updateNavigationMarkup();
    ensureHost(route);
    updateActiveLinks();
  }

  setNativeShellClasses(true);
  document.documentElement.classList.add('crm-known-module-route');
}

async function loadProfile(): Promise<void> {
  if (profileLoaded || isLegacyTemplateRoute()) {
    return;
  }

  profileLoaded = true;

  try {
    const response = await fetch('/api/administration?action=profile', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const payload = await response.json();

    if (!response.ok || payload.ok === false || !payload.profile) {
      return;
    }

    const profile = payload.profile as CrmProfile;
    currentProfile = profile;
    storeProfile(profile);

    if (profile.navigation) {
      window.CRM_NAV_FALLBACK = profile.navigation;
      updateNavigationMarkup();
      updateActiveLinks();
    }

    hydrateProfile(profile);
  } catch {
    // The profile is decorative in the shell; module access still decides auth.
  }
}

function hydrateProfile(profile: CrmProfile): void {
  currentProfile = profile;
  storeProfile(profile);

  document.querySelectorAll<HTMLImageElement>('[data-crm-native-profile-photo]').forEach((photo) => {
    setImageSource(photo, profilePhoto(profile), profileName(profile));
  });

  document.querySelectorAll<HTMLElement>('[data-crm-native-profile-name]').forEach((nameNode) => {
    setTextContent(nameNode, profileName(profile));
  });

  document.querySelectorAll<HTMLElement>('[data-crm-native-profile-role]').forEach((roleNode) => {
    setTextContent(roleNode, profileRole(profile));
  });
}

function closeSidebar(): void {
  document.body.classList.remove('crm-native-sidebar-open');
}

function syncSidebarToggleButtons(): void {
  const desktop = isDesktopSidebarMode();
  const collapsed = desktop && document.body.classList.contains('crm-native-sidebar-collapsed');

  document.querySelectorAll<HTMLElement>('[data-crm-native-sidebar-toggle]').forEach((button) => {
    button.setAttribute('aria-label', desktop ? (collapsed ? 'Déployer le menu' : 'Rabattre le menu') : 'Ouvrir le menu');
    button.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
  });
}

function setSidebarCollapsed(collapsed: boolean, persist = true): void {
  document.body.classList.toggle('crm-native-sidebar-collapsed', collapsed);
  document.documentElement.classList.toggle('crm-native-sidebar-collapsed', collapsed);

  if (persist) {
    storeSidebarCollapsed(collapsed);
  }

  syncSidebarToggleButtons();
}

function toggleSidebar(): void {
  if (isDesktopSidebarMode()) {
    document.body.classList.remove('crm-native-sidebar-open');
    setSidebarCollapsed(!document.body.classList.contains('crm-native-sidebar-collapsed'));

    return;
  }

  document.body.classList.toggle('crm-native-sidebar-open');
}

function setUserMenuOpen(open: boolean): void {
  document.querySelectorAll<HTMLElement>('[data-crm-native-user-menu]').forEach((menu) => {
    menu.hidden = !open;
    menu.classList.toggle('is-open', open);
  });

  document.querySelectorAll<HTMLElement>('[data-crm-native-user-menu-toggle]').forEach((button) => {
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
}

function toggleUserMenu(): void {
  const menu = document.querySelector<HTMLElement>('[data-crm-native-user-menu]');

  setUserMenuOpen(!menu || Boolean(menu.hidden));
}

function installShellApi(): void {
  window.MartinSolsCrmShell = {
    closeUserMenu: () => setUserMenuOpen(false),
    openUserMenu: () => setUserMenuOpen(true),
    toggleUserMenu,
  };
}

function installEvents(): void {
  if (installed) {
    return;
  }

  installed = true;

  document.addEventListener(
    'click',
    (event) => {
      const target = event.target instanceof Element ? event.target : null;

      if (target?.closest('[data-crm-native-sidebar-toggle]')) {
        event.preventDefault();
        toggleSidebar();
        return;
      }

      if (target?.closest('[data-crm-native-sidebar-close]')) {
        event.preventDefault();
        closeSidebar();
        return;
      }

      const userMenuToggle = target?.closest<HTMLElement>('[data-crm-native-user-menu-toggle]');
      if (userMenuToggle) {
        event.preventDefault();
        toggleUserMenu();
        return;
      }

      if (target?.closest('[data-crm-mobile-settings-toggle]')) {
        setUserMenuOpen(false);
        return;
      }

      if (target?.closest('[data-hub-assistant-open]')) {
        event.preventDefault();
        setUserMenuOpen(false);
        window.MartinSolsHubAssistant?.open?.();
        return;
      }

      if (target?.closest('[data-crm-native-user-menu] a')) {
        setUserMenuOpen(false);
        return;
      }

      if (target && !target.closest('[data-crm-native-user-wrap]')) {
        setUserMenuOpen(false);
      }

      const submenuButton = target?.closest<HTMLElement>('[data-crm-native-submenu-toggle]');
      if (submenuButton) {
        event.preventDefault();
        const submenu = submenuButton.closest<HTMLElement>('[data-crm-native-submenu]');
        const open = !submenu?.classList.contains('is-open');

        submenu?.classList.toggle('is-open', open);
        submenuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        return;
      }

      if (target?.closest('[data-crm-native-logout]')) {
        event.preventDefault();
        setUserMenuOpen(false);
        try {
          window.sessionStorage?.removeItem(profileStorageKey);
        } catch {
          // Nothing to clean when storage is unavailable.
        }
        window.MartinSolsCrmLogout?.();
      }
    },
    true,
  );

  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      setUserMenuOpen(false);
    }
  });

  window.addEventListener('resize', () => {
    if (isDesktopSidebarMode()) {
      closeSidebar();
      setSidebarCollapsed(readStoredSidebarCollapsed(), false);
    } else {
      syncSidebarToggleButtons();
    }
  });

  window.addEventListener('popstate', () => {
    renderNativeShell();
    closeSidebar();
    setUserMenuOpen(false);
  });

  window.addEventListener('crm:navigation', () => {
    renderNativeShell();
    closeSidebar();
    setUserMenuOpen(false);
  });

  window.addEventListener('crm:route-changed', () => {
    renderNativeShell();
    if (currentProfile) {
      hydrateProfile(currentProfile);
    }
    closeSidebar();
    setUserMenuOpen(false);
  });

  window.addEventListener('crm:profile-updated', (event) => {
    const profile = event instanceof CustomEvent ? (event.detail?.profile as CrmProfile | undefined) : undefined;

    if (profile) {
      hydrateProfile(profile);
    }
  });
}

export function installNativeCrmShell(): void {
  installShellApi();
  installEvents();
  setSidebarCollapsed(readStoredSidebarCollapsed(), false);
  renderNativeShell();

  if (document.readyState === 'loading') {
    document.addEventListener(
      'DOMContentLoaded',
      () => {
        renderNativeShell();
        void loadProfile();
      },
      { once: true },
    );
  } else {
    void loadProfile();
  }

  window.setTimeout(renderNativeShell, 80);
  window.setTimeout(renderNativeShell, 300);
}

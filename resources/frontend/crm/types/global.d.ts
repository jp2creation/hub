export {};

declare global {
  interface Window {
    BrandMorphLoader?: {
      begin?: (key?: string, options?: CrmLoaderOptions) => string | void;
      end?: (key?: string) => void;
      fail?: (key?: string, error?: unknown) => void;
      forceHide: () => void;
      hide: (key?: string) => void;
      show: (delay?: number) => void;
      track?: <T>(key: string, promise: Promise<T>, options?: CrmLoaderOptions) => Promise<T>;
    };
    CrmLoader?: Window['BrandMorphLoader'];
    CRM_NAV_FALLBACK?: CrmFallbackNavigation;
    MartinSolsCrmApi?: CrmApiClient;
    MartinSolsCrmAssets?: {
      brandMorphLoaderStylesheet?: string;
      logoUrl?: string;
    };
    MartinSolsCrmConfig?: CrmShellConfig;
    MartinSolsCrmShell?: {
      closeUserMenu: () => void;
      openUserMenu: () => void;
      toggleUserMenu: () => void;
    };
    MartinSolsCrmLogout?: () => void;
    MartinSolsUi?: MartinSolsUi;
    MartinSolsMobileApp?: {
      checkForUpdates?: () => void;
      openSettings?: () => void;
      requestLocation: () => void;
    };
    MartinSolsNativeApp?: {
      authenticateSavedMobileSession?: (requestId: string) => void;
      clearAppCode?: () => void;
      clearMobileSession?: () => void;
      checkForUpdates?: () => void;
      getMobileAuthStatus?: () => string;
      getVersionCode?: () => string;
      getVersionName?: () => string;
      openDeviceSecuritySettings?: () => void;
      requestLocation?: (requestId: string, highAccuracy: boolean) => string;
      saveMobileSession?: (payload: string) => string;
      setAppCode?: () => void;
    };
    __martinSolsCrmFetchCsrf?: boolean;
    __martinSolsCrmDeadLegacyLinksInstalled?: boolean;
    __martinSolsCrmLegacyTemplateNavigationBridge?: boolean;
    __martinSolsCrmModulesLoaded?: boolean;
    __martinSolsCrmRouteModuleLoaderInstalled?: boolean;
    __martinSolsUiInstalled?: boolean;
  }
}

export type CrmLoaderOptions = {
  delay?: number;
  timeout?: number;
  timeoutMessage?: string;
};

export type CrmApiClient = {
  get: <T = unknown>(url: string, options?: CrmRequestOptions) => Promise<T>;
  post: <T = unknown>(url: string, body?: CrmRequestOptions['body'], options?: CrmRequestOptions) => Promise<T>;
  request: <T = unknown>(url: string, options?: CrmRequestOptions) => Promise<T>;
};

export type CrmFallbackNavigation = {
  menuGroups: CrmMenuGroup[];
  menuItems: CrmMenuItem[];
  modules: CrmModule[];
};

export type CrmMenuGroup = {
  active: boolean;
  menuKey: string;
  sortOrder: number;
  title: string;
};

export type CrmMenuItem = {
  active: boolean;
  groupKey: string;
  iconKey: string;
  itemKey: string;
  label: string;
  sortOrder: number;
};

export type CrmModule = {
  active: boolean;
  menuBadge?: string;
  name: string;
  routePath: string;
  showMenuBadge?: boolean;
  slug: string;
  sortOrder: number;
};

export type CrmProfileNavigation = CrmFallbackNavigation;

export type CrmRequestOptions = Omit<RequestInit, 'body'> & {
  body?: BodyInit | Record<string, unknown> | null;
};

export type CrmShellConfig = {
  assets: {
    brandMorphLoaderStylesheet: string;
    logoUrl: string;
  };
  csrfToken: string;
  locale: string;
  logout: {
    legacyLogoutPath: string;
    loginUrl: string;
    logoutUrl: string;
  };
  mobile: {
    app: boolean;
    embed: boolean;
    siteId: number | null;
  };
  themeStorageKey: string;
};

export type MartinSolsUi = {
  bindNavigation: () => void;
  closeModal: (modal?: HTMLElement | null) => void;
  escapeHtml: (value: unknown) => string;
  icon: (name: string) => string;
  openModal: (
    content: string,
    options?: {
      closeLabel?: string;
      labelledBy?: string;
      onClose?: () => void;
    },
  ) => HTMLElement;
  renderProductGrid: (
    items: Array<{
      active?: boolean;
      busy?: boolean;
      id: number | string;
      imageUrl?: string | null;
      meta?: string;
      name: string;
    }>,
    options: {
      actionName: string;
      emptyLabel?: string;
    },
  ) => string;
  renderSegmentControl: (
    options: Array<{
      active?: boolean;
      label: string;
      value: string;
    }>,
    name: string,
  ) => string;
};

type CrmShellConfigInput = Partial<CrmShellConfig>;

export type CrmShellConfig = {
  assets: {
    brandMorphLoaderStylesheet: string;
    brandMorphLoaderVideo: string;
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
  theme: {
    accent: string;
    accentHex: string;
    primary: string;
    primaryHex: string;
  };
  themeStorageKey: string;
};

const defaultConfig: CrmShellConfig = {
  assets: {
    brandMorphLoaderStylesheet: '/modules/crm-core/brand-morph-loader.css',
    brandMorphLoaderVideo: '/modules/crm-core/brand-morph-loader.mp4',
    logoUrl: '/assets/logo/martin-sols-logo.png',
  },
  csrfToken: '',
  locale: 'fr',
  logout: {
    legacyLogoutPath: '/auth/login',
    loginUrl: '/login',
    logoutUrl: '/logout',
  },
  mobile: {
    app: false,
    embed: false,
    siteId: null,
  },
  theme: {
    accent: '245 178 18',
    accentHex: '#f5b212',
    primary: '149 0 46',
    primaryHex: '#95002e',
  },
  themeStorageKey: 'martin-sols-hub-theme-v1',
};

function normalizeConfig(config: CrmShellConfigInput): CrmShellConfig {
  return {
    ...defaultConfig,
    ...config,
    assets: {
      ...defaultConfig.assets,
      ...(config.assets || {}),
    },
    logout: {
      ...defaultConfig.logout,
      ...(config.logout || {}),
    },
    mobile: {
      ...defaultConfig.mobile,
      ...(config.mobile || {}),
      siteId: config.mobile?.siteId ? Number(config.mobile.siteId) : null,
    },
    theme: {
      ...defaultConfig.theme,
      ...(config.theme || {}),
    },
  };
}

export function readCrmShellConfig(): CrmShellConfig {
  const element = document.getElementById('crm-shell-config');

  if (!element?.textContent) {
    return defaultConfig;
  }

  try {
    return normalizeConfig(JSON.parse(element.textContent) as CrmShellConfigInput);
  } catch {
    return defaultConfig;
  }
}

export function installCrmShellGlobals(config: CrmShellConfig): void {
  window.MartinSolsCrmConfig = config;
  window.MartinSolsCrmAssets = config.assets;

  try {
    localStorage.setItem('crm-locale', config.locale);
  } catch {
    // Locale preference is optional.
  }
}

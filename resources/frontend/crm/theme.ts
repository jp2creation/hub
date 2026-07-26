type ThemeConfig = {
  cardStyle?: string;
  color?: string;
  container?: string;
  direction?: string;
  mode?: string;
  sidebarLayout?: string;
};

type ThemePalette = {
  accent?: string;
  primary?: string;
};

const colorPresets: Record<string, [string, string]> = {
  blue: ['59 130 246', '99 102 241'],
  purple: ['236 72 153', '14 165 233'],
  green: ['34 197 94', '20 184 166'],
  orange: ['249 115 22', '245 158 11'],
  red: ['239 68 68', '244 63 94'],
  cyan: ['149 0 46', '245 178 18'],
};

export function applyStoredTheme(storageKey: string, defaultTheme: ThemePalette = {}): void {
  const presets: Record<string, [string, string]> = {
    ...colorPresets,
    cyan: [defaultTheme.primary || colorPresets.cyan[0], defaultTheme.accent || colorPresets.cyan[1]],
  };

  try {
    const saved = localStorage.getItem(storageKey);

    if (!saved) {
      return;
    }

    const config = JSON.parse(saved) as ThemeConfig;
    const root = document.documentElement;

    if (config.mode === 'dark') {
      root.classList.add('dark');
    }

    if (config.direction === 'rtl') {
      root.dir = 'rtl';
    }

    if (config.color && presets[config.color]) {
      root.style.setProperty('--theme-primary', presets[config.color][0]);
      root.style.setProperty('--theme-accent', presets[config.color][1]);
    }

    if (config.sidebarLayout) {
      root.dataset.sidebarLayout = config.sidebarLayout;
    }

    if (config.container) {
      root.dataset.container = config.container;
    }

    if (config.cardStyle) {
      root.dataset.cardStyle = config.cardStyle;
    }
  } catch {
    // Theme preference is optional.
  }
}

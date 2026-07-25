import './styles/template-compat.css';
import './styles/shell.css';
import './styles/native-ui.css';
import { installCrmApiClient } from './api/client';
import { installCsrfFetch } from './api/csrf';
import { installCrmShellGlobals, readCrmShellConfig } from './config';
import { installNativeCrmShell } from './layout/native-shell';
import { installDeadLegacyLinkGuard } from './legacy/dead-links';
import { installLegacyLogoutBridge } from './legacy/logout-bridge';
import { installLegacyStylesheets, installLegacyTemplateNavigationBridge } from './legacy/template-compat';
import { revealBrandLoaderElement } from './loader';
import { installMobileEmbedBridge } from './mobile/embed-bridge';
import { installMobileFallbackNavigation } from './mobile/fallback-nav';
import { installMobileAppSettings } from './mobile/settings';
import { installCrmModuleHostGuard } from './modules/hosts';
import {
  installCurrentCrmModuleRouteLoader,
  loadBrandMorphLoader,
  loadCrmShellOverlays,
  loadCurrentCrmModuleOverlay,
  preloadRemainingCrmModuleOverlays,
} from './modules/register';
import { installFallbackNavigation } from './router/menu';
import { applyStoredTheme } from './theme';
import { installMartinSolsUi } from './ui/native-ui';

const crmShellConfig = readCrmShellConfig();

applyStoredTheme(crmShellConfig.themeStorageKey);
installCrmShellGlobals(crmShellConfig);
installLegacyStylesheets();
revealBrandLoaderElement();
installLegacyLogoutBridge(crmShellConfig);
installFallbackNavigation();
installLegacyTemplateNavigationBridge();
installDeadLegacyLinkGuard();

await loadBrandMorphLoader();

installCsrfFetch();
installCrmApiClient();
installMobileAppSettings();
installMobileEmbedBridge();
installMobileFallbackNavigation();
installMartinSolsUi();
installNativeCrmShell();
installCrmModuleHostGuard();
installCurrentCrmModuleRouteLoader();

const shellLoaderKey = 'crm:shell';

window.CrmLoader?.begin?.(shellLoaderKey, {
  delay: 0,
  timeout: 0,
  timeoutMessage: 'Le HUB met trop de temps a charger.',
});

try {
  await loadCrmShellOverlays();
  await loadCurrentCrmModuleOverlay();
  window.dispatchEvent(new CustomEvent('crm:module-ready', { detail: { key: shellLoaderKey } }));
  preloadRemainingCrmModuleOverlays();
} catch (error) {
  window.CrmLoader?.fail?.(shellLoaderKey, error instanceof Error ? error : new Error('Chargement du HUB impossible.'));
  throw error;
} finally {
  window.CrmLoader?.end?.(shellLoaderKey);
}

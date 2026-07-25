(() => {
  const loader = document.getElementById('brand-morph-loader');

  if (!loader) return;

  const startedAt = window.performance ? performance.now() : Date.now();
  const standaloneQuery = '(display-mode: standalone)';
  const isStandalone =
    (window.matchMedia && window.matchMedia(standaloneQuery).matches) || window.navigator.standalone === true;
  const maxStartupWait = isStandalone ? 14000 : 10000;
  const minimumStartupMs = isStandalone ? 950 : 520;
  const minimumRouteMs = isStandalone ? 650 : 420;
  const maxRouteWait = isStandalone ? 18000 : 12000;
  const transitionPollMs = 120;
  const loadingTextPattern = /(Chargement|Loading)/i;
  const loadingErrorPattern = /(Chargement impossible|Loading failed)/i;
  const styleId = 'brand-morph-loader-app-style';
  const startupKey = 'crm:startup';
  const routeKey = 'crm:route';
  const timers = new Map();
  let rootObserver = null;
  let monitoredUntil = startedAt + maxStartupWait;

  function now() {
    return window.performance ? performance.now() : Date.now();
  }

  function loaderApi() {
    return window.CrmLoader || window.BrandMorphLoader || null;
  }

  function ensureAppStyle() {
    if (document.getElementById(styleId)) return;

    const style = document.createElement('style');

    style.id = styleId;
    style.textContent =
      '' +
      '#brand-morph-loader.is-visible .brand-morph-loader__backdrop{background:#f5f7fb;backdrop-filter:none;-webkit-backdrop-filter:none;}' +
      '.dark #brand-morph-loader.is-visible .brand-morph-loader__backdrop{background:#0f172a;}' +
      '#root [class*="loading"]:not([class*="error"]):not([class*="notice"]),' +
      '#root [class*="Loading"]:not([class*="error"]):not([class*="notice"]),' +
      '#root .brand-loader-suppressed-loading,' +
      '#root .brand-loader-suppressed-loading *{color:transparent!important;text-shadow:none!important;}' +
      '#brand-morph-loader.is-visible ~ #root{opacity:0!important;pointer-events:none!important;}' +
      '#brand-morph-loader.is-visible ~ #root [class*="loading"],' +
      '#brand-morph-loader.is-visible ~ #root [class*="spinner"],' +
      '#brand-morph-loader.is-visible ~ #root [class~="animate-spin"],' +
      '#brand-morph-loader.is-visible ~ #root [role="progressbar"],' +
      '#brand-morph-loader.is-visible ~ #root [aria-busy="true"],' +
      '#brand-morph-loader.is-visible ~ #root [id^="crm-"][id$="-root"]{opacity:0!important;color:transparent!important;text-shadow:none!important;}';

    document.head.appendChild(style);
  }

  function suppressLoadingElement(element) {
    if (!element || !element.classList) return;

    element.classList.add('brand-loader-suppressed-loading');
  }

  function directVisible(visible) {
    loader.classList.toggle('is-visible', visible);
    loader.setAttribute('aria-hidden', visible ? 'false' : 'true');
  }

  function beginOperation(key, timeout, timeoutMessage) {
    ensureAppStyle();

    const api = loaderApi();

    if (api && typeof api.begin === 'function') {
      api.begin(key, { delay: 0, timeout, timeoutMessage });
      return;
    }

    if (api && typeof api.show === 'function') {
      api.show(0);
      return;
    }

    directVisible(true);
  }

  function endOperation(key) {
    const api = loaderApi();

    if (api && typeof api.end === 'function') {
      api.end(key);
      return;
    }

    if (api && typeof api.hide === 'function') {
      api.hide();
      return;
    }

    directVisible(false);
  }

  function failOperation(key, error) {
    const api = loaderApi();

    if (api && typeof api.fail === 'function') {
      api.fail(key, error);
      return;
    }

    directVisible(true);
    loader.classList.add('is-error');
  }

  function setOperationTimer(key, callback, delay) {
    const existing = timers.get(key);

    if (existing) {
      window.clearTimeout(existing);
    }

    timers.set(
      key,
      window.setTimeout(() => {
        timers.delete(key);
        callback();
      }, delay),
    );
  }

  function appIsReady() {
    const root = document.getElementById('root');

    return !root || root.childElementCount > 0 || now() - startedAt > maxStartupWait;
  }

  function isVisible(element) {
    const rect = element.getBoundingClientRect();

    if (!rect.width || !rect.height) return false;

    const style = window.getComputedStyle(element);

    return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
  }

  function isSpinnerCandidate(element) {
    if (element.closest('button, a, [role="button"]')) return false;
    if (element.getAttribute('role') === 'progressbar') return true;
    if (element.getAttribute('aria-busy') === 'true') return true;

    return Array.from(element.classList || []).some((className) => {
      return className === 'animate-spin' || /spinner|loader/i.test(className);
    });
  }

  function shadowRootHasBlockingLoader(element) {
    const shadowRoot = element.shadowRoot;

    if (!shadowRoot) return null;

    const candidates = shadowRoot.querySelectorAll(
      [
        '[class*="loading"]',
        '[class*="Loading"]',
        '[class*="spinner"]',
        '[class~="animate-spin"]',
        '[role="progressbar"]',
        '[aria-busy="true"]',
      ].join(', '),
    );

    for (const candidate of candidates) {
      if (!isVisible(candidate)) continue;
      if (isSpinnerCandidate(candidate)) return true;

      const text = (candidate.textContent || '').trim();

      if (loadingTextPattern.test(text) && !loadingErrorPattern.test(text)) {
        suppressLoadingElement(candidate);
        return true;
      }
    }

    return false;
  }

  function isModulePlaceholder(element) {
    const shadowBlocks = shadowRootHasBlockingLoader(element);

    if (shadowBlocks !== null) return shadowBlocks;

    const text = (element.textContent || '').trim();

    if (!loadingTextPattern.test(text) || loadingErrorPattern.test(text)) return false;
    if (element.childElementCount > 0) return false;

    const classes = Array.from(element.classList || []).join(' ');
    const id = element.id || '';

    return /module-host/.test(classes) || /^crm-.*(?:module|root)$/.test(id);
  }

  function hasBlockingLoader() {
    const root = document.getElementById('root');

    if (!root) return false;

    const candidates = root.querySelectorAll(
      [
        '[class*="loading"]',
        '[class*="Loading"]',
        '[class*="spinner"]',
        '[class~="animate-spin"]',
        '[role="progressbar"]',
        '[aria-busy="true"]',
        '[class*="module-host"]',
        '[id*="-module"]',
        '[id^="crm-"][id$="-root"]',
      ].join(', '),
    );

    for (const candidate of candidates) {
      if (loader.contains(candidate) || !isVisible(candidate)) continue;

      if (isSpinnerCandidate(candidate) || isModulePlaceholder(candidate)) {
        suppressLoadingElement(candidate);
        return true;
      }
    }

    return false;
  }

  function monitorTransition(transitionStartedAt, maxWait) {
    monitoredUntil = Math.max(monitoredUntil, transitionStartedAt + maxWait);
  }

  function observeLoadingMutations() {
    if (rootObserver || !window.MutationObserver) return;

    rootObserver = new MutationObserver(() => {
      if (now() > monitoredUntil || loader.classList.contains('is-visible')) return;
      if (!hasBlockingLoader()) return;

      startRouteMonitor();
    });

    rootObserver.observe(document.body, {
      childList: true,
      subtree: true,
      characterData: true,
    });
  }

  function hideWhenReady() {
    const wait = Math.max(0, minimumStartupMs - (now() - startedAt));

    setOperationTimer(
      startupKey,
      () => {
        if (now() - startedAt >= maxStartupWait || (appIsReady() && !hasBlockingLoader())) {
          endOperation(startupKey);
          return;
        }

        hideWhenReady();
      },
      wait || 80,
    );
  }

  function hideRouteWhenReady(routeStartedAt) {
    const elapsed = now() - routeStartedAt;
    const nextDelay =
      elapsed >= minimumRouteMs ? transitionPollMs : Math.max(transitionPollMs, minimumRouteMs - elapsed);

    setOperationTimer(
      routeKey,
      () => {
        const currentElapsed = now() - routeStartedAt;

        if (currentElapsed >= maxRouteWait || (currentElapsed >= minimumRouteMs && !hasBlockingLoader())) {
          endOperation(routeKey);
          return;
        }

        hideRouteWhenReady(routeStartedAt);
      },
      nextDelay,
    );
  }

  function startRouteMonitor() {
    const transitionStartedAt = now();

    beginOperation(routeKey, 0, 'Le module met trop de temps a charger.');
    monitorTransition(transitionStartedAt, maxRouteWait);
    hideRouteWhenReady(transitionStartedAt);
  }

  function handleImportError(event) {
    const detail = event && event.detail ? event.detail : {};

    failOperation(detail.key || routeKey, detail.error || detail.message || 'Chargement du module impossible.');
  }

  ensureAppStyle();
  beginOperation(startupKey, 0, 'Le HUB met trop de temps a charger.');
  observeLoadingMutations();

  window.addEventListener('pageshow', () => {
    beginOperation(startupKey, 0, 'Le HUB met trop de temps a charger.');
    monitorTransition(startedAt, maxStartupWait);
    hideWhenReady();
  });

  window.addEventListener('load', () => {
    beginOperation(startupKey, 0, 'Le HUB met trop de temps a charger.');
    monitorTransition(startedAt, maxStartupWait);
    hideWhenReady();
  });

  window.addEventListener('crm:navigation', startRouteMonitor);
  window.addEventListener('crm:module-error', handleImportError);

  if (document.readyState === 'complete') {
    hideWhenReady();
  }
})();

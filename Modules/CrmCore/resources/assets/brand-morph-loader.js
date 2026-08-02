(() => {
    const loader = document.getElementById('brand-morph-loader');

    if (!loader) return;

    const activeOperations = new Map();
    const legacyOperations = [];
    let timer = null;
    let sequence = 0;

    function errorElement() {
        return loader.querySelector('[data-brand-loader-error]');
    }

    function videoElement() {
        return loader.querySelector('[data-brand-loader-video]');
    }

    function syncVideoPlayback(visible) {
        const video = videoElement();

        if (!video) return;

        if (visible) {
            try {
                video.currentTime = 0;
            } catch (error) {
                // Ignore browser-specific media seek restrictions.
            }

            const playback = typeof video.play === 'function' ? video.play() : null;

            if (playback && typeof playback.catch === 'function') {
                playback.catch(() => undefined);
            }

            return;
        }

        if (typeof video.pause === 'function') {
            video.pause();
        }

        try {
            video.currentTime = 0;
        } catch (error) {
            // Ignore browser-specific media seek restrictions.
        }
    }

    function setVisible(visible) {
        loader.classList.toggle('is-visible', visible);
        loader.setAttribute('aria-hidden', visible ? 'false' : 'true');
        syncVideoPlayback(visible);
    }

    function clearError() {
        loader.classList.remove('is-error');

        const error = errorElement();
        if (error) error.textContent = '';
    }

    function scheduleVisible(delay = 100) {
        clearTimeout(timer);

        if (delay <= 0) {
            setVisible(true);
            return;
        }

        timer = window.setTimeout(() => {
            setVisible(true);
        }, delay);
    }

    function operationId(key) {
        return String(key || `crm-loader:${++sequence}`);
    }

    function begin(key, options = {}) {
        const id = operationId(key);
        const timeout = Number(options.timeout ?? 12000);
        const delay = Number(options.delay ?? 100);
        const existing = activeOperations.get(id);

        if (existing && existing.timer) {
            window.clearTimeout(existing.timer);
        }

        clearError();

        const operation = {
            startedAt: Date.now(),
            timer: null
        };

        if (timeout > 0) {
            operation.timer = window.setTimeout(() => {
                fail(id, options.timeoutMessage || 'Chargement trop long. Rechargez la page si cela continue.');
            }, timeout);
        }

        activeOperations.set(id, operation);
        scheduleVisible(delay);

        return id;
    }

    function complete(key) {
        const id = operationId(key);
        const operation = activeOperations.get(id);

        if (!operation) return;

        if (operation.timer) {
            window.clearTimeout(operation.timer);
        }

        activeOperations.delete(id);

        if (activeOperations.size > 0 || loader.classList.contains('is-error')) return;

        clearTimeout(timer);
        setVisible(false);
    }

    function show(delay = 100) {
        const id = `crm-loader:legacy:${++sequence}`;

        legacyOperations.push(id);
        begin(id, { delay, timeout: 0 });

        return id;
    }

    function hide(key) {
        complete(key || legacyOperations.pop());
    }

    function completeLegacyOperations() {
        while (legacyOperations.length > 0) {
            complete(legacyOperations.pop());
        }
    }

    function fail(key, error) {
        const id = key ? operationId(key) : null;
        const operation = id ? activeOperations.get(id) : null;
        const message = error instanceof Error
            ? error.message
            : String(error || 'Chargement impossible.');

        if (operation && operation.timer) {
            window.clearTimeout(operation.timer);
        }

        if (id) {
            activeOperations.delete(id);
        }

        clearTimeout(timer);
        loader.classList.add('is-error');
        setVisible(true);

        const element = errorElement();
        if (element) element.textContent = message;
    }

    function forceHide() {
        for (const operation of activeOperations.values()) {
            if (operation.timer) {
                window.clearTimeout(operation.timer);
            }
        }

        activeOperations.clear();
        legacyOperations.length = 0;
        clearTimeout(timer);
        clearError();
        setVisible(false);
    }

    function track(key, promise, options = {}) {
        const id = begin(key, options);

        return Promise.resolve(promise)
            .then((value) => {
                complete(id);
                return value;
            })
            .catch((error) => {
                fail(id, error);
                throw error;
            });
    }

    function dispatchNavigation(method, previousHref) {
        const detail = {
            method,
            href: window.location.href,
            path: window.location.pathname,
            previousHref
        };

        completeLegacyOperations();

        try {
            window.dispatchEvent(new CustomEvent('crm:navigation', { detail }));
        } catch (error) {
            window.dispatchEvent(new Event('crm:navigation'));
        }

        window.dispatchEvent(new Event('crm:route-changed'));
    }

    function installNavigationBus() {
        if (window.__crmNavigationInstalled) return;

        window.__crmNavigationInstalled = true;

        ['pushState', 'replaceState'].forEach((method) => {
            const original = window.history[method];

            window.history[method] = function crmNavigationHistoryState() {
                const previousHref = window.location.href;
                const result = original.apply(this, arguments);

                window.setTimeout(() => dispatchNavigation(method, previousHref), 0);

                return result;
            };
        });

        window.addEventListener('popstate', () => dispatchNavigation('popstate', null));
    }

    function eventDetail(event) {
        return event && typeof event === 'object' && 'detail' in event ? event.detail || {} : {};
    }

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a');
        if (!link) return;

        const url = new URL(link.href, window.location.href);

        const ignored =
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey ||
            link.target === '_blank' ||
            link.hasAttribute('download') ||
            link.hasAttribute('data-no-loader') ||
            url.origin !== window.location.origin ||
            (url.pathname === window.location.pathname && url.hash);

        if (!ignored) show();
    });

    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;

        const target = event.target;
        const form = target instanceof HTMLFormElement
            ? target
            : target && typeof target.closest === 'function'
                ? target.closest('form')
                : null;

        if (!form) return;
        if (form.method && form.method.toLowerCase() === 'dialog') return;
        if (form.matches('[data-no-loader], [data-ajax], [data-crm-ajax-form]')) return;
        if (form.closest('[data-no-loader]')) return;

        show();
    });

    ['hub:module-loading', 'crm:module-loading'].forEach((eventName) => window.addEventListener(eventName, (event) => {
        const detail = eventDetail(event);
        begin(detail.key || 'hub:module', {
            delay: detail.delay ?? 0,
            timeout: detail.timeout ?? 12000,
            timeoutMessage: detail.timeoutMessage
        });
    }));

    ['hub:module-ready', 'crm:module-ready'].forEach((eventName) => window.addEventListener(eventName, (event) => {
        complete(eventDetail(event).key || 'hub:module');
    }));

    ['hub:module-error', 'crm:module-error'].forEach((eventName) => window.addEventListener(eventName, (event) => {
        const detail = eventDetail(event);
        fail(detail.key || 'hub:module', detail.error || detail.message || 'Chargement du module impossible.');
    }));

    window.addEventListener('crm:navigation', completeLegacyOperations);

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) forceHide();
    });

    window.addEventListener('load', () => {
        if (activeOperations.size === 0) {
            forceHide();
        }
    });

    installNavigationBus();

    window.BrandMorphLoader = {
        begin,
        end: complete,
        fail,
        show,
        hide,
        forceHide,
        track
    };

    window.CrmLoader = window.BrandMorphLoader;
})();

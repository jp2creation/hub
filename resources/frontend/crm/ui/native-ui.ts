import { crmHostRouteForPath, normalizedCrmPath } from '../modules/hosts';

type ModalOptions = {
  closeLabel?: string;
  labelledBy?: string;
  onClose?: () => void;
};

type SegmentOption = {
  active?: boolean;
  label: string;
  value: string;
};

type ProductGridItem = {
  active?: boolean;
  busy?: boolean;
  id: number | string;
  imageUrl?: string | null;
  meta?: string;
  name: string;
};

type ProductGridOptions = {
  actionName: string;
  emptyLabel?: string;
};

export type MartinSolsUi = {
  bindNavigation: () => void;
  closeModal: (modal?: HTMLElement | null) => void;
  escapeHtml: (value: unknown) => string;
  icon: (name: string) => string;
  openModal: (content: string, options?: ModalOptions) => HTMLElement;
  renderProductGrid: (items: ProductGridItem[], options: ProductGridOptions) => string;
  renderSegmentControl: (options: SegmentOption[], name: string) => string;
};

const internalNavigationEvents = ['crm:navigation', 'crm:route-changed'];
const routeTransitionClass = 'crm-ui-route-transitioning';
const moduleReadyClass = 'crm-ui-module-ready';
let activeModalCount = 0;
let routeTransitionTimer = 0;

function escapeHtml(value: unknown): string {
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

function icon(name: string): string {
  const paths: Record<string, string> = {
    calendar: '<rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 3v4M16 3v4M4 10h16"></path>',
    'chevron-left': '<path d="m15 18-6-6 6-6"></path>',
    'chevron-right': '<path d="m9 18 6-6-6-6"></path>',
    close: '<path d="M18 6 6 18M6 6l12 12"></path>',
    package: '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"></path><path d="m4 7.5 8 4.5 8-4.5M12 12v9"></path>',
    plus: '<path d="M12 5v14M5 12h14"></path>',
    save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path><path d="M17 21v-8H7v8M7 3v5h8"></path>',
    trash: '<path d="M3 6h18M8 6V4h8v2M6 6l1 15h10l1-15"></path>',
    truck:
      '<path d="M3 7h11v8H3z"></path><path d="M14 10h3l3 3v2h-6z"></path><circle cx="7" cy="18" r="2"></circle><circle cx="17" cy="18" r="2"></circle>',
  };

  return `<svg class="ms-ui-icon" viewBox="0 0 24 24" aria-hidden="true">${paths[name] || paths.plus}</svg>`;
}

function closestModal(element?: HTMLElement | null): HTMLElement | null {
  return (
    element?.closest<HTMLElement>('[data-ms-ui-modal]') || document.querySelector<HTMLElement>('[data-ms-ui-modal]')
  );
}

function closeModal(modal?: HTMLElement | null): void {
  const target = closestModal(modal);

  if (!target || target.dataset.msUiClosing === 'true') {
    return;
  }

  target.dataset.msUiClosing = 'true';
  target.dispatchEvent(new CustomEvent('ms-ui-modal-close'));
  target.remove();

  activeModalCount = Math.max(0, activeModalCount - 1);
  document.body.classList.toggle('ms-ui-modal-open', activeModalCount > 0);
}

function openModal(content: string, options: ModalOptions = {}): HTMLElement {
  const modal = document.createElement('div');

  modal.className = 'ms-ui-modal';
  modal.dataset.msUiModal = 'true';
  modal.tabIndex = -1;
  modal.innerHTML = [
    '<button class="ms-ui-modal-backdrop" type="button" data-ms-ui-modal-close aria-label="Fermer"></button>',
    `<section class="ms-ui-dialog" role="dialog" aria-modal="true"${options.labelledBy ? ` aria-labelledby="${escapeHtml(options.labelledBy)}"` : ''}>`,
    content,
    `<button class="ms-ui-dialog-close" type="button" data-ms-ui-modal-close aria-label="${escapeHtml(options.closeLabel || 'Fermer')}">${icon('close')}</button>`,
    '</section>',
  ].join('');

  const keydownHandler = (event: KeyboardEvent): void => {
    if (event.key !== 'Escape') {
      return;
    }

    event.preventDefault();
    closeModal(modal);
  };

  modal.addEventListener(
    'ms-ui-modal-close',
    () => {
      document.removeEventListener('keydown', keydownHandler, true);
      options.onClose?.();
    },
    { once: true },
  );

  document.addEventListener('keydown', keydownHandler, true);
  document.body.appendChild(modal);
  activeModalCount += 1;
  document.body.classList.add('ms-ui-modal-open');

  window.requestAnimationFrame(() => {
    modal.querySelector<HTMLElement>('.ms-ui-dialog-close')?.focus({ preventScroll: true });
  });

  return modal;
}

function renderSegmentControl(options: SegmentOption[], name: string): string {
  return [
    `<div class="ms-ui-segment" role="tablist" aria-label="${escapeHtml(name)}">`,
    options
      .map((option) => {
        return [
          `<button type="button" data-view="${escapeHtml(option.value)}" class="${option.active ? 'is-active' : ''}" role="tab" aria-selected="${option.active ? 'true' : 'false'}">`,
          escapeHtml(option.label),
          '</button>',
        ].join('');
      })
      .join(''),
    '</div>',
  ].join('');
}

function itemInitials(name: string): string {
  return (
    name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join('') || 'MS'
  );
}

function renderProductGrid(items: ProductGridItem[], options: ProductGridOptions): string {
  if (!items.length) {
    return `<div class="ms-ui-empty">${escapeHtml(options.emptyLabel || 'Aucun élément disponible.')}</div>`;
  }

  return [
    '<div class="ms-ui-product-grid">',
    items
      .map((item) => {
        return [
          `<button class="ms-ui-product-card${item.active ? ' is-active' : ''}" type="button" data-${escapeHtml(options.actionName)}="${escapeHtml(item.id)}">`,
          '<span class="ms-ui-product-image">',
          item.imageUrl
            ? `<img src="${escapeHtml(item.imageUrl)}" alt="${escapeHtml(item.name)}" loading="lazy">`
            : `<span class="ms-ui-product-initials">${escapeHtml(itemInitials(item.name))}</span>`,
          `<span class="ms-ui-status-dot${item.busy ? ' is-busy' : ''}" aria-label="${item.busy ? 'Indisponible' : 'Disponible'}"></span>`,
          '</span>',
          '<span class="ms-ui-product-body">',
          `<strong>${escapeHtml(item.name)}</strong>`,
          item.meta ? `<small>${escapeHtml(item.meta)}</small>` : '',
          '</span>',
          '</button>',
        ].join('');
      })
      .join(''),
    '</div>',
  ].join('');
}

function isPlainInternalClick(event: MouseEvent, link: HTMLAnchorElement): boolean {
  return (
    !event.defaultPrevented &&
    event.button === 0 &&
    !event.metaKey &&
    !event.ctrlKey &&
    !event.shiftKey &&
    !event.altKey &&
    !link.hasAttribute('download') &&
    link.target !== '_blank'
  );
}

function setTemplateDefaults(): void {
  const html = document.documentElement;

  html.dataset.cardStyle = html.dataset.cardStyle || 'shadow';
  html.dataset.sidebarLayout = html.dataset.sidebarLayout || 'vertical';
  html.dataset.container = html.dataset.container || 'full';
}

function finishRouteTransition(): void {
  if (routeTransitionTimer) {
    window.clearTimeout(routeTransitionTimer);
    routeTransitionTimer = 0;
  }

  document.documentElement.classList.remove(routeTransitionClass);
  document.documentElement.classList.add(moduleReadyClass);

  window.setTimeout(() => {
    document.documentElement.classList.remove(moduleReadyClass);
  }, 320);
}

function beginRouteTransition(): void {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }

  document.documentElement.classList.remove(moduleReadyClass);
  document.documentElement.classList.add(routeTransitionClass);

  if (routeTransitionTimer) {
    window.clearTimeout(routeTransitionTimer);
  }

  routeTransitionTimer = window.setTimeout(finishRouteTransition, 1200);
}

function syncNavigationChrome(): void {
  const activeLink = document.querySelector<HTMLElement>('.crm-native-nav-link.is-active');

  activeLink?.closest<HTMLElement>('[data-crm-native-submenu]')?.classList.add('is-open');
  activeLink?.scrollIntoView({ block: 'nearest' });
  document.body.classList.remove('crm-native-sidebar-open');
}

function bindNavigation(): void {
  document.addEventListener(
    'click',
    (event) => {
      const target = event.target instanceof Element ? event.target : null;
      const link = target?.closest<HTMLAnchorElement>('a[href]');

      if (!link || !isPlainInternalClick(event, link)) {
        return;
      }

      const url = new URL(link.href, window.location.href);

      if (url.origin !== window.location.origin || !crmHostRouteForPath(url.pathname)) {
        return;
      }

      const nextPath = `${normalizedCrmPath(url.pathname)}${url.search}${url.hash}`;
      const currentPath = `${normalizedCrmPath(window.location.pathname)}${window.location.search}${window.location.hash}`;

      event.preventDefault();

      beginRouteTransition();

      if (nextPath !== currentPath) {
        window.history.pushState({}, '', nextPath);
      }

      internalNavigationEvents.forEach((eventName) => {
        window.dispatchEvent(new CustomEvent(eventName, { detail: { path: nextPath } }));
      });

      window.requestAnimationFrame(syncNavigationChrome);
    },
    true,
  );
}

function createUi(): MartinSolsUi {
  return {
    bindNavigation,
    closeModal,
    escapeHtml,
    icon,
    openModal,
    renderProductGrid,
    renderSegmentControl,
  };
}

export function installMartinSolsUi(): void {
  if (window.__martinSolsUiInstalled) {
    return;
  }

  window.__martinSolsUiInstalled = true;
  window.MartinSolsUi = window.MartinSolsUi || createUi();
  setTemplateDefaults();
  window.MartinSolsUi.bindNavigation();

  document.addEventListener(
    'click',
    (event) => {
      const target = event.target instanceof Element ? event.target : null;

      if (!target?.closest('[data-ms-ui-modal-close]')) {
        return;
      }

      event.preventDefault();
      closeModal(target.closest<HTMLElement>('[data-ms-ui-modal]'));
    },
    true,
  );

  window.addEventListener('popstate', beginRouteTransition);
  window.addEventListener('hub:module-ready', finishRouteTransition);
  window.addEventListener('crm:module-ready', finishRouteTransition);
  window.addEventListener('crm:route-changed', () => window.requestAnimationFrame(syncNavigationChrome));
  window.addEventListener('crm:navigation', () => window.requestAnimationFrame(syncNavigationChrome));
  window.requestAnimationFrame(syncNavigationChrome);
}

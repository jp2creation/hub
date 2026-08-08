(() => {
  const api = '/api/reservations';
  const rootId = 'crm-reservations-module';
  const styleId = 'crm-reservations-style';
  const activeSiteEvent = 'crm:active-site-changed';
  const activeSiteStorageKey = 'crm:active-site-id';
  const routeEvents = ['popstate', 'crm:navigation', 'crm:route-changed'];
  const routePrefixes = ['/reservations'];

  const state = {
    data: null,
    loading: false,
    error: '',
    selectedVehicleId: null,
    selectedDate: formatDate(new Date()),
    month: new Date(new Date().getFullYear(), new Date().getMonth(), 1),
    view: 'month',
    modal: null,
    selection: null,
  };

  let mountTimer = null;
  let loadSequence = 0;

  function isRoute() {
    const path = window.location.pathname.replace(/\/+$/, '') || '/';
    return routePrefixes.some((route) => path === route || path.startsWith(`${route}/`));
  }

  function esc(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  }

  function formatDate(date) {
    return [
      date.getFullYear(),
      String(date.getMonth() + 1).padStart(2, '0'),
      String(date.getDate()).padStart(2, '0'),
    ].join('-');
  }

  function parseDate(value) {
    const [year, month, day] = String(value || '')
      .slice(0, 10)
      .split('-')
      .map(Number);
    return new Date(year || 1970, (month || 1) - 1, day || 1);
  }

  function addDays(date, days) {
    const next = new Date(date);
    next.setDate(next.getDate() + days);
    return next;
  }

  function monthLabel(date) {
    const label = date.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
    return label.charAt(0).toUpperCase() + label.slice(1);
  }

  function dateLabel(value) {
    return parseDate(value).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  function timeLabel(value) {
    return String(value || '').slice(11, 16);
  }

  function reservationHeaderOffset() {
    const header = document.querySelector('.crm-native-header');
    const height = header?.getBoundingClientRect?.().height || 0;

    return Math.max(8, height + 8);
  }

  function isScrollableElement(node) {
    if (!node || node === document.body || node === document.documentElement) {
      return false;
    }

    const style = window.getComputedStyle(node);
    const canScroll = /(auto|scroll|overlay)/.test(style.overflowY);

    return canScroll && node.scrollHeight > node.clientHeight;
  }

  function findReservationScroller(target) {
    let node = target?.parentElement || null;

    while (node && node !== document.body && node !== document.documentElement) {
      if (isScrollableElement(node)) {
        return node;
      }

      node = node.parentElement;
    }

    const shellScroller = [document.querySelector('.crm-native-main'), document.querySelector('.crm-native-content')].find(
      (candidate) => isScrollableElement(candidate),
    );

    if (shellScroller) {
      return shellScroller;
    }

    return document.scrollingElement || document.documentElement;
  }

  function smoothWindowScrollTo(top) {
    const nextTop = Math.max(0, top);

    try {
      window.scrollTo({ top: nextTop, behavior: 'smooth' });
    } catch (error) {
      window.scrollTo(0, nextTop);
    }
  }

  function smoothElementScrollTo(scroller, top) {
    const nextTop = Math.max(0, top);

    try {
      scroller.scrollTo({ top: nextTop, behavior: 'smooth' });
    } catch (error) {
      scroller.scrollTop = nextTop;
    }
  }

  function scrollElementIntoReservationView(target, block = 'start') {
    const scroller = findReservationScroller(target);
    const targetRect = target.getBoundingClientRect();
    const isDocumentScroller =
      scroller === document.scrollingElement || scroller === document.documentElement || scroller === document.body;

    if (isDocumentScroller) {
      const currentTop = window.scrollY || document.documentElement.scrollTop || document.body.scrollTop || 0;
      const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
      const top =
        block === 'end'
          ? currentTop + targetRect.bottom - viewportHeight + 18
          : currentTop + targetRect.top - reservationHeaderOffset();

      smoothWindowScrollTo(top);
      return;
    }

    const scrollerRect = scroller.getBoundingClientRect();
    const top =
      block === 'end'
        ? scroller.scrollTop + targetRect.bottom - scrollerRect.bottom + 18
        : scroller.scrollTop + targetRect.top - scrollerRect.top - 8;

    smoothElementScrollTo(scroller, top);
  }

  function scrollToReservationTarget(resolveTarget, block = 'start', attempt = 0) {
    window.requestAnimationFrame(() => {
      const target = resolveTarget();

      if (!target) {
        if (attempt < 3) {
          window.setTimeout(() => scrollToReservationTarget(resolveTarget, block, attempt + 1), 60);
        }

        return;
      }

      scrollElementIntoReservationView(target, block);

      if (attempt === 0) {
        [90, 240, 520].forEach((delay) => {
          window.setTimeout(() => scrollElementIntoReservationView(target, block), delay);
        });
      }
    });
  }

  function scrollPlanningIntoView() {
    scrollToReservationTarget(
      () =>
        document.querySelector(`#${rootId} [data-resa-planning]`) ||
        document.querySelector(`#${rootId} [data-resa-calendar]`),
    );
  }

  function scrollSelectionIntoView() {
    scrollToReservationTarget(() => document.querySelector(`#${rootId} [data-resa-selection]`), 'end');
  }

  function activeSiteId() {
    const fromApi = Number(window.CRM_ACTIVE_SITE?.getSiteId?.() || 0);
    if (Number.isFinite(fromApi) && fromApi > 0) return fromApi;

    try {
      const fromStorage = Number(window.localStorage.getItem(activeSiteStorageKey) || 0);
      if (Number.isFinite(fromStorage) && fromStorage > 0) return fromStorage;
    } catch (error) {
      return null;
    }

    return null;
  }

  function siteId() {
    return Number(activeSiteId() || state.data?.user?.selectedSiteId || state.data?.sites?.[0]?.id || 0);
  }

  function selectedSite() {
    const id = siteId();
    return (state.data?.sites || []).find((site) => Number(site.id) === id) || null;
  }

  function selectedVehicle() {
    const vehicles = siteVehicles();
    if (!state.selectedVehicleId) return null;

    return vehicles.find((vehicle) => Number(vehicle.id) === Number(state.selectedVehicleId)) || null;
  }

  function siteVehicles() {
    const id = siteId();
    return (state.data?.vehicles || []).filter((vehicle) => Number(vehicle.siteId) === id && vehicle.active !== false);
  }

  function reservations() {
    return state.data?.reservations || [];
  }

  function vehicleReservations(vehicleId) {
    return reservations()
      .filter((reservation) => Number(reservation.vehicleId) === Number(vehicleId))
      .sort((a, b) => String(a.startAt).localeCompare(String(b.startAt)));
  }

  function reservationVehicle(reservation) {
    return (state.data?.vehicles || []).find((vehicle) => Number(vehicle.id) === Number(reservation.vehicleId)) || null;
  }

  function permissions() {
    return new Set(Array.isArray(state.data?.user?.permissions) ? state.data.user.permissions : []);
  }

  function startOfToday() {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return today;
  }

  function dateValueIsPast(value) {
    return parseDate(value).getTime() < startOfToday().getTime();
  }

  function slotDateIsPast(value) {
    return dateValueIsPast(String(value || '').slice(0, 10));
  }

  function reservationDateIsPast(reservation) {
    return slotDateIsPast(reservation?.startAt);
  }

  function userIsReservationAdmin() {
    return String(state.data?.user?.role || '').toLowerCase() === 'admin';
  }

  function canCreateReservationForDate(value) {
    return !dateValueIsPast(value) || userIsReservationAdmin();
  }

  function canUpdateReservation(reservation) {
    if (!reservation) return false;
    if (reservationDateIsPast(reservation) && !userIsReservationAdmin()) return false;

    const user = state.data?.user || {};
    const access = permissions();

    return (
      userIsReservationAdmin() ||
      access.has('reservations.update_any') ||
      (Number(reservation.userId) === Number(user.id) && access.has('reservations.update_own'))
    );
  }

  function canDeleteReservation(reservation) {
    if (!reservation) return false;
    if (reservationDateIsPast(reservation) && !userIsReservationAdmin()) return false;

    const user = state.data?.user || {};
    const access = permissions();

    return (
      userIsReservationAdmin() ||
      access.has('reservations.delete_any') ||
      (Number(reservation.userId) === Number(user.id) && access.has('reservations.delete_own'))
    );
  }

  function reservationUserName(reservation) {
    const directName = String(reservation?.userName || '').trim();
    if (directName) return directName;

    return (
      (state.data?.users || []).find((user) => Number(user.id) === Number(reservation?.userId))?.name ||
      'Non renseigné'
    );
  }

  function dateRange() {
    const from = new Date(state.month.getFullYear(), state.month.getMonth(), 1);
    const to = new Date(state.month.getFullYear(), state.month.getMonth() + 1, 0);

    return {
      from: formatDate(addDays(from, -7)),
      to: formatDate(addDays(to, 14)),
    };
  }

  async function request(action, options = {}) {
    const url = new URL(api, window.location.origin);
    url.searchParams.set('action', action);

    Object.entries(options.query || {}).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') url.searchParams.set(key, String(value));
    });

    const response = await fetch(url.toString(), {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
      },
      body: options.body ? JSON.stringify(options.body) : undefined,
    });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || 'Module réservations indisponible');
    }

    return payload;
  }

  function ensureStyle() {
    if (document.getElementById(styleId)) return;

    const style = document.createElement('style');
    style.id = styleId;
    style.textContent = `
      #${rootId}{--resa-primary:rgb(var(--theme-primary));--resa-border:var(--color-surface-200,#e2e8f0);--resa-text:var(--color-secondary-900,#0f172a);--resa-muted:var(--color-secondary-500,#64748b);--resa-green:#16a34a;--resa-red:#dc2626;--resa-blue:#4f6df5;display:grid;gap:1rem}
      #${rootId} *{box-sizing:border-box}
      #${rootId} svg{width:1.05rem;height:1.05rem;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} .resa-top{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem}
      #${rootId} .resa-title h1{margin:0;color:var(--resa-text);font-size:1.8rem;line-height:1.08;font-weight:600;letter-spacing:0}
      #${rootId} .resa-title p{margin:.35rem 0 0;color:var(--resa-muted);font-size:.92rem;font-weight:400}
      #${rootId} .resa-button{display:inline-flex;align-items:center;justify-content:center;gap:.42rem;min-height:2.45rem;border:1px solid var(--resa-border);border-radius:.5rem;background:#fff;padding:.58rem .9rem;color:var(--resa-text);font-size:.84rem;font-weight:600;text-decoration:none;cursor:pointer;box-shadow:0 10px 24px rgba(15,23,42,.04)}
      #${rootId} .resa-button-primary{border-color:transparent;background:var(--resa-primary);color:#fff}
      #${rootId} .resa-button-danger{color:#b91c1c}
      #${rootId} .resa-grid{display:grid;gap:.85rem}
      #${rootId} .resa-card{border:1px solid var(--resa-border);border-radius:.6rem;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.05)}
      #${rootId} .resa-vehicle-card{overflow:hidden}
      #${rootId} .resa-vehicle-card .resa-card-body{background:#fafafa}
      #${rootId} .resa-card-count{background:#f8fafc;color:var(--resa-text)}
      #${rootId} .resa-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:.8rem;border-bottom:1px solid var(--resa-border);padding:.92rem 1rem}
      #${rootId} .resa-card-title{margin:0;color:var(--resa-text);font-size:1rem;font-weight:600}
      #${rootId} .resa-card-subtitle{margin:.18rem 0 0;color:var(--resa-muted);font-size:.78rem;font-weight:750}
      #${rootId} .resa-card-body{padding:1rem}
      #${rootId} .resa-planning-header{display:grid;grid-template-columns:2.75rem minmax(0,1fr) 2.75rem;align-items:center;text-align:center}
      #${rootId} .resa-planning-card{scroll-margin-top:5.75rem}
      #${rootId} .resa-planning-header>div{min-width:0}
      #${rootId} .resa-nav-button{width:2.75rem;min-height:2.75rem;padding:0;border-radius:.65rem}
      #${rootId} .resa-nav-button svg{margin:0}
      #${rootId} .resa-vehicles{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,18rem),18rem));align-items:start;gap:.85rem}
      #${rootId} .resa-product-card{position:relative;display:grid;grid-template-columns:5.45rem minmax(0,1fr);overflow:hidden;width:100%;min-width:0;min-height:5.55rem;border:1px solid var(--resa-border);border-radius:.55rem;background:#fff;text-align:left;cursor:pointer;box-shadow:0 10px 24px rgba(15,23,42,.05);transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
      #${rootId} .resa-product-card:hover,#${rootId} .resa-product-card.is-active{border-color:rgb(var(--theme-primary) / .45);transform:translateY(-1px);box-shadow:0 14px 28px rgba(15,23,42,.08)}
      #${rootId} .resa-product-card:focus-visible{outline:3px solid rgb(var(--theme-primary) / .18);outline-offset:2px}
      #${rootId} .resa-product-image{position:relative;display:grid;place-items:center;width:100%;min-width:0;min-height:100%;border-right:1px solid var(--resa-border);background:var(--color-surface-100,#f1f5f9);color:var(--resa-primary);font-size:1.08rem;font-weight:600;overflow:hidden}
      #${rootId} .resa-product-image img{width:100%;height:100%;object-fit:cover}
      #${rootId} .resa-product-body{display:grid;align-content:center;gap:.28rem;min-width:0;padding:.66rem .78rem}
      #${rootId} .resa-product-name{display:block;color:var(--resa-text);font-size:.92rem;font-weight:600;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #${rootId} .resa-product-meta{display:block;color:var(--resa-muted);font-size:.74rem;font-weight:750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #${rootId} .resa-product-status{display:inline-flex;width:max-content;max-width:100%;align-items:center;gap:.34rem;border-radius:999px;background:#ecfdf3;padding:.22rem .52rem;color:var(--resa-green);font-size:.68rem;font-weight:600;white-space:nowrap}
      #${rootId} .resa-product-status:before{width:.42rem;height:.42rem;flex:0 0 auto;border-radius:999px;background:currentColor;content:""}
      #${rootId} .resa-product-status.is-busy{background:#fef2f2;color:var(--resa-red)}
      #${rootId} .resa-toolbar{display:grid;gap:.75rem}
      #${rootId} .resa-legend{display:flex;align-items:center;justify-content:center;gap:.65rem;flex-wrap:wrap}
      #${rootId} .resa-segment{display:inline-grid;grid-template-columns:repeat(3,minmax(0,1fr));border:1px solid var(--resa-border);border-radius:.55rem;overflow:hidden;background:#fff}
      #${rootId} .resa-segment button{border:0;border-right:1px solid var(--resa-border);background:transparent;padding:.55rem .85rem;color:var(--resa-text);font-size:.78rem;font-weight:600;cursor:pointer}
      #${rootId} .resa-segment button:last-child{border-right:0}
      #${rootId} .resa-segment button.is-active{background:var(--resa-primary);color:#fff}
      #${rootId} .resa-day-board{display:grid;gap:.85rem}
      #${rootId} .resa-day-section{display:grid;gap:.55rem}
      #${rootId} .resa-day-section-title{display:flex;align-items:center;justify-content:space-between;color:var(--resa-text);font-size:.84rem;font-weight:600}
      #${rootId} .resa-day-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(6.5rem,1fr));gap:.45rem}
      #${rootId} .resa-mobile-day-slots{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem}
      #${rootId} .resa-mobile-slot-column{display:grid;gap:.45rem;min-width:0}
      #${rootId} .resa-slot-column-heading{display:grid;place-items:center;min-height:3rem;border-radius:.55rem;background:#f8fafc;color:var(--resa-text);text-align:center}
      #${rootId} .resa-slot-column-heading strong{font-size:.78rem;font-weight:600}
      #${rootId} .resa-slot-column-heading span{font-size:.72rem;font-weight:600;color:var(--resa-muted)}
      #${rootId} .reservation-mobile-slot-button,#${rootId} .reservation-day-cell-button{display:grid;align-content:center;justify-items:center;gap:.12rem;min-height:3.75rem;border:0;border-radius:.55rem;background:var(--resa-green);color:#fff;padding:.38rem .45rem;text-align:center;cursor:pointer;box-shadow:0 9px 18px rgba(22,163,74,.22)}
      #${rootId} .reservation-mobile-slot-button.is-reserved,#${rootId} .reservation-day-cell-button.is-reserved{background:var(--resa-red);box-shadow:0 9px 18px rgba(220,38,38,.22)}
      #${rootId} .reservation-mobile-slot-button.is-selecting,#${rootId} .reservation-day-cell-button.is-selecting{background:var(--resa-primary);box-shadow:0 9px 18px rgb(var(--theme-primary) / .22)}
      #${rootId} .resa-slot-time{display:block;font-size:1.02rem;font-weight:600;line-height:1}
      #${rootId} .resa-slot-meta{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.68rem;font-weight:850;line-height:1.05;opacity:.9}
      #${rootId} .resa-slot-owner{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.62rem;font-weight:700;line-height:1.05;opacity:.96}
      #${rootId} .resa-selection-panel{border:1px solid color-mix(in srgb,var(--resa-primary) 22%,white);border-radius:.6rem;background:#fff;padding:.9rem;box-shadow:0 12px 28px rgb(var(--theme-primary) / .08)}
      #${rootId} .resa-selection-panel span{display:block;color:var(--resa-muted);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #${rootId} .resa-selection-panel strong{display:block;margin:.18rem 0;color:var(--resa-text);font-size:1.15rem;font-weight:600}
      #${rootId} .resa-selection-actions{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-top:.7rem}
      #${rootId} .reservation-fast-actions{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-top:.85rem}
      #${rootId} .resa-month-board{display:grid;gap:.65rem}
      #${rootId} .resa-month-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));scroll-margin-top:5.75rem;border:1px solid var(--resa-border);border-radius:.6rem;overflow:hidden}
      #${rootId} .resa-month-head,#${rootId} .resa-month-cell{min-height:4.2rem;border-right:1px solid var(--resa-border);border-bottom:1px solid var(--resa-border);padding:.52rem}
      #${rootId} .resa-month-head{min-height:auto;background:#f8fafc;color:var(--resa-muted);font-size:.72rem;font-weight:600;text-align:center;text-transform:uppercase}
      #${rootId} .resa-month-cell:nth-child(7n){border-right:0}
      #${rootId} .resa-month-cell button{display:grid;gap:.25rem;width:100%;height:100%;border:0;background:transparent;color:var(--resa-text);text-align:left;cursor:pointer}
      #${rootId} .resa-month-cell.is-muted{background:#fafafa;color:#94a3b8}
      #${rootId} .resa-month-dots{display:flex;align-items:center;justify-content:center;gap:.24rem;margin-top:auto}
      #${rootId} .resa-month-dot{width:.42rem;height:.42rem;border-radius:999px;background:var(--resa-primary)}
      #${rootId} .resa-month-dot-morning{background:#f7b711}
      #${rootId} .resa-month-dot-afternoon{background:#95002e}
      #${rootId} .resa-month-dot-day{background:#000000}
      #${rootId} .resa-list{display:grid;gap:.55rem}
      #${rootId} .resa-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.8rem;align-items:center;border:1px solid var(--resa-border);border-radius:.5rem;padding:.72rem .8rem;background:#fff}
      #${rootId} .resa-row strong{display:block;color:var(--resa-text);font-size:.88rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #${rootId} .resa-row span{display:block;margin-top:.12rem;color:var(--resa-muted);font-size:.74rem;font-weight:750}
      #${rootId} .resa-badge{display:inline-flex;align-items:center;border-radius:999px;background:#f7e8ee;padding:.25rem .6rem;color:var(--resa-primary);font-size:.72rem;font-weight:600}
      #${rootId} .resa-empty,#${rootId} .resa-loading{display:grid;place-items:center;min-height:6rem;border:1px dashed var(--resa-border);border-radius:.55rem;color:var(--resa-muted);font-weight:850;text-align:center;padding:1rem}
      #${rootId} .resa-alert{border:1px solid #fecaca;border-radius:.55rem;background:#fff1f2;padding:.8rem;color:#b91c1c;font-weight:850}
      #${rootId} .resa-modal{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;background:rgba(15,23,42,.52);padding:1rem}
      #${rootId} .resa-dialog{width:min(100%,34rem);max-height:86vh;overflow:auto;border-radius:.7rem;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.28)}
      #${rootId} .resa-dialog-compact{width:min(100%,28rem)}
      #${rootId} .resa-dialog-header{display:flex;align-items:center;justify-content:space-between;gap:.8rem;border-bottom:1px solid var(--resa-border);padding:1rem}
      #${rootId} .resa-dialog-kicker{margin:.16rem 0 0;color:var(--resa-muted);font-size:.78rem;font-weight:800}
      #${rootId} .resa-dialog-title{margin:0;color:var(--resa-text);font-size:1.05rem;font-weight:600}
      #${rootId} .resa-close{display:grid;place-items:center;width:2rem;height:2rem;border:1px solid var(--resa-border);border-radius:999px;background:#fff;color:var(--resa-muted);cursor:pointer}
      #${rootId} .resa-dialog-reservation-detail{width:min(100%,34rem)}
      #${rootId} .resa-view{display:grid;gap:.72rem;padding:1rem;background:#f8fafc}
      #${rootId} .resa-view-vehicle{display:grid;grid-template-columns:7rem minmax(0,1fr);align-items:center;gap:1.15rem;min-height:8.1rem;border:1px solid var(--resa-border);border-radius:.85rem;background:#fff;padding:1rem;box-shadow:0 12px 28px rgba(15,23,42,.04)}
      #${rootId} .resa-view-photo{display:grid;place-items:center;width:7rem;aspect-ratio:1;border-radius:.72rem;background:linear-gradient(135deg,#f7e8ee,#f3edf0);color:var(--resa-primary);font-size:1rem;font-weight:600;overflow:hidden}
      #${rootId} .resa-view-photo img{width:100%;height:100%;object-fit:cover}
      #${rootId} .resa-view-vehicle-copy{display:grid;gap:.5rem;min-width:0}
      #${rootId} .resa-view-line{display:flex;align-items:center;gap:.62rem;min-width:0;color:var(--resa-muted);font-size:.96rem;font-weight:400;line-height:1.25}
      #${rootId} .resa-view-line strong{display:block;min-width:0;color:var(--resa-text);font-size:1.22rem;font-weight:600;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #${rootId} .resa-view-line-icon{display:grid;place-items:center;width:2.38rem;height:2.38rem;flex:0 0 auto;border-radius:999px;background:#f7e8ee;color:var(--resa-primary)}
      #${rootId} .resa-view-details{display:grid;grid-template-columns:1fr 1fr;gap:.62rem}
      #${rootId} .resa-view-field{display:grid;grid-template-columns:2.65rem minmax(0,1fr);align-items:center;gap:.72rem;min-width:0;min-height:5.3rem;border:1px solid var(--resa-border);border-radius:.78rem;background:#fff;padding:.78rem .9rem;box-shadow:0 10px 24px rgba(15,23,42,.035)}
      #${rootId} .resa-view-field.is-wide{grid-column:1/-1}
      #${rootId} .resa-view-field-icon{display:grid;place-items:center;width:2.65rem;height:2.65rem;border-radius:.72rem;background:#fdf2f8;color:var(--resa-primary)}
      #${rootId} .resa-view-field-copy{display:grid;gap:.16rem;min-width:0}
      #${rootId} .resa-view-field-label{display:block;color:var(--resa-muted);font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:0}
      #${rootId} .resa-view-field strong{display:block;color:var(--resa-text);font-size:1rem;font-weight:600;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #${rootId} .resa-view-note{align-items:start;min-height:5.8rem}
      #${rootId} .resa-view-note p{margin:0;color:var(--resa-text);font-size:1rem;font-weight:600;line-height:1.36;white-space:pre-wrap}
      #${rootId} .resa-view-actions{display:flex;align-items:center;justify-content:center;gap:.55rem;border-top:0;padding:.82rem 1rem;background:#fff}
      #${rootId} .resa-icon-action{display:grid;place-items:center;width:6.8rem;height:3rem;border:1px solid var(--resa-border);border-radius:.65rem;background:#fff;color:var(--resa-muted);cursor:pointer;box-shadow:0 12px 28px rgba(15,23,42,.06)}
      #${rootId} .resa-icon-action svg{width:1.25rem;height:1.25rem}
      #${rootId} .resa-icon-action-primary{border-color:var(--resa-primary);background:var(--resa-primary);color:#fff;box-shadow:0 16px 30px rgb(var(--theme-primary) / .22)}
      #${rootId} .resa-icon-action-danger{color:#b91c1c}
      #${rootId} .resa-form{display:grid;gap:.78rem;padding:1rem}
      #${rootId} .resa-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem}
      #${rootId} label{display:grid;gap:.28rem;color:var(--resa-muted);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #${rootId} input,#${rootId} select,#${rootId} textarea{width:100%;border:1px solid var(--resa-border);border-radius:.5rem;background:#fff;padding:.65rem .72rem;color:var(--resa-text);font:inherit;font-size:.86rem;font-weight:750;text-transform:none}
      #${rootId} textarea{min-height:5.6rem;resize:vertical}
      #${rootId} .resa-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.45rem;border:1px solid var(--resa-border);border-radius:.55rem;background:#f8fafc;padding:.6rem}
      #${rootId} .resa-summary-item:first-child{grid-column:1/-1}
      #${rootId} .resa-summary-item span{display:block;color:var(--resa-muted);font-size:.66rem;font-weight:600;text-transform:uppercase}
      #${rootId} .resa-summary-item strong{display:block;margin-top:.12rem;color:var(--resa-text);font-size:.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .dark #${rootId}{--resa-border:var(--color-surface-700,#334155);--resa-text:#fff;--resa-muted:var(--color-secondary-400,#94a3b8)}
      .dark #${rootId} .resa-card,.dark #${rootId} .resa-button,.dark #${rootId} .resa-product-card,.dark #${rootId} .resa-row,.dark #${rootId} .resa-dialog,.dark #${rootId} input,.dark #${rootId} select,.dark #${rootId} textarea,.dark #${rootId} .resa-selection-panel,.dark #${rootId} .resa-view-field,.dark #${rootId} .resa-view-note,.dark #${rootId} .resa-icon-action{background:var(--color-surface-900,#0f172a);border-color:var(--resa-border)}
      .dark #${rootId} .resa-summary,.dark #${rootId} .resa-month-head,.dark #${rootId} .resa-view-vehicle{background:var(--color-surface-800,#1e293b)}
      @media (max-width:1100px){#${rootId} .resa-vehicles{grid-template-columns:repeat(auto-fill,minmax(min(100%,16.5rem),16.5rem))}}
      @media (max-width:760px){#${rootId}{gap:.85rem}#${rootId} .resa-top{display:grid;align-items:start}#${rootId} .resa-title h1{font-size:1.55rem}#${rootId} .resa-card-header,#${rootId} .resa-card-body{padding:.85rem}#${rootId} .resa-vehicles{grid-template-columns:1fr;gap:.65rem}#${rootId} .resa-product-card{grid-template-columns:5.45rem minmax(0,1fr);min-height:5.65rem;border-radius:.58rem}#${rootId} .resa-product-image{font-size:1.05rem}#${rootId} .resa-product-body{padding:.65rem .72rem}#${rootId} .resa-top .resa-button,#${rootId} .reservation-fast-actions .resa-button{width:100%}#${rootId} .resa-nav-button{width:2.75rem;min-height:2.75rem}#${rootId} .resa-month-head,#${rootId} .resa-month-cell{padding:.38rem;min-height:3.45rem}#${rootId} .resa-mobile-day-slots{grid-template-columns:repeat(2,minmax(0,1fr))}#${rootId} .reservation-mobile-slot-button{min-height:2.8rem;border-radius:.48rem}#${rootId} .resa-slot-time{font-size:.98rem}#${rootId} .resa-slot-meta{font-size:.64rem}#${rootId} .resa-row{grid-template-columns:1fr}#${rootId} .resa-form-grid{grid-template-columns:1fr}#${rootId} .reservation-fast-actions{grid-template-columns:1fr 1fr}#${rootId} .resa-dialog{max-height:82vh}#${rootId} .resa-view-details{grid-template-columns:1fr 1fr}#${rootId} .resa-view-details .resa-view-field:first-child{grid-column:1/-1}}
    `;
    document.head.appendChild(style);
  }

  function mount() {
    if (!isRoute()) return false;
    const root = document.getElementById(rootId);
    if (!root) return false;

    ensureStyle();
    return true;
  }

  function render() {
    if (!mount()) return;

    const root = document.getElementById(rootId);
    if (!root) return;

    if (state.loading && !state.data) {
      root.innerHTML = `<section class="resa-loading">Chargement des véhicules...</section>`;
      return;
    }

    if (state.error) {
      root.innerHTML = `
        <div class="resa-top">
          <div class="resa-title">
            <h1>Réservations véhicules</h1>
            <p>Connexion aux données réservations indisponible</p>
          </div>
        </div>
        <div class="resa-alert">${esc(state.error)}</div>
      `;
      return;
    }

    root.innerHTML = renderContent();
    bind(root);
  }

  function renderContent() {
    const vehicle = selectedVehicle();
    const vehicles = siteVehicles();
    const site = selectedSite();

    return `
      <div class="resa-top">
        <div class="resa-title">
            <h1>Réservations véhicules</h1>
            <p>${esc(site?.name || 'Site actif')} · Planning véhicules</p>
          </div>
      </div>
      <section class="resa-card resa-vehicle-card">
        <header class="resa-card-header">
          <div>
            <h2 class="resa-card-title">Véhicules du site</h2>
            <p class="resa-card-subtitle">Sélectionnez un véhicule pour afficher son planning</p>
          </div>
          <span class="resa-badge resa-card-count">${vehicles.length}</span>
        </header>
        <div class="resa-card-body">
          ${vehicles.length ? `<div class="resa-vehicles">${vehicles.map(renderVehicleCard).join('')}</div>` : `<div class="resa-empty">Aucun véhicule sur ce site.</div>`}
        </div>
      </section>
      ${
        vehicle
          ? `<section class="resa-card resa-planning-card" data-resa-planning>
        <header class="resa-card-header resa-planning-header">
          <button class="resa-button resa-nav-button" type="button" data-prev aria-label="Période précédente">${icon('chevron-left')}</button>
          <div>
            <h2 class="resa-card-title">${state.view === 'month' ? monthLabel(state.month) : dateLabel(state.selectedDate)}</h2>
            <p class="resa-card-subtitle">Planning véhicules</p>
          </div>
          <button class="resa-button resa-nav-button" type="button" data-next aria-label="Période suivante">${icon('chevron-right')}</button>
        </header>
        <div class="resa-card-body resa-grid">
          ${renderToolbar()}
          ${state.view === 'month' ? renderMonth(vehicle) : renderDay(vehicle)}
          ${renderSelectionPanel(vehicle)}
        </div>
      </section>`
          : ''
      }
      ${state.modal ? renderModal() : ''}
    `;
  }

  function renderVehicleCard(vehicle) {
    const currentReservation = currentVehicleReservation(vehicle);
    const busy = Boolean(currentReservation);
    const statusLabel = busy ? `Réservé par ${reservationUserName(currentReservation)}` : 'Disponible';
    const initials = String(vehicle.name || '?')
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part[0] || '')
      .join('')
      .toUpperCase();

    return `
      <button class="resa-product-card${Number(vehicle.id) === Number(selectedVehicle()?.id) ? ' is-active' : ''}" type="button" data-vehicle-id="${esc(vehicle.id)}">
        <span class="resa-product-image">${vehicle.photoUrl ? `<img src="${esc(vehicle.photoUrl)}" alt="${esc(vehicle.name)}" loading="lazy">` : esc(initials || 'V')}</span>
        <span class="resa-product-body">
          <strong class="resa-product-name">${esc(vehicle.name)}</strong>
          <span class="resa-product-meta">${esc(vehicle.description || 'Véhicule du site')}</span>
          <span class="resa-product-status${busy ? ' is-busy' : ''}">${esc(statusLabel)}</span>
        </span>
      </button>
    `;
  }

  function renderToolbar() {
    return `
      <div class="resa-toolbar">
        <div class="resa-segment" role="tablist" aria-label="Vue planning">
          <button type="button" data-view="month" class="${state.view === 'month' ? 'is-active' : ''}">Mois</button>
          <button type="button" data-view="day" class="${state.view === 'day' ? 'is-active' : ''}">Jour</button>
          <button type="button" data-view="today" class="">Aujourd'hui</button>
        </div>
      </div>
    `;
  }

  function renderDay(vehicle) {
    const slots = vehicleDaySlots(vehicle);
    const morning = slots.filter((slot) => slot.period === 'morning');
    const afternoon = slots.filter((slot) => slot.period === 'afternoon');

    return `
      <div class="reservation-day-board resa-day-board">
        <div class="resa-mobile-day-slots">
          ${renderSlotColumn('Matin', morning, 'morning')}
          ${renderSlotColumn('Après-midi', afternoon, 'afternoon')}
        </div>
        <div class="resa-legend" aria-label="Légende">
          <span class="resa-badge"><span style="width:.55rem;height:.55rem;border-radius:999px;background:#16a34a;margin-right:.35rem"></span>Disponible</span>
          <span class="resa-badge"><span style="width:.55rem;height:.55rem;border-radius:999px;background:#dc2626;margin-right:.35rem"></span>Réservé</span>
        </div>
      </div>
    `;
  }

  function renderSlotColumn(title, slots, period) {
    const range = slots.length ? `${slots[0].start} - ${slots[slots.length - 1].end}` : 'Aucun créneau';

    return `
      <section class="resa-mobile-slot-column reservation-day-row-track-${esc(period)}">
        <div class="resa-slot-column-heading"><strong>${esc(title)}</strong><span>${esc(range)}</span></div>
        ${slots.map(renderSlot).join('')}
      </section>
    `;
  }

  function renderSlot(slot) {
    const selected = reservationCellIsSelected(slot);
    const reservation = slot.reservation;
    const reservedVehicleName = reservation
      ? reservationVehicle(reservation)?.name || reservation.title || 'Réservé'
      : '';

    return `
      <button class="reservation-mobile-slot-button reservation-day-cell-button${reservation ? ' is-reserved' : ''}${selected ? ' is-selecting' : ''}" type="button" data-slot-start="${esc(slot.startAt)}" data-slot-end="${esc(slot.endAt)}"${reservation ? ` data-reservation-id="${esc(reservation.id)}"` : ''}>
        <span class="resa-slot-time">${esc(slot.start)}</span>
        <span class="resa-slot-meta">${reservation ? esc(reservedVehicleName) : reservationSelectionCellLabel(slot)}</span>
        ${reservation ? `<span class="resa-slot-owner">Réservé par ${esc(reservationUserName(reservation))}</span>` : ''}
      </button>
    `;
  }

  function renderSelectionPanel(vehicle) {
    if (!state.selection || !vehicle) return '';

    const complete = state.selection.startAt && state.selection.endAt;

    return `
      <section class="resa-selection-panel" data-resa-selection>
        <span>Créneau prêt</span>
        <strong>${esc(timeLabel(state.selection.startAt))}${complete ? ` → ${esc(timeLabel(state.selection.endAt))}` : ''}</strong>
        <p class="resa-card-subtitle">${complete ? 'Valide pour ouvrir la fiche de réservation.' : "Clique sur l'heure de fin."}</p>
        <div class="resa-selection-actions">
          <button class="resa-button" type="button" data-selection-clear>Effacer</button>
          <button class="resa-button resa-button-primary" type="button" data-selection-confirm ${complete ? '' : 'disabled'}>Valider</button>
        </div>
      </section>
    `;
  }

  function renderMonth(vehicle) {
    const days = calendarDays(state.month);
    const heads = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']
      .map((day) => `<div class="resa-month-head">${day}</div>`)
      .join('');
    const hasReservations = days.some((day) => {
      const date = formatDate(day);

      return vehicleReservations(vehicle.id).some((reservation) => sameDate(reservation.startAt, date));
    });

    return `
      <div class="resa-month-board">
        <div class="resa-month-grid" data-resa-calendar>
          ${heads}
          ${days.map((day) => renderMonthDay(day, vehicle)).join('')}
        </div>
        ${hasReservations ? renderMonthLegend() : ''}
      </div>
    `;
  }

  function renderMonthLegend() {
    return `
      <div class="resa-legend" aria-label="Légende planning mois">
        <span class="resa-badge"><span style="width:.55rem;height:.55rem;border-radius:999px;background:#f7b711;margin-right:.35rem"></span>Matin</span>
        <span class="resa-badge"><span style="width:.55rem;height:.55rem;border-radius:999px;background:#95002e;margin-right:.35rem"></span>Après-midi</span>
        <span class="resa-badge"><span style="width:.55rem;height:.55rem;border-radius:999px;background:#000000;margin-right:.35rem"></span>Journée complète</span>
      </div>
    `;
  }

  function renderMonthDay(day, vehicle) {
    const date = formatDate(day);
    const dots = vehicleReservations(vehicle.id)
      .filter((reservation) => sameDate(reservation.startAt, date))
      .slice(0, 3)
      .map(
        (reservation) =>
          `<span class="resa-month-dot resa-month-dot-${esc(reservationPeriodKey(reservation))}"></span>`,
      )
      .join('');
    const muted = day.getMonth() !== state.month.getMonth();

    return `
      <div class="resa-month-cell${muted ? ' is-muted' : ''}">
        <button type="button" data-date="${esc(date)}">
          <strong>${day.getDate()}</strong>
          ${dots ? `<span class="resa-month-dots">${dots}</span>` : ''}
        </button>
      </div>
    `;
  }

  function renderModal() {
    const reservation = state.modal?.reservation || null;
    const vehicle = reservation ? reservationVehicle(reservation) : selectedVehicle();
    const startAt = state.modal?.startAt || reservation?.startAt || `${state.selectedDate}T07:30`;
    const endAt = state.modal?.endAt || reservation?.endAt || `${state.selectedDate}T12:00`;

    if (reservation && (state.modal?.type !== 'form' || !canUpdateReservation(reservation))) {
      return renderReservationDetailsModal(reservation, vehicle);
    }

    return renderReservationFormModal(reservation, vehicle, startAt, endAt);
  }

  function renderReservationViewField(iconName, label, value, extraClass = '') {
    return `
      <div class="resa-view-field ${extraClass}">
        <span class="resa-view-field-icon">${icon(iconName)}</span>
        <span class="resa-view-field-copy">
          <span class="resa-view-field-label">${esc(label)}</span>
          <strong>${esc(value)}</strong>
        </span>
      </div>
    `;
  }

  function renderReservationViewNote(notes) {
    return `
      <div class="resa-view-field resa-view-note is-wide">
        <span class="resa-view-field-icon">${icon('file')}</span>
        <span class="resa-view-field-copy">
          <span class="resa-view-field-label">Note</span>
          <p>${esc(notes || 'Aucune note.')}</p>
        </span>
      </div>
    `;
  }

  function renderReservationDetailsModal(reservation, vehicle) {
    const startAt = reservation?.startAt || '';
    const endAt = reservation?.endAt || '';
    const notes = String(reservation?.notes || '').trim();
    const canEdit = canUpdateReservation(reservation);
    const canDelete = canDeleteReservation(reservation);
    const actions = `
      ${canDelete ? `<button class="resa-icon-action resa-icon-action-danger" type="button" data-delete-reservation="${esc(reservation.id)}" aria-label="Supprimer" title="Supprimer">${icon('trash')}</button>` : ''}
      ${canEdit ? `<button class="resa-icon-action resa-icon-action-primary" type="button" data-edit-reservation="${esc(reservation.id)}" aria-label="Modifier" title="Modifier">${icon('edit')}</button>` : ''}
    `.trim();
    const initials = String(vehicle?.name || '?')
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part[0] || '')
      .join('')
      .toUpperCase();

    return `
      <div class="resa-modal" data-modal-close>
        <section class="resa-dialog resa-dialog-compact resa-dialog-reservation-detail" role="dialog" aria-modal="true" aria-label="Détail réservation">
          <header class="resa-dialog-header">
            <div>
              <h2 class="resa-dialog-title">Réservation</h2>
              <p class="resa-dialog-kicker">${esc(vehicle?.name || 'Véhicule')}</p>
            </div>
            <button class="resa-close" type="button" data-modal-close>${icon('x')}</button>
          </header>
          <div class="resa-view">
            <div class="resa-view-vehicle">
              <span class="resa-view-photo">
                ${vehicle?.photoUrl ? `<img src="${esc(vehicle.photoUrl)}" alt="${esc(vehicle.name)}" loading="lazy">` : esc(initials || 'V')}
              </span>
              <span class="resa-view-vehicle-copy">
                <span class="resa-view-line">
                  <span class="resa-view-line-icon">${icon('truck')}</span>
                  <strong>${esc(vehicle?.name || 'Véhicule')}</strong>
                </span>
                <span class="resa-view-line">
                  <span class="resa-view-line-icon">${icon('calendar')}</span>
                  <span>${esc(dateLabel(startAt))}</span>
                </span>
              </span>
            </div>
            <div class="resa-view-details">
              ${renderReservationViewField('calendar', 'Date', dateLabel(startAt), 'is-wide')}
              ${renderReservationViewField('clock', 'Début', timeLabel(startAt))}
              ${renderReservationViewField('clock', 'Fin', timeLabel(endAt))}
              ${renderReservationViewField('user', 'Réservé par', reservationUserName(reservation), 'is-wide')}
              ${renderReservationViewNote(notes)}
            </div>
          </div>
          ${actions ? `<footer class="resa-view-actions">${actions}</footer>` : ''}
        </section>
      </div>
    `;
  }

  function renderReservationFormModal(reservation, vehicle, startAt, endAt) {
    const isEdit = Boolean(reservation);

    return `
      <div class="resa-modal" data-modal-close>
        <section class="resa-dialog" role="dialog" aria-modal="true" aria-label="${isEdit ? 'Modifier réservation' : 'Nouvelle réservation'}">
          <header class="resa-dialog-header">
            <h2 class="resa-dialog-title">${isEdit ? 'Modifier réservation' : 'Nouvelle réservation'}</h2>
            <button class="resa-close" type="button" data-modal-close>${icon('x')}</button>
          </header>
          <form class="resa-form" data-reservation-form>
            <div class="resa-summary">
              <span class="resa-summary-item"><span>Véhicule</span><strong>${esc(vehicle?.name || 'Véhicule')}</strong></span>
              <span class="resa-summary-item"><span>Début</span><strong>${esc(dateLabel(startAt))} ${esc(timeLabel(startAt))}</strong></span>
              <span class="resa-summary-item"><span>Fin</span><strong>${esc(dateLabel(endAt))} ${esc(timeLabel(endAt))}</strong></span>
            </div>
            <input type="hidden" name="id" value="${esc(reservation?.id || '')}">
            <label>Véhicule
              <select name="vehicleId" required>
                ${siteVehicles()
                  .map(
                    (item) =>
                      `<option value="${esc(item.id)}"${Number(item.id) === Number(vehicle?.id) ? ' selected' : ''}>${esc(item.name)}</option>`,
                  )
                  .join('')}
              </select>
            </label>
            <div class="resa-form-grid">
              <label>Date <input type="date" name="date" value="${esc(String(startAt).slice(0, 10))}" required></label>
              <label>Début <input type="time" name="start" value="${esc(timeLabel(startAt))}" required></label>
              <label>Fin <input type="time" name="end" value="${esc(timeLabel(endAt))}" required></label>
            </div>
            <label>Notes <textarea id="reservation-notes" name="notes" placeholder="Adresse, matériel, précision chantier...">${esc(reservation?.notes || '')}</textarea></label>
            <div class="reservation-fast-actions">
              <button class="resa-button resa-button-primary" type="submit">${icon('save')}${isEdit ? 'Modifier' : 'Créer'}</button>
              ${isEdit && canDeleteReservation(reservation) ? `<button class="resa-button resa-button-danger" type="button" data-delete-reservation="${esc(reservation.id)}">${icon('trash')}Supprimer</button>` : `<button class="resa-button" type="button" data-modal-close>Annuler</button>`}
            </div>
          </form>
        </section>
      </div>
    `;
  }

  function bind(root) {
    root.querySelectorAll('[data-vehicle-id]').forEach((button) => {
      button.addEventListener('click', () => {
        state.selectedVehicleId = Number(button.dataset.vehicleId);
        state.view = 'month';
        state.selection = null;
        render();
        scrollPlanningIntoView();
      });
    });

    root.querySelectorAll('[data-view]').forEach((button) => {
      button.addEventListener('click', () => {
        if (button.dataset.view === 'today') {
          const today = new Date();
          state.selectedDate = formatDate(today);
          state.month = new Date(today.getFullYear(), today.getMonth(), 1);
          state.view = 'day';
          state.selection = null;
          render();
          scrollPlanningIntoView();
          return;
        }

        state.view = button.dataset.view === 'week' ? 'day' : button.dataset.view;
        render();
        if (state.view === 'month' || state.view === 'day') scrollPlanningIntoView();
      });
    });

    root.querySelector('[data-today]')?.addEventListener('click', () => {
      const today = new Date();
      state.selectedDate = formatDate(today);
      state.month = new Date(today.getFullYear(), today.getMonth(), 1);
      state.view = 'day';
      state.selection = null;
      render();
      scrollPlanningIntoView();
    });

    root.querySelector('[data-prev]')?.addEventListener('click', () => movePeriod(-1));
    root.querySelector('[data-next]')?.addEventListener('click', () => movePeriod(1));

    root.querySelectorAll('[data-date]').forEach((button) => {
      button.addEventListener('click', () => {
        state.selectedDate = button.dataset.date;
        state.view = 'day';
        state.selection = null;
        render();
        scrollPlanningIntoView();
      });
    });

    root.querySelectorAll('[data-slot-start]').forEach((button) => {
      button.addEventListener('click', () => {
        if (button.dataset.reservationId) {
          openReservation(Number(button.dataset.reservationId));
          return;
        }

        if (slotDateIsPast(button.dataset.slotStart) && !userIsReservationAdmin()) return;

        chooseSlot(button.dataset.slotStart, button.dataset.slotEnd);
      });
    });

    root.querySelector('[data-selection-clear]')?.addEventListener('click', () => {
      state.selection = null;
      render();
    });

    root.querySelector('[data-selection-confirm]')?.addEventListener('click', () => {
      if (!state.selection?.startAt || !state.selection?.endAt) return;
      if (slotDateIsPast(state.selection.startAt) && !userIsReservationAdmin()) return;

      state.modal = {
        type: 'form',
        startAt: state.selection.startAt,
        endAt: state.selection.endAt,
      };
      render();
    });

    root.querySelectorAll('[data-modal-close]').forEach((node) => {
      node.addEventListener('click', (event) => {
        if (event.target !== node && !node.matches('button')) return;
        state.modal = null;
        render();
      });
    });

    root.querySelector('[data-reservation-form]')?.addEventListener('submit', saveReservation);
    root.querySelectorAll('[data-delete-reservation]').forEach((button) => button.addEventListener('click', deleteReservation));
    root.querySelectorAll('[data-edit-reservation]').forEach((button) => {
      button.addEventListener('click', () => {
        const reservation = reservations().find((item) => Number(item.id) === Number(button.dataset.editReservation));
        if (!reservation || !canUpdateReservation(reservation)) return;

        state.modal = { type: 'form', reservation };
        render();
      });
    });
  }

  function movePeriod(direction) {
    if (state.view === 'month') {
      state.month = new Date(state.month.getFullYear(), state.month.getMonth() + direction, 1);
      load({ force: true });
      return;
    }

    const selected = addDays(parseDate(state.selectedDate), direction);
    state.selectedDate = formatDate(selected);
    state.month = new Date(selected.getFullYear(), selected.getMonth(), 1);
    state.selection = null;
    render();
  }

  function chooseSlot(startAt, endAt) {
    if (slotDateIsPast(startAt) && !userIsReservationAdmin()) return;

    if (!state.selection || state.selection.endAt || startAt <= state.selection.startAt) {
      state.selection = { startAt, endAt: null };
      render();
      return;
    }

    state.selection.endAt = endAt;
    render();
    scrollSelectionIntoView();
  }

  function openReservation(id) {
    const reservation = reservations().find((item) => Number(item.id) === Number(id));
    if (!reservation) return;
    state.modal = { type: 'view', reservation };
    render();
  }

  async function saveReservation(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const id = Number(data.get('id') || 0);
    const date = String(data.get('date') || state.selectedDate);
    const reservation = id ? reservations().find((item) => Number(item.id) === id) : null;
    if (reservation && !canUpdateReservation(reservation)) return;
    if (!reservation && !canCreateReservationForDate(date)) return;

    const payload = {
      id: id || undefined,
      vehicleId: Number(data.get('vehicleId') || selectedVehicle()?.id),
      title: 'Réservation véhicule',
      startAt: `${date}T${data.get('start')}`,
      endAt: `${date}T${data.get('end')}`,
      notes: String(data.get('notes') || ''),
    };

    try {
      await request(id ? 'update_reservation' : 'create_reservation', {
        method: 'POST',
        body: payload,
      });
      state.modal = null;
      state.selection = null;
      await load({ force: true });
    } catch (error) {
      alert(error.message || 'Enregistrement impossible');
    }
  }

  async function deleteReservation(event) {
    const id = Number(event.currentTarget.dataset.deleteReservation || 0);
    if (!id || !confirm('Supprimer cette réservation ?')) return;

    try {
      await request('delete_reservation', { method: 'POST', body: { id } });
      state.modal = null;
      await load({ force: true });
    } catch (error) {
      alert(error.message || 'Suppression impossible');
    }
  }

  async function load(options = {}) {
    if (!isRoute()) return;
    if (state.loading && !options.force) return;

    const sequence = ++loadSequence;
    const range = dateRange();
    state.loading = true;
    state.error = '';
    render();

    try {
      const payload = await request('bootstrap', {
        query: {
          siteId: siteId() || '',
          from: range.from,
          to: range.to,
        },
      });

      if (sequence !== loadSequence) return;

      state.data = payload;
      const vehicles = siteVehicles();
      if (!vehicles.some((vehicle) => Number(vehicle.id) === Number(state.selectedVehicleId))) {
        state.selectedVehicleId = null;
      }
    } catch (error) {
      if (sequence === loadSequence) state.error = error.message || 'Connexion aux données réservations indisponible';
    } finally {
      if (sequence !== loadSequence) return;
      state.loading = false;
      render();
    }
  }

  function vehicleDefaultDayHours(vehicle) {
    const site = selectedSite();
    return {
      dayStart: vehicle?.dayStartTime || '06:00',
      dayEnd: vehicle?.dayEndTime || '19:30',
      daySplit: site?.hours?.morningEnd || '12:00',
    };
  }

  function vehicleDaySlots(vehicle) {
    const hours = vehicleDefaultDayHours(vehicle);

    return makeSlots(hours.dayStart, hours.dayEnd, hours.daySplit, vehicle);
  }

  function makeSlots(start, end, split, vehicle) {
    const slots = [];
    let cursor = timeMinutes(start);
    const finish = timeMinutes(end);
    const splitMinute = timeMinutes(split || '12:00');

    while (cursor < finish) {
      const next = Math.min(cursor + 30, finish);
      const startAt = `${state.selectedDate}T${minutesTime(cursor)}`;
      const endAt = `${state.selectedDate}T${minutesTime(next)}`;
      slots.push({
        period: cursor < splitMinute ? 'morning' : 'afternoon',
        start: minutesTime(cursor),
        end: minutesTime(next),
        startAt,
        endAt,
        reservation: reservationForSlot(vehicle.id, startAt, endAt),
      });
      cursor = next;
    }

    return slots;
  }

  function reservationForSlot(vehicleId, startAt, endAt) {
    return (
      vehicleReservations(vehicleId).find(
        (reservation) => reservation.startAt < endAt && reservation.endAt > startAt,
      ) || null
    );
  }

  function reservationPeriodKey(reservation) {
    const start = timeMinutes(timeLabel(reservation.startAt));
    const end = timeMinutes(timeLabel(reservation.endAt));

    if (start < timeMinutes('12:00') && end > timeMinutes('13:00')) {
      return 'day';
    }

    return start < timeMinutes('12:00') ? 'morning' : 'afternoon';
  }

  function reservationCellIsSelected(slot) {
    const selection = state.selection;
    if (!selection?.startAt) return false;
    if (!selection.endAt) return slot.startAt === selection.startAt;

    return slot.startAt >= selection.startAt && slot.endAt <= selection.endAt;
  }

  function reservationSelectionCellLabel(slot) {
    const selection = state.selection;
    if (!selection?.startAt || !reservationCellIsSelected(slot)) return `Fin ${slot.end}`;
    if (!selection.endAt) return slot.startAt === selection.startAt ? 'Début choisi' : `Fin ${slot.end}`;
    if (slot.startAt === selection.startAt) return 'Début';
    if (slot.endAt === selection.endAt) return 'Fin';

    return 'Inclus';
  }

  function calendarDays(month) {
    const first = new Date(month.getFullYear(), month.getMonth(), 1);
    const last = new Date(month.getFullYear(), month.getMonth() + 1, 0);
    const start = addDays(first, -((first.getDay() + 6) % 7));
    const end = addDays(last, 6 - ((last.getDay() + 6) % 7));
    const days = [];
    const current = new Date(start);

    while (current <= end) {
      days.push(new Date(current));
      current.setDate(current.getDate() + 1);
    }

    return days;
  }

  function currentVehicleReservation(vehicle) {
    const now = new Date();
    const value = now.toISOString().slice(0, 16);

    return (
      vehicleReservations(vehicle.id).find(
        (reservation) => reservation.startAt <= value && reservation.endAt >= value,
      ) || null
    );
  }

  function sameDate(value, date) {
    return String(value || '').slice(0, 10) === date;
  }

  function timeMinutes(time) {
    const [hours, minutes] = String(time || '00:00')
      .split(':')
      .map(Number);
    return Number(hours || 0) * 60 + Number(minutes || 0);
  }

  function minutesTime(minutes) {
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
  }

  function icon(name) {
    const paths = {
      plus: '<path d="M12 5v14M5 12h14"></path>',
      'chevron-left': '<path d="m15 18-6-6 6-6"></path>',
      'chevron-right': '<path d="m9 18 6-6-6-6"></path>',
      x: '<path d="M18 6 6 18M6 6l12 12"></path>',
      save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path><path d="M17 21v-8H7v8M7 3v5h8"></path>',
      trash: '<path d="M3 6h18M8 6V4h8v2M6 6l1 15h10l1-15"></path>',
      edit: '<path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path>',
      truck: '<path d="M10 17h4V5H2v12h3"></path><path d="M14 8h4l4 4v5h-3"></path><circle cx="7.5" cy="17.5" r="2.5"></circle><circle cx="16.5" cy="17.5" r="2.5"></circle>',
      calendar: '<path d="M8 2v4M16 2v4M3 10h18"></path><rect x="3" y="4" width="18" height="18" rx="2"></rect>',
      clock: '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>',
      user: '<path d="M20 21a8 8 0 0 0-16 0"></path><circle cx="12" cy="7" r="4"></circle>',
      file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"></path><path d="M14 2v6h6M8 13h8M8 17h5"></path>',
    };

    return `<svg viewBox="0 0 24 24" aria-hidden="true">${paths[name] || paths.plus}</svg>`;
  }

  function scheduleMount() {
    if (mountTimer) window.clearTimeout(mountTimer);
    mountTimer = window.setTimeout(() => {
      mountTimer = null;
      if (!mount()) return;
      if (!state.data) load({ force: true });
      else render();
    }, 0);
  }

  function boot() {
    scheduleMount();
    routeEvents.forEach((eventName) => window.addEventListener(eventName, scheduleMount));
    window.addEventListener(activeSiteEvent, () => load({ force: true }));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();

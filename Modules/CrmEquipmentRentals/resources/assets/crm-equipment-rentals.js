(() => {
  const api = '/api/equipment-rentals';
  const rootId = 'crm-equipment-rentals-module';
  const styleId = 'crm-equipment-rentals-style';
  const activeSiteEvent = 'crm:active-site-changed';
  const activeSiteStorageKey = 'crm:active-site-id';
  const routeEvents = ['popstate', 'crm:navigation', 'crm:route-changed'];

  const state = {
    data: null,
    loading: false,
    error: '',
    selectedItemId: null,
    selectedCategoryId: 'all',
    selectedDate: formatDate(new Date()),
    month: new Date(new Date().getFullYear(), new Date().getMonth(), 1),
    view: 'month',
    modal: null,
  };

  let mountTimer = null;
  let loadSequence = 0;

  function isRoute() {
    const path = window.location.pathname.replace(/\/+$/, '') || '/';
    return path === '/locations-materiel' || path.startsWith('/locations-materiel/');
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

  function scrollPlanningIntoView() {
    window.requestAnimationFrame(() => {
      const target =
        document.querySelector(`#${rootId} [data-rent-calendar]`) ||
        document.querySelector(`#${rootId} [data-rent-planning]`);

      target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
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

  function siteItems() {
    const id = siteId();

    return (state.data?.equipmentItems || [])
      .filter((item) => Number(item.siteId) === id && item.active !== false)
      .filter(
        (item) => state.selectedCategoryId === 'all' || Number(item.categoryId) === Number(state.selectedCategoryId),
      );
  }

  function siteItemsWithoutCategory() {
    const id = siteId();

    return (state.data?.equipmentItems || []).filter((item) => Number(item.siteId) === id && item.active !== false);
  }

  function selectedItem() {
    const items = siteItems();
    if (!state.selectedItemId) return null;

    return items.find((item) => Number(item.id) === Number(state.selectedItemId)) || null;
  }

  function rentals() {
    return state.data?.equipmentRentals || [];
  }

  function itemRentals(itemId) {
    return rentals()
      .filter((rental) => Number(rental.equipmentItemId) === Number(itemId))
      .sort((a, b) => String(a.startAt).localeCompare(String(b.startAt)));
  }

  function rentalItem(rental) {
    return (
      (state.data?.equipmentItems || []).find((item) => Number(item.id) === Number(rental.equipmentItemId)) || null
    );
  }

  function permissions() {
    return new Set(Array.isArray(state.data?.user?.permissions) ? state.data.user.permissions : []);
  }

  function canDeleteRental(rental) {
    const user = state.data?.user || {};
    const access = permissions();

    return (
      access.has('equipment_rentals.delete_any') ||
      (Number(rental.userId) === Number(user.id) && access.has('equipment_rentals.delete_own'))
    );
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
      throw new Error(payload.error || 'Module location matériel indisponible');
    }

    return payload;
  }

  function dateRange() {
    const from = new Date(state.month.getFullYear(), state.month.getMonth(), 1);
    const to = new Date(state.month.getFullYear(), state.month.getMonth() + 1, 0);

    return {
      from: formatDate(addDays(from, -7)),
      to: formatDate(addDays(to, 14)),
    };
  }

  function ensureStyle() {
    if (document.getElementById(styleId)) return;

    const style = document.createElement('style');
    style.id = styleId;
    style.textContent = `
      #${rootId}{--rent-primary:rgb(var(--theme-primary));--rent-border:var(--color-surface-200,#e4e9f1);--rent-soft:#f7f9fc;--rent-text:var(--color-secondary-900,#223957);--rent-muted:var(--color-secondary-500,#64748b);--rent-green:#16a34a;--rent-red:#dc2626;--rent-blue:#4f6df5;display:grid;gap:1rem}
      #${rootId} *{box-sizing:border-box}
      #${rootId} svg{width:1.05rem;height:1.05rem;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} .rent-top{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem}
      #${rootId} .rent-title h1{margin:0;color:var(--rent-text);font-size:1.8rem;line-height:1.08;font-weight:600;letter-spacing:0}
      #${rootId} .rent-title p{margin:.35rem 0 0;color:var(--rent-muted);font-size:.92rem;font-weight:700}
      #${rootId} .rent-button{display:inline-flex;align-items:center;justify-content:center;gap:.42rem;min-height:2.45rem;border:1px solid var(--rent-border);border-radius:.5rem;background:#fff;padding:.58rem .9rem;color:var(--rent-text);font-size:.84rem;font-weight:900;text-decoration:none;cursor:pointer;box-shadow:0 10px 24px rgba(15,23,42,.04)}
      #${rootId} .rent-button-primary{border-color:transparent;background:var(--rent-primary);color:#fff}
      #${rootId} .rent-button-danger{color:#b91c1c}
      #${rootId} .rent-card{border:1px solid var(--rent-border);border-radius:1rem;background:#fff;box-shadow:0 16px 42px rgba(15,23,42,.055)}
      #${rootId} .rent-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:.8rem;border-bottom:1px solid var(--rent-border);padding:.92rem 1rem}
      #${rootId} .rent-card-title{margin:0;color:var(--rent-text);font-size:1rem;font-weight:600}
      #${rootId} .rent-card-subtitle{margin:.18rem 0 0;color:var(--rent-muted);font-size:.78rem;font-weight:750}
      #${rootId} .rent-card-body{display:grid;gap:.85rem;padding:1rem}
      #${rootId} .rent-planning-header{display:grid;grid-template-columns:2.75rem minmax(0,1fr) 2.75rem;align-items:center;text-align:center}
      #${rootId} .rent-planning-card{scroll-margin-top:5.75rem}
      #${rootId} .rent-planning-header>div{min-width:0}
      #${rootId} .rent-nav-button{width:2.75rem;min-height:2.75rem;padding:0;border-radius:.65rem}
      #${rootId} .rent-nav-button svg{margin:0}
      #${rootId} .rent-resources{display:grid;gap:.85rem}
      #${rootId} .rent-resources-head{display:flex;align-items:center;justify-content:flex-end;gap:.75rem}
      #${rootId} .rent-items{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.85rem}
      #${rootId} .rent-product-card{position:relative;display:flex;min-width:0;flex-direction:column;overflow:hidden;border:1px solid var(--rent-border);border-radius:.75rem;background:#fff;text-align:left;cursor:pointer;box-shadow:0 14px 34px rgba(15,23,42,.055);transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
      #${rootId} .rent-product-card:hover,#${rootId} .rent-product-card.is-active{border-color:rgb(var(--theme-primary) / .55);transform:translateY(-1px);box-shadow:0 16px 36px rgba(15,23,42,.08)}
      #${rootId} .rent-product-card:focus-visible{outline:3px solid rgb(var(--theme-primary) / .2);outline-offset:2px}
      #${rootId} .rent-product-image{position:relative;display:grid;place-items:center;aspect-ratio:4/3;background:var(--color-surface-100,#f1f5f9);color:var(--rent-primary);font-size:1.35rem;font-weight:600;overflow:hidden}
      #${rootId} .rent-product-image img{width:100%;height:100%;object-fit:cover}
      #${rootId} .rent-dot{position:absolute;right:.62rem;top:.62rem;width:.72rem;height:.72rem;border-radius:999px;background:var(--rent-green);box-shadow:0 0 0 3px #fff}
      #${rootId} .rent-dot.is-busy{background:var(--rent-red)}
      #${rootId} .rent-product-body{display:flex;min-height:3.9rem;flex:1;flex-direction:column;gap:.35rem;padding:.65rem .75rem .7rem}
      #${rootId} .rent-product-name{display:-webkit-box;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2;color:var(--rent-text);font-size:.9rem;font-weight:900;line-height:1.2}
      #${rootId} .rent-product-meta{display:flex;justify-content:flex-end;margin-top:auto;color:var(--rent-primary);font-size:.7rem;font-weight:900}
      #${rootId} .rent-toolbar{display:grid;gap:.75rem}
      #${rootId} .rent-legend{display:flex;align-items:center;justify-content:center;gap:.65rem;flex-wrap:wrap}
      #${rootId} .rent-segment{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.25rem;border:1px solid var(--rent-border);border-radius:.8rem;background:var(--rent-soft);padding:.22rem}
      #${rootId} .rent-segment button{min-width:0;border:0;border-radius:.58rem;background:transparent;padding:.55rem .85rem;color:var(--rent-muted);font-size:.78rem;font-weight:900;cursor:pointer}
      #${rootId} .rent-segment button.is-active{background:var(--rent-primary);color:#fff;box-shadow:0 8px 16px rgb(var(--theme-primary) / .18)}
      #${rootId} select,#${rootId} input,#${rootId} textarea{width:100%;border:1px solid var(--rent-border);border-radius:.5rem;background:#fff;padding:.65rem .72rem;color:var(--rent-text);font:inherit;font-size:.86rem;font-weight:750}
      #${rootId} textarea{min-height:5.6rem;resize:vertical}
      #${rootId} label{display:grid;gap:.28rem;color:var(--rent-muted);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #${rootId} .rent-day-board{display:grid;gap:.75rem}
      #${rootId} .rent-periods{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.65rem}
      #${rootId} .rent-period{display:grid;gap:.18rem;min-height:4.4rem;border:0;border-radius:.6rem;color:#fff;padding:.75rem;text-align:left;cursor:pointer;box-shadow:0 12px 24px rgba(15,23,42,.12)}
      #${rootId} .rent-period strong{font-size:.95rem;font-weight:600}
      #${rootId} .rent-period span{font-size:.72rem;font-weight:850;opacity:.95}
      #${rootId} .rent-period-morning{background:#14b8a6}
      #${rootId} .rent-period-afternoon{background:#ff5c57}
      #${rootId} .rent-period-day{background:#4f6df5}
      #${rootId} .rent-period.is-reserved{background:var(--rent-red)}
      #${rootId} .rent-month-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));scroll-margin-top:5.75rem;border:1px solid var(--rent-border);border-radius:0 0 1rem 1rem;overflow:hidden}
      #${rootId} .rent-month-head,#${rootId} .rent-month-cell{min-height:4.2rem;border-right:1px solid var(--rent-border);border-bottom:1px solid var(--rent-border);padding:.52rem}
      #${rootId} .rent-month-head{min-height:auto;background:#f8fafc;color:var(--rent-muted);font-size:.72rem;font-weight:600;text-align:center;text-transform:uppercase}
      #${rootId} .rent-month-cell:nth-child(7n){border-right:0}
      #${rootId} .rent-month-cell button{display:grid;gap:.25rem;width:100%;height:100%;border:0;background:transparent;color:var(--rent-text);text-align:left;cursor:pointer}
      #${rootId} .rent-month-cell.is-muted{background:#fafafa;color:#94a3b8}
      #${rootId} .rent-month-dots{display:flex;align-items:center;justify-content:center;gap:.24rem;margin-top:auto}
      #${rootId} .rent-month-dot{width:.42rem;height:.42rem;border-radius:999px;background:var(--rent-primary)}
      #${rootId} .rent-month-dot-morning{background:#14b8a6}
      #${rootId} .rent-month-dot-afternoon{background:#ff5c57}
      #${rootId} .rent-month-dot-day{background:#4f6df5}
      #${rootId} .rent-list{display:grid;gap:.55rem}
      #${rootId} .rent-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.8rem;align-items:center;border:1px solid var(--rent-border);border-radius:.5rem;padding:.72rem .8rem;background:#fff}
      #${rootId} .rent-row strong{display:block;color:var(--rent-text);font-size:.88rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #${rootId} .rent-row span{display:block;margin-top:.12rem;color:var(--rent-muted);font-size:.74rem;font-weight:750}
      #${rootId} .rent-badge{display:inline-flex;align-items:center;border-radius:999px;background:#f7e8ee;padding:.25rem .6rem;color:var(--rent-primary);font-size:.72rem;font-weight:600}
      #${rootId} .rent-empty,#${rootId} .rent-loading{display:grid;place-items:center;min-height:6rem;border:1px dashed var(--rent-border);border-radius:.55rem;color:var(--rent-muted);font-weight:850;text-align:center;padding:1rem}
      #${rootId} .rent-alert{border:1px solid #fecaca;border-radius:.55rem;background:#fff1f2;padding:.8rem;color:#b91c1c;font-weight:850}
      #${rootId} .rent-modal{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;background:rgba(15,23,42,.52);padding:1rem}
      #${rootId} .rent-dialog{width:min(100%,34rem);max-height:86vh;overflow:auto;border-radius:.7rem;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.28)}
      #${rootId} .rent-dialog-header{display:flex;align-items:center;justify-content:space-between;gap:.8rem;border-bottom:1px solid var(--rent-border);padding:1rem}
      #${rootId} .rent-dialog-title{margin:0;color:var(--rent-text);font-size:1.05rem;font-weight:600}
      #${rootId} .rent-close{display:grid;place-items:center;width:2rem;height:2rem;border:1px solid var(--rent-border);border-radius:999px;background:#fff;color:var(--rent-muted);cursor:pointer}
      #${rootId} .rent-form{display:grid;gap:.78rem;padding:1rem}
      #${rootId} .rent-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem}
      #${rootId} .rent-summary{display:grid;grid-template-columns:4rem minmax(0,1fr);gap:.7rem;align-items:center;border:1px solid var(--rent-border);border-radius:.55rem;background:#f8fafc;padding:.6rem}
      #${rootId} .rent-summary-image{display:grid;place-items:center;aspect-ratio:1;border-radius:.45rem;background:#f7e8ee;color:var(--rent-primary);font-weight:600;overflow:hidden}
      #${rootId} .rent-summary-image img{width:100%;height:100%;object-fit:cover}
      #${rootId} .rent-summary strong{display:block;color:var(--rent-text);font-size:.92rem;font-weight:600}
      #${rootId} .rent-summary span{display:block;margin-top:.14rem;color:var(--rent-muted);font-size:.74rem;font-weight:750}
      #${rootId} .rent-actions{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-top:.4rem}
      .dark #${rootId}{--rent-border:var(--color-surface-700,#334155);--rent-text:#fff;--rent-muted:var(--color-secondary-400,#94a3b8)}
      .dark #${rootId} .rent-card,.dark #${rootId} .rent-button,.dark #${rootId} .rent-product-card,.dark #${rootId} .rent-row,.dark #${rootId} .rent-dialog,.dark #${rootId} input,.dark #${rootId} select,.dark #${rootId} textarea{background:var(--color-surface-900,#0f172a);border-color:var(--rent-border)}
      .dark #${rootId} .rent-summary,.dark #${rootId} .rent-month-head{background:var(--color-surface-800,#1e293b)}
      @media (max-width:1100px){#${rootId} .rent-items{grid-template-columns:repeat(3,minmax(0,1fr))}}
      @media (max-width:760px){#${rootId}{gap:.85rem}#${rootId} .rent-top{display:grid;align-items:start}#${rootId} .rent-title h1{font-size:1.82rem;line-height:1.08}#${rootId} .rent-items{grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}#${rootId} .rent-product-image{aspect-ratio:4/3;font-size:1.15rem}#${rootId} .rent-product-body{padding:.65rem .75rem .7rem}#${rootId} .rent-segment{width:100%}#${rootId} .rent-top .rent-button,#${rootId} .rent-actions .rent-button{width:100%}#${rootId} .rent-nav-button{width:2.75rem;min-height:2.75rem}#${rootId} .rent-periods{grid-template-columns:1fr}#${rootId} .rent-month-head,#${rootId} .rent-month-cell{padding:.38rem .18rem;min-height:3.85rem;text-align:center}#${rootId} .rent-month-cell button{align-items:center;text-align:center}#${rootId} .rent-row{grid-template-columns:1fr}#${rootId} .rent-form-grid{grid-template-columns:1fr}#${rootId} .rent-actions{grid-template-columns:1fr 1fr}#${rootId} .rent-dialog{max-height:82vh}}
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
      root.innerHTML = `<section class="rent-loading">Chargement du matériel...</section>`;
      return;
    }

    if (state.error) {
      root.innerHTML = `
        <div class="rent-top">
          <div class="rent-title">
            <h1>Location matériel</h1>
            <p>Connexion aux données locations indisponible</p>
          </div>
        </div>
        <div class="rent-alert">${esc(state.error)}</div>
      `;
      return;
    }

    root.innerHTML = renderContent();
    bind(root);
  }

  function renderContent() {
    const item = selectedItem();
    const items = siteItems();
    const site = selectedSite();

    return `
      <div class="rent-top">
        <div class="rent-title">
          <h1>Location matériel</h1>
          <p>${esc(site?.name || 'Site actif')} · Planning matériel</p>
        </div>
        ${item ? `<button class="rent-button rent-button-primary" type="button" data-rent-new>${icon('plus')}Nouvelle réservation</button>` : ''}
      </div>
      ${renderResources(items)}
      ${item ? renderPlanningSections(item) : ''}
      ${state.modal ? renderModal() : ''}
    `;
  }

  function renderResources(items) {
    return `
      <section class="rent-resources" data-rent-resources>
        <div class="rent-resources-head">
          <span class="rent-badge">${items.length}/${siteItemsWithoutCategory().length}</span>
        </div>
        <select data-category-filter aria-label="Catégorie matériel">
          <option value="all">Toutes catégories</option>
          ${(state.data?.equipmentCategories || []).map((category) => `<option value="${esc(category.id)}"${String(category.id) === String(state.selectedCategoryId) ? ' selected' : ''}>${esc(category.name)}</option>`).join('')}
        </select>
        ${items.length ? `<div class="rent-items">${items.map(renderItemCard).join('')}</div>` : `<div class="rent-empty">Aucun matériel sur ce site.</div>`}
      </section>
    `;
  }

  function renderPlanningSections(item) {
    return `
      <section class="rent-card rent-planning-card" data-rent-planning>
        <header class="rent-card-header rent-planning-header">
          <button class="rent-button rent-nav-button" type="button" data-prev aria-label="Période précédente">${icon('chevron-left')}</button>
          <div>
            <h2 class="rent-card-title">${state.view === 'month' ? monthLabel(state.month) : dateLabel(state.selectedDate)}</h2>
            <p class="rent-card-subtitle">Planning ${esc(item.name || 'matériel')}</p>
          </div>
          <button class="rent-button rent-nav-button" type="button" data-next aria-label="Période suivante">${icon('chevron-right')}</button>
        </header>
        <div class="rent-card-body">
          ${renderToolbar()}
          ${state.view === 'month' ? renderMonth(item) : renderDay(item)}
        </div>
      </section>
    `;
  }

  function renderItemCard(item) {
    const busy = isItemBusy(item);
    const initials = String(item.name || '?')
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part[0] || '')
      .join('')
      .toUpperCase();

    return `
      <button class="rent-product-card${Number(item.id) === Number(selectedItem()?.id) ? ' is-active' : ''}" type="button" data-item-id="${esc(item.id)}">
        <span class="rent-product-image">
          ${item.photoUrl ? `<img src="${esc(item.photoUrl)}" alt="${esc(item.name)}" loading="lazy">` : esc(initials || 'M')}
          <span class="rent-dot${busy ? ' is-busy' : ''}" aria-label="${busy ? 'Réservé' : 'Disponible'}"></span>
        </span>
        <span class="rent-product-body">
          <strong class="rent-product-name">${esc(item.name)}</strong>
          <span class="rent-product-meta">${esc(priceLabel(item))}</span>
        </span>
      </button>
    `;
  }

  function renderToolbar() {
    return `
      <div class="rent-toolbar">
        <div class="rent-segment" role="tablist" aria-label="Vue planning">
          <button type="button" data-view="month" class="${state.view === 'month' ? 'is-active' : ''}">Mois</button>
          <button type="button" data-view="day" class="${state.view === 'day' ? 'is-active' : ''}">Jour</button>
          <button type="button" data-view="today" class="">Aujourd'hui</button>
        </div>
      </div>
    `;
  }

  function renderDay(item) {
    const periods = rentalPeriods(item);

    return `
      <div class="rent-day-board">
        <div class="rent-periods">
          ${periods.map((period) => renderPeriod(item, period)).join('')}
        </div>
        <div class="rent-legend" aria-label="Légende">
          <span class="rent-badge"><span style="width:.55rem;height:.55rem;border-radius:999px;background:#14b8a6;margin-right:.35rem"></span>Matin</span>
          <span class="rent-badge"><span style="width:.55rem;height:.55rem;border-radius:999px;background:#ff5c57;margin-right:.35rem"></span>Après-midi</span>
          <span class="rent-badge"><span style="width:.55rem;height:.55rem;border-radius:999px;background:#4f6df5;margin-right:.35rem"></span>Journée complète</span>
        </div>
      </div>
    `;
  }

  function renderPeriod(item, period) {
    const rental = rentalForPeriod(item, period);

    return `
      <button class="rent-period rent-period-${esc(period.key)}${rental ? ' is-reserved' : ''}" type="button" data-period="${esc(period.key)}"${rental ? ` data-rental-id="${esc(rental.id)}"` : ''}>
        <strong>${esc(period.label)}</strong>
        <span>${esc(period.time)}</span>
        <span>${rental ? esc(rentalItem(rental)?.name || rental.title || 'Réservée') : 'Disponible'}</span>
      </button>
    `;
  }

  function renderMonth(item) {
    const days = calendarDays(state.month);
    const heads = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']
      .map((day) => `<div class="rent-month-head">${day}</div>`)
      .join('');

    return `
      <div class="rent-month-grid" data-rent-calendar>
        ${heads}
        ${days.map((day) => renderMonthDay(day, item)).join('')}
      </div>
    `;
  }

  function renderMonthDay(day, item) {
    const date = formatDate(day);
    const dots = itemRentals(item.id)
      .filter((rental) => sameDate(rental.startAt, date))
      .slice(0, 3)
      .map((rental) => `<span class="rent-month-dot rent-month-dot-${esc(periodKey(rental))}"></span>`)
      .join('');
    const muted = day.getMonth() !== state.month.getMonth();

    return `
      <div class="rent-month-cell${muted ? ' is-muted' : ''}">
        <button type="button" data-date="${esc(date)}">
          <strong>${day.getDate()}</strong>
          ${dots ? `<span class="rent-month-dots">${dots}</span>` : ''}
        </button>
      </div>
    `;
  }

  function renderModal() {
    const rental = state.modal?.rental || null;
    const item = rental ? rentalItem(rental) : selectedItem();
    const date = String(state.modal?.date || rental?.startAt || state.selectedDate).slice(0, 10);
    const period = state.modal?.period || periodKey(rental) || 'morning';
    const isEdit = Boolean(rental);

    return `
      <div class="rent-modal" data-modal-close>
        <section class="rent-dialog" role="dialog" aria-modal="true" aria-label="${isEdit ? 'Modifier location' : 'Location rapide'}">
          <header class="rent-dialog-header">
            <h2 class="rent-dialog-title">${isEdit ? 'Modifier location' : 'Location rapide'}</h2>
            <button class="rent-close" type="button" data-modal-close>${icon('x')}</button>
          </header>
          <form class="rent-form" data-rental-form>
            <div class="rent-summary">
              <span class="rent-summary-image">${item?.photoUrl ? `<img src="${esc(item.photoUrl)}" alt="${esc(item.name)}">` : esc(itemInitials(item))}</span>
              <span><strong>${esc(item?.name || 'Matériel')}</strong><span>${esc(dateLabel(date))} · ${esc(periodName(period))}</span></span>
            </div>
            <input type="hidden" name="id" value="${esc(rental?.id || '')}">
            <label>Matériel
              <select name="equipmentItemId" required>
                ${siteItems()
                  .map(
                    (entry) =>
                      `<option value="${esc(entry.id)}"${Number(entry.id) === Number(item?.id) ? ' selected' : ''}>${esc(entry.name)}</option>`,
                  )
                  .join('')}
              </select>
            </label>
            <div class="rent-form-grid">
              <label>Date <input type="date" name="date" value="${esc(date)}" required></label>
              <label>Période
                <select name="period" required>
                  ${rentalPeriods(item)
                    .map(
                      (entry) =>
                        `<option value="${esc(entry.key)}"${entry.key === period ? ' selected' : ''}>${esc(entry.label)}</option>`,
                    )
                    .join('')}
                </select>
              </label>
            </div>
            <label>Notes <textarea name="notes" placeholder="Client, chantier, précision...">${esc(rental?.notes || '')}</textarea></label>
            <div class="rent-actions">
              <button class="rent-button rent-button-primary" type="submit">${icon('save')}${isEdit ? 'Modifier' : 'Créer'}</button>
              ${isEdit && canDeleteRental(rental) ? `<button class="rent-button rent-button-danger" type="button" data-delete-rental="${esc(rental.id)}">${icon('trash')}Supprimer</button>` : `<button class="rent-button" type="button" data-modal-close>Annuler</button>`}
            </div>
          </form>
        </section>
      </div>
    `;
  }

  function bind(root) {
    root.querySelector('[data-category-filter]')?.addEventListener('change', (event) => {
      state.selectedCategoryId = event.currentTarget.value;
      state.selectedItemId = null;
      render();
    });

    root.querySelectorAll('[data-item-id]').forEach((button) => {
      button.addEventListener('click', () => {
        state.selectedItemId = Number(button.dataset.itemId);
        state.view = 'month';
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
          render();
          return;
        }

        state.view = button.dataset.view === 'week' ? 'day' : button.dataset.view;
        render();
        if (state.view === 'month') scrollPlanningIntoView();
      });
    });

    root.querySelector('[data-today]')?.addEventListener('click', () => {
      const today = new Date();
      state.selectedDate = formatDate(today);
      state.month = new Date(today.getFullYear(), today.getMonth(), 1);
      state.view = 'day';
      render();
    });

    root.querySelector('[data-prev]')?.addEventListener('click', () => movePeriod(-1));
    root.querySelector('[data-next]')?.addEventListener('click', () => movePeriod(1));
    root.querySelector('[data-rent-new]')?.addEventListener('click', () => openNewRental());

    root.querySelectorAll('[data-date]').forEach((button) => {
      button.addEventListener('click', () => {
        state.selectedDate = button.dataset.date;
        state.view = 'day';
        render();
      });
    });

    root.querySelectorAll('[data-period]').forEach((button) => {
      button.addEventListener('click', () => {
        if (button.dataset.rentalId) {
          openRental(Number(button.dataset.rentalId));
          return;
        }

        state.modal = { type: 'form', date: state.selectedDate, period: button.dataset.period };
        render();
      });
    });

    root.querySelectorAll('[data-modal-close]').forEach((node) => {
      node.addEventListener('click', (event) => {
        if (event.target !== node && !node.matches('button')) return;
        state.modal = null;
        render();
      });
    });

    root.querySelector('[data-rental-form]')?.addEventListener('submit', saveRental);
    root.querySelector('[data-delete-rental]')?.addEventListener('click', deleteRental);
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
    render();
  }

  function openNewRental() {
    if (!selectedItem()) return;

    state.modal = { type: 'form', date: state.selectedDate, period: 'morning' };
    render();
  }

  function openRental(id) {
    const rental = rentals().find((item) => Number(item.id) === Number(id));
    if (!rental) return;

    state.modal = { type: 'form', rental };
    render();
  }

  async function saveRental(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const id = Number(data.get('id') || 0);
    const period = String(data.get('period') || 'morning');
    const mapped = periodPayload(period);
    const payload = {
      id: id || undefined,
      equipmentItemId: Number(data.get('equipmentItemId') || selectedItem()?.id),
      date: String(data.get('date') || state.selectedDate),
      periodType: mapped.periodType,
      slot: mapped.slot,
      notes: String(data.get('notes') || ''),
      status: 'reserved',
    };

    try {
      await request(id ? 'update_rental' : 'create_rental', {
        method: 'POST',
        body: payload,
      });
      state.modal = null;
      await load({ force: true });
    } catch (error) {
      alert(error.message || 'Enregistrement impossible');
    }
  }

  async function deleteRental(event) {
    const id = Number(event.currentTarget.dataset.deleteRental || 0);
    if (!id || !confirm('Supprimer cette location ?')) return;

    try {
      await request('delete_rental', { method: 'POST', body: { id } });
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
      if (!siteItems().some((item) => Number(item.id) === Number(state.selectedItemId))) {
        state.selectedItemId = null;
      }
    } catch (error) {
      if (sequence === loadSequence) state.error = error.message || 'Connexion aux données locations indisponible';
    } finally {
      if (sequence !== loadSequence) return;
      state.loading = false;
      render();
    }
  }

  function rentalPeriods(item) {
    const mode = item?.rentalMode || 'half_day_and_day';
    const periods = [];

    if (mode !== 'day_only') {
      periods.push({ key: 'morning', label: 'Matin', time: '07:30 - 12:00' });
      periods.push({ key: 'afternoon', label: 'Après-midi', time: '13:30 - 17:30' });
    }

    if (mode !== 'half_day_only') {
      periods.push({ key: 'day', label: 'Journée complète', time: '07:30 - 17:30' });
    }

    return periods;
  }

  function rentalForPeriod(item, period) {
    return (
      itemRentals(item.id).find(
        (rental) => sameDate(rental.startAt, state.selectedDate) && periodKey(rental) === period.key,
      ) || null
    );
  }

  function periodPayload(period) {
    if (period === 'day') return { periodType: 'day', slot: 'full_day' };

    return { periodType: 'half_day', slot: period };
  }

  function periodKey(rental) {
    if (!rental) return '';
    if (rental.periodType === 'day' || rental.slot === 'full_day') return 'day';

    return rental.slot || 'morning';
  }

  function periodName(period, periodType) {
    const key = periodType === 'day' || period === 'full_day' ? 'day' : period;

    return (
      {
        morning: 'Matin',
        afternoon: 'Après-midi',
        day: 'Journée complète',
      }[key] || 'Matin'
    );
  }

  function priceLabel(item) {
    if (!item) return '';
    const showHalfDayPrice = item.showHalfDayPrice !== false;
    const showDayPrice = item.showDayPrice !== false;

    if (item.rentalMode === 'day_only') {
      return showDayPrice ? `${Number(item.dayPrice || 0)} EUR/j` : 'Tarif masqué';
    }

    if (showDayPrice) return `${Number(item.dayPrice || 0)} EUR/j`;
    if (showHalfDayPrice) return `${Number(item.halfDayPrice || 0)} EUR/½j`;

    return 'Tarif masqué';
  }

  function itemInitials(item) {
    return String(item?.name || 'M')
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part[0] || '')
      .join('')
      .toUpperCase();
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

  function isItemBusy(item) {
    const now = new Date().toISOString().slice(0, 16);
    return itemRentals(item.id).some((rental) => rental.startAt <= now && rental.endAt >= now);
  }

  function sameDate(value, date) {
    return String(value || '').slice(0, 10) === date;
  }

  function icon(name) {
    const paths = {
      plus: '<path d="M12 5v14M5 12h14"></path>',
      'chevron-left': '<path d="m15 18-6-6 6-6"></path>',
      'chevron-right': '<path d="m9 18 6-6-6-6"></path>',
      x: '<path d="M18 6 6 18M6 6l12 12"></path>',
      save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path><path d="M17 21v-8H7v8M7 3v5h8"></path>',
      trash: '<path d="M3 6h18M8 6V4h8v2M6 6l1 15h10l1-15"></path>',
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

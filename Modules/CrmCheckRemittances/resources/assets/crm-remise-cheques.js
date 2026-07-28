(() => {
  const api = '/api/remise-cheques';
  const routePath = '/remise-cheques';
  const mountedRoots = new WeakSet();
  let root = null;

  const state = {
    data: null,
    filters: { limit: '10', year: '', month: '' },
    selectedId: null,
    detailRemittance: null,
    remittanceModalOpen: false,
    checkModalOpen: false,
    remittanceDraft: emptyRemittanceDraft(),
    checkDraft: emptyCheckDraft(),
    loading: false,
    saving: false,
    ocrBusy: false,
    ocrRunId: 0,
    ocrStatus: '',
    photoViewer: null,
  };

  const esc = (value) =>
    String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');

  function themeCssVariables() {
    return [
      `--theme-primary:${themeRgb('--theme-primary')}`,
      `--theme-primary-color:${themeHex('--theme-primary-color')}`,
      `--theme-primary-dark-color:${themeHex('--theme-primary-dark-color')}`,
      `--theme-accent:${themeRgb('--theme-accent')}`,
      `--theme-accent-color:${themeHex('--theme-accent-color')}`,
    ].join(';');
  }

  function themeCssValue(name) {
    const styles = window.getComputedStyle(document.documentElement);

    return styles.getPropertyValue(name).trim().replace(/\s+/g, ' ');
  }

  function themeHex(name) {
    const value = themeCssValue(name);

    return /^#[0-9a-f]{6}$/i.test(value) ? value : '#000000';
  }

  function themeRgb(name) {
    const value = themeCssValue(name);

    return /^\d{1,3}\s+\d{1,3}\s+\d{1,3}$/.test(value) ? value : '0 0 0';
  }

  function today() {
    const date = new Date();
    return [
      date.getFullYear(),
      String(date.getMonth() + 1).padStart(2, '0'),
      String(date.getDate()).padStart(2, '0'),
    ].join('-');
  }

  function emptyRemittanceDraft() {
    return {
      id: '',
      remittanceDate: today(),
      reference: '',
      bankName: '',
      status: 'draft',
      notes: '',
    };
  }

  function emptyCheckDraft(remittanceId = '') {
    return {
      id: '',
      remittanceId,
      payerName: '',
      invoiceNumber: '',
      amount: '',
      photoDataUrl: '',
      photoName: '',
      ocrText: '',
      ocrConfidence: '',
      ocrVisualChecks: emptyVisualChecks(),
      sortOrder: 100,
    };
  }

  function emptyVisualChecks() {
    return {
      signature: null,
      order: null,
    };
  }

  function activeSiteId() {
    const fromApi = Number(window.CRM_ACTIVE_SITE?.getSiteId?.() || 0);
    if (Number.isFinite(fromApi) && fromApi > 0) return fromApi;

    try {
      const stored = Number(window.localStorage.getItem('crm:active-site-id') || 0);
      if (Number.isFinite(stored) && stored > 0) return stored;
    } catch (error) {
      // The backend will choose the first authorized site.
    }

    const selected = Number(state.data?.selectedSiteId || state.data?.user?.selectedSiteId || 0);
    return Number.isFinite(selected) && selected > 0 ? selected : '';
  }

  function activeSiteName() {
    const siteId = Number(state.data?.selectedSiteId || state.data?.user?.selectedSiteId || activeSiteId());
    const site = (state.data?.sites || []).find((item) => Number(item.id) === siteId);
    return site?.name || 'Site actif';
  }

  function money(value) {
    return new Intl.NumberFormat('fr-FR', {
      style: 'currency',
      currency: 'EUR',
      maximumFractionDigits: 2,
    }).format(Number(value) || 0);
  }

  function dateLabel(value) {
    if (!value) return '-';
    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) return value;

    return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  function parseMoney(value) {
    const normalized = String(value ?? '')
      .replace(/\s/g, '')
      .replace(',', '.');
    const number = Number(normalized);
    return Number.isFinite(number) ? Math.round(number * 100) / 100 : 0;
  }

  function selectedRemittance() {
    if (state.detailRemittance && Number(state.detailRemittance.id) === Number(state.selectedId)) {
      return state.detailRemittance;
    }

    const remittances = state.data?.remittances || [];
    return remittances.find((item) => Number(item.id) === Number(state.selectedId)) || null;
  }

  function detailIdFromPath() {
    const match = window.location.pathname.replace(/\/$/, '').match(/^\/remise-cheques\/(\d+)$/);
    return match ? Number(match[1]) : null;
  }

  function isDetailRoute() {
    return detailIdFromPath() !== null;
  }

  function dashboardUrl() {
    return routePath;
  }

  function detailUrl(id) {
    return `${routePath}/${encodeURIComponent(id)}`;
  }

  function navigateTo(url) {
    if (window.location.pathname + window.location.search === url) return false;
    window.history.pushState({}, '', url);
    window.dispatchEvent(new Event('crm:check-remittance-route-changed'));
    return true;
  }

  function openDashboard() {
    state.selectedId = null;
    state.detailRemittance = null;
    state.checkModalOpen = false;
    if (!navigateTo(dashboardUrl())) load();
  }

  function openRemittance(id) {
    state.selectedId = Number(id);
    state.detailRemittance = null;
    if (!navigateTo(detailUrl(id))) load();
  }

  async function getBootstrap() {
    const url = new URL(api, window.location.origin);
    url.searchParams.set('action', 'bootstrap');

    const siteId = activeSiteId();
    if (siteId) url.searchParams.set('siteId', String(siteId));
    if (state.filters.limit) url.searchParams.set('limit', state.filters.limit);
    if (state.filters.year) url.searchParams.set('year', state.filters.year);
    if (state.filters.month) url.searchParams.set('month', state.filters.month);

    return requestUrl(url.toString(), { method: 'GET' });
  }

  async function getRemittance(id) {
    return request('show_remittance', { id: Number(id) });
  }

  async function request(action, payload = {}) {
    const siteId = activeSiteId();
    return requestUrl(
      `${api}?action=${encodeURIComponent(action)}${siteId ? `&siteId=${encodeURIComponent(siteId)}` : ''}`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ siteId, ...payload }),
      },
    );
  }

  async function requestUrl(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.headers || {}),
      },
      ...options,
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch (error) {
      payload = { ok: false, error: 'Réponse serveur invalide' };
    }

    if (!response.ok || payload?.ok === false) {
      throw new Error(payload?.error || 'Opération impossible');
    }

    return payload;
  }

  async function load() {
    try {
      state.loading = true;
      render();
      const data = await getBootstrap();
      state.data = {
        ...data,
        remittances: (data.remittances || []).map(normalizeRemittance),
      };

      const routeDetailId = detailIdFromPath();
      if (routeDetailId) {
        state.selectedId = routeDetailId;
        const fromList = state.data.remittances.find((item) => Number(item.id) === routeDetailId);
        state.detailRemittance = fromList || null;

        const detail = await getRemittance(routeDetailId);
        state.detailRemittance = normalizeRemittance(detail.remittance);
        if (state.data?.user) {
          state.data.user.canManage = Boolean(detail.canManage);
        }
      } else {
        state.selectedId = null;
        state.detailRemittance = null;
      }
    } catch (error) {
      state.data = {
        ok: false,
        error: error instanceof Error ? error.message : 'Chargement impossible',
        remittances: [],
        summary: { remittanceCount: 0, checkCount: 0, totalAmount: 0 },
        user: { canManage: false },
      };
      state.detailRemittance = null;
    } finally {
      state.loading = false;
      render();
    }
  }

  function normalizeRemittance(remittance) {
    return {
      ...remittance,
      checks: Array.isArray(remittance?.checks) ? remittance.checks : [],
    };
  }

  function canManage() {
    return Boolean(state.data?.user?.canManage);
  }

  function render() {
    if (!root || !document.body.contains(root)) return;
    styles();

    if (state.loading && !state.data) {
      root.innerHTML = `<div class="check-page"><div class="check-loading">Chargement des remises de chèques...</div></div>`;
      return;
    }

    const error = state.data?.error || '';
    const summary = state.data?.summary || { remittanceCount: 0, checkCount: 0, totalAmount: 0 };
    const remittances = state.data?.remittances || [];
    const selected = selectedRemittance();
    const manage = canManage();
    const detailMode = isDetailRoute();

    root.innerHTML = `
      <div class="check-page">
        <header class="check-head">
          <div>
            <h1>${detailMode ? esc(selected?.reference || 'Remise de chèques') : 'Remise de chèques'}</h1>
            <p>${detailMode ? `${esc(dateLabel(selected?.remittanceDate))} - ${esc(selected?.siteName || activeSiteName())}` : esc(activeSiteName())}</p>
          </div>
          <div class="check-actions">
            ${detailMode ? `<button type="button" class="check-button" data-back-dashboard>${icon('arrowLeft')}<span>Tableau de bord</span></button>` : ''}
            ${!detailMode && manage ? `<button type="button" class="check-button check-button-primary" data-new-remittance>${icon('plus')}<span>Nouvelle remise</span></button>` : ''}
          </div>
        </header>

        ${error ? `<div class="check-notice">${esc(error)}</div>` : ''}

        ${detailMode ? renderDetailBody(selected, manage) : renderDashboardBody(summary, remittances, manage)}
        ${renderRemittanceModal(manage)}
        ${renderCheckModal(manage)}
        ${renderPhotoViewer()}
      </div>
    `;

    bind();
  }

  function renderDashboardBody(summary, remittances, manage) {
    return `
        <section class="check-summary">
          ${summaryCard('Remises', summary.remittanceCount || 0, 'Filtre actif', 'file', '#2563eb')}
          ${summaryCard('Chèques', summary.checkCount || 0, 'Nombre total', 'creditCard', '#0f766e')}
          ${summaryCard('Total', money(summary.totalAmount || 0), 'Somme des remises', 'banknote', 'var(--theme-primary-color)')}
          ${summaryCard('Site', activeSiteName(), `${remittances.length} ligne(s)`, 'building', '#7c3aed')}
        </section>

        <section class="check-card check-filters">
          <label>
            <span>Afficher</span>
            <select data-filter="limit">
              ${[10, 20, 50, 100].map((limit) => `<option value="${limit}" ${String(limit) === String(state.filters.limit) ? 'selected' : ''}>${limit} dernières</option>`).join('')}
            </select>
          </label>
          <label>
            <span>Mois</span>
            <select data-filter="month">
              <option value="" ${state.filters.month === '' ? 'selected' : ''}>Tous les mois</option>
              ${monthOptions()}
            </select>
          </label>
          <label>
            <span>Année</span>
            <input type="number" min="2020" max="2100" inputmode="numeric" data-filter="year" value="${esc(state.filters.year)}" placeholder="Toutes">
          </label>
        </section>

        <section class="check-card check-list">
          <div class="check-section-head">
            <div>
              <h2>Tableau de bord</h2>
              <span>${remittances.length} remise(s)</span>
            </div>
          </div>
          ${remittances.length ? renderRemittanceTable(remittances, manage) : renderEmpty(manage)}
        </section>
    `;
  }

  function renderDetailBody(remittance, manage) {
    if (!remittance) {
      return `
        <section class="check-card">
          <div class="check-empty">
            <strong>Remise introuvable</strong>
            <span>Retournez au tableau de bord pour choisir une remise existante.</span>
            <button type="button" class="check-button" data-back-dashboard>${icon('arrowLeft')}<span>Tableau de bord</span></button>
          </div>
        </section>
      `;
    }

    return renderDetail(remittance, manage);
  }

  function monthOptions() {
    return Array.from({ length: 12 }, (_, index) => {
      const value = String(index + 1);
      const label = new Date(`2026-${String(index + 1).padStart(2, '0')}-01T00:00:00`).toLocaleDateString('fr-FR', {
        month: 'long',
      });
      return `<option value="${value}" ${String(state.filters.month) === value ? 'selected' : ''}>${esc(label)}</option>`;
    }).join('');
  }

  function summaryCard(label, value, detail, iconName, color) {
    return `
      <article class="check-summary-card" style="--check-card-color:${esc(color)}">
        <span class="check-summary-icon">${icon(iconName)}</span>
        <div>
          <span>${esc(label)}</span>
          <strong>${esc(value)}</strong>
          <small>${esc(detail)}</small>
        </div>
      </article>
    `;
  }

  function renderRemittanceTable(remittances, manage) {
    return `
      <div class="check-table-wrap">
        <table class="check-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Reference</th>
              <th>Banque</th>
              <th>Chèques</th>
              <th>Total</th>
              <th>Statut</th>
              <th class="check-actions-cell">Actions</th>
            </tr>
          </thead>
          <tbody>
            ${remittances
              .map(
                (remittance) => `
              <tr>
                <td><button type="button" class="check-link" data-open-remittance="${esc(remittance.id)}">${esc(dateLabel(remittance.remittanceDate))}</button></td>
                <td>${esc(remittance.reference || '-')}</td>
                <td>${esc(remittance.bankName || '-')}</td>
                <td>${esc(remittance.checkCount || 0)}</td>
                <td><strong>${esc(money(remittance.totalAmount || 0))}</strong></td>
                <td><span class="check-status">${esc(remittance.statusLabel || 'Brouillon')}</span></td>
                <td>
                  <div class="check-row-actions">
                    <button type="button" class="check-mini-button check-icon-button" title="Voir" data-open-remittance="${esc(remittance.id)}">${icon('eye')}</button>
                    ${manage ? `<button type="button" class="check-mini-button check-icon-button" title="Modifier" data-edit-remittance="${esc(remittance.id)}">${icon('edit')}</button>` : ''}
                    ${manage ? `<button type="button" class="check-mini-button check-icon-button is-danger" title="Supprimer" data-delete-remittance="${esc(remittance.id)}">${icon('trash')}</button>` : ''}
                  </div>
                </td>
              </tr>
            `,
              )
              .join('')}
          </tbody>
        </table>
      </div>
    `;
  }

  function renderEmpty(manage) {
    return `
      <div class="check-empty">
        <strong>Aucune remise</strong>
        <span>Les remises apparaîtront ici.</span>
        ${manage ? `<button type="button" class="check-button check-button-primary" data-new-remittance>${icon('plus')}<span>Nouvelle remise</span></button>` : ''}
      </div>
    `;
  }

  function renderDetail(remittance, manage) {
    return `
      <section class="check-card check-detail">
        <div class="check-section-head">
          <div>
            <h2>${esc(remittance.reference || 'Remise de chèques')}</h2>
            <span>${esc(dateLabel(remittance.remittanceDate))} - ${esc(remittance.statusLabel || 'Brouillon')}</span>
          </div>
          <div class="check-actions">
            <button type="button" class="check-button" data-print-remittance="${esc(remittance.id)}">${icon('printer')}<span>Imprimer / PDF</span></button>
            ${manage ? `<button type="button" class="check-button check-button-primary" data-new-check="${esc(remittance.id)}">${icon('camera')}<span>Ajouter un chèque</span></button>` : ''}
          </div>
        </div>
        <div class="check-total-strip">
          <span><small>Total remise</small><strong>${esc(money(remittance.totalAmount || 0))}</strong></span>
          <span><small>Nombre</small><strong>${esc(remittance.checkCount || 0)} chèque(s)</strong></span>
          <span><small>Banque</small><strong>${esc(remittance.bankName || '-')}</strong></span>
          <span><small>Site</small><strong>${esc(remittance.siteName || activeSiteName())}</strong></span>
        </div>
        ${renderChecks(remittance, manage)}
      </section>
    `;
  }

  function renderChecks(remittance, manage) {
    if (!remittance.checks.length) {
      return `
        <div class="check-empty is-compact">
          <strong>Aucun chèque ajouté</strong>
          ${manage ? `<button type="button" class="check-button" data-new-check="${esc(remittance.id)}">${icon('plus')}<span>Ajouter</span></button>` : ''}
        </div>
      `;
    }

    return `
      <div class="check-table-wrap">
        <table class="check-table check-lines-table">
          <thead>
            <tr>
              <th>Nom</th>
              <th>Facture</th>
              <th>Montant</th>
              <th class="check-actions-cell">Actions</th>
            </tr>
          </thead>
          <tbody>
            ${remittance.checks
              .map(
                (check) => `
              <tr>
                <td>
                  <div class="check-line-name">
                    ${check.photoPath ? `<button type="button" class="check-photo-thumb" data-view-photo="${esc(check.photoPath)}" data-view-photo-title="${esc(check.payerName || 'Chèque')}"><img src="${esc(check.photoPath)}" alt=""></button>` : `<span>${icon('creditCard')}</span>`}
                    <strong>${esc(check.payerName || '-')}</strong>
                  </div>
                </td>
                <td>${esc(check.invoiceNumber || '-')}</td>
                <td><strong>${esc(money(check.amount || 0))}</strong></td>
                <td>
                  <div class="check-row-actions">
                    ${manage ? `<button type="button" class="check-mini-button check-icon-button" title="Modifier" data-edit-check="${esc(check.id)}">${icon('edit')}</button>` : ''}
                    ${manage ? `<button type="button" class="check-mini-button check-icon-button is-danger" title="Supprimer" data-delete-check="${esc(check.id)}">${icon('trash')}</button>` : ''}
                  </div>
                </td>
              </tr>
            `,
              )
              .join('')}
          </tbody>
        </table>
      </div>
    `;
  }

  function renderRemittanceModal(manage) {
    if (!state.remittanceModalOpen || !manage) return '';
    const draft = state.remittanceDraft;
    const statusOptions = state.data?.statusOptions || [
      { value: 'draft', label: 'Brouillon' },
      { value: 'ready', label: 'Prête à déposer' },
      { value: 'deposited', label: 'Déposée' },
    ];

    return `
      <div class="check-modal-backdrop" data-close-remittance>
        <div class="check-modal check-small-modal" role="dialog" aria-modal="true" aria-label="Remise de chèques" data-modal>
          <div class="check-modal-head">
            <div>
              <h2>${draft.id ? 'Modifier la remise' : 'Nouvelle remise'}</h2>
              <p>${esc(activeSiteName())}</p>
            </div>
            <button type="button" class="check-mini-button" data-close-remittance>Fermer</button>
          </div>
          <form class="check-form" data-remittance-form>
            <input type="hidden" name="id" value="${esc(draft.id)}">
            <label>
              <span>Date</span>
              <input type="date" name="remittanceDate" value="${esc(draft.remittanceDate || today())}" required>
            </label>
            <label>
              <span>Reference</span>
              <input type="text" name="reference" value="${esc(draft.reference)}" placeholder="Auto si vide">
            </label>
            <label>
              <span>Banque</span>
              <input type="text" name="bankName" value="${esc(draft.bankName)}">
            </label>
            <label>
              <span>Statut</span>
              <select name="status">
                ${statusOptions.map((option) => `<option value="${esc(option.value)}" ${draft.status === option.value ? 'selected' : ''}>${esc(option.label)}</option>`).join('')}
              </select>
            </label>
            <label class="check-field-full">
              <span>Notes</span>
              <textarea name="notes">${esc(draft.notes)}</textarea>
            </label>
            <div class="check-modal-actions">
              <button type="button" class="check-button" data-close-remittance>Annuler</button>
              <button type="submit" class="check-button check-button-primary">${draft.id ? 'Enregistrer' : 'Créer'}</button>
            </div>
          </form>
        </div>
      </div>
    `;
  }

  function renderCheckModal(manage) {
    if (!state.checkModalOpen || !manage) return '';
    const draft = state.checkDraft;
    const preview = draft.photoDataUrl || draft.photoPath || '';
    const ocrStatus = state.ocrStatus || (preview ? 'Photo chargée.' : '');
    const visualChecks = draft.ocrVisualChecks || emptyVisualChecks();

    return `
      <div class="check-modal-backdrop" data-close-check>
        <div class="check-modal" role="dialog" aria-modal="true" aria-label="Chèque" data-modal>
          <div class="check-modal-head">
            <div>
              <h2>${draft.id ? 'Modifier le chèque' : 'Ajouter un chèque'}</h2>
              <p>${esc(selectedRemittance()?.reference || 'Remise active')}</p>
            </div>
            <button type="button" class="check-mini-button" data-close-check>Fermer</button>
          </div>
          <div class="check-check-form">
            ${checkInput('invoiceNumber', 'Numéro facture', 'text', draft.invoiceNumber, 'Facture')}
            ${checkInput('payerName', 'Nom', 'text', draft.payerName, 'Client / émetteur')}
            ${checkInput('amount', 'Montant', 'text', draft.amount, '0,00', 'decimal')}
            <div class="check-photo-tools">
              <div class="check-photo-bar">
                <label class="check-button check-button-primary check-photo-capture">
                  ${icon('camera')}
                  <span>${preview ? 'Reprendre la photo' : 'Prendre en photo le chèque'}</span>
                  <input type="file" accept="image/*" capture="environment" data-photo-input>
                </label>
                ${
                  preview
                    ? `
                  <button type="button" class="check-mini-button" data-run-ocr ${draft.photoDataUrl && !state.ocrBusy ? '' : 'disabled'}>${state.ocrBusy ? 'Lecture...' : 'Relancer'}</button>
                  <button type="button" class="check-mini-button" data-remove-photo ${state.ocrBusy ? 'disabled' : ''}>Supprimer</button>
                `
                    : ''
                }
              </div>
              ${preview ? `<button type="button" class="check-photo-preview-button" data-view-photo="${esc(preview)}" data-view-photo-title="${esc(draft.payerName || 'Chèque')}"><img class="check-photo-preview" src="${esc(preview)}" alt=""></button>` : ''}
              ${ocrStatus ? `<div class="check-ocr-row"><span>${esc(ocrStatus)}</span></div>` : ''}
              ${renderVisualChecks(visualChecks)}
            </div>
          </div>
          <div class="check-modal-actions">
            <button type="button" class="check-button" data-close-check>Annuler</button>
            <button type="button" class="check-button check-button-primary" data-save-check>${draft.id ? 'Modifier' : 'Ajouter'}</button>
          </div>
        </div>
      </div>
    `;
  }

  function checkInput(field, label, type, value, placeholder, inputmode = '') {
    return `
      <label>
        <span>${esc(label)}</span>
        <input type="${esc(type)}" data-check-field="${esc(field)}" value="${esc(value)}" placeholder="${esc(placeholder)}" ${inputmode ? `inputmode="${esc(inputmode)}"` : ''}>
      </label>
    `;
  }

  function renderVisualChecks(checks) {
    return `
      <div class="check-readiness-grid">
        ${renderVisualCheck('signature', 'Signature', checks.signature)}
        ${renderVisualCheck('order', 'Destinataire', checks.order)}
      </div>
    `;
  }

  function renderVisualCheck(key, label, value) {
    const stateName = value === true ? 'ok' : value === false ? 'warn' : 'idle';
    const status = value === true ? 'OK' : value === false ? 'À vérifier' : 'Après détection';

    return `
      <div class="check-readiness-item check-readiness-${stateName}" data-readiness="${esc(key)}">
        <span class="check-readiness-box" aria-hidden="true"></span>
        <div>
          <strong>${esc(label)}</strong>
          <small>${esc(status)}</small>
        </div>
      </div>
    `;
  }

  function renderPhotoViewer() {
    const viewer = state.photoViewer;
    if (!viewer?.src) return '';

    return `
      <div class="check-photo-viewer-backdrop" data-close-photo-viewer>
        <div class="check-photo-viewer" role="dialog" aria-modal="true" aria-label="Photo du chèque">
          <div class="check-photo-viewer-head">
            <strong>${esc(viewer.title || 'Chèque')}</strong>
            <button type="button" class="check-mini-button" data-close-photo-viewer>Fermer</button>
          </div>
          <img src="${esc(viewer.src)}" alt="">
        </div>
      </div>
    `;
  }

  function bind() {
    root.querySelectorAll('[data-filter]').forEach((field) =>
      field.addEventListener('change', () => {
        state.filters[field.dataset.filter] = field.value;
        load();
      }),
    );

    root.querySelectorAll('input[data-filter]').forEach((field) =>
      field.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          state.filters[field.dataset.filter] = field.value;
          load();
        }
      }),
    );

    root.querySelectorAll('input[data-filter]').forEach((field) =>
      field.addEventListener('blur', () => {
        state.filters[field.dataset.filter] = field.value;
        load();
      }),
    );

    root.querySelectorAll('[data-new-remittance]').forEach((button) =>
      button.addEventListener('click', () => {
        state.remittanceDraft = emptyRemittanceDraft();
        state.remittanceModalOpen = true;
        render();
      }),
    );

    root.querySelectorAll('[data-back-dashboard]').forEach((button) => button.addEventListener('click', openDashboard));

    root.querySelectorAll('[data-open-remittance]').forEach((button) =>
      button.addEventListener('click', () => {
        openRemittance(Number(button.dataset.openRemittance));
      }),
    );

    root.querySelectorAll('[data-edit-remittance]').forEach((button) =>
      button.addEventListener('click', () => {
        const remittance = findRemittance(Number(button.dataset.editRemittance));
        if (!remittance) return;
        state.remittanceDraft = {
          id: remittance.id,
          remittanceDate: remittance.remittanceDate || today(),
          reference: remittance.reference || '',
          bankName: remittance.bankName || '',
          status: remittance.status || 'draft',
          notes: remittance.notes || '',
        };
        state.remittanceModalOpen = true;
        render();
      }),
    );

    root.querySelectorAll('[data-delete-remittance]').forEach((button) =>
      button.addEventListener('click', async () => {
        const deletedId = Number(button.dataset.deleteRemittance);
        if (!window.confirm('Supprimer cette remise de chèques ?')) return;
        await withSaving(async () => {
          await request('delete_remittance', { id: deletedId });
          state.selectedId = null;
          state.detailRemittance = null;
          if (isDetailRoute() && Number(detailIdFromPath()) === deletedId) {
            openDashboard();
          } else {
            await load();
          }
        });
      }),
    );

    root.querySelectorAll('[data-new-check]').forEach((button) =>
      button.addEventListener('click', () => {
        state.checkDraft = emptyCheckDraft(Number(button.dataset.newCheck));
        state.ocrStatus = '';
        state.checkModalOpen = true;
        render();
        warmUpCheckOcr();
      }),
    );

    root.querySelectorAll('[data-edit-check]').forEach((button) =>
      button.addEventListener('click', () => {
        const check = findCheck(Number(button.dataset.editCheck));
        if (!check) return;
        state.checkDraft = {
          id: check.id,
          remittanceId: check.remittanceId,
          payerName: check.payerName || '',
          invoiceNumber: check.invoiceNumber || '',
          amount: String(check.amount || '').replace('.', ','),
          photoPath: check.photoPath || '',
          photoDataUrl: '',
          photoName: check.originalName || '',
          ocrText: check.ocrText || '',
          ocrConfidence: check.ocrConfidence || '',
          ocrVisualChecks: emptyVisualChecks(),
          sortOrder: check.sortOrder || 100,
        };
        state.ocrStatus = '';
        state.checkModalOpen = true;
        render();
        warmUpCheckOcr();
      }),
    );

    root.querySelectorAll('[data-delete-check]').forEach((button) =>
      button.addEventListener('click', async () => {
        if (!window.confirm('Supprimer ce chèque ?')) return;
        await withSaving(async () => {
          await request('delete_check', { id: Number(button.dataset.deleteCheck) });
          await load();
        });
      }),
    );

    root.querySelector('[data-remittance-form]')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = Object.fromEntries(new FormData(event.currentTarget).entries());
      await withSaving(async () => {
        const response = await request('save_remittance', {
          id: form.id ? Number(form.id) : undefined,
          remittanceDate: form.remittanceDate,
          reference: form.reference,
          bankName: form.bankName,
          status: form.status,
          notes: form.notes,
        });
        state.remittanceModalOpen = false;
        if (response.remittance?.id) {
          openRemittance(response.remittance.id);
        } else {
          await load();
        }
      });
    });

    root.querySelectorAll('[data-check-field]').forEach((field) => {
      field.addEventListener('input', () => {
        state.checkDraft[field.dataset.checkField] = field.value;
      });
    });

    root.querySelector('[data-photo-input]')?.addEventListener('change', handlePhoto);
    root.querySelector('[data-run-ocr]')?.addEventListener('click', runOcr);
    root.querySelector('[data-remove-photo]')?.addEventListener('click', removeCheckPhoto);
    root.querySelector('[data-save-check]')?.addEventListener('click', saveCheck);

    root.querySelectorAll('[data-view-photo]').forEach((button) =>
      button.addEventListener('click', () => {
        state.photoViewer = {
          src: button.dataset.viewPhoto || '',
          title: button.dataset.viewPhotoTitle || 'Chèque',
        };
        render();
      }),
    );

    root.querySelectorAll('[data-close-photo-viewer]').forEach((element) =>
      element.addEventListener('click', (event) => {
        if (event.target !== event.currentTarget && !event.currentTarget.matches('button')) return;
        state.photoViewer = null;
        render();
      }),
    );

    root.querySelectorAll('[data-close-remittance]').forEach((element) =>
      element.addEventListener('click', (event) => {
        if (event.target !== event.currentTarget && !event.currentTarget.matches('button')) return;
        state.remittanceModalOpen = false;
        render();
      }),
    );

    root.querySelectorAll('[data-close-check]').forEach((element) =>
      element.addEventListener('click', (event) => {
        if (event.target !== event.currentTarget && !event.currentTarget.matches('button')) return;
        state.checkModalOpen = false;
        render();
      }),
    );

    root.querySelectorAll('[data-print-remittance]').forEach((button) =>
      button.addEventListener('click', () => {
        const remittance = (state.data?.remittances || []).find(
          (item) => Number(item.id) === Number(button.dataset.printRemittance),
        );
        if (remittance) printRemittance(remittance);
      }),
    );
  }

  function findRemittance(id) {
    if (state.detailRemittance && Number(state.detailRemittance.id) === Number(id)) {
      return state.detailRemittance;
    }

    return (state.data?.remittances || []).find((item) => Number(item.id) === Number(id)) || null;
  }

  function findCheck(id) {
    const remittances = [
      ...(state.detailRemittance ? [state.detailRemittance] : []),
      ...(state.data?.remittances || []),
    ];

    for (const remittance of remittances) {
      const check = (remittance.checks || []).find((item) => Number(item.id) === Number(id));
      if (check) return check;
    }

    return null;
  }

  async function handlePhoto(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = () => {
      state.ocrRunId += 1;
      state.ocrBusy = false;
      state.checkDraft.photoDataUrl = String(reader.result || '');
      state.checkDraft.photoName = file.name || 'cheque.jpg';
      state.checkDraft.ocrVisualChecks = emptyVisualChecks();
      state.ocrStatus = 'Photo chargée. Détection en cours...';
      render();
      window.setTimeout(() => runOcr(), 0);
    };
    reader.readAsDataURL(file);
  }

  function removeCheckPhoto() {
    state.ocrRunId += 1;
    state.ocrBusy = false;
    state.checkDraft.photoDataUrl = '';
    state.checkDraft.photoPath = '';
    state.checkDraft.photoName = '';
    state.checkDraft.ocrText = '';
    state.checkDraft.ocrConfidence = '';
    state.checkDraft.ocrVisualChecks = emptyVisualChecks();
    state.ocrStatus = '';
    render();
  }

  async function saveCheck() {
    const draft = { ...state.checkDraft };
    root.querySelectorAll('[data-check-field]').forEach((field) => {
      draft[field.dataset.checkField] = field.value;
    });

    if (!draft.remittanceId) {
      window.alert('Remise requise');
      return;
    }

    if (!String(draft.invoiceNumber || '').trim()) {
      window.alert('Numéro de facture requis');
      return;
    }

    if (!String(draft.payerName || '').trim()) {
      window.alert('Nom requis');
      return;
    }

    if (parseMoney(draft.amount) <= 0) {
      window.alert('Montant requis');
      return;
    }

    await withSaving(async () => {
      await request('save_check', {
        id: draft.id ? Number(draft.id) : undefined,
        remittanceId: Number(draft.remittanceId),
        payerName: draft.payerName,
        invoiceNumber: draft.invoiceNumber,
        amount: draft.amount,
        photoDataUrl: draft.photoDataUrl || '',
        photoName: draft.photoName,
        ocrText: draft.ocrText,
        ocrConfidence: draft.ocrConfidence,
        sortOrder: draft.sortOrder || 100,
      });
      state.checkModalOpen = false;
      await load();
    });
  }

  async function runOcr() {
    if (!state.checkDraft.photoDataUrl || state.ocrBusy) return;
    const sourcePhoto = state.checkDraft.photoDataUrl;
    const runId = state.ocrRunId + 1;
    state.ocrRunId = runId;
    const stillCurrent = () => state.ocrRunId === runId && state.checkDraft.photoDataUrl === sourcePhoto;

    return runBestCheckOcr(sourcePhoto, stillCurrent);

    try {
      state.ocrBusy = true;
      state.ocrStatus = 'Préparation de la photo...';
      render();

      const Tesseract = await ensureTesseract();
      const images = await prepareCheckOcrImages(sourcePhoto);
      if (!stillCurrent()) return;
      const visualChecks = { ...(images.visualChecks || emptyVisualChecks()) };
      state.checkDraft.ocrVisualChecks = visualChecks;

      state.ocrStatus = 'Lecture du montant...';
      render();
      const amountResults = [];
      for (let index = 0; index < images.amounts.length; index += 1) {
        state.ocrStatus =
          images.amounts.length > 1
            ? `Lecture du montant (${index + 1}/${images.amounts.length})...`
            : 'Lecture du montant...';
        render();
        amountResults.push(
          await recognizeCheckImage(Tesseract, images.amounts[index], 'eng', {
            ...(images.amounts[index].ocrOptions || {}),
            tessedit_char_whitelist: '0123456789,. €EUR',
            preserve_interword_spaces: '1',
          }),
        );
        if (!stillCurrent()) return;
      }

      state.ocrStatus = 'Lecture du nom...';
      render();
      const nameResults = [];
      for (let index = 0; index < images.names.length; index += 1) {
        state.ocrStatus =
          images.names.length > 1 ? `Lecture du nom (${index + 1}/${images.names.length})...` : 'Lecture du nom...';
        render();
        nameResults.push(
          await recognizeCheckImage(Tesseract, images.names[index], 'fra+eng', {
            preserve_interword_spaces: '1',
          }),
        );
        if (!stillCurrent()) return;
      }

      state.ocrStatus = 'Vérification globale...';
      render();
      const fullResult = await recognizeCheckImage(Tesseract, images.full, 'fra+eng', {
        preserve_interword_spaces: '1',
      });
      if (!stillCurrent()) return;

      const parsed = parseCheckOcr({
        amountResults,
        nameResults,
        full: fullResult,
      });
      visualChecks.order = Boolean(
        visualChecks.order ||
        detectOrderLabel(
          [
            ...amountResults.map((result) => result.text || ''),
            ...nameResults.map((result) => result.text || ''),
            fullResult.text || '',
          ].join('\n'),
        ),
      );
      state.checkDraft.ocrVisualChecks = visualChecks;

      state.checkDraft.ocrText = [
        `[orientation] rotation ${images.rotation}°`,
        '[contrôles visuels]',
        `Signature: ${visualChecks.signature ? 'oui' : 'à vérifier'}`,
        `Destinataire: ${visualChecks.order ? 'oui' : 'à vérifier'}`,
        ...amountResults.flatMap((result, index) => [`[zone montant ${index + 1}]`, result.text]),
        ...nameResults.flatMap((result, index) => [`[zone nom ${index + 1}]`, result.text]),
        '[lecture globale]',
        fullResult.text,
      ].join('\n');
      state.checkDraft.ocrConfidence = parsed.confidence;
      if (parsed.payerName) state.checkDraft.payerName = resolveDetectedPayerName(parsed.payerName);
      if (parsed.amount) state.checkDraft.amount = parsed.amount;
      state.ocrStatus =
        parsed.payerName && parsed.amount
          ? 'Nom et montant détectés.'
          : parsed.amount || parsed.payerName
            ? 'Détection partielle. Vérifiez les champs.'
            : 'Nom et montant non détectés, saisie manuelle possible.';
    } catch (error) {
      console.error('[crm-check-ocr]', error);
      if (stillCurrent()) {
        state.ocrStatus = 'OCR indisponible, saisie manuelle';
      }
    } finally {
      if (stillCurrent()) {
        state.ocrBusy = false;
        render();
      }
    }
  }

  async function runBestCheckOcr(sourcePhoto, stillCurrent) {
    try {
      state.ocrBusy = true;
      state.ocrStatus = 'Analyse OCR serveur...';
      render();

      const response = await request('detect_check_ocr', {
        remittanceId: state.checkDraft.remittanceId,
        photoDataUrl: sourcePhoto,
        knownPayerNames: knownPayerNames(),
      });
      if (!stillCurrent()) return;

      if (response.engineAvailable && applyServerOcrResult(response)) {
        state.ocrStatus =
          response.payerName && response.amount
            ? 'Nom et montant detectes par le serveur.'
            : 'Detection serveur partielle. Verifiez les champs.';
        state.ocrBusy = false;
        render();
        return;
      }

      state.ocrStatus = 'OCR serveur indisponible, lecture locale...';
      render();
    } catch (error) {
      console.warn('[crm-check-server-ocr]', error);
      if (!stillCurrent()) return;
      state.ocrStatus = 'OCR serveur indisponible, lecture locale...';
      render();
    }

    if (!stillCurrent()) return;
    state.ocrBusy = false;
    return runFastCheckOcr(sourcePhoto, stillCurrent);
  }

  function applyServerOcrResult(result) {
    const visualChecks = {
      ...emptyVisualChecks(),
      ...(result.visualChecks || {}),
    };
    state.checkDraft.ocrVisualChecks = visualChecks;
    state.checkDraft.ocrText =
      result.ocrText ||
      [
        '[ocr serveur]',
        result.engine ? `Moteur: ${result.engine}` : '',
        result.payerName ? `Nom: ${result.payerName}` : '',
        result.amount ? `Montant: ${result.amount}` : '',
        result.micr ? `CMC-7: ${result.micr}` : '',
      ]
        .filter(Boolean)
        .join('\n');
    state.checkDraft.ocrConfidence = result.ocrConfidence || '';

    if (result.payerName) {
      state.checkDraft.payerName = resolveDetectedPayerName(result.payerName);
    }

    if (result.amount) {
      state.checkDraft.amount = String(result.amount);
    }

    return Boolean(result.payerName || result.amount);
  }

  async function runFastCheckOcr(sourcePhoto, stillCurrent) {
    try {
      state.ocrBusy = true;
      state.ocrStatus = 'Preparation de la photo...';
      render();

      const Tesseract = await ensureTesseract();
      const images = await prepareCheckOcrImages(sourcePhoto);
      if (!stillCurrent()) return;

      const visualChecks = { ...(images.visualChecks || emptyVisualChecks()) };
      state.checkDraft.ocrVisualChecks = visualChecks;

      state.ocrStatus = 'Lecture rapide du montant...';
      render();
      const amountResults = [];
      if (images.inkAmount?.amount) {
        amountResults.push({
          role: 'amount',
          priority: 180,
          text: `${images.inkAmount.amount} EUR`,
          confidence: images.inkAmount.confidence || 75,
          lines: [],
        });
      }
      if (!amountResults.length) {
        amountResults.push(
          await recognizeCheckImage(Tesseract, images.amounts[0], 'eng', {
            ...(images.amounts[0].ocrOptions || {}),
            tessedit_char_whitelist: '0123456789,. €EUR',
            preserve_interword_spaces: '1',
          }),
        );
      }
      if (!stillCurrent()) return;

      let parsed = parseCheckOcr({ amountResults, nameResults: [], full: null });
      if (parsed.amount) {
        state.checkDraft.amount = parsed.amount;
        state.ocrStatus = 'Montant detecte. Lecture du nom...';
        render();
      }

      state.ocrStatus = 'Lecture rapide du nom...';
      render();
      const nameResults = [
        await recognizeCheckImage(Tesseract, images.names[0], 'eng', {
          ...(images.names[0].ocrOptions || {}),
          tessedit_char_whitelist: "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz '-.",
          preserve_interword_spaces: '1',
        }),
      ];
      if (!stillCurrent()) return;

      parsed = parseCheckOcr({ amountResults, nameResults, full: null });
      if ((!parsed.amount || !parsed.payerName) && (images.amounts[1] || images.names[1])) {
        state.ocrStatus = 'Verification rapide...';
        render();

        for (let index = 1; !parsed.amount && index < images.amounts.length; index += 1) {
          amountResults.push(
            await recognizeCheckImage(Tesseract, images.amounts[index], 'eng', {
              ...(images.amounts[index].ocrOptions || {}),
              tessedit_char_whitelist: '0123456789,. €EUR',
              preserve_interword_spaces: '1',
            }),
          );
          if (!stillCurrent()) return;
          parsed = parseCheckOcr({ amountResults, nameResults, full: null });
        }

        for (let index = 1; index < images.names.length; index += 1) {
          nameResults.push(
            await recognizeCheckImage(Tesseract, images.names[index], 'eng', {
              ...(images.names[index].ocrOptions || {}),
              tessedit_char_whitelist: "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz '-.",
              preserve_interword_spaces: '1',
            }),
          );
          if (!stillCurrent()) return;
          parsed = parseCheckOcr({ amountResults, nameResults, full: null });
        }
      }

      state.checkDraft.ocrText = [
        `[orientation] rotation ${images.rotation} deg`,
        '[controles visuels]',
        `Signature: ${visualChecks.signature ? 'oui' : 'a verifier'}`,
        `Destinataire: ${visualChecks.order ? 'oui' : 'a verifier'}`,
        ...amountResults.flatMap((result, index) => [`[zone montant ${index + 1}]`, result.text]),
        ...nameResults.flatMap((result, index) => [`[zone nom ${index + 1}]`, result.text]),
      ].join('\n');
      state.checkDraft.ocrConfidence = parsed.confidence;
      if (parsed.payerName) state.checkDraft.payerName = resolveDetectedPayerName(parsed.payerName);
      if (parsed.amount) state.checkDraft.amount = parsed.amount;
      state.ocrStatus =
        parsed.payerName && parsed.amount
          ? 'Nom et montant detectes.'
          : parsed.amount || parsed.payerName
            ? 'Detection partielle. Verifiez les champs.'
            : 'Nom et montant non detectes, saisie manuelle possible.';
    } catch (error) {
      console.error('[crm-check-ocr]', error);
      if (stillCurrent()) {
        state.ocrStatus = 'OCR indisponible, saisie manuelle';
      }
    } finally {
      if (stillCurrent()) {
        state.ocrBusy = false;
        render();
      }
    }
  }

  function warmUpCheckOcr() {
    if (window.__crmCheckOcrWarmup) return window.__crmCheckOcrWarmup;

    window.__crmCheckOcrWarmup = ensureTesseract()
      .then((Tesseract) => ensureCheckOcrWorker(Tesseract, 'eng'))
      .catch((error) => {
        console.warn('[crm-check-ocr-warmup]', error);
        return null;
      });

    return window.__crmCheckOcrWarmup;
  }

  const OCR_ENGINE_OPTIONS = {
    workerPath: 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/worker.min.js',
    corePath: 'https://cdn.jsdelivr.net/npm/tesseract.js-core@5/tesseract-core-simd.wasm.js',
    langPath: 'https://tessdata.projectnaptha.com/4.0.0',
  };

  function ensureTesseract() {
    if (window.Tesseract) return Promise.resolve(window.Tesseract);
    if (window.__crmCheckTesseractPromise) return window.__crmCheckTesseractPromise;

    window.__crmCheckTesseractPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
      script.async = true;
      script.onload = () => (window.Tesseract ? resolve(window.Tesseract) : reject(new Error('OCR introuvable')));
      script.onerror = () => reject(new Error('OCR indisponible'));
      document.head.appendChild(script);
    });

    return window.__crmCheckTesseractPromise;
  }

  async function ensureCheckOcrWorker(Tesseract, language) {
    const key = language || 'eng';
    window.__crmCheckOcrWorkers = window.__crmCheckOcrWorkers || {};
    const cached = window.__crmCheckOcrWorkers[key];
    if (cached?.worker) return cached;
    if (cached?.promise) return cached.promise;

    const holder = {
      worker: null,
      queue: Promise.resolve(),
      lastParameters: '',
    };

    holder.promise = (async () => {
      if (!Tesseract.createWorker) return null;

      holder.worker = await Tesseract.createWorker(key, 1, OCR_ENGINE_OPTIONS);
      return holder;
    })();

    window.__crmCheckOcrWorkers[key] = holder;
    return holder.promise;
  }

  async function recognizeCheckImage(Tesseract, image, language, options = {}) {
    const ocrOptions = {
      ...OCR_ENGINE_OPTIONS,
      ...options,
    };

    try {
      const workerHolder = await ensureCheckOcrWorker(Tesseract, language);
      if (!workerHolder?.worker) {
        const result = await Tesseract.recognize(image.dataUrl, language, ocrOptions);

        return {
          role: image.role || '',
          priority: Number(image.priority || 0),
          text: result?.data?.text || '',
          confidence: Math.round(Number(result?.data?.confidence || 0) * 100) / 100,
          lines: normalizeOcrLines(result?.data?.lines || []),
        };
      }

      const task = workerHolder.queue
        .catch(() => {})
        .then(async () => {
          const parametersKey = JSON.stringify(options || {});
          if (workerHolder.lastParameters !== parametersKey && workerHolder.worker.setParameters) {
            await workerHolder.worker.setParameters(options || {});
            workerHolder.lastParameters = parametersKey;
          }

          return workerHolder.worker.recognize(image.dataUrl);
        });
      workerHolder.queue = task.catch(() => {});
      const result = await task;

      return {
        role: image.role || '',
        priority: Number(image.priority || 0),
        text: result?.data?.text || '',
        confidence: Math.round(Number(result?.data?.confidence || 0) * 100) / 100,
        lines: normalizeOcrLines(result?.data?.lines || []),
      };
    } catch (error) {
      const fallbackLanguage = language.includes('fra') ? 'eng' : language;
      const fallback = await Tesseract.recognize(image.dataUrl, fallbackLanguage, ocrOptions);

      return {
        role: image.role || '',
        priority: Number(image.priority || 0),
        text: fallback?.data?.text || '',
        confidence: Math.round(Number(fallback?.data?.confidence || 0) * 100) / 100,
        lines: normalizeOcrLines(fallback?.data?.lines || []),
      };
    }
  }

  async function prepareCheckOcrImages(dataUrl) {
    const image = await loadImage(dataUrl);
    const oriented = orientCheckImage(image);

    return {
      rotation: oriented.rotation,
      visualChecks: analyzeCheckVisualReadiness(oriented.canvas),
      inkAmount: detectHandwrittenAmount(oriented.canvas),
      full: null,
      amounts: [
        renderOcrImage(
          oriented.canvas,
          { x: 0.78, y: 0.34, width: 0.2, height: 0.24 },
          {
            minWidth: 900,
            maxWidth: 1300,
            threshold: true,
            role: 'amount',
            priority: 135,
            ocrOptions: { tessedit_pageseg_mode: '7', user_defined_dpi: '240' },
          },
        ),
        renderOcrImage(
          oriented.canvas,
          { x: 0.74, y: 0.29, width: 0.25, height: 0.32 },
          {
            minWidth: 950,
            maxWidth: 1350,
            threshold: true,
            role: 'amount',
            priority: 115,
            ocrOptions: { tessedit_pageseg_mode: '6', user_defined_dpi: '240' },
          },
        ),
        renderOcrImage(
          oriented.canvas,
          { x: 0.8, y: 0.38, width: 0.17, height: 0.15 },
          {
            minWidth: 850,
            maxWidth: 1200,
            blueInk: true,
            role: 'amount',
            priority: 95,
            ocrOptions: { tessedit_pageseg_mode: '7', user_defined_dpi: '240' },
          },
        ),
      ],
      names: [
        renderOcrImage(
          oriented.canvas,
          { x: 0.36, y: 0.56, width: 0.34, height: 0.25 },
          {
            minWidth: 1000,
            maxWidth: 1450,
            threshold: true,
            role: 'payer',
            priority: 130,
            ocrOptions: { tessedit_pageseg_mode: '6', user_defined_dpi: '240' },
          },
        ),
        renderOcrImage(
          oriented.canvas,
          { x: 0.31, y: 0.5, width: 0.43, height: 0.34 },
          {
            minWidth: 1050,
            maxWidth: 1500,
            threshold: true,
            role: 'payer',
            priority: 105,
            ocrOptions: { tessedit_pageseg_mode: '6', user_defined_dpi: '240' },
          },
        ),
        renderOcrImage(
          oriented.canvas,
          { x: 0.32, y: 0.42, width: 0.46, height: 0.42 },
          {
            minWidth: 1000,
            maxWidth: 1450,
            role: 'payer',
            priority: 75,
            ocrOptions: { tessedit_pageseg_mode: '6', user_defined_dpi: '220' },
          },
        ),
      ],
    };
  }

  function orientCheckImage(image) {
    const sourceWidth = image.naturalWidth || image.width;
    const sourceHeight = image.naturalHeight || image.height;
    if (sourceHeight > sourceWidth * 1.12) {
      return {
        rotation: 270,
        canvas: rotateImageToCanvas(image, 270),
        score: 0,
      };
    }

    const rotations = [0, 90, 180, 270]
      .map((rotation) => {
        const canvas = rotateImageToCanvas(image, rotation);

        return {
          rotation,
          canvas,
          score: scoreCheckOrientation(canvas),
        };
      })
      .sort((a, b) => b.score - a.score);

    return rotations[0];
  }

  function rotateImageToCanvas(image, rotation) {
    const sourceWidth = image.naturalWidth || image.width;
    const sourceHeight = image.naturalHeight || image.height;
    const rightAngle = rotation === 90 || rotation === 270;
    const canvas = document.createElement('canvas');
    canvas.width = rightAngle ? sourceHeight : sourceWidth;
    canvas.height = rightAngle ? sourceWidth : sourceHeight;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    if (rotation === 90) {
      ctx.translate(canvas.width, 0);
      ctx.rotate(Math.PI / 2);
    } else if (rotation === 180) {
      ctx.translate(canvas.width, canvas.height);
      ctx.rotate(Math.PI);
    } else if (rotation === 270) {
      ctx.translate(0, canvas.height);
      ctx.rotate(-Math.PI / 2);
    }

    ctx.drawImage(image, 0, 0);

    return canvas;
  }

  function scoreCheckOrientation(canvas) {
    const sampleWidth = 420;
    const sampleHeight = Math.max(1, Math.round(canvas.height * (sampleWidth / canvas.width)));
    const sample = document.createElement('canvas');
    sample.width = sampleWidth;
    sample.height = sampleHeight;
    const ctx = sample.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(canvas, 0, 0, sampleWidth, sampleHeight);

    const bottom = darkPixelRatio(ctx, sampleWidth, sampleHeight, 0.05, 0.78, 0.78, 0.16);
    const top = darkPixelRatio(ctx, sampleWidth, sampleHeight, 0.05, 0.04, 0.78, 0.16);
    const rightAmountZone = darkPixelRatio(ctx, sampleWidth, sampleHeight, 0.68, 0.2, 0.27, 0.34);
    const centerPayerZone = darkPixelRatio(ctx, sampleWidth, sampleHeight, 0.32, 0.42, 0.4, 0.34);
    const landscapeScore = canvas.width > canvas.height ? 120 : -120;

    return landscapeScore + (bottom - top) * 900 + bottom * 420 + rightAmountZone * 140 + centerPayerZone * 100;
  }

  function darkPixelRatio(ctx, canvasWidth, canvasHeight, x, y, width, height) {
    const sx = Math.max(0, Math.round(canvasWidth * x));
    const sy = Math.max(0, Math.round(canvasHeight * y));
    const sw = Math.max(1, Math.min(canvasWidth - sx, Math.round(canvasWidth * width)));
    const sh = Math.max(1, Math.min(canvasHeight - sy, Math.round(canvasHeight * height)));
    const imageData = ctx.getImageData(sx, sy, sw, sh);
    const data = imageData.data;
    let dark = 0;

    for (let index = 0; index < data.length; index += 4) {
      const gray = data[index] * 0.299 + data[index + 1] * 0.587 + data[index + 2] * 0.114;
      if (gray < 115) dark += 1;
    }

    return dark / Math.max(1, data.length / 4);
  }

  function analyzeCheckVisualReadiness(canvas) {
    return {
      signature: detectInkInZone(canvas, { x: 0.66, y: 0.52, width: 0.31, height: 0.34 }, 0.006),
      order: detectLargeHandwritingInZone(
        canvas,
        { x: 0.1, y: 0.48, width: 0.58, height: 0.14 },
        {
          minArea: 1200,
          minWidth: 85,
          minHeight: 28,
        },
      ),
    };
  }

  function detectInkInZone(canvas, zone, minRatio) {
    const sampleWidth = 560;
    const sampleHeight = Math.max(1, Math.round(canvas.height * (sampleWidth / canvas.width)));
    const sample = document.createElement('canvas');
    sample.width = sampleWidth;
    sample.height = sampleHeight;
    const ctx = sample.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(canvas, 0, 0, sampleWidth, sampleHeight);

    const sx = Math.max(0, Math.round(sampleWidth * zone.x));
    const sy = Math.max(0, Math.round(sampleHeight * zone.y));
    const sw = Math.max(1, Math.min(sampleWidth - sx, Math.round(sampleWidth * zone.width)));
    const sh = Math.max(1, Math.min(sampleHeight - sy, Math.round(sampleHeight * zone.height)));
    const imageData = ctx.getImageData(sx, sy, sw, sh);
    const data = imageData.data;
    let ink = 0;

    for (let index = 0; index < data.length; index += 4) {
      const red = data[index];
      const green = data[index + 1];
      const blue = data[index + 2];
      const gray = red * 0.299 + green * 0.587 + blue * 0.114;
      const blueInk = gray < 205 && blue > 65 && blue > red * 0.7 && (blue - green > 10 || red - green > 8);
      const darkInk = gray < 82;

      if (blueInk || darkInk) ink += 1;
    }

    return ink / Math.max(1, data.length / 4) >= minRatio;
  }

  function detectOrderLabel(text) {
    const normalized = normalizeFrenchOcrText(text);

    return (
      /MARTIN\s*SOLS/.test(normalized) || /MART[1I]N\s*S[O0]LS/.test(normalized) || /MARTIN\s*S0LS/.test(normalized)
    );
  }

  function detectLargeHandwritingInZone(canvas, zone, limits) {
    const sample = amountInkSample(canvas, zone);
    return amountInkComponents(sample).some((component) => {
      const ratio = component.width / Math.max(1, component.height);

      return (
        component.area >= limits.minArea &&
        component.width >= limits.minWidth &&
        component.height >= limits.minHeight &&
        ratio >= 1.05
      );
    });
  }

  function detectHandwrittenAmount(canvas) {
    const zones = [
      { x: 0.795, y: 0.392, width: 0.125, height: 0.13 },
      { x: 0.785, y: 0.392, width: 0.145, height: 0.13 },
      { x: 0.805, y: 0.398, width: 0.11, height: 0.115 },
    ];
    const candidates = zones
      .map((zone, index) => readHandwrittenAmountZone(canvas, zone, index))
      .filter(Boolean)
      .filter((candidate) => candidate.amount.length >= 3 && candidate.amount.length <= 5);

    if (!candidates.length) return null;
    candidates.sort((a, b) => b.confidence - a.confidence || b.amount.length - a.amount.length);

    return candidates[0].confidence >= 68 ? candidates[0] : null;
  }

  function readHandwrittenAmountZone(canvas, zone, index) {
    const sample = amountInkSample(canvas, zone);
    const components = amountInkComponents(sample)
      .filter(
        (component) =>
          component.area > 70 && component.height > sample.height * 0.22 && component.width > sample.width * 0.025,
      )
      .sort((a, b) => a.x0 - b.x0);
    if (!components.length) return null;

    const maxHeight = Math.max(...components.map((component) => component.height));
    const boxes = components
      .filter(
        (component) => !(component.width / Math.max(1, component.height) > 1.15 && component.height < maxHeight * 0.85),
      )
      .flatMap((component) => splitAmountComponent(sample, component))
      .filter((box) => box.height >= maxHeight * 0.45)
      .filter((box) => !(box.width / Math.max(1, box.height) > 1.15 && box.height < maxHeight * 0.85))
      .sort((a, b) => a.x0 - b.x0)
      .slice(0, 5);

    const digits = boxes.map((box) => classifyAmountDigit(sample, box, maxHeight)).filter(Boolean);
    if (digits.length < 2) return null;

    return {
      amount: digits.map((digit) => digit.value).join(''),
      confidence: Math.round(digits.reduce((sum, digit) => sum + digit.confidence, 0) / digits.length - index * 3),
    };
  }

  function amountInkSample(canvas, zone) {
    const width = Math.max(1, Math.round(canvas.width * zone.width));
    const height = Math.max(1, Math.round(canvas.height * zone.height));
    const sx = Math.max(0, Math.round(canvas.width * zone.x));
    const sy = Math.max(0, Math.round(canvas.height * zone.y));
    const sample = document.createElement('canvas');
    sample.width = width;
    sample.height = height;
    const ctx = sample.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(canvas, sx, sy, width, height, 0, 0, width, height);
    const imageData = ctx.getImageData(0, 0, width, height);
    const data = imageData.data;
    const ink = new Uint8Array(width * height);

    for (let y = 0; y < height; y += 1) {
      for (let x = 0; x < width; x += 1) {
        const offset = (y * width + x) * 4;
        const red = data[offset];
        const green = data[offset + 1];
        const blue = data[offset + 2];
        const gray = red * 0.299 + green * 0.587 + blue * 0.114;
        const neutralDark = gray < 120 && Math.max(red, green, blue) - Math.min(red, green, blue) < 95;
        if (neutralDark) ink[y * width + x] = 1;
      }
    }

    return { width, height, ink };
  }

  function amountInkComponents(sample) {
    const { width, height, ink } = sample;
    const seen = new Uint8Array(width * height);
    const components = [];
    const queueX = [];
    const queueY = [];

    for (let y = 0; y < height; y += 1) {
      for (let x = 0; x < width; x += 1) {
        const index = y * width + x;
        if (!ink[index] || seen[index]) continue;

        let head = 0;
        let area = 0;
        let x0 = x;
        let x1 = x;
        let y0 = y;
        let y1 = y;
        queueX.length = 0;
        queueY.length = 0;
        queueX.push(x);
        queueY.push(y);
        seen[index] = 1;

        while (head < queueX.length) {
          const cx = queueX[head];
          const cy = queueY[head];
          head += 1;
          area += 1;
          if (cx < x0) x0 = cx;
          if (cx > x1) x1 = cx;
          if (cy < y0) y0 = cy;
          if (cy > y1) y1 = cy;

          for (let dy = -1; dy <= 1; dy += 1) {
            for (let dx = -1; dx <= 1; dx += 1) {
              if (!dx && !dy) continue;
              const nx = cx + dx;
              const ny = cy + dy;
              if (nx < 0 || ny < 0 || nx >= width || ny >= height) continue;
              const nextIndex = ny * width + nx;
              if (!ink[nextIndex] || seen[nextIndex]) continue;
              seen[nextIndex] = 1;
              queueX.push(nx);
              queueY.push(ny);
            }
          }
        }

        components.push({
          x0,
          x1,
          y0,
          y1,
          area,
          width: x1 - x0 + 1,
          height: y1 - y0 + 1,
        });
      }
    }

    return components;
  }

  function splitAmountComponent(sample, component) {
    const ratio = component.width / Math.max(1, component.height);
    if (ratio < 0.95 || ratio > 1.75) return [component];

    const split = bestAmountSplitColumn(sample, component);
    const left = amountBoxFromRange(sample, component.x0, split, component.y0, component.y1);
    const right = amountBoxFromRange(sample, split + 1, component.x1, component.y0, component.y1);

    return [left, right].filter(Boolean);
  }

  function bestAmountSplitColumn(sample, box) {
    const start = Math.round(box.x0 + box.width * 0.42);
    const end = Math.round(box.x0 + box.width * 0.64);
    let bestX = Math.round(box.x0 + box.width * 0.56);
    let bestScore = Infinity;

    for (let x = start; x <= end; x += 1) {
      let score = 0;
      for (let dx = -1; dx <= 1; dx += 1) {
        const column = x + dx;
        if (column < box.x0 || column > box.x1) continue;
        for (let y = box.y0; y <= box.y1; y += 1) {
          if (sample.ink[y * sample.width + column]) score += 1;
        }
      }
      if (score < bestScore) {
        bestScore = score;
        bestX = x;
      }
    }

    return bestX;
  }

  function amountBoxFromRange(sample, x0, x1, y0, y1) {
    let bx0 = x1;
    let bx1 = x0;
    let by0 = y1;
    let by1 = y0;
    let area = 0;

    for (let y = y0; y <= y1; y += 1) {
      for (let x = x0; x <= x1; x += 1) {
        if (!sample.ink[y * sample.width + x]) continue;
        area += 1;
        if (x < bx0) bx0 = x;
        if (x > bx1) bx1 = x;
        if (y < by0) by0 = y;
        if (y > by1) by1 = y;
      }
    }

    if (!area) return null;

    return {
      x0: bx0,
      x1: bx1,
      y0: by0,
      y1: by1,
      area,
      width: bx1 - bx0 + 1,
      height: by1 - by0 + 1,
    };
  }

  function classifyAmountDigit(sample, box, maxHeight) {
    const ratio = box.width / Math.max(1, box.height);
    if (ratio > 1.15 && box.height < maxHeight * 0.85) return null;

    const left = amountZoneDensity(sample, box, 0, 0.3, 0.15, 0.85);
    const right = amountZoneDensity(sample, box, 0.7, 1, 0.15, 0.85);
    const middle = amountZoneDensity(sample, box, 0.35, 0.65, 0.3, 0.7);
    const top = amountZoneDensity(sample, box, 0.15, 0.85, 0, 0.25);
    const bottom = amountZoneDensity(sample, box, 0.15, 0.85, 0.75, 1);

    if (ratio < 0.34 && right > 0.08) {
      return { value: '1', confidence: 72 };
    }

    if (middle < 0.05 && top > 0.09 && bottom > 0.08 && (left > 0.08 || right > 0.07)) {
      return { value: '0', confidence: 84 };
    }

    if (left < 0.09 && right > 0.13 && middle > 0.08) {
      return { value: '3', confidence: 82 };
    }

    if (middle > 0.22 && left > 0.08 && right > 0.1) {
      return { value: '8', confidence: 84 };
    }

    if (top > 0.16 && bottom < 0.09 && right >= left) {
      return { value: '7', confidence: 68 };
    }

    if (top > 0.12 && bottom > 0.12 && left > 0.1 && right < 0.12) {
      return { value: '6', confidence: 66 };
    }

    if (top > 0.12 && middle > 0.1 && bottom < 0.11 && right > 0.1) {
      return { value: '9', confidence: 64 };
    }

    return null;
  }

  function amountZoneDensity(sample, box, xStart, xEnd, yStart, yEnd) {
    const x0 = Math.max(box.x0, Math.floor(box.x0 + box.width * xStart));
    const x1 = Math.min(box.x1, Math.floor(box.x0 + box.width * xEnd));
    const y0 = Math.max(box.y0, Math.floor(box.y0 + box.height * yStart));
    const y1 = Math.min(box.y1, Math.floor(box.y0 + box.height * yEnd));
    let total = 0;
    let ink = 0;

    for (let y = y0; y <= y1; y += 1) {
      for (let x = x0; x <= x1; x += 1) {
        total += 1;
        if (sample.ink[y * sample.width + x]) ink += 1;
      }
    }

    return ink / Math.max(1, total);
  }

  function normalizeFrenchOcrText(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toUpperCase()
      .replace(/[|]/g, 'I')
      .replace(/[0]/g, 'O')
      .replace(/[^A-Z0-9]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function loadImage(dataUrl) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = () => reject(new Error('Image illisible'));
      image.src = dataUrl;
    });
  }

  function renderOcrImage(image, crop, options = {}) {
    const sourceWidth = image.naturalWidth || image.width;
    const sourceHeight = image.naturalHeight || image.height;
    const sx = Math.max(0, Math.round(sourceWidth * crop.x));
    const sy = Math.max(0, Math.round(sourceHeight * crop.y));
    const sw = Math.min(sourceWidth - sx, Math.round(sourceWidth * crop.width));
    const sh = Math.min(sourceHeight - sy, Math.round(sourceHeight * crop.height));
    const minWidth = options.minWidth || 1200;
    const maxWidth = options.maxWidth || 2200;
    const targetWidth = Math.min(maxWidth, Math.max(minWidth, sw * 2));
    const scale = targetWidth / Math.max(1, sw);
    const targetHeight = Math.max(1, Math.round(sh * scale));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(targetWidth);
    canvas.height = targetHeight;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(image, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);

    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = imageData.data;
    const threshold = Boolean(options.threshold);
    const blueInk = Boolean(options.blueInk);

    for (let index = 0; index < data.length; index += 4) {
      const red = data[index];
      const green = data[index + 1];
      const blue = data[index + 2];
      const gray = data[index] * 0.299 + data[index + 1] * 0.587 + data[index + 2] * 0.114;
      let value = Math.max(0, Math.min(255, (gray - 128) * 1.85 + 128));

      if (blueInk) {
        const isBlueInk = gray < 205 && blue > 70 && (blue - green > 12 || red - green > 10) && blue > red * 0.72;
        value = isBlueInk ? 0 : 255;
      } else if (threshold) {
        value = value > 168 ? 255 : value < 92 ? 0 : value;
      }

      data[index] = value;
      data[index + 1] = value;
      data[index + 2] = value;
    }

    ctx.putImageData(imageData, 0, 0);

    return {
      dataUrl: canvas.toDataURL('image/png'),
      width: canvas.width,
      height: canvas.height,
      role: options.role || '',
      priority: Number(options.priority || 0),
      ocrOptions: options.ocrOptions || null,
    };
  }

  function parseCheckOcr(results) {
    const amountResults = results.amountResults || (results.amount ? [results.amount] : []);
    const nameResults = results.nameResults || (results.name ? [results.name] : []);
    const amountText = amountResults.map((result) => result?.text || '').join('\n');
    const nameText = nameResults.map((result) => result?.text || '').join('\n');
    const nameLines = nameResults.flatMap((result) =>
      (result?.lines || []).map((line) => ({
        ...line,
        source: result?.role || 'name',
        priority: Number(result?.priority || 0),
      })),
    );
    const amount = parseOcrAmount(amountText, results.full?.text || '');
    const payerName = parseOcrName(nameLines, results.full?.lines || [], nameText, results.full?.text || '');
    const confidences = [...amountResults, ...nameResults, results.full]
      .map((result) => Number(result?.confidence || 0))
      .filter((value) => value > 0);
    const confidence = confidences.length
      ? Math.round((confidences.reduce((sum, value) => sum + value, 0) / confidences.length) * 100) / 100
      : null;

    return { amount, payerName, confidence };
  }

  function parseOcrAmount(amountText, fullText = '') {
    const candidates = [...amountCandidates(amountText, 100), ...amountCandidates(fullText, 20)].filter(
      (candidate) => candidate.value > 0 && candidate.value < 100000,
    );

    if (!candidates.length) return '';

    candidates.sort((a, b) => b.score - a.score || b.value - a.value);

    return candidates[0].value.toFixed(2).replace('.', ',');
  }

  function amountCandidates(text, sourceScore) {
    const normalized = String(text || '')
      .replace(/[Oo](?=[0-9])/g, '0')
      .replace(/(?<=[0-9])[Oo]/g, '0')
      .replace(/[€]/g, ' EUR ')
      .replace(/[€]/g, ' EUR ');
    const candidates = [];
    const decimalPattern = /(?:EUR|EUROS?)?\s*([0-9][0-9 .'\u00a0]{0,10}[,.][0-9]{2})\s*(?:EUR|EUROS?)?/gi;
    const thousandSpacedDecimalPattern =
      /(?:EUR|EUROS?)?\s*([0-9]{1,3}(?:[ '\u00a0][0-9]{3})+)\s+([0-9]{2})\s*(?:EUR|EUROS?)?/gi;
    const spacedDecimalPattern = /(?:EUR|EUROS?)?\s*([0-9]{1,5})\s+([0-9]{2})\s*(?:EUR|EUROS?)?/gi;
    const integerCurrencyPattern =
      /(?:EUR|EUROS?)\s*([0-9][0-9 .'\u00a0]{1,10})|([0-9][0-9 .'\u00a0]{1,10})\s*(?:EUR|EUROS?)/gi;

    for (const match of normalized.matchAll(decimalPattern)) {
      const raw = match[1].replace(/[ '\u00a0.]/g, '').replace(',', '.');
      const value = Math.round(Number(raw) * 100) / 100;
      if (!Number.isFinite(value)) continue;

      candidates.push({
        value,
        score:
          sourceScore + 40 + Math.min(24, raw.split('.')[0].length * 6) + (/(EUR|EUROS?)/i.test(match[0]) ? 15 : 0),
      });
    }

    for (const match of normalized.matchAll(thousandSpacedDecimalPattern)) {
      const raw = `${match[1].replace(/[ '\u00a0]/g, '')}.${match[2]}`;
      const value = Math.round(Number(raw) * 100) / 100;
      if (!Number.isFinite(value)) continue;

      candidates.push({
        value,
        score: sourceScore + 38 + (/(EUR|EUROS?)/i.test(match[0]) ? 16 : 0),
      });
    }

    for (const match of normalized.matchAll(spacedDecimalPattern)) {
      const hasCurrency = /(EUR|EUROS?)/i.test(match[0]);
      if (/\r|\n/.test(match[0])) continue;
      if (!hasCurrency && match[1].length > 4) continue;
      if (!hasCurrency && sourceScore < 80) continue;
      const value = Math.round(Number(`${match[1]}.${match[2]}`) * 100) / 100;
      if (!Number.isFinite(value)) continue;

      candidates.push({
        value,
        score: sourceScore + 26 + (hasCurrency ? 16 : 0),
      });
    }

    for (const match of normalized.matchAll(integerCurrencyPattern)) {
      const raw = (match[1] || match[2] || '').replace(/[ '\u00a0.]/g, '');
      if (!raw || raw.length > 6) continue;
      if (sourceScore >= 80 && raw.length < 3) continue;
      const value = Number(raw);
      if (!Number.isFinite(value)) continue;

      candidates.push({
        value,
        score: sourceScore + 10,
      });
    }

    if (sourceScore >= 110) {
      const bareIntegerPattern = /\b([1-9][0-9]{2,4})\b/g;
      for (const line of normalized.split(/\r?\n/)) {
        if (/[0-9]{5,}/.test(line)) continue;

        for (const match of line.matchAll(bareIntegerPattern)) {
          const value = Number(match[1]);
          if (!Number.isFinite(value) || value <= 0 || value >= 10000) continue;

          candidates.push({
            value,
            score: sourceScore + 18 + Math.min(20, match[1].length * 5),
          });
        }
      }
    }

    return [...candidates, ...fuzzyAmountCandidates(normalized, sourceScore)];
  }

  function fuzzyAmountCandidates(text, sourceScore) {
    if (sourceScore < 80) return [];

    const candidates = [];
    const chunks = String(text || '')
      .toUpperCase()
      .split(/\r?\n/)
      .flatMap((line) => line.match(/[0-9OQIDCSEBGZAITL|., '\u00a0-]{4,22}/g) || []);

    for (const chunk of chunks) {
      if (/[0-9]{5,}/.test(chunk)) continue;
      if (!/[,.]|\s[0-9OQIDCSEBGZAITL|]{1,2}\s*$/i.test(chunk)) continue;

      for (const variant of amountTextVariants(chunk)) {
        const normalized = variant.text.replace(/[ '\u00a0-]/g, '').replace(',', '.');
        if (/^[0-9]{5,}/.test(normalized)) continue;
        const match = normalized.match(/^([0-9]{2,6})\.([0-9]{2})$/);
        if (!match) continue;

        const value = Math.round(Number(`${match[1]}.${match[2]}`) * 100) / 100;
        if (!Number.isFinite(value) || value <= 0 || value >= 100000) continue;

        candidates.push({
          value,
          score: sourceScore + 28 + Math.min(36, match[1].length * 9) + variant.score,
        });
      }
    }

    return candidates;
  }

  function amountTextVariants(value) {
    const map = {
      O: [{ value: '0', score: 1 }],
      Q: [{ value: '0', score: 1 }],
      D: [{ value: '0', score: 0.8 }],
      I: [{ value: '1', score: 1 }],
      L: [{ value: '1', score: 0.8 }],
      '|': [{ value: '1', score: 0.8 }],
      Z: [{ value: '2', score: 1 }],
      C: [{ value: '2', score: 1 }],
      E: [{ value: '3', score: 1 }],
      A: [{ value: '4', score: 0.8 }],
      S: [
        { value: '2', score: 0.9 },
        { value: '5', score: 0.35 },
        { value: '6', score: 0.5 },
      ],
      B: [{ value: '8', score: 0.8 }],
      G: [{ value: '6', score: 0.8 }],
      T: [{ value: '7', score: 0.7 }],
    };
    let variants = [{ text: '', score: 0, previousRaw: '' }];

    for (const char of String(value || '').toUpperCase()) {
      const replacements = /[0-9., '\u00a0-]/.test(char)
        ? [{ value: char, score: 1 }]
        : map[char] || [{ value: '', score: 0 }];
      const next = [];
      for (const prefix of variants) {
        for (const replacement of replacements) {
          let score = prefix.score + replacement.score;
          if (char === 'S' && prefix.previousRaw === 'C' && replacement.value === '6') score += 0.9;
          if (char === 'S' && prefix.previousRaw === 'E' && replacement.value === '2') score += 0.9;
          next.push({
            text: prefix.text + replacement.value,
            score,
            previousRaw: /[A-Z]/.test(char) ? char : prefix.previousRaw,
          });
          if (next.length >= 80) break;
        }
        if (next.length >= 80) break;
      }
      variants = next;
    }

    const seen = new Map();
    for (const variant of variants) {
      const current = seen.get(variant.text);
      if (!current || variant.score > current.score) {
        seen.set(variant.text, { text: variant.text, score: variant.score });
      }
    }

    return [...seen.values()];
  }

  function parseOcrName(nameLines, fullLines, nameText, fullText) {
    const fromLines = [...nameLines, ...fullLines.map((line) => ({ ...line, source: 'full', priority: 0 }))];
    const fromText = `${nameText || ''}\n${fullText || ''}`
      .split(/\r?\n/)
      .map((text) => ({ text, source: 'text', priority: 0 }));
    const candidates = [...fromLines, ...fromText]
      .flatMap((line) => expandNameCandidateLine(line))
      .map((line) => scoreNameCandidate(line))
      .filter(Boolean);

    candidates.sort((a, b) => b.score - a.score);

    return candidates[0]?.text || '';
  }

  function resolveDetectedPayerName(value) {
    let candidate = cleanNameCandidate(value || '')
      .replace(/\b(?:vle|ville)\b$/i, '')
      .replace(/\s+/g, ' ')
      .trim();
    if (!candidate) return '';

    const known = knownPayerNames();
    const normalizedCandidate = normalizeNameForMatch(candidate);
    let best = null;

    for (const name of known) {
      const normalizedKnown = normalizeNameForMatch(name);
      if (!normalizedKnown || normalizedKnown === normalizedCandidate) {
        best = { name, score: 1 };
        break;
      }

      const containsScore =
        normalizedKnown.includes(normalizedCandidate) && normalizedCandidate.length >= 7
          ? 0.96
          : normalizedCandidate.includes(normalizedKnown) && normalizedKnown.length >= 7
            ? 0.9
            : 0;
      const score = containsScore || nameSimilarity(normalizedCandidate, normalizedKnown);
      if (!best || score > best.score) best = { name, score };
    }

    if (best && best.score >= 0.72) return best.name;

    return candidate;
  }

  function knownPayerNames() {
    const names = new Set();
    const add = (value) => {
      const cleaned = cleanNameCandidate(value || '');
      if (cleaned.length >= 4) names.add(cleaned);
    };

    const remittances = [
      ...(state.detailRemittance ? [state.detailRemittance] : []),
      ...(state.data?.remittances || []),
    ];
    remittances.forEach((remittance) => {
      (remittance.checks || []).forEach((check) => add(check.payerName));
    });

    return [...names];
  }

  function normalizeNameForMatch(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toUpperCase()
      .replace(/\b(?:VLE|VILLE)\b/g, '')
      .replace(/[^A-Z0-9]/g, '');
  }

  function nameSimilarity(a, b) {
    if (!a || !b) return 0;
    const distance = levenshteinDistance(a, b);
    return 1 - distance / Math.max(a.length, b.length, 1);
  }

  function levenshteinDistance(a, b) {
    const previous = Array.from({ length: b.length + 1 }, (_, index) => index);
    const current = new Array(b.length + 1);

    for (let i = 1; i <= a.length; i += 1) {
      current[0] = i;
      for (let j = 1; j <= b.length; j += 1) {
        current[j] =
          a[i - 1] === b[j - 1] ? previous[j - 1] : Math.min(previous[j - 1] + 1, previous[j] + 1, current[j - 1] + 1);
      }
      for (let j = 0; j <= b.length; j += 1) previous[j] = current[j];
    }

    return previous[b.length];
  }

  function expandNameCandidateLine(line) {
    const text = cleanOcrLine(line.text || '');
    const guidedSegments = [
      ...(text.match(/(?:ordre\s+de|l['\u2019]ordre\s+de)\s+(.+)$/i)?.[1]
        ? [text.match(/(?:ordre\s+de|l['\u2019]ordre\s+de)\s+(.+)$/i)[1]]
        : []),
      ...(text.match(/martin\s+sols\s+(.+)$/i)?.[1] ? [text.match(/martin\s+sols\s+(.+)$/i)[1]] : []),
      ...(text.match(/payez\s+(.+)$/i)?.[1] ? [text.match(/payez\s+(.+)$/i)[1]] : []),
    ];
    const segments = text
      .split(/\s*[*/|\\;]\s*|\s{2,}/)
      .map((segment) => cleanOcrLine(segment))
      .filter((segment) => segment.length >= 4 && segment !== text);

    return [
      line,
      ...guidedSegments.map((segment) => ({
        ...line,
        text: cleanOcrLine(segment),
        priority: Number(line.priority || 0) + 18,
      })),
      ...segments.map((segment) => ({
        ...line,
        text: segment,
        priority: Math.max(0, Number(line.priority || 0) - 15),
      })),
    ];
  }

  function normalizeOcrLines(lines) {
    return lines
      .map((line) => ({
        text: cleanOcrLine(line?.text || ''),
        bbox: line?.bbox || null,
      }))
      .filter((line) => line.text !== '');
  }

  function scoreNameCandidate(line) {
    let text = cleanNameCandidate(line.text || '');
    if (!text) return null;

    const blocked =
      /(banque|ch[eè]que|payez|ordre|euro|iban|bic|signature|signatur|montant|date|france|code|compte|client|guichet|rib|payable|endossable|somme|lettres?|toutes?|tontes|mnfe|martin\s+sols|caisse|epargne|aquitaine|poitou|charentes|cic|sud\s+ouest|cr[eé]dit|agricole|mutuel|populaire|postale|soci[eé]t[eé]\s+g[eé]n[eé]rale|bnp|lcl|tel\.?|t[eé]l\.?|t[eé]l[eé]phone|rue|route|avenue|boulevard|\bbd\b|chemin|impasse|all[eé]e|place|quartier|lieu\s+dit|r[eé]sidence|lotissement|\bza\b|\bzi\b|\bbp\b|cedex|\bcs\b|ch[eè]que\s+n|\bpau\b|mazerolles|morlaas|maucor|adour|germe|bart[eé]cagnia|grabots|pelut)/i;
    if (blocked.test(text)) return null;
    if (/\b[0-9]{1,4}\s+(?:rue|route|avenue|boulevard|bd|chemin|impasse|allee|all[eé]e)\b/i.test(text)) return null;
    if (!/[A-Za-zÀ-ÿ]/.test(text)) return null;
    if (/\d{3,}/.test(text)) return null;
    if (text.length < 4 || text.length > 55) return null;

    const words = text.split(/\s+/).filter(Boolean);
    if (words.length > 7) return null;
    if (
      words.length === 1 &&
      /^[A-ZÀ-Ÿ-]+$/.test(text) &&
      !/^(m|me|mme|mr|monsieur|madame|sarl|sas|eurl|sasu|ets)\b/i.test(text)
    )
      return null;
    if (Math.max(...words.map((word) => word.replace(/[^A-Za-zÀ-ÿ]/g, '').length)) < 5) return null;

    let score = 0;
    score += Number(line.priority || 0);
    score += line.source === 'payer' ? 42 : line.source === 'body' || line.source === 'name' ? 18 : 6;
    score += Math.min(text.length, 32);
    score += words.length >= 2 ? 18 : 0;
    score += /^(m|me|mme|mr|monsieur|madame|sarl|sas|eurl|sasu|ets)\b/i.test(text) ? 18 : 0;
    score += /^(me|mme|mr|monsieur|madame)\b/i.test(text) ? 55 : 0;
    score += /\b(ei|eirl|sarl|sas|eurl|sasu)\b/i.test(text) ? 10 : 0;
    score += /^[A-ZÀ-Ÿ0-9 &'().-]+$/.test(text) ? 8 : 0;
    score += line.source === 'payer' && /^[A-ZÀ-Ÿ &'().-]+$/.test(text) && words.length >= 2 ? 24 : 0;

    if (line.bbox) {
      const x0 = Number(line.bbox.x0 || 0);
      const y0 = Number(line.bbox.y0 || 0);
      if (x0 < 900) score += 8;
      if (y0 < 900) score += 6;
    }

    return { text, score };
  }

  function cleanOcrLine(value) {
    return String(value || '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function cleanNameCandidate(value) {
    return cleanOcrLine(value)
      .replace(/^[^A-Za-zÀ-ÿ]+/, '')
      .replace(/[^A-Za-zÀ-ÿ0-9 &'’().-]+/g, ' ')
      .replace(/\b(?:a|à)\s+l['’]ordre\s+de\b.*$/i, '')
      .replace(/\b(?:payez|payer)\b.*$/i, '')
      .replace(/\s+\d{1,2}$/g, '')
      .replace(/\s+(?:la|le)$/i, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  async function withSaving(callback) {
    try {
      state.saving = true;
      setBusy(true);
      await callback();
    } catch (error) {
      window.alert(error instanceof Error ? error.message : 'Opération impossible');
    } finally {
      state.saving = false;
      setBusy(false);
      render();
    }
  }

  function setBusy(isBusy) {
    root?.querySelectorAll('button,input,select,textarea').forEach((element) => {
      if (isBusy) {
        element.dataset.wasDisabled = element.disabled ? '1' : '0';
        element.disabled = true;
      } else if (element.dataset.wasDisabled === '0') {
        element.disabled = false;
      }
    });
  }

  function printRemittance(remittance) {
    const rows = (remittance.checks || [])
      .map(
        (check, index) => `
      <tr>
        <td>${index + 1}</td>
        <td>${esc(check.payerName || '')}</td>
        <td>${esc(check.invoiceNumber || '')}</td>
        <td class="amount">${esc(money(check.amount || 0))}</td>
      </tr>
    `,
      )
      .join('');

    const win = window.open('', '_blank');
    if (!win) {
      window.alert('Ouverture impression bloquée');
      return;
    }

    win.document.write(`
      <!doctype html>
      <html lang="fr">
        <head>
          <meta charset="UTF-8">
          <title>${esc(remittance.reference || 'Remise de chèques')}</title>
          <style>
            :root{${themeCssVariables()}}
            body{font-family:Arial,sans-serif;color:#0f172a;margin:32px}
            header{display:flex;justify-content:space-between;gap:24px;border-bottom:3px solid var(--theme-primary-color);padding-bottom:18px;margin-bottom:24px}
            h1{margin:0;font-size:26px}
            p{margin:4px 0;color:#475569}
            .total{border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;text-align:right}
            .total strong{display:block;font-size:24px;color:var(--theme-primary-color)}
            table{width:100%;border-collapse:collapse;margin-top:18px}
            th,td{border-bottom:1px solid #e2e8f0;padding:10px;text-align:left;font-size:13px}
            th{background:#f8fafc;color:#475569;text-transform:uppercase;font-size:11px}
            .amount{text-align:right;font-weight:700}
            footer{margin-top:32px;display:grid;grid-template-columns:1fr 1fr;gap:24px}
            footer div{height:80px;border:1px dashed #cbd5e1;border-radius:8px;padding:12px;color:#64748b}
            @media print{body{margin:18mm}.no-print{display:none}}
          </style>
        </head>
        <body>
          <header>
            <div>
              <h1>Remise de chèques</h1>
              <p>${esc(remittance.reference || '')}</p>
              <p>${esc(activeSiteName())} - ${esc(dateLabel(remittance.remittanceDate))}</p>
              <p>${esc(remittance.bankName || '')}</p>
            </div>
            <div class="total">
              <span>${esc(remittance.checkCount || 0)} chèque(s)</span>
              <strong>${esc(money(remittance.totalAmount || 0))}</strong>
            </div>
          </header>
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Nom</th>
                <th>Facture</th>
                <th class="amount">Montant</th>
              </tr>
            </thead>
            <tbody>${rows || `<tr><td colspan="7">Aucun chèque</td></tr>`}</tbody>
          </table>
          <footer>
            <div>Depot banque</div>
            <div>Signature</div>
          </footer>
          <script>setTimeout(function(){ window.focus(); window.print(); }, 250);<\/script>
        </body>
      </html>
    `);
    win.document.close();
  }

  function isRoute() {
    const path = window.location.pathname.replace(/\/$/, '');
    return path === routePath || path.startsWith(`${routePath}/`);
  }

  function findOutlet() {
    const explicit = document.getElementById('crm-check-remittance-module');
    if (explicit) return explicit;

    const layout = document.querySelector('.layout-container.layout-page') || document.querySelector('.layout-page');
    if (layout) return layout;

    const mains = [...document.querySelectorAll('main')].filter((element) => !element.closest('aside'));
    if (mains.length) return mains[mains.length - 1];

    return document.getElementById('root');
  }

  function ensureHost() {
    if (!isRoute()) return;

    let host = document.getElementById('crm-check-remittance-module');
    if (!host) {
      return;
    }

    if (mountedRoots.has(host)) return;
    root = host;
    mountedRoots.add(host);
    load();
  }

  function watchRouteChanges() {
    if (window.__crmCheckRemittanceRouteWatcher) return;
    window.__crmCheckRemittanceRouteWatcher = true;

    window.addEventListener('popstate', () => window.dispatchEvent(new Event('crm:check-remittance-route-changed')));
    window.addEventListener('crm:navigation', () => window.setTimeout(syncRoute, 0));
    window.addEventListener('crm:route-changed', () => window.setTimeout(ensureHost, 0));
    window.addEventListener('crm:check-remittance-route-changed', () => window.setTimeout(syncRoute, 0));
  }

  function syncRoute() {
    if (!isRoute()) return;
    ensureHost();
    if (root && mountedRoots.has(root)) load();
  }

  function styles() {
    if (document.getElementById('crm-check-remittance-style')) return;
    const style = document.createElement('style');
    style.id = 'crm-check-remittance-style';
    style.textContent = `
      .layout-container.layout-page:has(#crm-check-remittance-module),
      .layout-page:has(#crm-check-remittance-module){width:100%;max-width:100%;min-width:0;overflow-x:hidden}
      main:has(#crm-check-remittance-module){min-width:0;overflow-x:hidden}
      #crm-check-remittance-module{color:var(--color-secondary-900,#0f172a);width:100%;max-width:100%;min-width:0;overflow-x:hidden;box-sizing:border-box}
      #crm-check-remittance-module *{box-sizing:border-box}
      #crm-check-remittance-module .check-page{display:grid;gap:1rem;width:100%;max-width:100%;min-width:0}
      #crm-check-remittance-module .check-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem}
      #crm-check-remittance-module h1,#crm-check-remittance-module h2{margin:0;color:var(--color-secondary-900,#0f172a);letter-spacing:0;line-height:1.15}
      #crm-check-remittance-module h1{font-size:1.55rem;font-weight:600}
      #crm-check-remittance-module h2{font-size:1rem;font-weight:600}
      #crm-check-remittance-module p{margin:.25rem 0 0;color:var(--color-secondary-500,#64748b);font-size:.86rem;font-weight:750}
      #crm-check-remittance-module .check-actions{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:.5rem}
      #crm-check-remittance-module .check-card,#crm-check-remittance-module .check-summary-card{border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.05);min-width:0}
      #crm-check-remittance-module .check-card{overflow:hidden}
      #crm-check-remittance-module .check-loading{display:grid;min-height:16rem;place-items:center;color:var(--color-secondary-500,#64748b);font-weight:850}
      #crm-check-remittance-module .check-notice{border:1px solid #fecaca;border-radius:.5rem;background:#fef2f2;color:#991b1b;padding:.8rem;font-size:.86rem;font-weight:850}
      #crm-check-remittance-module .check-button,#crm-check-remittance-module .check-mini-button{display:inline-flex;align-items:center;justify-content:center;gap:.42rem;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:#fff;color:var(--color-secondary-700,#334155);font-weight:850;text-decoration:none;white-space:nowrap}
      #crm-check-remittance-module .check-button{min-height:2.4rem;padding:.55rem .8rem;font-size:.84rem}
      #crm-check-remittance-module .check-mini-button{min-height:2rem;padding:.4rem .55rem;font-size:.74rem}
      #crm-check-remittance-module .check-icon-button{width:2rem;min-width:2rem;padding:.35rem}
      #crm-check-remittance-module .check-icon{width:1rem;height:1rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #crm-check-remittance-module .check-button:hover,#crm-check-remittance-module .check-mini-button:hover{border-color:rgb(var(--theme-primary) / .45);color:rgb(var(--theme-primary))}
      #crm-check-remittance-module .check-button-primary{border-color:rgb(var(--theme-primary));background:rgb(var(--theme-primary));color:#fff}
      #crm-check-remittance-module .check-button-primary:hover{color:#fff;filter:brightness(.97)}
      #crm-check-remittance-module button:disabled,#crm-check-remittance-module input:disabled,#crm-check-remittance-module select:disabled,#crm-check-remittance-module textarea:disabled{opacity:.62;cursor:not-allowed}
      #crm-check-remittance-module input,#crm-check-remittance-module select,#crm-check-remittance-module textarea{min-height:2.45rem;width:100%;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:#fff;color:var(--color-secondary-900,#0f172a);padding:.55rem .65rem;font-size:.84rem;font-weight:750;outline:none}
      #crm-check-remittance-module textarea{min-height:5.6rem;resize:vertical}
      #crm-check-remittance-module input:focus,#crm-check-remittance-module select:focus,#crm-check-remittance-module textarea:focus{border-color:rgb(var(--theme-primary) / .55);box-shadow:0 0 0 3px rgb(var(--theme-primary) / .12)}
      #crm-check-remittance-module label{display:grid;gap:.35rem;min-width:0}
      #crm-check-remittance-module label span{color:var(--color-secondary-600,#475569);font-size:.76rem;font-weight:600}
      #crm-check-remittance-module .check-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.8rem}
      #crm-check-remittance-module .check-summary-card{display:grid;grid-template-columns:2.55rem minmax(0,1fr);align-items:center;gap:.75rem;min-width:0;padding:.9rem}
      #crm-check-remittance-module .check-summary-icon{display:grid;place-items:center;width:2.55rem;height:2.55rem;border-radius:.55rem;background:color-mix(in srgb,var(--check-card-color,var(--theme-primary-color)) 14%,white);color:var(--check-card-color,var(--theme-primary-color))}
      #crm-check-remittance-module .check-summary-card span:not(.check-summary-icon){display:block;color:var(--color-secondary-500,#64748b);font-size:.73rem;font-weight:600;text-transform:uppercase}
      #crm-check-remittance-module .check-summary-card strong{display:block;margin:.25rem 0;color:var(--color-secondary-900,#0f172a);font-size:1.17rem;font-weight:600;line-height:1.1;letter-spacing:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #crm-check-remittance-module .check-summary-card small{display:block;color:var(--color-secondary-400,#94a3b8);font-size:.72rem;font-weight:750;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #crm-check-remittance-module .check-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;padding:1rem}
      #crm-check-remittance-module .check-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--color-surface-200,#e2e8f0);padding:.9rem 1rem}
      #crm-check-remittance-module .check-section-head span{color:var(--color-secondary-500,#64748b);font-size:.78rem;font-weight:850}
      #crm-check-remittance-module .check-table-wrap{max-width:100%;overflow:auto;-webkit-overflow-scrolling:touch}
      #crm-check-remittance-module .check-table{width:100%;border-collapse:collapse;min-width:min(50rem,calc(100vw - 2rem))}
      #crm-check-remittance-module th,#crm-check-remittance-module td{border-bottom:1px solid var(--color-surface-200,#e2e8f0);padding:.72rem .8rem;text-align:left;font-size:.82rem;vertical-align:middle}
      #crm-check-remittance-module th{background:var(--color-surface-50,#f8fafc);color:var(--color-secondary-500,#64748b);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #crm-check-remittance-module td{color:var(--color-secondary-800,#1e293b);font-weight:750}
      #crm-check-remittance-module tr.is-selected td:first-child{box-shadow:inset 3px 0 rgb(var(--theme-primary))}
      #crm-check-remittance-module .check-actions-cell{text-align:right}
      #crm-check-remittance-module .check-row-actions{display:flex;justify-content:flex-end;gap:.4rem}
      #crm-check-remittance-module .check-link{border:0;background:transparent;color:rgb(var(--theme-primary));font-weight:600;padding:0;cursor:pointer}
      #crm-check-remittance-module .check-status{display:inline-flex;border-radius:999px;background:rgb(var(--theme-primary) / .1);color:rgb(var(--theme-primary));padding:.25rem .5rem;font-size:.72rem;font-weight:600}
      #crm-check-remittance-module .check-mini-button.is-danger:hover{border-color:#fecaca;color:#b91c1c;background:#fff7f7}
      #crm-check-remittance-module .check-empty{display:grid;gap:.55rem;justify-items:start;padding:1.1rem}
      #crm-check-remittance-module .check-empty strong{font-size:1rem}
      #crm-check-remittance-module .check-empty span{color:var(--color-secondary-500,#64748b);font-size:.86rem;font-weight:750}
      #crm-check-remittance-module .check-button-primary span{color:#fff;font-weight:600}
      #crm-check-remittance-module .check-empty.is-compact{border-top:1px solid var(--color-surface-200,#e2e8f0)}
      #crm-check-remittance-module .check-total-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.5rem;padding:1rem;border-bottom:1px solid var(--color-surface-200,#e2e8f0)}
      #crm-check-remittance-module .check-total-strip span{display:grid;gap:.15rem;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:var(--color-surface-50,#f8fafc);padding:.55rem .65rem;min-width:0}
      #crm-check-remittance-module .check-total-strip small{color:var(--color-secondary-500,#64748b);font-size:.68rem;font-weight:600;text-transform:uppercase}
      #crm-check-remittance-module .check-total-strip strong{color:var(--color-secondary-900,#0f172a);font-size:.9rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #crm-check-remittance-module .check-line-name{display:flex;align-items:center;gap:.55rem}
      #crm-check-remittance-module .check-line-name img,#crm-check-remittance-module .check-line-name span{width:2.25rem;height:2.25rem;border-radius:.45rem;border:1px solid var(--color-surface-200,#e2e8f0);object-fit:cover;background:var(--color-surface-50,#f8fafc)}
      #crm-check-remittance-module .check-line-name span{display:grid;place-items:center;color:rgb(var(--theme-primary))}
      #crm-check-remittance-module .check-photo-thumb{appearance:none;border:0;background:transparent;padding:0;cursor:pointer;line-height:0;border-radius:.45rem}
      #crm-check-remittance-module .check-photo-thumb:focus-visible{outline:3px solid rgb(var(--theme-primary) / .28);outline-offset:3px}
      #crm-check-remittance-module .check-modal-backdrop{position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;background:rgba(15,23,42,.45);padding:1rem}
      #crm-check-remittance-module .check-modal{width:min(100%,40rem);max-height:min(90vh,45rem);overflow:auto;border-radius:.75rem;background:#fff;box-shadow:0 24px 80px rgba(15,23,42,.25)}
      #crm-check-remittance-module .check-small-modal{width:min(100%,32rem)}
      #crm-check-remittance-module .check-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--color-surface-200,#e2e8f0);padding:1rem}
      #crm-check-remittance-module .check-modal-head h2{font-size:1.05rem}
      #crm-check-remittance-module .check-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;padding:1rem}
      #crm-check-remittance-module .check-field-full{grid-column:1 / -1}
      #crm-check-remittance-module .check-check-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;padding:1rem}
      #crm-check-remittance-module .check-photo-tools{grid-column:1 / -1;display:grid;grid-template-columns:minmax(7rem,.45fr) minmax(0,1fr);gap:.55rem;align-items:start}
      #crm-check-remittance-module .check-photo-bar{grid-column:1 / -1;display:flex;align-items:center;gap:.45rem;flex-wrap:wrap}
      #crm-check-remittance-module .check-photo-capture{cursor:pointer;min-height:2.35rem}
      #crm-check-remittance-module .check-photo-capture input{display:none}
      #crm-check-remittance-module .check-photo-preview-button{appearance:none;border:0;background:transparent;padding:0;cursor:pointer;text-align:left}
      #crm-check-remittance-module .check-photo-preview{width:100%;height:4.8rem;object-fit:cover;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:#fff}
      #crm-check-remittance-module .check-photo-viewer-backdrop{position:fixed;inset:0;z-index:2147483600;display:flex;align-items:center;justify-content:center;background:rgb(15 23 42 / .78);padding:1rem}
      #crm-check-remittance-module .check-photo-viewer{width:min(96vw,72rem);max-height:94vh;display:grid;grid-template-rows:auto minmax(0,1fr);overflow:hidden;border-radius:.85rem;background:#fff;box-shadow:0 2rem 5rem rgb(15 23 42 / .36)}
      #crm-check-remittance-module .check-photo-viewer-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--color-surface-200,#e2e8f0);padding:.8rem 1rem}
      #crm-check-remittance-module .check-photo-viewer-head strong{color:var(--color-secondary-900,#0f172a);font-size:.95rem;font-weight:600}
      #crm-check-remittance-module .check-photo-viewer img{width:100%;height:100%;max-height:calc(94vh - 3.5rem);object-fit:contain;background:#111827}
      #crm-check-remittance-module .check-ocr-row{grid-column:1 / -1;display:flex;align-items:center;gap:.65rem;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.55rem;background:var(--color-surface-50,#f8fafc);padding:.65rem}
      #crm-check-remittance-module .check-ocr-row span{color:var(--color-secondary-500,#64748b);font-size:.78rem;font-weight:800}
      #crm-check-remittance-module .check-readiness-grid{grid-column:1 / -1;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.55rem}
      #crm-check-remittance-module .check-readiness-item{display:flex;align-items:center;gap:.55rem;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.55rem;background:#fff;padding:.62rem .68rem;min-width:0}
      #crm-check-remittance-module .check-readiness-box{width:1.15rem;height:1.15rem;border-radius:.32rem;border:2px solid var(--color-surface-300,#cbd5e1);background:var(--color-surface-50,#f8fafc);box-shadow:inset 0 0 0 2px #fff;flex:0 0 auto}
      #crm-check-remittance-module .check-readiness-item strong{display:block;color:var(--color-secondary-900,#0f172a);font-size:.82rem;font-weight:600;line-height:1.1}
      #crm-check-remittance-module .check-readiness-item small{display:block;color:var(--color-secondary-500,#64748b);font-size:.68rem;font-weight:800;line-height:1.15;margin-top:.12rem}
      #crm-check-remittance-module .check-readiness-ok{border-color:rgb(34 197 94 / .35);background:rgb(34 197 94 / .08)}
      #crm-check-remittance-module .check-readiness-ok .check-readiness-box{border-color:#16a34a;background:#22c55e}
      #crm-check-remittance-module .check-readiness-warn{border-color:rgb(var(--theme-primary) / .28);background:rgb(var(--theme-primary) / .07)}
      #crm-check-remittance-module .check-readiness-warn .check-readiness-box{border-color:rgb(var(--theme-primary) / .75);background:#fff}
      #crm-check-remittance-module .check-modal-actions{grid-column:1 / -1;display:flex;justify-content:flex-end;gap:.5rem;padding:1rem;border-top:1px solid var(--color-surface-200,#e2e8f0)}
      .dark #crm-check-remittance-module .check-card,.dark #crm-check-remittance-module .check-summary-card,.dark #crm-check-remittance-module .check-modal{background:var(--color-surface-900,#0f172a);border-color:var(--color-surface-700,#334155)}
      .dark #crm-check-remittance-module input,.dark #crm-check-remittance-module select,.dark #crm-check-remittance-module textarea{background:var(--color-surface-800,#1e293b);border-color:var(--color-surface-700,#334155);color:#fff}
      .dark #crm-check-remittance-module .check-readiness-item{background:var(--color-surface-800,#1e293b);border-color:var(--color-surface-700,#334155)}
      .dark #crm-check-remittance-module .check-readiness-item strong{color:#fff}
      .dark #crm-check-remittance-module th{background:var(--color-surface-800,#1e293b)}
      @media (max-width:1100px){
        #crm-check-remittance-module .check-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
        #crm-check-remittance-module .check-total-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
      }
      @media (max-width:720px){
        #crm-check-remittance-module .check-head{display:grid}
        #crm-check-remittance-module .check-actions{justify-content:stretch}
        #crm-check-remittance-module .check-actions .check-button{flex:1}
        #crm-check-remittance-module .check-summary{grid-template-columns:1fr}
        #crm-check-remittance-module .check-filters{grid-template-columns:1fr}
        #crm-check-remittance-module .check-section-head{display:grid}
        #crm-check-remittance-module .check-total-strip{grid-template-columns:1fr}
        #crm-check-remittance-module .check-modal-backdrop{align-items:end;padding:.65rem}
        #crm-check-remittance-module .check-modal{max-height:86vh;border-radius:.75rem .75rem .5rem .5rem}
        #crm-check-remittance-module .check-form,#crm-check-remittance-module .check-check-form{grid-template-columns:1fr}
        #crm-check-remittance-module .check-photo-tools{grid-column:auto;grid-template-columns:1fr}
        #crm-check-remittance-module .check-field-full,#crm-check-remittance-module .check-ocr-row{grid-column:auto}
      }
    `;
    document.head.appendChild(style);
  }

  function icon(name) {
    const icons = {
      plus: `<path d="M12 5v14M5 12h14"/>`,
      arrowLeft: `<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>`,
      edit: `<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>`,
      trash: `<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/>`,
      eye: `<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>`,
      printer: `<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/>`,
      camera: `<path d="M14.5 4 16 7h3a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h3l1.5-3Z"/><circle cx="12" cy="13" r="3"/>`,
      scan: `<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/>`,
      creditCard: `<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>`,
      banknote: `<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M7 12h.01M17 12h.01"/>`,
      file: `<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/>`,
      building: `<path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9h.01M9 13h.01M9 17h.01"/>`,
    };

    return `<svg class="check-icon" viewBox="0 0 24 24" aria-hidden="true">${icons[name] || icons.file}</svg>`;
  }

  watchRouteChanges();
  document.addEventListener('DOMContentLoaded', () => {
    ensureHost();
    const observer = new MutationObserver(() => ensureHost());
    observer.observe(document.documentElement, { childList: true, subtree: true });
  });
  window.addEventListener('crm:active-site-changed', () => {
    if (isRoute() && root && mountedRoots.has(root)) load();
  });
  ensureHost();
})();

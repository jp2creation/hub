(function () {
  const api = "/api/pilotage-commercial";
  const routePath = "/pilotage-commercial";
  const storageSiteKey = "crm:active-site-id";
  const routeEvent = "crm:sales-route-changed";
  const mountedRoots = new WeakSet();
  let root = null;
  let state = initialState();

  function initialState() {
    return {
      loading: true,
      saving: false,
      error: "",
      notice: null,
      user: null,
      sites: [],
      representatives: [],
      selectedSiteId: numberOrNull(localStorage.getItem(storageSiteKey)),
      filters: { month: currentMonth(), representativeId: null, status: "" },
      summary: {},
      objectives: [],
      commissions: [],
      funnel: [],
      leaderboard: [],
      invoices: [],
      statusOptions: [],
      objectiveModal: null,
    };
  }

  function currentMonth() {
    const date = new Date();
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
  }

  function numberOrNull(value) {
    const number = Number(value || 0);
    return Number.isFinite(number) && number > 0 ? number : null;
  }

  function isRoute() {
    return window.location.pathname.replace(/\/$/, "") === routePath;
  }

  function findHost() {
    return document.getElementById("crm-sales-module");
  }

  function boot() {
    if (!isRoute()) return false;
    const host = findHost();
    if (!host) return false;
    if (mountedRoots.has(host)) return true;
    root = host;
    mountedRoots.add(host);
    ensureStyle();
    load();
    return true;
  }

  async function request(action, body, query) {
    const params = new URLSearchParams({ action });
    Object.entries(query || {}).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== "") params.set(key, String(value));
    });

    const response = await fetch(api + "?" + params.toString(), {
      method: body ? "POST" : "GET",
      credentials: "same-origin",
      headers: body ? { "Content-Type": "application/json", Accept: "application/json" } : { Accept: "application/json" },
      body: body ? JSON.stringify(body) : undefined,
    });
    const payload = await response.json();
    if (!response.ok || payload.ok === false) throw new Error(payload.error || "Action impossible");
    return payload;
  }

  async function load() {
    if (!root) return;
    state.loading = true;
    state.error = "";
    render();

    try {
      const payload = await request("bootstrap", null, {
        site_id: state.selectedSiteId || "",
        month: state.filters.month || currentMonth(),
        representativeId: state.filters.representativeId || "",
        status: state.filters.status || "",
      });

      state.user = payload.user || null;
      state.sites = Array.isArray(payload.sites) ? payload.sites : [];
      state.representatives = Array.isArray(payload.representatives) ? payload.representatives : [];
      state.selectedSiteId = Number(payload.selectedSiteId || state.selectedSiteId || 0) || null;
      state.filters = Object.assign({ month: currentMonth(), representativeId: null, status: "" }, payload.filters || {});
      state.summary = payload.summary || {};
      state.objectives = Array.isArray(payload.objectives) ? payload.objectives : [];
      state.commissions = Array.isArray(payload.commissions) ? payload.commissions : [];
      state.funnel = Array.isArray(payload.funnel) ? payload.funnel : [];
      state.leaderboard = Array.isArray(payload.leaderboard) ? payload.leaderboard : [];
      state.invoices = Array.isArray(payload.invoices) ? payload.invoices : [];
      state.statusOptions = Array.isArray(payload.statusOptions) ? payload.statusOptions : [];
      if (state.selectedSiteId) localStorage.setItem(storageSiteKey, String(state.selectedSiteId));
    } catch (error) {
      state.error = error instanceof Error ? error.message : "Impossible de charger le pilotage commercial";
    } finally {
      state.loading = false;
      render();
    }
  }

  async function syncDemo() {
    state.saving = true;
    state.notice = null;
    render();

    try {
      const payload = await request("sync_demo", {
        siteId: state.selectedSiteId,
        month: state.filters.month || currentMonth(),
      });
      state.notice = { type: "success", message: payload.message || "Donnees commerciales synchronisees." };
      await load();
    } catch (error) {
      state.saving = false;
      state.notice = { type: "error", message: error instanceof Error ? error.message : "Synchronisation impossible" };
      render();
    }
  }

  async function saveObjective(event) {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    data.siteId = state.selectedSiteId;
    data.month = state.filters.month || currentMonth();
    data.representativeId = data.representativeId || null;
    state.saving = true;
    render();

    try {
      await request("save_objective", data);
      state.notice = { type: "success", message: "Objectif commercial enregistre." };
      state.objectiveModal = null;
      await load();
    } catch (error) {
      state.saving = false;
      state.notice = { type: "error", message: error instanceof Error ? error.message : "Objectif impossible a enregistrer" };
      render();
    }
  }

  async function markCommissionPaid(id) {
    state.saving = true;
    render();

    try {
      await request("mark_commission_paid", { id });
      state.notice = { type: "success", message: "Commission marquee comme payee." };
      await load();
    } catch (error) {
      state.saving = false;
      state.notice = { type: "error", message: error instanceof Error ? error.message : "Validation commission impossible" };
      render();
    }
  }

  function render() {
    if (!root) return;
    root.innerHTML = `
      <section class="sales-page">
        ${headerHtml()}
        ${state.notice ? noticeHtml(state.notice) : ""}
        ${state.loading ? loadingHtml() : state.error ? noticeHtml({ type: "error", message: state.error }) : contentHtml()}
        ${state.objectiveModal ? objectiveModalHtml(state.objectiveModal) : ""}
      </section>
    `;
    bindEvents();
  }

  function headerHtml() {
    return `
      <header class="sales-head">
        <div>
          <span class="sales-eyebrow">Commercial</span>
          <h1>Pilotage commercial</h1>
          <p>${esc(currentSiteName())} · Objectifs, factures, commissions et activite terrain</p>
        </div>
        <div class="sales-actions">
          ${state.user?.canManage ? `<button class="sales-button sales-button-ghost" type="button" data-objective>${icon("target")}Objectif</button>` : ""}
          ${state.user?.canSync ? `<button class="sales-button sales-button-primary" type="button" data-sync-demo ${state.saving ? "disabled" : ""}>${icon("refresh")}Donnees demo</button>` : ""}
        </div>
      </header>
    `;
  }

  function loadingHtml() {
    return `
      <div class="sales-card sales-loading">
        <span class="sales-spinner"></span>
        <strong>Connexion aux donnees commerciales...</strong>
      </div>
    `;
  }

  function contentHtml() {
    return `
      ${filtersHtml()}
      <div class="sales-summary">
        ${summaryCard("CA paye", money(state.summary.revenue || 0), progressText(state.summary.targetProgress), "primary")}
        ${summaryCard("Marge", money(state.summary.margin || 0), `${number(state.summary.paidCount || 0)} facture(s) payee(s)`, "green")}
        ${summaryCard("En attente", money(state.summary.pendingRevenue || 0), `${number(state.summary.pendingCount || 0)} facture(s)`, "amber")}
        ${summaryCard("Commissions", money(state.summary.commissionAmount || 0), `${money(state.summary.commissionPaidAmount || 0)} paye`, "pink")}
      </div>
      <div class="sales-grid">
        <section class="sales-card sales-panel">
          <div class="sales-card-head"><h2>Entonnoir commercial</h2><span>${monthLabel(state.filters.month)}</span></div>
          ${funnelHtml()}
        </section>
        <section class="sales-card sales-panel">
          <div class="sales-card-head"><h2>Classement</h2><span>${esc(currentSiteName())}</span></div>
          ${leaderboardHtml()}
        </section>
      </div>
      <div class="sales-grid">
        <section class="sales-card sales-panel">
          <div class="sales-card-head"><h2>Objectifs</h2><span>${number(state.objectives.length)} objectif(s)</span></div>
          ${objectivesHtml()}
        </section>
        <section class="sales-card sales-panel">
          <div class="sales-card-head"><h2>Commissions</h2><span>${number(state.commissions.length)} ligne(s)</span></div>
          ${commissionsHtml()}
        </section>
      </div>
      <section class="sales-card sales-panel">
        <div class="sales-card-head"><h2>Factures</h2><span>${number(state.invoices.length)} affichée(s)</span></div>
        ${invoicesHtml()}
      </section>
    `;
  }

  function filtersHtml() {
    return `
      <section class="sales-card sales-filters">
        <label><span>Mois</span><input type="month" data-filter-month value="${esc(state.filters.month || currentMonth())}"></label>
        <label><span>Commercial</span><select data-filter-representative>
          <option value="">Tous</option>
          ${state.representatives.map((rep) => `<option value="${rep.id}" ${Number(state.filters.representativeId || 0) === Number(rep.id) ? "selected" : ""}>${esc(rep.name)}</option>`).join("")}
        </select></label>
        <label><span>Statut</span><select data-filter-status>
          <option value="">Tous les statuts</option>
          ${state.statusOptions.map((status) => `<option value="${esc(status.value)}" ${state.filters.status === status.value ? "selected" : ""}>${esc(status.label)}</option>`).join("")}
        </select></label>
      </section>
    `;
  }

  function funnelHtml() {
    if (!state.funnel.length) return emptyHtml("Aucune activite commerciale pour ce mois.");
    const max = Math.max(1, ...state.funnel.map((row) => Number(row.count || 0)));
    return `
      <div class="sales-funnel">
        ${state.funnel.map((row) => `
          <div class="sales-funnel-row">
            <div><strong>${number(row.count || 0)}</strong><span>${esc(row.label)}</span></div>
            <div class="sales-bar"><i style="width:${Math.max(8, (Number(row.count || 0) / max) * 100)}%"></i></div>
          </div>
        `).join("")}
      </div>
    `;
  }

  function leaderboardHtml() {
    if (!state.leaderboard.length) return emptyHtml("Aucun commercial sur ce site.");
    return `
      <div class="sales-ranking">
        ${state.leaderboard.map((row, index) => `
          <div class="sales-rank-row">
            <span class="sales-rank">${index + 1}</span>
            <span class="sales-avatar">${initials(row.name)}</span>
            <div><strong>${esc(row.name)}</strong><small>${number(row.visits || 0)} visite(s)</small></div>
            <b>${money(row.revenue || 0)}</b>
          </div>
        `).join("")}
      </div>
    `;
  }

  function objectivesHtml() {
    if (!state.objectives.length) return emptyHtml("Aucun objectif defini pour ce mois.");
    return `
      <div class="sales-objectives">
        ${state.objectives.map((objective) => `
          <article class="sales-objective">
            <div><strong>${esc(objective.representativeName || "Site")}</strong><span>${money(objective.actualRevenue || 0)} / ${money(objective.targetRevenue || 0)}</span></div>
            ${progressHtml(objective.revenueProgress)}
            <small>Marge ${money(objective.actualMargin || 0)} · Visites ${number(objective.actualVisits || 0)} / ${number(objective.targetVisits || 0)}</small>
          </article>
        `).join("")}
      </div>
    `;
  }

  function commissionsHtml() {
    if (!state.commissions.length) return emptyHtml("Aucune commission calculee.");
    return `
      <div class="sales-commission-list">
        ${state.commissions.map((commission) => `
          <article class="sales-commission">
            <div>
              <strong>${money(commission.amount || 0)}</strong>
              <span>${esc(commission.representativeName || "Non affecte")} · ${esc(commission.invoiceNumber || "-")}</span>
              <small>${esc(commission.customerName || "")}</small>
            </div>
            <div class="sales-commission-side">
              <em class="sales-status is-${esc(commission.status)}">${esc(commission.statusLabel || commission.status)}</em>
              ${state.user?.canManageCommissions && commission.status !== "paid" ? `<button type="button" data-paid-commission="${commission.id}">Payee</button>` : ""}
            </div>
          </article>
        `).join("")}
      </div>
    `;
  }

  function invoicesHtml() {
    if (!state.invoices.length) {
      return `
        <div class="sales-empty">
          <strong>Aucune facture synchronisee.</strong>
          <p>Utilisez les donnees demo pour valider le module en attendant l API externe.</p>
        </div>
      `;
    }

    return `
      <div class="sales-table-wrap">
        <table class="sales-table">
          <thead><tr><th>Facture</th><th>Client</th><th>Commercial</th><th>Date</th><th>Statut</th><th>Total</th><th>Marge</th></tr></thead>
          <tbody>
            ${state.invoices.map((invoice) => `
              <tr>
                <td><strong>${esc(invoice.number)}</strong></td>
                <td>${esc(invoice.customerName)}</td>
                <td>${esc(invoice.representativeName || "-")}</td>
                <td>${date(invoice.issueDate)}</td>
                <td><span class="sales-status is-${esc(invoice.status)}">${esc(invoice.statusLabel || invoice.status)}</span></td>
                <td>${money(invoice.total || 0)}</td>
                <td>${money(invoice.margin || 0)}</td>
              </tr>
            `).join("")}
          </tbody>
        </table>
      </div>
    `;
  }

  function objectiveModalHtml(objective) {
    return `
      <div class="sales-modal-backdrop" data-close-modal>
        <div class="sales-modal" role="dialog" aria-modal="true" aria-label="Objectif commercial" data-modal-panel>
          <div class="sales-modal-head">
            <h2>Objectif commercial</h2>
            <button type="button" data-close-modal aria-label="Fermer">${icon("x")}</button>
          </div>
          <form data-objective-form>
            <label><span>Commercial</span><select name="representativeId">
              <option value="">Objectif global du site</option>
              ${state.representatives.map((rep) => `<option value="${rep.id}" ${Number(objective.representativeUserId || 0) === Number(rep.id) ? "selected" : ""}>${esc(rep.name)}</option>`).join("")}
            </select></label>
            <div class="sales-form-grid">
              <label><span>CA cible</span><input name="targetRevenue" inputmode="decimal" value="${esc(objective.targetRevenue || state.summary.targetRevenue || 0)}"></label>
              <label><span>Marge cible</span><input name="targetMargin" inputmode="decimal" value="${esc(objective.targetMargin || 0)}"></label>
              <label><span>Visites cible</span><input name="targetVisits" inputmode="numeric" value="${esc(objective.targetVisits || 0)}"></label>
            </div>
            <label><span>Notes</span><textarea name="notes" rows="3">${esc(objective.notes || "")}</textarea></label>
            <div class="sales-modal-actions">
              <button class="sales-button sales-button-ghost" type="button" data-close-modal>Annuler</button>
              <button class="sales-button sales-button-primary" type="submit" ${state.saving ? "disabled" : ""}>Enregistrer</button>
            </div>
          </form>
        </div>
      </div>
    `;
  }

  function summaryCard(label, value, detail, tone) {
    return `
      <article class="sales-stat is-${tone}">
        <span>${label}</span>
        <strong>${value}</strong>
        <small>${detail || ""}</small>
      </article>
    `;
  }

  function progressHtml(value) {
    const progress = Math.max(0, Math.min(100, Number(value || 0)));
    return `<div class="sales-progress"><i style="width:${progress}%"></i></div>`;
  }

  function progressText(value) {
    return value === null || value === undefined ? "Aucun objectif" : `${number(value)}% de l'objectif`;
  }

  function emptyHtml(message) {
    return `<div class="sales-empty"><p>${esc(message)}</p></div>`;
  }

  function noticeHtml(notice) {
    return `<div class="sales-notice is-${esc(notice.type || "info")}">${esc(notice.message || "")}</div>`;
  }

  function bindEvents() {
    root.querySelectorAll("[data-filter-month]").forEach((input) => {
      input.addEventListener("change", () => {
        state.filters.month = input.value || currentMonth();
        load();
      });
    });
    root.querySelectorAll("[data-filter-representative]").forEach((select) => {
      select.addEventListener("change", () => {
        state.filters.representativeId = numberOrNull(select.value);
        load();
      });
    });
    root.querySelectorAll("[data-filter-status]").forEach((select) => {
      select.addEventListener("change", () => {
        state.filters.status = select.value || "";
        load();
      });
    });
    root.querySelectorAll("[data-sync-demo]").forEach((button) => button.addEventListener("click", syncDemo));
    root.querySelectorAll("[data-objective]").forEach((button) => {
      button.addEventListener("click", () => {
        state.objectiveModal = state.objectives[0] || {};
        render();
      });
    });
    root.querySelectorAll("[data-objective-form]").forEach((form) => form.addEventListener("submit", saveObjective));
    root.querySelectorAll("[data-paid-commission]").forEach((button) => {
      button.addEventListener("click", () => markCommissionPaid(Number(button.dataset.paidCommission || 0)));
    });
    root.querySelectorAll("[data-close-modal]").forEach((button) => {
      button.addEventListener("click", (event) => {
        if (event.target.closest("[data-modal-panel]") && !event.target.closest(".sales-modal-head button, .sales-modal-actions button")) return;
        state.objectiveModal = null;
        render();
      });
    });
  }

  function currentSiteName() {
    const site = state.sites.find((item) => Number(item.id) === Number(state.selectedSiteId));
    return site ? site.name : "Site actif";
  }

  function monthLabel(value) {
    if (!value) return "";
    const [year, month] = value.split("-");
    return `${month}/${year}`;
  }

  function money(value) {
    return new Intl.NumberFormat("fr-FR", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(Number(value || 0));
  }

  function number(value) {
    return new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 1 }).format(Number(value || 0));
  }

  function date(value) {
    if (!value) return "-";
    try {
      return new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short" }).format(new Date(value));
    } catch (error) {
      return value;
    }
  }

  function initials(value) {
    const text = String(value || "").trim();
    if (!text) return "C";
    return text.split(/\s+/).slice(0, 2).map((part) => part[0]).join("").toUpperCase();
  }

  function icon(name) {
    const paths = {
      refresh: '<path d="M21 12a9 9 0 0 1-9 9 8.7 8.7 0 0 1-6.1-2.5"/><path d="M3 12a9 9 0 0 1 15.1-6.6"/><path d="M3 16v-4h4"/><path d="M21 8V4h-4"/>',
      target: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
      x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    };
    return `<svg viewBox="0 0 24 24" aria-hidden="true">${paths[name] || ""}</svg>`;
  }

  function esc(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[char]);
  }

  function ensureStyle() {
    if (document.getElementById("crm-sales-style")) return;
    const style = document.createElement("style");
    style.id = "crm-sales-style";
    style.textContent = `
      #crm-sales-module{--sales-primary:rgb(var(--theme-primary));--sales-ink:#1d334a;--sales-muted:#718197;--sales-line:#e6ebf2;--sales-soft:#f8fafc;display:block}
      #crm-sales-module .sales-page{display:grid;gap:1rem;color:var(--sales-ink);font-family:var(--font-sans,Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif)}
      #crm-sales-module .sales-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem}
      #crm-sales-module .sales-eyebrow{display:block;margin-bottom:.35rem;color:var(--sales-primary);font-size:.72rem;font-weight:900;text-transform:uppercase}
      #crm-sales-module h1{margin:0;font-size:1.8rem;line-height:1.1;font-weight:900;letter-spacing:0}
      #crm-sales-module h2{margin:0;font-size:1rem;line-height:1.2;font-weight:900;letter-spacing:0}
      #crm-sales-module p{margin:.35rem 0 0;color:var(--sales-muted);font-size:.92rem;font-weight:650}
      #crm-sales-module .sales-actions{display:flex;flex-wrap:wrap;gap:.55rem;justify-content:flex-end}
      #crm-sales-module .sales-button{min-height:2.35rem;border:1px solid var(--sales-line);border-radius:.5rem;background:#fff;color:var(--sales-ink);display:inline-flex;align-items:center;justify-content:center;gap:.45rem;padding:.55rem .85rem;font-size:.82rem;font-weight:850;box-shadow:0 10px 24px rgba(29,51,74,.05);cursor:pointer}
      #crm-sales-module .sales-button svg{width:1.05rem;height:1.05rem;fill:none;stroke:currentColor;stroke-width:2.3;stroke-linecap:round;stroke-linejoin:round}
      #crm-sales-module .sales-button-primary{border-color:var(--sales-primary);background:var(--sales-primary);color:#fff}
      #crm-sales-module .sales-card{border:1px solid var(--sales-line);border-radius:.75rem;background:#fff;box-shadow:0 18px 44px rgba(29,51,74,.07)}
      #crm-sales-module .sales-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;padding:.8rem}
      #crm-sales-module label{display:grid;gap:.35rem;min-width:0}
      #crm-sales-module label span{font-size:.72rem;font-weight:900;text-transform:uppercase;color:#7b8a9c}
      #crm-sales-module input,#crm-sales-module select,#crm-sales-module textarea{width:100%;min-width:0;border:1px solid var(--sales-line);border-radius:.55rem;background:#fff;color:var(--sales-ink);font:inherit;font-weight:760;padding:.72rem .8rem;outline:none}
      #crm-sales-module input:focus,#crm-sales-module select:focus,#crm-sales-module textarea:focus{border-color:rgb(var(--theme-primary) / .65);box-shadow:0 0 0 3px rgb(var(--theme-primary) / .12)}
      #crm-sales-module .sales-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem}
      #crm-sales-module .sales-stat{border:1px solid var(--sales-line);border-radius:.75rem;background:#fff;padding:.95rem;box-shadow:0 18px 44px rgba(29,51,74,.07);min-height:6.7rem}
      #crm-sales-module .sales-stat span{display:block;color:#7b8a9c;font-size:.72rem;font-weight:900;text-transform:uppercase}
      #crm-sales-module .sales-stat strong{display:block;margin-top:.25rem;font-size:1.45rem;line-height:1.05;font-weight:600;letter-spacing:0}
      #crm-sales-module .sales-stat small{display:block;margin-top:.45rem;color:var(--sales-muted);font-size:.72rem;font-weight:750}
      #crm-sales-module .sales-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:.85rem}
      #crm-sales-module .sales-panel{padding:1rem;min-width:0}
      #crm-sales-module .sales-card-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.85rem}
      #crm-sales-module .sales-card-head span{font-size:.78rem;font-weight:850;color:#8391a3}
      #crm-sales-module .sales-funnel,#crm-sales-module .sales-ranking,#crm-sales-module .sales-objectives,#crm-sales-module .sales-commission-list{display:grid;gap:.65rem}
      #crm-sales-module .sales-funnel-row{display:grid;grid-template-columns:11rem minmax(0,1fr);align-items:center;gap:.75rem}
      #crm-sales-module .sales-funnel-row strong{display:block;font-size:1.35rem;line-height:1;font-weight:600}
      #crm-sales-module .sales-funnel-row span{display:block;margin-top:.25rem;color:var(--sales-muted);font-weight:800}
      #crm-sales-module .sales-bar,#crm-sales-module .sales-progress{height:.6rem;border-radius:999px;background:#eef2f7;overflow:hidden}
      #crm-sales-module .sales-bar i,#crm-sales-module .sales-progress i{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--sales-primary),#f2b705)}
      #crm-sales-module .sales-rank-row{display:grid;grid-template-columns:1.8rem 2.4rem minmax(0,1fr) auto;align-items:center;gap:.65rem;padding:.55rem;border-radius:.65rem;background:var(--sales-soft)}
      #crm-sales-module .sales-rank{font-weight:600;color:var(--sales-primary);text-align:center}
      #crm-sales-module .sales-avatar{width:2.35rem;height:2.35rem;border-radius:999px;display:grid;place-items:center;background:#f2dce5;color:var(--sales-primary);font-weight:600}
      #crm-sales-module .sales-rank-row strong,.sales-commission strong{display:block;font-weight:600}
      #crm-sales-module .sales-rank-row small,.sales-commission small{display:block;color:var(--sales-muted);font-weight:750}
      #crm-sales-module .sales-objective{display:grid;gap:.55rem;padding:.75rem;border:1px solid var(--sales-line);border-radius:.65rem;background:#fff}
      #crm-sales-module .sales-objective div{display:flex;align-items:center;justify-content:space-between;gap:.75rem}
      #crm-sales-module .sales-objective span,#crm-sales-module .sales-objective small{color:var(--sales-muted);font-weight:800}
      #crm-sales-module .sales-commission{display:flex;align-items:center;justify-content:space-between;gap:.8rem;padding:.75rem;border:1px solid var(--sales-line);border-radius:.65rem;background:#fff}
      #crm-sales-module .sales-commission-side{display:grid;justify-items:end;gap:.45rem}
      #crm-sales-module .sales-commission button{border:0;border-radius:.5rem;background:var(--sales-primary);color:#fff;font-weight:900;padding:.5rem .75rem;cursor:pointer}
      #crm-sales-module .sales-status{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:.25rem .55rem;background:#eef2f7;color:#516174;font-style:normal;font-size:.72rem;font-weight:600}
      #crm-sales-module .sales-status.is-paid,#crm-sales-module .sales-status.is-acquired{background:#dcfce7;color:#15803d}
      #crm-sales-module .sales-status.is-pending{background:#fef3c7;color:#a16207}
      #crm-sales-module .sales-status.is-overdue{background:#fee2e2;color:#b91c1c}
      #crm-sales-module .sales-table-wrap{overflow:auto;border:1px solid var(--sales-line);border-radius:.65rem}
      #crm-sales-module .sales-table{width:100%;border-collapse:collapse;min-width:760px}
      #crm-sales-module th,#crm-sales-module td{padding:.78rem .85rem;text-align:left;border-bottom:1px solid var(--sales-line);font-size:.88rem}
      #crm-sales-module th{background:var(--sales-soft);color:#68778a;font-size:.72rem;text-transform:uppercase;font-weight:600}
      #crm-sales-module td{font-weight:760;color:var(--sales-ink)}
      #crm-sales-module tr:last-child td{border-bottom:0}
      #crm-sales-module .sales-empty{padding:1rem;border:1px dashed #d8e0ea;border-radius:.65rem;background:var(--sales-soft);color:var(--sales-muted);font-weight:800}
      #crm-sales-module .sales-notice{border:1px solid #dbeafe;border-radius:.7rem;background:#eff6ff;color:#1d4ed8;padding:.85rem 1rem;font-weight:850}
      #crm-sales-module .sales-notice.is-error{border-color:#fecaca;background:#fef2f2;color:#b91c1c}
      #crm-sales-module .sales-notice.is-success{border-color:#bbf7d0;background:#f0fdf4;color:#15803d}
      #crm-sales-module .sales-loading{display:flex;align-items:center;gap:.85rem;padding:1.1rem;color:var(--sales-muted)}
      #crm-sales-module .sales-spinner{width:1.45rem;height:1.45rem;border-radius:999px;border:3px solid #e2e8f0;border-top-color:var(--sales-primary);animation:salesSpin .85s linear infinite}
      #crm-sales-module .sales-modal-backdrop{position:fixed;inset:0;z-index:10020;display:grid;place-items:center;padding:1rem;background:rgba(15,23,42,.45);backdrop-filter:blur(7px)}
      #crm-sales-module .sales-modal{width:min(35rem,100%);border-radius:.85rem;background:#fff;box-shadow:0 30px 80px rgba(15,23,42,.25);overflow:hidden}
      #crm-sales-module .sales-modal-head{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.1rem;border-bottom:1px solid var(--sales-line)}
      #crm-sales-module .sales-modal-head button{width:2.15rem;height:2.15rem;border:1px solid var(--sales-line);border-radius:999px;background:#fff;color:var(--sales-ink);display:grid;place-items:center;cursor:pointer}
      #crm-sales-module .sales-modal-head svg{width:1rem;height:1rem;fill:none;stroke:currentColor;stroke-width:2.4}
      #crm-sales-module .sales-modal form{display:grid;gap:.8rem;padding:1rem}
      #crm-sales-module .sales-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.7rem}
      #crm-sales-module .sales-modal-actions{display:flex;justify-content:flex-end;gap:.65rem}
      @keyframes salesSpin{to{transform:rotate(360deg)}}
      @media (max-width:920px){#crm-sales-module .sales-summary{grid-template-columns:repeat(2,minmax(0,1fr))}#crm-sales-module .sales-grid{grid-template-columns:1fr}}
      @media (max-width:640px){#crm-sales-module .sales-head{display:grid}#crm-sales-module h1{font-size:1.55rem}#crm-sales-module .sales-actions{justify-content:stretch}#crm-sales-module .sales-button{flex:1;padding:.55rem .75rem}#crm-sales-module .sales-filters{grid-template-columns:1fr}#crm-sales-module .sales-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:.55rem}#crm-sales-module .sales-stat{padding:.8rem;min-height:6rem}#crm-sales-module .sales-stat strong{font-size:1.3rem}#crm-sales-module .sales-panel{padding:.8rem}#crm-sales-module .sales-funnel-row{grid-template-columns:1fr;gap:.45rem}#crm-sales-module .sales-rank-row{grid-template-columns:1.5rem 2rem minmax(0,1fr);align-items:start}#crm-sales-module .sales-rank-row b{grid-column:3;justify-self:start}#crm-sales-module .sales-commission{display:grid}#crm-sales-module .sales-commission-side{justify-items:start}#crm-sales-module .sales-form-grid{grid-template-columns:1fr}#crm-sales-module .sales-modal-actions .sales-button{min-width:0}}
    `;
    document.head.appendChild(style);
  }

  const observer = new MutationObserver(() => boot());
  observer.observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener("crm:route-changed", () => setTimeout(boot, 30));
  window.addEventListener(routeEvent, () => setTimeout(boot, 30));
  window.addEventListener("popstate", () => window.dispatchEvent(new Event(routeEvent)));
  window.addEventListener("crm:active-site-changed", (event) => {
    state.selectedSiteId = numberOrNull(event.detail && event.detail.siteId) || numberOrNull(localStorage.getItem(storageSiteKey));
    if (isRoute() && root) load();
  });
  boot();
})();

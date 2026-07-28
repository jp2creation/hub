(function () {
  const api = "/api/demandes-acompte";
  const routePath = "/demandes-acompte";
  const storageSiteKey = "crm:active-site-id";
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
      selectedSiteId: numberOrNull(localStorage.getItem(storageSiteKey)),
      filters: { limit: 20, status: "", query: "" },
      summary: {},
      requests: [],
      modal: null,
      validationModal: null,
    };
  }

  function numberOrNull(value) {
    const number = Number(value || 0);
    return Number.isFinite(number) && number > 0 ? number : null;
  }

  function isRoute() {
    return window.location.pathname.replace(/\/$/, "") === routePath;
  }

  function findHost() {
    return document.getElementById("crm-deposit-requests-module");
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
        limit: state.filters.limit,
        status: state.filters.status,
        query: state.filters.query,
      });

      state.user = payload.user || null;
      state.sites = Array.isArray(payload.sites) ? payload.sites : [];
      state.selectedSiteId = Number(payload.selectedSiteId || state.selectedSiteId || 0) || null;
      state.filters = Object.assign({ limit: 20, status: "", query: "" }, payload.filters || {});
      state.summary = payload.summary || {};
      state.requests = Array.isArray(payload.requests) ? payload.requests : [];
      if (state.selectedSiteId) localStorage.setItem(storageSiteKey, String(state.selectedSiteId));
    } catch (error) {
      state.error = error instanceof Error ? error.message : "Impossible de charger les demandes d'acompte";
    } finally {
      state.loading = false;
      render();
    }
  }

  async function saveRequest(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = Object.fromEntries(new FormData(form).entries());
    data.id = state.modal && state.modal.id ? state.modal.id : undefined;
    data.siteId = state.selectedSiteId;
    state.saving = true;
    render();

    try {
      await request("save_request", data);
      state.notice = { type: "success", message: "Demande d'acompte enregistrée." };
      state.modal = null;
      await load();
    } catch (error) {
      state.saving = false;
      state.notice = { type: "error", message: error instanceof Error ? error.message : "Enregistrement impossible" };
      render();
    }
  }

  async function validateDeposit(event) {
    event.preventDefault();
    if (!state.validationModal) return;
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    data.id = state.validationModal.id;
    state.saving = true;
    render();

    try {
      await request("validate_request", data);
      state.notice = { type: "success", message: data.status === "validated" ? "Demande validée." : "Demande remise en attente." };
      state.validationModal = null;
      await load();
    } catch (error) {
      state.saving = false;
      state.notice = { type: "error", message: error instanceof Error ? error.message : "Validation impossible" };
      render();
    }
  }

  async function deleteDeposit(id) {
    if (!window.confirm("Supprimer cette demande d'acompte ?")) return;
    state.saving = true;
    render();

    try {
      await request("delete_request", { id });
      state.notice = { type: "success", message: "Demande supprimée." };
      await load();
    } catch (error) {
      state.saving = false;
      state.notice = { type: "error", message: error instanceof Error ? error.message : "Suppression impossible" };
      render();
    }
  }

  function render() {
    if (!root) return;
    root.innerHTML = `
      <section class="deposit-page">
        ${headerHtml()}
        ${state.notice ? noticeHtml(state.notice) : ""}
        ${state.loading ? `<div class="deposit-card deposit-loading">Chargement des demandes d'acompte...</div>` : state.error ? noticeHtml({ type: "error", message: state.error }) : contentHtml()}
        ${state.modal ? requestModalHtml(state.modal) : ""}
        ${state.validationModal ? validationModalHtml(state.validationModal) : ""}
      </section>
    `;
    bindEvents();
  }

  function headerHtml() {
    const siteName = currentSiteName();
    return `
      <header class="deposit-head">
        <div>
          <span class="deposit-eyebrow">Comptabilité</span>
          <h1>Demande d'acompte</h1>
          <p>${esc(siteName)} · Suivi des demandes et validations comptables</p>
        </div>
        <div class="deposit-actions">
          ${state.user && state.user.canCreate ? `<button class="deposit-button deposit-button-primary" type="button" data-new-request>${icon("plus")}Nouvelle demande</button>` : ""}
        </div>
      </header>
    `;
  }

  function contentHtml() {
    return `
      <div class="deposit-summary">
        ${summaryCard("En attente", state.summary.pendingCount || 0, money(state.summary.pendingAmount || 0), "pending")}
        ${summaryCard("Validées", state.summary.validatedCount || 0, money(state.summary.validatedAmount || 0), "validated")}
        ${summaryCard("Total", state.summary.totalCount || 0, "Demandes du site", "total")}
      </div>
      <section class="deposit-card">
        <div class="deposit-toolbar">
          <label><span>Recherche</span><input type="search" data-filter-query value="${esc(state.filters.query || "")}" placeholder="Facture, commande, client, demandeur"></label>
          <label><span>Statut</span><select data-filter-status>
            <option value="">Tous les statuts</option>
            <option value="pending" ${state.filters.status === "pending" ? "selected" : ""}>En attente</option>
            <option value="validated" ${state.filters.status === "validated" ? "selected" : ""}>Validé</option>
          </select></label>
          <label><span>Afficher</span><select data-filter-limit>
            ${[10, 20, 50, 100].map((limit) => `<option value="${limit}" ${Number(state.filters.limit) === limit ? "selected" : ""}>${limit} lignes</option>`).join("")}
          </select></label>
        </div>
        <div class="deposit-table-wrap">
          <table class="deposit-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Demandeur</th>
                <th>Facture / commande</th>
                <th>Client</th>
                <th>Montant</th>
                <th>Statut</th>
                <th>Validation</th>
                <th></th>
              </tr>
            </thead>
            <tbody>${state.requests.length ? state.requests.map(rowHtml).join("") : `<tr><td colspan="8"><div class="deposit-empty">Aucune demande d'acompte pour le moment.</div></td></tr>`}</tbody>
          </table>
        </div>
      </section>
    `;
  }

  function summaryCard(label, value, detail, tone) {
    return `
      <article class="deposit-summary-card deposit-summary-${tone}">
        <span class="deposit-summary-icon">${icon(tone === "validated" ? "check" : tone === "pending" ? "clock" : "file")}</span>
        <div>
          <small>${esc(label)}</small>
          <strong>${esc(value)}</strong>
          <em>${esc(detail)}</em>
        </div>
      </article>
    `;
  }

  function rowHtml(item) {
    const validated = item.status === "validated";
    return `
      <tr>
        <td><strong>${dateFr(item.requestDate)}</strong></td>
        <td>${esc(item.requesterName || "")}</td>
        <td><span class="deposit-doc">${esc(item.documentNumber || "")}</span></td>
        <td>${esc(item.clientName || "-")}</td>
        <td class="deposit-amount">${money(item.amount || 0)}</td>
        <td><span class="deposit-status ${validated ? "is-valid" : "is-pending"}">${validated ? icon("check") : icon("clock")}${esc(item.statusLabel || "En attente")}</span></td>
        <td>${validated ? `<strong>${dateFr(item.validatedDate || item.validatedAt)}</strong><small>${esc(item.validatedByName || "")}</small>` : `<span class="deposit-muted">En attente comptabilité</span>`}</td>
        <td class="deposit-row-actions">
          ${item.canEdit ? `<button class="deposit-mini" type="button" data-edit-request="${item.id}">${icon("edit")}</button>` : ""}
          ${item.canValidate ? `<button class="deposit-mini deposit-mini-primary" type="button" data-validate-request="${item.id}">${validated ? "Modifier" : "Valider"}</button>` : ""}
          ${item.canDelete ? `<button class="deposit-mini deposit-mini-danger" type="button" data-delete-request="${item.id}">${icon("trash")}</button>` : ""}
        </td>
      </tr>
    `;
  }

  function requestModalHtml(item) {
    const editing = Boolean(item.id);
    return `
      <div class="deposit-modal-backdrop" data-close-modal>
        <section class="deposit-modal" data-modal-panel>
          <header>
            <div>
              <h2>${editing ? "Modifier la demande" : "Nouvelle demande d'acompte"}</h2>
              <p>Les champs de validation sont réservés à la comptabilité.</p>
            </div>
            <button class="deposit-mini" type="button" data-close>${icon("x")}</button>
          </header>
          <form class="deposit-form" data-request-form>
            <label><span>Date</span><input type="date" name="requestDate" required value="${esc(item.requestDate || today())}"></label>
            <label><span>Demandeur</span><input name="requesterName" required value="${esc(item.requesterName || (state.user && state.user.name) || "")}"></label>
            <label><span>Facture ou commande</span><input name="documentNumber" required value="${esc(item.documentNumber || "")}" placeholder="N° facture ou commande"></label>
            <label><span>Nom du client</span><input name="clientName" value="${esc(item.clientName || "")}" placeholder="Client"></label>
            <label><span>Montant</span><input name="amount" inputmode="decimal" required value="${esc(item.amount || "")}" placeholder="0,00"></label>
            <label class="deposit-field-full"><span>Notes</span><textarea name="notes">${esc(item.notes || "")}</textarea></label>
            <footer>
              <button class="deposit-button" type="button" data-close>Annuler</button>
              <button class="deposit-button deposit-button-primary" type="submit" ${state.saving ? "disabled" : ""}>${state.saving ? "Enregistrement..." : "Enregistrer"}</button>
            </footer>
          </form>
        </section>
      </div>
    `;
  }

  function validationModalHtml(item) {
    return `
      <div class="deposit-modal-backdrop" data-close-modal>
        <section class="deposit-modal deposit-small-modal" data-modal-panel>
          <header>
            <div>
              <h2>Validation comptabilité</h2>
              <p>${esc(item.documentNumber)}${item.clientName ? " · " + esc(item.clientName) : ""} · ${money(item.amount || 0)}</p>
            </div>
            <button class="deposit-mini" type="button" data-close>${icon("x")}</button>
          </header>
          <form class="deposit-form" data-validation-form>
            <label><span>Statut</span><select name="status">
              <option value="pending" ${item.status === "pending" ? "selected" : ""}>En attente</option>
              <option value="validated" ${item.status === "validated" ? "selected" : ""}>Validé</option>
            </select></label>
            <label><span>Date de validation</span><input type="datetime-local" name="validatedAt" value="${datetimeLocal(item.validatedAt)}"></label>
            <footer>
              <button class="deposit-button" type="button" data-close>Annuler</button>
              <button class="deposit-button deposit-button-primary" type="submit" ${state.saving ? "disabled" : ""}>Enregistrer la validation</button>
            </footer>
          </form>
        </section>
      </div>
    `;
  }

  function bindEvents() {
    root.querySelector("[data-new-request]")?.addEventListener("click", () => {
      state.modal = { requestDate: today(), requesterName: state.user && state.user.name ? state.user.name : "" };
      state.notice = null;
      render();
    });
    root.querySelector("[data-request-form]")?.addEventListener("submit", saveRequest);
    root.querySelector("[data-validation-form]")?.addEventListener("submit", validateDeposit);
    root.querySelector("[data-filter-status]")?.addEventListener("change", (event) => {
      state.filters.status = event.target.value;
      load();
    });
    root.querySelector("[data-filter-limit]")?.addEventListener("change", (event) => {
      state.filters.limit = Number(event.target.value) || 20;
      load();
    });
    root.querySelector("[data-filter-query]")?.addEventListener("change", (event) => {
      state.filters.query = event.target.value;
      load();
    });
    root.querySelectorAll("[data-edit-request]").forEach((button) => {
      button.addEventListener("click", () => {
        state.modal = state.requests.find((item) => Number(item.id) === Number(button.dataset.editRequest)) || null;
        state.notice = null;
        render();
      });
    });
    root.querySelectorAll("[data-validate-request]").forEach((button) => {
      button.addEventListener("click", () => {
        state.validationModal = state.requests.find((item) => Number(item.id) === Number(button.dataset.validateRequest)) || null;
        state.notice = null;
        render();
      });
    });
    root.querySelectorAll("[data-delete-request]").forEach((button) => {
      button.addEventListener("click", () => deleteDeposit(Number(button.dataset.deleteRequest)));
    });
    root.querySelectorAll("[data-close]").forEach((button) => button.addEventListener("click", closeModals));
    root.querySelector("[data-close-modal]")?.addEventListener("click", (event) => {
      if (event.target.dataset.closeModal !== undefined) closeModals();
    });
    root.querySelectorAll("[data-modal-panel]").forEach((panel) => panel.addEventListener("click", (event) => event.stopPropagation()));
  }

  function closeModals() {
    state.modal = null;
    state.validationModal = null;
    state.saving = false;
    render();
  }

  function currentSiteName() {
    const site = state.sites.find((site) => Number(site.id) === Number(state.selectedSiteId));
    return site ? site.name : "Site actif";
  }

  function today() {
    return new Date().toISOString().slice(0, 10);
  }

  function datetimeLocal(value) {
    if (!value) return new Date().toISOString().slice(0, 16);
    return String(value).replace(" ", "T").slice(0, 16);
  }

  function dateFr(value) {
    if (!value) return "";
    const date = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "2-digit", year: "numeric" }).format(date);
  }

  function money(value) {
    return new Intl.NumberFormat("fr-FR", { style: "currency", currency: "EUR" }).format(Number(value || 0));
  }

  function noticeHtml(notice) {
    return `<div class="deposit-notice deposit-notice-${esc(notice.type || "success")}">${esc(notice.message || "")}</div>`;
  }

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function ensureStyle() {
    if (document.getElementById("crm-deposit-requests-style")) return;
    const style = document.createElement("style");
    style.id = "crm-deposit-requests-style";
    style.textContent = `
      .layout-container.layout-page:has(#crm-deposit-requests-module),
      .layout-page:has(#crm-deposit-requests-module){width:100%;max-width:100%;min-width:0;overflow-x:hidden}
      main:has(#crm-deposit-requests-module){min-width:0;overflow-x:hidden}
      #crm-deposit-requests-module{width:100%;max-width:100%;min-width:0;overflow-x:hidden;color:var(--color-secondary-900,#0f172a)}
      #crm-deposit-requests-module *{box-sizing:border-box}
      #crm-deposit-requests-module .deposit-page{display:grid;gap:1rem;min-width:0}
      #crm-deposit-requests-module .deposit-head{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem}
      #crm-deposit-requests-module .deposit-eyebrow{display:block;color:rgb(var(--theme-primary));font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
      #crm-deposit-requests-module h1,#crm-deposit-requests-module h2{margin:0;color:var(--color-secondary-900,#0f172a);font-weight:600;letter-spacing:0;line-height:1.12}
      #crm-deposit-requests-module h1{margin-top:.12rem;font-size:1.85rem}
      #crm-deposit-requests-module h2{font-size:1.1rem}
      #crm-deposit-requests-module p{margin:.25rem 0 0;color:var(--color-secondary-500,#64748b);font-size:.86rem;font-weight:750}
      #crm-deposit-requests-module .deposit-actions{display:flex;justify-content:flex-end;gap:.5rem;flex-wrap:wrap}
      #crm-deposit-requests-module .deposit-card,#crm-deposit-requests-module .deposit-summary-card{border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.05);min-width:0}
      #crm-deposit-requests-module .deposit-loading,.deposit-empty{display:grid;min-height:8rem;place-items:center;color:var(--color-secondary-500,#64748b);font-weight:850;text-align:center}
      #crm-deposit-requests-module .deposit-button,#crm-deposit-requests-module .deposit-mini{display:inline-flex;align-items:center;justify-content:center;gap:.42rem;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:#fff;color:var(--color-secondary-700,#334155);font-weight:850;text-decoration:none;white-space:nowrap;cursor:pointer}
      #crm-deposit-requests-module .deposit-button{min-height:2.45rem;padding:.55rem .85rem;font-size:.84rem}
      #crm-deposit-requests-module .deposit-mini{min-height:2rem;padding:.38rem .55rem;font-size:.74rem}
      #crm-deposit-requests-module .deposit-button-primary,#crm-deposit-requests-module .deposit-mini-primary{border-color:rgb(var(--theme-primary));background:rgb(var(--theme-primary));color:#fff}
      #crm-deposit-requests-module .deposit-mini-danger:hover{border-color:#fecaca;color:#b91c1c;background:#fff7f7}
      #crm-deposit-requests-module .deposit-icon{width:1rem;height:1rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #crm-deposit-requests-module .deposit-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.8rem}
      #crm-deposit-requests-module .deposit-summary-card{display:grid;grid-template-columns:2.65rem minmax(0,1fr);align-items:center;gap:.75rem;padding:.9rem}
      #crm-deposit-requests-module .deposit-summary-icon{display:grid;place-items:center;width:2.65rem;height:2.65rem;border-radius:.55rem;background:color-mix(in srgb,var(--deposit-tone,var(--theme-primary-color)) 14%,white);color:var(--deposit-tone,var(--theme-primary-color))}
      #crm-deposit-requests-module .deposit-summary-pending{--deposit-tone:#f59e0b}
      #crm-deposit-requests-module .deposit-summary-validated{--deposit-tone:#16a34a}
      #crm-deposit-requests-module .deposit-summary-total{--deposit-tone:rgb(var(--theme-primary))}
      #crm-deposit-requests-module .deposit-summary-card small{display:block;color:var(--color-secondary-500,#64748b);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #crm-deposit-requests-module .deposit-summary-card strong{display:block;margin:.15rem 0;color:var(--color-secondary-900,#0f172a);font-size:1.25rem;font-weight:600;line-height:1.05}
      #crm-deposit-requests-module .deposit-summary-card em{display:block;color:var(--color-secondary-400,#94a3b8);font-size:.72rem;font-style:normal;font-weight:750;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #crm-deposit-requests-module .deposit-toolbar{display:grid;grid-template-columns:minmax(0,1fr) minmax(10rem,.35fr) minmax(8rem,.25fr);gap:.75rem;padding:.9rem;border-bottom:1px solid var(--color-surface-200,#e2e8f0)}
      #crm-deposit-requests-module label{display:grid;gap:.35rem;min-width:0;color:var(--color-secondary-500,#64748b);font-size:.76rem;font-weight:900}
      #crm-deposit-requests-module input,#crm-deposit-requests-module select,#crm-deposit-requests-module textarea{width:100%;min-height:2.45rem;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:#fff;padding:.55rem .7rem;color:var(--color-secondary-900,#0f172a);font:inherit;font-size:.86rem;font-weight:750;outline:none}
      #crm-deposit-requests-module textarea{min-height:5.4rem;resize:vertical}
      #crm-deposit-requests-module input:focus,#crm-deposit-requests-module select:focus,#crm-deposit-requests-module textarea:focus{border-color:rgb(var(--theme-primary) / .55);box-shadow:0 0 0 3px rgb(var(--theme-primary) / .12)}
      #crm-deposit-requests-module .deposit-table-wrap{max-width:100%;overflow:auto;-webkit-overflow-scrolling:touch}
      #crm-deposit-requests-module .deposit-table{width:100%;border-collapse:collapse;min-width:min(70rem,calc(100vw - 2rem))}
      #crm-deposit-requests-module th,#crm-deposit-requests-module td{border-bottom:1px solid var(--color-surface-200,#e2e8f0);padding:.72rem .8rem;text-align:left;font-size:.82rem;vertical-align:middle}
      #crm-deposit-requests-module th{background:var(--color-surface-50,#f8fafc);color:var(--color-secondary-500,#64748b);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #crm-deposit-requests-module td{color:var(--color-secondary-800,#1e293b);font-weight:750}
      #crm-deposit-requests-module td small{display:block;margin-top:.1rem;color:var(--color-secondary-500,#64748b);font-size:.72rem;font-weight:750}
      #crm-deposit-requests-module .deposit-doc{font-weight:600;color:var(--color-secondary-900,#0f172a)}
      #crm-deposit-requests-module .deposit-amount{font-weight:600;color:var(--color-secondary-900,#0f172a);white-space:nowrap}
      #crm-deposit-requests-module .deposit-status{display:inline-flex;align-items:center;gap:.35rem;border-radius:999px;padding:.25rem .58rem;font-size:.72rem;font-weight:600;white-space:nowrap}
      #crm-deposit-requests-module .deposit-status.is-pending{background:#fef3c7;color:#92400e}
      #crm-deposit-requests-module .deposit-status.is-valid{background:#dcfce7;color:#166534}
      #crm-deposit-requests-module .deposit-muted{color:var(--color-secondary-400,#94a3b8);font-size:.78rem;font-weight:800}
      #crm-deposit-requests-module .deposit-row-actions{display:flex;justify-content:flex-end;gap:.4rem;white-space:nowrap}
      #crm-deposit-requests-module .deposit-notice{border-radius:.5rem;padding:.8rem .9rem;font-size:.86rem;font-weight:850}
      #crm-deposit-requests-module .deposit-notice-success{border:1px solid #bbf7d0;background:#f0fdf4;color:#166534}
      #crm-deposit-requests-module .deposit-notice-error{border:1px solid #fecaca;background:#fef2f2;color:#991b1b}
      #crm-deposit-requests-module .deposit-modal-backdrop{position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;background:rgba(15,23,42,.45);padding:1rem}
      #crm-deposit-requests-module .deposit-modal{width:min(100%,42rem);max-height:92vh;display:grid;grid-template-rows:auto minmax(0,1fr);overflow:hidden;border-radius:.75rem;background:#fff;box-shadow:0 24px 80px rgba(15,23,42,.28)}
      #crm-deposit-requests-module .deposit-small-modal{width:min(100%,30rem)}
      #crm-deposit-requests-module .deposit-modal header,#crm-deposit-requests-module .deposit-form footer{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--color-surface-200,#e2e8f0);padding:1rem}
      #crm-deposit-requests-module .deposit-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;overflow:auto;padding:1rem}
      #crm-deposit-requests-module .deposit-form footer{grid-column:1/-1;justify-content:flex-end;border-top:1px solid var(--color-surface-200,#e2e8f0);border-bottom:0;margin:0 -1rem -1rem;padding:1rem}
      #crm-deposit-requests-module .deposit-field-full{grid-column:1/-1}
      .dark #crm-deposit-requests-module .deposit-card,.dark #crm-deposit-requests-module .deposit-summary-card,.dark #crm-deposit-requests-module .deposit-modal{background:var(--color-surface-900,#0f172a);border-color:var(--color-surface-700,#334155)}
      .dark #crm-deposit-requests-module input,.dark #crm-deposit-requests-module select,.dark #crm-deposit-requests-module textarea{background:var(--color-surface-800,#1e293b);border-color:var(--color-surface-700,#334155);color:#fff}
      .dark #crm-deposit-requests-module th{background:var(--color-surface-800,#1e293b)}
      @media (max-width:900px){#crm-deposit-requests-module .deposit-summary{grid-template-columns:repeat(2,minmax(0,1fr))}#crm-deposit-requests-module .deposit-toolbar{grid-template-columns:1fr 1fr}}
      @media (max-width:640px){#crm-deposit-requests-module .deposit-head{display:grid}#crm-deposit-requests-module h1{font-size:1.5rem}#crm-deposit-requests-module .deposit-summary{grid-template-columns:1fr}#crm-deposit-requests-module .deposit-toolbar,#crm-deposit-requests-module .deposit-form{grid-template-columns:1fr}#crm-deposit-requests-module .deposit-field-full{grid-column:auto}#crm-deposit-requests-module .deposit-modal-backdrop{align-items:end;padding:.65rem}#crm-deposit-requests-module .deposit-modal{max-height:88vh;border-radius:.75rem .75rem .5rem .5rem}#crm-deposit-requests-module .deposit-actions .deposit-button{width:100%}}
    `;
    document.head.appendChild(style);
  }

  function icon(name) {
    const icons = {
      plus: `<path d="M12 5v14M5 12h14"/>`,
      check: `<path d="m20 6-11 11-5-5"/>`,
      clock: `<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>`,
      file: `<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/>`,
      edit: `<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>`,
      trash: `<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/>`,
      x: `<path d="M18 6 6 18M6 6l12 12"/>`,
    };
    return `<svg class="deposit-icon" viewBox="0 0 24 24" aria-hidden="true">${icons[name] || icons.file}</svg>`;
  }

  function watchRouteChanges() {
    if (window.__crmDepositRequestsRouteWatcher) return;
    window.__crmDepositRequestsRouteWatcher = true;
    window.addEventListener("popstate", () => window.dispatchEvent(new Event("crm:deposit-requests-route-changed")));
    window.addEventListener("crm:navigation", () => window.setTimeout(boot, 0));
    window.addEventListener("crm:route-changed", () => window.setTimeout(boot, 0));
    window.addEventListener("crm:deposit-requests-route-changed", () => window.setTimeout(boot, 0));
  }

  watchRouteChanges();
  document.addEventListener("DOMContentLoaded", () => {
    boot();
    new MutationObserver(() => boot()).observe(document.documentElement, { childList: true, subtree: true });
  });
  window.addEventListener("crm:active-site-changed", (event) => {
    state.selectedSiteId = numberOrNull(event.detail && event.detail.siteId) || numberOrNull(localStorage.getItem(storageSiteKey));
    if (isRoute() && root && mountedRoots.has(root)) load();
  });
  boot();
})();

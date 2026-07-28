(function () {
  const api = "/api/tournees-representants";
  const routePath = "/rapport-visite";
  const legacyRoutePath = "/tournees-representants";
  const routePaths = new Set([routePath, legacyRoutePath]);
  const rootId = "crm-sales-tours-module";
  const styleId = "crm-sales-tours-style";
  const routeStyleId = `${styleId}-route`;
  const storageSiteKey = "crm:active-site-id";
  const routeEvent = "crm:sales-tours-route-changed";
  const reclaimableHostIds = [
    "crm-leaves-module",
    "crm-dashboard-module",
    "crm-documents-module",
    "crm-teams-module",
    "crm-cash-control-module",
    "crm-deposit-requests-module",
    "crm-check-remittances-module"
  ];
  const mountedRoots = new WeakSet();
  let mountTimer = null;
  let guardTimer = null;
  let mountAttempts = 0;
  let root = null;

  const state = {
    loading: true,
    saving: false,
    error: "",
    notice: null,
    user: null,
    sites: [],
    representatives: [],
    selectedSiteId: numberOrNull(localStorage.getItem(storageSiteKey)),
    filters: {
      month: monthValue(new Date()),
      representativeId: "",
      status: "",
      query: "",
    },
    summary: {},
    tours: [],
    selectedTour: null,
    statusOptions: [],
    visitStatusOptions: [],
    visitTypeOptions: [],
    priorityOptions: [],
    moodOptions: [],
    tourModal: null,
    visitModal: null,
    reportModal: null,
  };

  function numberOrNull(value) {
    const number = Number(value || 0);
    return Number.isFinite(number) && number > 0 ? number : null;
  }

  function isRoute() {
    return routePaths.has(window.location.pathname.replace(/\/$/, "") || "/");
  }

  function syncRouteClass() {
    const active = isRoute();
    document.documentElement.classList.toggle("crm-sales-tours-route", active);
    document.documentElement.classList.toggle("crm-sales-tours-pending", active && !document.getElementById(rootId));
  }

  function clearRouteClass() {
    document.documentElement.classList.remove("crm-sales-tours-route", "crm-sales-tours-pending");
  }

  function ensureHost() {
    if (!isRoute()) {
      clearRouteClass();
      return false;
    }

    let host = document.getElementById(rootId);
    if (!host) {
      syncRouteClass();
      return false;
    }

    root = host;
    ensureStyle();
    syncRouteClass();

    if (mountedRoots.has(host)) {
      render();
      return true;
    }

    mountedRoots.add(host);
    load();
    return true;
  }

  function scheduleMount(reset = false) {
    if (reset) mountAttempts = 0;
    if (mountTimer) window.clearTimeout(mountTimer);

    if (!isRoute()) {
      clearRouteClass();
      return;
    }

    ensureRouteStyle();
    syncRouteClass();
    startRouteGuard();

    mountTimer = window.setTimeout(() => {
      mountTimer = null;
      if (!isRoute()) {
        clearRouteClass();
        return;
      }

      syncRouteClass();
      if (ensureHost()) return;

      mountAttempts += 1;
      if (mountAttempts < 40) scheduleMount(false);
    }, mountAttempts < 8 ? 150 : 500);
  }

  function startRouteGuard() {
    if (guardTimer) return;

    guardTimer = window.setInterval(() => {
      if (!isRoute()) {
        window.clearInterval(guardTimer);
        guardTimer = null;
        clearRouteClass();
        return;
      }

      syncRouteClass();
      if (!document.getElementById(rootId)) {
        root = null;
        scheduleMount(false);
      }
    }, 500);
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

  async function load(selectedTourId) {
    if (!root) return;
    state.loading = true;
    state.error = "";
    render();

    try {
      const payload = await request("bootstrap", null, {
        site_id: state.selectedSiteId || "",
        month: state.filters.month,
        representativeId: state.filters.representativeId,
        status: state.filters.status,
        query: state.filters.query,
        tourId: selectedTourId || (state.selectedTour && state.selectedTour.id) || "",
      });

      state.user = payload.user || null;
      state.sites = Array.isArray(payload.sites) ? payload.sites : [];
      state.representatives = Array.isArray(payload.representatives) ? payload.representatives : [];
      state.selectedSiteId = Number(payload.selectedSiteId || state.selectedSiteId || 0) || null;
      state.filters = Object.assign(state.filters, payload.filters || {});
      state.summary = payload.summary || {};
      state.tours = Array.isArray(payload.tours) ? payload.tours : [];
      state.selectedTour = payload.selectedTour || null;
      state.statusOptions = Array.isArray(payload.statusOptions) ? payload.statusOptions : [];
      state.visitStatusOptions = Array.isArray(payload.visitStatusOptions) ? payload.visitStatusOptions : [];
      state.visitTypeOptions = Array.isArray(payload.visitTypeOptions) ? payload.visitTypeOptions : [];
      state.priorityOptions = Array.isArray(payload.priorityOptions) ? payload.priorityOptions : [];
      state.moodOptions = Array.isArray(payload.moodOptions) ? payload.moodOptions : [];
      if (state.selectedSiteId) localStorage.setItem(storageSiteKey, String(state.selectedSiteId));
    } catch (error) {
      state.error = error instanceof Error ? error.message : "Impossible de charger les rapports de visite";
    } finally {
      state.loading = false;
      render();
    }
  }

  async function saveTour(event) {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    if (state.tourModal && state.tourModal.id) data.id = state.tourModal.id;
    data.siteId = state.selectedSiteId;
    state.saving = true;
    render();

    try {
      const payload = await request("save_tour", data);
      state.notice = { type: "success", message: "Rapport enregistré." };
      state.tourModal = null;
      await load(payload.tour && payload.tour.id);
    } catch (error) {
      state.saving = false;
      state.notice = { type: "error", message: error instanceof Error ? error.message : "Enregistrement impossible" };
      render();
    }
  }

  async function saveVisit(event) {
    event.preventDefault();
    if (!state.selectedTour) return;
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    data.tourId = state.selectedTour.id;
    if (state.visitModal && state.visitModal.id) data.id = state.visitModal.id;
    state.saving = true;
    render();

    try {
      const payload = await request("save_visit", data);
      state.notice = { type: "success", message: "Visite enregistrée." };
      state.visitModal = null;
      await load(payload.tour && payload.tour.id);
    } catch (error) {
      state.saving = false;
      state.notice = { type: "error", message: error instanceof Error ? error.message : "Enregistrement impossible" };
      render();
    }
  }

  async function saveReport(event) {
    event.preventDefault();
    if (!state.selectedTour) return;
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    data.id = state.selectedTour.id;
    state.saving = true;
    render();

    try {
      const payload = await request("save_report", data);
      state.notice = { type: "success", message: "Rapport de visite enregistré." };
      state.reportModal = null;
      await load(payload.tour && payload.tour.id);
    } catch (error) {
      state.saving = false;
      state.notice = { type: "error", message: error instanceof Error ? error.message : "Enregistrement impossible" };
      render();
    }
  }

  async function deleteTour(id) {
    if (!window.confirm("Supprimer ce rapport de visite et toutes ses visites ?")) return;
    state.saving = true;
    render();

    try {
      await request("delete_tour", { id });
      state.notice = { type: "success", message: "Rapport supprimé." };
      state.selectedTour = null;
      await load();
    } catch (error) {
      state.saving = false;
      state.notice = { type: "error", message: error instanceof Error ? error.message : "Suppression impossible" };
      render();
    }
  }

  async function deleteVisit(id) {
    if (!window.confirm("Supprimer cette visite ?")) return;
    state.saving = true;
    render();

    try {
      await request("delete_visit", { id });
      state.notice = { type: "success", message: "Visite supprimée." };
      await load(state.selectedTour && state.selectedTour.id);
    } catch (error) {
      state.saving = false;
      state.notice = { type: "error", message: error instanceof Error ? error.message : "Suppression impossible" };
      render();
    }
  }

  function render() {
    if (!root) return;

    if (state.loading && !state.tours.length) {
      root.innerHTML = `<div class="tour-loading">Chargement du rapport de visite...</div>`;
      return;
    }

    root.innerHTML = `
      <div class="tour-page">
        <header class="tour-header">
          <div class="tour-title">
            <span>Commercial</span>
            <h1>Rapport de visite</h1>
            <p>Planning, visites clients et rapports de visite par site.</p>
          </div>
          <div class="tour-actions">
            ${canCreate() ? `<button type="button" class="tour-button tour-button-primary" data-new-tour>${icon("plus")}<span>Nouveau rapport</span></button>` : ""}
            ${state.selectedTour && canReport() ? `<button type="button" class="tour-button" data-report-tour>${icon("clipboard")}<span>Rapport</span></button>` : ""}
          </div>
        </header>

        ${noticeHtml()}
        ${state.error ? `<div class="tour-notice tour-notice-error">${esc(state.error)}</div>` : ""}

        <section class="tour-stats">
          ${statCard("Rapports", state.summary.tours || 0, "Mois affiché", "calendar", "#2563eb")}
          ${statCard("Terminées", state.summary.completedTours || 0, "Rapports clos", "check", "#16a34a")}
          ${statCard("Visites", state.summary.visits || 0, `${state.summary.doneVisits || 0} réalisée(s)`, "users", "var(--theme-primary-color)")}
          ${statCard("Actions", state.summary.nextActions || 0, "Suites à prévoir", "flag", "#d97706")}
        </section>

        <section class="tour-toolbar">
          <label><span>Mois</span><input type="month" data-filter="month" value="${esc(state.filters.month || monthValue(new Date()))}"></label>
          <label><span>Représentant</span><select data-filter="representativeId">${option("", "Tous", state.filters.representativeId)}${state.representatives.map((rep) => option(rep.id, rep.name, state.filters.representativeId)).join("")}</select></label>
          <label><span>Statut</span><select data-filter="status">${option("", "Tous les statuts", state.filters.status)}${state.statusOptions.map((item) => option(item.value, item.label, state.filters.status)).join("")}</select></label>
          <label><span>Recherche</span><input type="search" data-filter="query" placeholder="Client, ville, contact..." value="${esc(state.filters.query || "")}"></label>
        </section>

        <main class="tour-layout">
          <section class="tour-left">
            <article class="tour-card">
              <div class="tour-card-head">
                <div>
                  <h2>Calendrier</h2>
                  <p>${esc(monthLabel(state.filters.month))}</p>
                </div>
                <div class="tour-month-nav">
                  <button type="button" data-month-prev title="Mois précédent">${icon("chevronLeft")}</button>
                  <button type="button" data-month-today>Aujourd'hui</button>
                  <button type="button" data-month-next title="Mois suivant">${icon("chevronRight")}</button>
                </div>
              </div>
              ${calendarHtml()}
            </article>

            <article class="tour-card">
              <div class="tour-card-head">
                <div>
                  <h2>Rapports du mois</h2>
                  <p>${state.tours.length} rapport(s)</p>
                </div>
              </div>
              ${tourListHtml()}
            </article>
          </section>

          <section class="tour-detail">
            ${detailHtml()}
          </section>
        </main>

        ${tourModalHtml()}
        ${visitModalHtml()}
        ${reportModalHtml()}
      </div>
    `;
    bindEvents();
  }

  function noticeHtml() {
    if (!state.notice) return "";
    return `<div class="tour-notice tour-notice-${esc(state.notice.type)}">${esc(state.notice.message)}</div>`;
  }

  function statCard(label, value, detail, iconName, color) {
    return `
      <article class="tour-stat" style="--stat-color:${esc(color)}">
        <div class="tour-stat-icon">${icon(iconName)}</div>
        <div>
          <span>${esc(label)}</span>
          <strong>${esc(value)}</strong>
          <small>${esc(detail)}</small>
        </div>
      </article>
    `;
  }

  function calendarHtml() {
    const days = calendarDays(state.filters.month);
    const weekdays = ["LUN", "MAR", "MER", "JEU", "VEN", "SAM", "DIM"];
    return `
      <div class="tour-calendar">
        ${weekdays.map((day) => `<div class="tour-weekday">${day}</div>`).join("")}
        ${days.map((day) => dayCell(day)).join("")}
      </div>
    `;
  }

  function dayCell(day) {
    const items = toursForDate(day.date);
    const selected = state.selectedTour && state.selectedTour.tourDate === isoDate(day.date);
    return `
      <button type="button" class="tour-day ${day.current ? "" : "is-muted"} ${selected ? "is-selected" : ""}" data-day="${isoDate(day.date)}">
        <span class="tour-day-number">${day.date.getDate()}</span>
        <span class="tour-day-list">
          ${items.slice(0, 3).map((tour) => `<span class="tour-day-pill" data-tour-id="${tour.id}">${esc(tour.representativeName || tour.title)} · ${tour.visitsTotal || 0}</span>`).join("")}
          ${items.length > 3 ? `<span class="tour-day-more">+${items.length - 3}</span>` : ""}
        </span>
      </button>
    `;
  }

  function tourListHtml() {
    if (!state.tours.length) {
      return `<div class="tour-empty">Aucun rapport de visite sur ce mois. ${canCreate() ? "Créez le premier rapport depuis le bouton du haut." : ""}</div>`;
    }

    return `
      <div class="tour-list">
        ${state.tours.map((tour) => `
          <button type="button" class="tour-row ${state.selectedTour && state.selectedTour.id === tour.id ? "is-active" : ""}" data-open-tour="${tour.id}">
            <div class="tour-row-date">
              <strong>${dayNumber(tour.tourDate)}</strong>
              <span>${monthShort(tour.tourDate)}</span>
            </div>
            <div class="tour-row-main">
              <strong>${esc(tour.title)}</strong>
              <span>${esc(tour.representativeName || "Représentant non défini")} · ${tour.visitsDone || 0}/${tour.visitsTotal || 0} visite(s)</span>
            </div>
            <span class="tour-badge tour-status-${esc(tour.status)}">${esc(tour.statusLabel)}</span>
          </button>
        `).join("")}
      </div>
    `;
  }

  function detailHtml() {
    const tour = state.selectedTour;
    if (!tour) {
      return `
        <article class="tour-card tour-card-empty">
          ${icon("route")}
          <h2>Aucun rapport sélectionné</h2>
          <p>Sélectionnez une journée du calendrier ou créez un rapport pour préparer les visites clients.</p>
        </article>
      `;
    }

    return `
      <article class="tour-card">
        <div class="tour-detail-head">
          <div>
            <span class="tour-eyebrow">${esc(dateLabel(tour.tourDate))}</span>
            <h2>${esc(tour.title)}</h2>
            <p>${esc(tour.representativeName || "Représentant non défini")} · ${esc(tour.siteName || "")}</p>
          </div>
          <span class="tour-badge tour-status-${esc(tour.status)}">${esc(tour.statusLabel)}</span>
        </div>
        ${tour.objective ? `<div class="tour-objective"><strong>Objectif</strong><span>${esc(tour.objective)}</span></div>` : ""}
        <div class="tour-progress">
          <span style="width:${progress(tour)}%"></span>
        </div>
        <div class="tour-detail-actions">
          ${canCreate() || canManage() ? `<button type="button" class="tour-button" data-edit-tour="${tour.id}">${icon("edit")}Modifier</button>` : ""}
          ${canReport() ? `<button type="button" class="tour-button tour-button-primary" data-new-visit>${icon("plus")}Ajouter une visite</button>` : ""}
          ${canReport() ? `<button type="button" class="tour-button" data-report-tour>${icon("clipboard")}Rapport</button>` : ""}
          ${canManage() ? `<button type="button" class="tour-button tour-button-danger" data-delete-tour="${tour.id}">${icon("trash")}Supprimer</button>` : ""}
        </div>
      </article>

      <article class="tour-card">
        <div class="tour-card-head">
          <div>
            <h2>Visites clients</h2>
            <p>${tour.visitsTotal || 0} visite(s) prévues</p>
          </div>
        </div>
        ${visitsHtml(tour.visits || [])}
      </article>

      <article class="tour-card">
        <div class="tour-card-head">
          <div>
            <h2>Rapport de visite</h2>
            <p>Synthèse, kilomètres et actions à suivre</p>
          </div>
        </div>
        <div class="tour-report">
          <div><small>Kilomètres</small><strong>${esc(tour.kilometers || 0)} km</strong></div>
          <div><small>Ressenti</small><strong>${esc(moodLabel(tour.reportMood))}</strong></div>
          <div class="tour-report-wide"><small>Synthèse</small><p>${tour.reportSummary ? esc(tour.reportSummary) : "Aucun rapport renseigné."}</p></div>
          <div class="tour-report-wide"><small>Actions à suivre</small><p>${tour.reportNextActions ? esc(tour.reportNextActions) : "Aucune action renseignée."}</p></div>
        </div>
      </article>
    `;
  }

  function visitsHtml(visits) {
    if (!visits.length) return `<div class="tour-empty">Aucune visite ajoutée à ce rapport.</div>`;
    return `
      <div class="visit-table-wrap">
        <table class="visit-table">
          <thead>
            <tr>
              <th>Heure</th>
              <th>Client</th>
              <th>Contact</th>
              <th>Adresse</th>
              <th>Type</th>
              <th>Priorité</th>
              <th>Statut</th>
              <th>Suite</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            ${visits.map((visit) => `
              <tr>
                <td>
                  <strong>${esc(timeLabel(visit.plannedAt))}</strong>
                  <small>${esc(visit.durationMinutes || 45)} min</small>
                </td>
                <td>
                  <strong class="visit-client-name">${esc(visit.customerName)}</strong>
                  ${visit.objective ? `<small>${esc(visit.objective)}</small>` : ""}
                  ${visit.result ? `<small>Résultat : ${esc(visit.result)}</small>` : ""}
                </td>
                <td>
                  ${visit.contactName ? `<strong>${esc(visit.contactName)}</strong>` : `<span class="tour-muted">Non renseigné</span>`}
                  ${visit.contactPhone ? `<small>${esc(visit.contactPhone)}</small>` : ""}
                  ${visit.contactEmail ? `<small>${esc(visit.contactEmail)}</small>` : ""}
                </td>
                <td>
                  <span>${esc([visit.address, visit.postalCode, visit.city].filter(Boolean).join(" ")) || "Adresse non renseignée"}</span>
                </td>
                <td><span class="visit-chip">${esc(visit.visitTypeLabel)}</span></td>
                <td><span class="visit-chip">${esc(visit.priorityLabel)}</span></td>
                <td><span class="tour-badge visit-status-${esc(visit.status)}">${esc(visit.statusLabel)}</span></td>
                <td>
                  ${visit.nextAction ? `<strong>${esc(visit.nextAction)}</strong>${visit.nextActionDate ? `<small>${esc(dateLabel(visit.nextActionDate))}</small>` : ""}` : `<span class="tour-muted">Aucune</span>`}
                </td>
                <td class="visit-actions">
                  ${canReport() ? `<button type="button" title="Modifier" data-edit-visit="${visit.id}">${icon("edit")}</button>` : ""}
                  ${canReport() ? `<button type="button" title="Supprimer" data-delete-visit="${visit.id}">${icon("trash")}</button>` : ""}
                </td>
              </tr>
            `).join("")}
          </tbody>
        </table>
      </div>
    `;
  }

  function tourModalHtml() {
    if (!state.tourModal) return "";
    const item = state.tourModal || {};
    return modalShell(item.id ? "Modifier le rapport" : "Nouveau rapport", `
      <form class="tour-form" data-tour-form>
        <div class="tour-form-grid">
          <label><span>Date *</span><input name="tourDate" type="date" required value="${esc(item.tourDate || isoDate(new Date()))}"></label>
          <label><span>Représentant *</span><select name="representativeUserId" required>${state.representatives.map((rep) => option(rep.id, rep.name, item.representativeUserId || (state.user && state.user.id))).join("")}</select></label>
          <label class="tour-form-wide"><span>Titre</span><input name="title" value="${esc(item.title || "")}" placeholder="Ex : Rapport Pau Nord"></label>
          <label><span>Statut</span><select name="status">${state.statusOptions.map((status) => option(status.value, status.label, item.status || "planned")).join("")}</select></label>
          <label class="tour-form-wide"><span>Objectif</span><textarea name="objective" rows="3" placeholder="Objectifs du rapport, priorités, clients sensibles...">${esc(item.objective || "")}</textarea></label>
        </div>
        <div class="tour-modal-actions">
          <button type="button" class="tour-button" data-close-modal>Annuler</button>
          <button type="submit" class="tour-button tour-button-primary" ${state.saving ? "disabled" : ""}>Enregistrer</button>
        </div>
      </form>
    `);
  }

  function visitModalHtml() {
    if (!state.visitModal) return "";
    const item = state.visitModal || {};
    return modalShell(item.id ? "Modifier la visite" : "Ajouter une visite", `
      <form class="tour-form" data-visit-form>
        <div class="tour-form-grid">
          <label class="tour-form-wide"><span>Client *</span><input name="customerName" required value="${esc(item.customerName || "")}" placeholder="Nom du client ou prospect"></label>
          <label><span>Heure prévue</span><input name="plannedAt" type="datetime-local" value="${esc(localDateTimeValue(item.plannedAt, state.selectedTour && state.selectedTour.tourDate))}"></label>
          <label><span>Durée</span><input name="durationMinutes" type="number" min="15" max="480" step="15" value="${esc(item.durationMinutes || 45)}"></label>
          <label><span>Type</span><select name="visitType">${state.visitTypeOptions.map((type) => option(type.value, type.label, item.visitType || "client")).join("")}</select></label>
          <label><span>Priorité</span><select name="priority">${state.priorityOptions.map((priority) => option(priority.value, priority.label, item.priority || "normal")).join("")}</select></label>
          <label><span>Statut</span><select name="status">${state.visitStatusOptions.map((status) => option(status.value, status.label, item.status || "planned")).join("")}</select></label>
          <label><span>Contact</span><input name="contactName" value="${esc(item.contactName || "")}"></label>
          <label><span>Téléphone</span><input name="contactPhone" value="${esc(item.contactPhone || "")}"></label>
          <label><span>Email</span><input name="contactEmail" type="email" value="${esc(item.contactEmail || "")}"></label>
          <label class="tour-form-wide"><span>Adresse</span><input name="address" value="${esc(item.address || "")}"></label>
          <label><span>Code postal</span><input name="postalCode" value="${esc(item.postalCode || "")}"></label>
          <label><span>Ville</span><input name="city" value="${esc(item.city || "")}"></label>
          <label class="tour-form-wide"><span>Objectif visite</span><textarea name="objective" rows="2">${esc(item.objective || "")}</textarea></label>
          <label class="tour-form-wide"><span>Résultat</span><textarea name="result" rows="2">${esc(item.result || "")}</textarea></label>
          <label class="tour-form-wide"><span>Prochaine action</span><textarea name="nextAction" rows="2">${esc(item.nextAction || "")}</textarea></label>
          <label><span>Date prochaine action</span><input name="nextActionDate" type="date" value="${esc(item.nextActionDate || "")}"></label>
        </div>
        <div class="tour-modal-actions">
          <button type="button" class="tour-button" data-close-modal>Annuler</button>
          <button type="submit" class="tour-button tour-button-primary" ${state.saving ? "disabled" : ""}>Enregistrer</button>
        </div>
      </form>
    `);
  }

  function reportModalHtml() {
    if (!state.reportModal || !state.selectedTour) return "";
    const tour = state.selectedTour;
    return modalShell("Rapport de visite", `
      <form class="tour-form" data-report-form>
        <div class="tour-form-grid">
          <label><span>Statut</span><select name="status">${state.statusOptions.map((status) => option(status.value, status.label, tour.status || "completed")).join("")}</select></label>
          <label><span>Kilomètres</span><input name="kilometers" type="number" min="0" step="0.1" value="${esc(tour.kilometers || 0)}"></label>
          <label><span>Ressenti</span><select name="reportMood">${option("", "Non renseigné", tour.reportMood)}${state.moodOptions.map((mood) => option(mood.value, mood.label, tour.reportMood)).join("")}</select></label>
          <label class="tour-form-wide"><span>Synthèse</span><textarea name="reportSummary" rows="4" placeholder="Résumé des visites, opportunités, points bloquants...">${esc(tour.reportSummary || "")}</textarea></label>
          <label class="tour-form-wide"><span>Actions à suivre</span><textarea name="reportNextActions" rows="4" placeholder="Relances, devis, appels, prochaines visites...">${esc(tour.reportNextActions || "")}</textarea></label>
        </div>
        <div class="tour-modal-actions">
          <button type="button" class="tour-button" data-close-modal>Annuler</button>
          <button type="submit" class="tour-button tour-button-primary" ${state.saving ? "disabled" : ""}>Enregistrer le rapport</button>
        </div>
      </form>
    `);
  }

  function modalShell(title, body) {
    return `
      <div class="tour-modal-backdrop">
        <section class="tour-modal">
          <header>
            <div>
              <h2>${esc(title)}</h2>
              <p>${state.selectedTour ? esc(state.selectedTour.title) : "Gestion commerciale"}</p>
            </div>
            <button type="button" class="tour-modal-close" data-close-modal>${icon("x")}<span>Fermer</span></button>
          </header>
          ${body}
        </section>
      </div>
    `;
  }

  function bindEvents() {
    root.querySelectorAll("[data-filter]").forEach((input) => {
      input.addEventListener("change", () => {
        state.filters[input.dataset.filter] = input.value;
        load();
      });
      if (input.type === "search") {
        input.addEventListener("keydown", (event) => {
          if (event.key === "Enter") {
            state.filters[input.dataset.filter] = input.value;
            load();
          }
        });
      }
    });
    root.querySelector("[data-new-tour]")?.addEventListener("click", () => {
      state.tourModal = { tourDate: isoDate(new Date()), representativeUserId: state.user && state.user.id, status: "planned" };
      render();
    });
    root.querySelectorAll("[data-open-tour]").forEach((button) => button.addEventListener("click", () => load(button.dataset.openTour)));
    root.querySelectorAll("[data-day]").forEach((button) => button.addEventListener("click", (event) => {
      const pill = event.target.closest("[data-tour-id]");
      if (pill) {
        load(pill.dataset.tourId);
        return;
      }
      const tours = toursForDate(new Date(button.dataset.day + "T00:00:00"));
      if (tours[0]) {
        load(tours[0].id);
      } else if (canCreate()) {
        state.tourModal = { tourDate: button.dataset.day, representativeUserId: state.user && state.user.id, status: "planned" };
        render();
      }
    }));
    root.querySelector("[data-month-prev]")?.addEventListener("click", () => changeMonth(-1));
    root.querySelector("[data-month-next]")?.addEventListener("click", () => changeMonth(1));
    root.querySelector("[data-month-today]")?.addEventListener("click", () => {
      state.filters.month = monthValue(new Date());
      load();
    });
    root.querySelectorAll("[data-edit-tour]").forEach((button) => button.addEventListener("click", () => {
      state.tourModal = Object.assign({}, state.selectedTour);
      render();
    }));
    root.querySelectorAll("[data-delete-tour]").forEach((button) => button.addEventListener("click", () => deleteTour(button.dataset.deleteTour)));
    root.querySelector("[data-new-visit]")?.addEventListener("click", () => {
      state.visitModal = {};
      render();
    });
    root.querySelectorAll("[data-edit-visit]").forEach((button) => button.addEventListener("click", () => {
      const visit = (state.selectedTour?.visits || []).find((item) => String(item.id) === String(button.dataset.editVisit));
      state.visitModal = Object.assign({}, visit || {});
      render();
    }));
    root.querySelectorAll("[data-delete-visit]").forEach((button) => button.addEventListener("click", () => deleteVisit(button.dataset.deleteVisit)));
    root.querySelectorAll("[data-report-tour]").forEach((button) => button.addEventListener("click", () => {
      state.reportModal = true;
      render();
    }));
    root.querySelectorAll("[data-close-modal]").forEach((button) => button.addEventListener("click", () => {
      state.tourModal = null;
      state.visitModal = null;
      state.reportModal = null;
      render();
    }));
    root.querySelector("[data-tour-form]")?.addEventListener("submit", saveTour);
    root.querySelector("[data-visit-form]")?.addEventListener("submit", saveVisit);
    root.querySelector("[data-report-form]")?.addEventListener("submit", saveReport);
  }

  function changeMonth(delta) {
    const date = monthDate(state.filters.month);
    date.setMonth(date.getMonth() + delta);
    state.filters.month = monthValue(date);
    load();
  }

  function canCreate() {
    return Boolean(state.user && state.user.canCreate);
  }

  function canReport() {
    return Boolean(state.user && state.user.canReport);
  }

  function canManage() {
    return Boolean(state.user && state.user.canManage);
  }

  function progress(tour) {
    const total = Number(tour.visitsTotal || 0);
    if (!total) return 0;
    return Math.round((Number(tour.visitsDone || 0) / total) * 100);
  }

  function toursForDate(date) {
    const target = isoDate(date);
    return state.tours.filter((tour) => tour.tourDate === target);
  }

  function calendarDays(month) {
    const base = monthDate(month);
    const year = base.getFullYear();
    const monthIndex = base.getMonth();
    const first = new Date(year, monthIndex, 1);
    const start = new Date(first);
    const mondayOffset = (first.getDay() + 6) % 7;
    start.setDate(first.getDate() - mondayOffset);
    return Array.from({ length: 42 }, (_, index) => {
      const date = new Date(start);
      date.setDate(start.getDate() + index);
      return { date, current: date.getMonth() === monthIndex };
    });
  }

  function monthDate(value) {
    const [year, month] = String(value || monthValue(new Date())).split("-").map(Number);
    return new Date(year || new Date().getFullYear(), (month || 1) - 1, 1);
  }

  function monthValue(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
  }

  function isoDate(value) {
    const date = value instanceof Date ? value : new Date(String(value) + "T00:00:00");
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
  }

  function localDateTimeValue(value, fallbackDate) {
    if (!value) return fallbackDate ? `${fallbackDate}T09:00` : "";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "";
    return `${isoDate(date)}T${String(date.getHours()).padStart(2, "0")}:${String(date.getMinutes()).padStart(2, "0")}`;
  }

  function monthLabel(value) {
    return new Intl.DateTimeFormat("fr-FR", { month: "long", year: "numeric" }).format(monthDate(value));
  }

  function monthShort(value) {
    return new Intl.DateTimeFormat("fr-FR", { month: "short" }).format(new Date(String(value) + "T00:00:00"));
  }

  function dayNumber(value) {
    return new Date(String(value) + "T00:00:00").getDate();
  }

  function dateLabel(value) {
    if (!value) return "-";
    return new Intl.DateTimeFormat("fr-FR", { weekday: "short", day: "2-digit", month: "short", year: "numeric" }).format(new Date(String(value).includes("T") ? value : value + "T00:00:00"));
  }

  function timeLabel(value) {
    if (!value) return "--:--";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "--:--";
    return new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit" }).format(date);
  }

  function moodLabel(value) {
    return (state.moodOptions.find((item) => item.value === value) || {}).label || "Non renseigné";
  }

  function option(value, label, selected) {
    return `<option value="${esc(value)}" ${String(value) === String(selected || "") ? "selected" : ""}>${esc(label)}</option>`;
  }

  function icon(name) {
    const icons = {
      plus: `<path d="M12 5v14M5 12h14"/>`,
      calendar: `<path d="M8 2v4M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/>`,
      check: `<path d="m20 6-11 11-5-5"/>`,
      users: `<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>`,
      flag: `<path d="M4 22V4"/><path d="M4 4h12l-1 4 1 4H4"/>`,
      clipboard: `<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3"/><path d="M8 13h8M8 17h5"/>`,
      edit: `<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>`,
      trash: `<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/>`,
      route: `<circle cx="6" cy="19" r="3"/><circle cx="18" cy="5" r="3"/><path d="M9 19h3a4 4 0 0 0 4-4V9"/>`,
      chevronLeft: `<path d="m15 18-6-6 6-6"/>`,
      chevronRight: `<path d="m9 18 6-6-6-6"/>`,
      x: `<path d="M18 6 6 18M6 6l12 12"/>`,
    };
    return `<svg class="tour-icon" viewBox="0 0 24 24" aria-hidden="true">${icons[name] || icons.calendar}</svg>`;
  }

  function esc(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function ensureRouteStyle() {
    if (document.getElementById(routeStyleId)) return;
    const style = document.createElement("style");
    style.id = routeStyleId;
    style.textContent = `
      html.crm-sales-tours-pending main .layout-container.layout-page,
      html.crm-sales-tours-pending main .layout-page,
      html.crm-sales-tours-pending #crm-leaves-module,
      html.crm-sales-tours-pending #crm-dashboard-module,
      html.crm-sales-tours-pending #crm-documents-module,
      html.crm-sales-tours-pending #crm-teams-module,
      html.crm-sales-tours-pending #crm-cash-control-module,
      html.crm-sales-tours-pending #crm-deposit-requests-module,
      html.crm-sales-tours-pending #crm-check-remittances-module{opacity:0;pointer-events:none}
    `;
    document.head.appendChild(style);
  }

  function ensureStyle() {
    ensureRouteStyle();
    if (document.getElementById(styleId)) return;
    const style = document.createElement("style");
    style.id = styleId;
    style.textContent = `
      .layout-container.layout-page:has(#${rootId}),
      .layout-page:has(#${rootId}){width:100%;max-width:100%;min-width:0;overflow-x:hidden}
      main:has(#${rootId}){min-width:0;overflow-x:hidden}
      #${rootId}{--tour-primary:rgb(var(--theme-primary));--tour-text:var(--color-secondary-900,#0f172a);--tour-muted:var(--color-secondary-500,#64748b);--tour-border:var(--color-surface-200,#e2e8f0);color:var(--tour-text);width:100%;max-width:100%;min-width:0;overflow-x:hidden}
      #${rootId} *{box-sizing:border-box}
      #${rootId} .tour-icon{width:1.05rem;height:1.05rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} .tour-page{display:grid;gap:1rem}
      #${rootId} .tour-header{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem}
      #${rootId} .tour-title span{display:block;color:var(--tour-primary);font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
      #${rootId} .tour-title h1{margin:.15rem 0 0;color:var(--tour-text);font-size:1.85rem;line-height:1.05;font-weight:600;letter-spacing:0}
      #${rootId} .tour-title p{margin:.32rem 0 0;color:var(--tour-muted);font-size:.9rem;font-weight:750}
      #${rootId} .tour-actions,#${rootId} .tour-detail-actions,#${rootId} .tour-modal-actions{display:flex;gap:.55rem;flex-wrap:wrap;justify-content:flex-end}
      #${rootId} .tour-button{display:inline-flex;align-items:center;justify-content:center;gap:.42rem;min-height:2.45rem;border:1px solid var(--tour-border);border-radius:.5rem;background:#fff;padding:.55rem .85rem;color:var(--tour-text);font-size:.83rem;font-weight:600;text-decoration:none;box-shadow:0 10px 22px rgba(15,23,42,.04);cursor:pointer}
      #${rootId} .tour-button-primary{border-color:transparent;background:var(--tour-primary);color:#fff}
      #${rootId} .tour-button-danger{color:#b91c1c}
      #${rootId} .tour-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.85rem}
      #${rootId} .tour-stat{display:grid;grid-template-columns:2.55rem minmax(0,1fr);align-items:center;gap:.75rem;border:1px solid var(--tour-border);border-radius:.5rem;background:#fff;padding:.85rem;box-shadow:0 12px 28px rgba(15,23,42,.05)}
      #${rootId} .tour-stat-icon{display:grid;place-items:center;width:2.55rem;height:2.55rem;border-radius:.5rem;background:color-mix(in srgb,var(--stat-color) 14%,white);color:var(--stat-color)}
      #${rootId} .tour-stat span,#${rootId} .tour-report small{display:block;color:var(--tour-muted);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #${rootId} .tour-stat strong{display:block;margin:.15rem 0;color:var(--tour-text);font-size:1.35rem;font-weight:600;line-height:1.05}
      #${rootId} .tour-stat small{display:block;color:var(--color-secondary-400,#94a3b8);font-size:.72rem;font-weight:750}
      #${rootId} .tour-toolbar{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem;border:1px solid var(--tour-border);border-radius:.5rem;background:#fff;padding:.85rem;box-shadow:0 12px 28px rgba(15,23,42,.04)}
      #${rootId} label{display:grid;gap:.32rem;color:var(--tour-muted);font-size:.75rem;font-weight:600}
      #${rootId} input,#${rootId} select,#${rootId} textarea{width:100%;min-height:2.55rem;border:1px solid var(--tour-border);border-radius:.5rem;background:#fff;padding:.55rem .7rem;color:var(--tour-text);font:inherit;font-size:.88rem;font-weight:760}
      #${rootId} textarea{resize:vertical}
      #${rootId} .tour-layout{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(22rem,.95fr);gap:1rem;align-items:start}
      #${rootId} .tour-left{display:grid;gap:1rem;min-width:0}
      #${rootId} .tour-card{border:1px solid var(--tour-border);border-radius:.5rem;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.05);overflow:hidden}
      #${rootId} .tour-card-head,#${rootId} .tour-detail-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--tour-border);padding:.95rem 1rem}
      #${rootId} .tour-card-head h2,#${rootId} .tour-detail-head h2{margin:0;color:var(--tour-text);font-size:1rem;font-weight:600;letter-spacing:0}
      #${rootId} .tour-card-head p,#${rootId} .tour-detail-head p{margin:.2rem 0 0;color:var(--tour-muted);font-size:.78rem;font-weight:750}
      #${rootId} .tour-month-nav{display:flex;gap:.35rem;align-items:center}
      #${rootId} .tour-month-nav button{min-height:2.25rem;border:1px solid var(--tour-border);border-radius:.5rem;background:#fff;padding:.45rem .65rem;color:var(--tour-text);font-weight:600;cursor:pointer}
      #${rootId} .tour-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border-top:0}
      #${rootId} .tour-weekday{padding:.65rem .45rem;border-bottom:1px solid var(--tour-border);color:var(--tour-muted);font-size:.72rem;font-weight:600;text-align:center}
      #${rootId} .tour-day{display:grid;align-content:start;gap:.3rem;min-height:7rem;border:0;border-right:1px solid var(--tour-border);border-bottom:1px solid var(--tour-border);background:#fff;padding:.55rem;text-align:left;cursor:pointer}
      #${rootId} .tour-day:nth-child(7n+7){border-right:0}
      #${rootId} .tour-day.is-muted{background:#f8fafc;color:#94a3b8}
      #${rootId} .tour-day.is-selected{box-shadow:inset 0 0 0 2px var(--tour-primary)}
      #${rootId} .tour-day-number{display:grid;place-items:center;width:1.8rem;height:1.8rem;border-radius:999px;font-weight:600}
      #${rootId} .tour-day.is-selected .tour-day-number{background:var(--tour-primary);color:#fff}
      #${rootId} .tour-day-list{display:grid;gap:.25rem;min-width:0}
      #${rootId} .tour-day-pill{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;border-radius:.35rem;background:#f1f5f9;padding:.18rem .35rem;color:var(--tour-text);font-size:.7rem;font-weight:850}
      #${rootId} .tour-day-more{color:var(--tour-muted);font-size:.7rem;font-weight:850}
      #${rootId} .tour-list{display:grid;gap:.5rem;padding:1rem}
      #${rootId} .tour-row{display:grid;grid-template-columns:3.5rem minmax(0,1fr) auto;align-items:center;gap:.75rem;border:1px solid var(--tour-border);border-radius:.5rem;background:#fff;padding:.65rem;text-align:left;cursor:pointer}
      #${rootId} .tour-row.is-active{border-color:var(--tour-primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--tour-primary) 12%,transparent)}
      #${rootId} .tour-row-date{display:grid;place-items:center;border-radius:.5rem;background:#f8fafc;min-height:3rem}
      #${rootId} .tour-row-date strong{font-size:1.15rem;font-weight:600}
      #${rootId} .tour-row-date span{color:var(--tour-muted);font-size:.68rem;font-weight:600;text-transform:uppercase}
      #${rootId} .tour-row-main{min-width:0}
      #${rootId} .tour-row-main strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.9rem;font-weight:600}
      #${rootId} .tour-row-main span{display:block;margin-top:.15rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--tour-muted);font-size:.76rem;font-weight:750}
      #${rootId} .tour-badge{display:inline-flex;align-items:center;justify-content:center;min-height:1.75rem;border-radius:999px;background:#f1f5f9;padding:.22rem .62rem;color:var(--tour-text);font-size:.72rem;font-weight:600;white-space:nowrap}
      #${rootId} .tour-status-completed,.visit-status-done{background:#dcfce7;color:#166534}
      #${rootId} .tour-status-in_progress{background:#dbeafe;color:#1d4ed8}
      #${rootId} .tour-status-canceled,.visit-status-canceled,.visit-status-missed{background:#fee2e2;color:#991b1b}
      #${rootId} .tour-detail{display:grid;gap:1rem;min-width:0}
      #${rootId} .tour-eyebrow{display:block;color:var(--tour-primary);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #${rootId} .tour-objective{display:grid;gap:.3rem;border-bottom:1px solid var(--tour-border);padding:.8rem 1rem;color:var(--tour-muted);font-size:.82rem;font-weight:750}
      #${rootId} .tour-objective strong{color:var(--tour-text)}
      #${rootId} .tour-progress{height:.45rem;background:#f1f5f9}
      #${rootId} .tour-progress span{display:block;height:100%;background:var(--tour-primary)}
      #${rootId} .tour-detail-actions{padding:1rem;justify-content:flex-start}
      #${rootId} .visit-table-wrap{max-width:100%;overflow:auto;-webkit-overflow-scrolling:touch}
      #${rootId} .visit-table{width:100%;min-width:min(78rem,calc(100vw - 2rem));border-collapse:collapse}
      #${rootId} .visit-table th{background:#f8fafc;color:var(--tour-muted);font-size:.72rem;font-weight:600;text-align:left;text-transform:uppercase;padding:.82rem .9rem;white-space:nowrap}
      #${rootId} .visit-table td{border-top:1px solid var(--tour-border);padding:.78rem .9rem;color:var(--tour-text);font-size:.82rem;font-weight:760;vertical-align:top}
      #${rootId} .visit-table td strong{display:block;color:var(--tour-text);font-size:.84rem;font-weight:600;line-height:1.25}
      #${rootId} .visit-table td small{display:block;margin-top:.16rem;color:var(--tour-muted);font-size:.72rem;font-weight:760;line-height:1.25}
      #${rootId} .visit-client-name{font-size:.9rem!important}
      #${rootId} .visit-chip{display:inline-flex;align-items:center;min-height:1.65rem;border-radius:999px;background:#f8fafc;padding:.18rem .55rem;color:var(--tour-muted);font-size:.72rem;font-weight:600;white-space:nowrap}
      #${rootId} .tour-muted{color:var(--tour-muted);font-weight:800}
      #${rootId} .visit-actions{display:flex;gap:.35rem;justify-content:flex-end;white-space:nowrap}
      #${rootId} .visit-actions button{display:grid;place-items:center;width:2rem;height:2rem;border:1px solid var(--tour-border);border-radius:.45rem;background:#fff;color:var(--tour-text);cursor:pointer}
      #${rootId} .tour-report{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem;padding:1rem}
      #${rootId} .tour-report>div{border:1px solid var(--tour-border);border-radius:.5rem;background:#f8fafc;padding:.75rem}
      #${rootId} .tour-report strong{display:block;margin-top:.2rem;font-size:1rem;font-weight:600}
      #${rootId} .tour-report p{margin:.25rem 0 0;color:#334155;font-weight:740}
      #${rootId} .tour-report-wide{grid-column:1/-1}
      #${rootId} .tour-empty,.tour-loading{margin:1rem;border:1px dashed var(--tour-border);border-radius:.5rem;padding:1rem;color:var(--tour-muted);font-weight:850;text-align:center}
      #${rootId} .tour-card-empty{display:grid;place-items:center;text-align:center;padding:2rem;color:var(--tour-muted)}
      #${rootId} .tour-card-empty .tour-icon{width:2rem;height:2rem;color:var(--tour-primary)}
      #${rootId} .tour-notice{border-radius:.5rem;padding:.75rem .9rem;font-weight:850}
      #${rootId} .tour-notice-success{background:#dcfce7;color:#166534}
      #${rootId} .tour-notice-error{background:#fee2e2;color:#991b1b}
      #${rootId} .tour-modal-backdrop{position:fixed;inset:0;z-index:1100;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.58);padding:1rem}
      #${rootId} .tour-modal{width:min(820px,100%);max-height:92vh;overflow:auto;border-radius:.75rem;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.28)}
      #${rootId} .tour-modal>header{position:sticky;top:0;z-index:1;display:flex;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--tour-border);background:#fff;padding:1rem}
      #${rootId} .tour-modal h2{margin:0;font-size:1.2rem;font-weight:600}
      #${rootId} .tour-modal p{margin:.2rem 0 0;color:var(--tour-muted);font-size:.8rem;font-weight:760}
      #${rootId} .tour-modal-close{display:inline-flex;align-items:center;gap:.35rem;border:1px solid var(--tour-border);border-radius:.5rem;background:#fff;padding:.45rem .65rem;color:var(--tour-text);font-weight:600;cursor:pointer}
      #${rootId} .tour-form{display:grid;gap:1rem;padding:1rem}
      #${rootId} .tour-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}
      #${rootId} .tour-form-wide{grid-column:1/-1}
      .dark #${rootId}{--tour-border:var(--color-surface-700,#334155);--tour-muted:var(--color-secondary-400,#94a3b8);--tour-text:#fff}
      .dark #${rootId} .tour-card,.dark #${rootId} .tour-stat,.dark #${rootId} .tour-toolbar,.dark #${rootId} .tour-button,.dark #${rootId} input,.dark #${rootId} select,.dark #${rootId} textarea,.dark #${rootId} .tour-row,.dark #${rootId} .visit-actions button,.dark #${rootId} .tour-modal,.dark #${rootId} .tour-modal>header,.dark #${rootId} .tour-modal-close{background:var(--color-surface-900,#0f172a);border-color:var(--tour-border)}
      .dark #${rootId} .visit-table th{background:var(--color-surface-800,#1e293b)}
      @media (max-width:1180px){#${rootId} .tour-layout{grid-template-columns:1fr}#${rootId} .tour-toolbar,#${rootId} .tour-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
      @media (max-width:720px){#${rootId} .tour-header{align-items:stretch;flex-direction:column}#${rootId} .tour-actions .tour-button{flex:1}#${rootId} .tour-title h1{font-size:1.55rem}#${rootId} .tour-toolbar,#${rootId} .tour-stats,#${rootId} .tour-report,#${rootId} .tour-form-grid{grid-template-columns:1fr}#${rootId} .tour-calendar{font-size:.86rem}#${rootId} .tour-day{min-height:5.8rem;padding:.38rem}#${rootId} .tour-row{grid-template-columns:3.2rem minmax(0,1fr)}#${rootId} .tour-row .tour-badge{grid-column:2;justify-self:start}#${rootId} .visit-table{min-width:58rem}#${rootId} .tour-modal-backdrop{align-items:end;padding:0}#${rootId} .tour-modal{max-height:94vh;border-radius:.8rem .8rem 0 0}}
    `;
    document.head.appendChild(style);
  }

  function watchRouteChanges() {
    if (window.__crmSalesToursRouteWatcher) return;
    window.__crmSalesToursRouteWatcher = true;
    window.addEventListener("popstate", () => window.dispatchEvent(new Event(routeEvent)));
    window.addEventListener("crm:navigation", () => scheduleMount(true));
    window.addEventListener("crm:route-changed", () => scheduleMount(true));
    window.addEventListener(routeEvent, () => scheduleMount(true));
  }

  watchRouteChanges();
  document.addEventListener("DOMContentLoaded", () => {
    scheduleMount(true);
  });
  window.addEventListener("crm:active-site-changed", (event) => {
    state.selectedSiteId = numberOrNull(event.detail && event.detail.siteId) || numberOrNull(localStorage.getItem(storageSiteKey));
    if (isRoute() && root && mountedRoots.has(root)) load();
  });
  scheduleMount(true);
})();

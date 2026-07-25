(function () {
  const rootId = "crm-dashboard-module";
  const styleId = "crm-dashboard-style";
  const activeSiteStorageKey = "crm:active-site-id";
  const activeSiteEvent = "crm:active-site-changed";
  const routeChangeEvent = "crm:dashboard-route-changed";
  const routePaths = new Set(["/", "/dashboard/crm"]);

  const state = {
    loading: false,
    data: null,
    error: "",
    siteId: null,
    mounted: false,
  };

  let mountTimer = null;
  let loadSequence = 0;

  function isHome() {
    const path = window.location.pathname.replace(/\/+$/, "") || "/";
    return routePaths.has(path);
  }

  function numberOrNull(value) {
    const number = Number(value || 0);
    return Number.isFinite(number) && number > 0 ? number : null;
  }

  function activeSiteId() {
    if (window.CRM_ACTIVE_SITE && typeof window.CRM_ACTIVE_SITE.getSiteId === "function") {
      return numberOrNull(window.CRM_ACTIVE_SITE.getSiteId());
    }

    try {
      return numberOrNull(window.localStorage.getItem(activeSiteStorageKey));
    } catch (error) {
      return null;
    }
  }

  function ensureStyle() {
    if (document.getElementById(styleId)) return;

    const style = document.createElement("style");
    style.id = styleId;
    style.textContent = `
      #${rootId}{--dash-primary:rgb(var(--theme-primary,149 0 46));--dash-border:var(--color-surface-200,#e2e8f0);--dash-muted:var(--color-secondary-500,#64748b);--dash-text:var(--color-secondary-900,#0f172a);display:grid;gap:1rem}
      #${rootId} *{box-sizing:border-box}
      #${rootId} svg{width:1.1rem;height:1.1rem;flex:none;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} .dash-top{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem}
      #${rootId} .dash-title h1{margin:0;color:var(--dash-text);font-size:1.8rem;line-height:1.1;font-weight:900;letter-spacing:0}
      #${rootId} .dash-title p{margin:.35rem 0 0;color:var(--dash-muted);font-size:.92rem;font-weight:650}
      #${rootId} .dash-actions{display:flex;gap:.55rem;flex-wrap:wrap;justify-content:flex-end}
      #${rootId} .dash-button{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:2.35rem;border:1px solid var(--dash-border);border-radius:.5rem;background:#fff;padding:.55rem .85rem;color:var(--dash-text);font-size:.82rem;font-weight:850;text-decoration:none;box-shadow:0 10px 24px rgba(15,23,42,.04)}
      #${rootId} .dash-button-primary{border-color:transparent;background:var(--dash-primary);color:#fff}
      #${rootId} .dash-grid{display:grid;gap:.85rem}
      #${rootId} .dash-stats{grid-template-columns:repeat(4,minmax(0,1fr))}
      #${rootId} .dash-main{grid-template-columns:minmax(0,1.15fr) minmax(19rem,.85fr);align-items:start}
      #${rootId} .dash-bottom{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
      #${rootId} .dash-card{min-width:0;border:1px solid var(--dash-border);border-radius:.5rem;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.05)}
      #${rootId} .dash-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:.8rem;border-bottom:1px solid var(--dash-border);padding:.95rem 1rem}
      #${rootId} .dash-card-title{margin:0;color:var(--dash-text);font-size:1rem;font-weight:900;letter-spacing:0}
      #${rootId} .dash-card-subtitle{margin:.22rem 0 0;color:var(--dash-muted);font-size:.78rem;font-weight:700}
      #${rootId} .dash-card-body{padding:1rem}
      #${rootId} .dash-stat{display:grid;grid-template-columns:2.6rem minmax(0,1fr);align-items:center;gap:.75rem;padding:.9rem}
      #${rootId} .dash-stat-icon{display:grid;place-items:center;width:2.6rem;height:2.6rem;border-radius:.5rem;background:color-mix(in srgb,var(--stat-color,#95002e) 14%,white);color:var(--stat-color,#95002e)}
      #${rootId} .dash-stat-icon svg,#${rootId} .dash-mini-icon svg{width:1.2rem;height:1.2rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} .dash-stat span{display:block;color:var(--dash-muted);font-size:.72rem;font-weight:900;text-transform:uppercase}
      #${rootId} .dash-stat strong{display:block;margin:.2rem 0;color:var(--dash-text);font-size:1.45rem;font-weight:950;line-height:1.05;letter-spacing:0}
      #${rootId} .dash-stat small{display:block;color:var(--color-secondary-400,#94a3b8);font-size:.72rem;font-weight:750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #${rootId} .dash-chart{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));align-items:end;gap:.55rem;min-height:12rem;padding:.2rem .15rem 0}
      #${rootId} .dash-bar{display:grid;align-items:end;gap:.45rem;min-width:0;height:11rem}
      #${rootId} .dash-bar-track{display:flex;align-items:flex-end;justify-content:center;height:9rem;border-radius:.5rem;background:linear-gradient(180deg,#f8fafc,#f1f5f9);overflow:hidden}
      #${rootId} .dash-bar-fill{width:58%;min-height:.35rem;border-radius:.45rem .45rem 0 0;background:var(--dash-primary);box-shadow:0 10px 22px rgba(149,0,46,.24)}
      #${rootId} .dash-bar-label{text-align:center;color:var(--dash-muted);font-size:.72rem;font-weight:850;white-space:nowrap}
      #${rootId} .dash-bar-value{text-align:center;color:var(--dash-text);font-size:.8rem;font-weight:950}
      #${rootId} .dash-list{display:grid;gap:.55rem}
      #${rootId} .dash-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:.8rem;border:1px solid var(--dash-border);border-radius:.5rem;padding:.75rem .8rem;background:#fff}
      #${rootId} .dash-row-main{min-width:0}
      #${rootId} .dash-row-title{display:flex;align-items:center;gap:.45rem;min-width:0;color:var(--dash-text);font-size:.9rem;font-weight:900}
      #${rootId} .dash-row-icon{display:grid;place-items:center;width:2rem;height:2rem;border-radius:.45rem;background:color-mix(in srgb,var(--dash-primary) 10%,white);color:var(--dash-primary)}
      #${rootId} .dash-row-title span:last-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #${rootId} .dash-row-meta{margin-top:.16rem;color:var(--dash-muted);font-size:.76rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #${rootId} .dash-badge{display:inline-flex;align-items:center;justify-content:center;min-height:1.75rem;border-radius:999px;background:#f1f5f9;padding:.25rem .65rem;color:var(--dash-text);font-size:.72rem;font-weight:900;white-space:nowrap}
      #${rootId} .dash-dot{width:.6rem;height:.6rem;border-radius:999px;background:var(--dot-color,#95002e);box-shadow:0 0 0 3px color-mix(in srgb,var(--dot-color,#95002e) 13%,white)}
      #${rootId} .dash-alert{display:grid;grid-template-columns:2.35rem minmax(0,1fr) auto;align-items:center;gap:.7rem;border:1px solid var(--dash-border);border-radius:.5rem;padding:.75rem .8rem;background:#fff;color:inherit;text-decoration:none}
      #${rootId} .dash-alert-icon{display:grid;place-items:center;width:2.35rem;height:2.35rem;border-radius:.5rem;background:color-mix(in srgb,var(--alert-color,#95002e) 13%,white);color:var(--alert-color,#95002e)}
      #${rootId} .dash-alert strong{display:block;color:var(--dash-text);font-size:.88rem;font-weight:900}
      #${rootId} .dash-alert span{display:block;margin-top:.12rem;color:var(--dash-muted);font-size:.73rem;font-weight:700}
      #${rootId} .dash-alert-value{color:var(--dash-text);font-size:1.1rem;font-weight:950}
      #${rootId} .dash-empty{display:grid;place-items:center;min-height:7rem;border:1px dashed var(--dash-border);border-radius:.5rem;color:var(--dash-muted);font-size:.82rem;font-weight:800;text-align:center;padding:1rem}
      #${rootId} .dash-notifications{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.65rem}
      #${rootId} .dash-note{border:1px solid var(--dash-border);border-radius:.5rem;padding:.8rem;background:#fff}
      #${rootId} .dash-note span{display:block;color:var(--dash-muted);font-size:.72rem;font-weight:900;text-transform:uppercase}
      #${rootId} .dash-note strong{display:block;margin-top:.25rem;color:var(--dash-text);font-size:1.35rem;font-weight:950;line-height:1}
      #${rootId} .dash-loading{border:1px solid var(--dash-border);border-radius:.5rem;background:#fff;padding:1rem;color:var(--dash-muted);font-weight:850}
      .dark #${rootId}{--dash-border:var(--color-surface-700,#334155);--dash-muted:var(--color-secondary-400,#94a3b8);--dash-text:#fff}
      .dark #${rootId} .dash-card,.dark #${rootId} .dash-row,.dark #${rootId} .dash-alert,.dark #${rootId} .dash-note,.dark #${rootId} .dash-button,.dark #${rootId} .dash-loading{background:var(--color-surface-900,#0f172a);border-color:var(--dash-border)}
      .dark #${rootId} .dash-bar-track{background:linear-gradient(180deg,#1e293b,#0f172a)}
      @media (max-width:1180px){#${rootId} .dash-stats{grid-template-columns:repeat(2,minmax(0,1fr))}#${rootId} .dash-main,#${rootId} .dash-bottom{grid-template-columns:1fr}}
      @media (max-width:720px){#${rootId}{gap:.85rem}#${rootId} .dash-top{align-items:stretch;flex-direction:column}#${rootId} .dash-actions{justify-content:stretch}#${rootId} .dash-button{flex:1}#${rootId} .dash-title h1{font-size:1.55rem}#${rootId} .dash-stats{grid-template-columns:1fr}#${rootId} .dash-card-header,#${rootId} .dash-card-body{padding:.85rem}#${rootId} .dash-chart{gap:.35rem}#${rootId} .dash-notifications{grid-template-columns:1fr}#${rootId} .dash-row{grid-template-columns:1fr}#${rootId} .dash-badge{justify-self:start}#${rootId} .dash-alert{grid-template-columns:2.35rem minmax(0,1fr)}#${rootId} .dash-alert-value{grid-column:2}}
    `;
    document.head.appendChild(style);
  }

  function mount() {
    if (!isHome()) return false;

    const root = document.getElementById(rootId);
    if (!root) return false;

    ensureStyle();
    if (!root.dataset.crmDashboardMounted) {
      root.dataset.crmDashboardMounted = "1";
      root.innerHTML = renderLoading();
    }

    state.mounted = true;
    return true;
  }

  function render() {
    if (!mount()) return;

    const root = document.getElementById(rootId);
    if (!root) return;

    if (state.loading && !state.data) {
      root.innerHTML = renderLoading();
      return;
    }

    if (state.error) {
      root.innerHTML = renderError(state.error);
      return;
    }

    root.innerHTML = renderDashboard(state.data);
  }

  async function load(options = {}) {
    if (!isHome()) return;
    if (state.loading && !options.force) return;

    const sequence = ++loadSequence;
    state.loading = true;
    state.error = "";
    state.siteId = activeSiteId();
    render();

    try {
      const url = new URL("/api/dashboard", window.location.origin);
      url.searchParams.set("action", "overview");
      if (state.siteId) url.searchParams.set("siteId", String(state.siteId));

      const response = await fetch(url.toString(), {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
      const data = await response.json();

      if (!response.ok || !data.ok) {
        throw new Error(data.error || "Dashboard indisponible");
      }

      if (sequence === loadSequence) {
        state.data = data;
      }
    } catch (error) {
      if (sequence === loadSequence) {
        state.error = error.message || "Dashboard indisponible";
      }
    } finally {
      if (sequence !== loadSequence) return;
      state.loading = false;
      render();
    }
  }

  function renderLoading() {
    return `
      <section class="dash-loading">
        Chargement du tableau de bord...
      </section>
    `;
  }

  function renderError(message) {
    return `
      <div class="dash-top">
        <div class="dash-title">
          <h1>Tableau de bord</h1>
          <p>Vue d'ensemble du HUB Martin Sols.</p>
        </div>
      </div>
      <section class="dash-empty">${esc(message)}</section>
    `;
  }

  function renderDashboard(data) {
    const site = selectedSite(data);
    const access = data.access || {};
    const stats = data.stats || {};
    const cards = [
      access.reservations ? statCard("Réservations", stats.reservationsToday || 0, "Aujourd'hui", "calendar", "#2563eb") : "",
      access.cashControl ? statCard("CA du mois", money(stats.monthlyRevenue || 0), monthName(), "euro", "#16a34a") : "",
      access.leaves ? statCard("Congés", stats.pendingLeaves || 0, "Demande(s) en attente", "user", "#f59e0b") : "",
      access.equipmentRentals ? statCard("Matériel dispo", `${stats.equipmentAvailable || 0}/${stats.equipmentTotal || 0}`, "Disponible maintenant", "box", "#95002e") : "",
    ].filter(Boolean).join("");

    return `
      <div class="dash-top">
        <div class="dash-title">
          <h1>Tableau de bord</h1>
          <p>${esc(site ? site.name : "Tous les sites")} · Synthèse du jour</p>
        </div>
        <div class="dash-actions">
          <a class="dash-button dash-button-primary" href="/locations-materiel">${icon("plus")}Location matériel</a>
          <a class="dash-button" href="/controle-caisse">${icon("receipt")}Contrôle caisse</a>
        </div>
      </div>

      <section class="dash-grid dash-stats">
        ${cards || empty("Aucun widget disponible avec vos droits actuels.")}
      </section>

      <section class="dash-grid dash-main">
        <article class="dash-card">
          <header class="dash-card-header">
            <div>
              <h2 class="dash-card-title">Évolution des réservations</h2>
              <p class="dash-card-subtitle">7 derniers jours</p>
            </div>
            <span class="dash-badge">${sumTrend(data.reservationTrend || [])} total</span>
          </header>
          <div class="dash-card-body">
            ${renderTrend(data.reservationTrend || [])}
          </div>
        </article>

        <article class="dash-card">
          <header class="dash-card-header">
            <div>
              <h2 class="dash-card-title">Alertes</h2>
              <p class="dash-card-subtitle">Points à surveiller</p>
            </div>
          </header>
          <div class="dash-card-body">
            ${renderAlerts(data.alerts || [])}
          </div>
        </article>
      </section>

      <section class="dash-grid dash-bottom">
        <article class="dash-card">
          <header class="dash-card-header">
            <div>
              <h2 class="dash-card-title">Dernières réservations</h2>
              <p class="dash-card-subtitle">Véhicules</p>
            </div>
            <a class="dash-button" href="/reservations">Voir</a>
          </header>
          <div class="dash-card-body">
            ${renderReservations(data.latestReservations || [])}
          </div>
        </article>

        <article class="dash-card">
          <header class="dash-card-header">
            <div>
              <h2 class="dash-card-title">Congés en cours</h2>
              <p class="dash-card-subtitle">Cette semaine</p>
            </div>
            <a class="dash-button" href="/conges">Voir</a>
          </header>
          <div class="dash-card-body">
            ${renderLeaves(data.currentLeaves || [])}
          </div>
        </article>
      </section>

      <section class="dash-card">
        <header class="dash-card-header">
          <div>
            <h2 class="dash-card-title">Notifications</h2>
            <p class="dash-card-subtitle">Demandes en attente et contrôles</p>
          </div>
          <span class="dash-badge">${esc((data.notifications && data.notifications.total) || 0)} total</span>
        </header>
        <div class="dash-card-body">
          ${renderNotifications(data.notifications || {})}
        </div>
      </section>
    `;
  }

  function statCard(label, value, detail, iconName, color) {
    return `
      <article class="dash-card dash-stat" style="--stat-color:${esc(color)}">
        <div class="dash-stat-icon">${icon(iconName)}</div>
        <div>
          <span>${esc(label)}</span>
          <strong>${esc(value)}</strong>
          <small>${esc(detail)}</small>
        </div>
      </article>
    `;
  }

  function renderTrend(points) {
    if (!points.length) return empty("Aucune réservation sur la période.");

    const max = Math.max(1, ...points.map((point) => Number(point.total || 0)));
    return `
      <div class="dash-chart" aria-label="Réservations sur 7 jours">
        ${points.map((point) => {
          const total = Number(point.total || 0);
          const height = Math.max(6, Math.round((total / max) * 100));
          return `
            <div class="dash-bar">
              <div class="dash-bar-value">${esc(total)}</div>
              <div class="dash-bar-track">
                <div class="dash-bar-fill" style="height:${height}%"></div>
              </div>
              <div class="dash-bar-label">${esc(point.label || point.date || "")}</div>
            </div>
          `;
        }).join("")}
      </div>
    `;
  }

  function renderReservations(items) {
    if (!items.length) return empty("Aucune réservation récente.");

    return `<div class="dash-list">${items.map((item) => `
      <a class="dash-row" href="/reservations">
        <div class="dash-row-main">
          <div class="dash-row-title"><span class="dash-row-icon">${icon("truck")}</span><span>${esc(item.vehicle || item.title || "Réservation")}</span></div>
          <div class="dash-row-meta">${esc(dateTime(item.startAt))} · ${esc(item.user || "-")} · ${esc(item.site || "-")}</div>
        </div>
        <span class="dash-badge">${esc(item.status || "prévue")}</span>
      </a>
    `).join("")}</div>`;
  }

  function renderLeaves(items) {
    if (!items.length) return empty("Aucune absence cette semaine.");

    return `<div class="dash-list">${items.map((item) => `
      <a class="dash-row" href="/conges">
        <div class="dash-row-main">
          <div class="dash-row-title"><span class="dash-dot" style="--dot-color:${esc(item.color || "#95002e")}"></span><span>${esc(item.name || "Utilisateur")}</span></div>
          <div class="dash-row-meta">${esc(item.type || "Congé")} · ${esc(periodLabel(item))} · ${esc(item.status || "")}</div>
        </div>
        <span class="dash-badge">${esc(item.period || "")}</span>
      </a>
    `).join("")}</div>`;
  }

  function renderAlerts(items) {
    if (!items.length) return empty("Aucune alerte pour le moment.");

    return `<div class="dash-list">${items.map((item) => {
      const color = alertColor(item.type);
      return `
        <a class="dash-alert" href="${esc(item.href || "/")}" style="--alert-color:${color}">
          <div class="dash-alert-icon">${icon(item.type === "danger" ? "alert" : "bell")}</div>
          <div>
            <strong>${esc(item.label || "Alerte")}</strong>
            <span>${esc(item.detail || "")}</span>
          </div>
          <div class="dash-alert-value">${esc(item.value || 0)}</div>
        </a>
      `;
    }).join("")}</div>`;
  }

  function renderNotifications(notifications) {
    return `
      <div class="dash-notifications">
        ${notificationCard("Congés", notifications.pendingLeaves || 0, "En attente")}
        ${notificationCard("Chèques", notifications.draftCheckRemittances || 0, "Brouillons")}
        ${notificationCard("Envois", notifications.failedNotifications || 0, "Échecs")}
      </div>
    `;
  }

  function notificationCard(label, value, detail) {
    return `
      <div class="dash-note">
        <span>${esc(label)}</span>
        <strong>${esc(value)}</strong>
        <small>${esc(detail)}</small>
      </div>
    `;
  }

  function empty(message) {
    return `<div class="dash-empty">${esc(message)}</div>`;
  }

  function selectedSite(data) {
    const siteId = Number(data && data.selectedSiteId);
    return (data.sites || []).find((site) => Number(site.id) === siteId) || null;
  }

  function sumTrend(points) {
    return points.reduce((sum, point) => sum + Number(point.total || 0), 0);
  }

  function money(value) {
    return new Intl.NumberFormat("fr-FR", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(Number(value || 0));
  }

  function monthName() {
    return new Intl.DateTimeFormat("fr-FR", { month: "long", year: "numeric" }).format(new Date());
  }

  function dateTime(value) {
    if (!value) return "-";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }).format(date);
  }

  function periodLabel(item) {
    if (!item.startDate || !item.endDate || item.startDate === item.endDate) {
      return item.startDate || "-";
    }

    return `${item.startDate} → ${item.endDate}`;
  }

  function alertColor(type) {
    return {
      danger: "#dc2626",
      warning: "#d97706",
      info: "#2563eb",
    }[type] || "#95002e";
  }

  function icon(name) {
    const paths = {
      calendar: '<path d="M8 2v4M16 2v4"></path><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M3 10h18"></path>',
      euro: '<path d="M15 6.5A6 6 0 1 0 15 17.5"></path><path d="M4 10h10M4 14h9"></path>',
      user: '<path d="M20 21a8 8 0 0 0-16 0"></path><circle cx="12" cy="7" r="4"></circle>',
      box: '<path d="m21 8-9-5-9 5 9 5 9-5Z"></path><path d="M3 8v8l9 5 9-5V8M12 13v8"></path>',
      plus: '<path d="M12 5v14M5 12h14"></path>',
      receipt: '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2Z"></path><path d="M9 7h6M9 11h6M9 15h4"></path>',
      truck: '<path d="M3 7h11v8H3z"></path><path d="M14 10h4l3 3v2h-7z"></path><circle cx="7" cy="18" r="2"></circle><circle cx="17" cy="18" r="2"></circle>',
      alert: '<path d="M12 9v4M12 17h.01"></path><path d="m10.3 3.9-8.1 14A2 2 0 0 0 3.9 21h16.2a2 2 0 0 0 1.7-3.1l-8.1-14a2 2 0 0 0-3.4 0Z"></path>',
      bell: '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path>',
    };

    return `<svg viewBox="0 0 24 24" aria-hidden="true">${paths[name] || paths.box}</svg>`;
  }

  function esc(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function shouldReload() {
    const nextSiteId = activeSiteId();

    return (
      !state.data ||
      Boolean(state.error) ||
      Number(state.siteId || 0) !== Number(nextSiteId || 0)
    );
  }

  function scheduleMount(options = {}) {
    if (!isHome()) return;

    if (mountTimer) {
      window.clearInterval(mountTimer);
      mountTimer = null;
    }

    let attempts = 0;
    mountTimer = window.setInterval(() => {
      attempts += 1;
      if (mount() || attempts > 80) {
        window.clearInterval(mountTimer);
        mountTimer = null;

        if (!isHome()) return;

        if (options.force || shouldReload()) {
          if (Number(state.siteId || 0) !== Number(activeSiteId() || 0)) {
            state.data = null;
          }

          load({ force: options.force });
          return;
        }

        render();
      }
    }, 100);
  }

  function handleRouteChange() {
    if (!isHome()) return;
    window.setTimeout(() => scheduleMount({ force: shouldReload() }), 0);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", scheduleMount, { once: true });
  } else {
    scheduleMount();
  }
  window.addEventListener("load", scheduleMount);
  window.addEventListener("popstate", handleRouteChange);
  window.addEventListener("crm:navigation", handleRouteChange);
  window.addEventListener("crm:route-changed", handleRouteChange);
  window.addEventListener(routeChangeEvent, handleRouteChange);
  window.addEventListener(activeSiteEvent, () => {
    if (!isHome()) return;
    const nextSiteId = activeSiteId();
    if (Number(state.siteId || 0) !== Number(nextSiteId || 0)) {
      state.siteId = nextSiteId;
      state.data = null;
      load({ force: true });
    }
  });
})();

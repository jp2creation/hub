(() => {
  const rootId = "crm-teams-module";
  const styleId = "crm-teams-style";
  const routePath = "/equipes";
  const activeSiteStorageKey = "crm:active-site-id";
  const activeSiteEvent = "crm:active-site-changed";
  const apiUrls = ["/api/equipes"];

  let root = null;
  let mountedPath = "";
  let bootScheduled = false;

  const state = {
    data: null,
    loading: false,
    error: "",
    query: "",
  };

  const esc = (value) => String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");

  function isRoute() {
    return window.location.pathname.replace(/\/+$/, "") === routePath;
  }

  function syncRouteClass() {
    const active = isRoute();
    document.documentElement.classList.toggle("crm-teams-route", active);
    document.body?.classList.toggle("crm-teams-route", active);
  }

  function numberOrNull(value) {
    const number = Number(value || 0);
    return Number.isFinite(number) && number > 0 ? number : null;
  }

  function activeSiteId() {
    const fromApi = numberOrNull(window.CRM_ACTIVE_SITE?.getSiteId?.());
    if (fromApi) return fromApi;

    try {
      const stored = numberOrNull(window.localStorage.getItem(activeSiteStorageKey));
      if (stored) return stored;
    } catch (error) {
      // Server fallback.
    }

    return numberOrNull(state.data?.selectedSiteId) || "";
  }

  function activeSite() {
    const siteId = numberOrNull(state.data?.selectedSiteId || activeSiteId());
    return (state.data?.sites || []).find((site) => Number(site.id) === siteId) || null;
  }

  async function request(action) {
    const params = new URLSearchParams({ action });
    const siteId = activeSiteId();
    if (siteId) params.set("siteId", String(siteId));

    let lastError = null;

    for (const url of apiUrls) {
      try {
        const response = await fetch(`${url}?${params.toString()}`, {
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok || payload.ok === false) {
          throw new Error(payload.error || "Action impossible");
        }

        return payload;
      } catch (error) {
        lastError = error;
      }
    }

    throw lastError || new Error("API equipe indisponible");
  }

  async function load() {
    if (!isRoute()) return;

    state.loading = true;
    state.error = "";
    render();

    try {
      state.data = await request("bootstrap");
    } catch (error) {
      state.error = error instanceof Error ? error.message : "Impossible de charger l'équipe.";
      state.data = null;
    } finally {
      state.loading = false;
      render();
    }
  }

  function ensureRoot() {
    syncRouteClass();
    if (!isRoute()) return false;

    let target = document.getElementById(rootId);
    if (!target) {
      const container = document.querySelector("main .layout-container.layout-page")
        || document.querySelector("main")
        || document.body;

      if (!container) return false;

      target = document.createElement("section");
      target.id = rootId;
      target.textContent = "Chargement de l'équipe...";
      container.appendChild(target);
    }

    root = target;
    ensureStyles();
    return true;
  }

  function selectedSiteId() {
    return numberOrNull(state.data?.selectedSiteId || activeSiteId());
  }

  function members() {
    const query = normalize(state.query);

    return (state.data?.members || []).filter((member) => {
      if (!query) return true;

      return [
        member.firstName,
        member.lastName,
        member.name,
        member.phone,
        member.email,
      ].some((value) => normalize(value).includes(query));
    });
  }

  function normalize(value) {
    return String(value || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase();
  }

  function initials(member) {
    const parts = [member.firstName, member.lastName]
      .join(" ")
      .trim()
      .split(/\s+/)
      .filter(Boolean);

    if (!parts.length) return "MS";
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();

    return `${parts[0][0] || ""}${parts[parts.length - 1][0] || ""}`.toUpperCase();
  }

  function canViewPresence() {
    return Boolean(state.data?.user?.canViewPresence);
  }

  function render() {
    if (!root || !isRoute()) return;
    ensureStyles();
    mountedPath = window.location.pathname;

    if (state.loading && !state.data) {
      root.innerHTML = `<div class="teams-loading">Chargement de l'équipe...</div>`;
      return;
    }

    if (state.error) {
      root.innerHTML = `<div class="teams-empty">${esc(state.error)}</div>`;
      return;
    }

    if (!state.data) {
      root.innerHTML = `<div class="teams-loading">Chargement de l'équipe...</div>`;
      return;
    }

    const site = activeSite();
    const currentMembers = members();
    const membersWithPhone = currentMembers.filter((member) => member.phone).length;
    const membersWithEmail = currentMembers.filter((member) => member.email).length;

    root.innerHTML = `
      <div class="teams-page">
        <header class="teams-header">
          <div class="teams-title">
            <span>Applications HUB</span>
            <h1>Équipe</h1>
            <p>${esc(site?.name || "Site actif")}</p>
          </div>
          <label class="teams-search">
            ${icon("search")}
            <input type="search" value="${esc(state.query)}" placeholder="Rechercher un membre" data-teams-search />
          </label>
        </header>

        <section class="teams-stats" aria-label="Synthèse équipe">
          ${statCard("Membres", currentMembers.length, "users", "#a50034")}
          ${statCard("Sites visibles", state.data.sites.length, "building", "#2563eb")}
          ${statCard("Téléphones", membersWithPhone, "phone", "#16a34a")}
          ${statCard("E-mails", membersWithEmail, "mail", "#7c3aed")}
        </section>

        <nav class="teams-sites" aria-label="Sites">
          ${state.data.sites.map((siteItem) => renderSiteButton(siteItem)).join("")}
        </nav>

        <section class="teams-card">
          <div class="teams-card-head">
            <div>
              <h2>${esc(site?.name || "Site actif")}</h2>
              <p>${currentMembers.length} membre${currentMembers.length > 1 ? "s" : ""}</p>
            </div>
          </div>
          ${currentMembers.length ? renderMembers(currentMembers) : renderEmptyMembers()}
        </section>
      </div>
    `;

    bind();
  }

  function statCard(label, value, iconName, color) {
    return `
      <div class="teams-stat" style="--stat-color:${esc(color)}">
        <span class="teams-stat-icon">${icon(iconName)}</span>
        <span>
          <small>${esc(label)}</small>
          <strong>${esc(value)}</strong>
        </span>
      </div>
    `;
  }

  function renderSiteButton(site) {
    const active = Number(site.id) === selectedSiteId();

    return `
      <button class="teams-site ${active ? "is-active" : ""}" type="button" data-site-id="${esc(site.id)}">
        <span>${esc(site.name)}</span>
        <small>${esc(site.membersCount)} membre${Number(site.membersCount) > 1 ? "s" : ""}</small>
      </button>
    `;
  }

  function renderMembers(list) {
    return `
      <div class="teams-table-wrap">
        <table class="teams-table">
          <thead>
            <tr>
              <th>Membre</th>
              ${canViewPresence() ? "<th>Statut</th>" : ""}
              <th>Prénom</th>
              <th>Nom</th>
              <th>Téléphone</th>
              <th>Mail</th>
            </tr>
          </thead>
          <tbody>
            ${list.map((member) => `
              <tr>
                <td>
                  <div class="teams-member">
                    <span class="teams-avatar">${member.photoUrl ? `<img src="${esc(member.photoUrl)}" alt="" onerror="this.remove()" />` : ""}<b>${esc(initials(member))}</b></span>
                    <span>
                      <strong>${esc(member.name || [member.firstName, member.lastName].join(" "))}</strong>
                    </span>
                  </div>
                </td>
                ${canViewPresence() ? `<td>${presencePill(member)}</td>` : ""}
                <td>${cell(member.firstName)}</td>
                <td>${cell(member.lastName)}</td>
                <td>${member.phone ? `<a href="tel:${esc(phoneHref(member.phone))}">${esc(member.phone)}</a>` : `<span class="teams-muted">Non renseigné</span>`}</td>
                <td>${member.email ? `<a href="mailto:${esc(member.email)}">${esc(member.email)}</a>` : `<span class="teams-muted">Non renseigné</span>`}</td>
              </tr>
            `).join("")}
          </tbody>
        </table>
      </div>
      <div class="teams-mobile-list" aria-label="Membres">
        ${list.map((member) => renderMemberCard(member)).join("")}
      </div>
    `;
  }

  function renderMemberCard(member) {
    const fullName = member.name || [member.firstName, member.lastName].filter(Boolean).join(" ");

    return `
      <article class="teams-person-card">
        <div class="teams-person-main">
          <span class="teams-avatar">${member.photoUrl ? `<img src="${esc(member.photoUrl)}" alt="" onerror="this.remove()" />` : ""}<b>${esc(initials(member))}</b></span>
          <span class="teams-person-identity">
            <strong>${esc(fullName || "Membre CRM")}</strong>
            ${canViewPresence() ? `<span class="teams-person-meta">${presencePill(member)}</span>` : ""}
          </span>
        </div>
        <dl class="teams-person-details">
          <div>
            <dt>Prénom</dt>
            <dd>${cell(member.firstName)}</dd>
          </div>
          <div>
            <dt>Nom</dt>
            <dd>${cell(member.lastName)}</dd>
          </div>
          <div>
            <dt>Téléphone</dt>
            <dd>${member.phone ? `<a href="tel:${esc(phoneHref(member.phone))}">${esc(member.phone)}</a>` : `<span class="teams-muted">Non renseigné</span>`}</dd>
          </div>
          <div>
            <dt>E-mail</dt>
            <dd>${member.email ? `<a href="mailto:${esc(member.email)}">${esc(member.email)}</a>` : `<span class="teams-muted">Non renseigné</span>`}</dd>
          </div>
        </dl>
      </article>
    `;
  }

  function presencePill(member) {
    if (!canViewPresence()) return "";

    const presence = member.presence || {};
    const online = Boolean(presence.isOnline);
    const label = presence.label || (online ? "En ligne" : "Hors ligne");

    return `
      <span class="teams-presence ${online ? "is-online" : "is-offline"}" title="${esc(label)}">
        <i aria-hidden="true"></i>
        ${esc(label)}
      </span>
    `;
  }

  function renderEmptyMembers() {
    return `<div class="teams-empty">Aucun membre pour ce site.</div>`;
  }

  function cell(value) {
    const text = String(value || "").trim();
    return text ? esc(text) : `<span class="teams-muted">Non renseigné</span>`;
  }

  function phoneHref(value) {
    return String(value || "").replace(/[^\d+]/g, "");
  }

  function bind() {
    root.querySelector("[data-teams-search]")?.addEventListener("input", (event) => {
      state.query = event.target.value;
      render();
      const input = root.querySelector("[data-teams-search]");
      input?.focus();
      input?.setSelectionRange?.(state.query.length, state.query.length);
    });

    root.querySelectorAll("[data-site-id]").forEach((button) => {
      button.addEventListener("click", () => {
        const siteId = numberOrNull(button.getAttribute("data-site-id"));
        if (!siteId || siteId === selectedSiteId()) return;

        if (window.CRM_ACTIVE_SITE?.setSiteId) {
          window.CRM_ACTIVE_SITE.setSiteId(siteId);
        } else {
          try {
            window.localStorage.setItem(activeSiteStorageKey, String(siteId));
          } catch (error) {
            // Reloading through the API will still use the explicit site id when possible.
          }

          window.dispatchEvent(new CustomEvent(activeSiteEvent, { detail: { siteId } }));
        }
      });
    });
  }

  function ensureStyles() {
    if (document.getElementById(styleId)) return;

    const style = document.createElement("style");
    style.id = styleId;
    style.textContent = `
      html.crm-teams-route,
      body.crm-teams-route{overflow-x:hidden}
      html.crm-teams-route main{max-width:100%;min-width:0;overflow-x:hidden}
      html.crm-teams-route .layout-container.layout-page{width:100%;max-width:100%;min-width:0;overflow-x:hidden}
      #${rootId}{--teams-primary:rgb(var(--theme-primary,149 0 46));--teams-border:var(--color-surface-200,#e2e8f0);--teams-muted:var(--color-secondary-500,#64748b);--teams-text:var(--color-secondary-900,#0f172a);display:block;min-width:0;color:var(--teams-text)}
      #${rootId} *{box-sizing:border-box}
      #${rootId} svg{width:1.05rem;height:1.05rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} a{color:var(--teams-primary);font-weight:850;text-decoration:none}
      #${rootId} a:hover{text-decoration:underline}
      #${rootId} .teams-page{display:grid;gap:1rem;min-width:0}
      #${rootId} .teams-header{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem}
      #${rootId} .teams-title span{display:block;color:var(--teams-primary);font-size:.72rem;font-weight:950;text-transform:uppercase;letter-spacing:.04em}
      #${rootId} .teams-title h1{margin:.15rem 0 0;color:var(--teams-text);font-size:1.85rem;line-height:1.05;font-weight:950;letter-spacing:0}
      #${rootId} .teams-title p{margin:.3rem 0 0;color:var(--teams-muted);font-size:.88rem;font-weight:750}
      #${rootId} .teams-search{display:grid;grid-template-columns:1.1rem minmax(0,1fr);align-items:center;gap:.55rem;width:min(100%,22rem);min-height:2.55rem;border:1px solid var(--teams-border);border-radius:.5rem;background:#fff;padding:0 .8rem;color:var(--teams-muted);box-shadow:0 10px 24px rgba(15,23,42,.04)}
      #${rootId} .teams-search input{width:100%;min-width:0;border:0;background:transparent;color:var(--teams-text);font:inherit;font-size:.9rem;font-weight:750;outline:none}
      #${rootId} .teams-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,13.5rem),1fr));gap:.85rem;min-width:0}
      #${rootId} .teams-stat{display:grid;grid-template-columns:2.55rem minmax(0,1fr);gap:.75rem;align-items:center;min-width:0;border:1px solid var(--teams-border);border-radius:.5rem;background:#fff;padding:.85rem;box-shadow:0 12px 28px rgba(15,23,42,.05)}
      #${rootId} .teams-stat-icon{display:grid;place-items:center;width:2.55rem;height:2.55rem;border-radius:.5rem;background:color-mix(in srgb,var(--stat-color) 14%,white);color:var(--stat-color)}
      #${rootId} .teams-stat small{display:block;color:var(--teams-muted);font-size:.72rem;font-weight:950;text-transform:uppercase}
      #${rootId} .teams-stat strong{display:block;margin:.15rem 0 0;color:var(--teams-text);font-size:1.3rem;font-weight:950;line-height:1.05}
      #${rootId} .teams-sites{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,8.75rem),1fr));gap:.55rem;min-width:0;overflow:visible;padding:.05rem .05rem .2rem}
      #${rootId} .teams-site{display:grid;gap:.12rem;width:100%;min-width:0;border:1px solid var(--teams-border);border-radius:.5rem;background:#fff;padding:.65rem .8rem;color:var(--teams-text);cursor:pointer;text-align:left;box-shadow:0 10px 24px rgba(15,23,42,.04)}
      #${rootId} .teams-site span{font-size:.88rem;font-weight:950;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #${rootId} .teams-site small{color:var(--teams-muted);font-size:.72rem;font-weight:800}
      #${rootId} .teams-site.is-active{border-color:transparent;background:var(--teams-primary);color:#fff;box-shadow:0 16px 30px rgba(149,0,46,.2)}
      #${rootId} .teams-site.is-active small{color:rgba(255,255,255,.78)}
      #${rootId} .teams-card{container-name:teams-card;container-type:inline-size;min-width:0;border:1px solid var(--teams-border);border-radius:.5rem;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.05);overflow:hidden}
      #${rootId} .teams-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--teams-border);padding:.95rem 1rem}
      #${rootId} .teams-card-head h2{margin:0;color:var(--teams-text);font-size:1.08rem;font-weight:950;letter-spacing:0}
      #${rootId} .teams-card-head p{margin:.18rem 0 0;color:var(--teams-muted);font-size:.78rem;font-weight:750}
      #${rootId} .teams-table-wrap{max-width:100%;overflow:auto;-webkit-overflow-scrolling:touch}
      #${rootId} .teams-table{width:100%;min-width:50rem;border-collapse:collapse}
      #${rootId} .teams-table th{background:#f8fafc;color:var(--teams-muted);font-size:.72rem;font-weight:950;text-align:left;text-transform:uppercase;padding:.82rem 1rem;white-space:nowrap}
      #${rootId} .teams-table td{border-top:1px solid var(--teams-border);padding:.8rem 1rem;color:var(--teams-text);font-size:.88rem;font-weight:750;vertical-align:middle}
      #${rootId} .teams-mobile-list{display:none;grid-template-columns:repeat(auto-fit,minmax(min(100%,18rem),1fr))}
      #${rootId} .teams-member{display:grid;grid-template-columns:2.6rem minmax(0,1fr);align-items:center;gap:.72rem;min-width:0}
      #${rootId} .teams-member strong{display:block;color:var(--teams-text);font-size:.92rem;font-weight:950;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #${rootId} .teams-member small{display:block;margin-top:.1rem;color:var(--teams-muted);font-size:.72rem;font-weight:800}
      #${rootId} .teams-person-card{display:grid;gap:.75rem;border-top:1px solid var(--teams-border);padding:.9rem 1rem}
      #${rootId} .teams-person-main{display:grid;grid-template-columns:2.8rem minmax(0,1fr);align-items:center;gap:.75rem}
      #${rootId} .teams-person-identity{min-width:0}
      #${rootId} .teams-person-identity strong{display:block;color:var(--teams-text);font-size:1rem;font-weight:950;line-height:1.15}
      #${rootId} .teams-person-meta{display:flex;flex-wrap:wrap;align-items:center;gap:.35rem;margin-top:.28rem}
      #${rootId} .teams-person-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.55rem;margin:0}
      #${rootId} .teams-person-details div{min-width:0;border:1px solid var(--teams-border);border-radius:.5rem;background:#f8fafc;padding:.55rem .65rem}
      #${rootId} .teams-person-details dt{margin:0 0 .18rem;color:var(--teams-muted);font-size:.66rem;font-weight:950;text-transform:uppercase}
      #${rootId} .teams-person-details dd{margin:0;min-width:0;color:var(--teams-text);font-size:.82rem;font-weight:850;line-height:1.25;overflow-wrap:anywhere}
      #${rootId} .teams-presence{display:inline-flex;align-items:center;gap:.36rem;min-height:1.65rem;border-radius:999px;padding:.18rem .58rem;font-size:.72rem;font-weight:950;white-space:nowrap}
      #${rootId} .teams-presence i{display:block;width:.48rem;height:.48rem;border-radius:999px;background:currentColor;box-shadow:0 0 0 3px color-mix(in srgb,currentColor 14%,transparent)}
      #${rootId} .teams-presence.is-online{background:rgba(22,163,74,.1);color:#16a34a}
      #${rootId} .teams-presence.is-offline{background:#f1f5f9;color:#64748b}
      #${rootId} .teams-avatar{position:relative;display:grid;place-items:center;width:2.6rem;height:2.6rem;overflow:hidden;border-radius:999px;background:color-mix(in srgb,var(--teams-primary) 12%,white);color:var(--teams-primary);font-size:.78rem;font-weight:950}
      #${rootId} .teams-avatar img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
      #${rootId} .teams-avatar b{position:relative;z-index:1}
      #${rootId} .teams-avatar img + b{display:none}
      #${rootId} .teams-muted{color:var(--teams-muted);font-weight:750}
      #${rootId} .teams-empty,.teams-loading{display:grid;place-items:center;min-height:9rem;border:1px dashed var(--teams-border);border-radius:.5rem;color:var(--teams-muted);font-size:.88rem;font-weight:850;text-align:center;padding:1rem}
      .dark #${rootId}{--teams-border:var(--color-surface-700,#334155);--teams-muted:var(--color-secondary-400,#94a3b8);--teams-text:#fff}
      .dark #${rootId} .teams-search,.dark #${rootId} .teams-stat,.dark #${rootId} .teams-site,.dark #${rootId} .teams-card{background:var(--color-surface-900,#0f172a);border-color:var(--teams-border)}
      .dark #${rootId} .teams-table th{background:var(--color-surface-800,#1e293b)}
      .dark #${rootId} .teams-person-details div{background:var(--color-surface-800,#1e293b)}
      @container teams-card (max-width:58rem){#${rootId} .teams-table-wrap{display:none}#${rootId} .teams-mobile-list{display:grid}}
      @media (max-width:1100px){#${rootId} .teams-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
      @media (max-width:720px){#${rootId} .teams-header{align-items:stretch;flex-direction:column}#${rootId} .teams-title h1{font-size:1.55rem}#${rootId} .teams-search{width:100%}#${rootId} .teams-stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem}#${rootId} .teams-stat{grid-template-columns:2.25rem minmax(0,1fr);padding:.7rem}#${rootId} .teams-stat-icon{width:2.25rem;height:2.25rem}#${rootId} .teams-sites{display:flex;overflow:auto;-webkit-overflow-scrolling:touch}#${rootId} .teams-site{flex:0 0 auto;width:auto;min-width:8.6rem}#${rootId} .teams-table-wrap{display:none}#${rootId} .teams-mobile-list{display:grid;grid-template-columns:1fr}}
      @media (max-width:390px){#${rootId} .teams-person-details{grid-template-columns:1fr}}
    `;
    document.head.appendChild(style);
  }

  function icon(name) {
    const icons = {
      users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
      building: '<path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-4"></path><path d="M9 9h1"></path><path d="M9 13h1"></path><path d="M9 17h1"></path>',
      phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.11 4.2 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.32 1.77.57 2.61a2 2 0 0 1-.45 2.11L8 9.67a16 16 0 0 0 6.33 6.33l1.23-1.23a2 2 0 0 1 2.11-.45c.84.25 1.71.45 2.61.57A2 2 0 0 1 22 16.92z"></path>',
      mail: '<path d="M4 4h16v16H4z"></path><path d="m22 6-10 7L2 6"></path>',
      search: '<circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path>',
    };

    return `<svg viewBox="0 0 24 24" aria-hidden="true">${icons[name] || icons.users}</svg>`;
  }

  function scheduleBoot(forceLoad = false) {
    syncRouteClass();
    if (bootScheduled) return;

    bootScheduled = true;
    window.setTimeout(() => {
      bootScheduled = false;
      if (!ensureRoot()) return;

      if (mountedPath !== window.location.pathname || forceLoad || !state.data) {
        load();
      } else {
        render();
      }
    }, 80);
  }

  function watchRouteChanges() {
    if (window.__crmTeamsRouteWatcher) return;
    window.__crmTeamsRouteWatcher = true;

    window.addEventListener("popstate", () => scheduleBoot(true));
    window.addEventListener("crm:navigation", () => scheduleBoot(true));
    window.addEventListener("crm:route-changed", () => scheduleBoot(true));
  }

  window.addEventListener(activeSiteEvent, () => {
    if (isRoute()) load();
  });
  document.addEventListener("DOMContentLoaded", () => scheduleBoot());
  document.addEventListener("click", () => scheduleBoot(), true);
  window.setTimeout(() => scheduleBoot(), 0);
  watchRouteChanges();
})();

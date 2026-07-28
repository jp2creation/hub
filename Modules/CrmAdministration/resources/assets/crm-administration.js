(() => {
  const api = "/api/administration";
  const rootId = "crm-administration-module";
  const styleId = "crm-administration-style";
  const routeEvents = ["popstate", "crm:navigation", "crm:route-changed"];

  const state = {
    data: null,
    loading: false,
    error: "",
    tab: new URLSearchParams(window.location.search).get("section") || "menu",
    saving: "",
  };

  let mountTimer = null;
  let loadSequence = 0;

  function isRoute() {
    const path = window.location.pathname.replace(/\/+$/, "") || "/";
    return path === "/administration" || path.startsWith("/administration/");
  }

  function esc(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
  }

  function canManageUsers() {
    const actor = state.data?.actor || {};
    const permissions = Array.isArray(actor.permissions) ? actor.permissions : [];

    return actor.role === "admin" || permissions.includes("platform.manage_users");
  }

  async function request(action, options = {}) {
    const url = new URL(api, window.location.origin);
    url.searchParams.set("action", action);

    const response = await fetch(url.toString(), {
      method: options.method || "GET",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": csrfToken(),
      },
      body: options.body ? JSON.stringify(options.body) : undefined,
    });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || "Administration indisponible");
    }

    return payload;
  }

  function ensureStyle() {
    if (document.getElementById(styleId)) return;

    const style = document.createElement("style");
    style.id = styleId;
    style.textContent = `
      #${rootId}{--admin-primary:rgb(var(--theme-primary));--admin-border:var(--color-surface-200,#e2e8f0);--admin-text:var(--color-secondary-900,#0f172a);--admin-muted:var(--color-secondary-500,#64748b);display:grid;gap:1rem}
      #${rootId} *{box-sizing:border-box}
      #${rootId} svg{width:1.05rem;height:1.05rem;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} .admin-top{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem}
      #${rootId} .admin-title h1{margin:0;color:var(--admin-text);font-size:1.8rem;line-height:1.08;font-weight:600;letter-spacing:0}
      #${rootId} .admin-title p{margin:.35rem 0 0;color:var(--admin-muted);font-size:.92rem;font-weight:700}
      #${rootId} .admin-tabs{display:flex;gap:.45rem;flex-wrap:wrap}
      #${rootId} .admin-tab{border:1px solid var(--admin-border);border-radius:.5rem;background:#fff;padding:.55rem .82rem;color:var(--admin-text);font-size:.8rem;font-weight:600;cursor:pointer}
      #${rootId} .admin-tab.is-active{border-color:transparent;background:var(--admin-primary);color:#fff}
      #${rootId} .admin-card{border:1px solid var(--admin-border);border-radius:.6rem;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.05)}
      #${rootId} .admin-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:.8rem;border-bottom:1px solid var(--admin-border);padding:.92rem 1rem}
      #${rootId} .admin-card-title{margin:0;color:var(--admin-text);font-size:1rem;font-weight:600}
      #${rootId} .admin-card-subtitle{margin:.18rem 0 0;color:var(--admin-muted);font-size:.78rem;font-weight:750}
      #${rootId} .admin-card-body{display:grid;gap:.85rem;padding:1rem}
      #${rootId} .admin-button{display:inline-flex;align-items:center;justify-content:center;gap:.42rem;min-height:2.35rem;border:1px solid var(--admin-border);border-radius:.5rem;background:#fff;padding:.55rem .85rem;color:var(--admin-text);font-size:.82rem;font-weight:600;cursor:pointer;text-decoration:none}
      #${rootId} .admin-button-primary{border-color:transparent;background:var(--admin-primary);color:#fff}
      #${rootId} .admin-button-danger{color:#b91c1c}
      #${rootId} .admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.8rem}
      #${rootId} .admin-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.8rem}
      #${rootId} .admin-row{display:grid;gap:.7rem;border:1px solid var(--admin-border);border-radius:.55rem;background:#fff;padding:.8rem}
      #${rootId} .admin-row-title{display:flex;align-items:center;justify-content:space-between;gap:.65rem}
      #${rootId} .admin-row-title strong{color:var(--admin-text);font-size:.94rem;font-weight:600}
      #${rootId} .admin-row-title span{color:var(--admin-muted);font-size:.72rem;font-weight:850}
      #${rootId} label{display:grid;gap:.28rem;color:var(--admin-muted);font-size:.7rem;font-weight:600;text-transform:uppercase}
      #${rootId} input,#${rootId} select,#${rootId} textarea{width:100%;border:1px solid var(--admin-border);border-radius:.48rem;background:#fff;padding:.62rem .7rem;color:var(--admin-text);font:inherit;font-size:.84rem;font-weight:750;text-transform:none}
      #${rootId} input[type="color"]{height:2.55rem;padding:.22rem;cursor:pointer}
      #${rootId} textarea{min-height:6.5rem;resize:vertical}
      #${rootId} .admin-check{display:flex;align-items:center;gap:.45rem;color:var(--admin-text);font-size:.84rem;font-weight:850;text-transform:none}
      #${rootId} .admin-check input{width:1rem;height:1rem}
      #${rootId} .admin-site-heading{display:flex;align-items:center;gap:.55rem;min-width:0}
      #${rootId} .admin-site-swatch{display:inline-block;width:1rem;height:1rem;flex:0 0 auto;border:1px solid var(--admin-border);border-radius:999px;background:var(--site-color,var(--admin-primary));box-shadow:0 0 0 .18rem color-mix(in srgb,var(--site-color,var(--admin-primary)) 12%,transparent)}
      #${rootId} .admin-site-photo{display:grid;grid-template-columns:6.2rem minmax(0,1fr);align-items:center;gap:.8rem;border:1px solid var(--admin-border);border-radius:.55rem;background:#f8fafc;padding:.7rem}
      #${rootId} .admin-site-photo-preview{display:grid;place-items:center;width:6.2rem;aspect-ratio:4/3;overflow:hidden;border:1px solid var(--admin-border);border-radius:.5rem;background:#fff;color:var(--admin-muted);font-size:.72rem;font-weight:600;text-align:center}
      #${rootId} .admin-site-photo-preview img{width:100%;height:100%;object-fit:cover}
      #${rootId} .admin-site-photo-content{display:grid;gap:.18rem;min-width:0}
      #${rootId} .admin-site-photo-content strong{color:var(--admin-text);font-size:.9rem;font-weight:600}
      #${rootId} .admin-site-photo-content p{margin:0;color:var(--admin-muted);font-size:.74rem;font-weight:750}
      #${rootId} .admin-site-photo-actions{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.35rem}
      #${rootId} .admin-password-panel{display:grid;gap:.65rem;border:1px solid var(--admin-border);border-radius:.55rem;background:#f8fafc;padding:.8rem}
      #${rootId} .admin-password-panel strong{color:var(--admin-text);font-size:.9rem;font-weight:600}
      #${rootId} .admin-password-panel p,#${rootId} .admin-help{margin:.12rem 0 0;color:var(--admin-muted);font-size:.74rem;font-weight:750;text-transform:none}
      #${rootId} .admin-actions{display:flex;justify-content:flex-end;gap:.55rem;flex-wrap:wrap}
      #${rootId} .admin-empty,#${rootId} .admin-loading{display:grid;place-items:center;min-height:7rem;border:1px dashed var(--admin-border);border-radius:.55rem;color:var(--admin-muted);font-weight:850;text-align:center;padding:1rem}
      #${rootId} .admin-alert{border:1px solid #fecaca;border-radius:.55rem;background:#fff1f2;padding:.8rem;color:#b91c1c;font-weight:850}
      #${rootId} .admin-menu-groups{display:grid;grid-template-columns:minmax(16rem,.7fr) minmax(0,1.3fr);gap:.85rem}
      #${rootId} .admin-icon-preview{display:inline-grid;place-items:center;width:1.9rem;height:1.9rem;border-radius:.45rem;background:#f7e8ee;color:var(--admin-primary)}
      .dark #${rootId}{--admin-border:var(--color-surface-700,#334155);--admin-text:#fff;--admin-muted:var(--color-secondary-400,#94a3b8)}
      .dark #${rootId} .admin-card,.dark #${rootId} .admin-row,.dark #${rootId} .admin-button,.dark #${rootId} .admin-tab,.dark #${rootId} input,.dark #${rootId} select,.dark #${rootId} textarea,.dark #${rootId} .admin-site-photo,.dark #${rootId} .admin-site-photo-preview,.dark #${rootId} .admin-password-panel{background:var(--color-surface-900,#0f172a);border-color:var(--admin-border)}
      @media (max-width:900px){#${rootId} .admin-menu-groups,#${rootId} .admin-grid,#${rootId} .admin-grid-3{grid-template-columns:1fr}}
      @media (max-width:700px){#${rootId} .admin-top{display:grid}#${rootId} .admin-title h1{font-size:1.55rem}#${rootId} .admin-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}#${rootId} .admin-tab{width:100%}#${rootId} .admin-actions{display:grid;grid-template-columns:1fr 1fr}}
      @media (max-width:520px){#${rootId} .admin-site-photo{grid-template-columns:1fr}#${rootId} .admin-site-photo-preview{width:100%;max-width:14rem}}
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
      root.innerHTML = `<section class="admin-loading">Chargement de l'administration...</section>`;
      return;
    }

    if (state.error) {
      root.innerHTML = `
        <div class="admin-top">
          <div class="admin-title">
            <h1>Administration</h1>
            <p>Configuration du HUB Martin Sols</p>
          </div>
        </div>
        <div class="admin-alert">${esc(state.error)}</div>
      `;
      return;
    }

    root.innerHTML = renderContent();
    bind(root);
  }

  function renderContent() {
    return `
      <div class="admin-top">
        <div class="admin-title">
          <h1>Administration</h1>
          <p>Menus, modules, sites, pages et utilisateurs</p>
        </div>
      </div>
      ${renderTabs()}
      ${renderPanel()}
    `;
  }

  function renderTabs() {
    const tabs = [
      ["menu", "Menu gauche"],
      ["modules", "Modules"],
      ["sites", "Sites"],
      ["pages", "Pages"],
      ["users", "Utilisateurs"],
    ];

    return `<nav class="admin-tabs">${tabs.map(([key, label]) => `<button class="admin-tab${state.tab === key ? " is-active" : ""}" type="button" data-tab="${key}">${label}</button>`).join("")}</nav>`;
  }

  function renderPanel() {
    if (state.tab === "modules") return renderModules();
    if (state.tab === "sites") return renderSites();
    if (state.tab === "pages") return renderPages();
    if (state.tab === "users") return renderUsers();

    return renderMenu();
  }

  function renderMenu() {
    const groups = state.data?.menuGroups || [];
    const items = state.data?.menuItems || [];

    return `
      <section class="admin-card">
        <header class="admin-card-header">
          <div>
            <h2 class="admin-card-title">Menu gauche</h2>
            <p class="admin-card-subtitle">Renommez, masquez, déplacez et changez les icônes des liens.</p>
          </div>
          <button class="admin-button admin-button-primary" type="button" data-save-menu>${state.saving === "menu" ? "Enregistrement..." : "Enregistrer"}</button>
        </header>
        <div class="admin-card-body admin-menu-groups">
          <div>
            <h3 class="admin-card-title">Titres des groupes</h3>
            <div class="admin-card-body">
              ${groups.map(renderMenuGroupForm).join("")}
            </div>
          </div>
          <div>
            <h3 class="admin-card-title">Liens</h3>
            <div class="admin-card-body">
              ${items.map((item) => renderMenuItemForm(item, groups)).join("")}
            </div>
          </div>
        </div>
      </section>
    `;
  }

  function renderMenuGroupForm(group) {
    return `
      <div class="admin-row" data-menu-group="${esc(group.menuKey)}">
        <div class="admin-row-title"><strong>${esc(group.title)}</strong><span>${esc(group.menuKey)}</span></div>
        <div class="admin-grid">
          <label>Titre <input name="title" value="${esc(group.title)}"></label>
          <label>Ordre <input name="sortOrder" type="number" value="${esc(group.sortOrder)}"></label>
        </div>
        <label class="admin-check"><input name="active" type="checkbox"${group.active !== false ? " checked" : ""}> Visible</label>
      </div>
    `;
  }

  function renderMenuItemForm(item, groups) {
    return `
      <div class="admin-row" data-menu-item="${esc(item.itemKey)}">
        <div class="admin-row-title"><strong>${esc(item.label)}</strong><span class="admin-icon-preview">${icon(item.iconKey)}</span></div>
        <div class="admin-grid-3">
          <label>Nom <input name="label" value="${esc(item.label)}"></label>
          <label>Groupe
            <select name="groupKey">
              ${groups.map((group) => `<option value="${esc(group.menuKey)}"${group.menuKey === item.groupKey ? " selected" : ""}>${esc(group.title)}</option>`).join("")}
            </select>
          </label>
          <label>Icône
            <select name="iconKey">
              ${iconOptions().map((option) => `<option value="${esc(option)}"${option === item.iconKey ? " selected" : ""}>${esc(option)}</option>`).join("")}
            </select>
          </label>
          <label>Ordre <input name="sortOrder" type="number" value="${esc(item.sortOrder)}"></label>
          <label class="admin-check"><input name="active" type="checkbox"${item.active !== false ? " checked" : ""}> Visible</label>
        </div>
      </div>
    `;
  }

  function renderModules() {
    return `
      <section class="admin-card">
        <header class="admin-card-header">
          <div><h2 class="admin-card-title">Modules</h2><p class="admin-card-subtitle">Titre, route, badge et visibilité.</p></div>
        </header>
        <div class="admin-card-body">
          ${(state.data?.modules || []).map(renderModuleForm).join("")}
        </div>
      </section>
    `;
  }

  function renderModuleForm(module) {
    return `
      <form class="admin-row" data-module-form>
        <input type="hidden" name="id" value="${esc(module.id)}">
        <div class="admin-row-title"><strong>${esc(module.name)}</strong><span>${esc(module.slug)}</span></div>
        <div class="admin-grid-3">
          <label>Nom <input name="name" value="${esc(module.name)}" required></label>
          <label>Slug <input name="slug" value="${esc(module.slug)}" required></label>
          <label>Route <input name="routePath" value="${esc(module.routePath)}" required></label>
          <label>Badge <input name="menuBadge" value="${esc(module.menuBadge)}"></label>
          <label>Ordre <input name="sortOrder" type="number" value="${esc(module.sortOrder)}"></label>
          <label class="admin-check"><input name="active" type="checkbox"${module.active !== false ? " checked" : ""}> Actif</label>
          <label class="admin-check"><input name="showMenuBadge" type="checkbox"${module.showMenuBadge ? " checked" : ""}> Afficher badge</label>
        </div>
        <div class="admin-actions"><button class="admin-button admin-button-primary" type="submit">Enregistrer</button></div>
      </form>
    `;
  }

  function renderSites() {
    return `
      <section class="admin-card">
        <header class="admin-card-header">
          <div><h2 class="admin-card-title">Sites</h2><p class="admin-card-subtitle">Création, modification, suppression et horaires.</p></div>
          <button class="admin-button admin-button-primary" type="button" data-new-site>Créer un site</button>
        </header>
        <div class="admin-card-body">
          ${(state.data?.sites || []).map(renderSiteForm).join("")}
        </div>
      </section>
    `;
  }

  function renderSiteForm(site) {
    const hours = site.hours || {};
    const color = validHexColor(site.color) || defaultSiteColor();

    return `
      <form class="admin-row" data-site-form>
        <input type="hidden" name="id" value="${esc(site.id || "")}">
        <div class="admin-row-title">
          <span class="admin-site-heading" style="--site-color:${esc(color)}"><i class="admin-site-swatch" aria-hidden="true"></i><strong>${esc(site.name || "Nouveau site")}</strong></span>
          <span>${site.active === false ? "Masqué" : "Actif"}</span>
        </div>
        ${renderSitePhoto(site)}
        <div class="admin-grid-3">
          <label>Nom <input name="name" value="${esc(site.name || "")}" required></label>
          <label>Couleur <input name="color" type="color" value="${esc(color)}"></label>
          <label>Téléphone <input name="phone" type="tel" value="${esc(site.phone || "")}"></label>
          <label>E-mail <input name="email" type="email" value="${esc(site.email || "")}"></label>
          <label>Matin début <input name="morningStart" type="time" value="${esc(hours.morningStart || "07:30")}"></label>
          <label>Matin fin <input name="morningEnd" type="time" value="${esc(hours.morningEnd || "12:00")}"></label>
          <label>Après-midi début <input name="afternoonStart" type="time" value="${esc(hours.afternoonStart || "13:30")}"></label>
          <label>Après-midi fin <input name="afternoonEnd" type="time" value="${esc(hours.afternoonEnd || "17:30")}"></label>
          <label class="admin-check"><input name="active" type="checkbox"${site.active !== false ? " checked" : ""}> Actif</label>
        </div>
        <label>Adresse <input name="address" value="${esc(site.address || "")}"></label>
        <div class="admin-actions">
          <button class="admin-button admin-button-primary" type="submit">Enregistrer</button>
          ${site.id ? `<button class="admin-button admin-button-danger" type="button" data-delete-site="${esc(site.id)}">Supprimer</button>` : ""}
        </div>
      </form>
    `;
  }

  function renderSitePhoto(site) {
    const src = site.photoUrl || "";

    return `
      <div class="admin-site-photo">
        <span class="admin-site-photo-preview" data-site-photo-preview>${src ? `<img src="${esc(src)}" alt="${esc(site.name || "Site")}" loading="lazy">` : "<span>Photo du site</span>"}</span>
        <div class="admin-site-photo-content">
          <strong>Photo du site</strong>
          <p>Image affichée avec les informations du site.</p>
          <div class="admin-site-photo-actions">
            <button class="admin-button" type="button" data-site-photo-pick>${src ? "Remplacer" : "Choisir une photo"}</button>
            <button class="admin-button admin-button-danger" type="button" data-site-photo-remove${src ? "" : " hidden"}>Supprimer</button>
          </div>
          <input type="file" accept="image/png,image/jpeg,image/webp" hidden data-site-photo-input>
          <input type="hidden" name="photoDataUrl" value="">
          <input type="hidden" name="removePhoto" value="">
        </div>
      </div>
    `;
  }

  function renderPages() {
    return `
      <section class="admin-card">
        <header class="admin-card-header">
          <div><h2 class="admin-card-title">Pages HUB</h2><p class="admin-card-subtitle">Pages visibles dans le menu et contenus internes.</p></div>
          <button class="admin-button admin-button-primary" type="button" data-new-page>Créer une page</button>
        </header>
        <div class="admin-card-body">
          ${(state.data?.pages || []).map(renderPageForm).join("")}
        </div>
      </section>
    `;
  }

  function renderPageForm(page) {
    return `
      <form class="admin-row" data-page-form>
        <input type="hidden" name="id" value="${esc(page.id || "")}">
        <div class="admin-row-title"><strong>${esc(page.title || "Nouvelle page")}</strong><span>${esc(page.routePath || "")}</span></div>
        <div class="admin-grid">
          <label>Titre <input name="title" value="${esc(page.title || "")}" required></label>
          <label>Slug <input name="slug" value="${esc(page.slug || "")}"></label>
          <label>Icône <select name="iconKey">${iconOptions().map((option) => `<option value="${esc(option)}"${option === page.iconKey ? " selected" : ""}>${esc(option)}</option>`).join("")}</select></label>
          <label>Ordre <input name="sortOrder" type="number" value="${esc(page.sortOrder || 100)}"></label>
        </div>
        <label>Résumé <input name="excerpt" value="${esc(page.excerpt || "")}"></label>
        <label>Contenu <textarea name="content" required>${esc(page.content || "")}</textarea></label>
        <div class="admin-grid">
          <label class="admin-check"><input name="active" type="checkbox"${page.active !== false ? " checked" : ""}> Active</label>
          <label class="admin-check"><input name="showInMenu" type="checkbox"${page.showInMenu !== false ? " checked" : ""}> Dans le menu</label>
        </div>
        <div class="admin-actions">
          <button class="admin-button admin-button-primary" type="submit">Enregistrer</button>
          ${page.id ? `<button class="admin-button admin-button-danger" type="button" data-delete-page="${esc(page.id)}">Supprimer</button>` : ""}
        </div>
      </form>
    `;
  }

  function renderUsers() {
    return `
      <section class="admin-card">
        <header class="admin-card-header">
          <div><h2 class="admin-card-title">Utilisateurs</h2><p class="admin-card-subtitle">Rôle, site principal et activation.</p></div>
        </header>
        <div class="admin-card-body">
          ${(state.data?.users || []).map(renderUserForm).join("")}
        </div>
      </section>
    `;
  }

  function renderUserForm(user) {
    return `
      <form class="admin-row" data-user-form>
        <input type="hidden" name="id" value="${esc(user.id)}">
        <input type="hidden" name="siteIds" value="${esc(JSON.stringify(user.siteIds || []))}">
        <input type="hidden" name="moduleIds" value="${esc(JSON.stringify(user.moduleIds || []))}">
        <input type="hidden" name="permissionIds" value="${esc(JSON.stringify(user.permissionIds || []))}">
        <input type="hidden" name="accessRules" value="${esc(JSON.stringify(user.accessRules || []))}">
        <div class="admin-row-title"><strong>${esc(user.name)}</strong><span>${esc(user.role)}</span></div>
        <div class="admin-grid-3">
          <label>Nom <input name="name" value="${esc(user.name)}" required></label>
          <label>Email <input name="email" type="email" value="${esc(user.email)}" required></label>
          <label>Téléphone <input name="phone" value="${esc(user.phone || "")}"></label>
          <label>Rôle
            <select name="role">
              ${(state.data?.roles || []).map((role) => `<option value="${esc(role.key)}"${role.key === user.role ? " selected" : ""}>${esc(role.label)}</option>`).join("")}
            </select>
          </label>
          <label>Site principal
            <select name="primarySiteId">
              ${(state.data?.sites || []).map((site) => `<option value="${esc(site.id)}"${Number(site.id) === Number(user.primarySiteId) ? " selected" : ""}>${esc(site.name)}</option>`).join("")}
            </select>
          </label>
          <label class="admin-check"><input name="active" type="checkbox"${user.active !== false ? " checked" : ""}> Actif</label>
        </div>
        ${renderUserPasswordFields(user)}
        <div class="admin-actions"><button class="admin-button admin-button-primary" type="submit">Enregistrer</button></div>
      </form>
    `;
  }

  function renderUserPasswordFields(user) {
    if (!canManageUsers()) return "";

    return `
      <div class="admin-password-panel">
        <div>
          <strong>Mot de passe</strong>
          <p>Laisser vide pour conserver le mot de passe actuel.</p>
        </div>
        <div class="admin-grid">
          <label>Nouveau mot de passe <input name="password" type="password" autocomplete="new-password"></label>
          <label>Confirmation <input name="passwordConfirmation" type="password" autocomplete="new-password"></label>
        </div>
      </div>
    `;
  }

  function bind(root) {
    root.querySelectorAll("[data-tab]").forEach((button) => {
      button.addEventListener("click", () => {
        state.tab = button.dataset.tab;
        const url = new URL(window.location.href);
        url.searchParams.set("section", state.tab);
        window.history.replaceState({}, "", `${url.pathname}${url.search}`);
        render();
      });
    });

    root.querySelector("[data-save-menu]")?.addEventListener("click", saveMenu);
    root.querySelectorAll("[data-module-form]").forEach((form) => form.addEventListener("submit", saveModule));
    root.querySelectorAll("[data-site-form]").forEach((form) => form.addEventListener("submit", saveSite));
    root.querySelectorAll("[data-site-form]").forEach(bindSitePhotoForm);
    root.querySelectorAll("[data-page-form]").forEach((form) => form.addEventListener("submit", savePage));
    root.querySelectorAll("[data-user-form]").forEach((form) => form.addEventListener("submit", saveUser));
    root.querySelectorAll("[data-delete-site]").forEach((button) => button.addEventListener("click", deleteSite));
    root.querySelectorAll("[data-delete-page]").forEach((button) => button.addEventListener("click", deletePage));
    root.querySelector("[data-new-site]")?.addEventListener("click", () => {
      state.data.sites = [{ id: "", name: "", active: true, address: "", phone: "", email: "", color: defaultSiteColor(), photoUrl: "", hours: {} }, ...(state.data?.sites || [])];
      render();
    });
    root.querySelector("[data-new-page]")?.addEventListener("click", () => {
      state.data.pages = [{ id: "", title: "", slug: "", excerpt: "", content: "", iconKey: "article", active: true, showInMenu: true, sortOrder: 100 }, ...(state.data?.pages || [])];
      render();
    });
  }

  async function saveMenu() {
    const root = document.getElementById(rootId);
    const groups = Array.from(root.querySelectorAll("[data-menu-group]")).map((row) => ({
      menuKey: row.dataset.menuGroup,
      title: row.querySelector('[name="title"]').value,
      sortOrder: Number(row.querySelector('[name="sortOrder"]').value || 100),
      active: row.querySelector('[name="active"]').checked,
    }));
    const items = Array.from(root.querySelectorAll("[data-menu-item]")).map((row) => ({
      itemKey: row.dataset.menuItem,
      label: row.querySelector('[name="label"]').value,
      groupKey: row.querySelector('[name="groupKey"]').value,
      iconKey: row.querySelector('[name="iconKey"]').value,
      sortOrder: Number(row.querySelector('[name="sortOrder"]').value || 100),
      active: row.querySelector('[name="active"]').checked,
    }));

    await save("menu", () => request("save_menu_settings", { method: "POST", body: { groups, items } }));
  }

  async function saveModule(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);

    await save("module", () => request("save_module", {
      method: "POST",
      body: {
        id: Number(data.get("id") || 0),
        name: String(data.get("name") || ""),
        slug: String(data.get("slug") || ""),
        routePath: String(data.get("routePath") || ""),
        menuBadge: String(data.get("menuBadge") || ""),
        showMenuBadge: Boolean(data.get("showMenuBadge")),
        active: Boolean(data.get("active")),
        sortOrder: Number(data.get("sortOrder") || 100),
      },
    }));
  }

  async function saveSite(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);

    await save("site", () => request("save_site", {
      method: "POST",
      body: {
        id: Number(data.get("id") || 0),
        name: String(data.get("name") || ""),
        address: String(data.get("address") || ""),
        phone: String(data.get("phone") || ""),
        email: String(data.get("email") || ""),
        color: String(data.get("color") || ""),
        morningStart: String(data.get("morningStart") || "07:30"),
        morningEnd: String(data.get("morningEnd") || "12:00"),
        afternoonStart: String(data.get("afternoonStart") || "13:30"),
        afternoonEnd: String(data.get("afternoonEnd") || "17:30"),
        photoDataUrl: String(data.get("photoDataUrl") || ""),
        removePhoto: Boolean(data.get("removePhoto")),
        active: Boolean(data.get("active")),
      },
    }));
  }

  function bindSitePhotoForm(form) {
    const input = form.querySelector("[data-site-photo-input]");
    const pick = form.querySelector("[data-site-photo-pick]");
    const remove = form.querySelector("[data-site-photo-remove]");
    const photoData = form.querySelector('[name="photoDataUrl"]');
    const removePhoto = form.querySelector('[name="removePhoto"]');
    const preview = form.querySelector("[data-site-photo-preview]");

    pick?.addEventListener("click", () => input?.click());

    input?.addEventListener("change", async () => {
      const file = input.files && input.files[0];
      if (!file) return;

      if (!["image/png", "image/jpeg", "image/webp"].includes(file.type)) {
        alert("Choisis une image PNG, JPG ou WebP.");
        input.value = "";
        return;
      }

      if (file.size > 5 * 1024 * 1024) {
        alert("La photo ne doit pas dépasser 5 Mo.");
        input.value = "";
        return;
      }

      try {
        const dataUrl = await readFileAsDataUrl(file);
        photoData.value = dataUrl;
        removePhoto.value = "";
        preview.innerHTML = `<img src="${esc(dataUrl)}" alt="">`;
        remove?.removeAttribute("hidden");
        if (pick) pick.textContent = "Remplacer";
      } catch (error) {
        alert(error.message || "Photo illisible.");
      }
    });

    remove?.addEventListener("click", () => {
      photoData.value = "";
      removePhoto.value = "1";
      if (input) input.value = "";
      preview.innerHTML = "<span>Photo du site</span>";
      remove.setAttribute("hidden", "");
      if (pick) pick.textContent = "Choisir une photo";
    });
  }

  function readFileAsDataUrl(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || ""));
      reader.onerror = () => reject(new Error("Photo illisible"));
      reader.readAsDataURL(file);
    });
  }

  async function savePage(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);

    await save("page", () => request("save_page", {
      method: "POST",
      body: {
        id: Number(data.get("id") || 0),
        title: String(data.get("title") || ""),
        slug: String(data.get("slug") || ""),
        excerpt: String(data.get("excerpt") || ""),
        content: String(data.get("content") || ""),
        iconKey: String(data.get("iconKey") || "article"),
        active: Boolean(data.get("active")),
        showInMenu: Boolean(data.get("showInMenu")),
        sortOrder: Number(data.get("sortOrder") || 100),
      },
    }));
  }

  async function saveUser(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const body = {
      id: Number(data.get("id") || 0),
      name: String(data.get("name") || ""),
      email: String(data.get("email") || ""),
      phone: String(data.get("phone") || ""),
      role: String(data.get("role") || "user"),
      primarySiteId: Number(data.get("primarySiteId") || 0),
      siteIds: mergePrimarySite(parseJsonArray(data.get("siteIds")), Number(data.get("primarySiteId") || 0)),
      moduleIds: parseJsonArray(data.get("moduleIds")),
      permissionIds: parseJsonArray(data.get("permissionIds")),
      accessRules: parseJsonArray(data.get("accessRules")),
      active: Boolean(data.get("active")),
    };
    const password = String(data.get("password") || "");

    if (password !== "") {
      body.password = password;
      body.passwordConfirmation = String(data.get("passwordConfirmation") || "");
    }

    await save("user", () => request("save_user", {
      method: "POST",
      body,
    }));
  }

  async function deleteSite(event) {
    const id = Number(event.currentTarget.dataset.deleteSite || 0);
    if (!id || !confirm("Supprimer ce site ?")) return;

    await save("site", () => request("delete_site", { method: "POST", body: { id } }));
  }

  async function deletePage(event) {
    const id = Number(event.currentTarget.dataset.deletePage || 0);
    if (!id || !confirm("Supprimer cette page ?")) return;

    await save("page", () => request("delete_page", { method: "POST", body: { id } }));
  }

  async function save(key, callback) {
    state.saving = key;
    render();

    try {
      await callback();
      await load({ force: true });
      if (key === "site") {
        window.CRM_ACTIVE_SITE?.reload?.();
      }
    } catch (error) {
      alert(error.message || "Enregistrement impossible");
      state.saving = "";
      render();
    }
  }

  async function load(options = {}) {
    if (!isRoute()) return;
    if (state.loading && !options.force) return;

    const sequence = ++loadSequence;
    state.loading = true;
    state.error = "";
    render();

    try {
      const payload = await request("bootstrap");
      if (sequence !== loadSequence) return;
      state.data = payload;
    } catch (error) {
      if (sequence === loadSequence) state.error = error.message || "Administration indisponible";
    } finally {
      if (sequence !== loadSequence) return;
      state.loading = false;
      state.saving = "";
      render();
    }
  }

  function iconOptions() {
    return [
      "dashboard",
      "calendar",
      "truck",
      "bus",
      "package",
      "users",
      "settings",
      "article",
      "fileText",
      "banknote",
      "creditCard",
      "checklist",
      "ruler",
      "table",
      "category",
    ];
  }

  function parseJsonArray(value) {
    try {
      const parsed = JSON.parse(String(value || "[]"));

      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      return [];
    }
  }

  function mergePrimarySite(siteIds, primarySiteId) {
    const ids = siteIds.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0);

    if (primarySiteId > 0 && !ids.includes(primarySiteId)) {
      ids.unshift(primarySiteId);
    }

    return ids;
  }

  function validHexColor(value) {
    const color = String(value || "").trim();

    return /^#[0-9a-fA-F]{6}$/.test(color) ? color.toLowerCase() : "";
  }

  function defaultSiteColor() {
    const color = getComputedStyle(document.documentElement).getPropertyValue("--theme-primary-color");

    return validHexColor(color) || "#7f1d3a";
  }

  function icon(key) {
    const paths = {
      article: '<path d="M7 3h7l4 4v14H7z"></path><path d="M14 3v5h5"></path><path d="M10 12h6M10 16h5"></path>',
      banknote: '<rect x="3" y="6" width="18" height="12" rx="2"></rect><circle cx="12" cy="12" r="2.5"></circle><path d="M6 9h1M17 15h1"></path>',
      bus: '<rect x="5" y="3" width="14" height="14" rx="3"></rect><path d="M8 7h8M8 11h8M8 17v2M16 17v2"></path>',
      calendar: '<rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 3v4M16 3v4M4 10h16"></path>',
      category: '<rect x="4" y="4" width="6" height="6" rx="1.5"></rect><rect x="14" y="4" width="6" height="6" rx="1.5"></rect><rect x="4" y="14" width="6" height="6" rx="1.5"></rect><rect x="14" y="14" width="6" height="6" rx="1.5"></rect>',
      checklist: '<path d="m8 7 1.6 1.6L13 5"></path><path d="M16 7h4"></path><path d="m8 15 1.6 1.6L13 13"></path><path d="M16 15h4"></path>',
      creditCard: '<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M3 10h18M7 15h4"></path>',
      dashboard: '<rect x="4" y="4" width="6" height="6" rx="1.5"></rect><rect x="14" y="4" width="6" height="6" rx="1.5"></rect><rect x="4" y="14" width="6" height="6" rx="1.5"></rect><rect x="14" y="14" width="6" height="6" rx="1.5"></rect>',
      fileText: '<path d="M7 3h7l4 4v14H7z"></path><path d="M14 3v5h5"></path><path d="M10 13h6M10 17h4"></path>',
      package: '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"></path><path d="m4 7.5 8 4.5 8-4.5M12 12v9"></path>',
      ruler: '<path d="M4 17 17 4l3 3L7 20z"></path><path d="m14 7 3 3M11 10l2 2M8 13l3 3"></path>',
      settings: '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"></path><path d="M12 2v3M12 19v3M4.9 4.9 7 7M17 17l2.1 2.1M2 12h3M19 12h3"></path>',
      table: '<path d="M4 6h16M4 12h16M4 18h16"></path><path d="M8 6v12M16 6v12"></path>',
      truck: '<path d="M3 7h11v8H3z"></path><path d="M14 10h3l3 3v2h-6z"></path><circle cx="7" cy="18" r="2"></circle><circle cx="17" cy="18" r="2"></circle>',
      users: '<path d="M16 11a4 4 0 1 0-8 0"></path><path d="M4 21a8 8 0 0 1 16 0"></path>',
    };

    return `<svg viewBox="0 0 24 24" aria-hidden="true">${paths[key] || paths.category}</svg>`;
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
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
})();

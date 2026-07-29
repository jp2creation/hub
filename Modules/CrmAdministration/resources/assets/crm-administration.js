(() => {
  const api = "/api/administration";
  const rootId = "crm-administration-module";
  const styleId = "crm-administration-style";
  const routeEvents = ["popstate", "crm:navigation", "crm:route-changed"];

  const state = {
    data: null,
    loading: false,
    error: "",
    tab: sectionFromLocation(),
    editing: null,
    search: "",
    userFilters: { siteId: "all", role: "all" },
    saving: "",
  };

  let mountTimer = null;
  let loadSequence = 0;
  let currentMenuDrag = null;

  function adminSections() {
    return [
      ["overview", "Vue d'ensemble", "Pilotage", "dashboard"],
      ["users", "Utilisateurs", "Accès et droits", "users"],
      ["sites", "Sites", "Coordonnées", "category"],
      ["modules", "Modules", "Visibilité", "package"],
      ["menu", "Navigation", "Sections et pages", "settings"],
      ["pages", "Pages HUB", "Contenus", "article"],
    ];
  }

  function normalizeTab(value) {
    const key = String(value || "").replace(/^\/+|\/+$/g, "");

    return adminSections().some(([section]) => section === key) ? key : "overview";
  }

  function sectionFromLocation() {
    const sectionParam = new URLSearchParams(window.location.search).get("section");
    if (sectionParam) return normalizeTab(sectionParam);

    const path = window.location.pathname.replace(/\/+$/, "") || "/";
    const section = path.replace(/^\/administration\/?/, "");

    return normalizeTab(section);
  }

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

  function canManageRoles() {
    const actor = state.data?.actor || {};
    const permissions = Array.isArray(actor.permissions) ? actor.permissions : [];

    return actor.role === "admin" || permissions.includes("platform.manage_users") || permissions.includes("platform.manage_roles");
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
      #${rootId}{--admin-primary:rgb(var(--theme-primary));--admin-primary-soft:rgb(var(--theme-primary) / .08);--admin-border:var(--color-surface-200,#e2e8f0);--admin-text:var(--color-secondary-900,#0f172a);--admin-muted:var(--color-secondary-500,#64748b);--admin-soft:#f8fafc;display:grid;gap:1rem}
      #${rootId} *{box-sizing:border-box}
      #${rootId} svg{width:1.05rem;height:1.05rem;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} .admin-shell{display:grid;gap:1rem}
      #${rootId} .admin-top{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem}
      #${rootId} .admin-title h1{margin:0;color:var(--admin-text);font-size:1.82rem;line-height:1.08;font-weight:600;letter-spacing:0}
      #${rootId} .admin-title p{margin:.36rem 0 0;color:var(--admin-muted);font-size:.92rem;font-weight:400}
      #${rootId} .admin-head-actions{display:flex;align-items:center;gap:.55rem;flex-wrap:wrap;justify-content:flex-end}
      #${rootId} .admin-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.8rem}
      #${rootId} .admin-stat{display:grid;grid-template-columns:2.75rem minmax(0,1fr);align-items:center;gap:.72rem;min-height:5.6rem;border:1px solid var(--admin-border);border-radius:.6rem;background:#fff;padding:.9rem 1rem;box-shadow:0 12px 28px rgba(15,23,42,.045)}
      #${rootId} .admin-stat-icon{display:grid;place-items:center;width:2.75rem;height:2.75rem;border-radius:.58rem;background:color-mix(in srgb,var(--stat-color,var(--admin-primary)) 14%,white);color:var(--stat-color,var(--admin-primary))}
      #${rootId} .admin-stat span:not(.admin-stat-icon){display:block;overflow:hidden;color:var(--admin-muted);font-size:.72rem;font-weight:600;text-transform:uppercase;text-overflow:ellipsis;white-space:nowrap}
      #${rootId} .admin-stat strong{display:block;overflow:hidden;color:var(--admin-text);font-size:1.35rem;font-weight:600;line-height:1.05;text-overflow:ellipsis;white-space:nowrap}
      #${rootId} .admin-stat small{display:block;margin-top:.2rem;overflow:hidden;color:#94a3b8;font-size:.74rem;font-weight:400;text-overflow:ellipsis;white-space:nowrap}
      #${rootId} .admin-workspace{display:grid;gap:1rem;min-width:0}
      #${rootId} .admin-main{display:grid;gap:1rem;min-width:0}
      #${rootId} .admin-toolbar{display:flex;align-items:center;justify-content:space-between;gap:.8rem;border:1px solid var(--admin-border);border-radius:.6rem;background:#fff;padding:.75rem;box-shadow:0 10px 24px rgba(15,23,42,.04)}
      #${rootId} .admin-toolbar-copy{min-width:0}
      #${rootId} .admin-toolbar-copy strong{display:block;color:var(--admin-text);font-size:.98rem;font-weight:600}
      #${rootId} .admin-toolbar-copy span{display:block;margin-top:.16rem;color:var(--admin-muted);font-size:.76rem;font-weight:400}
      #${rootId} .admin-search{display:grid;grid-template-columns:1.1rem minmax(0,1fr);align-items:center;gap:.42rem;min-width:min(22rem,100%);border:1px solid var(--admin-border);border-radius:.55rem;background:var(--admin-soft);padding:0 .72rem;color:var(--admin-muted)}
      #${rootId} .admin-search input{border:0;background:transparent;padding:.65rem 0;box-shadow:none}
      #${rootId} .admin-filter-card{display:flex;align-items:flex-end;gap:.65rem;flex-wrap:wrap;border:1px solid var(--admin-border);border-radius:.5rem;background:#fff;padding:.72rem;box-shadow:0 10px 24px rgba(15,23,42,.04)}
      #${rootId} .admin-filter-all{display:inline-flex;align-items:center;justify-content:center;gap:.42rem;min-height:2.65rem;border:1px solid var(--admin-border);border-radius:.5rem;background:#fff;padding:.52rem .75rem;color:var(--admin-text);font:inherit;font-size:.8rem;font-weight:600;cursor:pointer;white-space:nowrap}
      #${rootId} .admin-filter-all.is-active{border-color:transparent;background:var(--admin-primary);color:#fff;box-shadow:0 12px 24px rgb(var(--theme-primary) / .16)}
      #${rootId} .admin-filter-all span{display:inline-flex;align-items:center;justify-content:center;min-width:1.45rem;height:1.35rem;border-radius:999px;background:#f1f5f9;color:var(--admin-muted);font-size:.68rem;font-weight:600}
      #${rootId} .admin-filter-all.is-active span{background:rgba(255,255,255,.2);color:#fff}
      #${rootId} .admin-filter-control{min-width:12rem;max-width:16rem;flex:1 1 12rem}
      #${rootId} .admin-filter-control select{min-height:2.65rem}
      #${rootId} .admin-filter-count{margin-left:auto;color:var(--admin-muted);font-size:.76rem;font-weight:400;white-space:nowrap}
      #${rootId} .admin-card{border:1px solid var(--admin-border);border-radius:.5rem;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.05)}
      #${rootId} .admin-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--admin-border);padding:.9rem 1rem}
      #${rootId} .admin-card-title{margin:0;color:var(--admin-text);font-size:1rem;font-weight:600}
      #${rootId} .admin-card-subtitle{margin:.18rem 0 0;color:var(--admin-muted);font-size:.78rem;font-weight:400}
      #${rootId} .admin-card-body{display:grid;gap:.85rem;padding:1rem}
      #${rootId} .admin-list-card .admin-card-body{display:block;padding:0}
      #${rootId} .admin-list-card .admin-empty{margin:1rem}
      #${rootId} .admin-button{display:inline-flex;align-items:center;justify-content:center;gap:.42rem;min-height:2.4rem;border:1px solid var(--admin-border);border-radius:.5rem;background:#fff;padding:.55rem .8rem;color:var(--admin-text);font-size:.84rem;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap}
      #${rootId} .admin-button:hover{border-color:rgb(var(--theme-primary) / .45);color:var(--admin-primary)}
      #${rootId} .admin-button-primary{border-color:transparent;background:var(--admin-primary);color:#fff}
      #${rootId} .admin-button-primary:hover{border-color:transparent;color:#fff}
      #${rootId} .admin-button-danger{color:#b91c1c}
      #${rootId} .admin-button-soft{background:var(--admin-primary-soft);color:var(--admin-primary);border-color:rgb(var(--theme-primary) / .16)}
      #${rootId} .admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.8rem}
      #${rootId} .admin-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.8rem}
      #${rootId} .admin-row{display:grid;gap:.7rem;border:1px solid var(--admin-border);border-radius:.55rem;background:#fff;padding:.8rem}
      #${rootId} .admin-row-title{display:flex;align-items:center;justify-content:space-between;gap:.65rem}
      #${rootId} .admin-row-title strong{color:var(--admin-text);font-size:.94rem;font-weight:600}
      #${rootId} .admin-row-title span{color:var(--admin-muted);font-size:.72rem;font-weight:400}
      #${rootId} .admin-row-title .admin-pill{color:inherit}
      #${rootId} label{display:grid;gap:.28rem;color:var(--admin-muted);font-size:.7rem;font-weight:600;text-transform:uppercase}
      #${rootId} input,#${rootId} select,#${rootId} textarea{width:100%;border:1px solid var(--admin-border);border-radius:.48rem;background:#fff;padding:.62rem .7rem;color:var(--admin-text);font:inherit;font-size:.84rem;font-weight:400;text-transform:none}
      #${rootId} input[type="color"]{height:2.55rem;padding:.22rem;cursor:pointer}
      #${rootId} textarea{min-height:6.5rem;resize:vertical}
      #${rootId} .admin-check{display:flex;align-items:center;gap:.45rem;color:var(--admin-text);font-size:.84rem;font-weight:400;text-transform:none}
      #${rootId} .admin-check input{width:1rem;height:1rem}
      #${rootId} .admin-pill{display:inline-flex;align-items:center;justify-content:center;min-height:1.55rem;border-radius:999px;background:color-mix(in srgb,var(--admin-pill-color,#64748b) 12%,white);padding:.18rem .55rem;color:var(--admin-pill-color,#64748b);font-size:.7rem;font-weight:600;white-space:nowrap}
      #${rootId} .admin-pill.is-active{--admin-pill-color:#16a34a}
      #${rootId} .admin-pill.is-hidden{--admin-pill-color:#b91c1c}
      #${rootId} .admin-site-heading{display:flex;align-items:center;gap:.55rem;min-width:0}
      #${rootId} .admin-site-swatch{display:inline-block;width:1rem;height:1rem;flex:0 0 auto;border:1px solid var(--admin-border);border-radius:999px;background:var(--site-color,var(--admin-primary));box-shadow:0 0 0 .18rem color-mix(in srgb,var(--site-color,var(--admin-primary)) 12%,transparent)}
      #${rootId} .admin-site-photo{display:grid;grid-template-columns:6.2rem minmax(0,1fr);align-items:center;gap:.8rem;border:1px solid var(--admin-border);border-radius:.55rem;background:var(--admin-soft);padding:.7rem}
      #${rootId} .admin-site-photo-preview{display:grid;place-items:center;width:6.2rem;aspect-ratio:4/3;overflow:hidden;border:1px solid var(--admin-border);border-radius:.5rem;background:#fff;color:var(--admin-muted);font-size:.72rem;font-weight:600;text-align:center}
      #${rootId} .admin-site-photo-preview img{width:100%;height:100%;object-fit:cover}
      #${rootId} .admin-site-photo-content{display:grid;gap:.18rem;min-width:0}
      #${rootId} .admin-site-photo-content strong{color:var(--admin-text);font-size:.9rem;font-weight:600}
      #${rootId} .admin-site-photo-content p{margin:0;color:var(--admin-muted);font-size:.74rem;font-weight:400}
      #${rootId} .admin-site-photo-actions{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.35rem}
      #${rootId} .admin-password-panel{display:grid;gap:.65rem;border:1px solid var(--admin-border);border-radius:.55rem;background:var(--admin-soft);padding:.8rem}
      #${rootId} .admin-password-panel strong{color:var(--admin-text);font-size:.9rem;font-weight:600}
      #${rootId} .admin-password-panel p,#${rootId} .admin-help{margin:.12rem 0 0;color:var(--admin-muted);font-size:.74rem;font-weight:400;text-transform:none}
      #${rootId} .admin-actions{display:flex;justify-content:flex-end;gap:.55rem;flex-wrap:wrap}
      #${rootId} .admin-empty,#${rootId} .admin-loading{display:grid;place-items:center;min-height:7rem;border:1px dashed var(--admin-border);border-radius:.55rem;color:var(--admin-muted);font-weight:400;text-align:center;padding:1rem}
      #${rootId} .admin-alert{border:1px solid #fecaca;border-radius:.55rem;background:#fff1f2;padding:.8rem;color:#b91c1c;font-weight:400}
      #${rootId} .admin-menu-tree-card{overflow:hidden}
      #${rootId} .admin-menu-tree{display:block}
      #${rootId} .admin-menu-tree-row{--menu-indent:0rem;position:relative;display:grid;grid-template-columns:1.8rem 2.35rem minmax(0,1fr) auto;align-items:center;gap:.7rem;border:0;border-bottom:1px solid var(--admin-border);background:#fff;padding:.72rem .9rem .72rem calc(.9rem + var(--menu-indent));min-width:0}
      #${rootId} .admin-menu-tree-row:last-child{border-bottom:0}
      #${rootId} .admin-menu-tree-row:hover{background:var(--admin-soft)}
      #${rootId} .admin-menu-tree-row.is-dragging{opacity:.48;background:var(--admin-soft)}
      #${rootId} .admin-menu-tree-row.is-drop-before{box-shadow:inset 0 2px 0 var(--admin-primary)}
      #${rootId} .admin-menu-tree-row.is-drop-after{box-shadow:inset 0 -2px 0 var(--admin-primary)}
      #${rootId} .admin-menu-tree-row-item,#${rootId} .admin-menu-tree-row-subitem{background:#fafafa}
      #${rootId} .admin-menu-tree-row-item::before,#${rootId} .admin-menu-tree-row-subitem::before{content:"";position:absolute;left:calc(.9rem + var(--menu-indent) - 1.15rem);top:50%;width:1rem;border-top:1px solid var(--admin-border)}
      #${rootId} .admin-menu-tree-row-item .admin-list-icon,#${rootId} .admin-menu-tree-row-subitem .admin-list-icon{width:2.05rem;height:2.05rem;background:#fff;color:var(--admin-muted);box-shadow:inset 0 0 0 1px var(--admin-border)}
      #${rootId} .admin-menu-tree-row-group .admin-list-title{font-size:.95rem}
      #${rootId} .admin-menu-tree-row-item .admin-list-title,#${rootId} .admin-menu-tree-row-subitem .admin-list-title{font-size:.89rem}
      #${rootId} .admin-icon-preview{display:inline-grid;place-items:center;width:1.9rem;height:1.9rem;border-radius:.45rem;background:#f7e8ee;color:var(--admin-primary)}
      #${rootId} .admin-list{display:block}
      #${rootId} .admin-list-row{display:grid;grid-template-columns:2.55rem minmax(0,1fr) auto;align-items:center;gap:.72rem;border:0;border-bottom:1px solid var(--admin-border);background:#fff;padding:.72rem .9rem;min-width:0}
      #${rootId} .admin-list-row.is-draggable{grid-template-columns:1.8rem 2.55rem minmax(0,1fr) auto}
      #${rootId} .admin-list-row:last-child{border-bottom:0}
      #${rootId} .admin-list-row:hover{background:var(--admin-soft)}
      #${rootId} .admin-list-row.is-dragging{opacity:.48;background:var(--admin-soft)}
      #${rootId} .admin-list-row.is-drop-before{box-shadow:inset 0 2px 0 var(--admin-primary)}
      #${rootId} .admin-list-row.is-drop-after{box-shadow:inset 0 -2px 0 var(--admin-primary)}
      #${rootId} .admin-drag-handle{display:grid;place-items:center;width:1.8rem;height:2rem;border:1px solid transparent;border-radius:.45rem;color:var(--admin-muted);cursor:grab}
      #${rootId} .admin-drag-handle:hover{border-color:rgb(var(--theme-primary) / .18);background:var(--admin-primary-soft);color:var(--admin-primary)}
      #${rootId} .admin-list-row.is-dragging .admin-drag-handle{cursor:grabbing}
      #${rootId} .admin-list-icon{display:grid;place-items:center;width:2.25rem;height:2.25rem;border-radius:.5rem;background:var(--admin-primary-soft);color:var(--admin-primary);overflow:hidden}
      #${rootId} .admin-list-icon img{width:100%;height:100%;object-fit:cover}
      #${rootId} .admin-list-main{min-width:0}
      #${rootId} .admin-list-title{display:block;overflow:hidden;color:var(--admin-text);font-size:.92rem;font-weight:600;line-height:1.12;text-overflow:ellipsis;white-space:nowrap}
      #${rootId} .admin-list-subtitle{display:block;margin-top:.18rem;overflow:hidden;color:var(--admin-muted);font-size:.74rem;font-weight:400;text-overflow:ellipsis;white-space:nowrap}
      #${rootId} .admin-list-side{display:flex;align-items:center;justify-content:flex-end;gap:.4rem;min-width:0}
      #${rootId} .admin-icon-button{display:inline-grid;place-items:center;width:2rem;min-width:2rem;height:2rem;border:1px solid var(--admin-border);border-radius:.5rem;background:#fff;color:var(--admin-text);cursor:pointer}
      #${rootId} .admin-icon-button:hover{border-color:rgb(var(--theme-primary) / .45);background:#fff;color:var(--admin-primary)}
      #${rootId} .admin-modal-backdrop{position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;background:rgba(15,23,42,.45);padding:1rem}
      #${rootId} .admin-modal{width:min(46rem,100%);max-height:min(90vh,48rem);display:grid;grid-template-rows:auto minmax(0,1fr);overflow:hidden;border:1px solid var(--admin-border);border-radius:.75rem;background:#fff;box-shadow:0 24px 80px rgba(15,23,42,.25)}
      #${rootId} .admin-modal-wide{width:min(58rem,100%)}
      #${rootId} .admin-modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--admin-border);padding:1rem}
      #${rootId} .admin-modal-title{margin:0;color:var(--admin-text);font-size:1.08rem;font-weight:600}
      #${rootId} .admin-modal-subtitle{margin:.22rem 0 0;color:var(--admin-muted);font-size:.78rem;font-weight:400}
      #${rootId} .admin-modal-body{min-height:0;overflow:auto;padding:1rem;background:#fafafa}
      #${rootId} .admin-modal-body .admin-row{box-shadow:none}
      #${rootId} .admin-role-profile{display:grid;gap:.18rem;border:1px solid rgb(var(--theme-primary) / .16);border-radius:.55rem;background:var(--admin-primary-soft);padding:.78rem .85rem}
      #${rootId} .admin-role-profile strong{color:var(--admin-text);font-size:.9rem;font-weight:600}
      #${rootId} .admin-role-profile span{color:var(--admin-muted);font-size:.76rem;font-weight:400}
      #${rootId} .admin-access-layout{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.8rem}
      #${rootId} .admin-access-box{display:grid;grid-template-rows:auto minmax(0,1fr);min-height:18rem;border:1px solid var(--admin-border);border-radius:.55rem;background:#fff;overflow:hidden}
      #${rootId} .admin-access-box-head{border-bottom:1px solid var(--admin-border);background:var(--admin-soft);padding:.72rem .8rem}
      #${rootId} .admin-access-box-head strong{display:block;color:var(--admin-text);font-size:.86rem;font-weight:600}
      #${rootId} .admin-access-box-head span{display:block;margin-top:.12rem;color:var(--admin-muted);font-size:.7rem;font-weight:400}
      #${rootId} .admin-check-list{display:grid;align-content:start;gap:.35rem;max-height:22rem;overflow:auto;padding:.65rem}
      #${rootId} .admin-access-group{display:grid;gap:.35rem}
      #${rootId} .admin-access-group-title{margin:.3rem 0 .1rem;color:var(--admin-muted);font-size:.68rem;font-weight:600;text-transform:uppercase}
      #${rootId} .admin-access-check{display:grid;grid-template-columns:1rem minmax(0,1fr);align-items:start;gap:.45rem;border:1px solid transparent;border-radius:.5rem;padding:.42rem;color:var(--admin-text);text-transform:none}
      #${rootId} .admin-access-check:hover{border-color:rgb(var(--theme-primary) / .18);background:var(--admin-primary-soft)}
      #${rootId} .admin-access-check input{width:1rem;height:1rem;margin:.12rem 0 0;padding:0}
      #${rootId} .admin-access-check strong{display:block;overflow:hidden;font-size:.78rem;font-weight:600;line-height:1.15;text-overflow:ellipsis;white-space:nowrap}
      #${rootId} .admin-access-check span{display:block;margin-top:.1rem;overflow:hidden;color:var(--admin-muted);font-size:.68rem;font-weight:400;text-overflow:ellipsis;white-space:nowrap}
      #${rootId} .admin-overview-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}
      #${rootId} .admin-overview-list{display:grid;gap:.65rem}
      #${rootId} .admin-overview-link{display:grid;grid-template-columns:2.3rem minmax(0,1fr) auto;align-items:center;gap:.7rem;border:1px solid var(--admin-border);border-radius:.55rem;background:#fff;padding:.72rem;text-align:left;color:var(--admin-text);font:inherit;cursor:pointer}
      #${rootId} .admin-overview-link:hover{background:var(--admin-primary-soft);border-color:rgb(var(--theme-primary) / .22)}
      #${rootId} .admin-overview-link-icon{display:grid;place-items:center;width:2.3rem;height:2.3rem;border-radius:.55rem;background:var(--admin-primary-soft);color:var(--admin-primary)}
      #${rootId} .admin-overview-link strong{display:block;font-size:.88rem;font-weight:600}
      #${rootId} .admin-overview-copy > span{display:block;margin-top:.12rem;color:var(--admin-muted);font-size:.72rem;font-weight:400}
      #${rootId} .admin-list-meta{display:flex;align-items:center;gap:.35rem;flex-wrap:wrap}
      .dark #${rootId}{--admin-border:var(--color-surface-700,#334155);--admin-text:#fff;--admin-muted:var(--color-secondary-400,#94a3b8);--admin-soft:var(--color-surface-800,#1e293b)}
      .dark #${rootId} .admin-card,.dark #${rootId} .admin-row,.dark #${rootId} .admin-button,.dark #${rootId} .admin-icon-button,.dark #${rootId} .admin-filter-card,.dark #${rootId} .admin-filter-all,.dark #${rootId} .admin-toolbar,.dark #${rootId} .admin-stat,.dark #${rootId} .admin-list-row,.dark #${rootId} .admin-menu-tree-row,.dark #${rootId} .admin-menu-tree-row-item .admin-list-icon,.dark #${rootId} .admin-modal,.dark #${rootId} .admin-overview-link,.dark #${rootId} input,.dark #${rootId} select,.dark #${rootId} textarea,.dark #${rootId} .admin-site-photo,.dark #${rootId} .admin-site-photo-preview,.dark #${rootId} .admin-password-panel{background:var(--color-surface-900,#0f172a);border-color:var(--admin-border)}
      .dark #${rootId} .admin-modal-body{background:var(--color-surface-800,#1e293b)}
      @media (max-width:1100px){#${rootId} .admin-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
      @media (max-width:900px){#${rootId} .admin-grid,#${rootId} .admin-grid-3,#${rootId} .admin-overview-grid,#${rootId} .admin-access-layout{grid-template-columns:1fr}}
      @media (max-width:700px){#${rootId} .admin-top,#${rootId} .admin-toolbar,#${rootId} .admin-filter-card{display:grid}#${rootId} .admin-title h1{font-size:1.55rem}#${rootId} .admin-actions{display:grid;grid-template-columns:1fr 1fr}#${rootId} .admin-search,#${rootId} .admin-filter-control{min-width:0;width:100%;max-width:none}#${rootId} .admin-filter-count{margin-left:0}#${rootId} .admin-list-row{grid-template-columns:2.5rem minmax(0,1fr);align-items:start}#${rootId} .admin-list-row.is-draggable,#${rootId} .admin-menu-tree-row{grid-template-columns:1.8rem 2.5rem minmax(0,1fr)}#${rootId} .admin-list-side{grid-column:1 / -1;justify-content:space-between}#${rootId} .admin-menu-tree-row .admin-list-side{padding-left:calc(4.1rem + var(--menu-indent))}}
      @media (max-width:520px){#${rootId} .admin-stats{grid-template-columns:1fr}#${rootId} .admin-site-photo{grid-template-columns:1fr}#${rootId} .admin-site-photo-preview{width:100%;max-width:14rem}#${rootId} .admin-actions{grid-template-columns:1fr}}
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
      <section class="admin-shell">
        <div class="admin-top">
          <div class="admin-title">
            <h1>Administration HUB</h1>
            <p>Réglages, accès, sites, modules et contenus sans quitter le HUB.</p>
          </div>
          <div class="admin-head-actions">
            <button class="admin-button admin-button-soft" type="button" data-tab="users">${icon("users")} Utilisateurs</button>
            <button class="admin-button admin-button-primary" type="button" data-tab="sites">${icon("category")} Sites</button>
          </div>
        </div>
        ${renderStats()}
        <div class="admin-workspace">
          <div class="admin-main">
            ${renderToolbar()}
            ${renderPanel()}
          </div>
        </div>
        ${renderModal()}
      </section>
    `;
  }

  function renderStats() {
    const data = state.data || {};
    const sites = data.sites || [];
    const modules = data.modules || [];
    const users = data.users || [];
    const pages = data.pages || [];

    return `
      <section class="admin-stats" aria-label="Synthèse administration">
        ${statCard("Sites actifs", sites.filter((site) => site.active !== false).length, `${sites.length} site(s)`, "category", "#2563eb")}
        ${statCard("Utilisateurs", users.filter((user) => user.active !== false).length, `${users.length} profil(s)`, "users", "var(--admin-primary)")}
        ${statCard("Modules actifs", modules.filter((module) => module.active !== false).length, `${modules.length} module(s)`, "package", "#0f766e")}
        ${statCard("Pages visibles", pages.filter((page) => page.active !== false && page.showInMenu !== false).length, `${pages.length} page(s)`, "article", "#f59e0b")}
      </section>
    `;
  }

  function statCard(label, value, detail, iconKey, color) {
    return `
      <article class="admin-stat" style="--stat-color:${esc(color)}">
        <span class="admin-stat-icon">${icon(iconKey)}</span>
        <span>
          <span>${esc(label)}</span>
          <strong>${esc(value)}</strong>
          <small>${esc(detail)}</small>
        </span>
      </article>
    `;
  }

  function renderToolbar() {
    const [key, label, detail] = adminSections().find(([section]) => section === state.tab) || adminSections()[0];

    return `
      <section class="admin-toolbar">
        <div class="admin-toolbar-copy">
          <strong>${esc(label)}</strong>
          <span>${esc(toolbarDescription(key, detail))}</span>
        </div>
        ${searchableTabs().includes(state.tab) ? `
          <label class="admin-search">
            ${icon("search")}
            <input data-admin-search value="${esc(state.search)}" placeholder="${esc(searchPlaceholder(key))}" autocomplete="off">
          </label>
        ` : ""}
      </section>
    `;
  }

  function renderPanel() {
    if (state.tab === "overview") return renderOverview();
    if (state.tab === "modules") return renderModules();
    if (state.tab === "sites") return renderSites();
    if (state.tab === "pages") return renderPages();
    if (state.tab === "users") return renderUsers();

    return renderMenu();
  }

  function toolbarDescription(key, fallback) {
    const descriptions = {
      overview: "Vue synthétique de l'administration et accès rapides.",
      users: "Identité, mot de passe, site principal, modules et permissions.",
      sites: "Coordonnées, horaires, couleur, photo et activation des sites.",
      modules: "Modules disponibles dans le HUB, routes et badges du menu.",
      menu: "Organisation du menu latéral affiché dans le HUB.",
      pages: "Pages internes, contenus et visibilité dans le menu.",
    };

    return descriptions[key] || fallback || "";
  }

  function searchableTabs() {
    return ["users", "sites", "modules", "pages"];
  }

  function searchPlaceholder(key) {
    return {
      users: "Rechercher un utilisateur",
      sites: "Rechercher un site",
      modules: "Rechercher un module",
      pages: "Rechercher une page",
    }[key] || "Rechercher";
  }

  function filteredRecords(records, fields) {
    const query = state.search.trim().toLowerCase();
    if (!query) return records;

    return records.filter((record) => fields.some((field) => String(record?.[field] || "").toLowerCase().includes(query)));
  }

  function renderOverview() {
    const inactiveUsers = (state.data?.users || []).filter((user) => user.active === false);
    const inactiveModules = (state.data?.modules || []).filter((module) => module.active === false);
    const hiddenPages = (state.data?.pages || []).filter((page) => page.active === false || page.showInMenu === false);

    return `
      <section class="admin-overview-grid">
        <div class="admin-card">
          <header class="admin-card-header">
            <div>
              <h2 class="admin-card-title">Actions rapides</h2>
              <p class="admin-card-subtitle">Accédez directement aux réglages les plus fréquents.</p>
            </div>
          </header>
          <div class="admin-card-body admin-overview-list">
            ${overviewLink("users", "Gérer les utilisateurs", "Créer, activer, attribuer les sites et droits", "users")}
            ${overviewLink("sites", "Gérer les sites", "Adresse, téléphone, horaires, couleur et photo", "category")}
            ${overviewLink("modules", "Gérer les modules", "Routes, badges et visibilité dans le HUB", "package")}
            ${overviewLink("menu", "Organiser la navigation", "Sections, pages, sous-pages, icônes et ordre d’affichage", "settings")}
          </div>
        </div>
        <div class="admin-card">
          <header class="admin-card-header">
            <div>
              <h2 class="admin-card-title">À surveiller</h2>
              <p class="admin-card-subtitle">Éléments masqués ou désactivés.</p>
            </div>
          </header>
          <div class="admin-card-body admin-overview-list">
            ${overviewStatus("Utilisateurs inactifs", inactiveUsers.length, inactiveUsers.map((user) => user.name).slice(0, 4).join(", "), "users")}
            ${overviewStatus("Modules masqués", inactiveModules.length, inactiveModules.map((module) => module.name).slice(0, 4).join(", "), "modules")}
            ${overviewStatus("Pages non visibles", hiddenPages.length, hiddenPages.map((page) => page.title).slice(0, 4).join(", "), "pages")}
          </div>
        </div>
      </section>
    `;
  }

  function overviewLink(tab, title, detail, iconKey) {
    return `
      <button class="admin-overview-link" type="button" data-tab="${esc(tab)}">
        <span class="admin-overview-link-icon">${icon(iconKey)}</span>
        <span class="admin-overview-copy"><strong>${esc(title)}</strong><span>${esc(detail)}</span></span>
        ${icon("chevron")}
      </button>
    `;
  }

  function overviewStatus(label, count, detail, tab) {
    return `
      <button class="admin-overview-link" type="button" data-tab="${esc(tab)}">
        <span class="admin-overview-link-icon">${icon(tab === "modules" ? "package" : tab === "pages" ? "article" : "users")}</span>
        <span class="admin-overview-copy"><strong>${esc(label)} · ${esc(count)}</strong><span>${esc(detail || "Aucun élément à corriger")}</span></span>
        ${icon("chevron")}
      </button>
    `;
  }

  function renderListCard(title, subtitle, action, rows, emptyMessage, options = {}) {
    const listAttrs = options.dragType ? ` data-menu-drag-list="${esc(options.dragType)}"` : "";

    return `
      <section class="admin-card admin-list-card">
        <header class="admin-card-header">
          <div><h2 class="admin-card-title">${esc(title)}</h2><p class="admin-card-subtitle">${esc(subtitle)}</p></div>
          ${action || ""}
        </header>
        <div class="admin-card-body">
          ${rows.length ? `<div class="admin-list"${listAttrs}>${rows.join("")}</div>` : emptyState(emptyMessage)}
        </div>
      </section>
    `;
  }

  function renderListRow({ type, id, title, subtitle, iconKey, iconHtml, meta = "", actions = "", dragType = "", dragId = "" }) {
    const draggable = dragType && dragId;
    const dragAttrs = draggable ? ` draggable="true" data-menu-drag-row data-menu-drag-type="${esc(dragType)}" data-menu-drag-id="${esc(dragId)}"` : "";
    const dragHandle = draggable ? `<span class="admin-drag-handle" title="Glisser pour changer l'ordre">${icon("grip")}</span>` : "";

    return `
      <article class="admin-list-row${draggable ? " is-draggable" : ""}"${dragAttrs}>
        ${dragHandle}
        <span class="admin-list-icon">${iconHtml || icon(iconKey || "category")}</span>
        <span class="admin-list-main">
          <strong class="admin-list-title">${esc(title)}</strong>
          <span class="admin-list-subtitle">${esc(subtitle || "Aucune information complémentaire")}</span>
        </span>
        <span class="admin-list-side">
          ${meta}
          ${actions}
          <button class="admin-icon-button" type="button" data-edit-type="${esc(type)}" data-edit-id="${esc(id)}" aria-label="Modifier ${esc(title)}" title="Modifier">${icon("edit")}</button>
        </span>
      </article>
    `;
  }

  function statusPill(active, activeLabel = "Actif", hiddenLabel = "Masqué") {
    return `<span class="admin-pill${active ? " is-active" : " is-hidden"}">${active ? activeLabel : hiddenLabel}</span>`;
  }

  function siteName(id) {
    const site = (state.data?.sites || []).find((item) => Number(item.id) === Number(id));

    return site?.name || "";
  }

  function roleLabel(roleKey) {
    const role = (state.data?.roles || []).find((item) => item.key === roleKey);

    return role?.label || roleKey || "Rôle";
  }

  function roleProfile(roleKey) {
    return (state.data?.roles || []).find((item) => item.key === roleKey) || null;
  }

  function groupedPermissions(permissions) {
    const groups = new Map();

    permissions.forEach((permission) => {
      const key = permission.group || "Autres";
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(permission);
    });

    return Array.from(groups.entries());
  }

  function menuGroupTitle(groupKey) {
    const group = (state.data?.menuGroups || []).find((item) => item.menuKey === groupKey);

    return group?.title || groupKey || "Section";
  }

  function sortMenuRecords(records, labelKey) {
    return [...records].sort((first, second) => {
      const order = Number(first?.sortOrder || 0) - Number(second?.sortOrder || 0);
      if (order !== 0) return order;

      return String(first?.[labelKey] || "").localeCompare(String(second?.[labelKey] || ""), "fr");
    });
  }

  function menuItemParentKey(item) {
    return String(item?.parentItemKey || "");
  }

  function menuItemsForGroup(groupKey, parentItemKey = "") {
    return sortMenuRecords(
      (state.data?.menuItems || []).filter((item) => item.groupKey === groupKey && menuItemParentKey(item) === parentItemKey),
      "label",
    );
  }

  function menuChildItems(parentItemKey) {
    return sortMenuRecords(
      (state.data?.menuItems || []).filter((item) => menuItemParentKey(item) === parentItemKey),
      "label",
    );
  }

  function menuParentOptions(currentItemKey) {
    return sortMenuRecords(
      (state.data?.menuItems || []).filter((item) => item.itemKey !== currentItemKey && !menuItemParentKey(item)),
      "label",
    );
  }

  function renderMenuTreeRow({ rowType, type, id, title, subtitle, iconKey, meta = "", depth = 0, dragType = "", dragId = "", parentGroupKey = "", parentItemKey = "" }) {
    const indent = `${Math.max(0, Number(depth) || 0) * 2.35}rem`;
    const draggable = dragType && dragId;
    const dragAttrs = draggable
      ? ` draggable="true" data-menu-drag-row data-menu-drag-type="${esc(dragType)}" data-menu-drag-id="${esc(dragId)}" data-menu-parent-group="${esc(parentGroupKey)}" data-menu-parent-item="${esc(parentItemKey)}"`
      : "";
    const dragHandle = draggable ? `<span class="admin-drag-handle" title="Glisser pour changer l'ordre">${icon("grip")}</span>` : "";

    return `
      <article class="admin-menu-tree-row admin-menu-tree-row-${esc(rowType)}${draggable ? " is-draggable" : ""}" style="--menu-indent:${esc(indent)}"${dragAttrs}>
        ${dragHandle}
        <span class="admin-list-icon">${icon(iconKey || "category")}</span>
        <span class="admin-list-main">
          <strong class="admin-list-title">${esc(title)}</strong>
          <span class="admin-list-subtitle">${esc(subtitle || "Aucune information complémentaire")}</span>
        </span>
        <span class="admin-list-side">
          ${meta}
          <button class="admin-icon-button" type="button" data-edit-type="${esc(type)}" data-edit-id="${esc(id)}" aria-label="Modifier ${esc(title)}" title="Modifier">${icon("edit")}</button>
        </span>
      </article>
    `;
  }

  function renderMenu() {
    const groups = sortMenuRecords(state.data?.menuGroups || [], "title");
    const groupKeys = new Set(groups.map((group) => group.menuKey));
    const rows = [];

    groups.forEach((group) => {
      const children = menuItemsForGroup(group.menuKey);

      rows.push(renderMenuTreeRow({
        rowType: "group",
        type: "menu-group",
        id: group.menuKey,
        title: group.title,
        subtitle: `${group.menuKey} · ${children.length} page(s)`,
        iconKey: "settings",
        meta: statusPill(group.active !== false, "Visible", "Masqué"),
          dragType: "menu-group",
          dragId: group.menuKey,
          parentGroupKey: group.menuKey,
      }));

      const appendItemRows = (item, depth = 1) => {
        const subItems = menuChildItems(item.itemKey);

        rows.push(renderMenuTreeRow({
          rowType: depth > 1 ? "subitem" : "item",
          type: "menu-item",
          id: item.itemKey,
          title: item.label,
          subtitle: menuItemParentKey(item) ? `${menuGroupTitle(item.groupKey)} · sous-page de ${menuItemParentKey(item)}` : item.itemKey,
          iconKey: item.iconKey || "category",
          meta: `${statusPill(item.active !== false, "Visible", "Masqué")}${subItems.length ? `<span class="admin-pill">${esc(subItems.length)} sous-page(s)</span>` : ""}<span class="admin-pill">ordre ${esc(item.sortOrder)}</span>`,
          depth,
          dragType: "menu-item",
          dragId: item.itemKey,
          parentGroupKey: item.groupKey,
          parentItemKey: menuItemParentKey(item),
        }));

        subItems.forEach((subItem) => appendItemRows(subItem, depth + 1));
      };

      children.forEach((item) => appendItemRows(item));
    });

    sortMenuRecords((state.data?.menuItems || []).filter((item) => !groupKeys.has(item.groupKey) && !menuItemParentKey(item)), "label").forEach((item) => {
      rows.push(renderMenuTreeRow({
        rowType: "item",
        type: "menu-item",
        id: item.itemKey,
        title: item.label,
        subtitle: `Sans section · ${item.itemKey}`,
        iconKey: item.iconKey || "category",
        meta: `${statusPill(item.active !== false, "Visible", "Masqué")}<span class="admin-pill">ordre ${esc(item.sortOrder)}</span>`,
        dragType: "menu-item",
        dragId: item.itemKey,
        parentGroupKey: item.groupKey,
      }));
    });

    return `
      <section class="admin-card admin-list-card admin-menu-tree-card">
        <header class="admin-card-header">
          <div>
            <h2 class="admin-card-title">Structure de la navigation</h2>
            <p class="admin-card-subtitle">Glissez les sections, pages et sous-pages pour organiser la navigation du HUB.</p>
          </div>
          <span class="admin-list-meta">
            <span class="admin-pill">${esc(groups.length)} sections</span>
            <span class="admin-pill">${esc(state.data?.menuItems?.length || 0)} pages</span>
          </span>
        </header>
        <div class="admin-card-body">
          ${rows.length ? `<div class="admin-list admin-menu-tree" data-menu-drag-list="menu-tree">${rows.join("")}</div>` : emptyState("Aucun élément de menu trouvé.")}
        </div>
      </section>
    `;
  }

  function renderMenuGroupForm(group) {
    return `
      <form class="admin-row admin-edit-form" data-menu-group-form data-menu-group="${esc(group.menuKey)}">
        <div class="admin-row-title"><strong>${esc(group.title)}</strong><span>${esc(group.menuKey)}</span></div>
        <div class="admin-grid">
          <label>Titre <input name="title" value="${esc(group.title)}"></label>
          <label>Ordre <input name="sortOrder" type="number" value="${esc(group.sortOrder)}"></label>
        </div>
        <label class="admin-check"><input name="active" type="checkbox"${group.active !== false ? " checked" : ""}> Visible</label>
        <div class="admin-actions">
          <button class="admin-button" type="button" data-close-admin-modal>Annuler</button>
          <button class="admin-button admin-button-primary" type="submit">${state.saving === "menu" ? "Enregistrement..." : "Enregistrer"}</button>
        </div>
      </form>
    `;
  }

  function renderMenuItemForm(item, groups) {
    const parentOptions = menuParentOptions(item.itemKey);

    return `
      <form class="admin-row admin-edit-form" data-menu-item-form data-menu-item="${esc(item.itemKey)}">
        <div class="admin-row-title"><strong>${esc(item.label)}</strong><span class="admin-icon-preview">${icon(item.iconKey)}</span></div>
        <div class="admin-grid-3">
          <label>Nom <input name="label" value="${esc(item.label)}"></label>
          <label>Section
            <select name="groupKey">
              ${groups.map((group) => `<option value="${esc(group.menuKey)}"${group.menuKey === item.groupKey ? " selected" : ""}>${esc(group.title)}</option>`).join("")}
            </select>
          </label>
          <label>Page parente
            <select name="parentItemKey">
              <option value="">Niveau principal</option>
              ${parentOptions.map((parent) => `<option value="${esc(parent.itemKey)}"${parent.itemKey === item.parentItemKey ? " selected" : ""}>${esc(parent.label)} · ${esc(menuGroupTitle(parent.groupKey))}</option>`).join("")}
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
        <div class="admin-actions">
          <button class="admin-button" type="button" data-close-admin-modal>Annuler</button>
          <button class="admin-button admin-button-primary" type="submit">${state.saving === "menu" ? "Enregistrement..." : "Enregistrer"}</button>
        </div>
      </form>
    `;
  }

  function renderModules() {
    const modules = filteredRecords(state.data?.modules || [], ["name", "slug", "routePath", "menuBadge"]);
    const rows = modules.map((module) => renderListRow({
      type: "module",
      id: module.id,
      title: module.name,
      subtitle: `${module.routePath || "Route non renseignée"} · ${module.description || module.slug}`,
      iconKey: moduleIcon(module),
      meta: `${statusPill(module.active !== false, "Actif", "Masqué")}<span class="admin-pill">${esc(module.slug)}</span>${module.menuBadge ? `<span class="admin-pill">${esc(module.menuBadge)}</span>` : ""}`,
    }));

    return renderListCard("Liste des modules", "Titre, route, badge et visibilité. Cliquez sur le crayon pour modifier.", "", rows, "Aucun module trouvé.");
  }

  function renderModuleForm(module) {
    return `
      <form class="admin-row" data-module-form>
        <input type="hidden" name="id" value="${esc(module.id)}">
        <div class="admin-row-title">
          <strong>${esc(module.name)}</strong>
          <span class="admin-list-meta"><span class="admin-pill${module.active !== false ? " is-active" : " is-hidden"}">${module.active !== false ? "Actif" : "Masqué"}</span><span class="admin-pill">${esc(module.slug)}</span></span>
        </div>
        <div class="admin-grid-3">
          <label>Nom <input name="name" value="${esc(module.name)}" required></label>
          <label>Slug <input name="slug" value="${esc(module.slug)}" required></label>
          <label>Route <input name="routePath" value="${esc(module.routePath)}" required></label>
          <label>Badge <input name="menuBadge" value="${esc(module.menuBadge)}"></label>
          <label>Ordre <input name="sortOrder" type="number" value="${esc(module.sortOrder)}"></label>
          <label class="admin-check"><input name="active" type="checkbox"${module.active !== false ? " checked" : ""}> Actif</label>
          <label class="admin-check"><input name="showMenuBadge" type="checkbox"${module.showMenuBadge ? " checked" : ""}> Afficher badge</label>
        </div>
        <div class="admin-actions">
          <button class="admin-button" type="button" data-close-admin-modal>Annuler</button>
          <button class="admin-button admin-button-primary" type="submit">Enregistrer</button>
        </div>
      </form>
    `;
  }

  function renderSites() {
    const sites = filteredRecords(state.data?.sites || [], ["name", "address", "phone", "email"]);
    const rows = sites.map((site) => {
      const color = validHexColor(site.color) || defaultSiteColor();
      const photo = site.photoUrl ? `<img src="${esc(site.photoUrl)}" alt="">` : `<i class="admin-site-swatch" style="--site-color:${esc(color)}" aria-hidden="true"></i>`;

      return renderListRow({
        type: "site",
        id: site.id || "new",
        title: site.name || "Nouveau site",
        subtitle: [site.address || "Adresse non renseignée", site.phone || "Téléphone non renseigné", site.email || "E-mail non renseigné"].join(" · "),
        iconHtml: photo,
        meta: `${statusPill(site.active !== false, "Actif", "Masqué")}<span class="admin-pill">${esc(site.slug || "nouveau")}</span>`,
      });
    });

    return renderListCard(
      "Liste des sites",
      "Coordonnées, horaires, couleur, photo et activation.",
      `<button class="admin-button admin-button-primary" type="button" data-new-site>${icon("plus")} Créer un site</button>`,
      rows,
      "Aucun site trouvé.",
    );
  }

  function renderSiteForm(site) {
    const hours = site.hours || {};
    const color = validHexColor(site.color) || defaultSiteColor();

    return `
      <form class="admin-row" data-site-form>
        <input type="hidden" name="id" value="${esc(site.id || "")}">
        <div class="admin-row-title">
          <span class="admin-site-heading" style="--site-color:${esc(color)}"><i class="admin-site-swatch" aria-hidden="true"></i><strong>${esc(site.name || "Nouveau site")}</strong></span>
          <span class="admin-pill${site.active === false ? " is-hidden" : " is-active"}">${site.active === false ? "Masqué" : "Actif"}</span>
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
          <button class="admin-button" type="button" data-close-admin-modal>Annuler</button>
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
    const pages = filteredRecords(state.data?.pages || [], ["title", "slug", "excerpt"]);
    const rows = pages.map((page) => renderListRow({
      type: "page",
      id: page.id || "new",
      title: page.title || "Nouvelle page",
      subtitle: page.excerpt || page.routePath || "Contenu interne HUB",
      iconKey: page.iconKey || "article",
      meta: `${statusPill(page.active !== false, "Active", "Masquée")}<span class="admin-pill">${page.showInMenu !== false ? "Menu" : "Hors menu"}</span><span class="admin-pill">${esc(page.slug || "nouvelle")}</span>`,
    }));

    return renderListCard(
      "Liste des pages",
      "Pages visibles dans le menu et contenus internes.",
      `<button class="admin-button admin-button-primary" type="button" data-new-page>${icon("plus")} Créer une page</button>`,
      rows,
      "Aucune page trouvée.",
    );
  }

  function renderPageForm(page) {
    return `
      <form class="admin-row" data-page-form>
        <input type="hidden" name="id" value="${esc(page.id || "")}">
        <div class="admin-row-title">
          <strong>${esc(page.title || "Nouvelle page")}</strong>
          <span class="admin-list-meta"><span class="admin-pill${page.active !== false ? " is-active" : " is-hidden"}">${page.active !== false ? "Active" : "Masquée"}</span><span class="admin-pill">${page.showInMenu !== false ? "Menu" : "Hors menu"}</span></span>
        </div>
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
          <button class="admin-button" type="button" data-close-admin-modal>Annuler</button>
          <button class="admin-button admin-button-primary" type="submit">Enregistrer</button>
          ${page.id ? `<button class="admin-button admin-button-danger" type="button" data-delete-page="${esc(page.id)}">Supprimer</button>` : ""}
        </div>
      </form>
    `;
  }

  function renderUsers() {
    const allUsers = state.data?.users || [];
    const searchedUsers = filteredRecords(allUsers, ["name", "email", "phone", "role"]);
    const users = filterUsers(searchedUsers);
    const rows = users.map((user) => {
      const primarySite = siteName(user.primarySiteId);
      const siteCount = Array.isArray(user.siteIds) ? user.siteIds.length : 0;
      const roleAction = canManageRoles()
        ? `<button class="admin-icon-button" type="button" data-edit-type="user-roles" data-edit-id="${esc(user.id)}" aria-label="Gérer les rôles de ${esc(user.name)}" title="Rôles et accès">${icon("shield")}</button>`
        : "";

      return renderListRow({
        type: "user",
        id: user.id,
        title: user.name,
        subtitle: [user.email || "E-mail non renseigné", user.phone || "Téléphone non renseigné", primarySite || "Aucun site principal"].join(" · "),
        iconKey: "users",
        meta: `${statusPill(user.active !== false, "Actif", "Inactif")}<span class="admin-pill">${esc(roleLabel(user.role))}</span><span class="admin-pill">${esc(siteCount)} site(s)</span>`,
        actions: roleAction,
      });
    });

    return `
      ${renderUserFilters(users.length, allUsers.length)}
      ${renderListCard("Liste des utilisateurs", "Identité, rôle, sites et accès. Le bouclier ouvre les droits détaillés.", "", rows, "Aucun utilisateur trouvé.")}
    `;
  }

  function renderUserFilters(visibleCount, totalCount) {
    const filters = normalizedUserFilters();
    const isAll = filters.siteId === "all" && filters.role === "all";

    return `
      <section class="admin-filter-card" aria-label="Filtres utilisateurs">
        <button class="admin-filter-all${isAll ? " is-active" : ""}" type="button" data-user-filter-reset>
          ${icon("users")} Tous les utilisateurs <span>${esc(totalCount)}</span>
        </button>
        <label class="admin-filter-control">Site
          <select data-user-filter="siteId">
            <option value="all"${filters.siteId === "all" ? " selected" : ""}>Tous les sites</option>
            ${(state.data?.sites || []).map((site) => `<option value="${esc(site.id)}"${String(site.id) === filters.siteId ? " selected" : ""}>${esc(site.name)}</option>`).join("")}
          </select>
        </label>
        <label class="admin-filter-control">Rôle
          <select data-user-filter="role">
            <option value="all"${filters.role === "all" ? " selected" : ""}>Tous les rôles</option>
            ${(state.data?.roles || []).map((role) => `<option value="${esc(role.key)}"${role.key === filters.role ? " selected" : ""}>${esc(role.label)}</option>`).join("")}
          </select>
        </label>
        <span class="admin-filter-count">${esc(visibleCount)} affiché(s)</span>
      </section>
    `;
  }

  function filterUsers(users) {
    const filters = normalizedUserFilters();

    return users.filter((user) => {
      const siteMatches = filters.siteId === "all" || (Array.isArray(user.siteIds) && user.siteIds.some((siteId) => String(siteId) === filters.siteId));
      const roleMatches = filters.role === "all" || user.role === filters.role;

      return siteMatches && roleMatches;
    });
  }

  function normalizedUserFilters() {
    return {
      siteId: String(state.userFilters?.siteId || "all"),
      role: String(state.userFilters?.role || "all"),
    };
  }

  function renderUserForm(user) {
    return `
      <form class="admin-row" data-user-form>
        <input type="hidden" name="id" value="${esc(user.id)}">
        <input type="hidden" name="siteIds" value="${esc(JSON.stringify(user.siteIds || []))}">
        <input type="hidden" name="moduleIds" value="${esc(JSON.stringify(user.moduleIds || []))}">
        <input type="hidden" name="permissionIds" value="${esc(JSON.stringify(user.permissionIds || []))}">
        <input type="hidden" name="accessRules" value="${esc(JSON.stringify(user.accessRules || []))}">
        <div class="admin-row-title">
          <strong>${esc(user.name)}</strong>
          <span class="admin-list-meta"><span class="admin-pill${user.active !== false ? " is-active" : " is-hidden"}">${user.active !== false ? "Actif" : "Inactif"}</span><span class="admin-pill">${esc(user.role)}</span></span>
        </div>
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
        <div class="admin-actions">
          <button class="admin-button" type="button" data-close-admin-modal>Annuler</button>
          <button class="admin-button admin-button-primary" type="submit">Enregistrer</button>
        </div>
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

  function renderUserRolesForm(user) {
    const active = user.active !== false;

    return `
      <form class="admin-row" data-user-roles-form>
        <input type="hidden" name="id" value="${esc(user.id)}">
        <input type="hidden" name="name" value="${esc(user.name)}">
        <input type="hidden" name="email" value="${esc(user.email || "")}">
        <input type="hidden" name="phone" value="${esc(user.phone || "")}">
        <input type="hidden" name="active" value="${active ? "1" : "0"}">
        <input type="hidden" name="accessRules" value="${esc(JSON.stringify(user.accessRules || []))}">
        <div class="admin-row-title">
          <strong>${esc(user.name)}</strong>
          <span class="admin-list-meta"><span class="admin-pill${active ? " is-active" : " is-hidden"}">${active ? "Actif" : "Inactif"}</span><span class="admin-pill">${esc(roleLabel(user.role))}</span></span>
        </div>
        <div class="admin-grid">
          <label>Profil de rôle
            <select name="role" data-role-select>
              ${(state.data?.roles || []).map((role) => `<option value="${esc(role.key)}"${role.key === user.role ? " selected" : ""}>${esc(role.label)}</option>`).join("")}
            </select>
          </label>
          <label>Site principal
            <select name="primarySiteId">
              ${(state.data?.sites || []).map((site) => `<option value="${esc(site.id)}"${Number(site.id) === Number(user.primarySiteId) ? " selected" : ""}>${esc(site.name)}</option>`).join("")}
            </select>
          </label>
        </div>
        ${renderRoleProfileHint(user.role)}
        <div class="admin-actions">
          <button class="admin-button admin-button-soft" type="button" data-apply-role-profile>${icon("checklist")} Appliquer le profil</button>
        </div>
        <div class="admin-access-layout">
          ${renderAccessBox("Sites autorisés", "Sites visibles par ce membre.", renderSiteAccessChecks(user))}
          ${renderAccessBox("Modules autorisés", "Modules affichés dans le HUB.", renderModuleAccessChecks(user))}
          ${renderAccessBox("Permissions", "Actions autorisées dans les modules.", renderPermissionAccessChecks(user))}
        </div>
        <div class="admin-actions">
          <button class="admin-button" type="button" data-close-admin-modal>Annuler</button>
          <button class="admin-button admin-button-primary" type="submit">Enregistrer les rôles</button>
        </div>
      </form>
    `;
  }

  function renderRoleProfileHint(roleKey) {
    const profile = roleProfile(roleKey);

    return `
      <div class="admin-role-profile" data-role-profile-help>
        <strong>${esc(profile?.label || "Profil personnalisé")}</strong>
        <span>${esc(profile?.description || "Ajustez les accès ci-dessous pour créer un profil sur mesure.")}</span>
      </div>
    `;
  }

  function renderAccessBox(title, subtitle, content) {
    return `
      <section class="admin-access-box">
        <header class="admin-access-box-head">
          <strong>${esc(title)}</strong>
          <span>${esc(subtitle)}</span>
        </header>
        <div class="admin-check-list">
          ${content || emptyState("Aucun élément disponible.")}
        </div>
      </section>
    `;
  }

  function renderSiteAccessChecks(user) {
    const selected = new Set((user.siteIds || []).map((id) => Number(id)));

    return (state.data?.sites || []).map((site) => accessCheck({
      name: "siteIds",
      value: site.id,
      title: site.name,
      subtitle: site.active === false ? "Site masqué" : (site.address || site.slug || "Site HUB"),
      checked: selected.has(Number(site.id)),
    })).join("");
  }

  function renderModuleAccessChecks(user) {
    const selected = new Set((user.moduleIds || []).map((id) => Number(id)));

    return (state.data?.modules || []).map((module) => accessCheck({
      name: "moduleIds",
      value: module.id,
      title: module.name,
      subtitle: module.active === false ? `${module.slug} · masqué` : module.slug,
      checked: selected.has(Number(module.id)),
    })).join("");
  }

  function renderPermissionAccessChecks(user) {
    const selected = new Set((user.permissionIds || []).map((id) => Number(id)));
    const groups = groupedPermissions(state.data?.permissions || []);

    return groups.map(([group, permissions]) => `
      <div class="admin-access-group">
        <strong class="admin-access-group-title">${esc(group || "Autres")}</strong>
        ${permissions.map((permission) => accessCheck({
          name: "permissionIds",
          value: permission.id,
          title: permission.label || permission.name,
          subtitle: permission.name,
          checked: selected.has(Number(permission.id)),
        })).join("")}
      </div>
    `).join("");
  }

  function accessCheck({ name, value, title, subtitle, checked }) {
    return `
      <label class="admin-access-check">
        <input type="checkbox" name="${esc(name)}" value="${esc(value)}"${checked ? " checked" : ""}>
        <span><strong>${esc(title)}</strong><span>${esc(subtitle || "")}</span></span>
      </label>
    `;
  }

  function renderModal() {
    if (!state.editing) return "";

    const modal = modalContent(state.editing);
    if (!modal) return "";

    return `
      <div class="admin-modal-backdrop" data-admin-modal-backdrop>
        <section class="admin-modal${modal.wide ? " admin-modal-wide" : ""}" role="dialog" aria-modal="true" aria-label="${esc(modal.title)}">
          <header class="admin-modal-header">
            <div>
              <h2 class="admin-modal-title">${esc(modal.title)}</h2>
              <p class="admin-modal-subtitle">${esc(modal.subtitle)}</p>
            </div>
            <button class="admin-icon-button" type="button" data-close-admin-modal aria-label="Fermer">${icon("close")}</button>
          </header>
          <div class="admin-modal-body">
            ${modal.body}
          </div>
        </section>
      </div>
    `;
  }

  function modalContent(editing) {
    const type = editing.type;
    const record = findEditableRecord(type, editing.id);
    if (!record) return null;

    if (type === "user") {
      return { title: "Modifier l'utilisateur", subtitle: record.name, body: renderUserForm(record) };
    }

    if (type === "user-roles") {
      return { title: "Rôles et accès", subtitle: record.name, body: renderUserRolesForm(record), wide: true };
    }

    if (type === "site") {
      return { title: record.id ? "Modifier le site" : "Créer un site", subtitle: record.name || "Nouveau site", body: renderSiteForm(record) };
    }

    if (type === "module") {
      return { title: "Modifier le module", subtitle: record.name, body: renderModuleForm(record) };
    }

    if (type === "page") {
      return { title: record.id ? "Modifier la page" : "Créer une page", subtitle: record.title || "Nouvelle page", body: renderPageForm(record) };
    }

    if (type === "menu-group") {
      return { title: "Modifier la section", subtitle: record.title, body: renderMenuGroupForm(record) };
    }

    if (type === "menu-item") {
      return { title: "Modifier la page", subtitle: record.label, body: renderMenuItemForm(record, state.data?.menuGroups || []) };
    }

    return null;
  }

  function findEditableRecord(type, id) {
    const sourceType = type === "user-roles" ? "user" : type;
    const sources = {
      module: state.data?.modules || [],
      page: state.data?.pages || [],
      site: state.data?.sites || [],
      user: state.data?.users || [],
      "menu-group": state.data?.menuGroups || [],
      "menu-item": state.data?.menuItems || [],
    };
    const key = sourceType === "menu-group" ? "menuKey" : sourceType === "menu-item" ? "itemKey" : "id";
    const records = sources[sourceType] || [];

    if (id === "new") {
      return records.find((record) => !record.id) || null;
    }

    return records.find((record) => String(record?.[key]) === String(id)) || null;
  }

  function moduleIcon(module) {
    const iconBySlug = {
      conges: "calendar",
      dashboard: "dashboard",
      documents: "fileText",
      equipes: "users",
      "controle-caisse": "creditCard",
      "demandes-acompte": "banknote",
      "locations-materiel": "package",
      "remise-cheques": "creditCard",
      reservations: "truck",
      "tapis-romus": "ruler",
    };

    return iconBySlug[module.slug] || "category";
  }

  function emptyState(message) {
    return `<div class="admin-empty">${esc(message)}</div>`;
  }

  function bind(root) {
    root.querySelectorAll("[data-tab]").forEach((button) => {
      button.addEventListener("click", () => {
        openTab(button.dataset.tab);
      });
    });

    root.querySelector("[data-admin-search]")?.addEventListener("input", (event) => {
      state.search = event.currentTarget.value;
      render();
      const input = document.querySelector(`#${rootId} [data-admin-search]`);
      input?.focus();
      input?.setSelectionRange?.(state.search.length, state.search.length);
    });

    root.querySelectorAll("[data-edit-type]").forEach((button) => {
      button.addEventListener("click", () => openEditor(button.dataset.editType, button.dataset.editId));
    });
    root.querySelectorAll("[data-close-admin-modal]").forEach((button) => {
      button.addEventListener("click", closeEditor);
    });
    root.querySelector("[data-admin-modal-backdrop]")?.addEventListener("click", (event) => {
      if (event.target === event.currentTarget) closeEditor();
    });
    root.querySelectorAll("[data-menu-group-form]").forEach((form) => form.addEventListener("submit", saveMenuGroup));
    root.querySelectorAll("[data-menu-item-form]").forEach((form) => form.addEventListener("submit", saveMenuItem));
    root.querySelectorAll("[data-module-form]").forEach((form) => form.addEventListener("submit", saveModule));
    root.querySelectorAll("[data-site-form]").forEach((form) => form.addEventListener("submit", saveSite));
    root.querySelectorAll("[data-site-form]").forEach(bindSitePhotoForm);
    root.querySelectorAll("[data-page-form]").forEach((form) => form.addEventListener("submit", savePage));
    root.querySelectorAll("[data-user-form]").forEach((form) => form.addEventListener("submit", saveUser));
    root.querySelectorAll("[data-user-roles-form]").forEach((form) => form.addEventListener("submit", saveUserRoles));
    root.querySelectorAll("[data-role-select]").forEach((select) => {
      select.addEventListener("change", () => updateRoleProfileHint(select.closest("[data-user-roles-form]")));
    });
    root.querySelectorAll("[data-apply-role-profile]").forEach((button) => {
      button.addEventListener("click", () => applySelectedRoleProfile(button.closest("[data-user-roles-form]")));
    });
    root.querySelector("[data-user-filter-reset]")?.addEventListener("click", resetUserFilters);
    root.querySelectorAll("[data-user-filter]").forEach((filter) => {
      filter.addEventListener("change", () => setUserFilter(filter.dataset.userFilter, filter.value));
    });
    bindMenuDrag(root);
    root.querySelectorAll("[data-delete-site]").forEach((button) => button.addEventListener("click", deleteSite));
    root.querySelectorAll("[data-delete-page]").forEach((button) => button.addEventListener("click", deletePage));
    root.querySelector("[data-new-site]")?.addEventListener("click", () => {
      state.data.sites = [{ id: "", name: "", active: true, address: "", phone: "", email: "", color: defaultSiteColor(), photoUrl: "", hours: {} }, ...(state.data?.sites || [])];
      state.editing = { type: "site", id: "new" };
      render();
    });
    root.querySelector("[data-new-page]")?.addEventListener("click", () => {
      state.data.pages = [{ id: "", title: "", slug: "", excerpt: "", content: "", iconKey: "article", active: true, showInMenu: true, sortOrder: 100 }, ...(state.data?.pages || [])];
      state.editing = { type: "page", id: "new" };
      render();
    });
  }

  function resetUserFilters() {
    state.userFilters = { siteId: "all", role: "all" };
    render();
  }

  function setUserFilter(key, value) {
    state.userFilters = {
      ...normalizedUserFilters(),
      [key]: String(value || "all"),
    };
    render();
  }

  function bindMenuDrag(root) {
    root.querySelectorAll("[data-menu-drag-row]").forEach((row) => {
      row.addEventListener("dragstart", startMenuDrag);
      row.addEventListener("dragover", overMenuDrag);
      row.addEventListener("dragleave", leaveMenuDrag);
      row.addEventListener("drop", dropMenuDrag);
      row.addEventListener("dragend", endMenuDrag);
    });
  }

  function startMenuDrag(event) {
    if (event.target.closest("button,input,select,textarea,a")) {
      event.preventDefault();
      return;
    }

    const row = event.currentTarget;
    currentMenuDrag = {
      type: row.dataset.menuDragType,
      id: row.dataset.menuDragId,
      parentGroupKey: row.dataset.menuParentGroup || "",
    };
    row.classList.add("is-dragging");
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData("text/plain", `${currentMenuDrag.type}:${currentMenuDrag.id}`);
  }

  function overMenuDrag(event) {
    const row = event.currentTarget;
    if (!canDropMenuDrag(row)) return;

    event.preventDefault();
    event.dataTransfer.dropEffect = "move";
    markMenuDrop(row, menuDropPosition(event, row));
  }

  function leaveMenuDrag(event) {
    event.currentTarget.classList.remove("is-drop-before", "is-drop-after");
  }

  function dropMenuDrag(event) {
    const row = event.currentTarget;
    if (!canDropMenuDrag(row)) return;

    event.preventDefault();
    const position = menuDropPosition(event, row);
    const drag = currentMenuDrag;
    clearMenuDropMarkers(true);
    currentMenuDrag = null;
    reorderMenu(drag.type, drag.id, row.dataset.menuDragType, row.dataset.menuDragId, position);
  }

  function endMenuDrag() {
    currentMenuDrag = null;
    clearMenuDropMarkers(true);
  }

  function canDropMenuDrag(row) {
    if (!currentMenuDrag) return false;
    const targetType = row.dataset.menuDragType;
    const targetId = row.dataset.menuDragId;

    if (targetType === currentMenuDrag.type && targetId === currentMenuDrag.id) return false;
    if (currentMenuDrag.type === "menu-group") return targetType === "menu-group";
    if (currentMenuDrag.type === "menu-item") return targetType === "menu-item" || targetType === "menu-group";

    return false;
  }

  function menuDropPosition(event, row) {
    const rect = row.getBoundingClientRect();

    return event.clientY < rect.top + (rect.height / 2) ? "before" : "after";
  }

  function markMenuDrop(row, position) {
    clearMenuDropMarkers();
    row.classList.add(position === "before" ? "is-drop-before" : "is-drop-after");
  }

  function clearMenuDropMarkers(clearDragging = false) {
    document.querySelectorAll(`#${rootId} [data-menu-drag-row]`).forEach((row) => {
      row.classList.remove("is-drop-before", "is-drop-after");
      if (clearDragging) row.classList.remove("is-dragging");
    });
  }

  function openEditor(type, id) {
    state.editing = { type, id };
    render();
  }

  function closeEditor() {
    if (state.editing?.type === "site" && state.editing.id === "new") {
      state.data.sites = (state.data?.sites || []).filter((site) => site.id);
    }

    if (state.editing?.type === "page" && state.editing.id === "new") {
      state.data.pages = (state.data?.pages || []).filter((page) => page.id);
    }

    state.editing = null;
    render();
  }

  function openTab(tab) {
    const next = normalizeTab(tab);
    if (state.tab !== next) {
      state.search = "";
    }

    state.tab = next;

    const url = new URL(window.location.href);
    url.pathname = next === "overview" ? "/administration" : `/administration/${next}`;
    url.searchParams.delete("section");
    window.history.replaceState({}, "", `${url.pathname}${url.search}`);
    render();
  }

  async function reorderMenu(type, sourceId, targetType, targetId, position) {
    const changed = type === "menu-group"
      ? reorderMenuGroup(sourceId, targetType, targetId, position)
      : type === "menu-item"
        ? reorderMenuItem(sourceId, targetType, targetId, position)
        : false;

    if (!changed) return;

    await saveMenuOrder();
  }

  function reorderMenuGroup(sourceId, targetType, targetId, position) {
    if (targetType !== "menu-group") return false;

    const records = state.data?.menuGroups || [];
    const idKey = "menuKey";
    const ordered = [...records];
    const fromIndex = ordered.findIndex((record) => String(record?.[idKey]) === String(sourceId));
    const moving = ordered[fromIndex];
    const target = ordered.find((record) => String(record?.[idKey]) === String(targetId));

    if (fromIndex < 0 || !moving || !target) return false;

    ordered.splice(fromIndex, 1);
    let toIndex = ordered.findIndex((record) => String(record?.[idKey]) === String(targetId));
    if (toIndex < 0) return false;
    if (position === "after") toIndex += 1;
    ordered.splice(toIndex, 0, moving);

    state.data.menuGroups = renumberMenuGroups(ordered);

    return true;
  }

  function reorderMenuItem(sourceId, targetType, targetId, position) {
    const records = state.data?.menuItems || [];
    const moving = records.find((record) => String(record?.itemKey) === String(sourceId));
    const target = targetType === "menu-item"
      ? records.find((record) => String(record?.itemKey) === String(targetId))
      : findMenuRecord("menu-group", targetId);

    if (!moving || !target) return false;

    const targetGroupKey = targetType === "menu-item" ? target.groupKey : target.menuKey;
    if (!targetGroupKey) return false;

    const targetParentItemKey = targetType === "menu-item" ? menuItemParentKey(target) : "";
    if (targetParentItemKey && menuChildItems(sourceId).length > 0) return false;

    const groupedItems = sortMenuRecords(records.filter((item) => item.groupKey === targetGroupKey && menuItemParentKey(item) === targetParentItemKey && String(item.itemKey) !== String(sourceId)), "label");
    let insertIndex = targetType === "menu-group"
      ? (position === "before" ? 0 : groupedItems.length)
      : groupedItems.findIndex((item) => String(item.itemKey) === String(targetId));

    if (insertIndex < 0) return false;
    if (targetType === "menu-item" && position === "after") insertIndex += 1;

    groupedItems.splice(insertIndex, 0, { ...moving, groupKey: targetGroupKey, parentItemKey: targetParentItemKey });

    const ordered = [];
    const handledItems = new Set(groupedItems.map((item) => item.itemKey));
    const knownGroups = new Set();
    sortMenuRecords(state.data?.menuGroups || [], "title").forEach((group) => {
      knownGroups.add(group.menuKey);

      ordered.push(...sortMenuRecords(records.filter((item) => item.groupKey === group.menuKey && String(item.itemKey) !== String(sourceId) && !handledItems.has(item.itemKey)), "label"));
    });

    sortMenuRecords(records.filter((item) => !knownGroups.has(item.groupKey) && item.groupKey !== targetGroupKey && String(item.itemKey) !== String(sourceId)), "label").forEach((item) => {
      ordered.push(item);
    });

    ordered.push(...groupedItems);

    state.data.menuItems = renumberMenuItems(ordered.map((item) => menuItemParentKey(item) === sourceId ? { ...item, groupKey: targetGroupKey } : item));

    return true;
  }

  function renumberMenuGroups(groups) {
    return groups.map((group, index) => ({
      ...group,
      sortOrder: (index + 1) * 10,
    }));
  }

  function renumberMenuItems(items) {
    const counters = {};

    return items.map((item) => {
      const groupKey = `${item.groupKey || ""}:${menuItemParentKey(item)}`;
      counters[groupKey] = (counters[groupKey] || 0) + 1;

      return {
        ...item,
        sortOrder: counters[groupKey] * 10,
      };
    });
  }

  function findMenuRecord(type, id) {
    const collection = type === "menu-group" ? (state.data?.menuGroups || []) : (state.data?.menuItems || []);
    const idKey = type === "menu-group" ? "menuKey" : "itemKey";

    return collection.find((record) => String(record?.[idKey]) === String(id)) || null;
  }

  async function saveMenuOrder() {
    await save("menu", () => request("save_menu_settings", {
      method: "POST",
      body: { groups: menuGroupsPayload(), items: menuItemsPayload() },
    }));
  }

  async function saveMenuGroup(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const menuKey = form.dataset.menuGroup;
    const groups = menuGroupsPayload((group) => group.menuKey === menuKey ? {
      menuKey,
      title: form.querySelector('[name="title"]').value,
      sortOrder: Number(form.querySelector('[name="sortOrder"]').value || 100),
      active: form.querySelector('[name="active"]').checked,
    } : group);

    await save("menu", () => request("save_menu_settings", { method: "POST", body: { groups, items: menuItemsPayload() } }));
  }

  async function saveMenuItem(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const itemKey = form.dataset.menuItem;
    const items = menuItemsPayload((item) => item.itemKey === itemKey ? {
      itemKey,
      label: form.querySelector('[name="label"]').value,
      groupKey: form.querySelector('[name="groupKey"]').value,
      parentItemKey: form.querySelector('[name="parentItemKey"]').value,
      iconKey: form.querySelector('[name="iconKey"]').value,
      sortOrder: Number(form.querySelector('[name="sortOrder"]').value || 100),
      active: form.querySelector('[name="active"]').checked,
    } : item);

    await save("menu", () => request("save_menu_settings", { method: "POST", body: { groups: menuGroupsPayload(), items } }));
  }

  function menuGroupsPayload(mapper = null) {
    return (state.data?.menuGroups || []).map((group) => {
      const payload = {
        menuKey: group.menuKey,
        title: group.title,
        sortOrder: Number(group.sortOrder || 100),
        active: group.active !== false,
      };

      return mapper ? mapper(payload) : payload;
    });
  }

  function menuItemsPayload(mapper = null) {
    return (state.data?.menuItems || []).map((item) => {
      const payload = {
        itemKey: item.itemKey,
        label: item.label,
        groupKey: item.groupKey,
        parentItemKey: item.parentItemKey || "",
        iconKey: item.iconKey,
        sortOrder: Number(item.sortOrder || 100),
        active: item.active !== false,
      };

      return mapper ? mapper(payload) : payload;
    });
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

  async function saveUserRoles(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const primarySiteId = Number(data.get("primarySiteId") || 0);

    await save("user", () => request("save_user", {
      method: "POST",
      body: {
        id: Number(data.get("id") || 0),
        name: String(data.get("name") || ""),
        email: String(data.get("email") || ""),
        phone: String(data.get("phone") || ""),
        role: String(data.get("role") || "user"),
        primarySiteId,
        siteIds: mergePrimarySite(checkedNumberValues(form, "siteIds"), primarySiteId),
        moduleIds: checkedNumberValues(form, "moduleIds"),
        permissionIds: checkedNumberValues(form, "permissionIds"),
        accessRules: parseJsonArray(data.get("accessRules")),
        active: String(data.get("active") || "1") !== "0",
      },
    }));
  }

  function checkedNumberValues(form, name) {
    return Array.from(form.querySelectorAll(`input[name="${name}"]:checked`))
      .map((input) => Number(input.value))
      .filter((value) => Number.isFinite(value) && value > 0);
  }

  function updateRoleProfileHint(form) {
    if (!form) return;
    const profile = roleProfile(form.querySelector("[data-role-select]")?.value);
    const hint = form.querySelector("[data-role-profile-help]");
    if (!hint || !profile) return;

    hint.querySelector("strong").textContent = profile.label || "Profil personnalisé";
    hint.querySelector("span").textContent = profile.description || "Ajustez les accès ci-dessous pour créer un profil sur mesure.";
  }

  function applySelectedRoleProfile(form) {
    if (!form) return;
    const profile = roleProfile(form.querySelector("[data-role-select]")?.value);
    if (!profile) return;

    const moduleSlugs = new Set(profile.moduleSlugs || []);
    const permissionNames = new Set(profile.permissions || []);
    const moduleIds = new Set((state.data?.modules || []).filter((module) => moduleSlugs.has(module.slug)).map((module) => Number(module.id)));
    const permissionIds = new Set((state.data?.permissions || []).filter((permission) => permissionNames.has(permission.name)).map((permission) => Number(permission.id)));

    form.querySelectorAll('input[name="moduleIds"]').forEach((input) => {
      input.checked = moduleIds.has(Number(input.value));
    });
    form.querySelectorAll('input[name="permissionIds"]').forEach((input) => {
      input.checked = permissionIds.has(Number(input.value));
    });
    const accessRules = form.querySelector('[name="accessRules"]');
    if (accessRules) accessRules.value = "[]";
    updateRoleProfileHint(form);
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
      state.editing = null;
      await load({ force: true });
      if (key === "site") {
        window.CRM_ACTIVE_SITE?.reload?.();
      }
      if (key === "menu") {
        window.dispatchEvent(new CustomEvent("crm:navigation-refresh"));
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
      chevron: '<path d="m9 18 6-6-6-6"></path>',
      checklist: '<path d="m8 7 1.6 1.6L13 5"></path><path d="M16 7h4"></path><path d="m8 15 1.6 1.6L13 13"></path><path d="M16 15h4"></path>',
      creditCard: '<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M3 10h18M7 15h4"></path>',
      close: '<path d="M18 6 6 18M6 6l12 12"></path>',
      dashboard: '<rect x="4" y="4" width="6" height="6" rx="1.5"></rect><rect x="14" y="4" width="6" height="6" rx="1.5"></rect><rect x="4" y="14" width="6" height="6" rx="1.5"></rect><rect x="14" y="14" width="6" height="6" rx="1.5"></rect>',
      edit: '<path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"></path>',
      fileText: '<path d="M7 3h7l4 4v14H7z"></path><path d="M14 3v5h5"></path><path d="M10 13h6M10 17h4"></path>',
      grip: '<path d="M9 5h.01M15 5h.01M9 12h.01M15 12h.01M9 19h.01M15 19h.01"></path>',
      package: '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"></path><path d="m4 7.5 8 4.5 8-4.5M12 12v9"></path>',
      plus: '<path d="M12 5v14M5 12h14"></path>',
      ruler: '<path d="M4 17 17 4l3 3L7 20z"></path><path d="m14 7 3 3M11 10l2 2M8 13l3 3"></path>',
      search: '<circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>',
      settings: '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"></path><path d="M12 2v3M12 19v3M4.9 4.9 7 7M17 17l2.1 2.1M2 12h3M19 12h3"></path>',
      shield: '<path d="M12 3 19 6v5c0 5-3.1 8.7-7 10-3.9-1.3-7-5-7-10V6z"></path><path d="m9.5 12 1.8 1.8 3.7-4"></path>',
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
      const routeTab = sectionFromLocation();
      if (state.tab !== routeTab) {
        state.tab = routeTab;
        state.search = "";
      }
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

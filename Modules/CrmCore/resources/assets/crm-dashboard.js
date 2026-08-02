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
    quickAccessModalOpen: false,
    quickAccessDraft: [],
    quickAccessSaving: false,
    quickAccessError: "",
    quickAccessDragSlug: "",
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

  function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
  }

  async function request(action, options = {}) {
    const url = new URL("/api/dashboard", window.location.origin);
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
      throw new Error(payload.error || "Dashboard indisponible");
    }

    return payload;
  }

  function ensureStyle() {
    if (document.getElementById(styleId)) return;

    const style = document.createElement("style");
    style.id = styleId;
    style.textContent = `
      #${rootId}{--dash-primary:rgb(var(--theme-primary));--dash-border:var(--color-surface-200,#e2e8f0);--dash-muted:var(--color-secondary-500,#64748b);--dash-text:var(--color-secondary-900,#0f172a);display:grid;gap:1rem}
      #${rootId} *{box-sizing:border-box}
      #${rootId} svg{width:1.1rem;height:1.1rem;flex:none;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} .dash-top{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem}
      #${rootId} .dash-title h1{margin:0;color:var(--dash-text);font-size:1.8rem;line-height:1.1;font-weight:600;letter-spacing:0}
      #${rootId} .dash-title p{margin:.35rem 0 0;color:var(--dash-muted);font-size:.92rem;font-weight:650}
      #${rootId} .dash-actions{display:flex;gap:.55rem;flex-wrap:wrap;justify-content:flex-end}
      #${rootId} .dash-button{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:2.35rem;border:1px solid var(--dash-border);border-radius:.5rem;background:#fff;padding:.55rem .85rem;color:var(--dash-text);font:inherit;font-size:.82rem;font-weight:850;text-decoration:none;box-shadow:0 10px 24px rgba(15,23,42,.04);cursor:pointer}
      #${rootId} .dash-button-primary{border-color:transparent;background:var(--dash-primary);color:#fff}
      #${rootId} .dash-grid{display:grid;gap:.85rem}
      #${rootId} .dash-stats{grid-template-columns:repeat(4,minmax(0,1fr))}
      #${rootId} .dash-main{grid-template-columns:minmax(0,1.15fr) minmax(19rem,.85fr);align-items:start}
      #${rootId} .dash-bottom{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
      #${rootId} .dash-card{min-width:0;border:1px solid var(--dash-border);border-radius:.5rem;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.05)}
      #${rootId} .dash-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:.8rem;border-bottom:1px solid var(--dash-border);padding:.95rem 1rem}
      #${rootId} .dash-card-title{margin:0;color:var(--dash-text);font-size:1rem;font-weight:600;letter-spacing:0}
      #${rootId} .dash-card-subtitle{margin:.22rem 0 0;color:var(--dash-muted);font-size:.78rem;font-weight:400}
      #${rootId} .dash-card-body{padding:1rem}
      #${rootId} .dash-stat{display:grid;grid-template-columns:2.6rem minmax(0,1fr);align-items:center;gap:.75rem;padding:.9rem}
      #${rootId} .dash-stat-icon{display:grid;place-items:center;width:2.6rem;height:2.6rem;border-radius:.5rem;background:color-mix(in srgb,var(--stat-color,var(--theme-primary-color)) 14%,white);color:var(--stat-color,var(--theme-primary-color))}
      #${rootId} .dash-stat-icon svg,#${rootId} .dash-mini-icon svg{width:1.2rem;height:1.2rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} .dash-stat span{display:block;color:var(--dash-muted);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #${rootId} .dash-stat strong{display:block;margin:.2rem 0;color:var(--dash-text);font-size:1.45rem;font-weight:600;line-height:1.05;letter-spacing:0}
      #${rootId} .dash-stat small{display:block;color:var(--color-secondary-400,#94a3b8);font-size:.72rem;font-weight:750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #${rootId} .dash-quick-header{align-items:center}
      #${rootId} .dash-quick-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.85rem}
      #${rootId} .dash-quick-card{display:grid;grid-template-columns:minmax(0,1fr) auto;grid-template-rows:auto 1fr;align-items:end;gap:.75rem;min-width:0;min-height:8.6rem;border:1px solid var(--dash-border);border-radius:.5rem;background:#fff;padding:1rem;color:inherit;text-decoration:none;box-shadow:0 10px 24px rgba(15,23,42,.035);transition:border-color .18s ease,box-shadow .18s ease,transform .18s ease}
      #${rootId} .dash-quick-card:hover{border-color:color-mix(in srgb,var(--quick-color,var(--theme-primary-color)) 42%,var(--dash-border));box-shadow:0 18px 38px rgba(15,23,42,.08);transform:translateY(-1px)}
      #${rootId} .dash-quick-icon{grid-column:1/-1;display:grid;place-items:center;width:3.25rem;height:3.25rem;border-radius:.6rem;background:color-mix(in srgb,var(--quick-color,var(--theme-primary-color)) 14%,white);color:var(--quick-color,var(--theme-primary-color))}
      #${rootId} .dash-quick-icon svg{width:1.45rem;height:1.45rem}
      #${rootId} .dash-quick-text{min-width:0;align-self:end}
      #${rootId} .dash-quick-title{display:block;color:var(--dash-text);font-size:.95rem;font-weight:600;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #${rootId} .dash-quick-detail{display:-webkit-box;margin-top:.35rem;color:var(--dash-muted);font-size:.74rem;font-weight:400;line-height:1.35;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
      #${rootId} .dash-quick-arrow{display:grid;place-items:center;align-self:end;width:1.7rem;height:1.7rem;border-radius:.45rem;color:var(--dash-muted)}
      #${rootId} .dash-quick-card:hover .dash-quick-arrow{color:var(--quick-color,var(--theme-primary-color));background:color-mix(in srgb,var(--quick-color,var(--theme-primary-color)) 9%,white)}
      #${rootId} .dash-icon-button{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border:1px solid var(--dash-border);border-radius:.5rem;background:#fff;color:var(--dash-text);font:inherit;cursor:pointer}
      #${rootId} .dash-modal-backdrop{position:fixed;inset:0;z-index:2147483000;display:block;background:rgba(15,23,42,.18);padding:0}
      #${rootId} .dash-modal{position:absolute;top:0;right:0;display:grid;grid-template-rows:auto minmax(0,1fr);width:min(38rem,66.666vw);min-width:min(35rem,100vw);height:100dvh;max-height:none;overflow:hidden;border:0;border-left:1px solid var(--dash-border);border-radius:.75rem 0 0 .75rem;background:#fff;box-shadow:-20px 0 70px rgba(15,23,42,.18);animation:dashDrawerIn .18s ease-out}
      #${rootId} .dash-modal-header{display:grid;grid-template-columns:minmax(0,1fr) 2rem;align-items:flex-start;gap:1rem;border-bottom:1px solid var(--dash-border);padding:1rem}
      #${rootId} .dash-modal-heading{min-width:0}
      #${rootId} .dash-modal-title{margin:0;color:var(--dash-text);font-size:1.08rem;font-weight:600}
      #${rootId} .dash-modal-subtitle{margin:.22rem 0 0;color:var(--dash-muted);font-size:.78rem;font-weight:400}
      #${rootId} .dash-modal-body{display:flex;min-height:0;flex-direction:column;gap:1rem;overflow:auto;background:#fafafa;padding:1rem}
      #${rootId} .dash-modal-back-button{display:none}
      #${rootId} .dash-modal-close-button{grid-column:2}
      #${rootId} .dash-quick-settings-list{display:grid;gap:.65rem}
      #${rootId} .dash-quick-setting-row{display:grid;grid-template-columns:1.65rem 2.45rem minmax(0,1fr) auto;align-items:center;gap:.65rem;border:1px solid var(--dash-border);border-radius:.6rem;background:#fff;padding:.7rem;box-shadow:0 10px 24px rgba(15,23,42,.035)}
      #${rootId} .dash-quick-setting-row.is-dragging{opacity:.45}
      #${rootId} .dash-quick-drag-handle{display:grid;place-items:center;width:1.65rem;height:2.1rem;border:0;background:transparent;color:#94a3b8;cursor:grab;padding:0}
      #${rootId} .dash-quick-drag-handle:active{cursor:grabbing}
      #${rootId} .dash-quick-setting-icon{display:grid;place-items:center;width:2.45rem;height:2.45rem;border-radius:.55rem;background:color-mix(in srgb,var(--quick-color,var(--theme-primary-color)) 14%,white);color:var(--quick-color,var(--theme-primary-color))}
      #${rootId} .dash-quick-setting-copy{min-width:0}
      #${rootId} .dash-quick-setting-copy strong{display:block;overflow:hidden;color:var(--dash-text);font-size:.9rem;font-weight:600;text-overflow:ellipsis;white-space:nowrap}
      #${rootId} .dash-quick-setting-copy small{display:block;margin-top:.14rem;overflow:hidden;color:var(--dash-muted);font-size:.72rem;font-weight:400;text-overflow:ellipsis;white-space:nowrap}
      #${rootId} .dash-switch{position:relative;display:inline-flex;width:2.65rem;height:1.5rem;cursor:pointer}
      #${rootId} .dash-switch input{position:absolute;opacity:0;pointer-events:none}
      #${rootId} .dash-switch span{display:block;width:100%;height:100%;border-radius:999px;background:#cbd5e1;box-shadow:inset 0 0 0 1px rgba(15,23,42,.06);transition:background .18s ease}
      #${rootId} .dash-switch span::after{content:"";position:absolute;top:.2rem;left:.2rem;width:1.1rem;height:1.1rem;border-radius:999px;background:#fff;box-shadow:0 4px 10px rgba(15,23,42,.18);transition:transform .18s ease}
      #${rootId} .dash-switch input:checked + span{background:var(--dash-primary)}
      #${rootId} .dash-switch input:checked + span::after{transform:translateX(1.15rem)}
      #${rootId} .dash-modal-error{border:1px solid #fecdd3;border-radius:.55rem;background:#fff1f2;padding:.7rem .8rem;color:#be123c;font-size:.78rem;font-weight:600}
      #${rootId} .dash-modal-actions{position:sticky;bottom:0;z-index:2;display:flex;align-items:center;justify-content:flex-end;gap:.55rem;margin-top:auto;border-top:1px solid var(--dash-border);background:rgba(250,250,250,.94);padding:.8rem 0 0;backdrop-filter:blur(10px)}
      #${rootId} .dash-modal-actions .dash-button{flex:0 1 auto;min-width:6.25rem;box-shadow:none}
      @keyframes dashDrawerIn{from{transform:translateX(2rem);opacity:.7}to{transform:translateX(0);opacity:1}}
      #${rootId} .dash-chart{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));align-items:end;gap:.55rem;min-height:12rem;padding:.2rem .15rem 0}
      #${rootId} .dash-bar{display:grid;align-items:end;gap:.45rem;min-width:0;height:11rem}
      #${rootId} .dash-bar-track{display:flex;align-items:flex-end;justify-content:center;height:9rem;border-radius:.5rem;background:linear-gradient(180deg,#f8fafc,#f1f5f9);overflow:hidden}
      #${rootId} .dash-bar-fill{width:58%;min-height:.35rem;border-radius:.45rem .45rem 0 0;background:var(--dash-primary);box-shadow:0 10px 22px rgb(var(--theme-primary) / .24)}
      #${rootId} .dash-bar-label{text-align:center;color:var(--dash-muted);font-size:.72rem;font-weight:850;white-space:nowrap}
      #${rootId} .dash-bar-value{text-align:center;color:var(--dash-text);font-size:.8rem;font-weight:600}
      #${rootId} .dash-list{display:grid;gap:.55rem}
      #${rootId} .dash-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:.8rem;border:1px solid var(--dash-border);border-radius:.5rem;padding:.75rem .8rem;background:#fff}
      #${rootId} .dash-row-main{min-width:0}
      #${rootId} .dash-row-title{display:flex;align-items:center;gap:.45rem;min-width:0;color:var(--dash-text);font-size:.9rem;font-weight:600}
      #${rootId} .dash-row-icon{display:grid;place-items:center;width:2rem;height:2rem;border-radius:.45rem;background:color-mix(in srgb,var(--dash-primary) 10%,white);color:var(--dash-primary)}
      #${rootId} .dash-row-title span:last-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #${rootId} .dash-row-meta{margin-top:.16rem;color:var(--dash-muted);font-size:.76rem;font-weight:400;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #${rootId} .dash-badge{display:inline-flex;align-items:center;justify-content:center;min-height:1.75rem;border-radius:999px;background:#f1f5f9;padding:.25rem .65rem;color:var(--dash-text);font-size:.72rem;font-weight:600;white-space:nowrap}
      #${rootId} .dash-dot{width:.6rem;height:.6rem;border-radius:999px;background:var(--dot-color,var(--theme-primary-color));box-shadow:0 0 0 3px color-mix(in srgb,var(--dot-color,var(--theme-primary-color)) 13%,white)}
      #${rootId} .dash-alert{display:grid;grid-template-columns:2.35rem minmax(0,1fr) auto;align-items:center;gap:.7rem;border:1px solid var(--dash-border);border-radius:.5rem;padding:.75rem .8rem;background:#fff;color:inherit;text-decoration:none}
      #${rootId} .dash-alert-icon{display:grid;place-items:center;width:2.35rem;height:2.35rem;border-radius:.5rem;background:color-mix(in srgb,var(--alert-color,var(--theme-primary-color)) 13%,white);color:var(--alert-color,var(--theme-primary-color))}
      #${rootId} .dash-alert strong{display:block;color:var(--dash-text);font-size:.88rem;font-weight:600}
      #${rootId} .dash-alert span{display:block;margin-top:.12rem;color:var(--dash-muted);font-size:.73rem;font-weight:400}
      #${rootId} .dash-alert-value{color:var(--dash-text);font-size:1.1rem;font-weight:600}
      #${rootId} .dash-empty{display:grid;place-items:center;min-height:7rem;border:1px dashed var(--dash-border);border-radius:.5rem;color:var(--dash-muted);font-size:.82rem;font-weight:800;text-align:center;padding:1rem}
      #${rootId} .dash-stats .dash-empty{grid-column:1/-1}
      #${rootId} .dash-quick-grid .dash-empty{grid-column:1/-1}
      #${rootId} .dash-notifications{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.65rem}
      #${rootId} .dash-note{border:1px solid var(--dash-border);border-radius:.5rem;padding:.8rem;background:#fff}
      #${rootId} .dash-note span{display:block;color:var(--dash-muted);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #${rootId} .dash-note strong{display:block;margin-top:.25rem;color:var(--dash-text);font-size:1.35rem;font-weight:600;line-height:1}
      #${rootId} .dash-loading{border:1px solid var(--dash-border);border-radius:.5rem;background:#fff;padding:1rem;color:var(--dash-muted);font-weight:850}
      .dark #${rootId}{--dash-border:var(--color-surface-700,#334155);--dash-muted:var(--color-secondary-400,#94a3b8);--dash-text:#fff}
      .dark #${rootId} .dash-card,.dark #${rootId} .dash-row,.dark #${rootId} .dash-alert,.dark #${rootId} .dash-note,.dark #${rootId} .dash-button,.dark #${rootId} .dash-icon-button,.dark #${rootId} .dash-loading,.dark #${rootId} .dash-quick-card,.dark #${rootId} .dash-modal,.dark #${rootId} .dash-quick-setting-row{background:var(--color-surface-900,#0f172a);border-color:var(--dash-border)}
      .dark #${rootId} .dash-modal-body,.dark #${rootId} .dash-modal-actions{background:var(--color-surface-800,#1e293b)}
      .dark #${rootId} .dash-bar-track{background:linear-gradient(180deg,#1e293b,#0f172a)}
      @media (prefers-reduced-motion:reduce){#${rootId} .dash-modal{animation:none}}
      @media (max-width:1180px){#${rootId} .dash-stats,#${rootId} .dash-quick-grid{grid-template-columns:repeat(2,minmax(0,1fr))}#${rootId} .dash-main,#${rootId} .dash-bottom{grid-template-columns:1fr}}
      @media (max-width:1024px){#${rootId} .dash-modal-backdrop{background:#f8fafc}#${rootId} .dash-modal{inset:0;width:100vw;min-width:0;max-width:none;height:100dvh;border:0;border-radius:0;box-shadow:none}#${rootId} .dash-modal-header{position:sticky;top:0;z-index:3;grid-template-columns:1fr;gap:.75rem;border-bottom:0;background:#f8fafc;padding:1.05rem 1rem .85rem}#${rootId} .dash-modal-back-button{display:inline-flex;justify-content:flex-start;gap:.4rem;width:auto;min-width:0;height:auto;border:0;background:transparent;padding:.1rem 0;color:var(--dash-primary);font-size:.98rem;font-weight:600}#${rootId} .dash-modal-back-button svg{width:1.25rem;height:1.25rem}#${rootId} .dash-modal-close-button{display:none}#${rootId} .dash-modal-title{font-size:1.55rem;line-height:1.05}#${rootId} .dash-modal-subtitle{font-size:.82rem}#${rootId} .dash-modal-body{background:#f8fafc;padding:0 1rem calc(5.35rem + env(safe-area-inset-bottom))}#${rootId} .dash-modal-actions{position:fixed;right:auto;bottom:calc(.65rem + env(safe-area-inset-bottom));left:50%;display:inline-flex;align-items:center;justify-content:center;width:max-content;max-width:calc(100vw - 2rem);transform:translateX(-50%);margin:0;border:1px solid var(--dash-border);border-radius:.75rem;background:rgba(255,255,255,.96);padding:.45rem;box-shadow:0 -16px 34px rgba(15,23,42,.08)}#${rootId} .dash-modal-actions .dash-button{flex:0 1 auto;min-width:5.45rem;max-width:8.75rem;overflow:hidden;padding-inline:.72rem;text-overflow:ellipsis}}
      @media (max-width:720px){#${rootId}{gap:.85rem}#${rootId} .dash-top{align-items:stretch;flex-direction:column}#${rootId} .dash-actions{justify-content:stretch}#${rootId} .dash-button{flex:1}#${rootId} .dash-title h1{font-size:1.55rem}#${rootId} .dash-stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem}#${rootId} .dash-stat{grid-template-columns:2.2rem minmax(0,1fr);gap:.55rem;padding:.72rem}#${rootId} .dash-stat-icon{width:2.2rem;height:2.2rem}#${rootId} .dash-stat-icon svg{width:1rem;height:1rem}#${rootId} .dash-stat span{font-size:.66rem}#${rootId} .dash-stat strong{font-size:1.2rem}#${rootId} .dash-stat small{font-size:.66rem}#${rootId} .dash-quick-header{align-items:flex-start;flex-direction:row}#${rootId} .dash-quick-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem}#${rootId} .dash-quick-card{grid-template-columns:minmax(0,1fr);min-height:7.25rem;gap:.55rem;padding:.72rem}#${rootId} .dash-quick-icon{width:2.35rem;height:2.35rem}#${rootId} .dash-quick-icon svg{width:1.08rem;height:1.08rem}#${rootId} .dash-quick-title{font-size:.78rem}#${rootId} .dash-quick-detail{margin-top:.24rem;font-size:.65rem;line-height:1.25}#${rootId} .dash-quick-arrow{display:none}#${rootId} .dash-quick-customize{flex:0 0 2.35rem;width:2.35rem;min-width:2.35rem;height:2.35rem;min-height:2.35rem;padding:0;gap:0}#${rootId} .dash-quick-customize span{display:none}#${rootId} .dash-card-header,#${rootId} .dash-card-body{padding:.85rem}#${rootId} .dash-chart{gap:.35rem}#${rootId} .dash-notifications{grid-template-columns:1fr}#${rootId} .dash-row{grid-template-columns:1fr}#${rootId} .dash-badge{justify-self:start}#${rootId} .dash-alert{grid-template-columns:2.35rem minmax(0,1fr)}#${rootId} .dash-alert-value{grid-column:2}}
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
    bindEvents(root);
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
      access.equipmentRentals ? statCard("Matériel dispo", `${stats.equipmentAvailable || 0}/${stats.equipmentTotal || 0}`, "Disponible maintenant", "box", "var(--theme-primary-color)") : "",
    ].filter(Boolean).join("");

    return `
      <div class="dash-top">
        <div class="dash-title">
          <h1>Tableau de bord</h1>
          <p>${esc(site ? site.name : "Tous les sites")} · Synthèse du jour</p>
        </div>
      </div>

      <section class="dash-grid dash-stats">
        ${cards || empty("Aucun widget disponible avec vos droits actuels.")}
      </section>

      ${renderQuickAccess(data)}

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

      ${renderQuickAccessModal(data)}
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

  function renderQuickAccess(data) {
    const modules = quickAccessModules(data.quickAccessModules || data.modules || []);
    const settings = quickAccessSettings(data);

    return `
      <section class="dash-card dash-quick">
        <header class="dash-card-header dash-quick-header">
          <div>
            <h2 class="dash-card-title">Accès rapide</h2>
            <p class="dash-card-subtitle">Accédez en un clic aux modules disponibles du HUB.</p>
          </div>
          ${settings.length ? `<button class="dash-button dash-quick-customize" type="button" data-quick-customize aria-label="Personnaliser les accès rapides">${icon("sliders")}<span>Personnaliser</span></button>` : ""}
        </header>
        <div class="dash-card-body">
          <div class="dash-quick-grid">
            ${modules.length ? modules.map(quickAccessCard).join("") : empty("Aucun accès rapide disponible avec vos droits actuels.")}
          </div>
        </div>
      </section>
    `;
  }

  function quickAccessModules(modules) {
    return modules
      .filter((module) => module && module.slug !== "dashboard" && module.enabled !== false && moduleHref(module));
  }

  function quickAccessSettings(data) {
    const source = Array.isArray(data.quickAccessSettings) ? data.quickAccessSettings : (data.modules || []);

    return source
      .filter((module) => module && module.slug !== "dashboard" && moduleHref(module))
      .map((module, index) => ({
        id: Number(module.id || 0),
        name: module.name || "",
        slug: module.slug || "",
        routePath: module.routePath || "",
        enabled: module.enabled !== false,
        quickAccessSortOrder: Number.isFinite(Number(module.quickAccessSortOrder)) ? Number(module.quickAccessSortOrder) : index,
      }))
      .sort((first, second) => {
        const order = Number(first.quickAccessSortOrder || 0) - Number(second.quickAccessSortOrder || 0);
        if (order !== 0) return order;

        return String(first.name || "").localeCompare(String(second.name || ""), "fr");
      });
  }

  function renderQuickAccessModal(data) {
    if (!state.quickAccessModalOpen) return "";

    const modules = state.quickAccessDraft.length ? state.quickAccessDraft : quickAccessSettings(data);

    return `
      <div class="dash-modal-backdrop" data-quick-modal-backdrop>
        <section class="dash-modal" role="dialog" aria-modal="true" aria-label="Personnaliser les accès rapides">
          <header class="dash-modal-header">
            <button class="dash-icon-button dash-modal-back-button" type="button" data-quick-close aria-label="Retour">${icon("arrowLeft")}<span>Retour</span></button>
            <div class="dash-modal-heading">
              <h2 class="dash-modal-title">Accès rapide</h2>
              <p class="dash-modal-subtitle">Choisissez les modules visibles et glissez-les dans l’ordre souhaité.</p>
            </div>
            <button class="dash-icon-button dash-modal-close-button" type="button" data-quick-close aria-label="Fermer">${icon("close")}</button>
          </header>
          <div class="dash-modal-body">
            <div class="dash-quick-settings-list" data-quick-list>
              ${modules.length ? modules.map(quickAccessSettingRow).join("") : empty("Aucun module disponible avec vos droits actuels.")}
            </div>
            ${state.quickAccessError ? `<div class="dash-modal-error">${esc(state.quickAccessError)}</div>` : ""}
            <div class="dash-modal-actions">
              <button class="dash-button" type="button" data-quick-close>Annuler</button>
              <button class="dash-button dash-button-primary" type="button" data-quick-save${state.quickAccessSaving ? " disabled" : ""}>${state.quickAccessSaving ? "Enregistrement..." : "Enregistrer"}</button>
            </div>
          </div>
        </section>
      </div>
    `;
  }

  function quickAccessSettingRow(module) {
    const meta = moduleQuickMeta(module);

    return `
      <article class="dash-quick-setting-row" draggable="true" data-quick-drag-row data-quick-slug="${esc(module.slug)}" style="--quick-color:${esc(meta.color)}">
        <button class="dash-quick-drag-handle" type="button" aria-label="Déplacer ${esc(module.name || meta.label)}">${icon("grip")}</button>
        <span class="dash-quick-setting-icon">${icon(meta.icon)}</span>
        <span class="dash-quick-setting-copy">
          <strong>${esc(module.name || meta.label)}</strong>
          <small>${esc(meta.detail)}</small>
        </span>
        <label class="dash-switch" aria-label="Afficher ${esc(module.name || meta.label)}">
          <input type="checkbox" data-quick-enabled data-quick-slug="${esc(module.slug)}"${module.enabled ? " checked" : ""}>
          <span></span>
        </label>
      </article>
    `;
  }

  function quickAccessCard(module) {
    const meta = moduleQuickMeta(module);
    const href = moduleHref(module);
    const external = isExternalHref(href);

    return `
      <a class="dash-quick-card" href="${esc(href)}" style="--quick-color:${esc(meta.color)}"${external ? ' target="_blank" rel="noopener noreferrer"' : ""}>
        <span class="dash-quick-icon">${icon(meta.icon)}</span>
        <span class="dash-quick-text">
          <span class="dash-quick-title">${esc(module.name || meta.label)}</span>
          <span class="dash-quick-detail">${esc(meta.detail)}</span>
        </span>
        <span class="dash-quick-arrow">${icon("chevronRight")}</span>
      </a>
    `;
  }

  function moduleQuickMeta(module) {
    return {
      reservations: { label: "Réservations véhicules", detail: "Planning, véhicules et créneaux disponibles", icon: "truck", color: "#2563eb" },
      "locations-materiel": { label: "Location matériel", detail: "Matériel disponible, locations et retours", icon: "box", color: "var(--theme-primary-color)" },
      equipes: { label: "Équipe", detail: "Membres, sites et coordonnées utiles", icon: "users", color: "#0ea5e9" },
      conges: { label: "Congés & Absences", detail: "Calendrier, demandes et suivi des soldes", icon: "calendar", color: "#f59e0b" },
      "controle-caisse": { label: "Contrôle caisse", detail: "Caisse du jour, écarts et vérifications", icon: "receipt", color: "#16a34a" },
      "demandes-acompte": { label: "Demande d'acompte", detail: "Demandes, validations et historique", icon: "banknote", color: "#0f766e" },
      "remise-cheques": { label: "Remise de chèques", detail: "Remises, chèques et dépôt bancaire", icon: "creditCard", color: "#be123c" },
      addvance: { label: "Addvance", detail: "Accès externe Addvance Solutions", icon: "external", color: "#7c3aed" },
      "tournees-representants": { label: "Rapport de visite", detail: "Visites clients et comptes rendus terrain", icon: "clipboard", color: "#db2777" },
      "pilotage-commercial": { label: "Pilotage commercial", detail: "Objectifs, chiffres et commissions", icon: "chart", color: "#0891b2" },
      "pages-crm": { label: "Pages HUB", detail: "Pages internes et contenus du HUB", icon: "article", color: "#2563eb" },
      administration: { label: "Administration", detail: "Sites, utilisateurs, modules et navigation", icon: "settings", color: "var(--theme-primary-color)" },
      "documents-promo": { label: "Promo", detail: "Documents commerciaux et promotions", icon: "article", color: "#f59e0b" },
      "documents-fiches-techniques": { label: "Fiches techniques", detail: "Produits, fiches et informations terrain", icon: "article", color: "#0ea5e9" },
      "documents-procedures": { label: "Procédures", detail: "Méthodes, procédures et documents internes", icon: "article", color: "#64748b" },
      "tapis-romus": { label: "Tapis ROMUS", detail: "Commande, mesures et suivi des tapis", icon: "table", color: "#9333ea" },
      planning: { label: "Planning", detail: "Événements et organisation par site", icon: "calendar", color: "#0d9488" },
      documents: { label: "Documents", detail: "Documents partagés et contenus internes", icon: "article", color: "#64748b" },
    }[module.slug] || {
      label: module.name || "Module",
      detail: "Accéder au module du HUB",
      icon: "box",
      color: "var(--theme-primary-color)",
    };
  }

  function moduleHref(module) {
    const routePath = String(module.routePath || "").trim();
    const slugPath = module.slug ? `/${String(module.slug).replace(/^\/+/, "")}` : "";
    const href = routePath || slugPath;

    if (!href) return "";
    if (isExternalHref(href) || href.startsWith("/")) return href;

    return `/${href.replace(/^\/+/, "")}`;
  }

  function isExternalHref(href) {
    return /^https?:\/\//i.test(String(href || ""));
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
          <div class="dash-row-title"><span class="dash-dot" style="--dot-color:${esc(item.color || "var(--theme-primary-color)")}"></span><span>${esc(item.name || "Utilisateur")}</span></div>
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

  function bindEvents(root) {
    root.querySelector("[data-quick-customize]")?.addEventListener("click", openQuickAccessModal);
    root.querySelectorAll("[data-quick-close]").forEach((button) => {
      button.addEventListener("click", closeQuickAccessModal);
    });
    root.querySelector("[data-quick-modal-backdrop]")?.addEventListener("click", (event) => {
      if (event.target === event.currentTarget) closeQuickAccessModal();
    });
    root.querySelectorAll("[data-quick-enabled]").forEach((input) => {
      input.addEventListener("change", () => {
        updateQuickAccessEnabled(input.dataset.quickSlug, input.checked);
      });
    });
    root.querySelector("[data-quick-save]")?.addEventListener("click", saveQuickAccess);
    bindQuickAccessDrag(root);
  }

  function openQuickAccessModal() {
    state.quickAccessDraft = quickAccessSettings(state.data || {}).map((module) => ({ ...module }));
    state.quickAccessModalOpen = true;
    state.quickAccessError = "";
    render();
  }

  function closeQuickAccessModal() {
    state.quickAccessModalOpen = false;
    state.quickAccessDraft = [];
    state.quickAccessError = "";
    state.quickAccessDragSlug = "";
    render();
  }

  function updateQuickAccessEnabled(slug, enabled) {
    state.quickAccessDraft = state.quickAccessDraft.map((module) => (
      module.slug === slug ? { ...module, enabled } : module
    ));
    state.quickAccessError = "";
    render();
  }

  function bindQuickAccessDrag(root) {
    root.querySelectorAll("[data-quick-drag-row]").forEach((row) => {
      row.addEventListener("dragstart", (event) => {
        state.quickAccessDragSlug = row.dataset.quickSlug || "";
        row.classList.add("is-dragging");
        event.dataTransfer?.setData("text/plain", state.quickAccessDragSlug);
        if (event.dataTransfer) event.dataTransfer.effectAllowed = "move";
      });
      row.addEventListener("dragover", (event) => {
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = "move";
      });
      row.addEventListener("drop", (event) => {
        event.preventDefault();
        moveQuickAccessDraft(state.quickAccessDragSlug, row.dataset.quickSlug || "");
      });
      row.addEventListener("dragend", () => {
        state.quickAccessDragSlug = "";
        root.querySelectorAll("[data-quick-drag-row]").forEach((item) => item.classList.remove("is-dragging"));
      });
    });
  }

  function moveQuickAccessDraft(fromSlug, toSlug) {
    if (!fromSlug || !toSlug || fromSlug === toSlug) return;

    const draft = [...state.quickAccessDraft];
    const fromIndex = draft.findIndex((module) => module.slug === fromSlug);
    const toIndex = draft.findIndex((module) => module.slug === toSlug);

    if (fromIndex < 0 || toIndex < 0) return;

    const [module] = draft.splice(fromIndex, 1);
    draft.splice(toIndex, 0, module);
    state.quickAccessDraft = draft.map((item, index) => ({ ...item, quickAccessSortOrder: index }));
    state.quickAccessError = "";
    render();
  }

  async function saveQuickAccess() {
    if (state.quickAccessSaving) return;

    state.quickAccessSaving = true;
    state.quickAccessError = "";
    render();

    try {
      const payload = await request("save_quick_access", {
        method: "POST",
        body: {
          modules: state.quickAccessDraft.map((module) => ({
            slug: module.slug,
            enabled: Boolean(module.enabled),
          })),
        },
      });

      state.data = {
        ...(state.data || {}),
        modules: payload.modules || state.data?.modules || [],
        quickAccessModules: payload.quickAccessModules || [],
        quickAccessSettings: payload.quickAccessSettings || [],
      };
      state.quickAccessModalOpen = false;
      state.quickAccessDraft = [];
    } catch (error) {
      state.quickAccessError = error.message || "Impossible d’enregistrer les accès rapides.";
    } finally {
      state.quickAccessSaving = false;
      state.quickAccessDragSlug = "";
      render();
    }
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
    }[type] || "var(--theme-primary-color)";
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
      article: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"></path><path d="M14 2v6h6M8 13h8M8 17h6"></path>',
      arrowLeft: '<path d="m12 19-7-7 7-7"></path><path d="M19 12H5"></path>',
      banknote: '<rect x="3" y="6" width="18" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 9v6M18 9v6"></path>',
      bell: '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path>',
      chart: '<path d="M4 19V5"></path><path d="M8 19v-8"></path><path d="M12 19V8"></path><path d="M16 19v-5"></path><path d="M20 19V3"></path>',
      chevronRight: '<path d="m9 18 6-6-6-6"></path>',
      clipboard: '<rect x="8" y="2" width="8" height="4" rx="1"></rect><path d="M9 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3"></path><path d="M8 13h8M8 17h6"></path>',
      close: '<path d="M18 6 6 18"></path><path d="m6 6 12 12"></path>',
      creditCard: '<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M3 10h18M7 15h4"></path>',
      external: '<path d="M15 3h6v6"></path><path d="M10 14 21 3"></path><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"></path>',
      grip: '<path d="M9 5h.01M9 12h.01M9 19h.01M15 5h.01M15 12h.01M15 19h.01"></path>',
      settings: '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"></path><path d="M12 2v3M12 19v3M4.9 4.9 7 7M17 17l2.1 2.1M2 12h3M19 12h3"></path>',
      sliders: '<path d="M4 21v-7M4 10V3"></path><path d="M12 21v-9M12 8V3"></path><path d="M20 21v-5M20 12V3"></path><path d="M2 14h4M10 8h4M18 16h4"></path>',
      table: '<rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 10h18M9 4v16"></path>',
      users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"></path>',
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

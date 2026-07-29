(() => {
  const rootId = "crm-tapis-romus-module";
  const styleId = "crm-tapis-romus-style";
  const routeEvents = ["popstate", "crm:navigation", "crm:route-changed"];
  const state = {
    width: 4,
    length: 5,
    quantity: 1,
  };

  let mountTimer = null;

  function isRoute() {
    const path = window.location.pathname.replace(/\/+$/, "") || "/";
    return path === "/tapis-romus" || path.startsWith("/tapis-romus/");
  }

  function esc(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function ensureStyle() {
    if (document.getElementById(styleId)) return;

    const style = document.createElement("style");
    style.id = styleId;
    style.textContent = `
      #${rootId}{--tapis-primary:rgb(var(--theme-primary));--tapis-border:var(--color-surface-200,#e2e8f0);--tapis-text:var(--color-secondary-900,#0f172a);--tapis-muted:var(--color-secondary-500,#64748b);display:grid;gap:1rem}
      #${rootId} *{box-sizing:border-box}
      #${rootId} svg{width:1.05rem;height:1.05rem;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} .tapis-top{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem}
      #${rootId} .tapis-title h1{margin:0;color:var(--tapis-text);font-size:1.8rem;line-height:1.08;font-weight:600;letter-spacing:0}
      #${rootId} .tapis-title p{margin:.35rem 0 0;color:var(--tapis-muted);font-size:.92rem;font-weight:400}
      #${rootId} .tapis-grid{display:grid;grid-template-columns:minmax(0,.85fr) minmax(0,1.15fr);gap:1rem;align-items:start}
      #${rootId} .tapis-card{border:1px solid var(--tapis-border);border-radius:.6rem;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.05)}
      #${rootId} .tapis-card-header{border-bottom:1px solid var(--tapis-border);padding:.95rem 1rem}
      #${rootId} .tapis-card-title{margin:0;color:var(--tapis-text);font-size:1rem;font-weight:600}
      #${rootId} .tapis-card-subtitle{margin:.18rem 0 0;color:var(--tapis-muted);font-size:.78rem;font-weight:750}
      #${rootId} .tapis-card-body{display:grid;gap:.8rem;padding:1rem}
      #${rootId} label{display:grid;gap:.28rem;color:var(--tapis-muted);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #${rootId} input{width:100%;border:1px solid var(--tapis-border);border-radius:.5rem;background:#fff;padding:.65rem .72rem;color:var(--tapis-text);font:inherit;font-size:.92rem;font-weight:850}
      #${rootId} .tapis-result{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.7rem}
      #${rootId} .tapis-stat{border:1px solid var(--tapis-border);border-radius:.55rem;background:#fff;padding:.8rem}
      #${rootId} .tapis-stat span{display:block;color:var(--tapis-muted);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #${rootId} .tapis-stat strong{display:block;margin-top:.25rem;color:var(--tapis-text);font-size:1.4rem;font-weight:600}
      #${rootId} .tapis-note{border:1px dashed var(--tapis-border);border-radius:.55rem;padding:.85rem;color:var(--tapis-muted);font-size:.84rem;font-weight:750;line-height:1.5}
      #${rootId} .tapis-list{display:grid;gap:.55rem}
      #${rootId} .tapis-row{display:flex;align-items:center;justify-content:space-between;gap:.7rem;border:1px solid var(--tapis-border);border-radius:.5rem;padding:.72rem .8rem;background:#fff}
      #${rootId} .tapis-row strong{color:var(--tapis-text);font-size:.9rem;font-weight:600}
      #${rootId} .tapis-row span{color:var(--tapis-muted);font-size:.78rem;font-weight:800}
      .dark #${rootId}{--tapis-border:var(--color-surface-700,#334155);--tapis-text:#fff;--tapis-muted:var(--color-secondary-400,#94a3b8)}
      .dark #${rootId} .tapis-card,.dark #${rootId} .tapis-stat,.dark #${rootId} .tapis-row,.dark #${rootId} input{background:var(--color-surface-900,#0f172a);border-color:var(--tapis-border)}
      @media (max-width:900px){#${rootId} .tapis-grid,#${rootId} .tapis-result{grid-template-columns:1fr}}
      @media (max-width:700px){#${rootId} .tapis-title h1{font-size:1.55rem}#${rootId} .tapis-top{display:grid}}
    `;
    document.head.appendChild(style);
  }

  function mount() {
    if (!isRoute()) return false;
    const root = document.getElementById(rootId);
    if (!root) return false;

    ensureStyle();
    root.innerHTML = render();
    bind(root);

    return true;
  }

  function render() {
    const surface = Number(state.width || 0) * Number(state.length || 0) * Number(state.quantity || 0);
    const rolls = Math.ceil(surface / 25);

    return `
      <div class="tapis-top">
        <div class="tapis-title">
          <h1>Tapis ROMUS</h1>
          <p>Préparation et suivi des besoins tapis.</p>
        </div>
      </div>
      <section class="tapis-grid">
        <article class="tapis-card">
          <header class="tapis-card-header">
            <h2 class="tapis-card-title">Calcul rapide</h2>
            <p class="tapis-card-subtitle">Surface et rouleaux estimés</p>
          </header>
          <div class="tapis-card-body">
            <label>Largeur (m) <input data-field="width" type="number" min="0" step="0.01" value="${esc(state.width)}"></label>
            <label>Longueur (m) <input data-field="length" type="number" min="0" step="0.01" value="${esc(state.length)}"></label>
            <label>Quantité <input data-field="quantity" type="number" min="1" step="1" value="${esc(state.quantity)}"></label>
          </div>
        </article>
        <article class="tapis-card">
          <header class="tapis-card-header">
            <h2 class="tapis-card-title">Synthèse</h2>
            <p class="tapis-card-subtitle">Base native Laravel/Vite</p>
          </header>
          <div class="tapis-card-body">
            <div class="tapis-result">
              <div class="tapis-stat"><span>Surface</span><strong>${surface.toFixed(2)} m²</strong></div>
              <div class="tapis-stat"><span>Rouleaux 25 m²</span><strong>${rolls}</strong></div>
              <div class="tapis-stat"><span>Chutes</span><strong>${Math.max((rolls * 25) - surface, 0).toFixed(2)} m²</strong></div>
            </div>
            <div class="tapis-note">Ce module est maintenant chargé comme les autres modules HUB natifs. Les prochaines intégrations ROMUS pourront être branchées ici sans dépendance au template historique.</div>
            <div class="tapis-list">
              <div class="tapis-row"><strong>Préparation</strong><span>Calcul local</span></div>
              <div class="tapis-row"><strong>Suivi chantier</strong><span>À connecter</span></div>
              <div class="tapis-row"><strong>Export</strong><span>À connecter</span></div>
            </div>
          </div>
        </article>
      </section>
    `;
  }

  function bind(root) {
    root.querySelectorAll("[data-field]").forEach((input) => {
      input.addEventListener("input", () => {
        state[input.dataset.field] = Number(input.value || 0);
        mount();
      });
    });
  }

  function scheduleMount() {
    if (mountTimer) window.clearTimeout(mountTimer);
    mountTimer = window.setTimeout(() => {
      mountTimer = null;
      mount();
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

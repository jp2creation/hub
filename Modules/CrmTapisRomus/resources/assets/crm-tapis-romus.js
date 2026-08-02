import romusLogoUrl from "./logo-romus.png";

(() => {
  const rootId = "crm-tapis-romus-module";
  const styleId = "crm-tapis-romus-style";
  const activeSiteEvent = "crm:active-site-changed";
  const routeEvents = ["popstate", "crm:navigation", "crm:route-changed", activeSiteEvent];
  const pdfLibUrl = "/romus-tapis/pdf-lib.min.js";
  const templatePdfUrl = "/romus-tapis/BON%20DE%20COMMANDE%20TAPIS%20ROMUS_AOUT2025.pdf";

  const productRows = [
    ["tapis", "Tapis", "modeleTapis", "refTapis", "qteTapis"],
    ["cadre", "Cadre", "modeleCadre", "refCadre", "qteCadre"],
    ["equerres", "Équerres", "modeleEquerres", "refEquerres", "qteEquerres"],
    ["pattes", "Pattes de fixation", "modelePattes", "refPattes", "qtePattes"],
  ];
  const dimensionFields = [
    ["l1", "Longueur des profilés", "L1"],
    ["l2", "Longueur des profilés", "L2"],
    ["p1", "Profondeur", "P1"],
    ["p2", "Profondeur", "P2"],
    ["diagonale", "Diagonale", "DIAG"],
  ];
  const steps = [
    ["Coordonnées", "Client et chantier"],
    ["Références", "Articles et quantités"],
    ["Dimensions", "Plan et cadre"],
    ["PDF", "Contrôle final"],
  ];

  const state = {
    step: 0,
    isGenerating: false,
    pdfGenerated: false,
    pdfUrl: "",
    notice: null,
    errors: {},
    sitePrefill: {
      siteId: null,
      values: {},
      touched: {},
    },
    profilePrefill: {
      loaded: false,
      loading: false,
      profile: null,
      values: {},
      touched: {},
    },
    data: {
      commandeOuDevis: "",
      date: todayIso(),
      raisonSociale: "",
      nom: "",
      prenom: "",
      adresse: "",
      tel: "",
      email: "",
      refChantier: "",
      numeroCommande: "",
      modeleTapis: "",
      refTapis: "",
      qteTapis: "",
      modeleCadre: "",
      refCadre: "",
      qteCadre: "",
      modeleEquerres: "",
      refEquerres: "",
      qteEquerres: "",
      modelePattes: "",
      refPattes: "",
      qtePattes: "",
      l1: "",
      l2: "",
      p1: "",
      p2: "",
      diagonale: "",
      dimensionType: "",
      reservationMode: "",
      romusRef: "",
      autreCadre: "",
    },
  };

  let mountTimer = null;
  let pdfLibPromise = null;
  let profilePrefillPromise = null;

  function isRoute() {
    const path = window.location.pathname.replace(/\/+$/, "") || "/";

    return path === "/tapis-romus" || path.startsWith("/tapis-romus/");
  }

  function todayIso() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, "0");
    const day = String(today.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
  }

  function esc(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === "function") {
      return window.CSS.escape(String(value));
    }

    return String(value).replace(/["\\]/g, "\\$&");
  }

  function svgIcon(name) {
    const icons = {
      arrow: '<path d="M19 12H5"></path><path d="m12 19-7-7 7-7"></path>',
      boxes: '<path d="m7.5 4.27 4.5 2.6 4.5-2.6"></path><path d="M3 8l4.5 2.6L12 8l4.5 2.6L21 8"></path><path d="M3 8v8l4.5 2.6 4.5-2.6 4.5 2.6L21 16V8"></path><path d="M12 8v8"></path><path d="M7.5 10.6v8"></path><path d="M16.5 10.6v8"></path>',
      calendar: '<rect width="18" height="18" x="3" y="4" rx="2"></rect><path d="M16 2v4"></path><path d="M8 2v4"></path><path d="M3 10h18"></path>',
      check: '<path d="m20 6-11 11-5-5"></path>',
      clipboard: '<rect width="8" height="4" x="8" y="2" rx="1"></rect><path d="M8 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"></path>',
      clipboardCheck: '<rect width="8" height="4" x="8" y="2" rx="1"></rect><path d="M8 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"></path><path d="m9 14 2 2 4-4"></path>',
      download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="M7 10l5 5 5-5"></path><path d="M12 15V3"></path>',
      file: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"></path><path d="M14 2v6h6"></path><path d="M9 13h6"></path><path d="M9 17h3"></path>',
      fileCheck: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"></path><path d="M14 2v6h6"></path><path d="m9 15 2 2 4-5"></path>',
      fileText: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"></path><path d="M14 2v6h6"></path><path d="M8 13h8"></path><path d="M8 17h5"></path>',
      info: '<circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path>',
      mail: '<rect width="20" height="16" x="2" y="4" rx="2"></rect><path d="m22 7-10 6L2 7"></path>',
      map: '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle>',
      package: '<path d="m7.5 4.27 9 5.15"></path><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path>',
      phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 5.15 12.8 19.79 19.79 0 0 1 2.08 4.18 2 2 0 0 1 4.06 2h3a2 2 0 0 1 2 1.72c.12.9.32 1.77.58 2.61a2 2 0 0 1-.45 2.11L8 9.6a16 16 0 0 0 6.4 6.4l1.16-1.16a2 2 0 0 1 2.11-.45c.84.26 1.72.46 2.61.58A2 2 0 0 1 22 16.92Z"></path>',
      ruler: '<path d="M21.3 15.3 15 21.6 2.4 9 8.7 2.7Z"></path><path d="m14.5 4.5-2 2"></path><path d="m17.5 7.5-2 2"></path><path d="m8.5 10.5-2 2"></path><path d="m11.5 13.5-2 2"></path>',
      rulerSquare: '<path d="M4 19V5a1 1 0 0 1 1-1h14"></path><path d="M8 19h11a1 1 0 0 0 1-1V7"></path><path d="M4 15h4"></path><path d="M4 11h3"></path><path d="M4 7h4"></path><path d="M12 19v-4"></path><path d="M16 19v-3"></path><path d="M20 15h-4"></path><path d="M20 11h-3"></path>',
      sparkles: '<path d="m12 3-1.9 4.1L6 9l4.1 1.9L12 15l1.9-4.1L18 9l-4.1-1.9Z"></path><path d="M5 3v4"></path><path d="M3 5h4"></path><path d="M19 17v4"></path><path d="M17 19h4"></path>',
      user: '<path d="M20 21a8 8 0 0 0-16 0"></path><circle cx="12" cy="7" r="4"></circle>',
    };

    return `<svg viewBox="0 0 24 24" aria-hidden="true">${icons[name] || icons.info}</svg>`;
  }

  function currentSiteLabel() {
    const site = currentSite();
    if (site && (site.name || site.slug)) {
      return site.name || site.slug;
    }

    const switcherLabel = document.querySelector("[data-crm-active-site-current]");
    const label = switcherLabel && switcherLabel.textContent ? switcherLabel.textContent.trim() : "";

    return label || "Site courant";
  }

  function currentSite() {
    const api = window.CRM_ACTIVE_SITE;
    if (!api || typeof api.getSites !== "function") {
      return null;
    }

    const sites = Array.isArray(api.getSites()) ? api.getSites() : [];
    const siteId = typeof api.getSiteId === "function" ? Number(api.getSiteId() || 0) : 0;

    return sites.find((site) => Number(site.id) === siteId) || sites[0] || null;
  }

  function siteContactValues(site) {
    if (!site) {
      return {};
    }

    return {
      raisonSociale: String(site.name || ""),
      adresse: String(site.address || ""),
      tel: String(site.phone || ""),
      email: String(site.email || ""),
    };
  }

  function prefillContactFromSite(options = {}) {
    const site = currentSite();
    const values = siteContactValues(site);
    const siteId = site && site.id ? Number(site.id) : null;
    const force = options.force === true;

    if (!siteId) {
      return;
    }

    ["raisonSociale", "adresse", "tel", "email"].forEach((fieldName) => {
      const nextValue = values[fieldName] || "";
      const currentValue = String(state.data[fieldName] || "");
      const previousValue = String(state.sitePrefill.values[fieldName] || "");
      const hasBeenEdited = state.sitePrefill.touched[fieldName] === true;
      const canPrefill = force || (!hasBeenEdited && (!isFilledValue(currentValue) || currentValue === previousValue));

      if (canPrefill) {
        state.data[fieldName] = nextValue;
        state.sitePrefill.values[fieldName] = nextValue;
        state.sitePrefill.touched[fieldName] = false;
        delete state.errors[fieldName];
      }
    });

    state.sitePrefill.siteId = siteId;
  }

  function profileContactValues(profile) {
    if (!profile) {
      return {};
    }

    const displayName = String(profile.displayName || profile.name || "").trim();
    const displayNameParts = displayName.split(/\s+/).filter(Boolean);
    const firstName = String(profile.firstName || "").trim() || displayNameParts[0] || "";
    const lastName = String(profile.lastName || "").trim() || (displayNameParts.length > 1 ? displayNameParts.slice(1).join(" ") : "");

    return {
      nom: lastName,
      prenom: firstName,
    };
  }

  function prefillContactFromProfile(profile = state.profilePrefill.profile, options = {}) {
    const values = profileContactValues(profile);
    const force = options.force === true;
    let changed = false;

    ["nom", "prenom"].forEach((fieldName) => {
      const nextValue = values[fieldName] || "";
      const currentValue = String(state.data[fieldName] || "");
      const previousValue = String(state.profilePrefill.values[fieldName] || "");
      const hasBeenEdited = state.profilePrefill.touched[fieldName] === true;
      const canPrefill = isFilledValue(nextValue)
        && (force || (!hasBeenEdited && (!isFilledValue(currentValue) || currentValue === previousValue)));

      if (canPrefill) {
        state.data[fieldName] = nextValue;
        state.profilePrefill.values[fieldName] = nextValue;
        state.profilePrefill.touched[fieldName] = false;
        delete state.errors[fieldName];
        changed = changed || currentValue !== nextValue;
      }
    });

    return changed;
  }

  function loadProfilePrefill(options = {}) {
    const force = options.force === true;

    if (!isRoute()) {
      return Promise.resolve(false);
    }

    if (state.profilePrefill.loaded && !force) {
      return Promise.resolve(false);
    }

    if (profilePrefillPromise && !force) {
      return profilePrefillPromise;
    }

    state.profilePrefill.loading = true;
    profilePrefillPromise = fetch("/api/administration?action=profile", {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then((response) => response.json().then((payload) => ({ ok: response.ok, payload })))
      .then(({ ok, payload }) => {
        if (!ok || payload.ok === false || !payload.profile) {
          state.profilePrefill.loaded = true;

          return false;
        }

        state.profilePrefill.profile = payload.profile;
        state.profilePrefill.loaded = true;

        const changed = prefillContactFromProfile(payload.profile);
        if (changed) {
          scheduleMount();
        }

        return changed;
      })
      .catch(() => {
        state.profilePrefill.loaded = true;

        return false;
      })
      .finally(() => {
        state.profilePrefill.loading = false;
        profilePrefillPromise = null;
      });

    return profilePrefillPromise;
  }

  function missingSiteContactLabels(site) {
    const values = siteContactValues(site);
    const labels = {
      adresse: "adresse",
      tel: "téléphone",
      email: "e-mail",
    };

    return Object.entries(labels)
      .filter(([fieldName]) => !isFilledValue(values[fieldName]))
      .map(([, label]) => label);
  }

  function ensureStyle() {
    if (document.getElementById(styleId)) {
      return;
    }

    const style = document.createElement("style");
    style.id = styleId;
    style.textContent = `
      #${rootId}{--tapis-primary:rgb(var(--theme-primary));--tapis-primary-soft:rgb(var(--theme-primary) / .09);--tapis-border:var(--color-surface-200,#e2e8f0);--tapis-border-strong:var(--color-surface-300,#cbd5e1);--tapis-text:var(--color-secondary-900,#0f172a);--tapis-muted:var(--color-secondary-500,#64748b);--tapis-soft:var(--color-surface-50,#f8fafc);display:grid;gap:1rem;max-width:100%}
      #${rootId} *{box-sizing:border-box}
      #${rootId} svg{width:1.05rem;height:1.05rem;fill:none;stroke:currentColor;stroke-width:2.1;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} [hidden]{display:none!important}
      #${rootId} .tapis-page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem}
      #${rootId} .tapis-page-title{display:grid;gap:0;min-width:0}
      #${rootId} .tapis-eyebrow{margin:0 0 .28rem;color:var(--tapis-primary);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #${rootId} h1{margin:0;color:var(--tapis-text);font-size:1.8rem;line-height:1.1;font-weight:600;letter-spacing:0}
      #${rootId} .tapis-heading{display:flex;align-items:center;gap:.52rem;flex-wrap:wrap}
      #${rootId} .tapis-subtitle{margin:.35rem 0 0;color:var(--tapis-muted);font-size:.92rem;font-weight:600}
      #${rootId} .tapis-romus-title-logo{display:block;width:auto;height:1.46rem;max-width:min(9.2rem,52vw);object-fit:contain}
      #${rootId} .tapis-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.85rem}
      #${rootId} .tapis-kpi{display:grid;grid-template-columns:2.6rem minmax(0,1fr);align-items:center;gap:.75rem;border:1px solid var(--tapis-border);border-radius:.5rem;background:#fff;padding:.9rem;box-shadow:0 12px 28px rgba(15,23,42,.05)}
      #${rootId} .tapis-kpi-icon{display:grid;place-items:center;width:2.6rem;height:2.6rem;border-radius:.5rem;background:color-mix(in srgb,var(--tapis-kpi-color,var(--tapis-primary)) 14%,white);color:var(--tapis-kpi-color,var(--tapis-primary));flex:0 0 auto}
      #${rootId} .tapis-kpi-icon svg{width:1.2rem;height:1.2rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #${rootId} .tapis-kpi.is-command{--tapis-kpi-color:#be123c}
      #${rootId} .tapis-kpi.is-references{--tapis-kpi-color:#2563eb}
      #${rootId} .tapis-kpi.is-dimensions{--tapis-kpi-color:#f59e0b}
      #${rootId} .tapis-kpi.is-pdf{--tapis-kpi-color:#16a34a}
      #${rootId} .tapis-kpi span{display:block;color:var(--tapis-muted);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #${rootId} .tapis-kpi strong{display:block;margin:.2rem 0 0;color:var(--tapis-text);font-size:1.45rem;font-weight:600;line-height:1.05;letter-spacing:0}
      #${rootId} .tapis-shell{display:grid;grid-template-columns:minmax(13rem,.27fr) minmax(0,1fr);gap:1rem;align-items:start}
      #${rootId} .tapis-card{border:1px solid var(--tapis-border);border-radius:.5rem;background:#fff;box-shadow:0 12px 28px rgb(15 23 42 / .05);overflow:hidden}
      #${rootId} .tapis-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--tapis-border);padding:.95rem 1rem}
      #${rootId} .tapis-card-head-copy{display:grid;gap:.18rem;min-width:0}
      #${rootId} .tapis-card-title{margin:0;color:var(--tapis-text);font-size:1.04rem;font-weight:600;line-height:1.2}
      #${rootId} .tapis-card-subtitle{margin:0;color:var(--tapis-muted);font-size:.83rem;font-weight:400;line-height:1.35}
      #${rootId} .tapis-badge{display:inline-flex;align-items:center;justify-content:center;min-height:1.75rem;border-radius:999px;background:var(--tapis-primary-soft);padding:.25rem .65rem;color:var(--tapis-primary);font-size:.76rem;font-weight:600;white-space:nowrap}
      #${rootId} .tapis-stepper{display:grid;gap:.45rem;padding:.65rem}
      #${rootId} .tapis-step-button{display:grid;grid-template-columns:2.15rem minmax(0,1fr);align-items:center;gap:.7rem;width:100%;border:0;border-radius:.45rem;background:transparent;padding:.65rem;color:var(--tapis-muted);font:inherit;text-align:left;cursor:pointer}
      #${rootId} .tapis-step-button:hover,#${rootId} .tapis-step-button:focus-visible{background:var(--tapis-primary-soft);color:var(--tapis-primary);outline:none}
      #${rootId} .tapis-step-button.is-active{background:var(--tapis-primary);color:#fff;box-shadow:0 12px 24px rgb(var(--theme-primary) / .18)}
      #${rootId} .tapis-step-button.is-done:not(.is-active){background:#ecfdf3;color:#15803d}
      #${rootId} .tapis-step-number{display:grid;place-items:center;width:2.15rem;height:2.15rem;border-radius:.45rem;background:#fff;border:1px solid var(--tapis-border);color:inherit;font-size:.84rem;font-weight:600}
      #${rootId} .tapis-step-button.is-active .tapis-step-number{border-color:rgb(255 255 255 / .42);background:rgb(255 255 255 / .16);color:#fff}
      #${rootId} .tapis-step-copy strong{display:block;color:inherit;font-size:.9rem;font-weight:600;line-height:1.2}
      #${rootId} .tapis-step-copy span{display:block;margin-top:.15rem;color:inherit;opacity:.72;font-size:.76rem;font-weight:400;line-height:1.25}
      #${rootId} .tapis-workspace{display:grid;gap:1rem}
      #${rootId} .tapis-panel{display:grid;gap:1rem}
      #${rootId} .tapis-choice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}
      #${rootId} .tapis-prefill{display:grid;grid-template-columns:2.2rem minmax(0,1fr) auto;align-items:center;gap:.7rem;border:1px solid #bbf7d0;border-radius:.5rem;background:#f0fdf4;padding:.72rem .8rem;color:#166534;font-size:.83rem;font-weight:400;line-height:1.4}
      #${rootId} .tapis-prefill.is-warning{border-color:#fed7aa;background:#fffbeb;color:#92400e}
      #${rootId} .tapis-prefill-icon{display:grid;place-items:center;width:2.2rem;height:2.2rem;border-radius:.45rem;background:rgb(255 255 255 / .72);color:inherit}
      #${rootId} .tapis-prefill-action{border:1px solid currentColor;border-radius:.45rem;background:#fff;padding:.52rem .72rem;color:inherit;font:inherit;font-size:.78rem;font-weight:600;white-space:nowrap;cursor:pointer}
      #${rootId} .tapis-prefill-action:hover{box-shadow:0 8px 18px rgb(15 23 42 / .07)}
      #${rootId} .tapis-choice{position:relative;display:grid;grid-template-columns:2.55rem minmax(0,1fr) 1.2rem;align-items:center;gap:.75rem;border:1px solid var(--tapis-border);border-radius:.5rem;background:#fff;padding:.78rem .85rem;color:var(--tapis-text);cursor:pointer;transition:border-color .16s ease,box-shadow .16s ease,background .16s ease}
      #${rootId} .tapis-choice:hover{border-color:rgb(var(--theme-primary) / .45);box-shadow:0 10px 20px rgb(15 23 42 / .05)}
      #${rootId} .tapis-choice.is-active{border-color:var(--tapis-primary);background:var(--tapis-primary-soft);box-shadow:0 12px 24px rgb(var(--theme-primary) / .12)}
      #${rootId} .tapis-choice.is-invalid{border-color:#ef4444;background:#fff7f7}
      #${rootId} .tapis-choice input{position:absolute;opacity:0;pointer-events:none}
      #${rootId} .tapis-choice-icon{display:grid;place-items:center;width:2.55rem;height:2.55rem;border-radius:.45rem;background:var(--tapis-primary-soft);color:var(--tapis-primary)}
      #${rootId} .tapis-choice-title{display:block;color:var(--tapis-text);font-size:.92rem;font-weight:600}
      #${rootId} .tapis-choice-detail{display:block;margin-top:.15rem;color:var(--tapis-muted);font-size:.78rem;font-weight:400}
      #${rootId} .tapis-choice-dot{width:1.05rem;height:1.05rem;border-radius:999px;border:1px solid var(--tapis-border-strong);background:#fff}
      #${rootId} .tapis-choice.is-active .tapis-choice-dot{border-color:var(--tapis-primary);box-shadow:inset 0 0 0 .28rem #fff;background:var(--tapis-primary)}
      #${rootId} .tapis-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.78rem}
      #${rootId} .tapis-field{display:grid;gap:.32rem;min-width:0}
      #${rootId} .tapis-field.is-wide{grid-column:1/-1}
      #${rootId} .tapis-field-error{margin:0;color:#b91c1c;font-size:.74rem;font-weight:500;line-height:1.32}
      #${rootId} label.tapis-label{color:var(--tapis-muted);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #${rootId} input,#${rootId} textarea,#${rootId} select{width:100%;min-width:0;border:1px solid var(--tapis-border);border-radius:.45rem;background:#fff;padding:.67rem .72rem;color:var(--tapis-text);font:inherit;font-size:.88rem;font-weight:400;outline:none;transition:border-color .16s ease,box-shadow .16s ease,background .16s ease}
      #${rootId} textarea{min-height:5.2rem;resize:vertical}
      #${rootId} input:focus,#${rootId} textarea:focus,#${rootId} select:focus{border-color:rgb(var(--theme-primary) / .65);box-shadow:0 0 0 3px rgb(var(--theme-primary) / .12)}
      #${rootId} .is-invalid input,#${rootId} .is-invalid textarea,#${rootId} .is-invalid select,input.is-invalid,textarea.is-invalid{border-color:#ef4444;background:#fff7f7}
      #${rootId} .tapis-lines{display:grid;gap:.65rem}
      #${rootId} .tapis-line{display:grid;grid-template-columns:minmax(7.5rem,.7fr) minmax(0,1fr) minmax(0,.75fr) minmax(5rem,.38fr);gap:.6rem;align-items:end;border:1px solid var(--tapis-border);border-radius:.5rem;background:#fff;padding:.72rem}
      #${rootId} .tapis-line-name{align-self:center;display:flex;align-items:center;gap:.6rem;color:var(--tapis-text);font-size:.92rem;font-weight:600}
      #${rootId} .tapis-line-icon{display:grid;place-items:center;width:2.3rem;height:2.3rem;border-radius:.45rem;background:var(--tapis-primary-soft);color:var(--tapis-primary);flex:0 0 auto}
      #${rootId} .tapis-dimension-layout{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(18rem,.85fr);gap:1rem;align-items:start}
      #${rootId} .tapis-diagram{border:1px solid var(--tapis-border);border-radius:.5rem;background:linear-gradient(180deg,#fff,#f8fafc);padding:.8rem}
      #${rootId} .tapis-diagram svg{display:block;width:100%;height:auto;stroke-width:1.8}
      #${rootId} .tapis-dimension-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.72rem}
      #${rootId} .tapis-field-code{display:flex;align-items:center;justify-content:space-between;gap:.6rem}
      #${rootId} .tapis-code{border-radius:999px;background:var(--tapis-primary-soft);padding:.18rem .48rem;color:var(--tapis-primary);font-size:.7rem;font-weight:600}
      #${rootId} .tapis-unit-input{position:relative}
      #${rootId} .tapis-unit-input input{padding-right:3rem}
      #${rootId} .tapis-unit{position:absolute;right:.72rem;top:50%;transform:translateY(-50%);color:var(--tapis-muted);font-size:.78rem;font-weight:500;pointer-events:none}
      #${rootId} .tapis-option-stack{display:grid;gap:.6rem}
      #${rootId} .tapis-option{border:1px solid var(--tapis-border);border-radius:.5rem;background:#fff;padding:.72rem}
      #${rootId} .tapis-option.is-active{border-color:var(--tapis-primary);background:var(--tapis-primary-soft)}
      #${rootId} .tapis-option.is-invalid,#${rootId} .tapis-nested.is-invalid{border-color:#ef4444;background:#fff7f7}
      #${rootId} .tapis-option-label{display:flex;align-items:flex-start;gap:.6rem;color:var(--tapis-text);font-size:.88rem;font-weight:600;cursor:pointer}
      #${rootId} input[type="radio"],#${rootId} input[type="checkbox"]{accent-color:var(--tapis-primary);width:1rem;height:1rem;min-height:0;padding:0;border-radius:999px;flex:0 0 auto}
      #${rootId} .tapis-nested{display:grid;gap:.55rem;margin-top:.65rem;padding-left:1.55rem}
      #${rootId} .tapis-nested-line{display:grid;grid-template-columns:auto minmax(0,1fr) minmax(8rem,.8fr);gap:.55rem;align-items:center;color:var(--tapis-muted);font-size:.82rem;font-weight:400}
      #${rootId} .tapis-note{display:flex;gap:.6rem;align-items:flex-start;border:1px dashed var(--tapis-border-strong);border-radius:.5rem;background:var(--tapis-soft);padding:.78rem;color:var(--tapis-muted);font-size:.82rem;font-weight:400;line-height:1.45}
      #${rootId} .tapis-review{display:grid;gap:.8rem}
      #${rootId} .tapis-review-group{border:1px solid var(--tapis-border);border-radius:.5rem;background:#fff;overflow:hidden}
      #${rootId} .tapis-review-group h3{margin:0;border-bottom:1px solid var(--tapis-border);padding:.72rem .8rem;color:var(--tapis-text);font-size:.92rem;font-weight:600}
      #${rootId} .tapis-review-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0}
      #${rootId} .tapis-review-item{border-bottom:1px solid var(--tapis-border);padding:.7rem .8rem;color:var(--tapis-text);font-size:.88rem;font-weight:400;line-height:1.35}
      #${rootId} .tapis-review-item:nth-last-child(-n+2){border-bottom:0}
      #${rootId} .tapis-review-item span{display:block;margin-bottom:.2rem;color:var(--tapis-muted);font-size:.68rem;font-weight:600;text-transform:uppercase}
      #${rootId} .tapis-actions{position:sticky;bottom:.75rem;z-index:5;display:flex;justify-content:space-between;gap:.75rem;border:1px solid var(--tapis-border);border-radius:.65rem;background:rgb(255 255 255 / .94);padding:.65rem;box-shadow:0 16px 34px rgb(15 23 42 / .12);backdrop-filter:blur(12px)}
      #${rootId} .tapis-action-group{display:flex;gap:.6rem;min-width:0}
      #${rootId} .tapis-btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;min-height:2.6rem;border:1px solid var(--tapis-border);border-radius:.5rem;background:#fff;padding:0 .95rem;color:var(--tapis-text);font:inherit;font-size:.9rem;font-weight:600;text-decoration:none;cursor:pointer;transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}
      #${rootId} .tapis-btn:hover{transform:translateY(-1px);box-shadow:0 10px 20px rgb(15 23 42 / .08)}
      #${rootId} .tapis-btn:disabled{cursor:not-allowed;opacity:.56;transform:none;box-shadow:none}
      #${rootId} .tapis-btn-primary{border-color:var(--tapis-primary);background:var(--tapis-primary);color:#fff;box-shadow:0 14px 26px rgb(var(--theme-primary) / .22)}
      #${rootId} .tapis-btn-ghost{background:#fff;color:var(--tapis-primary);border-color:rgb(var(--theme-primary) / .25)}
      #${rootId} .tapis-message{display:flex;align-items:flex-start;gap:.55rem;border:1px solid var(--tapis-border);border-radius:.5rem;background:#fff;padding:.72rem .8rem;color:var(--tapis-muted);font-size:.86rem;font-weight:400;line-height:1.4}
      #${rootId} .tapis-message.is-success{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
      #${rootId} .tapis-message.is-error{border-color:#fecaca;background:#fff7f7;color:#991b1b}
      #${rootId} .tapis-message a{color:inherit;font-weight:600}
      .dark #${rootId}{--tapis-border:var(--color-surface-700,#334155);--tapis-border-strong:var(--color-surface-600,#475569);--tapis-text:#fff;--tapis-muted:var(--color-secondary-400,#94a3b8);--tapis-soft:var(--color-surface-900,#0f172a)}
      .dark #${rootId} .tapis-card,.dark #${rootId} .tapis-kpi,.dark #${rootId} input,.dark #${rootId} textarea,.dark #${rootId} select,.dark #${rootId} .tapis-choice,.dark #${rootId} .tapis-line,.dark #${rootId} .tapis-option,.dark #${rootId} .tapis-review-group,.dark #${rootId} .tapis-actions,.dark #${rootId} .tapis-message,.dark #${rootId} .tapis-prefill-action{background:var(--color-surface-900,#0f172a);border-color:var(--tapis-border)}
      @media (max-width:1100px){#${rootId} .tapis-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}#${rootId} .tapis-shell,#${rootId} .tapis-dimension-layout{grid-template-columns:1fr}#${rootId} .tapis-stepper{grid-template-columns:repeat(4,minmax(0,1fr))}#${rootId} .tapis-step-button{grid-template-columns:1fr;justify-items:center;text-align:center}#${rootId} .tapis-step-copy span{display:none}}
      @media (max-width:760px){#${rootId}{gap:.85rem}#${rootId} .tapis-page-head{align-items:flex-start;flex-direction:column;gap:.7rem}#${rootId} h1{font-size:1.55rem}#${rootId} .tapis-romus-title-logo{height:1.25rem;max-width:8rem}#${rootId} .tapis-subtitle{font-size:.88rem}#${rootId} .tapis-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem}#${rootId} .tapis-kpi{grid-template-columns:2.2rem minmax(0,1fr);gap:.55rem;padding:.72rem}#${rootId} .tapis-kpi-icon{width:2.2rem;height:2.2rem}#${rootId} .tapis-kpi-icon svg{width:1rem;height:1rem}#${rootId} .tapis-kpi span{font-size:.66rem}#${rootId} .tapis-kpi strong{font-size:1.2rem}#${rootId} .tapis-card-head{padding:.82rem}#${rootId} .tapis-stepper{grid-template-columns:repeat(4,minmax(0,1fr));gap:.35rem;padding:.5rem}#${rootId} .tapis-step-button{padding:.45rem .25rem}#${rootId} .tapis-step-number{width:1.95rem;height:1.95rem}#${rootId} .tapis-step-copy strong{font-size:.72rem}#${rootId} .tapis-prefill{grid-template-columns:2.2rem minmax(0,1fr)}#${rootId} .tapis-prefill-action{grid-column:1/-1;width:100%}#${rootId} .tapis-choice-grid,#${rootId} .tapis-form-grid,#${rootId} .tapis-dimension-grid,#${rootId} .tapis-review-grid{grid-template-columns:1fr}#${rootId} .tapis-line{grid-template-columns:1fr;gap:.55rem}#${rootId} .tapis-nested{padding-left:0}#${rootId} .tapis-nested-line{grid-template-columns:auto minmax(0,1fr)}#${rootId} .tapis-nested-line input[type="text"]{grid-column:1/-1}#${rootId} .tapis-actions{bottom:.45rem;display:grid}#${rootId} .tapis-action-group{justify-content:space-between}#${rootId} .tapis-btn{flex:1 1 0;padding:0 .7rem}#${rootId} .tapis-review-item:nth-last-child(-n+2){border-bottom:1px solid var(--tapis-border)}#${rootId} .tapis-review-item:last-child{border-bottom:0}}
    `;
    document.head.appendChild(style);
  }

  function mount() {
    if (!isRoute()) {
      return false;
    }

    const root = document.getElementById(rootId);
    if (!root) {
      return false;
    }

    ensureStyle();
    prefillContactFromSite();
    prefillContactFromProfile();
    loadProfilePrefill();
    root.innerHTML = render();

    if (root.dataset.tapisRomusBound !== "1") {
      bind(root);
      root.dataset.tapisRomusBound = "1";
    }

    return true;
  }

  function render() {
    return `
      <div class="tapis-page-head">
        <div class="tapis-page-title">
          <p class="tapis-eyebrow">Applications HUB</p>
          <h1 class="tapis-heading"><span>Tapis</span><img class="tapis-romus-title-logo" src="${romusLogoUrl}" alt="ROMUS" loading="lazy"></h1>
          <p class="tapis-subtitle">${esc(currentSiteLabel())} · Bon de commande tapis d'entrée sur mesure</p>
        </div>
      </div>
      <section class="tapis-kpis" aria-label="Avancement Tapis ROMUS">
        ${summaryCard("Commande", state.data.commandeOuDevis ? typeLabel() : "À choisir", "clipboard", "is-command")}
        ${summaryCard("Références", `${completedProductRows().length}/4`, "package", "is-references")}
        ${summaryCard("Dimensions", dimensionsComplete() ? "OK" : "À saisir", "ruler", "is-dimensions")}
        ${summaryCard("PDF", state.pdfGenerated ? "Généré" : "Prêt", state.pdfGenerated ? "fileCheck" : "fileText", "is-pdf")}
      </section>
      <section class="tapis-shell">
        <aside class="tapis-card" aria-label="Étapes">
          <div class="tapis-stepper">
            ${steps.map((step, index) => stepButton(step, index)).join("")}
          </div>
        </aside>
        <div class="tapis-workspace">
          <article class="tapis-card">
            ${renderPanel()}
          </article>
          ${state.notice ? renderNotice() : ""}
          ${renderActions()}
        </div>
      </section>
    `;
  }

  function summaryCard(label, value, icon, tone) {
    return `
      <div class="tapis-kpi ${esc(tone)}">
        <span class="tapis-kpi-icon">${svgIcon(icon)}</span>
        <span><span>${esc(label)}</span><strong>${esc(value)}</strong></span>
      </div>
    `;
  }

  function stepButton(step, index) {
    const active = index === state.step;
    const done = stepComplete(index);

    return `
      <button class="tapis-step-button${active ? " is-active" : ""}${done ? " is-done" : ""}" type="button" data-action="step" data-step="${index}" aria-current="${active ? "step" : "false"}">
        <span class="tapis-step-number">${done ? svgIcon("check") : index + 1}</span>
        <span class="tapis-step-copy"><strong>${esc(step[0])}</strong><span>${esc(step[1])}</span></span>
      </button>
    `;
  }

  function renderPanel() {
    if (state.step === 0) {
      return renderContactStep();
    }

    if (state.step === 1) {
      return renderReferencesStep();
    }

    if (state.step === 2) {
      return renderDimensionsStep();
    }

    return renderReviewStep();
  }

  function renderContactStep() {
    const isCommande = state.data.commandeOuDevis === "commande";

    return `
      <header class="tapis-card-head">
        <span class="tapis-card-head-copy">
          <h2 class="tapis-card-title">Coordonnées</h2>
          <p class="tapis-card-subtitle">Type de demande, client et chantier.</p>
        </span>
        <span class="tapis-badge">Étape 1</span>
      </header>
      <div class="tapis-panel" style="padding:1rem">
        <div class="tapis-choice-grid" data-choice-group="commandeOuDevis">
          ${choiceCard("commandeOuDevis", "commande", "Commande", "Avec numéro de commande", "clipboard")}
          ${choiceCard("commandeOuDevis", "devis", "Demande de devis", "Sans numéro de commande", "file")}
        </div>
        ${fieldError("commandeOuDevis")}
        ${siteContactNotice()}
        <div class="tapis-form-grid">
          ${field("date", "Date", "date")}
          ${field("raisonSociale", "Raison sociale")}
          ${field("nom", "Nom")}
          ${field("prenom", "Prénom")}
          ${field("adresse", "Adresse", "textarea", { wide: true })}
          ${field("tel", "Tél.", "tel")}
          ${field("email", "Email", "email")}
          ${field("refChantier", "Réf. chantier", "text", { wide: true })}
          <div class="tapis-field is-wide${state.errors.numeroCommande ? " is-invalid" : ""}" data-field-wrap="numeroCommande"${isCommande ? "" : " hidden"}>
            <label class="tapis-label" for="numeroCommande">N° de commande</label>
            <input id="numeroCommande" data-field="numeroCommande" type="text" value="${esc(state.data.numeroCommande)}" placeholder="Numéro de commande" aria-invalid="${state.errors.numeroCommande ? "true" : "false"}">
            ${fieldError("numeroCommande")}
          </div>
        </div>
      </div>
    `;
  }

  function siteContactNotice() {
    const site = currentSite();
    if (!site) {
      return `
        <div class="tapis-prefill is-warning">
          <span class="tapis-prefill-icon">${svgIcon("info")}</span>
          <span>Aucun site actif chargé pour préremplir les coordonnées. Les champs restent saisissables.</span>
        </div>
      `;
    }

    const missing = missingSiteContactLabels(site);
    const missingText = missing.length
      ? ` Coordonnées site à compléter : ${missing.join(", ")}.`
      : "";

    return `
      <div class="tapis-prefill${missing.length ? " is-warning" : ""}">
        <span class="tapis-prefill-icon">${svgIcon(missing.length ? "info" : "check")}</span>
        <span>Coordonnées préremplies depuis ${esc(site.name || "le site actif")}. Les champs restent modifiables.${esc(missingText)}</span>
        <button class="tapis-prefill-action" type="button" data-action="prefill-site">Reprendre les coordonnées du site</button>
      </div>
    `;
  }

  function renderReferencesStep() {
    return `
      <header class="tapis-card-head">
        <span class="tapis-card-head-copy">
          <h2 class="tapis-card-title">Références et quantités</h2>
          <p class="tapis-card-subtitle">Renseigner uniquement les lignes utiles.</p>
        </span>
        <span class="tapis-badge">${completedProductRows().length} ligne(s)</span>
      </header>
      <div class="tapis-panel" style="padding:1rem">
        <div class="tapis-lines">
          ${productRows.map((row) => productRow(row)).join("")}
        </div>
        <div class="tapis-note">${svgIcon("info")}<span>Si une ligne est commencée, le modèle, la référence et la quantité doivent être complétés.</span></div>
      </div>
    `;
  }

  function productRow(row) {
    const [key, label, model, reference, quantity] = row;

    return `
      <div class="tapis-line" data-product-row="${key}">
        <div class="tapis-line-name"><span class="tapis-line-icon">${svgIcon("package")}</span><span>${esc(label)}</span></div>
        ${field(model, "Modèle", "text", { compact: true })}
        ${field(reference, "Réf.", "text", { compact: true })}
        ${field(quantity, "Quantité", "number", { compact: true, min: "0", step: "1" })}
      </div>
    `;
  }

  function renderDimensionsStep() {
    const isReservation = state.data.dimensionType === "reservation";

    return `
      <header class="tapis-card-head">
        <span class="tapis-card-head-copy">
          <h2 class="tapis-card-title">Dimensions</h2>
          <p class="tapis-card-subtitle">Saisie des cotes en millimètres et configuration du cadre.</p>
        </span>
        <span class="tapis-badge">${dimensionsComplete() ? "Complet" : "À compléter"}</span>
      </header>
      <div class="tapis-panel" style="padding:1rem">
        <div class="tapis-dimension-layout">
          <div class="tapis-panel">
            <div class="tapis-diagram">${dimensionDiagram()}</div>
            <div class="tapis-dimension-grid">
              ${dimensionFields.map(([id, label, code]) => dimensionField(id, label, code)).join("")}
            </div>
          </div>
          <div class="tapis-panel">
            <div class="tapis-option-stack" data-choice-group="dimensionType">
              ${optionRadio("dimensionType", "exact", "Dimensions exactes du tapis à fabriquer")}
              ${optionRadio("dimensionType", "interieur", "Dimensions prises à l'intérieur du cadre")}
              <div class="tapis-option${isReservation ? " is-active" : ""}${state.errors.dimensionType ? " is-invalid" : ""}" data-option-wrap="dimensionType" data-option-value="reservation">
                <label class="tapis-option-label">
                  <input type="radio" name="dimensionType" data-radio-field="dimensionType" value="reservation"${isReservation ? " checked" : ""} aria-invalid="${state.errors.dimensionType ? "true" : "false"}">
                  <span>Dimensions de la réservation</span>
                </label>
                <div class="tapis-nested${state.errors.reservationMode ? " is-invalid" : ""}" id="reservationOptions">
                  <label class="tapis-nested-line${state.errors.romusRef ? " is-invalid" : ""}" data-field-wrap="romusRef">
                    <input type="radio" name="reservationMode" data-radio-field="reservationMode" value="romus"${state.data.reservationMode === "romus" ? " checked" : ""}${isReservation ? "" : " disabled"} aria-invalid="${state.errors.reservationMode ? "true" : "false"}">
                    <span>Cadre Romus - Réf.</span>
                    <input type="text" data-field="romusRef" value="${esc(state.data.romusRef)}"${isReservation ? "" : " disabled"} placeholder="Référence" aria-invalid="${state.errors.romusRef ? "true" : "false"}">
                  </label>
                  <label class="tapis-nested-line${state.errors.autreCadre ? " is-invalid" : ""}" data-field-wrap="autreCadre">
                    <input type="radio" name="reservationMode" data-radio-field="reservationMode" value="autre"${state.data.reservationMode === "autre" ? " checked" : ""}${isReservation ? "" : " disabled"} aria-invalid="${state.errors.reservationMode ? "true" : "false"}">
                    <span>Autre cadre</span>
                    <input type="text" data-field="autreCadre" value="${esc(state.data.autreCadre)}"${isReservation ? "" : " disabled"} placeholder="Largeur en mm" aria-invalid="${state.errors.autreCadre ? "true" : "false"}">
                  </label>
                  <label class="tapis-nested-line">
                    <input type="radio" name="reservationMode" data-radio-field="reservationMode" value="sans"${state.data.reservationMode === "sans" ? " checked" : ""}${isReservation ? "" : " disabled"} aria-invalid="${state.errors.reservationMode ? "true" : "false"}">
                    <span>Sans cadre</span>
                  </label>
                  ${fieldError("reservationMode")}
                  ${fieldError("romusRef")}
                  ${fieldError("autreCadre")}
                </div>
              </div>
            </div>
            ${fieldError("dimensionType")}
            <div class="tapis-note">${svgIcon("info")}<span>Si les cotes ne peuvent pas être communiquées avec le schéma, un gabarit s'impose. Toute commande validée ne pourra pas être annulée ou modifiée.</span></div>
          </div>
        </div>
      </div>
    `;
  }

  function renderReviewStep() {
    return `
      <header class="tapis-card-head">
        <span class="tapis-card-head-copy">
          <h2 class="tapis-card-title">Validation PDF</h2>
          <p class="tapis-card-subtitle">Contrôle final avant génération du bon ROMUS.</p>
        </span>
        <span class="tapis-badge">${state.pdfGenerated ? "PDF généré" : "Prêt"}</span>
      </header>
      <div class="tapis-panel" style="padding:1rem">
        <div class="tapis-review">${reviewHtml()}</div>
      </div>
    `;
  }

  function choiceCard(fieldName, value, label, detail, icon) {
    const active = state.data[fieldName] === value;
    const invalid = Boolean(state.errors[fieldName]);

    return `
      <label class="tapis-choice${active ? " is-active" : ""}${invalid ? " is-invalid" : ""}" data-choice-wrap="${fieldName}" data-choice-value="${value}">
        <input type="radio" name="${fieldName}" data-radio-field="${fieldName}" value="${value}"${active ? " checked" : ""} aria-invalid="${invalid ? "true" : "false"}">
        <span class="tapis-choice-icon">${svgIcon(icon)}</span>
        <span><span class="tapis-choice-title">${esc(label)}</span><span class="tapis-choice-detail">${esc(detail)}</span></span>
        <span class="tapis-choice-dot"></span>
      </label>
    `;
  }

  function optionRadio(fieldName, value, label) {
    const active = state.data[fieldName] === value;
    const invalid = Boolean(state.errors[fieldName]);

    return `
      <div class="tapis-option${active ? " is-active" : ""}${invalid ? " is-invalid" : ""}" data-option-wrap="${fieldName}" data-option-value="${value}">
        <label class="tapis-option-label">
          <input type="radio" name="${fieldName}" data-radio-field="${fieldName}" value="${value}"${active ? " checked" : ""} aria-invalid="${invalid ? "true" : "false"}">
          <span>${esc(label)}</span>
        </label>
      </div>
    `;
  }

  function fieldError(fieldName) {
    const message = state.errors[fieldName];

    return message ? `<p class="tapis-field-error" data-error-for="${esc(fieldName)}">${esc(message)}</p>` : "";
  }

  function field(id, label, type = "text", options = {}) {
    const classes = ["tapis-field"];
    const error = state.errors[id] || "";
    if (options.wide) {
      classes.push("is-wide");
    }
    if (options.compact) {
      classes.push("is-compact");
    }
    if (error) {
      classes.push("is-invalid");
    }

    const attrs = [
      `id="${esc(id)}"`,
      `data-field="${esc(id)}"`,
      `value="${esc(state.data[id])}"`,
      options.min ? `min="${esc(options.min)}"` : "",
      options.step ? `step="${esc(options.step)}"` : "",
      options.placeholder ? `placeholder="${esc(options.placeholder)}"` : "",
    ].filter(Boolean).join(" ");

    if (type === "textarea") {
      return `
        <div class="${classes.join(" ")}" data-field-wrap="${esc(id)}">
          <label class="tapis-label" for="${esc(id)}">${esc(label)}</label>
          <textarea id="${esc(id)}" data-field="${esc(id)}" aria-invalid="${error ? "true" : "false"}">${esc(state.data[id])}</textarea>
          ${fieldError(id)}
        </div>
      `;
    }

    return `
      <div class="${classes.join(" ")}" data-field-wrap="${esc(id)}">
        <label class="tapis-label" for="${esc(id)}">${esc(label)}</label>
        <input type="${esc(type)}" ${attrs} aria-invalid="${error ? "true" : "false"}">
        ${fieldError(id)}
      </div>
    `;
  }

  function dimensionField(id, label, code) {
    const error = state.errors[id] || "";

    return `
      <div class="tapis-field${error ? " is-invalid" : ""}" data-field-wrap="${esc(id)}">
        <label class="tapis-label tapis-field-code" for="${esc(id)}"><span>${esc(label)}</span><span class="tapis-code">${esc(code)}</span></label>
        <span class="tapis-unit-input">
          <input id="${esc(id)}" data-field="${esc(id)}" type="number" min="0" step="1" value="${esc(state.data[id])}" aria-invalid="${error ? "true" : "false"}">
          <span class="tapis-unit">mm</span>
        </span>
        ${fieldError(id)}
      </div>
    `;
  }

  function renderActions() {
    const canGoBack = state.step > 0;
    const isLast = state.step === 3;

    return `
      <div class="tapis-actions">
        <div class="tapis-action-group">
          <button class="tapis-btn tapis-btn-ghost" type="button" data-action="prev"${canGoBack ? "" : " disabled"}>${svgIcon("arrow")} Retour</button>
        </div>
        <div class="tapis-action-group">
          ${isLast && state.pdfUrl ? `<a class="tapis-btn" href="${esc(state.pdfUrl)}" download="BON_DE_COMMANDE_TAPIS_ROMUS_rempli.pdf">${svgIcon("download")} Télécharger</a>` : ""}
          <button class="tapis-btn tapis-btn-primary" type="button" data-action="${isLast ? "generate" : "next"}"${state.isGenerating ? " disabled" : ""}>
            ${isLast ? svgIcon("download") : svgIcon("check")}
            ${isLast ? (state.isGenerating ? "Génération..." : "Générer le PDF") : "Étape suivante"}
          </button>
        </div>
      </div>
    `;
  }

  function renderNotice() {
    const type = state.notice.type === "success" ? "is-success" : "is-error";

    return `<div class="tapis-message ${type}">${svgIcon(state.notice.type === "success" ? "check" : "info")}<span>${state.notice.html}</span></div>`;
  }

  function bind(root) {
    root.addEventListener("input", handleInput);
    root.addEventListener("change", handleInput);
    root.addEventListener("click", handleClick);
  }

  function handleInput(event) {
    const field = event.target && event.target.closest ? event.target.closest("[data-field]") : null;
    const radio = event.target && event.target.closest ? event.target.closest("[data-radio-field]") : null;

    if (!field && !radio) {
      return;
    }

    state.notice = null;
    state.pdfGenerated = false;

    if (field) {
      state.data[field.dataset.field] = field.value;
      if (["raisonSociale", "adresse", "tel", "email"].includes(field.dataset.field)) {
        state.sitePrefill.touched[field.dataset.field] = true;
      }
      if (["nom", "prenom"].includes(field.dataset.field)) {
        state.profilePrefill.touched[field.dataset.field] = true;
      }
      clearFieldError(field.dataset.field);
    }

    if (radio && radio.checked) {
      state.data[radio.dataset.radioField] = radio.value;
      if (radio.dataset.radioField === "commandeOuDevis" && radio.value !== "commande") {
        state.data.numeroCommande = "";
        clearFieldError("numeroCommande");
      }
      if (radio.dataset.radioField === "dimensionType" && radio.value !== "reservation") {
        clearFieldError("reservationMode");
        clearFieldError("romusRef");
        clearFieldError("autreCadre");
      }
      clearFieldError(radio.dataset.radioField);
      syncGroups();
    }

    if (state.step === 3) {
      scheduleMount();
    }
  }

  function handleClick(event) {
    const action = event.target && event.target.closest ? event.target.closest("[data-action]") : null;
    if (!action) {
      return;
    }

    const type = action.dataset.action;

    if (type === "next") {
      if (validateStep(state.step)) {
        state.step = Math.min(state.step + 1, 3);
        state.notice = null;
        scheduleMount();
      }
      return;
    }

    if (type === "prev") {
      state.step = Math.max(state.step - 1, 0);
      state.notice = null;
      scheduleMount();
      return;
    }

    if (type === "step") {
      state.step = Number(action.dataset.step || 0);
      state.notice = null;
      scheduleMount();
      return;
    }

    if (type === "prefill-site") {
      prefillContactFromSite({ force: true });
      state.notice = null;
      scheduleMount();
      return;
    }

    if (type === "generate") {
      generatePdf();
    }
  }

  function syncGroups() {
    const root = document.getElementById(rootId);
    if (!root) {
      return;
    }

    root.querySelectorAll("[data-choice-wrap]").forEach((item) => {
      item.classList.toggle("is-active", state.data[item.dataset.choiceWrap] === item.dataset.choiceValue);
    });
    root.querySelectorAll("[data-option-wrap]").forEach((item) => {
      item.classList.toggle("is-active", state.data[item.dataset.optionWrap] === item.dataset.optionValue);
    });

    const commandField = root.querySelector('[data-field-wrap="numeroCommande"]');
    if (commandField) {
      commandField.hidden = state.data.commandeOuDevis !== "commande";
    }

    root.querySelectorAll("#reservationOptions input").forEach((input) => {
      input.disabled = state.data.dimensionType !== "reservation";
    });
  }

  function validateStep(step, { silent = false } = {}) {
    let ok = true;

    if (!silent) {
      state.notice = null;
      state.errors = {};
      clearInvalid();
    }

    if (step === 0) {
      const required = {
        commandeOuDevis: "Choisissez Commande ou Demande de devis.",
        date: "La date est obligatoire.",
        raisonSociale: "La raison sociale du site est obligatoire.",
        nom: "Le nom est obligatoire.",
        prenom: "Le prénom est obligatoire.",
        adresse: "L'adresse est obligatoire.",
        tel: "Le téléphone est obligatoire.",
        email: "L'e-mail est obligatoire.",
        refChantier: "La référence chantier est obligatoire.",
      };

      if (state.data.commandeOuDevis === "commande") {
        required.numeroCommande = "Le numéro de commande est obligatoire.";
      }

      Object.entries(required).forEach(([fieldName, message]) => {
        if (!isFilledValue(state.data[fieldName])) {
          ok = false;
          if (!silent) {
            setFieldError(fieldName, message, fieldName === "commandeOuDevis" ? "choice" : "field");
          }
        }
      });

      if (isFilledValue(state.data.date) && !isValidDate(state.data.date)) {
        ok = false;
        if (!silent) {
          setFieldError("date", "La date est invalide.");
        }
      }

      if (isFilledValue(state.data.tel) && !isValidPhone(state.data.tel)) {
        ok = false;
        if (!silent) {
          setFieldError("tel", "Le téléphone est invalide.");
        }
      }

      if (isFilledValue(state.data.email) && !/^\S+@\S+\.\S+$/.test(state.data.email.trim())) {
        ok = false;
        if (!silent) {
          setFieldError("email", "L'e-mail est invalide.");
        }
      }
    }

    if (step === 1) {
      const rows = productRows.map((row) => row.slice(2));
      const completeRows = rows.filter((row) => row.every((fieldName) => isFilledValue(state.data[fieldName])));

      if (completeRows.length === 0) {
        ok = false;
        if (!silent) {
          rows[0].forEach((fieldName) => setFieldError(fieldName, "Renseignez au moins une ligne article complète."));
        }
      }

      rows.forEach((row) => {
        const filledCount = row.filter((fieldName) => isFilledValue(state.data[fieldName])).length;
        if (filledCount > 0 && filledCount < row.length) {
          ok = false;
          if (!silent) {
            row.forEach((fieldName) => {
              if (!isFilledValue(state.data[fieldName])) {
                setFieldError(fieldName, "Champ requis pour cette ligne.");
              }
            });
          }
        }

        const quantityField = row[2];
        if (isFilledValue(state.data[quantityField]) && !isPositiveNumber(state.data[quantityField])) {
          ok = false;
          if (!silent) {
            setFieldError(quantityField, "Quantité invalide.");
          }
        }
      });
    }

    if (step === 2) {
      dimensionFields.forEach(([fieldName]) => {
        if (!isFilledValue(state.data[fieldName])) {
          ok = false;
          if (!silent) {
            setFieldError(fieldName, "Cote obligatoire.");
          }
        } else if (!isPositiveNumber(state.data[fieldName])) {
          ok = false;
          if (!silent) {
            setFieldError(fieldName, "Cote invalide.");
          }
        }
      });

      if (!isFilledValue(state.data.dimensionType)) {
        ok = false;
        if (!silent) {
          setFieldError("dimensionType", "Choisissez le type de dimensions.", "choice");
        }
      }

      if (state.data.dimensionType === "reservation") {
        if (!isFilledValue(state.data.reservationMode)) {
          ok = false;
          if (!silent) {
            setFieldError("reservationMode", "Choisissez la configuration du cadre.", "choice");
          }
        }

        if (state.data.reservationMode === "romus" && !isFilledValue(state.data.romusRef)) {
          ok = false;
          if (!silent) {
            setFieldError("romusRef", "Référence du cadre Romus obligatoire.");
          }
        }

        if (state.data.reservationMode === "autre" && !isFilledValue(state.data.autreCadre)) {
          ok = false;
          if (!silent) {
            setFieldError("autreCadre", "Largeur du cadre obligatoire.");
          }
        } else if (state.data.reservationMode === "autre" && !isPositiveNumber(state.data.autreCadre)) {
          ok = false;
          if (!silent) {
            setFieldError("autreCadre", "Largeur du cadre invalide.");
          }
        }
      }
    }

    if (!ok && !silent) {
      state.notice = {
        type: "error",
        html: "Toutes les informations nécessaires de cette étape doivent être complétées avant de continuer.",
      };
      scheduleMount();
    }

    return ok;
  }

  function validateAllSteps() {
    for (let index = 0; index <= 2; index += 1) {
      if (!validateStep(index, { silent: true })) {
        state.step = index;
        state.errors = {};
        clearInvalid();
        validateStep(index, { silent: false });
        state.notice = {
          type: "error",
          html: "Complétez les étapes précédentes avant de générer le PDF ROMUS.",
        };
        scheduleMount();

        return false;
      }
    }

    return true;
  }

  function clearInvalid() {
    const root = document.getElementById(rootId);
    if (!root) {
      return;
    }

    root.querySelectorAll(".is-invalid").forEach((item) => item.classList.remove("is-invalid"));
  }

  function setFieldError(fieldName, message, type = "field") {
    state.errors[fieldName] = message;
    type === "choice" ? markChoice(fieldName, true) : markField(fieldName, true);
  }

  function clearFieldError(fieldName) {
    delete state.errors[fieldName];
    markField(fieldName, false);
    markChoice(fieldName, false);

    const root = document.getElementById(rootId);
    if (!root) {
      return;
    }

    root.querySelectorAll(`[data-error-for="${cssEscape(fieldName)}"]`).forEach((item) => item.remove());
  }

  function markField(fieldName, invalid) {
    const root = document.getElementById(rootId);
    const escapedFieldName = cssEscape(fieldName);
    const selector = `[data-field-wrap="${escapedFieldName}"], [data-field="${escapedFieldName}"]`;
    const items = root ? root.querySelectorAll(selector) : [];
    items.forEach((item) => item.classList.toggle("is-invalid", invalid));
  }

  function markChoice(fieldName, invalid) {
    const root = document.getElementById(rootId);
    if (!root) {
      return;
    }

    if (fieldName === "reservationMode") {
      root.querySelectorAll('[name="reservationMode"]').forEach((item) => item.classList.toggle("is-invalid", invalid));
      root.querySelectorAll("#reservationOptions").forEach((item) => item.classList.toggle("is-invalid", invalid));
      return;
    }

    const escapedFieldName = cssEscape(fieldName);
    root.querySelectorAll(`[data-choice-wrap="${escapedFieldName}"], [data-option-wrap="${escapedFieldName}"]`).forEach((item) => {
      item.classList.toggle("is-invalid", invalid);
    });
  }

  function isFilledValue(value) {
    return String(value ?? "").trim() !== "";
  }

  function isValidDate(value) {
    return /^\d{4}-\d{2}-\d{2}$/.test(String(value || ""))
      && !Number.isNaN(Date.parse(`${value}T00:00:00`));
  }

  function isValidPhone(value) {
    const text = String(value || "").trim();
    const digits = text.replace(/\D/g, "");

    return digits.length >= 8 && /^[+()0-9 .-]+$/.test(text);
  }

  function isPositiveNumber(value) {
    const number = Number(value);

    return Number.isFinite(number) && number > 0;
  }

  function stepComplete(index) {
    if (index <= 2) {
      return validateStep(index, { silent: true });
    }

    return state.pdfGenerated;
  }

  function completedProductRows() {
    return productRows.filter((row) => row.slice(2).every((fieldName) => isFilledValue(state.data[fieldName])));
  }

  function dimensionsComplete() {
    return dimensionFields.every(([fieldName]) => isFilledValue(state.data[fieldName]));
  }

  function typeLabel() {
    if (state.data.commandeOuDevis === "commande") {
      return "Commande";
    }

    if (state.data.commandeOuDevis === "devis") {
      return "Devis";
    }

    return "À choisir";
  }

  function formatDateFr(isoDate) {
    if (!isoDate) {
      return "";
    }

    const [year, month, day] = isoDate.split("-");

    return day && month && year ? `${day}/${month}/${year}` : isoDate;
  }

  function dimensionTypeLabel() {
    if (state.data.dimensionType === "exact") {
      return "Dimensions exactes du tapis à fabriquer";
    }

    if (state.data.dimensionType === "interieur") {
      return "Dimensions prises à l'intérieur du cadre";
    }

    if (state.data.dimensionType === "reservation") {
      return "Dimensions de la réservation";
    }

    return "-";
  }

  function reservationLabel() {
    if (state.data.dimensionType !== "reservation") {
      return "-";
    }

    if (state.data.reservationMode === "romus") {
      return `Cadre Romus - Réf. ${state.data.romusRef}`;
    }

    if (state.data.reservationMode === "autre") {
      return `Autre cadre - largeur ${state.data.autreCadre} mm`;
    }

    if (state.data.reservationMode === "sans") {
      return "Sans cadre";
    }

    return "-";
  }

  function reviewHtml() {
    const productItems = completedProductRows().map((row) => {
      const [, label, model, reference, quantity] = row;

      return reviewItem(label, `${state.data[model]} · ${state.data[reference]} · Qté ${state.data[quantity]}`);
    }).join("");

    return `
      <div class="tapis-review-group">
        <h3>Coordonnées</h3>
        <div class="tapis-review-grid">
          ${reviewItem("Type", state.data.commandeOuDevis === "commande" ? `Commande n° ${state.data.numeroCommande}` : "Demande de devis")}
          ${reviewItem("Date", formatDateFr(state.data.date))}
          ${reviewItem("Raison sociale", state.data.raisonSociale)}
          ${reviewItem("Nom / Prénom", `${state.data.nom} ${state.data.prenom}`)}
          ${reviewItem("Adresse", state.data.adresse)}
          ${reviewItem("Contact", `${state.data.tel} · ${state.data.email}`)}
          ${reviewItem("Réf. chantier", state.data.refChantier)}
        </div>
      </div>
      <div class="tapis-review-group">
        <h3>Références et quantités</h3>
        <div class="tapis-review-grid">${productItems || reviewItem("Lignes", "Aucune ligne complète")}</div>
      </div>
      <div class="tapis-review-group">
        <h3>Dimensions</h3>
        <div class="tapis-review-grid">
          ${reviewItem("L1", `${state.data.l1} mm`)}
          ${reviewItem("L2", `${state.data.l2} mm`)}
          ${reviewItem("P1", `${state.data.p1} mm`)}
          ${reviewItem("P2", `${state.data.p2} mm`)}
          ${reviewItem("Diagonale", `${state.data.diagonale} mm`)}
          ${reviewItem("Type de dimensions", dimensionTypeLabel())}
          ${reviewItem("Configuration cadre", reservationLabel())}
        </div>
      </div>
    `;
  }

  function reviewItem(label, value) {
    return `<div class="tapis-review-item"><span>${esc(label)}</span>${esc(value).replace(/\n/g, "<br>")}</div>`;
  }

  async function generatePdf() {
    if (!validateAllSteps()) {
      return;
    }

    state.isGenerating = true;
    state.notice = null;
    scheduleMount();

    try {
      const pdfLib = await loadPdfLib();
      const { PDFDocument } = pdfLib;
      const response = await fetch(templatePdfUrl, { cache: "force-cache" });

      if (!response.ok) {
        throw new Error(`Modèle PDF introuvable (${response.status})`);
      }

      const existingPdfBytes = await response.arrayBuffer();
      const pdfDoc = await PDFDocument.load(existingPdfBytes);
      const form = pdfDoc.getForm();
      const page = pdfDoc.getPages()[0];
      const regularFont = await pdfDoc.embedFont(pdfLib.StandardFonts.Helvetica);
      const boldFont = await pdfDoc.embedFont(pdfLib.StandardFonts.HelveticaBold);

      fillPdf(form, page, regularFont, boldFont, pdfLib);

      const pdfBytes = await pdfDoc.save();
      const blob = new Blob([pdfBytes], { type: "application/pdf" });
      const url = URL.createObjectURL(blob);

      if (state.pdfUrl) {
        URL.revokeObjectURL(state.pdfUrl);
      }

      state.pdfUrl = url;
      state.pdfGenerated = true;
      downloadPdf(url);
      state.notice = {
        type: "success",
        html: `PDF généré avec succès. <a href="${esc(url)}" download="BON_DE_COMMANDE_TAPIS_ROMUS_rempli.pdf">Télécharger à nouveau le PDF rempli</a>`,
      };
    } catch (error) {
      console.error("[tapis-romus] PDF generation failed", error);
      state.notice = {
        type: "error",
        html: `Impossible de générer le PDF ROMUS : ${esc(error && error.message ? error.message : "erreur inconnue")}.`,
      };
    } finally {
      state.isGenerating = false;
      scheduleMount();
    }
  }

  function fillPdf(form, page, regularFont, boldFont, pdfLib) {
    const data = state.data;
    const setText = (name, value) => {
      try {
        form.getTextField(name).setText(value || "");
      } catch (error) {
        console.warn("[tapis-romus] Champ texte introuvable:", name, error);
      }
    };
    const setFontSize = (name, size) => {
      try {
        form.getTextField(name).setFontSize(size);
      } catch (error) {
        console.warn("[tapis-romus] Taille de champ introuvable:", name, error);
      }
    };
    const setCheck = (name, checked) => {
      try {
        const checkbox = form.getCheckBox(name);
        checked ? checkbox.check() : checkbox.uncheck();
      } catch (error) {
        console.warn("[tapis-romus] Case introuvable:", name, error);
      }
    };
    const setRadio = (name, value) => {
      try {
        form.getRadioGroup(name).select(value);
      } catch (error) {
        console.warn("[tapis-romus] Radio introuvable:", name, error);
      }
    };

    setRadio("Commande ou devis", data.commandeOuDevis === "commande" ? "Choix1" : "Choix2");
    setText("Date", formatDateFr(data.date));
    setText(" Raison Sociale", data.raisonSociale);
    setText(" Nom", data.nom);
    setText(" Prénom", data.prenom);
    setText(" Adresse", data.adresse);
    setText(" Tél", data.tel);
    setText(" Fax", data.email);
    setText(" Réf Chantier", data.refChantier);

    setText("ModèleTapis", data.modeleTapis);
    setText("RéfTapis", data.refTapis);
    setText("QuantitéTapis", data.qteTapis);
    setText("ModèleCadre", data.modeleCadre);
    setText("RéfCadre", data.refCadre);
    setText("QuantitéCadre", data.qteCadre);
    setText("ModèleEquerres", data.modeleEquerres);
    setText("RéfEquerres", data.refEquerres);
    setText("QuantitéEquerres", data.qteEquerres);
    setText("ModèlePattes de fixation", data.modelePattes);
    setText("RéfPattes de fixation", data.refPattes);
    setText("QuantitéPattes de fixation", data.qtePattes);

    [
      "ModèleTapis",
      "RéfTapis",
      "QuantitéTapis",
      "ModèleCadre",
      "RéfCadre",
      "QuantitéCadre",
      "ModèleEquerres",
      "RéfEquerres",
      "QuantitéEquerres",
      "ModèlePattes de fixation",
      "RéfPattes de fixation",
      "QuantitéPattes de fixation",
    ].forEach((name) => setFontSize(name, 9));

    setText("Longueur L1", data.l1);
    setText("Longueur L2", data.l2);
    setText("Profondeur P1", data.p1);
    setText("Profondeur P2", data.p2);
    setText("Dimension Exact", "");

    setCheck("Dimensions exactes du tapis à fabriquer", data.dimensionType === "exact");
    setCheck("Dimensions prises à lintérieur du cadre1", data.dimensionType === "interieur");
    setCheck("Dimensions de la réservation dans laquelle1", data.dimensionType === "reservation");
    setCheck("Je mettrai un cadre Romus lequel  Réf", data.reservationMode === "romus");
    setCheck("Je mettrai un autre cadre précisez sa largeur en mm", data.reservationMode === "autre");
    setCheck("Je ne mettrai pas de cadre", data.reservationMode === "sans");

    setText("undefined_14", data.romusRef);
    setText("Autre cadre", data.autreCadre);
    setText("Dimension Int", data.dimensionType === "interieur" ? "Oui" : "");

    try {
      form.flatten();
    } catch (error) {
      console.warn("[tapis-romus] Flatten non appliqué", error);
    }

    if (data.commandeOuDevis === "commande" && data.numeroCommande) {
      drawFittedText(page, boldFont, pdfLib, data.numeroCommande, 214, 777.2, 76, 10, 8);
    }

    if (data.diagonale) {
      const boxX = 148;
      const boxY = 266;
      const boxHeight = 19.843;
      const valueAreaWidth = 44;
      const size = fitTextSize(regularFont, data.diagonale, valueAreaWidth, 10, 7.5);
      const textWidth = regularFont.widthOfTextAtSize(data.diagonale, size);

      page.drawText(data.diagonale, {
        x: boxX + ((valueAreaWidth - textWidth) / 2) + 3,
        y: boxY + ((boxHeight - size) / 2) + 1,
        size,
        font: regularFont,
        color: pdfLib.rgb(0.1, 0.1, 0.1),
      });
    }
  }

  function drawFittedText(page, font, pdfLib, text, x, y, maxWidth, preferredSize, minSize) {
    const size = fitTextSize(font, text, maxWidth, preferredSize, minSize);

    page.drawText(text, {
      x,
      y,
      size,
      font,
      color: pdfLib.rgb(0.1, 0.1, 0.1),
    });
  }

  function fitTextSize(font, text, maxWidth, preferredSize, minSize = 7) {
    let size = preferredSize;

    while (size > minSize && font.widthOfTextAtSize(text, size) > maxWidth) {
      size -= 0.5;
    }

    return size;
  }

  function downloadPdf(url) {
    const link = document.createElement("a");
    link.href = url;
    link.download = "BON_DE_COMMANDE_TAPIS_ROMUS_rempli.pdf";
    link.rel = "noopener";
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  function loadPdfLib() {
    if (window.PDFLib && window.PDFLib.PDFDocument) {
      return Promise.resolve(window.PDFLib);
    }

    if (pdfLibPromise) {
      return pdfLibPromise;
    }

    pdfLibPromise = new Promise((resolve, reject) => {
      const existing = document.querySelector(`script[src="${pdfLibUrl}"]`);

      if (existing) {
        existing.addEventListener("load", () => resolve(window.PDFLib), { once: true });
        existing.addEventListener("error", () => reject(new Error("Chargement de pdf-lib impossible")), { once: true });
        return;
      }

      const script = document.createElement("script");
      script.src = pdfLibUrl;
      script.async = true;
      script.dataset.tapisPdfLib = "true";
      script.onload = () => resolve(window.PDFLib);
      script.onerror = () => reject(new Error("Chargement de pdf-lib impossible"));
      document.head.appendChild(script);
    });

    return pdfLibPromise;
  }

  function dimensionDiagram() {
    return `
      <svg viewBox="0 0 760 430" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <defs>
          <pattern id="tapisDiagramGrid" width="24" height="24" patternUnits="userSpaceOnUse">
            <path d="M 24 0 L 0 0 0 24" fill="none" stroke="#dbe4eb" stroke-width="1"></path>
          </pattern>
          <marker id="tapisDiagramArrow" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
            <path d="M 0 0 L 10 5 L 0 10 z" fill="rgb(var(--theme-primary))"></path>
          </marker>
        </defs>
        <rect x="12" y="12" width="736" height="406" rx="22" fill="url(#tapisDiagramGrid)"></rect>
        <rect x="174" y="110" width="412" height="188" rx="18" fill="#cfd8df"></rect>
        <rect x="188" y="124" width="384" height="160" rx="12" fill="#203247"></rect>
        <g opacity="0.18">
          <rect x="188" y="144" width="384" height="18" fill="#ffffff"></rect>
          <rect x="188" y="182" width="384" height="18" fill="#ffffff"></rect>
          <rect x="188" y="220" width="384" height="18" fill="#ffffff"></rect>
          <rect x="188" y="258" width="384" height="18" fill="#ffffff"></rect>
        </g>
        <line x1="194" y1="276" x2="568" y2="132" stroke="#ffffff" stroke-width="3" opacity="0.95" marker-start="url(#tapisDiagramArrow)" marker-end="url(#tapisDiagramArrow)"></line>
        <line x1="188" y1="110" x2="188" y2="78" stroke="rgb(var(--theme-primary))" stroke-width="3"></line>
        <line x1="572" y1="110" x2="572" y2="78" stroke="rgb(var(--theme-primary))" stroke-width="3"></line>
        <line x1="188" y1="78" x2="572" y2="78" stroke="rgb(var(--theme-primary))" stroke-width="3" marker-start="url(#tapisDiagramArrow)" marker-end="url(#tapisDiagramArrow)"></line>
        <line x1="188" y1="298" x2="188" y2="330" stroke="rgb(var(--theme-primary))" stroke-width="3"></line>
        <line x1="572" y1="298" x2="572" y2="330" stroke="rgb(var(--theme-primary))" stroke-width="3"></line>
        <line x1="188" y1="330" x2="572" y2="330" stroke="rgb(var(--theme-primary))" stroke-width="3" marker-start="url(#tapisDiagramArrow)" marker-end="url(#tapisDiagramArrow)"></line>
        <line x1="174" y1="124" x2="134" y2="124" stroke="rgb(var(--theme-primary))" stroke-width="3"></line>
        <line x1="174" y1="284" x2="134" y2="284" stroke="rgb(var(--theme-primary))" stroke-width="3"></line>
        <line x1="134" y1="124" x2="134" y2="284" stroke="rgb(var(--theme-primary))" stroke-width="3" marker-start="url(#tapisDiagramArrow)" marker-end="url(#tapisDiagramArrow)"></line>
        <line x1="586" y1="124" x2="626" y2="124" stroke="rgb(var(--theme-primary))" stroke-width="3"></line>
        <line x1="586" y1="284" x2="626" y2="284" stroke="rgb(var(--theme-primary))" stroke-width="3"></line>
        <line x1="626" y1="124" x2="626" y2="284" stroke="rgb(var(--theme-primary))" stroke-width="3" marker-start="url(#tapisDiagramArrow)" marker-end="url(#tapisDiagramArrow)"></line>
        <line x1="286" y1="360" x2="474" y2="360" stroke="rgb(var(--theme-primary))" stroke-width="5" marker-end="url(#tapisDiagramArrow)"></line>
        <text x="380" y="390" text-anchor="middle" font-size="18" font-weight="600" fill="rgb(var(--theme-primary))">Sens de la marche</text>
        ${diagramLabel("L1", 380, 70)}
        ${diagramLabel("L2", 380, 336)}
        ${diagramLabel("P1", 100, 208)}
        ${diagramLabel("P2", 660, 208)}
        ${diagramLabel("DIAG", 266, 256, 112)}
      </svg>
    `;
  }

  function diagramLabel(label, x, y, width = 96) {
    const rectX = x - (width / 2);

    return `
      <g>
        <rect x="${rectX}" y="${y - 24}" width="${width}" height="38" rx="19" fill="#ffffff" stroke="#d9e0e6"></rect>
        <text x="${x}" y="${y}" text-anchor="middle" font-size="18" font-weight="600" fill="#203247">${esc(label)}</text>
      </g>
    `;
  }

  function scheduleMount() {
    if (mountTimer) {
      window.clearTimeout(mountTimer);
    }

    mountTimer = window.setTimeout(() => {
      mountTimer = null;
      mount();
    }, 0);
  }

  function boot() {
    scheduleMount();
    routeEvents.forEach((eventName) => window.addEventListener(eventName, scheduleMount));
    window.addEventListener("crm:profile-updated", (event) => {
      const profile = event.detail && event.detail.profile ? event.detail.profile : null;
      if (!profile) {
        return;
      }

      state.profilePrefill.profile = profile;
      state.profilePrefill.loaded = true;

      if (prefillContactFromProfile(profile) && isRoute()) {
        scheduleMount();
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
})();

(() => {
  const api = "/api/documents";
  const routePattern = /^\/documents\/(promo|fiches-techniques|procedures)$/;
  const mountedRoots = new WeakSet();
  let root = null;
  let hostRetryTimer = null;
  let hostRetryAttempts = 0;

  const state = {
    data: null,
    category: "",
    filters: { query: "", directoryId: "" },
    loading: false,
    saving: false,
    directoryModalOpen: false,
    documentModalOpen: false,
    directoryDraft: emptyDirectoryDraft(),
    documentDraft: emptyDocumentDraft(),
    fileLabel: "",
  };

  const esc = (value) => String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");

  function emptyDirectoryDraft() {
    return {
      id: "",
      name: "",
      description: "",
      parentId: "",
      visibility: "restricted",
      sortOrder: 100,
    };
  }

  function emptyDocumentDraft() {
    return {
      id: "",
      name: "",
      description: "",
      directoryId: "",
      visibility: "restricted",
      sortOrder: 100,
      fileDataUrl: "",
      fileName: "",
    };
  }

  function categoryFromPath() {
    const match = window.location.pathname.replace(/\/$/, "").match(routePattern);
    return match ? match[1] : "";
  }

  function isRoute() {
    return Boolean(categoryFromPath());
  }

  function activeSiteId() {
    const fromApi = Number(window.CRM_ACTIVE_SITE?.getSiteId?.() || 0);
    if (Number.isFinite(fromApi) && fromApi > 0) return fromApi;

    try {
      const stored = Number(window.localStorage.getItem("crm:active-site-id") || 0);
      if (Number.isFinite(stored) && stored > 0) return stored;
    } catch (error) {
      // Backend fallback.
    }

    return Number(state.data?.selectedSiteId || 0) || "";
  }

  async function request(action, body = null) {
    const params = new URLSearchParams({ action, category: state.category || categoryFromPath() });
    const siteId = activeSiteId();
    if (siteId) params.set("siteId", String(siteId));

    const response = await fetch(`${api}?${params.toString()}`, {
      method: body ? "POST" : "GET",
      credentials: "same-origin",
      headers: body ? { "Content-Type": "application/json", Accept: "application/json" } : { Accept: "application/json" },
      body: body ? JSON.stringify(body) : undefined,
    });
    const payload = await response.json();

    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || "Action impossible");
    }

    return payload;
  }

  async function load() {
    if (!isRoute()) return;
    state.category = categoryFromPath();
    state.loading = true;
    render();

    try {
      state.data = await request("bootstrap");
    } catch (error) {
      state.data = { ok: false, error: error instanceof Error ? error.message : "Erreur documents" };
    } finally {
      state.loading = false;
      render();
    }
  }

  function categoryLabel() {
    return state.data?.category?.label || {
      promo: "Promo",
      "fiches-techniques": "Fiches techniques",
      procedures: "Procédures",
    }[state.category] || "Documents";
  }

  function activeSiteName() {
    const siteId = Number(state.data?.selectedSiteId || activeSiteId());
    const site = (state.data?.sites || []).find((item) => Number(item.id) === siteId);
    return site?.name || "Site actif";
  }

  function documents() {
    const query = normalize(state.filters.query);
    const directoryId = String(state.filters.directoryId || "");

    return (state.data?.documents || []).filter((document) => {
      if (directoryId === "root" && document.directoryId) return false;
      if (directoryId && directoryId !== "root" && String(document.directoryId || "") !== directoryId) return false;
      if (!query) return true;

      return [
        document.name,
        document.description,
        document.originalName,
        document.directoryName,
        document.createdBy,
      ].some((value) => normalize(value).includes(query));
    });
  }

  function normalize(value) {
    return String(value || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase();
  }

  function fileKind(mimeType = "") {
    if (mimeType.includes("pdf")) return "PDF";
    if (mimeType.includes("image")) return "Image";
    if (mimeType.includes("word")) return "Word";
    if (mimeType.includes("excel") || mimeType.includes("sheet")) return "Excel";
    if (mimeType.includes("powerpoint") || mimeType.includes("presentation")) return "PowerPoint";
    if (mimeType.includes("csv")) return "CSV";
    if (mimeType.includes("text")) return "Texte";
    return "Fichier";
  }

  function dateLabel(value) {
    if (!value) return "-";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "-";
    return date.toLocaleDateString("fr-FR", { day: "2-digit", month: "short", year: "numeric" });
  }

  function sizeLabel(bytes) {
    const value = Number(bytes || 0);
    if (value <= 0) return "0 B";
    const units = ["B", "KB", "MB", "GB"];
    const power = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
    return `${(value / (1024 ** power)).toFixed(power ? 1 : 0).replace(".", ",")} ${units[power]}`;
  }

  function render() {
    if (!root || !isRoute()) return;
    styles();

    if (state.loading && !state.data) {
      root.innerHTML = `<div class="doc-loading">Chargement des documents...</div>`;
      return;
    }

    if (state.data?.ok === false) {
      root.innerHTML = `<div class="doc-empty">${esc(state.data.error || "Impossible de charger les documents.")}</div>`;
      return;
    }

    const canManage = Boolean(state.data?.canManage);
    const docs = documents();
    const directories = state.data?.directories || [];

    root.innerHTML = `
      <div class="doc-page">
        ${renderHeader(canManage)}
        ${renderStats(docs)}
        ${renderFilters(directories)}
        ${renderDirectories(directories, canManage)}
        ${renderDocuments(docs, canManage)}
        ${state.directoryModalOpen ? renderDirectoryModal() : ""}
        ${state.documentModalOpen ? renderDocumentModal() : ""}
      </div>
    `;

    bind(canManage);
  }

  function renderHeader(canManage) {
    const categories = state.data?.categories || [];
    const nav = categories.map((category) => `
      <a class="doc-tab ${category.slug === state.category ? "is-active" : ""}" href="${esc(category.route)}">${esc(category.label)}</a>
    `).join("");

    return `
      <header class="doc-header">
        <div class="doc-title">
          <span>Documents</span>
          <h1>${esc(categoryLabel())}</h1>
          <p>${esc(activeSiteName())}</p>
        </div>
        ${canManage ? `
          <div class="doc-actions">
            <button class="doc-button" type="button" data-new-directory>${icon("folder-plus")}<span>Dossier</span></button>
            <button class="doc-button doc-button-primary" type="button" data-new-document>${icon("upload")}<span>Ajouter</span></button>
          </div>
        ` : ""}
      </header>
      <nav class="doc-tabs" aria-label="Catégories documents">${nav}</nav>
    `;
  }

  function renderStats(docs) {
    const totalSize = docs.reduce((sum, document) => sum + Number(document.size || 0), 0);
    return `
      <section class="doc-stats">
        ${statCard("Documents", docs.length, "file-text", "var(--theme-primary-color)")}
        ${statCard("Dossiers", state.data?.directories?.length || 0, "folder", "#2563eb")}
        ${statCard("Poids total", sizeLabel(totalSize), "database", "#16a34a")}
        ${statCard("Privés", docs.filter((document) => document.visibility === "private").length, "lock", "#7c3aed")}
      </section>
    `;
  }

  function statCard(label, value, iconName, color) {
    return `
      <article class="doc-stat" style="--stat-color:${color}">
        <span class="doc-stat-icon">${icon(iconName)}</span>
        <span>
          <small>${esc(label)}</small>
          <strong>${esc(value)}</strong>
        </span>
      </article>
    `;
  }

  function renderFilters(directories) {
    return `
      <section class="doc-toolbar">
        <label>
          <span>Recherche</span>
          <input type="search" value="${esc(state.filters.query)}" placeholder="Nom, dossier, description..." data-filter-query>
        </label>
        <label>
          <span>Dossier</span>
          <select data-filter-directory>
            <option value="">Tous les dossiers</option>
            <option value="root" ${state.filters.directoryId === "root" ? "selected" : ""}>Sans dossier</option>
            ${directories.map((directory) => `
              <option value="${directory.id}" ${String(state.filters.directoryId) === String(directory.id) ? "selected" : ""}>${esc(directory.name)}</option>
            `).join("")}
          </select>
        </label>
      </section>
    `;
  }

  function renderDirectories(directories, canManage) {
    if (!directories.length) return "";

    return `
      <section class="doc-card">
        <div class="doc-card-head">
          <div>
            <h2>Dossiers</h2>
            <p>Classement interne de cette catégorie.</p>
          </div>
        </div>
        <div class="doc-folder-grid">
          ${directories.map((directory) => `
            <article class="doc-folder">
              <button type="button" class="doc-folder-main" data-pick-directory="${directory.id}">
                <span class="doc-folder-icon">${icon("folder")}</span>
                <span>
                  <strong>${esc(directory.name)}</strong>
                  <small>${esc(visibilityLabel(directory.visibility))}${directory.parentId ? " · Sous-dossier" : ""}</small>
                </span>
              </button>
              ${canManage ? `
                <span class="doc-folder-actions">
                  <button type="button" title="Modifier" data-edit-directory="${directory.id}">${icon("edit")}</button>
                  <button type="button" title="Supprimer" data-delete-directory="${directory.id}">${icon("trash")}</button>
                </span>
              ` : ""}
            </article>
          `).join("")}
        </div>
      </section>
    `;
  }

  function renderDocuments(docs, canManage) {
    return `
      <section class="doc-card">
        <div class="doc-card-head">
          <div>
            <h2>Documents</h2>
            <p>${docs.length} document(s) affiché(s).</p>
          </div>
        </div>
        ${docs.length ? `
          <div class="doc-list">
            ${docs.map((document) => `
              <article class="doc-row">
                <a class="doc-row-main" href="${esc(document.downloadUrl)}" target="_blank" rel="noopener">
                  <span class="doc-kind">${esc(fileKind(document.mimeType))}</span>
                  <span class="doc-row-text">
                    <strong>${esc(document.name)}</strong>
                    <small>${esc(document.directoryName || "Sans dossier")} · ${esc(document.readableSize || sizeLabel(document.size))} · ${esc(dateLabel(document.updatedAt))}</small>
                    ${document.description ? `<em>${esc(document.description)}</em>` : ""}
                  </span>
                </a>
                <span class="doc-badge">${esc(visibilityLabel(document.visibility))}</span>
                <span class="doc-row-actions">
                  <a title="Ouvrir" href="${esc(document.downloadUrl)}" target="_blank" rel="noopener">${icon("eye")}</a>
                  ${canManage ? `
                    <button type="button" title="Modifier" data-edit-document="${document.id}">${icon("edit")}</button>
                    <button type="button" title="Supprimer" data-delete-document="${document.id}">${icon("trash")}</button>
                  ` : ""}
                </span>
              </article>
            `).join("")}
          </div>
        ` : `<div class="doc-empty">Aucun document pour le moment.</div>`}
      </section>
    `;
  }

  function renderDirectoryModal() {
    const draft = state.directoryDraft;
    const directories = (state.data?.directories || []).filter((directory) => String(directory.id) !== String(draft.id));
    return `
      <div class="doc-modal-backdrop" data-close-modal>
        <section class="doc-modal" role="dialog" aria-modal="true">
          <header>
            <div>
              <h2>${draft.id ? "Modifier le dossier" : "Nouveau dossier"}</h2>
              <p>${esc(categoryLabel())}</p>
            </div>
            <button type="button" data-close-modal>Fermer</button>
          </header>
          <div class="doc-modal-body">
            <label><span>Nom</span><input value="${esc(draft.name)}" data-directory-field="name"></label>
            <label><span>Dossier parent</span><select data-directory-field="parentId">
              <option value="">Racine</option>
              ${directories.map((directory) => `<option value="${directory.id}" ${String(draft.parentId) === String(directory.id) ? "selected" : ""}>${esc(directory.name)}</option>`).join("")}
            </select></label>
            <label><span>Visibilité</span><select data-directory-field="visibility">
              ${visibilityOptions(draft.visibility)}
            </select></label>
            <label><span>Description</span><textarea rows="3" data-directory-field="description">${esc(draft.description)}</textarea></label>
          </div>
          <footer>
            <button type="button" class="doc-button" data-close-modal>Annuler</button>
            <button type="button" class="doc-button doc-button-primary" data-save-directory>${state.saving ? "Enregistrement..." : "Enregistrer"}</button>
          </footer>
        </section>
      </div>
    `;
  }

  function renderDocumentModal() {
    const draft = state.documentDraft;
    return `
      <div class="doc-modal-backdrop" data-close-modal>
        <section class="doc-modal" role="dialog" aria-modal="true">
          <header>
            <div>
              <h2>${draft.id ? "Modifier le document" : "Ajouter un document"}</h2>
              <p>${esc(categoryLabel())}</p>
            </div>
            <button type="button" data-close-modal>Fermer</button>
          </header>
          <div class="doc-modal-body">
            <label><span>Fichier${draft.id ? " (optionnel)" : ""}</span><input type="file" data-document-file></label>
            ${state.fileLabel ? `<div class="doc-file-label">${esc(state.fileLabel)}</div>` : ""}
            <label><span>Nom</span><input value="${esc(draft.name)}" data-document-field="name" placeholder="Nom affiché"></label>
            <label><span>Dossier</span><select data-document-field="directoryId">
              <option value="">Sans dossier</option>
              ${(state.data?.directories || []).map((directory) => `<option value="${directory.id}" ${String(draft.directoryId) === String(directory.id) ? "selected" : ""}>${esc(directory.name)}</option>`).join("")}
            </select></label>
            <label><span>Visibilité</span><select data-document-field="visibility">
              ${visibilityOptions(draft.visibility)}
            </select></label>
            <label><span>Description</span><textarea rows="3" data-document-field="description">${esc(draft.description)}</textarea></label>
          </div>
          <footer>
            <button type="button" class="doc-button" data-close-modal>Annuler</button>
            <button type="button" class="doc-button doc-button-primary" data-save-document>${state.saving ? "Enregistrement..." : "Enregistrer"}</button>
          </footer>
        </section>
      </div>
    `;
  }

  function visibilityOptions(current) {
    return [
      ["restricted", "Equipe du site"],
      ["private", "Privé"],
      ["public", "Public HUB"],
    ].map(([value, label]) => `<option value="${value}" ${current === value ? "selected" : ""}>${label}</option>`).join("");
  }

  function visibilityLabel(value) {
    return {
      public: "Public HUB",
      restricted: "Equipe du site",
      private: "Privé",
    }[value] || "Equipe du site";
  }

  function bind(canManage) {
    root.querySelector("[data-filter-query]")?.addEventListener("input", (event) => {
      state.filters.query = event.target.value;
      render();
    });

    root.querySelector("[data-filter-directory]")?.addEventListener("change", (event) => {
      state.filters.directoryId = event.target.value === "root" ? "root" : event.target.value;
      render();
    });

    root.querySelectorAll("[data-pick-directory]").forEach((button) => button.addEventListener("click", () => {
      state.filters.directoryId = button.dataset.pickDirectory || "";
      render();
    }));

    root.querySelector("[data-new-directory]")?.addEventListener("click", () => {
      state.directoryDraft = emptyDirectoryDraft();
      state.directoryModalOpen = true;
      render();
    });

    root.querySelector("[data-new-document]")?.addEventListener("click", () => {
      state.documentDraft = emptyDocumentDraft();
      state.fileLabel = "";
      state.documentModalOpen = true;
      render();
    });

    root.querySelectorAll("[data-close-modal]").forEach((element) => element.addEventListener("click", (event) => {
      if (element.classList.contains("doc-modal-backdrop") && event.target !== element) return;
      closeModals();
    }));

    if (!canManage) return;

    root.querySelectorAll("[data-edit-directory]").forEach((button) => button.addEventListener("click", () => {
      const directory = (state.data?.directories || []).find((item) => String(item.id) === String(button.dataset.editDirectory));
      if (!directory) return;
      state.directoryDraft = {
        id: directory.id,
        name: directory.name || "",
        description: directory.description || "",
        parentId: directory.parentId || "",
        visibility: directory.visibility || "restricted",
        sortOrder: directory.sortOrder || 100,
      };
      state.directoryModalOpen = true;
      render();
    }));

    root.querySelectorAll("[data-delete-directory]").forEach((button) => button.addEventListener("click", async () => {
      if (!window.confirm("Supprimer ce dossier ? Il doit être vide.")) return;
      await saveAction("delete_directory", { id: button.dataset.deleteDirectory });
    }));

    root.querySelectorAll("[data-edit-document]").forEach((button) => button.addEventListener("click", () => {
      const document = (state.data?.documents || []).find((item) => String(item.id) === String(button.dataset.editDocument));
      if (!document) return;
      state.documentDraft = {
        id: document.id,
        name: document.name || "",
        description: document.description || "",
        directoryId: document.directoryId || "",
        visibility: document.visibility || "restricted",
        sortOrder: document.sortOrder || 100,
        fileDataUrl: "",
        fileName: "",
      };
      state.fileLabel = document.originalName || "";
      state.documentModalOpen = true;
      render();
    }));

    root.querySelectorAll("[data-delete-document]").forEach((button) => button.addEventListener("click", async () => {
      if (!window.confirm("Supprimer ce document ?")) return;
      await saveAction("delete_document", { id: button.dataset.deleteDocument });
    }));

    root.querySelectorAll("[data-directory-field]").forEach((field) => field.addEventListener("input", () => {
      state.directoryDraft[field.dataset.directoryField] = field.value;
    }));

    root.querySelectorAll("[data-document-field]").forEach((field) => field.addEventListener("input", () => {
      state.documentDraft[field.dataset.documentField] = field.value;
    }));

    root.querySelector("[data-document-file]")?.addEventListener("change", readFile);
    root.querySelector("[data-save-directory]")?.addEventListener("click", saveDirectory);
    root.querySelector("[data-save-document]")?.addEventListener("click", saveDocument);
  }

  function closeModals() {
    state.directoryModalOpen = false;
    state.documentModalOpen = false;
    state.saving = false;
    render();
  }

  function readFile(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = () => {
      state.documentDraft.fileDataUrl = String(reader.result || "");
      state.documentDraft.fileName = file.name;
      state.fileLabel = `${file.name} · ${sizeLabel(file.size)}`;
      if (!state.documentDraft.name) state.documentDraft.name = file.name.replace(/\.[^.]+$/, "");
      render();
    };
    reader.readAsDataURL(file);
  }

  async function saveDirectory() {
    await saveAction("save_directory", {
      ...state.directoryDraft,
      category: state.category,
      siteId: activeSiteId(),
    });
  }

  async function saveDocument() {
    await saveAction("save_document", {
      ...state.documentDraft,
      category: state.category,
      siteId: activeSiteId(),
    });
  }

  async function saveAction(action, payload) {
    if (state.saving) return;
    state.saving = true;
    render();

    try {
      await request(action, payload);
      state.directoryModalOpen = false;
      state.documentModalOpen = false;
      state.fileLabel = "";
      await load();
    } catch (error) {
      window.alert(error instanceof Error ? error.message : "Action impossible");
      state.saving = false;
      render();
    }
  }

  function findOutlet() {
    const explicit = document.querySelector("[data-crm-module-outlet]");
    if (explicit) return explicit;

    return null;
  }

  function scheduleEnsureHost() {
    if (hostRetryTimer || hostRetryAttempts >= 40) return;

    hostRetryAttempts += 1;
    hostRetryTimer = window.setTimeout(() => {
      hostRetryTimer = null;
      ensureHost();
    }, hostRetryAttempts < 8 ? 80 : 180);
  }

  function ensureHost() {
    if (!isRoute()) return;

    let host = document.getElementById("crm-documents-module");
    if (!host) {
      const outlet = findOutlet();
      if (!outlet) {
        scheduleEnsureHost();
        return;
      }

      host = document.createElement("div");
      host.id = "crm-documents-module";
      host.textContent = "Chargement des documents...";

      if (outlet.id === "crm-documents-module") {
        host = outlet;
      } else {
        while (outlet.firstChild) outlet.firstChild.remove();
        outlet.appendChild(host);
      }
    }

    hostRetryAttempts = 0;
    root = host;

    if (mountedRoots.has(host) && state.category === categoryFromPath()) {
      render();
      return;
    }

    mountedRoots.add(host);
    load();
  }

  function styles() {
    if (document.getElementById("crm-documents-style")) return;

    const style = document.createElement("style");
    style.id = "crm-documents-style";
    style.textContent = `
      #crm-documents-module{--doc-primary:rgb(var(--theme-primary));--doc-border:var(--color-surface-200,#e2e8f0);--doc-muted:var(--color-secondary-500,#64748b);--doc-text:var(--color-secondary-900,#0f172a);min-width:0;color:var(--doc-text)}
      #crm-documents-module *{box-sizing:border-box}
      #crm-documents-module svg{width:1.05rem;height:1.05rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #crm-documents-module .doc-page{display:grid;gap:1rem;min-width:0}
      #crm-documents-module .doc-header{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem}
      #crm-documents-module .doc-title span{display:block;color:var(--doc-primary);font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
      #crm-documents-module .doc-title h1{margin:.15rem 0 0;color:var(--doc-text);font-size:1.85rem;line-height:1.05;font-weight:600;letter-spacing:0}
      #crm-documents-module .doc-title p{margin:.3rem 0 0;color:var(--doc-muted);font-size:.88rem;font-weight:750}
      #crm-documents-module .doc-actions{display:flex;gap:.55rem;flex-wrap:wrap;justify-content:flex-end}
      #crm-documents-module .doc-button{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;min-height:2.45rem;border:1px solid var(--doc-border);border-radius:.5rem;background:#fff;padding:.55rem .9rem;color:var(--doc-text);font-size:.84rem;font-weight:600;text-decoration:none;box-shadow:0 10px 22px rgba(15,23,42,.05);cursor:pointer}
      #crm-documents-module .doc-button-primary{border-color:transparent;background:var(--doc-primary);color:#fff}
      #crm-documents-module .doc-tabs{display:flex;gap:.5rem;overflow:auto;padding:.2rem 0}
      #crm-documents-module .doc-tab{display:inline-flex;align-items:center;justify-content:center;min-height:2.35rem;border:1px solid var(--doc-border);border-radius:.5rem;background:#fff;padding:.5rem .85rem;color:var(--doc-muted);font-size:.82rem;font-weight:600;text-decoration:none;white-space:nowrap}
      #crm-documents-module .doc-tab.is-active{border-color:transparent;background:var(--doc-primary);color:#fff;box-shadow:0 12px 24px rgb(var(--theme-primary) / .18)}
      #crm-documents-module .doc-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.85rem}
      #crm-documents-module .doc-stat{display:grid;grid-template-columns:2.55rem minmax(0,1fr);gap:.75rem;align-items:center;border:1px solid var(--doc-border);border-radius:.5rem;background:#fff;padding:.85rem;box-shadow:0 12px 28px rgba(15,23,42,.05)}
      #crm-documents-module .doc-stat-icon{display:grid;place-items:center;width:2.55rem;height:2.55rem;border-radius:.5rem;background:color-mix(in srgb,var(--stat-color) 14%,white);color:var(--stat-color)}
      #crm-documents-module .doc-stat small{display:block;color:var(--doc-muted);font-size:.72rem;font-weight:600;text-transform:uppercase}
      #crm-documents-module .doc-stat strong{display:block;margin:.15rem 0 0;color:var(--doc-text);font-size:1.25rem;font-weight:600;line-height:1.05}
      #crm-documents-module .doc-toolbar{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(14rem,.8fr);gap:.75rem;border:1px solid var(--doc-border);border-radius:.5rem;background:#fff;padding:.85rem;box-shadow:0 12px 28px rgba(15,23,42,.04)}
      #crm-documents-module label{display:grid;gap:.35rem;color:var(--doc-muted);font-size:.76rem;font-weight:600}
      #crm-documents-module input,#crm-documents-module select,#crm-documents-module textarea{width:100%;min-height:2.55rem;border:1px solid var(--doc-border);border-radius:.5rem;background:#fff;padding:.55rem .7rem;color:var(--doc-text);font:inherit;font-size:.9rem;font-weight:750}
      #crm-documents-module textarea{resize:vertical}
      #crm-documents-module .doc-card{border:1px solid var(--doc-border);border-radius:.5rem;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.05);overflow:hidden}
      #crm-documents-module .doc-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--doc-border);padding:.95rem 1rem}
      #crm-documents-module .doc-card-head h2{margin:0;color:var(--doc-text);font-size:1rem;font-weight:600;letter-spacing:0}
      #crm-documents-module .doc-card-head p{margin:.2rem 0 0;color:var(--doc-muted);font-size:.78rem;font-weight:750}
      #crm-documents-module .doc-folder-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.7rem;padding:1rem}
      #crm-documents-module .doc-folder{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:.5rem;border:1px solid var(--doc-border);border-radius:.5rem;background:#fff;padding:.65rem}
      #crm-documents-module .doc-folder-main{display:grid;grid-template-columns:2.3rem minmax(0,1fr);align-items:center;gap:.65rem;border:0;background:transparent;padding:0;text-align:left;cursor:pointer}
      #crm-documents-module .doc-folder-icon{display:grid;place-items:center;width:2.3rem;height:2.3rem;border-radius:.5rem;background:#eef2ff;color:#2563eb}
      #crm-documents-module .doc-folder strong{display:block;color:var(--doc-text);font-size:.88rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #crm-documents-module .doc-folder small{display:block;margin-top:.1rem;color:var(--doc-muted);font-size:.72rem;font-weight:750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #crm-documents-module .doc-folder-actions,#crm-documents-module .doc-row-actions{display:inline-flex;align-items:center;justify-content:flex-end;gap:.35rem}
      #crm-documents-module .doc-folder-actions button,#crm-documents-module .doc-row-actions button,#crm-documents-module .doc-row-actions a{display:grid;place-items:center;width:2rem;height:2rem;border:1px solid var(--doc-border);border-radius:.45rem;background:#fff;color:var(--doc-text);cursor:pointer;text-decoration:none}
      #crm-documents-module .doc-list{display:grid}
      #crm-documents-module .doc-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:.8rem;border-bottom:1px solid var(--doc-border);padding:.75rem 1rem}
      #crm-documents-module .doc-row:last-child{border-bottom:0}
      #crm-documents-module .doc-row-main{display:grid;grid-template-columns:3.25rem minmax(0,1fr);align-items:center;gap:.75rem;min-width:0;text-decoration:none;color:inherit}
      #crm-documents-module .doc-kind{display:grid;place-items:center;min-height:2.25rem;border-radius:.45rem;background:color-mix(in srgb,var(--doc-primary) 12%,white);color:var(--doc-primary);font-size:.72rem;font-weight:600}
      #crm-documents-module .doc-row-text{min-width:0}
      #crm-documents-module .doc-row-text strong{display:block;color:var(--doc-text);font-size:.93rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #crm-documents-module .doc-row-text small,#crm-documents-module .doc-row-text em{display:block;margin-top:.12rem;color:var(--doc-muted);font-size:.76rem;font-weight:750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-style:normal}
      #crm-documents-module .doc-badge{display:inline-flex;align-items:center;min-height:1.65rem;border-radius:999px;background:#f1f5f9;padding:.25rem .6rem;color:var(--doc-muted);font-size:.7rem;font-weight:600;white-space:nowrap}
      #crm-documents-module .doc-empty,.doc-loading{display:grid;place-items:center;min-height:9rem;border:1px dashed var(--doc-border);border-radius:.5rem;color:var(--doc-muted);font-size:.88rem;font-weight:850;text-align:center;padding:1rem}
      #crm-documents-module .doc-modal-backdrop{position:fixed;inset:0;z-index:1100;display:grid;place-items:center;background:rgba(15,23,42,.45);padding:1rem}
      #crm-documents-module .doc-modal{width:min(34rem,100%);max-height:92vh;display:grid;grid-template-rows:auto minmax(0,1fr) auto;border-radius:.75rem;background:#fff;box-shadow:0 25px 80px rgba(15,23,42,.28);overflow:hidden}
      #crm-documents-module .doc-modal header,#crm-documents-module .doc-modal footer{display:flex;align-items:center;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--doc-border);padding:1rem}
      #crm-documents-module .doc-modal footer{border-top:1px solid var(--doc-border);border-bottom:0;justify-content:flex-end}
      #crm-documents-module .doc-modal h2{margin:0;color:var(--doc-text);font-size:1.1rem;font-weight:600;letter-spacing:0}
      #crm-documents-module .doc-modal p{margin:.15rem 0 0;color:var(--doc-muted);font-size:.78rem;font-weight:750}
      #crm-documents-module .doc-modal header > button{border:1px solid var(--doc-border);border-radius:.45rem;background:#fff;padding:.45rem .65rem;color:var(--doc-muted);font-weight:850;cursor:pointer}
      #crm-documents-module .doc-modal-body{display:grid;gap:.8rem;overflow:auto;padding:1rem}
      #crm-documents-module .doc-file-label{border:1px solid #bbf7d0;border-radius:.5rem;background:#f0fdf4;padding:.65rem;color:#166534;font-size:.82rem;font-weight:850}
      .dark #crm-documents-module{--doc-border:var(--color-surface-700,#334155);--doc-muted:var(--color-secondary-400,#94a3b8);--doc-text:#fff}
      .dark #crm-documents-module .doc-card,.dark #crm-documents-module .doc-stat,.dark #crm-documents-module .doc-toolbar,.dark #crm-documents-module .doc-tab,.dark #crm-documents-module .doc-button,.dark #crm-documents-module .doc-folder,.dark #crm-documents-module input,.dark #crm-documents-module select,.dark #crm-documents-module textarea,.dark #crm-documents-module .doc-row-actions a,.dark #crm-documents-module .doc-row-actions button,.dark #crm-documents-module .doc-folder-actions button,.dark #crm-documents-module .doc-modal{background:var(--color-surface-900,#0f172a);border-color:var(--doc-border)}
      @media (max-width:1100px){#crm-documents-module .doc-stats{grid-template-columns:repeat(2,minmax(0,1fr))}#crm-documents-module .doc-folder-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
      @media (max-width:720px){#crm-documents-module .doc-header{align-items:stretch;flex-direction:column}#crm-documents-module .doc-actions{justify-content:stretch}#crm-documents-module .doc-actions .doc-button{flex:1}#crm-documents-module .doc-title h1{font-size:1.55rem}#crm-documents-module .doc-stats,#crm-documents-module .doc-toolbar,#crm-documents-module .doc-folder-grid{grid-template-columns:1fr}#crm-documents-module .doc-row{grid-template-columns:1fr;gap:.65rem}#crm-documents-module .doc-badge,#crm-documents-module .doc-row-actions{justify-self:start}#crm-documents-module .doc-row-main{grid-template-columns:2.8rem minmax(0,1fr)}#crm-documents-module .doc-modal-backdrop{align-items:end;padding:0}#crm-documents-module .doc-modal{max-height:94vh;border-radius:.8rem .8rem 0 0}}
    `;
    document.head.appendChild(style);
  }

  function icon(name) {
    const icons = {
      "folder-plus": '<path d="M12 10v6"></path><path d="M9 13h6"></path><path d="M3 7h5l2 2h11v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>',
      upload: '<path d="M12 16V4"></path><path d="m7 9 5-5 5 5"></path><path d="M20 16v4H4v-4"></path>',
      "file-text": '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M8 13h8"></path><path d="M8 17h6"></path>',
      folder: '<path d="M3 7h5l2 2h11v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>',
      database: '<ellipse cx="12" cy="5" rx="8" ry="3"></ellipse><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"></path><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"></path>',
      lock: '<rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path>',
      edit: '<path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path>',
      trash: '<path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 14H6L5 6"></path>',
      eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle>',
    };

    return `<svg viewBox="0 0 24 24" aria-hidden="true">${icons[name] || icons["file-text"]}</svg>`;
  }

  function watchRouteChanges() {
    if (window.__crmDocumentsRouteWatcher) return;
    window.__crmDocumentsRouteWatcher = true;

    window.addEventListener("popstate", () => window.dispatchEvent(new Event("crm:documents-route-changed")));
    window.addEventListener("crm:navigation", () => window.setTimeout(ensureHost, 0));
    window.addEventListener("crm:route-changed", () => window.setTimeout(ensureHost, 0));
    window.addEventListener("crm:documents-route-changed", () => window.setTimeout(ensureHost, 0));
  }

  window.addEventListener("crm:active-site-changed", () => {
    if (isRoute()) load();
  });
  document.addEventListener("DOMContentLoaded", ensureHost);
  window.setTimeout(ensureHost, 0);
  watchRouteChanges();
})();

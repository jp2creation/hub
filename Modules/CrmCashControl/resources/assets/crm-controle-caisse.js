(() => {
  const api = "/api/controle-caisse";
  let root = null;
  const mountedRoots = new WeakSet();
  const state = {
    data: null,
    selectedId: null,
    view: "dashboard",
    draft: null,
    receiptDraft: emptyReceiptDraft(),
    receiptModalOpen: false,
    cashCountModalOpen: false,
    dayModalOpen: false,
    createDayModalOpen: false,
    createDayDate: "",
    listYear: "",
    listMonth: "",
    cashCountDraft: null,
    movementDraft: emptyMovementDraft(),
    movementModalOpen: false,
    loading: false,
    saving: false,
  };

  const esc = (value) => String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");

  function themeCssVariables() {
    return [
      `--theme-primary:${themeRgb("--theme-primary")}`,
      `--theme-primary-color:${themeHex("--theme-primary-color")}`,
      `--theme-primary-dark-color:${themeHex("--theme-primary-dark-color")}`,
      `--theme-accent:${themeRgb("--theme-accent")}`,
      `--theme-accent-color:${themeHex("--theme-accent-color")}`,
    ].join(";");
  }

  function themeCssValue(name) {
    const styles = window.getComputedStyle(document.documentElement);

    return styles.getPropertyValue(name).trim().replace(/\s+/g, " ");
  }

  function themeHex(name) {
    const value = themeCssValue(name);

    return /^#[0-9a-f]{6}$/i.test(value) ? value : "#000000";
  }

  function themeRgb(name) {
    const value = themeCssValue(name);

    return /^\d{1,3}\s+\d{1,3}\s+\d{1,3}$/.test(value) ? value : "0 0 0";
  }

  function emptyReceiptDraft(date = "") {
    return {
      id: "",
      invoiceNumber: "",
      customerName: "",
      occurredOn: date,
      invoiceTotal: "",
      cashAmount: "",
      cardAmount: "",
      checkAmount: "",
      transferAmount: "",
      controlAmount: "",
      paymentNote: "",
      sortOrder: 100,
    };
  }

  function emptyMovementDraft() {
    return {
      id: "",
      type: "cash_out",
      label: "",
      amount: "",
      occurredOn: "",
      sortOrder: 100,
      attachmentDataUrl: "",
      attachmentName: "",
    };
  }

  function cashCountDraftFromDay(day) {
    const lines = Array.isArray(day?.cashCountLines) ? day.cashCountLines : [];
    return {
      hasCashCount: Boolean(day?.hasCashCount),
      checkCounted: numberValue(day?.checkCounted),
      transferCounted: numberValue(day?.transferCounted),
      cardCounted: numberValue(day?.cardCounted),
      lines: lines.map((line) => ({
        kind: line.kind || "bill",
        label: line.label || (line.kind === "coin" ? "Piece" : "Billet"),
        denomination: Number(line.denomination) || 0,
        previousQuantity: String(line.previousQuantity || 0),
        currentQuantity: String(line.currentQuantity || 0),
        depositQuantity: String(line.depositQuantity || 0),
      })),
    };
  }

  function today() {
    const date = new Date();
    return [
      date.getFullYear(),
      String(date.getMonth() + 1).padStart(2, "0"),
      String(date.getDate()).padStart(2, "0"),
    ].join("-");
  }

  function monthParts(value = "") {
    const fallback = today();
    const date = String(value || fallback);
    const match = date.match(/^(\d{4})-(\d{2})/);

    return {
      year: match ? match[1] : fallback.slice(0, 4),
      month: match ? match[2] : fallback.slice(5, 7),
    };
  }

  function applyRouteIntent() {
    const params = new URLSearchParams(window.location.search);
    const view = params.get("view");
    const action = params.get("action");

    if (view === "list") {
      state.view = "list";
    } else if (view === "dashboard") {
      state.view = "dashboard";
    } else if (view === "day") {
      state.view = "days";
    }

    if (params.get("year")) state.listYear = params.get("year");
    if (params.get("month")) state.listMonth = String(params.get("month")).padStart(2, "0");

    if (action === "new" || params.get("new") === "1") {
      state.view = "dashboard";
      state.createDayDate = params.get("date") || state.createDayDate || state.data?.nextDate || today();
      state.createDayModalOpen = true;
    } else {
      state.createDayModalOpen = false;
    }
  }

  function syncRouteIntent() {
    if (!root || !document.body.contains(root) || !mountedRoots.has(root)) return;

    const previous = [
      state.view,
      state.createDayModalOpen ? "open" : "closed",
      state.createDayDate,
      state.listYear,
      state.listMonth,
    ].join("|");

    applyRouteIntent();

    const current = [
      state.view,
      state.createDayModalOpen ? "open" : "closed",
      state.createDayDate,
      state.listYear,
      state.listMonth,
    ].join("|");

    if (previous !== current) render();
  }

  function watchRouteChanges() {
    if (window.__crmCashControlRouteWatcher) return;
    window.__crmCashControlRouteWatcher = true;

    window.addEventListener("popstate", () => window.dispatchEvent(new Event("crm:cash-route-changed")));
    window.addEventListener("crm:navigation", syncRouteIntent);
    window.addEventListener("crm:route-changed", syncRouteIntent);
    window.addEventListener("crm:cash-route-changed", syncRouteIntent);
  }

  function monthLabel(month) {
    const date = new Date(`2026-${String(month).padStart(2, "0")}-01T00:00:00`);
    if (Number.isNaN(date.getTime())) return String(month);

    return date.toLocaleDateString("fr-FR", { month: "long" });
  }

  function numberValue(value) {
    if (value === null || value === undefined || value === "") return "";
    return String(value).replace(".", ",");
  }

  function parseMoney(value) {
    const number = Number(String(value ?? "0").replace(",", "."));
    return Number.isFinite(number) ? Math.round(number * 100) / 100 : 0;
  }

  function roundMoney(value) {
    return Math.round((Number(value) || 0) * 100) / 100;
  }

  function isZero(value) {
    return Math.abs(Number(value) || 0) <= 0.01;
  }

  function money(value) {
    if (value === null || value === undefined || value === "") return "-";
    return new Intl.NumberFormat("fr-FR", {
      style: "currency",
      currency: "EUR",
      maximumFractionDigits: 2,
    }).format(Number(value) || 0);
  }

  function signedMoney(value) {
    if (value === null || value === undefined || value === "") return "-";
    const number = Number(value) || 0;
    const sign = number > 0 ? "+" : "";
    return sign + money(number);
  }

  function receiptPaymentTotal(receipt) {
    return roundMoney(
      parseMoney(receipt?.cashAmount)
      + parseMoney(receipt?.cardAmount)
      + parseMoney(receipt?.checkAmount)
      + parseMoney(receipt?.transferAmount)
      + parseMoney(receipt?.controlAmount),
    );
  }

  function receiptValidation(receipt) {
    const invoiceTotal = parseMoney(receipt?.invoiceTotal);
    const paymentTotal = receiptPaymentTotal(receipt);
    const difference = roundMoney(invoiceTotal - paymentTotal);
    const usedModes = [
      ['cashAmount', 'Especes'],
      ['cardAmount', 'CB'],
      ['checkAmount', 'Cheque'],
      ['transferAmount', 'Virement'],
      ['controlAmount', 'Autre'],
    ].filter(([field]) => parseMoney(receipt?.[field]) > 0);
    const hasInput = invoiceTotal > 0 || paymentTotal > 0 || String(receipt?.invoiceNumber || "").trim() !== "" || String(receipt?.customerName || "").trim() !== "";

    if (!hasInput) {
      return {
        status: "waiting",
        label: "A completer",
        detail: "Saisissez une facture",
        difference,
        invoiceTotal,
        paymentTotal,
        usedModes,
      };
    }

    if (invoiceTotal <= 0) {
      return {
        status: "error",
        label: "Erreur",
        detail: "Total facture manquant",
        difference,
        invoiceTotal,
        paymentTotal,
        usedModes,
      };
    }

    if (paymentTotal <= 0) {
      return {
        status: "error",
        label: "Erreur",
        detail: "Paiement manquant",
        difference,
        invoiceTotal,
        paymentTotal,
        usedModes,
      };
    }

    if (!isZero(difference)) {
      return {
        status: "error",
        label: "Ecart",
        detail: `Ecart ${signedMoney(difference)}`,
        difference,
        invoiceTotal,
        paymentTotal,
        usedModes,
      };
    }

    return {
      status: "ok",
      label: "OK",
      detail: usedModes.length > 1 ? "Paiement mixte OK" : `${usedModes[0]?.[1] || "Paiement"} OK`,
      difference,
      invoiceTotal,
      paymentTotal,
      usedModes,
    };
  }

  function checkState(ok, detail = "") {
    return {
      status: ok ? "ok" : "error",
      label: ok ? "OK" : "Ecart",
      detail,
    };
  }

  function statusBadge(status, label, detail = "") {
    const className = status === "ok" ? "is-ok" : status === "error" ? "is-error" : "is-waiting";
    return `<span class="cash-check-badge ${className}">${esc(label)}${detail ? `<small>${esc(detail)}</small>` : ""}</span>`;
  }

  function dateLabel(value) {
    if (!value) return "-";
    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleDateString("fr-FR", { day: "2-digit", month: "short", year: "numeric" });
  }

  function activeSiteId() {
    const fromApi = Number(window.CRM_ACTIVE_SITE?.getSiteId?.() || 0);
    if (Number.isFinite(fromApi) && fromApi > 0) return fromApi;

    try {
      const stored = Number(window.localStorage.getItem("crm:active-site-id") || 0);
      if (Number.isFinite(stored) && stored > 0) return stored;
    } catch (error) {
      // The API will select the first authorized site.
    }

    const selected = Number(state.data?.selectedSiteId || state.data?.user?.selectedSiteId || 0);
    return Number.isFinite(selected) && selected > 0 ? selected : "";
  }

  function activeSiteName() {
    const siteId = Number(state.data?.selectedSiteId || state.data?.user?.selectedSiteId || activeSiteId());
    const site = (state.data?.sites || []).find((item) => Number(item.id) === siteId);
    return site?.name || "Site actif";
  }

  function selectedDay() {
    return (state.data?.days || []).find((day) => Number(day.id) === Number(state.selectedId)) || null;
  }

  function draftFromDay(day) {
    return {
      id: day.id,
      cashDate: day.cashDate || today(),
      openingBalance: numberValue(day.openingBalance),
      invoiceTotal: numberValue(day.invoiceTotal),
      cashSales: numberValue(day.cashSales),
      cardSales: numberValue(day.cardSales),
      checkSales: numberValue(day.checkSales),
      transferSales: numberValue(day.transferSales),
      countedCash: numberValue(day.countedCash),
      bankCounted: numberValue(day.bankCounted),
      checkCounted: numberValue(day.checkCounted),
      transferCounted: numberValue(day.transferCounted),
      cardCounted: numberValue(day.cardCounted),
      invoiceErrorsCount: String(day.invoiceErrorsCount || 0),
      notes: day.notes || "",
    };
  }

  function cashCountTotalsFromDraft() {
    const draft = state.cashCountDraft;
    const lines = draft?.lines || [];
    const cashGrossTotal = lines.reduce((sum, line) => (
      sum + (Number(line.denomination) || 0) * (Number(line.currentQuantity) || 0)
    ), 0);
    const cashDepositTotal = lines.reduce((sum, line) => (
      sum + (Number(line.denomination) || 0) * (Number(line.depositQuantity) || 0)
    ), 0);

    return {
      cashGrossTotal: Math.round(cashGrossTotal * 100) / 100,
      cashDepositTotal: Math.round(cashDepositTotal * 100) / 100,
      countedCash: Math.round((cashGrossTotal - cashDepositTotal) * 100) / 100,
      hasCashCount: Boolean(draft?.hasCashCount) || lines.some((line) => (
        Number(line.previousQuantity) > 0 || Number(line.currentQuantity) > 0 || Number(line.depositQuantity) > 0
      )),
    };
  }

  function draftCalculations() {
    const day = selectedDay();
    const draft = state.draft;
    if (!day || !draft) return null;

    const cashInTotal = (day.movements || [])
      .filter((movement) => movement.type === "cash_in")
      .reduce((sum, movement) => sum + parseMoney(movement.amount), 0);
    const cashOutTotal = (day.movements || [])
      .filter((movement) => movement.type === "cash_out")
      .reduce((sum, movement) => sum + parseMoney(movement.amount), 0);
    const controlTotal = (day.receipts || [])
      .reduce((sum, receipt) => sum + parseMoney(receipt.controlAmount), 0);
    const invoiceTotal = parseMoney(draft.invoiceTotal);
    const cashSales = parseMoney(draft.cashSales);
    const cardSales = parseMoney(draft.cardSales);
    const checkSales = parseMoney(draft.checkSales);
    const transferSales = parseMoney(draft.transferSales);
    const cashCount = cashCountTotalsFromDraft();
    const countedCash = cashCount.hasCashCount ? cashCount.countedCash : (String(draft.countedCash || "").trim() === "" ? null : parseMoney(draft.countedCash));
    const bankCounted = String(draft.bankCounted || "").trim() === "" ? null : parseMoney(draft.bankCounted);
    const checkCounted = String(draft.checkCounted || "").trim() === "" ? null : parseMoney(draft.checkCounted);
    const transferCounted = String(draft.transferCounted || "").trim() === "" ? null : parseMoney(draft.transferCounted);
    const cardCounted = String(draft.cardCounted || "").trim() === "" ? null : parseMoney(draft.cardCounted);
    const paymentsTotal = cashSales + cardSales + checkSales + transferSales + controlTotal;
    const expectedCash = parseMoney(draft.openingBalance) + cashSales + cashInTotal - cashOutTotal - cashCount.cashDepositTotal;
    const bankExpected = cardSales + checkSales + transferSales + controlTotal;
    const entryDifference = invoiceTotal - paymentsTotal;
    const cashDifference = countedCash === null ? null : countedCash - expectedCash;
    const bankDifference = bankCounted === null ? null : bankCounted - bankExpected;
    const checkDifference = checkCounted === null ? null : checkCounted - checkSales;
    const transferDifference = transferCounted === null ? null : transferCounted - transferSales;
    const cardDifference = cardCounted === null ? null : cardCounted - cardSales;
    const errors = Number(draft.invoiceErrorsCount || 0);
    const hasGranularControls = checkCounted !== null || transferCounted !== null || cardCounted !== null;

    let status = "ok";
    if (
      countedCash === null
      || (!hasGranularControls && bankExpected > 0 && bankCounted === null)
      || (hasGranularControls && checkSales > 0 && checkCounted === null)
      || (hasGranularControls && transferSales > 0 && transferCounted === null)
      || (hasGranularControls && cardSales > 0 && cardCounted === null)
    ) status = "review";
    if (
      status !== "review"
      && (Math.abs(entryDifference) > 0.01
        || Math.abs(cashDifference || 0) > 0.01
        || (bankDifference !== null && Math.abs(bankDifference) > 0.01)
        || (checkDifference !== null && Math.abs(checkDifference) > 0.01)
        || (transferDifference !== null && Math.abs(transferDifference) > 0.01)
        || (cardDifference !== null && Math.abs(cardDifference) > 0.01)
        || errors > 0)
    ) {
      status = "anomaly";
    }

    return {
      cashInTotal,
      cashOutTotal,
      cashGrossTotal: cashCount.cashGrossTotal,
      cashDepositTotal: cashCount.cashDepositTotal,
      controlTotal,
      paymentsTotal,
      expectedCash,
      bankExpected,
      entryDifference,
      cashDifference,
      bankDifference,
      checkDifference,
      transferDifference,
      cardDifference,
      status,
      statusLabel: statusLabel(status),
      statusColor: statusColor(status),
    };
  }

  function statusLabel(status) {
    return {
      ok: "Caisse OK",
      anomaly: "Anomalie",
      review: "A verifier",
    }[status] || "A verifier";
  }

  function statusColor(status) {
    return {
      ok: "#16a34a",
      anomaly: "#dc2626",
      review: "#64748b",
    }[status] || "#64748b";
  }

  function normalizeDay(day) {
    return {
      ...day,
      movements: Array.isArray(day.movements) ? day.movements : [],
      receipts: Array.isArray(day.receipts) ? day.receipts : [],
      cashCountLines: Array.isArray(day.cashCountLines) ? day.cashCountLines : [],
    };
  }

  function mergeDay(day) {
    if (!state.data) return;
    const normalized = normalizeDay(day);
    const days = state.data.days || [];
    const index = days.findIndex((item) => Number(item.id) === Number(normalized.id));
    if (index >= 0) {
      days[index] = normalized;
    } else {
      days.unshift(normalized);
    }
    days.sort((a, b) => String(b.cashDate).localeCompare(String(a.cashDate)) || Number(b.id) - Number(a.id));
    state.selectedId = normalized.id;
    state.draft = draftFromDay(normalized);
    state.cashCountDraft = cashCountDraftFromDay(normalized);
  }

  function selectDayById(id) {
    const day = (state.data?.days || []).find((item) => Number(item.id) === Number(id));
    if (!day) return false;

    state.selectedId = day.id;
    state.draft = draftFromDay(day);
    state.receiptDraft = emptyReceiptDraft(day.cashDate || today());
    state.receiptModalOpen = false;
    state.cashCountModalOpen = false;
    state.dayModalOpen = false;
    state.cashCountDraft = cashCountDraftFromDay(day);
    state.movementDraft = emptyMovementDraft();
    state.movementModalOpen = false;

    return true;
  }

  async function request(action, payload = null) {
    const params = new URLSearchParams({ action });
    const siteId = activeSiteId();
    if (siteId) params.set("siteId", String(siteId));

    const options = {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    };

    if (payload) {
      options.method = "POST";
      options.headers = { ...options.headers, "Content-Type": "application/json" };
      options.body = JSON.stringify(siteId && payload.siteId === undefined && payload.site_id === undefined
        ? { ...payload, siteId: Number(siteId) }
        : payload);
    }

    const response = await fetch(`${api}?${params.toString()}`, options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || "API controle caisse indisponible");
    }
    return data;
  }

  async function load() {
    if (!root) return;
    state.loading = true;
    root.innerHTML = '<div class="cash-card cash-loading">Chargement...</div>';
    styles();

    try {
      state.data = await request("bootstrap");
      state.data.days = (state.data.days || []).map(normalizeDay);
      const first = state.data.days?.[0] || null;
      state.selectedId = first?.id || null;
      state.draft = first ? draftFromDay(first) : null;
      state.receiptDraft = emptyReceiptDraft(first?.cashDate || today());
      state.createDayDate = state.data.nextDate || today();
      const listDate = first?.cashDate || state.data.nextDate || today();
      const listParts = monthParts(listDate);
      state.listYear = listParts.year;
      state.listMonth = listParts.month;
      state.cashCountDraft = first ? cashCountDraftFromDay(first) : null;
      state.movementDraft = emptyMovementDraft();
      applyRouteIntent();
      render();
    } catch (error) {
      root.innerHTML = `<div class="cash-card cash-pad"><div class="cash-notice">${esc(error instanceof Error ? error.message : "Chargement impossible")}</div></div>`;
      styles();
    } finally {
      state.loading = false;
    }
  }

  function render() {
    if (!root) return;
    styles();
    const day = selectedDay();
    const canManage = Boolean(state.data?.user?.canManage);
    const showDashboard = state.view === "dashboard";

    root.innerHTML = `
      <div class="cash-page">
        <header class="cash-head">
          <div>
            <h1>Controle caisse</h1>
            <p>${esc(activeSiteName())}</p>
          </div>
        </header>
        ${showDashboard ? renderDashboard(canManage) : (state.view === "list" ? renderCashList(canManage) : (day ? renderWorkspace(day, canManage) : renderEmpty(canManage)))}
        ${renderCreateDayModal(canManage)}
      </div>
    `;
    bindElements();
  }

  function renderEmpty(canManage) {
    return `
      <div class="cash-card cash-empty">
        <strong>Aucune caisse pour ce site</strong>
        <span>Cree une caisse pour demarrer le suivi du site.</span>
        <button type="button" class="cash-button cash-button-primary" data-open-create-day ${canManage ? "" : "disabled"}>Nouvelle caisse</button>
      </div>
    `;
  }

  function renderDashboard(canManage) {
    const days = state.data?.days || [];
    const last = days[0] || null;
    const errors = days.filter((day) => day.status === "anomaly");
    const okDays = days.filter((day) => day.status === "ok");
    const reviewDays = days.filter((day) => day.status === "review");

    return `
      <section class="cash-dashboard">
        <div class="cash-dashboard-actions">
          <button type="button" class="cash-button cash-button-primary" data-open-create-day ${canManage ? "" : "disabled"}>Nouvelle caisse</button>
        </div>
        <div class="cash-dashboard-grid">
          ${dashboardCard("Derniere caisse", last ? dateLabel(last.cashDate) : "-", last ? money(last.expectedCash) : "Aucune", "calendar", "#2563eb")}
          ${dashboardCard("Dernier statut", last ? (last.statusLabel || statusLabel(last.status)) : "-", last ? money(last.paymentsTotal) : "-", "status", last ? statusColor(last.status) : "#64748b")}
          ${dashboardCard("Caisses OK", String(okDays.length), `${days.length} journees`, "check", "#16a34a")}
          ${dashboardCard("A verifier", String(errors.length + reviewDays.length), `${errors.length} anomalies`, "alert", "#dc2626")}
        </div>
        <section class="cash-card cash-dashboard-section">
          <div class="cash-section-head">
            <h2>20 dernieres caisses</h2>
            <span>${esc(days.length)} lignes</span>
          </div>
          ${renderDayTable(days.slice(0, 20), canManage)}
        </section>
        <section class="cash-card cash-dashboard-section">
          <div class="cash-section-head">
            <h2>Caisses en erreur</h2>
            <span>${esc(errors.length)} lignes</span>
          </div>
          ${renderDayTable(errors, canManage)}
        </section>
      </section>
    `;
  }

  function renderCashList(canManage) {
    const days = state.data?.days || [];
    const years = collectCashYears(days);
    const year = state.listYear || years[0] || monthParts().year;
    const month = state.listMonth || monthParts().month;
    const filtered = days.filter((day) => {
      const parts = monthParts(day.cashDate);
      return parts.year === String(year) && parts.month === String(month).padStart(2, "0");
    });
    const okDays = filtered.filter((day) => day.status === "ok");
    const reviewDays = filtered.filter((day) => day.status === "review");
    const anomalyDays = filtered.filter((day) => day.status === "anomaly");
    const paymentsTotal = filtered.reduce((sum, day) => sum + Number(day.paymentsTotal || 0), 0);
    const expectedCashTotal = filtered.reduce((sum, day) => sum + Number(day.expectedCash || 0), 0);

    return `
      <section class="cash-card cash-list">
        <div class="cash-section-head">
          <div>
            <h2>Liste des caisses</h2>
            <p>${esc(monthLabel(month))} ${esc(year)}</p>
          </div>
          <span>${esc(filtered.length)} ligne(s)</span>
        </div>
        <div class="cash-list-summary cash-dashboard-grid">
          ${dashboardCard("Caisses du mois", String(filtered.length), `${monthLabel(month)} ${year}`, "calendar", "#2563eb")}
          ${dashboardCard("Encaissements", money(paymentsTotal), `${okDays.length} OK`, "check", "#16a34a")}
          ${dashboardCard("Caisse attendue", money(expectedCashTotal), "Total du mois", "status", "#0ea5e9")}
          ${dashboardCard("A verifier", String(anomalyDays.length + reviewDays.length), `${anomalyDays.length} anomalie(s)`, "alert", "#dc2626")}
        </div>
        <div class="cash-list-filters">
          <label class="cash-field">
            <span>Annee</span>
            <select data-list-filter="year">
              ${years.map((item) => `<option value="${esc(item)}" ${String(item) === String(year) ? "selected" : ""}>${esc(item)}</option>`).join("")}
            </select>
          </label>
          <label class="cash-field">
            <span>Mois</span>
            <select data-list-filter="month">
              ${Array.from({ length: 12 }, (_, index) => {
                const value = String(index + 1).padStart(2, "0");
                return `<option value="${esc(value)}" ${value === String(month).padStart(2, "0") ? "selected" : ""}>${esc(monthLabel(value))}</option>`;
              }).join("")}
            </select>
          </label>
        </div>
        ${renderCashListTable(filtered, canManage)}
      </section>
    `;
  }

  function collectCashYears(days) {
    const fallback = monthParts().year;
    return Array.from(new Set([...days.map((day) => monthParts(day.cashDate).year), fallback]))
      .sort((a, b) => Number(b) - Number(a));
  }

  function renderCashListTable(days, canManage) {
    return `
      <div class="cash-table-wrap">
        <table class="cash-table cash-list-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Statut</th>
              <th>Encaissements</th>
              <th>Caisse attendue</th>
              <th>Ecart caisse</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            ${days.length ? days.map((day) => renderCashListRow(day, canManage)).join("") : '<tr><td colspan="6" class="cash-muted">Aucune caisse pour ce mois.</td></tr>'}
          </tbody>
        </table>
      </div>
    `;
  }

  function renderCashListRow(day, canManage) {
    const disabled = canManage ? "" : "disabled";
    return `
      <tr>
        <td>${esc(dateLabel(day.cashDate))}</td>
        <td><span class="cash-status-pill" style="--cash-pill:${esc(day.statusColor || statusColor(day.status))}">${esc(day.statusLabel || statusLabel(day.status))}</span></td>
        <td>${esc(day.receiptCount || 0)} - ${esc(money(day.paymentsTotal))}</td>
        <td>${esc(money(day.expectedCash))}</td>
        <td class="${Number(day.cashDifference || 0) === 0 ? "cash-positive" : "cash-negative"}">${esc(signedMoney(day.cashDifference))}</td>
        <td class="cash-row-actions">
          <button type="button" class="cash-mini-button cash-icon-button" data-view-day="${esc(day.id)}" title="Voir la caisse" aria-label="Voir la caisse">${actionIcon("view")}</button>
          <button type="button" class="cash-mini-button cash-icon-button" data-edit-day="${esc(day.id)}" title="Modifier la caisse" aria-label="Modifier la caisse" ${disabled}>${actionIcon("edit")}</button>
          <button type="button" class="cash-mini-button cash-icon-button is-danger" data-delete-day="${esc(day.id)}" title="Supprimer la caisse" aria-label="Supprimer la caisse" ${disabled}>${actionIcon("trash")}</button>
        </td>
      </tr>
    `;
  }

  function renderCreateDayModal(canManage) {
    if (!state.createDayModalOpen || !canManage) return "";

    return `
      <div class="cash-modal-backdrop" data-close-create-day>
        <div class="cash-modal cash-create-day-modal" role="dialog" aria-modal="true" aria-label="Nouvelle caisse" data-create-day-modal>
          <div class="cash-modal-head">
            <div>
              <h2>Nouvelle caisse</h2>
              <p>Date de la caisse a creer</p>
            </div>
            <button type="button" class="cash-mini-button" data-close-create-day>Fermer</button>
          </div>
          <div class="cash-create-day-form">
            <label class="cash-field">
              <span>Date</span>
              <input type="date" data-create-day-date value="${esc(state.createDayDate || state.data?.nextDate || today())}">
            </label>
          </div>
          <div class="cash-modal-actions">
            <button type="button" class="cash-button" data-close-create-day>Annuler</button>
            <button type="button" class="cash-button cash-button-primary" data-create-day>OK</button>
          </div>
        </div>
      </div>
    `;
  }

  function dashboardCard(label, value, detail, icon, color) {
    return `
      <article class="cash-dashboard-card" style="--cash-card-color:${esc(color || "var(--theme-primary-color)")}">
        <div class="cash-dashboard-card-icon">${dashboardIcon(icon)}</div>
        <div>
          <span>${esc(label)}</span>
          <strong>${esc(value)}</strong>
          <small>${esc(detail)}</small>
        </div>
      </article>
    `;
  }

  function dashboardIcon(name) {
    const paths = {
      calendar: '<path d="M8 2v4"></path><path d="M16 2v4"></path><path d="M3 10h18"></path><rect x="3" y="4" width="18" height="18" rx="2"></rect>',
      status: '<path d="M12 2a10 10 0 1 0 10 10"></path><path d="M12 6v6l4 2"></path>',
      check: '<path d="M20 6 9 17l-5-5"></path>',
      alert: '<path d="M12 9v4"></path><path d="M12 17h.01"></path><path d="M10.3 3.9 2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"></path>',
    };

    return `<svg class="cash-dashboard-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${paths[name] || paths.status}</svg>`;
  }

  function renderDayTable(days, canManage) {
    return `
      <div class="cash-table-wrap">
        <table class="cash-table cash-day-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Statut</th>
              <th>Encaissements</th>
              <th>Caisse attendue</th>
              <th>Ecart caisse</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            ${days.length ? days.map((day) => renderDashboardDayRow(day, canManage)).join("") : '<tr><td colspan="6" class="cash-muted">Aucune ligne.</td></tr>'}
          </tbody>
        </table>
      </div>
    `;
  }

  function renderDashboardDayRow(day, canManage) {
    const disabled = canManage ? "" : "disabled";
    return `
      <tr>
        <td>${esc(dateLabel(day.cashDate))}</td>
        <td><span class="cash-status-pill" style="--cash-pill:${esc(day.statusColor || statusColor(day.status))}">${esc(day.statusLabel || statusLabel(day.status))}</span></td>
        <td>${esc(day.receiptCount || 0)} - ${esc(money(day.paymentsTotal))}</td>
        <td>${esc(money(day.expectedCash))}</td>
        <td class="${Number(day.cashDifference || 0) === 0 ? "cash-positive" : "cash-negative"}">${esc(signedMoney(day.cashDifference))}</td>
        <td class="cash-row-actions">
          <button type="button" class="cash-mini-button cash-icon-button" data-edit-day="${esc(day.id)}" title="Modifier la caisse" aria-label="Modifier la caisse" ${disabled}>${actionIcon("edit")}</button>
          <button type="button" class="cash-mini-button cash-icon-button is-danger" data-delete-day="${esc(day.id)}" title="Supprimer la caisse" aria-label="Supprimer la caisse" ${disabled}>${actionIcon("trash")}</button>
        </td>
      </tr>
    `;
  }

  function renderWorkspace(day, canManage) {
    const calculations = draftCalculations() || day;
    return `
      ${renderSummary(calculations, day)}
      ${renderQuickActions(day, canManage)}
      <section class="cash-main">
        ${renderReceipts(day, canManage)}
        ${renderCashCount(day, canManage)}
        ${renderDayForm(day, canManage)}
        ${renderMovements(day, canManage)}
      </section>
      ${renderReceiptModal(day, canManage)}
      ${renderCashCountModal(day, canManage)}
      ${renderDayControlsModal(day, canManage)}
      ${renderMovementModal(day, canManage)}
    `;
  }

  function renderQuickActions(day, canManage) {
    const disabled = canManage ? "" : "disabled";
    return `
      <section class="cash-card cash-quick-actions" aria-label="Actions caisse">
        <div>
          <h2>Saisies rapides</h2>
          <p>${esc(dateLabel(day.cashDate))}</p>
        </div>
        <div class="cash-quick-actions-buttons">
          <button type="button" class="cash-button cash-button-primary" data-open-receipt ${disabled}>Ajouter une facture</button>
          <button type="button" class="cash-button" data-open-cash-count ${disabled}>Comptage especes</button>
          <button type="button" class="cash-button" data-open-movement="cash_in" ${disabled}>Ajouter une entree</button>
          <button type="button" class="cash-button" data-open-movement="cash_out" ${disabled}>Ajouter une sortie</button>
          <button type="button" class="cash-button" data-open-day-controls ${disabled}>Controles / notes</button>
          <button type="button" class="cash-button" data-print-cash-day>PDF caisse</button>
        </div>
      </section>
    `;
  }

  function renderSummary(calculations, day) {
    const status = calculations.status || day.status || "review";
    const differenceColor = calculations.cashDifference !== null && Math.abs(Number(calculations.cashDifference || 0)) > 0.01 ? "#dc2626" : "#16a34a";
    return `
      <section class="cash-summary" aria-label="Synthese caisse">
        ${summaryCard("Statut", statusLabel(status), dateLabel(day.cashDate), "status", statusColor(status), statusColor(status))}
        ${summaryCard("Encaissements", money(calculations.paymentsTotal), `${day.receiptCount || 0} ligne(s)`, "receipt", "#2563eb")}
        ${summaryCard("Caisse attendue", money(calculations.expectedCash), "Report + especes - sorties", "cash", "var(--theme-primary-color)")}
        ${summaryCard("Ecart caisse", signedMoney(calculations.cashDifference), "Comptage physique", calculations.cashDifference !== null && Math.abs(Number(calculations.cashDifference || 0)) > 0.01 ? "alert" : "check", differenceColor, differenceColor, calculations.cashDifference !== null && Math.abs(Number(calculations.cashDifference || 0)) > 0.01)}
      </section>
    `;
  }

  function summaryCard(label, value, detail, icon, color, valueColor = "", isBad = false) {
    return `
      <div class="cash-summary-item ${isBad ? "is-bad" : ""}" style="--cash-summary-color:${esc(color || "var(--theme-primary-color)")}">
        <div class="cash-summary-icon">${summaryIcon(icon)}</div>
        <div>
          <span>${esc(label)}</span>
          <strong ${valueColor ? `style="color:${esc(valueColor)}"` : ""}>${esc(value)}</strong>
          <small>${esc(detail)}</small>
        </div>
      </div>
    `;
  }

  function summaryIcon(name) {
    const paths = {
      status: '<path d="M12 2a10 10 0 1 0 10 10"></path><path d="M12 6v6l4 2"></path>',
      receipt: '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2Z"></path><path d="M9 7h6"></path><path d="M9 11h6"></path><path d="M9 15h4"></path>',
      cash: '<rect x="3" y="6" width="18" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 10v4"></path><path d="M18 10v4"></path>',
      check: '<path d="M20 6 9 17l-5-5"></path>',
      alert: '<path d="M12 9v4"></path><path d="M12 17h.01"></path><path d="M10.3 3.9 2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"></path>',
    };

    return `<svg class="cash-summary-icon-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${paths[name] || paths.status}</svg>`;
  }

  function renderReceipts(day, canManage) {
    const receipts = day.receipts || [];
    return `
      <section class="cash-card cash-receipts">
        <div class="cash-section-head">
          <h2>Encaissements du jour</h2>
          <span>${esc(receipts.length)} ligne(s)</span>
        </div>
        <div class="cash-table-wrap">
          <table class="cash-table cash-receipt-table">
            <thead>
              <tr>
                <th>Facture</th>
                <th>Client</th>
                <th>Total</th>
                <th>Especes</th>
                <th>CB</th>
                <th>Cheque</th>
                <th>Virement</th>
                <th>Autre</th>
                <th>Controle</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              ${receipts.length ? receipts.map((receipt) => renderReceiptRow(receipt, canManage)).join("") : '<tr><td colspan="10" class="cash-muted">Aucun encaissement saisi.</td></tr>'}
            </tbody>
          </table>
        </div>
      </section>
    `;
  }

  function renderReceiptModal(day, canManage) {
    if (!state.receiptModalOpen || !canManage) return "";

    const draft = state.receiptDraft;
    const validation = receiptValidation(draft);
    return `
      <div class="cash-modal-backdrop" data-close-receipt>
        <div class="cash-modal cash-receipt-modal" role="dialog" aria-modal="true" aria-label="Facture caisse" data-receipt-modal>
          <div class="cash-modal-head">
            <div>
              <h2>${draft.id ? "Modifier la facture" : "Ajouter une facture"}</h2>
              <p>Informations de vente et modes de paiement</p>
            </div>
            <button type="button" class="cash-mini-button" data-close-receipt>Fermer</button>
          </div>
          <div class="cash-entry-check ${validation.status === "ok" ? "is-ok" : validation.status === "error" ? "is-error" : "is-waiting"}" data-receipt-validation>
            <strong>${esc(validation.label)}</strong>
            <span>${esc(validation.detail)} - Paiements ${esc(money(validation.paymentTotal))}</span>
          </div>
          <div class="cash-receipt-form cash-receipt-form-modal">
            ${receiptField("invoiceNumber", "Facture", "text", draft.invoiceNumber, "")}
            ${receiptField("customerName", "Client", "text", draft.customerName, "")}
            ${receiptField("invoiceTotal", "Total facture", "text", draft.invoiceTotal, "", "decimal")}
            ${receiptField("cashAmount", "Especes", "text", draft.cashAmount, "", "decimal")}
            ${receiptField("cardAmount", "CB", "text", draft.cardAmount, "", "decimal")}
            ${receiptField("checkAmount", "Cheque", "text", draft.checkAmount, "", "decimal")}
            ${receiptField("transferAmount", "Virement", "text", draft.transferAmount, "", "decimal")}
            ${receiptField("controlAmount", "Autre", "text", draft.controlAmount, "", "decimal")}
            ${receiptField("occurredOn", "Date", "date", draft.occurredOn || day.cashDate || today(), "")}
            ${receiptField("paymentNote", "Note", "text", draft.paymentNote, "")}
          </div>
          <div class="cash-modal-actions">
            <button type="button" class="cash-button" data-close-receipt>Annuler</button>
            <button type="button" class="cash-button cash-button-primary" data-save-receipt>${draft.id ? "Modifier la facture" : "Ajouter la facture"}</button>
          </div>
        </div>
      </div>
    `;
  }

  function receiptField(name, label, type, value, disabled, inputMode = "") {
    return `
      <label class="cash-field" data-receipt-field-shell="${esc(name)}">
        <span>${esc(label)}</span>
        <input type="${esc(type)}" data-receipt-field="${esc(name)}" value="${esc(value)}" ${inputMode ? `inputmode="${esc(inputMode)}"` : ""} ${disabled}>
      </label>
    `;
  }

  function renderReceiptRow(receipt, canManage) {
    const validation = receiptValidation(receipt);
    return `
      <tr class="${validation.status === "error" ? "cash-row-error" : validation.status === "ok" ? "cash-row-ok" : ""}">
        <td>${esc(receipt.invoiceNumber || "-")}</td>
        <td>${esc(receipt.customerName || "-")}</td>
        <td>${esc(money(receipt.invoiceTotal))}</td>
        <td>${esc(money(receipt.cashAmount))}</td>
        <td>${esc(money(receipt.cardAmount))}</td>
        <td>${esc(money(receipt.checkAmount))}</td>
        <td>${esc(money(receipt.transferAmount))}</td>
        <td>${esc(money(receipt.controlAmount))}</td>
        <td>${statusBadge(validation.status, validation.label, validation.detail)}</td>
        <td class="cash-row-actions">
          <button type="button" class="cash-mini-button cash-icon-button" data-edit-receipt="${esc(receipt.id)}" title="Modifier la facture" aria-label="Modifier la facture" ${canManage ? "" : "disabled"}>${actionIcon("edit")}</button>
          <button type="button" class="cash-mini-button cash-icon-button is-danger" data-delete-receipt="${esc(receipt.id)}" title="Supprimer la facture" aria-label="Supprimer la facture" ${canManage ? "" : "disabled"}>${actionIcon("trash")}</button>
        </td>
      </tr>
    `;
  }

  function renderCashCount(day, canManage) {
    const draft = state.cashCountDraft || cashCountDraftFromDay(day);
    const totals = cashCountTotalsFromDraft();
    const calculations = draftCalculations() || day;
    const difference = calculations.cashDifference ?? day.cashDifference;
    const hasCount = Boolean(draft.hasCashCount || day.hasCashCount);
    const countStatus = !hasCount ? "waiting" : isZero(difference) ? "ok" : "error";

    return `
      <section class="cash-card cash-count cash-control-compact">
        <div class="cash-section-head">
          <h2>Comptage des especes</h2>
          ${statusBadge(countStatus, countStatus === "ok" ? "OK" : countStatus === "error" ? "Ecart" : "A saisir", countStatus === "error" ? signedMoney(difference) : money(totals.countedCash))}
        </div>
        <div class="cash-count-summary">
          ${totalPill("Ouverture J-1", day.openingBalance)}
          ${totalPill("Especes du jour", day.cashSales)}
          ${totalPill("Sorties especes", day.cashOutTotal)}
          ${totalPill("Remise banque", totals.cashDepositTotal)}
          ${totalPill("Cloture theorique", draftCalculations()?.expectedCash ?? day.expectedCash)}
          ${totalPill("Caisse comptee", totals.countedCash)}
        </div>
        <div class="cash-actions cash-actions-right">
          <button type="button" class="cash-button cash-button-primary" data-open-cash-count ${canManage ? "" : "disabled"}>${hasCount ? "Modifier le comptage" : "Compter les especes"}</button>
        </div>
      </section>
    `;
  }

  function renderCashCountModal(day, canManage) {
    if (!state.cashCountModalOpen || !canManage) return "";

    const disabled = canManage ? "" : "disabled";
    const draft = state.cashCountDraft || cashCountDraftFromDay(day);
    const totals = cashCountTotalsFromDraft();
    const calculations = draftCalculations() || day;
    const difference = calculations.cashDifference ?? day.cashDifference;
    const cashStatus = draft.hasCashCount || day.hasCashCount ? checkState(isZero(difference), `Ecart ${signedMoney(difference)}`) : { status: "waiting", label: "A saisir", detail: "Comptage requis" };

    return `
      <div class="cash-modal-backdrop" data-close-cash-count>
        <div class="cash-modal cash-modal-wide" role="dialog" aria-modal="true" aria-label="Comptage especes" data-cash-count-modal>
          <div class="cash-modal-head">
            <div>
              <h2>Comptage des especes</h2>
              <p>Caisse du ${esc(dateLabel(day.cashDate))}</p>
            </div>
            <button type="button" class="cash-mini-button" data-close-cash-count>Fermer</button>
          </div>
          <div class="cash-entry-check ${cashStatus.status === "ok" ? "is-ok" : cashStatus.status === "error" ? "is-error" : "is-waiting"}" data-cash-count-validation>
            <strong>${esc(cashStatus.label)}</strong>
            <span>${esc(cashStatus.detail)} - Caisse comptee ${esc(money(totals.countedCash))}</span>
          </div>
          <div class="cash-modal-scroll">
            <div class="cash-count-summary">
              ${totalPill("Ouverture J-1", day.openingBalance)}
              ${totalPill("Especes du jour", day.cashSales)}
              ${totalPill("Sorties especes", day.cashOutTotal)}
              ${totalPill("Remise banque", totals.cashDepositTotal)}
              ${totalPill("Cloture theorique", calculations.expectedCash ?? day.expectedCash)}
              ${totalPill("Caisse comptee", totals.countedCash)}
            </div>
            <div class="cash-count-table">
              <div class="cash-count-head">
                <span>Coupure</span>
                <span>Caisse</span>
                <span>Remise</span>
                <span>Total</span>
              </div>
              ${draft.lines.map((line, index) => renderCashCountLine(line, index, disabled)).join("")}
            </div>
            <div class="cash-bank-controls">
              ${cashControlField("checkCounted", "Cheques reels", draft.checkCounted, disabled)}
              ${cashControlField("transferCounted", "Virements reels", draft.transferCounted, disabled)}
              ${cashControlField("cardCounted", "Telecollecte reelle", draft.cardCounted, disabled)}
            </div>
            ${renderControlChecks(day, calculations)}
          </div>
          <div class="cash-modal-actions">
            <button type="button" class="cash-button" data-close-cash-count>Annuler</button>
            <button type="button" class="cash-button cash-button-primary" data-save-cash-count ${disabled}>Valider le comptage</button>
          </div>
        </div>
      </div>
    `;
  }

  function renderControlChecks(day, calculations) {
    const valueForMoney = (value) => {
      if (value === null || value === undefined || value === "") return value;
      return typeof value === "string" ? parseMoney(value) : Number(value);
    };
    const rows = [
      {
        label: "Saisie factures",
        expected: calculations.paymentsTotal ?? day.paymentsTotal,
        actual: day.invoiceTotal,
        difference: calculations.entryDifference ?? day.entryDifference,
        ready: Number(day.receiptCount || 0) > 0,
      },
      {
        label: "Especes",
        expected: calculations.expectedCash ?? day.expectedCash,
        actual: calculations.cashGrossTotal !== undefined ? calculations.cashGrossTotal - calculations.cashDepositTotal : day.countedCash,
        difference: calculations.cashDifference ?? day.cashDifference,
        ready: Boolean(day.hasCashCount || state.cashCountDraft?.hasCashCount),
      },
      {
        label: "Cheques",
        expected: day.checkSales,
        actual: state.cashCountDraft?.checkCounted || day.checkCounted,
        difference: calculations.checkDifference ?? day.checkDifference,
        ready: parseMoney(day.checkSales) <= 0 || String(state.cashCountDraft?.checkCounted || day.checkCounted || "").trim() !== "",
      },
      {
        label: "Virements",
        expected: day.transferSales,
        actual: state.cashCountDraft?.transferCounted || day.transferCounted,
        difference: calculations.transferDifference ?? day.transferDifference,
        ready: parseMoney(day.transferSales) <= 0 || String(state.cashCountDraft?.transferCounted || day.transferCounted || "").trim() !== "",
      },
      {
        label: "Telecollecte",
        expected: day.cardSales,
        actual: state.cashCountDraft?.cardCounted || day.cardCounted,
        difference: calculations.cardDifference ?? day.cardDifference,
        ready: parseMoney(day.cardSales) <= 0 || String(state.cashCountDraft?.cardCounted || day.cardCounted || "").trim() !== "",
      },
    ];

    return `
      <div class="cash-check-grid">
        ${rows.map((row) => {
          const status = !row.ready ? "waiting" : isZero(row.difference) ? "ok" : "error";
          const expected = valueForMoney(row.expected);
          const actual = valueForMoney(row.actual);
          return `
            <div class="cash-check ${status === "ok" ? "is-ok" : status === "error" ? "is-error" : "is-waiting"}">
              <span>${esc(row.label)}</span>
              <strong>${esc(status === "ok" ? "OK" : status === "error" ? signedMoney(row.difference) : "A saisir")}</strong>
              <small>Attendu ${esc(money(expected))} - Reel ${esc(money(actual))}</small>
            </div>
          `;
        }).join("")}
      </div>
    `;
  }

  function renderCashCountLine(line, index, disabled) {
    const denomination = Number(line.denomination) || 0;
    const current = Number(line.currentQuantity) || 0;
    const deposit = Number(line.depositQuantity) || 0;
    const total = Math.round((current - deposit) * denomination * 100) / 100;

    return `
      <div class="cash-count-row">
        <div>
          <strong>${esc(line.label)} ${esc(money(denomination))}</strong>
        </div>
        <input type="number" min="0" step="1" inputmode="numeric" data-cash-count-line="${esc(index)}" data-cash-count-field="currentQuantity" value="${esc(line.currentQuantity)}" ${disabled}>
        <input type="number" min="0" step="1" inputmode="numeric" data-cash-count-line="${esc(index)}" data-cash-count-field="depositQuantity" value="${esc(line.depositQuantity)}" ${disabled}>
        <span data-cash-count-total="${esc(index)}">${esc(money(total))}</span>
      </div>
    `;
  }

  function cashControlField(name, label, value, disabled) {
    return `
      <label class="cash-field">
        <span>${esc(label)}</span>
        <input type="text" data-cash-bank-field="${esc(name)}" value="${esc(value)}" inputmode="decimal" ${disabled}>
      </label>
    `;
  }

  function renderDayForm(day, canManage) {
    const draft = state.draft || draftFromDay(day);
    const calculations = draftCalculations() || day;
    return `
      <section class="cash-card cash-control-compact">
        <div class="cash-section-head">
          <h2>Controles du jour</h2>
          <span>${esc(day.statusLabel || statusLabel(day.status))}</span>
        </div>
        <div class="cash-total-strip">
          ${totalPill("Factures", day.invoiceTotal)}
          ${totalPill("Especes", day.cashSales)}
          ${totalPill("CB", day.cardSales)}
          ${totalPill("Cheques", day.checkSales)}
          ${totalPill("Virements", day.transferSales)}
          ${totalPill("Remise banque", day.cashDepositTotal)}
          ${totalPill("Caisse comptee", day.countedCash)}
        </div>
        ${renderControlChecks(day, calculations)}
        <div class="cash-actions cash-actions-right">
          <button type="button" class="cash-button" data-open-day-controls ${canManage ? "" : "disabled"}>Modifier les controles</button>
        </div>
      </section>
    `;
  }

  function renderDayControlsModal(day, canManage) {
    if (!state.dayModalOpen || !canManage) return "";

    const draft = state.draft || draftFromDay(day);
    const disabled = canManage ? "" : "disabled";
    const hasReceipts = (day.receipts || []).length > 0;

    return `
      <div class="cash-modal-backdrop" data-close-day-controls>
        <div class="cash-modal cash-modal-wide" role="dialog" aria-modal="true" aria-label="Controles du jour" data-day-controls-modal>
          <div class="cash-modal-head">
            <div>
              <h2>Controles du jour</h2>
              <p>Caisse du ${esc(dateLabel(day.cashDate))}</p>
            </div>
            <button type="button" class="cash-mini-button" data-close-day-controls>Fermer</button>
          </div>
          <form class="cash-form cash-form-modal" data-day-form>
            <div class="cash-total-strip">
              ${totalPill("Factures", day.invoiceTotal)}
              ${totalPill("Especes", day.cashSales)}
              ${totalPill("CB", day.cardSales)}
              ${totalPill("Cheques", day.checkSales)}
              ${totalPill("Virements", day.transferSales)}
              ${totalPill("Remise banque", day.cashDepositTotal)}
              ${totalPill("Caisse comptee", day.countedCash)}
            </div>
            <div class="cash-grid cash-control-grid">
              ${field("cashDate", "Date", "date", draft.cashDate, disabled)}
              ${field("openingBalance", "Report veille", "text", draft.openingBalance, disabled, "decimal")}
              ${field("invoiceErrorsCount", "Erreurs facture", "number", draft.invoiceErrorsCount, disabled)}
            </div>
            ${hasReceipts ? "" : renderManualTotals(draft, disabled)}
            <label class="cash-field cash-field-full">
              <span>Notes</span>
              <textarea data-field="notes" ${disabled}>${esc(draft.notes)}</textarea>
            </label>
            <div class="cash-modal-actions">
              <button type="button" class="cash-button" data-close-day-controls>Annuler</button>
              <button type="submit" class="cash-button cash-button-primary" ${disabled}>Enregistrer</button>
            </div>
          </form>
        </div>
      </div>
    `;
  }

  function totalPill(label, value) {
    return `<span><small>${esc(label)}</small><strong>${esc(money(value))}</strong></span>`;
  }

  function renderManualTotals(draft, disabled) {
    return `
      <details class="cash-manual-totals">
        <summary>Totaux manuels sans encaissements</summary>
        <div class="cash-grid">
          ${field("invoiceTotal", "Total factures", "text", draft.invoiceTotal, disabled, "decimal")}
          ${field("cashSales", "Especes", "text", draft.cashSales, disabled, "decimal")}
          ${field("cardSales", "Carte bancaire", "text", draft.cardSales, disabled, "decimal")}
          ${field("checkSales", "Cheques", "text", draft.checkSales, disabled, "decimal")}
          ${field("transferSales", "Virements", "text", draft.transferSales, disabled, "decimal")}
        </div>
      </details>
    `;
  }

  function field(name, label, type, value, disabled, inputMode = "") {
    return `
      <label class="cash-field">
        <span>${esc(label)}</span>
        <input type="${esc(type)}" data-field="${esc(name)}" value="${esc(value)}" ${inputMode ? `inputmode="${esc(inputMode)}"` : ""} ${disabled}>
      </label>
    `;
  }

  function renderMovements(day, canManage) {
    const movements = day.movements || [];
    return `
      <section class="cash-card cash-movements">
        <div class="cash-section-head">
          <h2>Mouvements caisse</h2>
          <span>Entrees ${esc(money(day.cashInTotal))} - Sorties ${esc(money(day.cashOutTotal))}</span>
        </div>
        <div class="cash-table-wrap">
          <table class="cash-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>Libelle</th>
                <th>Date</th>
                <th>Montant</th>
                <th>Justificatif</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              ${movements.length ? movements.map((movement) => renderMovementRow(movement, canManage)).join("") : '<tr><td colspan="6" class="cash-muted">Aucun mouvement.</td></tr>'}
            </tbody>
          </table>
        </div>
      </section>
    `;
  }

  function renderMovementModal(day, canManage) {
    if (!state.movementModalOpen || !canManage) return "";

    const draft = state.movementDraft;
    return `
      <div class="cash-modal-backdrop" data-close-movement>
        <div class="cash-modal" role="dialog" aria-modal="true" aria-label="Mouvement caisse" data-cash-modal>
          <div class="cash-modal-head">
            <div>
              <h2>${draft.id ? "Modifier le mouvement" : "Nouveau mouvement"}</h2>
              <p>${draft.type === "cash_in" ? "Entree d'especes" : "Sortie d'especes"}</p>
            </div>
            <button type="button" class="cash-mini-button" data-close-movement>Fermer</button>
          </div>
          <div class="cash-movement-form cash-movement-form-modal">
            <select data-movement-field="type">
              ${(state.data?.movementTypes || []).map((type) => `<option value="${esc(type.value)}" ${draft.type === type.value ? "selected" : ""}>${esc(type.label)}</option>`).join("")}
            </select>
            <input type="text" data-movement-field="label" value="${esc(draft.label)}" placeholder="Libelle">
            <input type="text" data-movement-field="amount" value="${esc(draft.amount)}" placeholder="Montant" inputmode="decimal">
            <input type="date" data-movement-field="occurredOn" value="${esc(draft.occurredOn || day.cashDate || today())}">
            <label class="cash-file">
              <input type="file" data-movement-file accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/png,image/jpeg,image/webp">
              <span>${esc(draft.attachmentName || "Justificatif")}</span>
            </label>
          </div>
          <div class="cash-modal-actions">
            <button type="button" class="cash-button" data-close-movement>Annuler</button>
            <button type="button" class="cash-button cash-button-primary" data-save-movement>${draft.id ? "Modifier" : "Ajouter"}</button>
          </div>
        </div>
      </div>
    `;
  }

  function renderMovementRow(movement, canManage) {
    const amount = movement.type === "cash_out" ? -Math.abs(Number(movement.amount || 0)) : Number(movement.amount || 0);
    return `
      <tr>
        <td>${esc(movement.typeLabel || movement.type)}</td>
        <td>${esc(movement.label)}</td>
        <td>${esc(dateLabel(movement.occurredOn))}</td>
        <td class="${amount < 0 ? "cash-negative" : "cash-positive"}">${esc(signedMoney(amount))}</td>
        <td>${movement.justificationPath ? `<a href="${esc(movement.justificationPath)}" target="_blank" rel="noopener">${esc(movement.originalName || "Ouvrir")}</a>` : "-"}</td>
        <td class="cash-row-actions">
          <button type="button" class="cash-mini-button cash-icon-button" data-edit-movement="${esc(movement.id)}" title="Modifier le mouvement" aria-label="Modifier le mouvement" ${canManage ? "" : "disabled"}>${actionIcon("edit")}</button>
          <button type="button" class="cash-mini-button cash-icon-button is-danger" data-delete-movement="${esc(movement.id)}" title="Supprimer le mouvement" aria-label="Supprimer le mouvement" ${canManage ? "" : "disabled"}>${actionIcon("trash")}</button>
        </td>
      </tr>
    `;
  }

  function printCashDay() {
    const day = selectedDay();
    if (!day) return;

    const calculations = draftCalculations() || day;
    const receipts = day.receipts || [];
    const movements = day.movements || [];
    const cashLines = day.cashCountLines || [];
    const invoiceNumbers = receipts
      .map((receipt) => String(receipt.invoiceNumber || "").trim())
      .filter(Boolean);
    const title = `Controle caisse - ${dateLabel(day.cashDate)} - ${activeSiteName()}`;
    const receiptRows = receipts.length ? receipts.map((receipt) => {
      const validation = receiptValidation(receipt);
      return `
        <tr>
          <td>${esc(receipt.invoiceNumber || "-")}</td>
          <td>${esc(receipt.customerName || "-")}</td>
          <td>${esc(money(receipt.invoiceTotal))}</td>
          <td>${esc(money(receipt.cashAmount))}</td>
          <td>${esc(money(receipt.cardAmount))}</td>
          <td>${esc(money(receipt.checkAmount))}</td>
          <td>${esc(money(receipt.transferAmount))}</td>
          <td>${esc(money(receipt.controlAmount))}</td>
          <td>${esc(validation.label)}${validation.detail ? ` - ${esc(validation.detail)}` : ""}</td>
        </tr>
      `;
    }).join("") : '<tr><td colspan="9" class="muted">Aucune facture saisie.</td></tr>';
    const cashRows = cashLines.length ? cashLines.map((line) => {
      const current = Number(line.currentQuantity || 0);
      const deposit = Number(line.depositQuantity || 0);
      const denomination = Number(line.denomination || 0);
      return `
        <tr>
          <td>${esc(line.label || "")} ${esc(money(denomination))}</td>
          <td>${esc(current)}</td>
          <td>${esc(deposit)}</td>
          <td>${esc(money((current - deposit) * denomination))}</td>
        </tr>
      `;
    }).join("") : '<tr><td colspan="4" class="muted">Aucun comptage detaille.</td></tr>';
    const movementRows = movements.length ? movements.map((movement) => {
      const amount = movement.type === "cash_out" ? -Math.abs(Number(movement.amount || 0)) : Number(movement.amount || 0);
      return `
        <tr>
          <td>${esc(movement.typeLabel || movement.type || "-")}</td>
          <td>${esc(movement.label || "-")}</td>
          <td>${esc(dateLabel(movement.occurredOn))}</td>
          <td>${esc(signedMoney(amount))}</td>
        </tr>
      `;
    }).join("") : '<tr><td colspan="4" class="muted">Aucun mouvement.</td></tr>';
    const html = `
      <!doctype html>
      <html lang="fr">
        <head>
          <meta charset="utf-8">
          <title>${esc(title)}</title>
          <style>
            :root{${themeCssVariables()};font-family:Arial,Helvetica,sans-serif;color:#0f172a}
            *{box-sizing:border-box}
            body{margin:0;background:#fff;padding:24px}
            header{display:flex;justify-content:space-between;gap:16px;border-bottom:3px solid var(--theme-primary-color);padding-bottom:14px;margin-bottom:18px}
            h1{margin:0;font-size:24px;line-height:1.15}
            h2{margin:22px 0 10px;font-size:16px}
            p{margin:4px 0;color:#475569;font-size:12px;font-weight:700}
            .badge{display:inline-flex;border-radius:999px;background:#f1f5f9;color:#334155;padding:5px 9px;font-size:12px;font-weight:800}
            .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px}
            .card{border:1px solid #e2e8f0;border-radius:8px;padding:10px;background:#f8fafc}
            .card span{display:block;color:#64748b;font-size:10px;font-weight:900;text-transform:uppercase}
            .card strong{display:block;margin-top:4px;font-size:15px}
            table{width:100%;border-collapse:collapse;margin-top:8px;page-break-inside:auto}
            th,td{border:1px solid #e2e8f0;padding:7px 8px;text-align:left;font-size:11px;vertical-align:top}
            th{background:#f8fafc;color:#475569;text-transform:uppercase;font-size:10px}
            tr{page-break-inside:avoid}
            .muted{color:#64748b;text-align:center}
            .notes{min-height:52px;border:1px solid #e2e8f0;border-radius:8px;padding:10px;white-space:pre-wrap}
            @page{size:A4;margin:12mm}
            @media print{body{padding:0}.no-print{display:none}}
          </style>
        </head>
        <body>
          <header>
            <div>
              <h1>Controle caisse</h1>
              <p>${esc(activeSiteName())} - ${esc(dateLabel(day.cashDate))}</p>
              <p>Factures: ${esc(invoiceNumbers.length ? invoiceNumbers.join(", ") : "aucun numero saisi")}</p>
            </div>
            <div>
              <span class="badge">${esc(day.statusLabel || statusLabel(day.status))}</span>
              <p>Generation ${esc(new Date().toLocaleDateString("fr-FR"))}</p>
            </div>
          </header>
          <section class="grid">
            <div class="card"><span>Encaissements</span><strong>${esc(money(calculations.paymentsTotal ?? day.paymentsTotal))}</strong></div>
            <div class="card"><span>Factures</span><strong>${esc(money(day.invoiceTotal))}</strong></div>
            <div class="card"><span>Caisse attendue</span><strong>${esc(money(calculations.expectedCash ?? day.expectedCash))}</strong></div>
            <div class="card"><span>Ecart caisse</span><strong>${esc(signedMoney(calculations.cashDifference ?? day.cashDifference))}</strong></div>
          </section>
          <h2>Encaissements du jour</h2>
          <table>
            <thead>
              <tr>
                <th>N facture</th>
                <th>Client</th>
                <th>Total facture</th>
                <th>Especes</th>
                <th>CB</th>
                <th>Cheque</th>
                <th>Virement</th>
                <th>Autre</th>
                <th>Controle</th>
              </tr>
            </thead>
            <tbody>${receiptRows}</tbody>
          </table>
          <h2>Comptage especes</h2>
          <table>
            <thead><tr><th>Coupure</th><th>Caisse</th><th>Remise</th><th>Total cloture</th></tr></thead>
            <tbody>${cashRows}</tbody>
          </table>
          <h2>Mouvements caisse</h2>
          <table>
            <thead><tr><th>Type</th><th>Libelle</th><th>Date</th><th>Montant</th></tr></thead>
            <tbody>${movementRows}</tbody>
          </table>
          <h2>Notes</h2>
          <div class="notes">${esc(day.notes || "")}</div>
          <script>window.addEventListener("load",function(){setTimeout(function(){window.print();},150);});<\/script>
        </body>
      </html>
    `;

    const printWindow = window.open("", "_blank", "width=980,height=1200");
    if (!printWindow) {
      window.alert("Autorise les popups pour generer le PDF de caisse.");
      return;
    }

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
  }

  function actionIcon(name) {
    const paths = {
      view: '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"></path><circle cx="12" cy="12" r="3"></circle>',
      edit: '<path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"></path>',
      trash: '<path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v5"></path><path d="M14 11v5"></path>',
    };

    return `<svg class="cash-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${paths[name] || ""}</svg>`;
  }

  function refreshReceiptModalStatus() {
    const validation = receiptValidation(state.receiptDraft);
    const panel = root?.querySelector("[data-receipt-validation]");
    if (panel) {
      panel.className = `cash-entry-check ${validation.status === "ok" ? "is-ok" : validation.status === "error" ? "is-error" : "is-waiting"}`;
      panel.innerHTML = `<strong>${esc(validation.label)}</strong><span>${esc(validation.detail)} - Paiements ${esc(money(validation.paymentTotal))}</span>`;
    }

    const invoiceTotal = parseMoney(state.receiptDraft.invoiceTotal);
    const paymentTotal = receiptPaymentTotal(state.receiptDraft);
    const hasInput = invoiceTotal > 0 || paymentTotal > 0;
    root?.querySelectorAll("[data-receipt-field-shell]").forEach((shell) => {
      const name = shell.dataset.receiptFieldShell;
      shell.classList.remove("is-valid", "is-invalid");

      if (name === "invoiceTotal" && hasInput) {
        shell.classList.add(invoiceTotal > 0 ? "is-valid" : "is-invalid");
      }

      if (["cashAmount", "cardAmount", "checkAmount", "transferAmount", "controlAmount"].includes(name) && paymentTotal > 0) {
        const value = parseMoney(state.receiptDraft[name]);
        if (value > 0) shell.classList.add(validation.status === "ok" ? "is-valid" : "is-invalid");
      }
    });
  }

  function refreshCashCountModalStatus() {
    const day = selectedDay();
    if (!day) return;

    const totals = cashCountTotalsFromDraft();
    const calculations = draftCalculations() || day;
    const difference = calculations.cashDifference ?? day.cashDifference;
    const hasCount = Boolean(state.cashCountDraft?.hasCashCount || day.hasCashCount);
    const status = !hasCount ? "waiting" : isZero(difference) ? "ok" : "error";
    const panel = root?.querySelector("[data-cash-count-validation]");

    if (panel) {
      panel.className = `cash-entry-check ${status === "ok" ? "is-ok" : status === "error" ? "is-error" : "is-waiting"}`;
      panel.innerHTML = `<strong>${esc(status === "ok" ? "OK" : status === "error" ? "Ecart" : "A saisir")}</strong><span>${esc(status === "error" ? `Ecart ${signedMoney(difference)}` : "Comptage en cours")} - Caisse comptee ${esc(money(totals.countedCash))}</span>`;
    }

    (state.cashCountDraft?.lines || []).forEach((line, index) => {
      const total = roundMoney((Number(line.currentQuantity) - Number(line.depositQuantity)) * Number(line.denomination));
      const target = root?.querySelector(`[data-cash-count-total="${index}"]`);
      if (target) target.textContent = money(total);
    });
  }

  function bindElements() {
    root.querySelectorAll("[data-view]").forEach((button) => {
      button.addEventListener("click", () => {
        state.view = button.dataset.view || "dashboard";
        render();
      });
    });

    root.querySelectorAll("[data-open-day]").forEach((button) => {
      button.addEventListener("click", () => {
        if (!selectDayById(button.dataset.openDay)) return;
        state.view = "days";
        render();
      });
    });

    root.querySelector("[data-day-form]")?.addEventListener("submit", async (event) => {
      event.preventDefault();
      await saveDay();
    });

    root.querySelectorAll("[data-field]").forEach((input) => {
      input.addEventListener("input", () => {
        if (!state.draft) return;
        state.draft[input.dataset.field] = input.value;
      });
    });

    root.querySelectorAll("[data-receipt-field]").forEach((input) => {
      input.addEventListener("input", () => {
        state.receiptDraft[input.dataset.receiptField] = input.value;
        refreshReceiptModalStatus();
      });
      input.addEventListener("change", () => {
        state.receiptDraft[input.dataset.receiptField] = input.value;
        refreshReceiptModalStatus();
      });
    });

    root.querySelectorAll("[data-open-receipt]").forEach((button) => {
      button.addEventListener("click", () => {
        state.receiptDraft = emptyReceiptDraft(selectedDay()?.cashDate || today());
        state.receiptModalOpen = true;
        render();
      });
    });
    root.querySelectorAll("[data-close-receipt]").forEach((element) => {
      element.addEventListener("click", () => {
        state.receiptModalOpen = false;
        state.receiptDraft = emptyReceiptDraft(selectedDay()?.cashDate || today());
        render();
      });
    });
    root.querySelector("[data-receipt-modal]")?.addEventListener("click", (event) => {
      event.stopPropagation();
    });
    root.querySelector("[data-save-receipt]")?.addEventListener("click", saveReceipt);
    root.querySelectorAll("[data-edit-receipt]").forEach((button) => {
      button.addEventListener("click", () => editReceipt(button.dataset.editReceipt));
    });
    root.querySelectorAll("[data-delete-receipt]").forEach((button) => {
      button.addEventListener("click", () => deleteReceipt(button.dataset.deleteReceipt));
    });

    root.querySelectorAll("[data-cash-count-line]").forEach((input) => {
      input.addEventListener("input", () => {
        const index = Number(input.dataset.cashCountLine);
        const field = input.dataset.cashCountField;
        if (!state.cashCountDraft?.lines?.[index] || !field) return;
        state.cashCountDraft.lines[index][field] = input.value;
        state.cashCountDraft.hasCashCount = true;
        refreshCashCountModalStatus();
      });
    });

    root.querySelectorAll("[data-cash-bank-field]").forEach((input) => {
      input.addEventListener("input", () => {
        if (!state.cashCountDraft) return;
        state.cashCountDraft[input.dataset.cashBankField] = input.value;
        refreshCashCountModalStatus();
      });
    });

    root.querySelector("[data-save-cash-count]")?.addEventListener("click", saveCashCount);

    root.querySelectorAll("[data-open-cash-count]").forEach((button) => {
      button.addEventListener("click", () => {
        const day = selectedDay();
        if (!day) return;
        state.cashCountDraft = state.cashCountDraft || cashCountDraftFromDay(day);
        state.cashCountModalOpen = true;
        render();
      });
    });

    root.querySelectorAll("[data-close-cash-count]").forEach((element) => {
      element.addEventListener("click", () => {
        state.cashCountModalOpen = false;
        render();
      });
    });

    root.querySelector("[data-cash-count-modal]")?.addEventListener("click", (event) => {
      event.stopPropagation();
    });

    root.querySelectorAll("[data-open-day-controls]").forEach((button) => {
      button.addEventListener("click", () => {
        state.dayModalOpen = true;
        render();
      });
    });

    root.querySelectorAll("[data-close-day-controls]").forEach((element) => {
      element.addEventListener("click", () => {
        state.dayModalOpen = false;
        render();
      });
    });

    root.querySelector("[data-day-controls-modal]")?.addEventListener("click", (event) => {
      event.stopPropagation();
    });

    root.querySelectorAll("[data-open-movement]").forEach((button) => {
      button.addEventListener("click", () => {
        state.movementDraft = {
          ...emptyMovementDraft(),
          type: button.dataset.openMovement || "cash_out",
          occurredOn: selectedDay()?.cashDate || today(),
        };
        state.movementModalOpen = true;
        render();
      });
    });

    root.querySelectorAll("[data-close-movement]").forEach((element) => {
      element.addEventListener("click", () => {
        state.movementModalOpen = false;
        state.movementDraft = emptyMovementDraft();
        render();
      });
    });

    root.querySelector("[data-cash-modal]")?.addEventListener("click", (event) => {
      event.stopPropagation();
    });

    root.querySelectorAll("[data-movement-field]").forEach((input) => {
      input.addEventListener("input", () => {
        state.movementDraft[input.dataset.movementField] = input.value;
      });
      input.addEventListener("change", () => {
        state.movementDraft[input.dataset.movementField] = input.value;
      });
    });

    root.querySelector("[data-movement-file]")?.addEventListener("change", readMovementFile);

    root.querySelectorAll("[data-edit-day]").forEach((button) => {
      button.addEventListener("click", () => {
        if (!selectDayById(button.dataset.editDay)) return;
        state.view = "days";
        state.dayModalOpen = true;
        render();
      });
    });

    root.querySelectorAll("[data-delete-day]").forEach((button) => {
      button.addEventListener("click", () => deleteDay(button.dataset.deleteDay));
    });

    root.querySelectorAll("[data-view-day]").forEach((button) => {
      button.addEventListener("click", () => {
        if (!selectDayById(button.dataset.viewDay)) return;
        state.view = "days";
        render();
      });
    });

    root.querySelectorAll("[data-list-filter]").forEach((input) => {
      input.addEventListener("change", () => {
        if (input.dataset.listFilter === "year") {
          state.listYear = input.value;
        }
        if (input.dataset.listFilter === "month") {
          state.listMonth = input.value;
        }
        render();
      });
    });

    root.querySelectorAll("[data-open-create-day]").forEach((button) => {
      button.addEventListener("click", () => {
        state.createDayDate = state.data?.nextDate || today();
        state.createDayModalOpen = true;
        render();
      });
    });

    root.querySelectorAll("[data-close-create-day]").forEach((element) => {
      element.addEventListener("click", () => {
        state.createDayModalOpen = false;
        render();
      });
    });

    root.querySelector("[data-create-day-modal]")?.addEventListener("click", (event) => {
      event.stopPropagation();
    });

    root.querySelector("[data-create-day-date]")?.addEventListener("input", (event) => {
      state.createDayDate = event.target.value;
    });
    root.querySelector("[data-create-day-date]")?.addEventListener("change", (event) => {
      state.createDayDate = event.target.value;
    });

    root.querySelectorAll("[data-create-day]").forEach((button) => {
      button.addEventListener("click", createDay);
    });

    root.querySelector("[data-save-movement]")?.addEventListener("click", saveMovement);
    root.querySelector("[data-cancel-movement]")?.addEventListener("click", () => {
      state.movementDraft = emptyMovementDraft();
      render();
    });

    root.querySelectorAll("[data-edit-movement]").forEach((button) => {
      button.addEventListener("click", () => editMovement(button.dataset.editMovement));
    });

    root.querySelectorAll("[data-delete-movement]").forEach((button) => {
      button.addEventListener("click", () => deleteMovement(button.dataset.deleteMovement));
    });

    root.querySelectorAll("[data-print-cash-day]").forEach((button) => {
      button.addEventListener("click", printCashDay);
    });
  }

  async function createDay() {
    if (state.saving) return;
    const input = root.querySelector("[data-create-day-date]");
    const cashDate = input?.value || state.createDayDate || state.data?.nextDate || today();
    await withSaving(async () => {
      const data = await request("create_day", { cashDate });
      mergeDay(data.day);
      state.view = "days";
      state.createDayModalOpen = false;
      state.createDayDate = data.nextDate || today();
      const parts = monthParts(data.day?.cashDate || cashDate);
      state.listYear = parts.year;
      state.listMonth = parts.month;
      state.receiptDraft = emptyReceiptDraft(data.day?.cashDate || cashDate);
      state.receiptModalOpen = false;
      state.cashCountModalOpen = false;
      state.dayModalOpen = false;
      state.cashCountDraft = cashCountDraftFromDay(data.day);
      state.movementDraft = emptyMovementDraft();
      state.movementModalOpen = false;
      if (data.nextDate) state.data.nextDate = data.nextDate;
    });
  }

  async function saveDay() {
    if (!state.draft || state.saving) return;
    await withSaving(async () => {
      const data = await request("save_day", state.draft);
      mergeDay(data.day);
      state.dayModalOpen = false;
    });
  }

  async function deleteDay(id) {
    if (!id || state.saving) return;
    const day = (state.data?.days || []).find((item) => Number(item.id) === Number(id));
    const label = day ? dateLabel(day.cashDate) : "cette caisse";
    if (!window.confirm(`Supprimer la caisse du ${label} ? Les factures, mouvements et comptages lies seront aussi supprimes.`)) return;

    await withSaving(async () => {
      await request("delete_day", { id });
      if (!state.data) return;

      state.data.days = (state.data.days || []).filter((item) => Number(item.id) !== Number(id));
      const next = state.data.days[0] || null;
      state.selectedId = next?.id || null;
      state.draft = next ? draftFromDay(next) : null;
      state.receiptDraft = emptyReceiptDraft(next?.cashDate || today());
      state.receiptModalOpen = false;
      state.cashCountModalOpen = false;
      state.dayModalOpen = false;
      state.cashCountDraft = next ? cashCountDraftFromDay(next) : null;
      state.movementDraft = emptyMovementDraft();
      state.movementModalOpen = false;
    });
  }

  async function saveReceipt() {
    const day = selectedDay();
    if (!day || state.saving) return;
    const payload = {
      ...state.receiptDraft,
      dayId: day.id,
      occurredOn: state.receiptDraft.occurredOn || day.cashDate || today(),
    };

    await withSaving(async () => {
      const data = await request("save_receipt", payload);
      mergeDay(data.day);
      state.receiptDraft = emptyReceiptDraft(data.day?.cashDate || day.cashDate || today());
      state.receiptModalOpen = false;
    });
  }

  function editReceipt(id) {
    const receipt = (selectedDay()?.receipts || []).find((item) => Number(item.id) === Number(id));
    if (!receipt) return;

    state.receiptDraft = {
      id: receipt.id,
      invoiceNumber: receipt.invoiceNumber || "",
      customerName: receipt.customerName || "",
      occurredOn: receipt.occurredOn || selectedDay()?.cashDate || today(),
      invoiceTotal: numberValue(receipt.invoiceTotal),
      cashAmount: numberValue(receipt.cashAmount),
      cardAmount: numberValue(receipt.cardAmount),
      checkAmount: numberValue(receipt.checkAmount),
      transferAmount: numberValue(receipt.transferAmount),
      controlAmount: numberValue(receipt.controlAmount),
      paymentNote: receipt.paymentNote || "",
      sortOrder: receipt.sortOrder || 100,
    };
    state.receiptModalOpen = true;
    render();
  }

  async function deleteReceipt(id) {
    if (!id || state.saving) return;
    if (!window.confirm("Supprimer cet encaissement ?")) return;

    await withSaving(async () => {
      const data = await request("delete_receipt", { id });
      mergeDay(data.day);
      state.receiptDraft = emptyReceiptDraft(data.day?.cashDate || selectedDay()?.cashDate || today());
      state.receiptModalOpen = false;
    });
  }

  async function saveCashCount() {
    const day = selectedDay();
    if (!day || !state.cashCountDraft || state.saving) return;

    await withSaving(async () => {
      const data = await request("save_cash_count", {
        dayId: day.id,
        checkCounted: state.cashCountDraft.checkCounted,
        transferCounted: state.cashCountDraft.transferCounted,
        cardCounted: state.cashCountDraft.cardCounted,
        lines: state.cashCountDraft.lines,
      });
      mergeDay(data.day);
      state.cashCountModalOpen = false;
    });
  }

  async function saveMovement() {
    const day = selectedDay();
    if (!day || state.saving) return;
    const payload = {
      ...state.movementDraft,
      dayId: day.id,
      occurredOn: state.movementDraft.occurredOn || day.cashDate || today(),
    };

    await withSaving(async () => {
      const data = await request("save_movement", payload);
      mergeDay(data.day);
      state.movementDraft = emptyMovementDraft();
      state.movementModalOpen = false;
    });
  }

  function editMovement(id) {
    const movement = (selectedDay()?.movements || []).find((item) => Number(item.id) === Number(id));
    if (!movement) return;

    state.movementDraft = {
      id: movement.id,
      type: movement.type || "cash_out",
      label: movement.label || "",
      amount: numberValue(movement.amount),
      occurredOn: movement.occurredOn || selectedDay()?.cashDate || today(),
      sortOrder: movement.sortOrder || 100,
      attachmentDataUrl: "",
      attachmentName: movement.originalName || "",
    };
    state.movementModalOpen = true;
    render();
  }

  async function deleteMovement(id) {
    if (!id || state.saving) return;
    if (!window.confirm("Supprimer ce mouvement de caisse ?")) return;

    await withSaving(async () => {
      const data = await request("delete_movement", { id });
      mergeDay(data.day);
      state.movementDraft = emptyMovementDraft();
    });
  }

  async function readMovementFile(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = () => {
      state.movementDraft.attachmentDataUrl = String(reader.result || "");
      state.movementDraft.attachmentName = file.name;
      const label = root.querySelector(".cash-file span");
      if (label) label.textContent = file.name;
    };
    reader.readAsDataURL(file);
  }

  async function withSaving(callback) {
    try {
      state.saving = true;
      setBusy(true);
      await callback();
      render();
    } catch (error) {
      window.alert(error instanceof Error ? error.message : "Operation impossible");
    } finally {
      state.saving = false;
      setBusy(false);
    }
  }

  function setBusy(isBusy) {
    root?.querySelectorAll("button,input,select,textarea").forEach((element) => {
      if (isBusy) {
        element.dataset.wasDisabled = element.disabled ? "1" : "0";
        element.disabled = true;
      } else if (element.dataset.wasDisabled === "0") {
        element.disabled = false;
      }
    });
  }

  function styles() {
    if (document.getElementById("crm-cash-control-style")) return;

    const style = document.createElement("style");
    style.id = "crm-cash-control-style";
    style.textContent = `
      #crm-cash-control-module{color:var(--color-secondary-900,#0f172a)}
      #crm-cash-control-module .cash-page{display:grid;gap:1rem}
      #crm-cash-control-module .cash-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem}
      #crm-cash-control-module h1,#crm-cash-control-module h2{margin:0;color:var(--color-secondary-900,#0f172a);letter-spacing:0;line-height:1.15}
      #crm-cash-control-module h1{font-size:1.55rem;font-weight:900}
      #crm-cash-control-module h2{font-size:1rem;font-weight:900}
      #crm-cash-control-module p{margin:.25rem 0 0;color:var(--color-secondary-500,#64748b);font-size:.86rem;font-weight:700}
      #crm-cash-control-module .cash-create{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:.5rem}
      #crm-cash-control-module .cash-card,#crm-cash-control-module .cash-summary-item,#crm-cash-control-module .cash-dashboard-card{border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.05)}
      #crm-cash-control-module .cash-pad{padding:1rem}
      #crm-cash-control-module .cash-loading{display:grid;min-height:16rem;place-items:center;color:var(--color-secondary-500,#64748b);font-weight:850}
      #crm-cash-control-module .cash-notice{border:1px solid #fecaca;border-radius:.5rem;background:#fef2f2;color:#991b1b;padding:.8rem;font-size:.86rem;font-weight:800}
      #crm-cash-control-module .cash-empty{display:grid;gap:.45rem;justify-items:start;padding:1.1rem}
      #crm-cash-control-module .cash-empty strong{font-size:1rem}
      #crm-cash-control-module .cash-empty span{color:var(--color-secondary-500,#64748b);font-size:.86rem;font-weight:700}
      #crm-cash-control-module .cash-button,#crm-cash-control-module .cash-mini-button{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:#fff;color:var(--color-secondary-700,#334155);font-weight:850;text-decoration:none;white-space:nowrap}
      #crm-cash-control-module .cash-button{min-height:2.4rem;padding:.55rem .8rem;font-size:.84rem}
      #crm-cash-control-module .cash-mini-button{min-height:2rem;padding:.4rem .55rem;font-size:.74rem}
      #crm-cash-control-module .cash-icon-button{width:2rem;min-width:2rem;padding:.35rem}
      #crm-cash-control-module .cash-icon{width:1rem;height:1rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #crm-cash-control-module .cash-button:hover,#crm-cash-control-module .cash-mini-button:hover{border-color:rgb(var(--theme-primary) / .45);color:rgb(var(--theme-primary))}
      #crm-cash-control-module .cash-button-primary{border-color:rgb(var(--theme-primary));background:rgb(var(--theme-primary));color:#fff}
      #crm-cash-control-module .cash-button-primary:hover{color:#fff;filter:brightness(.97)}
      #crm-cash-control-module button:disabled,#crm-cash-control-module input:disabled,#crm-cash-control-module select:disabled,#crm-cash-control-module textarea:disabled{opacity:.62;cursor:not-allowed}
      #crm-cash-control-module input,#crm-cash-control-module select,#crm-cash-control-module textarea{min-height:2.45rem;width:100%;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:#fff;color:var(--color-secondary-900,#0f172a);padding:.55rem .65rem;font-size:.84rem;font-weight:750;outline:none}
      #crm-cash-control-module textarea{min-height:5.8rem;resize:vertical}
      #crm-cash-control-module input:focus,#crm-cash-control-module select:focus,#crm-cash-control-module textarea:focus{border-color:rgb(var(--theme-primary) / .55);box-shadow:0 0 0 3px rgb(var(--theme-primary) / .12)}
      #crm-cash-control-module .cash-quick-actions{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem}
      #crm-cash-control-module .cash-quick-actions-buttons{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:.5rem}
      #crm-cash-control-module .cash-entry-check{display:flex;align-items:center;justify-content:space-between;gap:.75rem;border-bottom:1px solid var(--color-surface-200,#e2e8f0);padding:.8rem 1rem;font-size:.82rem;font-weight:850}
      #crm-cash-control-module .cash-entry-check strong{font-size:.9rem;font-weight:950}
      #crm-cash-control-module .cash-entry-check span{color:var(--color-secondary-600,#475569)}
      #crm-cash-control-module .cash-entry-check.is-ok{background:#f0fdf4;color:#15803d}
      #crm-cash-control-module .cash-entry-check.is-error{background:#fef2f2;color:#b91c1c}
      #crm-cash-control-module .cash-entry-check.is-waiting{background:var(--color-surface-50,#f8fafc);color:var(--color-secondary-600,#475569)}
      #crm-cash-control-module .cash-check-badge{display:inline-flex;align-items:center;gap:.35rem;border-radius:999px;padding:.26rem .5rem;font-size:.72rem;font-weight:950;white-space:nowrap}
      #crm-cash-control-module .cash-check-badge small{font-size:.68rem;font-weight:850;opacity:.82}
      #crm-cash-control-module .cash-check-badge.is-ok{background:#dcfce7;color:#15803d}
      #crm-cash-control-module .cash-check-badge.is-error{background:#fee2e2;color:#b91c1c}
      #crm-cash-control-module .cash-check-badge.is-waiting{background:var(--color-surface-100,#f1f5f9);color:var(--color-secondary-600,#475569)}
      #crm-cash-control-module .cash-dashboard{display:grid;gap:1rem}
      #crm-cash-control-module .cash-dashboard-actions{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:flex-end}
      #crm-cash-control-module .cash-dashboard-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.8rem}
      #crm-cash-control-module .cash-dashboard-card{display:grid;grid-template-columns:2.55rem minmax(0,1fr);align-items:center;gap:.75rem;min-width:0;padding:.9rem}
      #crm-cash-control-module .cash-dashboard-card-icon{display:grid;place-items:center;width:2.55rem;height:2.55rem;border-radius:.55rem;background:color-mix(in srgb,var(--cash-card-color,var(--theme-primary-color)) 14%,white);color:var(--cash-card-color,var(--theme-primary-color))}
      #crm-cash-control-module .cash-dashboard-icon{width:1.25rem;height:1.25rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #crm-cash-control-module .cash-dashboard-card span,#crm-cash-control-module .cash-summary-item span{display:block;color:var(--color-secondary-500,#64748b);font-size:.73rem;font-weight:900;text-transform:uppercase}
      #crm-cash-control-module .cash-dashboard-card strong,#crm-cash-control-module .cash-summary-item strong{display:block;margin:.25rem 0;color:var(--color-secondary-900,#0f172a);font-size:1.25rem;font-weight:900;line-height:1.1;letter-spacing:0}
      #crm-cash-control-module .cash-dashboard-card small,#crm-cash-control-module .cash-summary-item small{display:block;color:var(--color-secondary-400,#94a3b8);font-size:.72rem;font-weight:750;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #crm-cash-control-module .cash-dashboard-section{overflow:hidden}
      #crm-cash-control-module .cash-list{overflow:hidden}
      #crm-cash-control-module .cash-list-summary{padding:1rem;border-bottom:1px solid var(--color-surface-200,#e2e8f0)}
      #crm-cash-control-module .cash-list-filters{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;border-bottom:1px solid var(--color-surface-200,#e2e8f0);padding:1rem}
      #crm-cash-control-module .cash-list-table{min-width:58rem}
      #crm-cash-control-module .cash-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.8rem}
      #crm-cash-control-module .cash-summary-item{display:grid;grid-template-columns:2.45rem minmax(0,1fr);align-items:center;gap:.72rem;min-width:0;padding:.9rem}
      #crm-cash-control-module .cash-summary-icon{display:grid;place-items:center;width:2.45rem;height:2.45rem;border-radius:.55rem;background:color-mix(in srgb,var(--cash-summary-color,var(--theme-primary-color)) 14%,white);color:var(--cash-summary-color,var(--theme-primary-color))}
      #crm-cash-control-module .cash-summary-icon-svg{width:1.2rem;height:1.2rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      #crm-cash-control-module .cash-summary-item.is-bad{border-color:#fecaca;background:#fff7f7}
      #crm-cash-control-module .cash-summary-item.is-bad strong{color:#b91c1c}
      #crm-cash-control-module .cash-main{display:grid;gap:1rem;min-width:0}
      #crm-cash-control-module .cash-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--color-surface-200,#e2e8f0);padding:.9rem 1rem}
      #crm-cash-control-module .cash-section-head span{color:var(--color-secondary-500,#64748b);font-size:.78rem;font-weight:850}
      #crm-cash-control-module .cash-form{display:grid;gap:1rem;padding-bottom:1rem}
      #crm-cash-control-module .cash-form-modal{padding:1rem 0 0}
      #crm-cash-control-module .cash-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.8rem;padding:1rem 1rem 0}
      #crm-cash-control-module .cash-control-grid{grid-template-columns:repeat(5,minmax(0,1fr))}
      #crm-cash-control-module .cash-control-compact{overflow:hidden}
      #crm-cash-control-module .cash-receipts,#crm-cash-control-module .cash-movements{overflow:hidden}
      #crm-cash-control-module .cash-receipt-form{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.75rem;padding:1rem;align-items:end}
      #crm-cash-control-module .cash-receipt-actions{display:flex;justify-content:flex-end;padding:1rem}
      #crm-cash-control-module .cash-receipt-actions .cash-button{min-width:11rem}
      #crm-cash-control-module .cash-receipt-modal{width:min(100%,37rem)}
      #crm-cash-control-module .cash-receipt-form-modal{grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem;padding:1rem}
      #crm-cash-control-module .cash-receipt-form-modal .cash-field:nth-child(10){grid-column:1 / -1}
      #crm-cash-control-module .cash-form-buttons{display:flex;gap:.5rem;align-items:center;grid-column:auto / span 2}
      #crm-cash-control-module .cash-form-buttons .cash-button{flex:1}
      #crm-cash-control-module .cash-total-strip{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:.5rem;padding:1rem 1rem 0}
      #crm-cash-control-module .cash-total-strip span{display:grid;gap:.15rem;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:var(--color-surface-50,#f8fafc);padding:.55rem .65rem;min-width:0}
      #crm-cash-control-module .cash-total-strip small{color:var(--color-secondary-500,#64748b);font-size:.68rem;font-weight:900;text-transform:uppercase}
      #crm-cash-control-module .cash-total-strip strong{color:var(--color-secondary-900,#0f172a);font-size:.9rem;font-weight:900;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #crm-cash-control-module .cash-count{overflow:hidden}
      #crm-cash-control-module .cash-count-summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:.5rem;padding:1rem 1rem 0}
      #crm-cash-control-module .cash-count-summary span{display:grid;gap:.15rem;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:var(--color-surface-50,#f8fafc);padding:.55rem .65rem;min-width:0}
      #crm-cash-control-module .cash-count-summary small{color:var(--color-secondary-500,#64748b);font-size:.68rem;font-weight:900;text-transform:uppercase}
      #crm-cash-control-module .cash-count-summary strong{color:var(--color-secondary-900,#0f172a);font-size:.9rem;font-weight:900;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #crm-cash-control-module .cash-count-table{display:grid;gap:.35rem;padding:1rem}
      #crm-cash-control-module .cash-count-head,#crm-cash-control-module .cash-count-row{display:grid;grid-template-columns:minmax(7rem,1fr) 5.5rem 5.5rem 6.5rem;gap:.45rem;align-items:center}
      #crm-cash-control-module .cash-count-head span{color:var(--color-secondary-500,#64748b);font-size:.68rem;font-weight:900;text-transform:uppercase}
      #crm-cash-control-module .cash-count-row{border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:#fff;padding:.4rem}
      #crm-cash-control-module .cash-count-row strong{display:block;color:var(--color-secondary-800,#1e293b);font-size:.78rem;font-weight:900}
      #crm-cash-control-module .cash-count-row input{min-height:2.1rem;text-align:center;padding:.35rem .4rem}
      #crm-cash-control-module .cash-count-row > span{justify-self:end;color:var(--color-secondary-900,#0f172a);font-size:.78rem;font-weight:900}
      #crm-cash-control-module .cash-bank-controls{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;padding:0 1rem 1rem}
      #crm-cash-control-module .cash-check-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.55rem;padding:1rem}
      #crm-cash-control-module .cash-check{display:grid;gap:.18rem;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.5rem;background:#fff;padding:.58rem .65rem;min-width:0}
      #crm-cash-control-module .cash-check span{color:var(--color-secondary-500,#64748b);font-size:.68rem;font-weight:950;text-transform:uppercase}
      #crm-cash-control-module .cash-check strong{font-size:.92rem;font-weight:950}
      #crm-cash-control-module .cash-check small{color:var(--color-secondary-500,#64748b);font-size:.68rem;font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #crm-cash-control-module .cash-check.is-ok{border-color:#bbf7d0;background:#f0fdf4}
      #crm-cash-control-module .cash-check.is-ok strong{color:#15803d}
      #crm-cash-control-module .cash-check.is-error{border-color:#fecaca;background:#fef2f2}
      #crm-cash-control-module .cash-check.is-error strong{color:#b91c1c}
      #crm-cash-control-module .cash-check.is-waiting{background:var(--color-surface-50,#f8fafc)}
      #crm-cash-control-module .cash-manual-totals{margin:0 1rem;border:1px dashed var(--color-surface-300,#cbd5e1);border-radius:.5rem;background:var(--color-surface-50,#f8fafc)}
      #crm-cash-control-module .cash-manual-totals summary{cursor:pointer;padding:.75rem .8rem;color:var(--color-secondary-600,#475569);font-size:.82rem;font-weight:900}
      #crm-cash-control-module .cash-manual-totals .cash-grid{padding:.2rem .8rem .8rem}
      #crm-cash-control-module .cash-field{display:grid;gap:.35rem;min-width:0}
      #crm-cash-control-module .cash-field span{color:var(--color-secondary-600,#475569);font-size:.76rem;font-weight:900}
      #crm-cash-control-module .cash-field.is-valid input{border-color:#22c55e;background:#f0fdf4}
      #crm-cash-control-module .cash-field.is-invalid input{border-color:#ef4444;background:#fef2f2}
      #crm-cash-control-module .cash-field-full{padding:0 1rem}
      #crm-cash-control-module .cash-actions{display:flex;justify-content:flex-end;padding:0 1rem}
      #crm-cash-control-module .cash-actions-right{padding:1rem}
      #crm-cash-control-module .cash-movement-actions{display:flex;justify-content:flex-end;gap:.5rem;padding:1rem}
      #crm-cash-control-module .cash-movement-form{display:grid;grid-template-columns:10rem minmax(10rem,1fr) 8rem 10rem minmax(8rem,12rem) auto auto;gap:.55rem;padding:1rem;align-items:center}
      #crm-cash-control-module .cash-movement-form-modal{grid-template-columns:1fr;gap:.7rem;padding:1rem 0 0}
      #crm-cash-control-module .cash-file{display:flex;min-width:0;align-items:center;justify-content:center;min-height:2.45rem;border:1px dashed var(--color-surface-300,#cbd5e1);border-radius:.5rem;background:var(--color-surface-50,#f8fafc);color:var(--color-secondary-600,#475569);font-size:.8rem;font-weight:850;cursor:pointer;padding:.45rem .6rem}
      #crm-cash-control-module .cash-file input{display:none}
      #crm-cash-control-module .cash-file span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      #crm-cash-control-module .cash-table-wrap{overflow:auto;border-top:1px solid var(--color-surface-200,#e2e8f0)}
      #crm-cash-control-module .cash-table{width:100%;border-collapse:collapse;min-width:52rem}
      #crm-cash-control-module .cash-receipt-table{min-width:64rem}
      #crm-cash-control-module th,#crm-cash-control-module td{border-bottom:1px solid var(--color-surface-200,#e2e8f0);padding:.72rem .8rem;text-align:left;font-size:.82rem;vertical-align:middle}
      #crm-cash-control-module th{background:var(--color-surface-50,#f8fafc);color:var(--color-secondary-500,#64748b);font-size:.72rem;font-weight:900;text-transform:uppercase}
      #crm-cash-control-module td{color:var(--color-secondary-800,#1e293b);font-weight:750}
      #crm-cash-control-module td a{color:rgb(var(--theme-primary));font-weight:900;text-decoration:none}
      #crm-cash-control-module .cash-row-actions{display:flex;justify-content:flex-end;gap:.4rem}
      #crm-cash-control-module .cash-row-ok td:first-child{box-shadow:inset 3px 0 #16a34a}
      #crm-cash-control-module .cash-row-error td:first-child{box-shadow:inset 3px 0 #dc2626}
      #crm-cash-control-module .cash-negative{color:#b91c1c}
      #crm-cash-control-module .cash-positive{color:#15803d}
      #crm-cash-control-module .cash-muted{color:var(--color-secondary-400,#94a3b8);text-align:center}
      #crm-cash-control-module .cash-mini-button.is-danger:hover{border-color:#fecaca;color:#b91c1c;background:#fff7f7}
      #crm-cash-control-module .cash-status-pill{display:inline-flex;align-items:center;border-radius:999px;background:color-mix(in srgb,var(--cash-pill,#64748b) 12%,white);color:var(--cash-pill,#64748b);padding:.25rem .48rem;font-size:.72rem;font-weight:900}
      #crm-cash-control-module .cash-modal-backdrop{position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;background:rgba(15,23,42,.45);padding:1rem}
      #crm-cash-control-module .cash-modal{width:min(100%,34rem);max-height:min(90vh,44rem);overflow:auto;border-radius:.75rem;background:#fff;box-shadow:0 24px 80px rgba(15,23,42,.25)}
      #crm-cash-control-module .cash-modal-wide{width:min(100%,56rem)}
      #crm-cash-control-module .cash-modal-scroll{max-height:60vh;overflow:auto}
      #crm-cash-control-module .cash-create-day-modal{width:min(100%,26rem)}
      #crm-cash-control-module .cash-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--color-surface-200,#e2e8f0);padding:1rem}
      #crm-cash-control-module .cash-modal-head h2{font-size:1.05rem}
      #crm-cash-control-module .cash-modal-head p{margin:.2rem 0 0}
      #crm-cash-control-module .cash-create-day-form{padding:1rem}
      #crm-cash-control-module .cash-modal-actions{display:flex;justify-content:flex-end;gap:.5rem;padding:1rem;border-top:1px solid var(--color-surface-200,#e2e8f0)}
      .dark #crm-cash-control-module .cash-card,.dark #crm-cash-control-module .cash-summary-item,.dark #crm-cash-control-module .cash-dashboard-card{background:var(--color-surface-900,#0f172a);border-color:var(--color-surface-700,#334155)}
      .dark #crm-cash-control-module input,.dark #crm-cash-control-module select,.dark #crm-cash-control-module textarea{background:var(--color-surface-800,#1e293b);border-color:var(--color-surface-700,#334155);color:#fff}
      .dark #crm-cash-control-module th{background:var(--color-surface-800,#1e293b)}
      @media (max-width:1180px){
        #crm-cash-control-module .cash-dashboard-grid,#crm-cash-control-module .cash-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
        #crm-cash-control-module .cash-grid,#crm-cash-control-module .cash-control-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
        #crm-cash-control-module .cash-receipt-form{grid-template-columns:repeat(2,minmax(0,1fr))}
        #crm-cash-control-module .cash-total-strip{grid-template-columns:repeat(3,minmax(0,1fr))}
        #crm-cash-control-module .cash-count-summary{grid-template-columns:repeat(3,minmax(0,1fr))}
        #crm-cash-control-module .cash-check-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
        #crm-cash-control-module .cash-bank-controls{grid-template-columns:repeat(3,minmax(0,1fr))}
        #crm-cash-control-module .cash-movement-form{grid-template-columns:repeat(2,minmax(0,1fr))}
      }
      @media (max-width:720px){
        #crm-cash-control-module .cash-head{display:grid}
        #crm-cash-control-module .cash-create{justify-content:stretch}
        #crm-cash-control-module .cash-create .cash-button{width:100%}
        #crm-cash-control-module .cash-dashboard-actions{justify-content:stretch}
        #crm-cash-control-module .cash-dashboard-actions .cash-button{flex:1}
        #crm-cash-control-module .cash-quick-actions{display:grid}
        #crm-cash-control-module .cash-quick-actions-buttons{display:grid;grid-template-columns:1fr 1fr;justify-content:stretch}
        #crm-cash-control-module .cash-quick-actions-buttons .cash-button{width:100%;white-space:normal}
        #crm-cash-control-module .cash-entry-check{display:grid}
        #crm-cash-control-module .cash-dashboard-grid,#crm-cash-control-module .cash-summary{grid-template-columns:1fr}
        #crm-cash-control-module .cash-list-filters{grid-template-columns:1fr}
        #crm-cash-control-module .cash-grid,#crm-cash-control-module .cash-control-grid,#crm-cash-control-module .cash-receipt-form{grid-template-columns:1fr}
        #crm-cash-control-module .cash-form-buttons{grid-column:auto}
        #crm-cash-control-module .cash-total-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
        #crm-cash-control-module .cash-count-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
        #crm-cash-control-module .cash-check-grid{grid-template-columns:1fr}
        #crm-cash-control-module .cash-count-head{display:none}
        #crm-cash-control-module .cash-count-row{grid-template-columns:minmax(6rem,1fr) 4.6rem 4.6rem;grid-template-areas:"label current deposit" "label total total"}
        #crm-cash-control-module .cash-count-row > div{grid-area:label}
        #crm-cash-control-module .cash-count-row input[data-cash-count-field="currentQuantity"]{grid-area:current}
        #crm-cash-control-module .cash-count-row input[data-cash-count-field="depositQuantity"]{grid-area:deposit}
        #crm-cash-control-module .cash-count-row > span{grid-area:total;justify-self:stretch;text-align:right}
        #crm-cash-control-module .cash-bank-controls{grid-template-columns:1fr}
        #crm-cash-control-module .cash-movement-actions{display:grid;grid-template-columns:1fr 1fr}
        #crm-cash-control-module .cash-movement-form{grid-template-columns:1fr}
        #crm-cash-control-module .cash-modal-backdrop{align-items:end;padding:.65rem}
        #crm-cash-control-module .cash-modal{max-height:86vh;border-radius:.75rem .75rem .5rem .5rem}
        #crm-cash-control-module .cash-modal-scroll{max-height:58vh}
        #crm-cash-control-module .cash-receipt-form-modal{grid-template-columns:repeat(2,minmax(0,1fr))}
        #crm-cash-control-module .cash-receipt-form-modal .cash-field:nth-child(1),#crm-cash-control-module .cash-receipt-form-modal .cash-field:nth-child(2),#crm-cash-control-module .cash-receipt-form-modal .cash-field:nth-child(10){grid-column:1 / -1}
        #crm-cash-control-module .cash-actions{justify-content:stretch}
        #crm-cash-control-module .cash-actions .cash-button{width:100%}
      }
    `;
    document.head.appendChild(style);
  }

  function mount() {
    const candidate = document.getElementById("crm-cash-control-module");
    if (!candidate || mountedRoots.has(candidate)) return;

    root = candidate;
    mountedRoots.add(candidate);
    watchRouteChanges();
    load();
  }

  function startMountObserver() {
    mount();
    const observer = new MutationObserver(mount);
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", startMountObserver, { once: true });
  } else {
    startMountObserver();
  }

  window.addEventListener("crm:active-site-changed", () => {
    if (root && mountedRoots.has(root)) load();
  });
})();

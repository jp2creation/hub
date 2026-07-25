(() => {
  const api = '/api/conges';
  const rootId = 'crm-leaves-module';
  const routeEvent = 'crm:leaves-route-changed';
  let root = null;
  let hostNode = null;
  let mountTimer = null;
  let wallResizeTimer = null;
  let filterRenderTimer = null;
  let mountAttempts = 0;
  const mountedRoots = new WeakSet();
  const state = {
    data: null,
    month: new Date(new Date().getFullYear(), new Date().getMonth(), 1),
    view: 'calendar',
    filters: {
      employeeId: 'all',
      type: 'all',
      status: 'active',
      query: '',
    },
    selectedDate: formatDate(new Date()),
    wallStartDate: formatDate(new Date()),
    wallMode: 'month',
    modal: null,
    exportModal: null,
  };

  const esc = (value) =>
    String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');

  function parseDate(value) {
    const [year, month, day] = String(value || '')
      .split('-')
      .map(Number);
    return new Date(year, month - 1, day);
  }

  function formatDate(date) {
    return [
      date.getFullYear(),
      String(date.getMonth() + 1).padStart(2, '0'),
      String(date.getDate()).padStart(2, '0'),
    ].join('-');
  }

  function dateLabel(value) {
    const date = parseDate(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  function monthLabel(date) {
    const label = date.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
    return label.charAt(0).toUpperCase() + label.slice(1);
  }

  function addDays(date, days) {
    const next = new Date(date);
    next.setDate(next.getDate() + days);
    return next;
  }

  function calendarDays(date) {
    const first = new Date(date.getFullYear(), date.getMonth(), 1);
    const last = new Date(date.getFullYear(), date.getMonth() + 1, 0);
    const start = addDays(first, -((first.getDay() + 6) % 7));
    const end = addDays(last, 6 - ((last.getDay() + 6) % 7));
    const days = [];
    const current = new Date(start);

    while (current <= end) {
      days.push(new Date(current));
      current.setDate(current.getDate() + 1);
    }

    return days;
  }

  function weekStart(date) {
    return addDays(date, -((date.getDay() + 6) % 7));
  }

  function weekKey(date) {
    return formatDate(weekStart(date));
  }

  function normalizeColor(value, fallback = '#facc15') {
    return /^#[0-9a-f]{6}$/i.test(String(value || '')) ? value : fallback;
  }

  function typeMeta(type) {
    return (
      (state.data?.types || []).find((item) => item.value === type) || {
        value: 'conge',
        label: 'Conge',
        color: '#facc15',
      }
    );
  }

  function employees() {
    return (state.data?.employees || []).filter((employee) => employee.active);
  }

  function employeeSortIndex(employeeId) {
    const index = employees().findIndex((employee) => Number(employee.id) === Number(employeeId));
    return index === -1 ? 9999 : index;
  }

  function selectedEmployee() {
    if (state.filters.employeeId === 'all') return null;
    return employees().find((employee) => Number(employee.id) === Number(state.filters.employeeId)) || null;
  }

  function currentEmployee() {
    const employeeId = Number(state.data?.user?.employeeId || 0);
    if (!employeeId) return null;
    return employees().find((employee) => Number(employee.id) === employeeId) || null;
  }

  function employeeForLeave(leave) {
    return employees().find((employee) => Number(employee.id) === Number(leave?.employeeId)) || null;
  }

  function canManage() {
    return Boolean(state.data?.user?.canManage);
  }

  function canViewTeam() {
    return Boolean(state.data?.user?.canViewTeam || canManage());
  }

  function canViewBalances() {
    return Boolean(state.data?.user?.canViewBalances || canManage());
  }

  function canViewRequests() {
    return state.data?.user?.canViewRequests !== false;
  }

  function canViewReports() {
    return Boolean(state.data?.user?.canViewReports || canManage());
  }

  function canManageSettings() {
    return Boolean(state.data?.user?.canManageSettings || canManage());
  }

  function canCreateRequest() {
    return state.data?.user?.canCreateRequest !== false;
  }

  function canExport() {
    return state.data?.user?.canExport !== false;
  }

  function employeePhoto(employee) {
    return String(employee?.photoUrl || employee?.photo_url || '').trim();
  }

  function employeeAvatar(employee, className = 'leave-person-avatar', fallbackName = '') {
    const name = employee?.name || fallbackName || 'Utilisateur';
    const photo = employeePhoto(employee);

    if (photo) {
      return `<span class="${esc(className)} has-photo"><img src="${esc(photo)}" alt="${esc(name)}" loading="lazy"></span>`;
    }

    return `<span class="${esc(className)}" style="--person-color:${esc(normalizeColor(employee?.color, '#38bdf8'))}">${esc(initials(name))}</span>`;
  }

  function isOwnLeave(leave) {
    const employee = currentEmployee();
    return Boolean(employee && Number(leave?.employeeId) === Number(employee.id));
  }

  function canEditLeave(leave) {
    if (canManage()) {
      return leave?.status !== 'approved' || state.data?.user?.role === 'admin';
    }
    return isOwnLeave(leave) && leave?.status === 'pending';
  }

  function canDeleteLeave(leave) {
    if (canManage()) {
      return leave?.status !== 'approved' || state.data?.user?.role === 'admin';
    }
    return isOwnLeave(leave) && leave?.status === 'pending';
  }

  function activeSiteId() {
    const fromApi = Number(window.CRM_ACTIVE_SITE?.getSiteId?.() || 0);
    if (Number.isFinite(fromApi) && fromApi > 0) return fromApi;

    try {
      const fromStorage = Number(window.localStorage.getItem('crm:active-site-id') || 0);
      if (Number.isFinite(fromStorage) && fromStorage > 0) return fromStorage;
    } catch (error) {
      // The server will fall back to the first authorized site.
    }

    const selected = Number(state.data?.selectedSiteId || state.data?.user?.selectedSiteId || 0);
    return Number.isFinite(selected) && selected > 0 ? selected : '';
  }

  function activeSiteName() {
    const siteId = Number(state.data?.selectedSiteId || state.data?.user?.selectedSiteId || activeSiteId());
    const site = (state.data?.sites || []).find((item) => Number(item.id) === siteId);
    return site?.name || 'Site actif';
  }

  function syncEmployeeFilter() {
    if (state.filters.employeeId === 'all') return;
    const exists = employees().some((employee) => Number(employee.id) === Number(state.filters.employeeId));
    if (!exists) state.filters.employeeId = 'all';
  }

  function statusLabel(status) {
    return (
      {
        approved: 'Valide',
        planned: 'Planifie',
        pending: 'A valider',
        refused: 'Refuse',
      }[status] ||
      status ||
      ''
    );
  }

  function periodLabel(period) {
    return (
      {
        full: 'Journee',
        morning: 'Matin',
        afternoon: 'Apres-midi',
      }[period] ||
      period ||
      ''
    );
  }

  function matchesFilters(leave) {
    const filters = state.filters;
    const query = filters.query.trim().toLowerCase();

    if (filters.employeeId !== 'all' && Number(leave.employeeId) !== Number(filters.employeeId)) return false;
    if (filters.type !== 'all' && leave.type !== filters.type) return false;
    if (filters.status === 'active' && leave.status === 'refused') return false;
    if (filters.status !== 'all' && filters.status !== 'active' && leave.status !== filters.status) return false;

    if (query) {
      const meta = typeMeta(leave.type);
      const haystack = [
        leave.employeeName,
        meta.label,
        statusLabel(leave.status),
        periodLabel(leave.period),
        leave.startDate,
        leave.endDate,
        leave.notes,
      ]
        .join(' ')
        .toLowerCase();
      if (!haystack.includes(query)) return false;
    }

    return true;
  }

  function filteredLeaves() {
    return (state.data?.leaves || []).filter(matchesFilters);
  }

  function activeLeaves() {
    return filteredLeaves()
      .filter((leave) => leave.status !== 'refused')
      .sort((a, b) => a.startDate.localeCompare(b.startDate) || a.employeeName.localeCompare(b.employeeName));
  }

  function leavesForDate(date) {
    return activeLeaves()
      .filter((leave) => leave.startDate <= date && leave.endDate >= date)
      .sort(
        (a, b) =>
          employeeSortIndex(a.employeeId) - employeeSortIndex(b.employeeId) ||
          a.employeeName.localeCompare(b.employeeName) ||
          a.startDate.localeCompare(b.startDate),
      );
  }

  function employeeIdsForWeek(day) {
    const start = weekStart(day);
    const end = addDays(start, 6);
    const first = formatDate(start);
    const last = formatDate(end);
    const seen = new Set();

    return activeLeaves()
      .filter((leave) => leave.startDate <= last && leave.endDate >= first)
      .sort(
        (a, b) =>
          employeeSortIndex(a.employeeId) - employeeSortIndex(b.employeeId) ||
          a.employeeName.localeCompare(b.employeeName) ||
          a.startDate.localeCompare(b.startDate),
      )
      .map((leave) => Number(leave.employeeId))
      .filter((employeeId) => {
        if (seen.has(employeeId)) return false;
        seen.add(employeeId);
        return true;
      });
  }

  function selectedDateLeaves() {
    return leavesForDate(state.selectedDate);
  }

  function monthLeaves() {
    const { first, last } = monthBounds();
    return activeLeaves().filter((leave) => leave.startDate <= last && leave.endDate >= first);
  }

  function monthBounds() {
    return {
      first: formatDate(new Date(state.month.getFullYear(), state.month.getMonth(), 1)),
      last: formatDate(new Date(state.month.getFullYear(), state.month.getMonth() + 1, 0)),
    };
  }

  function yearLeaves() {
    const first = `${state.month.getFullYear()}-01-01`;
    const last = `${state.month.getFullYear()}-12-31`;
    return activeLeaves().filter((leave) => leave.startDate <= last && leave.endDate >= first);
  }

  function daysCount(leave) {
    const start = parseDate(leave.startDate);
    const end = parseDate(leave.endDate);
    const days = Math.max(1, Math.round((end - start) / 86400000) + 1);
    return days === 1 && leave.period !== 'full' ? 0.5 : days;
  }

  function overlapDays(leave, startDate, endDate) {
    const start = parseDate(leave.startDate);
    const end = parseDate(leave.endDate);
    const rangeStart = parseDate(startDate);
    const rangeEnd = parseDate(endDate);
    const from = start > rangeStart ? start : rangeStart;
    const to = end < rangeEnd ? end : rangeEnd;

    if (to < from) return 0;

    const days = Math.max(1, Math.round((to - from) / 86400000) + 1);
    return days === 1 && leave.period !== 'full' ? 0.5 : days;
  }

  function formatDaysCount(value) {
    return Number.isInteger(value) ? String(value) : String(value).replace('.', ',');
  }

  function monthReportLeaves() {
    const { first, last } = monthBounds();

    return filteredLeaves()
      .filter((leave) => leave.startDate <= last && leave.endDate >= first)
      .sort(
        (a, b) =>
          a.startDate.localeCompare(b.startDate) ||
          a.endDate.localeCompare(b.endDate) ||
          employeeSortIndex(a.employeeId) - employeeSortIndex(b.employeeId) ||
          a.employeeName.localeCompare(b.employeeName),
      );
  }

  function openModal(leave) {
    const ownEmployee = currentEmployee();
    const fallbackEmployee = canManage() ? selectedEmployee() || employees()[0] : ownEmployee || employees()[0];
    const existingLeave = Boolean(leave?.id);

    state.modal = {
      id: leave?.id || '',
      employeeId: leave?.employeeId || fallbackEmployee?.id || '',
      startDate: leave?.startDate || formatDate(new Date()),
      endDate: leave?.endDate || leave?.startDate || formatDate(new Date()),
      type: leave?.type || 'conge',
      period: leave?.period || 'full',
      status: leave?.status || (canManage() ? 'approved' : 'pending'),
      notes: leave?.notes || '',
      readonly: existingLeave && !canEditLeave(leave),
      canDelete: existingLeave && canDeleteLeave(leave),
      canReview: existingLeave && canManage() && ['pending', 'planned'].includes(leave?.status),
    };
    render();
  }

  function openExportModal() {
    const { first, last } = monthBounds();
    const list = state.data?.export?.employees || employees();
    state.exportModal = {
      fromDate: first,
      toDate: last,
      includeOtherSites: false,
      employees: list,
      employeeIds: list.map((employee) => Number(employee.id)),
      loading: false,
      downloading: false,
      error: '',
    };
    render();
  }

  async function loadExportOptions(includeOtherSites) {
    if (!state.exportModal) return;

    state.exportModal.loading = true;
    state.exportModal.error = '';
    render();

    try {
      const data = await request('export_options', {
        includeOtherSites,
        fromDate: state.exportModal.fromDate,
        toDate: state.exportModal.toDate,
      });
      if (!state.exportModal) return;

      const existingIds = new Set(state.exportModal.employeeIds.map(Number));
      const list = data.employees || [];
      const keptIds = list.map((employee) => Number(employee.id)).filter((id) => existingIds.has(id));

      state.exportModal.includeOtherSites = includeOtherSites;
      state.exportModal.employees = list;
      state.exportModal.employeeIds = keptIds.length ? keptIds : list.map((employee) => Number(employee.id));
      state.exportModal.loading = false;
      render();
    } catch (error) {
      if (!state.exportModal) return;

      state.exportModal.loading = false;
      state.exportModal.error = error instanceof Error ? error.message : 'Options export indisponibles';
      render();
    }
  }

  function filenameFromDisposition(header) {
    const match = String(header || '').match(/filename="?([^"]+)"?/i);
    return match?.[1] || 'conges.pdf';
  }

  async function downloadExportPdf() {
    if (!state.exportModal) return;

    state.exportModal.downloading = true;
    state.exportModal.error = '';
    render();

    const modal = state.exportModal;
    const params = new URLSearchParams({ action: 'export_pdf' });
    const siteId = activeSiteId();
    if (siteId) params.set('siteId', String(siteId));

    try {
      const response = await fetch(`${api}?${params.toString()}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          fromDate: modal.fromDate,
          toDate: modal.toDate,
          employeeIds: modal.employeeIds,
          includeOtherSites: modal.includeOtherSites,
          siteId: siteId ? Number(siteId) : undefined,
        }),
      });

      if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.error || 'Export PDF impossible');
      }

      const blob = await response.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filenameFromDisposition(response.headers.get('Content-Disposition'));
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => window.URL.revokeObjectURL(url), 1000);

      state.exportModal = null;
      render();
    } catch (error) {
      if (!state.exportModal) return;

      state.exportModal.downloading = false;
      state.exportModal.error = error instanceof Error ? error.message : 'Export PDF impossible';
      render();
    }
  }

  function renderHeader() {
    return `
      <header class="leave-app-header">
        <div class="leave-header-main">
          <h1>Congés &amp; Absences</h1>
          ${renderViewTabs()}
        </div>
        <div class="leave-header-icons" aria-label="Outils congés">
          <button type="button" class="leave-round-icon" data-filter-focus aria-label="Rechercher">⌕</button>
          <button type="button" class="leave-round-icon" aria-label="Réglages">⚙</button>
          <button type="button" class="leave-round-icon" aria-label="Notifications">◌</button>
        </div>
      </header>
    `;
  }

  function icon(name) {
    const icons = {
      users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
      calendar: '<path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/>',
      check: '<path d="M20 6 9 17l-5-5"/>',
      clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
      alert: '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
      today: '<path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/><path d="M8 15h.01"/><path d="M12 15h.01"/><path d="M16 15h.01"/>',
    };

    return `<svg class="leave-summary-icon-svg" viewBox="0 0 24 24" aria-hidden="true">${icons[name] || icons.calendar}</svg>`;
  }

  function reportLeavesForDate(date, leaves) {
    return leaves
      .filter((leave) => leave.status !== 'refused' && leave.startDate <= date && leave.endDate >= date)
      .sort(
        (a, b) =>
          employeeSortIndex(a.employeeId) - employeeSortIndex(b.employeeId) ||
          a.employeeName.localeCompare(b.employeeName) ||
          a.startDate.localeCompare(b.startDate),
      );
  }

  function exportMonthDays() {
    const days = [];
    const current = new Date(state.month.getFullYear(), state.month.getMonth(), 1);
    const last = new Date(state.month.getFullYear(), state.month.getMonth() + 1, 0);

    while (current <= last) {
      days.push(new Date(current));
      current.setDate(current.getDate() + 1);
    }

    return days;
  }

  function exportEmployees(leaves) {
    const list = employees();
    if (state.filters.employeeId !== 'all') {
      return list.filter((employee) => Number(employee.id) === Number(state.filters.employeeId));
    }

    const query = state.filters.query.trim().toLowerCase();
    if (!query) return list;

    const visibleEmployeeIds = new Set(leaves.map((leave) => Number(leave.employeeId)));
    return list.filter(
      (employee) =>
        visibleEmployeeIds.has(Number(employee.id)) ||
        String(employee.name || '')
          .toLowerCase()
          .includes(query),
    );
  }

  function shortWeekday(date) {
    return date.toLocaleDateString('fr-FR', { weekday: 'short' }).replace('.', '');
  }

  function isWeekend(date) {
    return date.getDay() === 0 || date.getDay() === 6;
  }

  function weekdayLetter(date) {
    return ['D', 'L', 'M', 'M', 'J', 'V', 'S'][date.getDay()] || '';
  }

  function isoWeekNumber(date) {
    const current = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const dayNumber = current.getUTCDay() || 7;
    current.setUTCDate(current.getUTCDate() + 4 - dayNumber);
    const yearStart = new Date(Date.UTC(current.getUTCFullYear(), 0, 1));

    return Math.ceil(((current - yearStart) / 86400000 + 1) / 7);
  }

  function wallMonthTitle(date) {
    const months = [
      'JANVIER',
      'FEVRIER',
      'MARS',
      'AVRIL',
      'MAI',
      'JUIN',
      'JUILLET',
      'AOUT',
      'SEPTEMBRE',
      'OCTOBRE',
      'NOVEMBRE',
      'DECEMBRE',
    ];

    return `${months[date.getMonth()] || 'MOIS'} ${date.getFullYear()}`;
  }

  function wallEmployeeLabel(name) {
    const firstName = String(name || '')
      .trim()
      .split(/\s+/)[0];

    return (firstName || '').toLocaleUpperCase('fr-FR');
  }

  function isZoneA(date) {
    const value = formatDate(date);
    const ranges = [
      ['2026-02-07', '2026-02-23'],
      ['2026-04-04', '2026-04-20'],
      ['2026-05-14', '2026-05-17'],
      ['2026-07-04', '2026-08-31'],
      ['2026-12-19', '2027-01-04'],
    ];

    return ranges.some(([from, to]) => value >= from && value <= to);
  }

  function wallWeekendClass(date) {
    if (date.getDay() === 6) return 'is-saturday';
    if (date.getDay() === 0) return 'is-sunday';
    return '';
  }

  function teamDayClass(day, index) {
    return [
      isWeekend(day) ? 'is-weekend' : '',
      !isWeekend(day) && index % 2 === 1 ? 'is-alternate' : '',
    ]
      .filter(Boolean)
      .join(' ');
  }

  function wallLeaveColor(leave) {
    return (
      {
        conge: '#ffff00',
        rtt: '#7dd3fc',
        absence: '#fb7185',
        formation: '#c4b5fd',
        maladie: '#cbd5e1',
      }[leave?.type] || normalizeColor(typeMeta(leave?.type).color, '#ffff00')
    );
  }

  function leaveTypeCode(type) {
    return (
      {
        conge: 'CP',
        rtt: 'RTT',
        absence: 'ABS',
        formation: 'FOR',
        maladie: 'MAL',
      }[type] ||
      String(type || '')
        .slice(0, 3)
        .toUpperCase() ||
      'ABS'
    );
  }

  function periodShortLabel(period) {
    return (
      {
        morning: 'M',
        afternoon: 'AM',
      }[period] || ''
    );
  }

  function employeeMonthTotal(employee, leaves) {
    const { first, last } = monthBounds();
    return leaves
      .filter((leave) => leave.status !== 'refused' && Number(leave.employeeId) === Number(employee.id))
      .reduce((sum, leave) => sum + overlapDays(leave, first, last), 0);
  }

  function exportCellLeaves(employee, date, leaves) {
    return reportLeavesForDate(date, leaves).filter((leave) => Number(leave.employeeId) === Number(employee.id));
  }

  function renderExportPlanning(leaves) {
    const days = exportMonthDays();
    const rows = exportEmployees(leaves);

    if (!rows.length) {
      return '<div class="pdf-empty">Aucun utilisateur a exporter pour ce mois avec les filtres actuels.</div>';
    }

    return `
      <section class="pdf-sheet">
        <div class="pdf-month-band">${esc(monthLabel(state.month))}</div>
        <table class="pdf-planning-table" style="--day-count:${days.length}">
          <colgroup>
            <col class="pdf-col-employee">
            <col class="pdf-col-total">
            ${days.map(() => '<col class="pdf-col-day">').join('')}
          </colgroup>
          <thead>
            <tr>
              <th class="pdf-employee-head" rowspan="2">Utilisateur</th>
              <th class="pdf-total-head" rowspan="2">Total</th>
              ${days.map((day) => `<th class="pdf-weekday-head ${isWeekend(day) ? 'is-weekend' : ''}">${esc(shortWeekday(day))}</th>`).join('')}
            </tr>
            <tr>
              ${days.map((day) => `<th class="pdf-day-head ${isWeekend(day) ? 'is-weekend' : ''}">${day.getDate()}</th>`).join('')}
            </tr>
          </thead>
          <tbody>
            ${rows
              .map((employee) => {
                const total = employeeMonthTotal(employee, leaves);
                return `
                <tr>
                  <th class="pdf-employee-cell">${esc(employee.name)}</th>
                  <td class="pdf-total-cell">${total ? esc(formatDaysCount(total)) : ''}</td>
                  ${days
                    .map((day) => {
                      const date = formatDate(day);
                      const cellLeaves = exportCellLeaves(employee, date, leaves);
                      const visible = cellLeaves.slice(0, 2);
                      const more = cellLeaves.length - visible.length;

                      return `
                      <td class="pdf-date-cell ${isWeekend(day) ? 'is-weekend' : ''} ${cellLeaves.length ? 'has-leave' : ''}">
                        ${visible
                          .map((leave) => {
                            const meta = typeMeta(leave.type);
                            const color = normalizeColor(meta.color || '#38bdf8');
                            const period = periodShortLabel(leave.period);
                            return `
                            <span class="pdf-absence-chip ${leave.status === 'pending' ? 'is-pending' : ''}" style="--absence-color:${esc(color)}">
                              <b>${esc(leaveTypeCode(leave.type))}</b>${period ? `<small>${esc(period)}</small>` : ''}
                            </span>
                          `;
                          })
                          .join('')}
                        ${more > 0 ? `<span class="pdf-more">+${more}</span>` : ''}
                      </td>
                    `;
                    })
                    .join('')}
                </tr>
              `;
              })
              .join('')}
          </tbody>
        </table>
      </section>
    `;
  }

  function renderExportLegend(leaves) {
    const activeTypes = new Set(leaves.filter((leave) => leave.status !== 'refused').map((leave) => leave.type));
    const types = (state.data?.types || []).filter((type) => activeTypes.has(type.value) || !activeTypes.size);

    return `
      <section class="pdf-legend">
        <strong>Légende</strong>
        ${types
          .map((type) => {
            const color = normalizeColor(type.color, '#38bdf8');
            return `
            <span>
              <i style="background:${esc(color)}"></i>
              <b>${esc(leaveTypeCode(type.value))}</b>
              ${esc(type.label)}
            </span>
          `;
          })
          .join('')}
        <span><em class="pdf-pending-mark"></em>En attente de validation</span>
      </section>
    `;
  }

  function wallViewportWidth() {
    const rootWidth = root?.getBoundingClientRect?.().width || 0;
    return Math.max(320, Math.round(rootWidth || window.innerWidth || 1024));
  }

  function wallDayCount() {
    if (state.wallMode === 'day') return 1;
    if (state.wallMode === 'week') return 7;

    const width = wallViewportWidth();

    if (width < 430) return 12;
    if (width < 640) return 16;
    if (width < 900) return 24;
    if (width < 1180) return 35;
    if (width < 1500) return 45;

    return 60;
  }

  function wallModeStartDate(mode = state.wallMode, date = parseDate(state.wallStartDate)) {
    if (mode === 'week') return weekStart(date);

    return date;
  }

  function setWallMode(mode) {
    const nextMode = ['day', 'week', 'month'].includes(mode) ? mode : 'month';
    state.wallMode = nextMode;
    state.wallStartDate = formatDate(wallModeStartDate(nextMode));
    render();
  }

  function moveWallPeriod(direction) {
    const start = parseDate(state.wallStartDate);
    const steps = {
      day: 1,
      week: 7,
      month: wallDayCount(),
    };

    state.wallStartDate = formatDate(
      wallModeStartDate(state.wallMode, addDays(start, direction * (steps[state.wallMode] || wallDayCount()))),
    );
    render();
  }

  function wallVisibleDays() {
    const days = [];
    const current = wallModeStartDate();
    const count = wallDayCount();

    for (let index = 0; index < count; index += 1) {
      days.push(addDays(current, index));
    }

    return days;
  }

  function wallPeriodBounds() {
    const days = wallVisibleDays();
    return {
      first: formatDate(days[0]),
      last: formatDate(days[days.length - 1]),
      count: days.length,
    };
  }

  function wallMonthGroups(days) {
    const groups = [];

    days.forEach((day) => {
      const lastGroup = groups[groups.length - 1];
      if (!lastGroup || lastGroup.month.getMonth() !== day.getMonth() || lastGroup.month.getFullYear() !== day.getFullYear()) {
        groups.push({
          month: new Date(day.getFullYear(), day.getMonth(), 1),
          days: [],
        });
      }

      groups[groups.length - 1].days.push(day);
    });

    return groups;
  }

  function wallLeavesForDate(employee, date, leaves) {
    const value = formatDate(date);

    return leaves
      .filter((leave) => leave.status !== 'refused')
      .filter((leave) => Number(leave.employeeId) === Number(employee.id))
      .filter((leave) => leave.startDate <= value && leave.endDate >= value)
      .sort((a, b) => a.startDate.localeCompare(b.startDate) || a.endDate.localeCompare(b.endDate));
  }

  function teamRangeClass(leave, day) {
    if (!leave) return '';

    const previous = formatDate(addDays(day, -1));
    const next = formatDate(addDays(day, 1));
    const hasBefore = leave.startDate <= previous;
    const hasAfter = leave.endDate >= next;

    if (!hasBefore && !hasAfter) return 'is-range-single';
    if (!hasBefore) return 'is-range-start';
    if (!hasAfter) return 'is-range-end';

    return 'is-range-middle';
  }

  function renderWallMonth(month, days, leaves) {
    const rows = exportEmployees(leaves);

    return `
      <section class="leave-wall-month">
        <table class="leave-wall-table" style="--day-count:${days.length}">
          <colgroup>
            <col class="leave-wall-col-user">
            ${days.map(() => '<col class="leave-wall-col-day">').join('')}
          </colgroup>
          <thead>
            <tr>
              <th class="leave-wall-month-title" colspan="${days.length + 1}">${esc(wallMonthTitle(month))}</th>
            </tr>
            <tr>
              <th class="leave-wall-label">zone A</th>
              ${days.map((day) => `<th class="leave-wall-zone ${isZoneA(day) ? 'is-zone-a' : ''}"></th>`).join('')}
            </tr>
            <tr>
              <th class="leave-wall-label">semaine</th>
              ${days.map((day, index) => `<th class="leave-wall-week">${day.getDay() === 1 || index === 0 ? esc(isoWeekNumber(day)) : ''}</th>`).join('')}
            </tr>
            <tr>
              <th class="leave-wall-label"></th>
              ${days.map((day) => `<th class="leave-wall-weekday ${wallWeekendClass(day)}">${esc(weekdayLetter(day))}</th>`).join('')}
            </tr>
            <tr>
              <th class="leave-wall-label"></th>
              ${days.map((day) => `<th class="leave-wall-day-number ${wallWeekendClass(day)}">${day.getDate()}</th>`).join('')}
            </tr>
          </thead>
          <tbody>
            ${
              rows.length
                ? rows
                    .map(
                      (employee) => `
                        <tr>
                          <th class="leave-wall-user">${esc(wallEmployeeLabel(employee.name))}</th>
                          ${days
                            .map((day) => {
                              const leavesForDay = wallLeavesForDate(employee, day, leaves);
                              const primary = leavesForDay[0];
                              const period = primary?.period || 'full';
                              const actionAttr = primary?.id ? `data-edit-leave="${esc(primary.id)}"` : '';
                              const title = primary
                                ? `${employee.name} - ${typeMeta(primary.type).label} - ${dateLabel(formatDate(day))} - ${statusLabel(primary.status)}`
                                : `${employee.name} - ${dateLabel(formatDate(day))}`;

                              return `
                                <td class="leave-wall-cell ${wallWeekendClass(day)} ${primary ? 'has-leave' : ''}" ${actionAttr} title="${esc(title)}">
                                  ${
                                    primary
                                      ? `<span class="leave-wall-fill is-${esc(period)} ${primary.status === 'pending' ? 'is-pending' : ''}" style="--wall-color:${esc(wallLeaveColor(primary))}"></span>`
                                      : ''
                                  }
                                  ${leavesForDay.length > 1 ? '<span class="leave-wall-more">+</span>' : ''}
                                </td>
                              `;
                            })
                            .join('')}
                        </tr>
                      `,
                    )
                    .join('')
                : `
                  <tr>
                    <th class="leave-wall-user">AUCUN</th>
                    ${days.map((day) => `<td class="leave-wall-cell ${wallWeekendClass(day)}"></td>`).join('')}
                  </tr>
                `
            }
          </tbody>
        </table>
      </section>
    `;
  }

  function renderWallPlanning(leaves) {
    const days = wallVisibleDays();

    return `
      <div class="leave-wall-sheet" style="--wall-days:${days.length}">
        ${wallMonthGroups(days)
          .map((group) => renderWallMonth(group.month, group.days, leaves))
          .join('')}
      </div>
    `;
  }

  function exportDocumentHtml() {
    const leaves = monthReportLeaves();
    const title = `Congés & Absences - ${activeSiteName()} - ${monthLabel(state.month)}`;
    const generatedAt = new Date().toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
    const days = exportMonthDays();
    const period = days.length
      ? `${dateLabel(formatDate(days[0]))} au ${dateLabel(formatDate(days[days.length - 1]))}`
      : monthLabel(state.month);

    return `<!doctype html>
      <html lang="fr">
        <head>
          <meta charset="utf-8">
          <title>${esc(title)}</title>
          <style>
            @page { size:A4 landscape; margin:8mm; }
            * { box-sizing:border-box; }
            body { margin:0; background:#fff; color:#172033; font-family:Arial, Helvetica, sans-serif; font-size:9px; }
            .pdf-page { display:grid; gap:7px; }
            .pdf-top { display:grid; grid-template-columns:42mm minmax(0,1fr) 45mm; align-items:center; gap:8mm; }
            .pdf-brand { display:flex; align-items:center; min-height:16mm; }
            .pdf-logo { max-width:35mm; max-height:14mm; object-fit:contain; }
            .pdf-brand-fallback { display:block; color:#95002e; font-size:15px; font-weight:950; line-height:1; text-transform:uppercase; }
            h1 { margin:0; color:#1f2937; font-family:Georgia, 'Times New Roman', serif; font-size:25px; font-weight:800; line-height:1.05; text-align:left; }
            .pdf-meta { text-align:right; color:#64748b; font-size:8px; font-weight:700; line-height:1.35; }
            .pdf-period { margin:0; color:#254236; font-size:10px; font-weight:800; text-align:center; }
            .pdf-period strong { color:#16695c; }
            .pdf-sheet { overflow:hidden; border:1px solid #dfe7e1; border-radius:5px; background:#fff; }
            .pdf-month-band { margin-left:58mm; background:#16695c; color:#fff; padding:4px 6px; font-size:11px; font-weight:900; text-align:center; text-transform:lowercase; }
            .pdf-planning-table { width:100%; table-layout:fixed; border-collapse:collapse; }
            .pdf-col-employee { width:44mm; }
            .pdf-col-total { width:14mm; }
            .pdf-col-day { width:calc((100% - 58mm) / var(--day-count)); }
            .pdf-planning-table th,
            .pdf-planning-table td { border:1px solid #e6ebe7; padding:0; text-align:center; vertical-align:middle; }
            .pdf-employee-head { background:#fff8ed; color:#254236; font-size:8px; font-weight:950; text-align:left; text-transform:uppercase; }
            .pdf-total-head { background:#eaf2ef; color:#254236; font-size:8px; font-weight:950; text-transform:uppercase; }
            .pdf-weekday-head { height:13px; background:#f8fafc; color:#64748b; font-size:6.4px; font-weight:900; text-transform:lowercase; }
            .pdf-day-head { height:15px; background:#fff; color:#172033; font-size:8px; font-weight:900; }
            .pdf-weekday-head.is-weekend,
            .pdf-day-head.is-weekend,
            .pdf-date-cell.is-weekend { background:#f6f7f8; color:#94a3b8; }
            .pdf-employee-cell { height:19px; background:#fff8ed; color:#254236; padding:2px 5px!important; font-size:8.5px; font-weight:850; text-align:left!important; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .pdf-total-cell { background:#f0f6f3; color:#254236; font-size:10px; font-weight:950; }
            .pdf-date-cell { height:19px; background:#fff; padding:1px!important; }
            .pdf-date-cell.has-leave { background:#fffdf8; }
            .pdf-absence-chip { display:flex; min-height:13px; align-items:center; justify-content:center; gap:1px; border:1px solid var(--absence-color); border-radius:2px; background:var(--absence-color); color:#172033; font-size:6.8px; font-weight:950; line-height:1; }
            .pdf-absence-chip + .pdf-absence-chip { margin-top:1px; }
            .pdf-absence-chip small { font-size:5.5px; font-weight:950; opacity:.85; }
            .pdf-absence-chip.is-pending { border-style:dashed; background:#fff; }
            .pdf-more { display:block; color:#64748b; font-size:6px; font-weight:900; line-height:1; }
            .pdf-legend { display:flex; flex-wrap:wrap; align-items:center; gap:4px 10px; color:#334155; font-size:8px; font-weight:800; }
            .pdf-legend strong { margin-right:2px; color:#172033; font-size:8px; text-transform:uppercase; }
            .pdf-legend span { display:inline-flex; align-items:center; gap:4px; }
            .pdf-legend i { width:8px; height:8px; flex:0 0 auto; border-radius:2px; border:1px solid rgba(15,23,42,.08); }
            .pdf-legend b { color:#172033; }
            .pdf-pending-mark { width:11px; height:8px; border:1px dashed #64748b; border-radius:2px; background:#fff; }
            .pdf-empty { border:1px dashed #cbd5e1; border-radius:5px; padding:16px; color:#64748b; font-weight:800; text-align:center; }
            @media print {
              .pdf-sheet { break-inside:avoid; }
            }
            @supports (background:color-mix(in srgb, red 50%, white)) {
              .pdf-absence-chip { background:color-mix(in srgb, var(--absence-color) 34%, white); border-color:color-mix(in srgb, var(--absence-color) 65%, white); }
            }
          </style>
        </head>
        <body>
          <main class="pdf-page">
            <header class="pdf-top">
              <div class="pdf-brand">
                <img class="pdf-logo" src="/assets/logo/martin-sols-logo.png" alt="Martin Sols" onload="this.nextElementSibling.style.display='none';" onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
                <span class="pdf-brand-fallback">Martin Sols</span>
              </div>
              <h1>Tableau des congés et absences</h1>
              <div class="pdf-meta">Export PDF<br>${esc(generatedAt)}</div>
            </header>
            <p class="pdf-period"><strong>Période :</strong> ${esc(period)} - ${esc(activeSiteName())}</p>
            ${renderExportPlanning(leaves)}
            ${renderExportLegend(leaves)}
          </main>
        </body>
      </html>`;
  }

  function exportPdf() {
    openExportModal();
  }

  function leaveViews() {
    const views = [{ key: 'calendar', label: 'Mon calendrier' }];

    if (canViewTeam()) {
      views.push({ key: 'team', label: 'Mon équipe' });
    }

    if (canViewBalances()) {
      views.push({ key: 'balances', label: 'Soldes' });
    }

    if (canViewRequests()) {
      views.push({ key: 'requests', label: 'Demandes' });
    }

    if (canViewReports()) {
      views.push({ key: 'reports', label: 'Rapports' });
    }

    if (canManageSettings()) {
      views.push({ key: 'settings', label: 'Gestion' });
    }

    return views;
  }

  function allowedViewKeys() {
    return leaveViews().map((view) => view.key);
  }

  function ensureAllowedView() {
    if (!allowedViewKeys().includes(state.view)) {
      state.view = 'calendar';
    }
  }

  function requestList() {
    return filteredLeaves()
      .slice()
      .sort(
        (a, b) =>
          b.startDate.localeCompare(a.startDate) ||
          employeeSortIndex(a.employeeId) - employeeSortIndex(b.employeeId) ||
          a.employeeName.localeCompare(b.employeeName),
      );
  }

  function yearApprovedDaysForEmployee(employeeId) {
    return yearLeaves()
      .filter((leave) => leave.status === 'approved' && Number(leave.employeeId) === Number(employeeId))
      .reduce((sum, leave) => sum + daysCount(leave), 0);
  }

  function yearPendingDaysForEmployee(employeeId) {
    return yearLeaves()
      .filter((leave) => leave.status === 'pending' && Number(leave.employeeId) === Number(employeeId))
      .reduce((sum, leave) => sum + daysCount(leave), 0);
  }

  function currentScopeEmployee() {
    return selectedEmployee() || currentEmployee() || employees()[0] || null;
  }

  function initials(value) {
    return String(value || 'MS')
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part.charAt(0))
      .join('')
      .toLocaleUpperCase('fr-FR');
  }

  function yearPeriodLabel() {
    const year = state.month.getFullYear();
    return `1er janv. ${year} - 31 dec. ${year}`;
  }

  function yearlyLeavesForEmployee(employeeId = null) {
    const first = `${state.month.getFullYear()}-01-01`;
    const last = `${state.month.getFullYear()}-12-31`;

    return filteredLeaves()
      .filter((leave) => leave.status !== 'refused' && leave.startDate <= last && leave.endDate >= first)
      .filter((leave) => !employeeId || Number(leave.employeeId) === Number(employeeId));
  }

  function yearlyStats() {
    const employee = currentScopeEmployee();
    const leaves = yearlyLeavesForEmployee(employee?.id || null);
    const approved = leaves.filter((leave) => leave.status === 'approved').reduce((sum, leave) => sum + daysCount(leave), 0);
    const pending = leaves.filter((leave) => leave.status === 'pending').reduce((sum, leave) => sum + daysCount(leave), 0);
    const total = approved + pending;
    const available = Math.max(0, 25 - total);

    return { approved, pending, total, available };
  }

  function typeTotal(typeValue, employeeId = null) {
    return yearlyLeavesForEmployee(employeeId)
      .filter((leave) => leave.type === typeValue)
      .reduce((sum, leave) => sum + daysCount(leave), 0);
  }

  function monthGridDays(monthDate) {
    const days = calendarDays(monthDate);
    while (days.length < 42) {
      days.push(addDays(days[days.length - 1], 1));
    }

    return days.slice(0, 42);
  }

  function leavesForMiniDay(day) {
    const date = formatDate(day);
    const employee = currentScopeEmployee();

    return activeLeaves()
      .filter((leave) => leave.startDate <= date && leave.endDate >= date)
      .filter((leave) => !employee || Number(leave.employeeId) === Number(employee.id));
  }

  function renderTopMetrics() {
    const stats = yearlyStats();
    const cards = [
      { label: 'Disponibles', value: stats.available, icon: 'check', tone: '#16a34a' },
      { label: 'Total', value: stats.total, icon: 'calendar', tone: '#2563eb' },
      { label: 'Utilisés', value: stats.approved, icon: 'users', tone: 'rgb(var(--theme-primary,149 0 46))' },
      { label: 'En attente', value: stats.pending, icon: 'clock', tone: '#f59e0b' },
    ];

    return `
      <div class="leave-stat-strip" aria-label="Soldes congés">
        ${cards
          .map(
            (card) => `
              <article class="leave-stat-card" style="--leave-stat-color:${esc(card.tone)}">
                <span class="leave-stat-icon" aria-hidden="true">${icon(card.icon)}</span>
                <div class="leave-stat-copy">
                  <small>${esc(card.label)}</small>
                  <strong>${esc(formatDaysCount(card.value))}j</strong>
                </div>
              </article>
            `,
          )
          .join('')}
      </div>
    `;
  }

  function renderViewTabs() {
    return `
      <nav class="leave-view-tabs" aria-label="Navigation congés">
        ${leaveViews()
          .map(
            (view) => `
              <button type="button" class="leave-view-tab ${state.view === view.key ? 'is-active' : ''}" data-view="${esc(view.key)}">
                ${esc(view.label)}
              </button>
            `,
          )
          .join('')}
      </nav>
    `;
  }

  function renderFilters() {
    return `
      <section class="leave-filter-card leave-app-filters" aria-label="Filtres congés">
        <div class="leave-search-field">
          <span aria-hidden="true">⌕</span>
          <input type="search" data-filter-query value="${esc(state.filters.query)}" placeholder="Rechercher un membre, motif, statut...">
        </div>
        <label>
          <span>Membre</span>
          <select data-filter-employee>
            <option value="all" ${state.filters.employeeId === 'all' ? 'selected' : ''}>Tous les membres</option>
            ${employees()
              .map(
                (employee) =>
                  `<option value="${esc(employee.id)}" ${Number(state.filters.employeeId) === Number(employee.id) ? 'selected' : ''}>${esc(employee.name)}</option>`,
              )
              .join('')}
          </select>
        </label>
        <label>
          <span>Type</span>
          <select data-filter-type>
            <option value="all" ${state.filters.type === 'all' ? 'selected' : ''}>Tous les types</option>
            ${(state.data?.types || [])
              .map(
                (type) =>
                  `<option value="${esc(type.value)}" ${state.filters.type === type.value ? 'selected' : ''}>${esc(type.label)}</option>`,
              )
              .join('')}
          </select>
        </label>
        <label>
          <span>Statut</span>
          <select data-filter-status>
            <option value="active" ${state.filters.status === 'active' ? 'selected' : ''}>Actifs seulement</option>
            <option value="all" ${state.filters.status === 'all' ? 'selected' : ''}>Tous les statuts</option>
            <option value="pending" ${state.filters.status === 'pending' ? 'selected' : ''}>À valider</option>
            <option value="approved" ${state.filters.status === 'approved' ? 'selected' : ''}>Validés</option>
            <option value="planned" ${state.filters.status === 'planned' ? 'selected' : ''}>Planifiés</option>
            <option value="refused" ${state.filters.status === 'refused' ? 'selected' : ''}>Refusés</option>
          </select>
        </label>
        <button type="button" class="leaves-button leave-filter-reset" data-filter-reset>Effacer</button>
      </section>
    `;
  }

  function renderTeamFilters() {
    return `
      <div class="leave-team-filter-strip" aria-label="Filtres du planning équipe">
        <div class="leave-search-field">
          <span aria-hidden="true">⌕</span>
          <input type="search" data-filter-query value="${esc(state.filters.query)}" placeholder="Rechercher un membre, motif, statut...">
        </div>
        <label>
          <span>Membre</span>
          <select data-filter-employee>
            <option value="all" ${state.filters.employeeId === 'all' ? 'selected' : ''}>Tous les membres</option>
            ${employees()
              .map(
                (employee) =>
                  `<option value="${esc(employee.id)}" ${Number(state.filters.employeeId) === Number(employee.id) ? 'selected' : ''}>${esc(employee.name)}</option>`,
              )
              .join('')}
          </select>
        </label>
        <label>
          <span>Type</span>
          <select data-filter-type>
            <option value="all" ${state.filters.type === 'all' ? 'selected' : ''}>Tous les types</option>
            ${(state.data?.types || [])
              .map(
                (type) =>
                  `<option value="${esc(type.value)}" ${state.filters.type === type.value ? 'selected' : ''}>${esc(type.label)}</option>`,
              )
              .join('')}
          </select>
        </label>
        <label>
          <span>Statut</span>
          <select data-filter-status>
            <option value="active" ${state.filters.status === 'active' ? 'selected' : ''}>Actifs seulement</option>
            <option value="all" ${state.filters.status === 'all' ? 'selected' : ''}>Tous les statuts</option>
            <option value="pending" ${state.filters.status === 'pending' ? 'selected' : ''}>À valider</option>
            <option value="approved" ${state.filters.status === 'approved' ? 'selected' : ''}>Validés</option>
            <option value="planned" ${state.filters.status === 'planned' ? 'selected' : ''}>Planifiés</option>
            <option value="refused" ${state.filters.status === 'refused' ? 'selected' : ''}>Refusés</option>
          </select>
        </label>
        <button type="button" class="leaves-button leave-filter-reset" data-filter-reset>Effacer</button>
      </div>
    `;
  }

  function renderBalancePanel() {
    const employee = currentScopeEmployee();
    const year = state.month.getFullYear();
    const used = employee ? yearApprovedDaysForEmployee(employee.id) : yearLeaves().filter((leave) => leave.status === 'approved').reduce((sum, leave) => sum + daysCount(leave), 0);
    const pending = employee ? yearPendingDaysForEmployee(employee.id) : yearLeaves().filter((leave) => leave.status === 'pending').reduce((sum, leave) => sum + daysCount(leave), 0);
    const projected = used + pending;

    return `
      <aside class="leave-side-panel leave-absences-sidebar">
        <section class="leave-profile-card">
          ${employeeAvatar(employee, 'leave-profile-avatar', activeSiteName())}
          <h2>${esc(employee?.name || activeSiteName())}</h2>
          <p>${esc(activeSiteName())} - Equipe</p>
          <div class="leave-balance-grid">
            <span><strong>${esc(formatDaysCount(used))}j</strong><small>valides ${year}</small></span>
            <span><strong>${esc(formatDaysCount(pending))}j</strong><small>en attente</small></span>
            <span><strong>${esc(formatDaysCount(projected))}j</strong><small>previsionnel</small></span>
          </div>
        </section>
        <section class="leave-type-card leave-sidebar-card">
          <h3>Absences par type</h3>
          ${(state.data?.types || [])
            .map((type) => {
              const total = typeTotal(type.value, employee?.id || null);
              return `
                <button type="button" class="leave-type-row" data-type-filter="${esc(type.value)}" style="--type-color:${esc(normalizeColor(type.color, '#38bdf8'))}">
                  <span></span>
                  <strong>${esc(type.label)}</strong>
                  <em>${esc(formatDaysCount(total))} j</em>
                </button>
              `;
            })
            .join('')}
        </section>
      </aside>
    `;
  }

  async function request(action, payload = null) {
    const params = new URLSearchParams({ action });
    const siteId = activeSiteId();
    if (siteId) params.set('siteId', String(siteId));

    const options = { credentials: 'same-origin' };
    if (payload) {
      if (siteId && payload.siteId === undefined && payload.site_id === undefined) {
        payload = { ...payload, siteId: Number(siteId) };
      }
      options.method = 'POST';
      options.headers = { 'Content-Type': 'application/json' };
      options.body = JSON.stringify(payload);
    }
    const response = await fetch(`${api}?${params.toString()}`, options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'API conges indisponible');
    }
    return data;
  }

  async function refreshData() {
    state.data = await request('bootstrap');
    syncEmployeeFilter();
  }

  async function reviewLeave(id, approved) {
    try {
      await request(approved ? 'approve_leave' : 'refuse_leave', { id: Number(id) });
      state.modal = null;
      await refreshData();
      render();
    } catch (error) {
      alert(error instanceof Error ? error.message : 'Action impossible');
    }
  }

  async function load() {
    if (!canRender()) return;

    root.innerHTML = '<div class="leave-card leave-loading">Chargement...</div>';
    styles();
    try {
      const data = await request('bootstrap');
      if (!canRender()) return;

      state.data = data;
      syncEmployeeFilter();
      render();
    } catch (error) {
      if (!canRender()) return;

      root.innerHTML = `<div class="leave-card leave-card-pad"><div class="leaves-notice">${esc(error instanceof Error ? error.message : 'Chargement impossible')}</div></div>`;
      styles();
    }
  }

  function styles() {
    if (!root) return;

    const target = root instanceof ShadowRoot ? root : document.head;
    let style = target.querySelector('#crm-conges-style');
    if (!style) {
      style = document.createElement('style');
      style.id = 'crm-conges-style';
      target.appendChild(style);
    }

    const css = `
      #crm-leaves-module { color:var(--color-secondary-900,#0f172a); }
      #crm-leaves-module .leaves-page { display:grid; gap:1.5rem; }
      #crm-leaves-module .leaves-header { display:flex; flex-direction:column; gap:1rem; }
      #crm-leaves-module .leaves-kicker { color:rgb(var(--theme-primary)); font-size:.72rem; font-weight:800; text-transform:uppercase; }
      #crm-leaves-module .leaves-title { margin:.25rem 0 0; color:var(--color-secondary-900,#0f172a); font-size:2rem; font-weight:900; line-height:1.12; letter-spacing:0; }
      #crm-leaves-module .leaves-subtitle { margin:.35rem 0 0; color:var(--color-secondary-500,#64748b); font-size:.92rem; }
      #crm-leaves-module .leaves-header-actions { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; }
      #crm-leaves-module .leave-hero { position:relative; display:grid; grid-template-columns:minmax(0,1fr) auto; align-items:end; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:1rem; background:linear-gradient(135deg,#fff 0%,#fff7fb 48%,#f8fafc 100%); padding:1.2rem; box-shadow:0 18px 45px rgba(15,23,42,.06); }
      #crm-leaves-module .leave-hero-content { min-width:0; }
      #crm-leaves-module .leave-view-tabs { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.7rem; }
      #crm-leaves-module .leave-view-tab { min-width:0; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.9rem; background:#fff; padding:.8rem .9rem; color:var(--color-secondary-700,#334155); text-align:left; box-shadow:0 10px 24px rgba(15,23,42,.04); }
      #crm-leaves-module .leave-view-tab:hover { border-color:rgb(var(--theme-primary) / .4); background:rgb(var(--theme-primary) / .035); }
      #crm-leaves-module .leave-view-tab.is-active { border-color:rgb(var(--theme-primary)); background:rgb(var(--theme-primary)); color:#fff; box-shadow:0 16px 30px rgb(var(--theme-primary) / .22); }
      #crm-leaves-module .leave-view-tab strong { display:block; overflow:hidden; font-size:.88rem; font-weight:950; line-height:1.1; text-overflow:ellipsis; white-space:nowrap; }
      #crm-leaves-module .leave-view-tab span { display:block; margin-top:.18rem; overflow:hidden; color:inherit; font-size:.7rem; font-weight:750; opacity:.72; text-overflow:ellipsis; white-space:nowrap; }
      #crm-leaves-module .leave-filter-card { display:grid; grid-template-columns:minmax(14rem,1.5fr) repeat(3,minmax(9rem,1fr)) auto; align-items:end; gap:.7rem; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:1rem; background:#fff; padding:.85rem; box-shadow:0 12px 32px rgba(15,23,42,.05); }
      #crm-leaves-module .leave-filter-card label { display:grid; gap:.32rem; min-width:0; }
      #crm-leaves-module .leave-filter-card label span { color:var(--color-secondary-500,#64748b); font-size:.68rem; font-weight:900; text-transform:uppercase; }
      #crm-leaves-module .leave-filter-card select,
      #crm-leaves-module .leave-search-field input { min-height:2.6rem; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.8rem; background:#f8fafc; color:var(--color-secondary-900,#0f172a); padding:.55rem .7rem; font-size:.84rem; font-weight:780; outline:none; }
      #crm-leaves-module .leave-filter-card select:focus,
      #crm-leaves-module .leave-search-field input:focus { border-color:rgb(var(--theme-primary) / .55); background:#fff; box-shadow:0 0 0 .18rem rgb(var(--theme-primary) / .08); }
      #crm-leaves-module .leave-search-field { display:grid; grid-template-columns:1.3rem minmax(0,1fr); align-items:center; gap:.35rem; min-width:0; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.8rem; background:#f8fafc; padding:0 .7rem; }
      #crm-leaves-module .leave-search-field span { color:var(--color-secondary-400,#94a3b8); font-size:1.1rem; font-weight:950; }
      #crm-leaves-module .leave-search-field input { border:0; background:transparent; padding-left:0; box-shadow:none!important; }
      #crm-leaves-module .leave-filter-reset { min-height:2.6rem; border-radius:.8rem; }
      #crm-leaves-module .leave-hr-layout { display:grid; grid-template-columns:minmax(14rem,18rem) minmax(0,1fr); align-items:start; gap:1rem; }
      #crm-leaves-module .leave-side-panel { display:grid; gap:1rem; }
      #crm-leaves-module .leave-balance-card,
      #crm-leaves-module .leave-type-card { border:1px solid var(--color-surface-200,#e2e8f0); border-radius:1rem; background:#fff; padding:1rem; box-shadow:0 14px 34px rgba(15,23,42,.05); }
      #crm-leaves-module .leave-panel-kicker { margin:0 0 .45rem; color:rgb(var(--theme-primary)); font-size:.68rem; font-weight:950; text-transform:uppercase; }
      #crm-leaves-module .leave-balance-card h2 { margin:0; color:var(--color-secondary-900,#0f172a); font-size:1.05rem; font-weight:950; line-height:1.15; letter-spacing:0; }
      #crm-leaves-module .leave-balance-grid { display:grid; gap:.5rem; margin-top:.9rem; }
      #crm-leaves-module .leave-balance-grid span { display:grid; grid-template-columns:auto minmax(0,1fr); align-items:baseline; gap:.5rem; border-radius:.8rem; background:#f8fafc; padding:.65rem .7rem; }
      #crm-leaves-module .leave-balance-grid strong { color:var(--color-secondary-900,#0f172a); font-size:1rem; font-weight:950; }
      #crm-leaves-module .leave-balance-grid small { color:var(--color-secondary-500,#64748b); font-size:.72rem; font-weight:800; }
      #crm-leaves-module .leave-type-card { display:grid; gap:.45rem; }
      #crm-leaves-module .leave-type-row { display:grid; grid-template-columns:.7rem minmax(0,1fr) auto; align-items:center; gap:.5rem; min-height:2.35rem; border:1px solid transparent; border-radius:.75rem; background:#f8fafc; color:var(--color-secondary-800,#1e293b); padding:.5rem .55rem; text-align:left; }
      #crm-leaves-module .leave-type-row:hover { border-color:color-mix(in srgb,var(--type-color,#38bdf8) 50%,#e2e8f0); background:color-mix(in srgb,var(--type-color,#38bdf8) 8%,white); }
      #crm-leaves-module .leave-type-row span { width:.62rem; height:.62rem; border-radius:999px; background:var(--type-color,#38bdf8); }
      #crm-leaves-module .leave-type-row strong { min-width:0; overflow:hidden; font-size:.8rem; font-weight:900; text-overflow:ellipsis; white-space:nowrap; }
      #crm-leaves-module .leave-type-row em { color:var(--color-secondary-500,#64748b); font-size:.72rem; font-style:normal; font-weight:900; white-space:nowrap; }
      #crm-leaves-module .leaves-button { display:inline-flex; min-height:2.35rem; align-items:center; justify-content:center; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.55rem; background:#fff; color:var(--color-secondary-700,#334155); padding:.55rem .75rem; font-size:.84rem; font-weight:800; line-height:1; text-decoration:none; }
      #crm-leaves-module .leaves-button:hover { color:rgb(var(--theme-primary)); border-color:rgb(var(--theme-primary) / .45); background:rgb(var(--theme-primary) / .04); }
      #crm-leaves-module .leaves-button-primary { border-color:rgb(var(--theme-primary)); background:rgb(var(--theme-primary)); color:#fff; }
      #crm-leaves-module .leaves-button-primary:hover { color:#fff; filter:brightness(.97); }
      #crm-leaves-module .leaves-button.is-active { border-color:rgb(var(--theme-primary) / .45); background:rgb(var(--theme-primary) / .08); color:rgb(var(--theme-primary)); }
      #crm-leaves-module .leaves-button-export { gap:.45rem; border-color:rgb(var(--theme-primary)); background:rgb(var(--theme-primary)); color:#fff; box-shadow:0 10px 24px rgb(var(--theme-primary) / .18); }
      #crm-leaves-module .leaves-button-export:hover { color:#fff; background:rgb(var(--theme-primary)); filter:brightness(.97); }
      #crm-leaves-module .leaves-button-export span:first-child { display:grid; place-items:center; width:1.5rem; height:1.5rem; border-radius:.45rem; background:rgba(255,255,255,.18); font-size:.62rem; font-weight:950; }
      #crm-leaves-module .leave-loading { min-height:16rem; display:grid; place-items:center; color:var(--color-secondary-500,#64748b); font-weight:800; }
      #crm-leaves-module .leave-summary { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.8rem; }
      #crm-leaves-module .leave-summary-card { display:grid; grid-template-columns:2.55rem minmax(0,1fr); align-items:center; gap:.75rem; min-width:0; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.75rem; background:#fff; padding:.9rem; }
      #crm-leaves-module .leave-summary-icon { display:grid; place-items:center; width:2.55rem; height:2.55rem; border-radius:.55rem; background:color-mix(in srgb,var(--leave-summary-color,#95002e) 14%,white); color:var(--leave-summary-color,#95002e); }
      #crm-leaves-module .leave-summary-icon-svg { width:1.1rem; height:1.1rem; fill:none; stroke:currentColor; stroke-width:2.15; stroke-linecap:round; stroke-linejoin:round; }
      #crm-leaves-module .leave-summary-card small { display:block; color:var(--color-secondary-500,#64748b); font-size:.73rem; font-weight:900; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
      #crm-leaves-module .leave-summary-card strong { display:block; margin:.18rem 0; color:var(--color-secondary-900,#0f172a); font-size:1.2rem; font-weight:950; line-height:1.08; letter-spacing:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
      #crm-leaves-module .leave-summary-card em { display:block; color:var(--color-secondary-400,#94a3b8); font-size:.72rem; font-style:normal; font-weight:750; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
      #crm-leaves-module .leave-workflow-card { overflow:hidden; }
      #crm-leaves-module .leave-request-list { display:grid; gap:.65rem; padding:1rem; }
      #crm-leaves-module .leave-request-row { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:.6rem; align-items:center; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.8rem; background:#fff; padding:.55rem; }
      #crm-leaves-module .leave-request-row:hover { border-color:color-mix(in srgb,var(--request-color,#95002e) 45%,#e2e8f0); background:color-mix(in srgb,var(--request-color,#95002e) 4%,white); }
      #crm-leaves-module .leave-request-main { display:grid; min-width:0; grid-template-columns:.9rem minmax(0,1fr) auto; align-items:center; gap:.55rem; border:0; background:transparent; color:inherit; padding:.15rem; text-align:left; }
      #crm-leaves-module .leave-request-dot { width:.72rem; height:.72rem; border-radius:999px; background:var(--request-color,#95002e); box-shadow:0 0 0 .22rem color-mix(in srgb,var(--request-color,#95002e) 14%,white); }
      #crm-leaves-module .leave-request-text { display:block; min-width:0; }
      #crm-leaves-module .leave-request-text strong { display:block; overflow:hidden; color:var(--color-secondary-900,#0f172a); font-size:.86rem; font-weight:900; text-overflow:ellipsis; white-space:nowrap; }
      #crm-leaves-module .leave-request-text small { display:block; margin-top:.12rem; overflow:hidden; color:var(--color-secondary-500,#64748b); font-size:.73rem; font-weight:750; text-overflow:ellipsis; white-space:nowrap; }
      #crm-leaves-module .leave-status-pill { display:inline-flex; min-height:1.65rem; align-items:center; justify-content:center; border-radius:999px; background:var(--color-surface-100,#f1f5f9); color:var(--color-secondary-600,#475569); padding:.25rem .55rem; font-size:.7rem; font-weight:900; white-space:nowrap; }
      #crm-leaves-module .leave-status-pill.is-approved { background:#dcfce7; color:#166534; }
      #crm-leaves-module .leave-status-pill.is-pending { background:#fff7ed; color:#c2410c; }
      #crm-leaves-module .leave-status-pill.is-planned { background:#eff6ff; color:#1d4ed8; }
      #crm-leaves-module .leave-status-pill.is-refused { background:#fee2e2; color:#991b1b; }
      #crm-leaves-module .leave-request-actions { display:flex; align-items:center; justify-content:flex-end; gap:.35rem; }
      #crm-leaves-module .leave-icon-action { display:grid; place-items:center; width:2.1rem; height:2.1rem; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.7rem; background:#fff; color:var(--color-secondary-600,#475569); font-size:1rem; font-weight:950; line-height:1; }
      #crm-leaves-module .leave-icon-action.is-approve { border-color:#bbf7d0; background:#f0fdf4; color:#15803d; }
      #crm-leaves-module .leave-icon-action.is-refuse { border-color:#fecaca; background:#fef2f2; color:#be123c; }
      #crm-leaves-module .leave-request-hint { color:var(--color-secondary-400,#94a3b8); font-size:.7rem; font-weight:850; white-space:nowrap; }
      #crm-leaves-module .leave-report-card { overflow:hidden; }
      #crm-leaves-module .leave-report-scroll { overflow:auto; padding:1rem; }
      #crm-leaves-module .pdf-sheet { min-width:56rem; overflow:hidden; border:1px solid #dfe7e1; border-radius:.65rem; background:#fff; }
      #crm-leaves-module .pdf-month-band { margin-left:12rem; background:#16695c; color:#fff; padding:.35rem .5rem; font-size:.78rem; font-weight:950; text-align:center; text-transform:capitalize; }
      #crm-leaves-module .pdf-planning-table { width:100%; table-layout:fixed; border-collapse:collapse; }
      #crm-leaves-module .pdf-col-employee { width:9.5rem; }
      #crm-leaves-module .pdf-col-total { width:3.2rem; }
      #crm-leaves-module .pdf-planning-table th,
      #crm-leaves-module .pdf-planning-table td { border:1px solid #e6ebe7; padding:0; text-align:center; vertical-align:middle; }
      #crm-leaves-module .pdf-employee-head,
      #crm-leaves-module .pdf-total-head { background:#fff8ed; color:#254236; font-size:.62rem; font-weight:950; text-transform:uppercase; }
      #crm-leaves-module .pdf-weekday-head { height:1.35rem; background:#f8fafc; color:#64748b; font-size:.58rem; font-weight:900; text-transform:lowercase; }
      #crm-leaves-module .pdf-day-head { height:1.5rem; background:#fff; color:#172033; font-size:.7rem; font-weight:900; }
      #crm-leaves-module .pdf-weekday-head.is-weekend,
      #crm-leaves-module .pdf-day-head.is-weekend,
      #crm-leaves-module .pdf-date-cell.is-weekend { background:#f6f7f8; color:#94a3b8; }
      #crm-leaves-module .pdf-employee-cell { height:2rem; background:#fff8ed; color:#254236; padding:.25rem .45rem!important; font-size:.72rem; font-weight:850; text-align:left!important; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
      #crm-leaves-module .pdf-total-cell { background:#f0f6f3; color:#254236; font-size:.78rem; font-weight:950; }
      #crm-leaves-module .pdf-date-cell { height:2rem; background:#fff; padding:.12rem!important; }
      #crm-leaves-module .pdf-date-cell.has-leave { background:#fffdf8; }
      #crm-leaves-module .pdf-absence-chip { display:flex; min-height:1.05rem; align-items:center; justify-content:center; gap:.08rem; border:1px solid var(--absence-color); border-radius:.25rem; background:color-mix(in srgb,var(--absence-color) 34%,white); color:#172033; font-size:.55rem; font-weight:950; line-height:1; }
      #crm-leaves-module .pdf-absence-chip + .pdf-absence-chip { margin-top:.08rem; }
      #crm-leaves-module .pdf-absence-chip small { font-size:.48rem; font-weight:950; opacity:.85; }
      #crm-leaves-module .pdf-absence-chip.is-pending { border-style:dashed; background:#fff; }
      #crm-leaves-module .pdf-more { display:block; color:#64748b; font-size:.52rem; font-weight:900; line-height:1; }
      #crm-leaves-module .pdf-legend { display:flex; flex-wrap:wrap; align-items:center; gap:.35rem .7rem; border-top:1px solid var(--color-surface-200,#e2e8f0); padding:.8rem 1rem; color:#334155; font-size:.74rem; font-weight:800; }
      #crm-leaves-module .pdf-legend strong { margin-right:.1rem; color:#172033; font-size:.72rem; text-transform:uppercase; }
      #crm-leaves-module .pdf-legend span { display:inline-flex; align-items:center; gap:.32rem; }
      #crm-leaves-module .pdf-legend i { width:.6rem; height:.6rem; flex:0 0 auto; border-radius:.2rem; border:1px solid rgba(15,23,42,.08); }
      #crm-leaves-module .pdf-pending-mark { width:.75rem; height:.55rem; border:1px dashed #64748b; border-radius:.16rem; background:#fff; }
      #crm-leaves-module .leave-wall-card { background:#fff; }
      #crm-leaves-module .leave-wall-actions { display:flex; flex-wrap:wrap; align-items:center; justify-content:flex-end; gap:.45rem; }
      #crm-leaves-module .leave-wall-scroll { background:#fff; padding:.95rem; }
      #crm-leaves-module .leave-wall-sheet { --wall-user-width:6.8rem; --wall-day-width:1.38rem; display:grid; min-width:max(100%,calc(var(--wall-user-width) + (var(--wall-days) * var(--wall-day-width)))); gap:.35rem; border:1px solid #111827; background:#fff; padding:.35rem; box-shadow:0 16px 38px rgba(15,23,42,.08); }
      #crm-leaves-module .leave-wall-month { overflow:hidden; background:#fff; }
      #crm-leaves-module .leave-wall-table { width:100%; table-layout:fixed; border-collapse:collapse; color:#111827; font-family:Arial, Helvetica, sans-serif; }
      #crm-leaves-module .leave-wall-col-user { width:var(--wall-user-width); }
      #crm-leaves-module .leave-wall-col-day { width:calc((100% - var(--wall-user-width)) / var(--day-count)); }
      #crm-leaves-module .leave-wall-table th,
      #crm-leaves-module .leave-wall-table td { border:1px solid #111827; padding:0; text-align:center; vertical-align:middle; }
      #crm-leaves-module .leave-wall-month-title { height:1rem; background:#fff; color:#111827; font-size:.58rem; font-weight:950; line-height:1; text-transform:uppercase; }
      #crm-leaves-module .leave-wall-label { height:.82rem; background:#fff; color:#111827; font-size:.48rem; font-weight:950; line-height:1; text-transform:lowercase; }
      #crm-leaves-module .leave-wall-zone,
      #crm-leaves-module .leave-wall-week,
      #crm-leaves-module .leave-wall-weekday,
      #crm-leaves-module .leave-wall-day-number { height:.82rem; background:#fff; color:#111827; font-size:.48rem; font-weight:800; line-height:1; }
      #crm-leaves-module .leave-wall-zone.is-zone-a { background:#ff0000; }
      #crm-leaves-module .leave-wall-weekday.is-saturday,
      #crm-leaves-module .leave-wall-day-number.is-saturday,
      #crm-leaves-module .leave-wall-cell.is-saturday { background:#e7e6fb; }
      #crm-leaves-module .leave-wall-weekday.is-sunday,
      #crm-leaves-module .leave-wall-day-number.is-sunday,
      #crm-leaves-module .leave-wall-cell.is-sunday { background:#82b7f6; }
      #crm-leaves-module .leave-wall-user { height:.86rem; background:#fff; color:#111827; padding:0 .25rem!important; font-size:.48rem; font-weight:950; line-height:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-transform:uppercase; }
      #crm-leaves-module .leave-wall-cell { position:relative; height:.86rem; background:#fff; }
      #crm-leaves-module .leave-wall-cell.has-leave { cursor:pointer; }
      #crm-leaves-module .leave-wall-cell.has-leave:hover { outline:2px solid rgb(var(--theme-primary)); outline-offset:-2px; }
      #crm-leaves-module .leave-wall-fill { position:absolute; inset:0; background:var(--wall-color,#ffff00); }
      #crm-leaves-module .leave-wall-fill.is-morning { bottom:50%; }
      #crm-leaves-module .leave-wall-fill.is-afternoon { top:50%; }
      #crm-leaves-module .leave-wall-fill.is-pending { background:repeating-linear-gradient(45deg,var(--wall-color,#ffff00) 0 4px,#fff 4px 7px); }
      #crm-leaves-module .leave-wall-more { position:absolute; inset:.05rem .05rem auto auto; z-index:1; display:grid; place-items:center; width:.48rem; height:.48rem; border-radius:999px; background:#111827; color:#fff; font-size:.35rem; font-weight:950; line-height:1; }
      #crm-leaves-module .leave-workspace { display:grid; gap:1.5rem; }
      #crm-leaves-module .leave-card { border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.75rem; background:#fff; overflow:hidden; }
      #crm-leaves-module .leave-card-pad { padding:1rem; }
      #crm-leaves-module .leave-month-nav { display:flex; flex-direction:column; gap:.9rem; border-bottom:1px solid var(--color-surface-200,#e2e8f0); padding:1rem; }
      #crm-leaves-module .leave-month-left { display:flex; align-items:center; gap:.6rem; }
      #crm-leaves-module .leave-month-title { margin:0; color:var(--color-secondary-900,#0f172a); font-size:1.15rem; font-weight:850; line-height:1.15; letter-spacing:0; }
      #crm-leaves-module .leave-month-subtitle { margin:.15rem 0 0; color:var(--color-secondary-500,#64748b); font-size:.76rem; font-weight:700; }
      #crm-leaves-module .leave-nav-button { display:grid; place-items:center; width:2.2rem; height:2.2rem; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.55rem; background:#fff; color:var(--color-secondary-600,#475569); font-size:1.2rem; line-height:1; font-weight:850; }
      #crm-leaves-module .leave-nav-button:hover { border-color:rgb(var(--theme-primary) / .45); color:rgb(var(--theme-primary)); background:rgb(var(--theme-primary) / .04); }
      #crm-leaves-module .leave-weekdays { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); background:var(--color-surface-50,#f8fafc); border-bottom:1px solid var(--color-surface-200,#e2e8f0); }
      #crm-leaves-module .leave-weekdays span { padding:.7rem .45rem; color:var(--color-secondary-500,#64748b); font-size:.72rem; font-weight:850; text-align:center; text-transform:uppercase; }
      #crm-leaves-module .leave-calendar-grid { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:0; background:var(--color-surface-200,#e2e8f0); }
      #crm-leaves-module .leave-date { --line-x-pad:.55rem; display:flex; min-width:0; min-height:7.2rem; flex-direction:column; border:0; background:#fff; box-shadow:inset -1px 0 0 var(--color-surface-200,#e2e8f0), inset 0 -1px 0 var(--color-surface-200,#e2e8f0); color:var(--color-secondary-900,#0f172a); padding:.55rem; text-align:left; transition:background-color .15s ease; }
      #crm-leaves-module .leave-date:hover { background:rgb(var(--theme-primary) / .04); }
      #crm-leaves-module .leave-date.is-selected { background:rgb(var(--theme-primary) / .04); }
      #crm-leaves-module .leave-date.is-other { background:var(--color-surface-50,#f8fafc); color:var(--color-secondary-400,#94a3b8); }
      #crm-leaves-module .leave-date.has-absences:not(.is-selected) { background:linear-gradient(180deg,#fff 0%,var(--color-surface-50,#f8fafc) 100%); }
      #crm-leaves-module .leave-date.is-today .leave-number { background:rgb(var(--theme-primary)); color:#fff; }
      #crm-leaves-module .leave-date.is-sunday:not(.is-other) .leave-number { color:#be123c; }
      #crm-leaves-module .leave-date.is-today.is-sunday .leave-number { color:#fff; }
      #crm-leaves-module .leave-day-head { display:flex; align-items:center; justify-content:space-between; gap:.35rem; }
      #crm-leaves-module .leave-number { display:inline-flex; width:1.8rem; height:1.8rem; align-items:center; justify-content:center; border-radius:999px; font-size:.86rem; font-weight:850; }
      #crm-leaves-module .leave-day-items { display:grid; align-content:start; gap:.18rem; margin-top:.45rem; }
      #crm-leaves-module .leave-line { --line-overlap-left:0rem; --line-overlap-right:0rem; display:block; min-width:0; width:calc(100% + var(--line-overlap-left) + var(--line-overlap-right)); height:.2rem; margin-left:calc(var(--line-overlap-left) * -1); border-radius:999px; background:var(--line-color); box-shadow:0 2px 7px var(--line-shadow); opacity:.95; }
      #crm-leaves-module .leave-line.is-continued-before { --line-overlap-left:var(--line-x-pad); border-top-left-radius:0; border-bottom-left-radius:0; }
      #crm-leaves-module .leave-line.is-continued-after { --line-overlap-right:var(--line-x-pad); border-top-right-radius:0; border-bottom-right-radius:0; }
      #crm-leaves-module .leave-line.is-morning,
      #crm-leaves-module .leave-line.is-afternoon { width:58%; }
      #crm-leaves-module .leave-line.is-afternoon { margin-left:auto; }
      #crm-leaves-module .leave-line.is-planned,
      #crm-leaves-module .leave-line.is-pending { opacity:.72; }
      #crm-leaves-module .leave-lane-spacer { display:block; height:.2rem; }
      #crm-leaves-module .leave-day-card, #crm-leaves-module .leave-users-card { border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.75rem; background:#fff; }
      #crm-leaves-module .leave-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; border-bottom:1px solid var(--color-surface-200,#e2e8f0); padding:1rem; }
      #crm-leaves-module .leave-card-title { margin:0; color:var(--color-secondary-900,#0f172a); font-size:1rem; font-weight:850; line-height:1.2; letter-spacing:0; }
      #crm-leaves-module .leave-card-subtitle { margin:.25rem 0 0; color:var(--color-secondary-500,#64748b); font-size:.8rem; font-weight:650; }
      #crm-leaves-module .leave-add-day { flex:0 0 auto; border:1px solid rgb(var(--theme-primary)); border-radius:.55rem; background:rgb(var(--theme-primary)); color:#fff; padding:.55rem .75rem; font-size:.78rem; font-weight:900; }
      #crm-leaves-module .leave-day-list { display:grid; gap:.55rem; padding:1rem; }
      #crm-leaves-module .leave-day-row { display:grid; grid-template-columns:1rem minmax(0,1fr) auto; align-items:center; gap:.55rem; min-height:2.5rem; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.55rem; background:#fff; padding:.55rem .65rem; color:var(--color-secondary-900,#0f172a); text-align:left; }
      #crm-leaves-module .leave-day-row:hover { border-color:rgb(var(--theme-primary) / .45); background:rgb(var(--theme-primary) / .04); }
      #crm-leaves-module .leave-day-dot { width:.72rem; height:.72rem; border-radius:999px; background:var(--day-color); }
      #crm-leaves-module .leave-day-name { display:block; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:.86rem; font-weight:850; }
      #crm-leaves-module .leave-day-meta { display:block; margin-top:.12rem; color:var(--color-secondary-500,#64748b); font-size:.72rem; font-weight:700; }
      #crm-leaves-module .leave-day-edit { color:rgb(var(--theme-primary)); font-size:.72rem; font-weight:900; }
      #crm-leaves-module .leave-day-empty { border:1px dashed var(--color-surface-300,#cbd5e1); border-radius:.55rem; padding:1rem; color:var(--color-secondary-500,#64748b); font-size:.85rem; font-weight:700; text-align:center; }
      #crm-leaves-module .leave-users-head { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; border-bottom:1px solid var(--color-surface-200,#e2e8f0); padding:1rem; }
      #crm-leaves-module .leave-users-title { margin:0; color:var(--color-secondary-900,#0f172a); font-size:1rem; font-weight:850; line-height:1.2; letter-spacing:0; }
      #crm-leaves-module .leave-users-site { margin:.25rem 0 0; color:var(--color-secondary-500,#64748b); font-size:.8rem; font-weight:650; }
      #crm-leaves-module .leave-users-count { display:inline-flex; align-items:center; border-radius:999px; background:rgb(var(--theme-primary) / .08); color:rgb(var(--theme-primary)); padding:.25rem .55rem; font-size:.72rem; font-weight:900; }
      #crm-leaves-module .leave-users-grid { display:grid; grid-template-columns:1fr; gap:.55rem; padding:1rem; }
      #crm-leaves-module .leave-user-row { display:grid; grid-template-columns:1rem minmax(0,1fr) auto; align-items:center; gap:.5rem; min-height:2.35rem; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.55rem; background:#fff; padding:.48rem .6rem; color:var(--color-secondary-900,#0f172a); text-align:left; }
      #crm-leaves-module .leave-user-row:hover, #crm-leaves-module .leave-user-row.is-active { border-color:rgb(var(--theme-primary) / .45); background:rgb(var(--theme-primary) / .05); }
      #crm-leaves-module .leave-user-dot { width:.72rem; height:.72rem; border-radius:999px; background:var(--user-color); }
      #crm-leaves-module .leave-user-name { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:.84rem; font-weight:850; }
      #crm-leaves-module .leave-user-count { color:var(--color-secondary-500,#64748b); font-size:.8rem; font-weight:850; }
      #crm-leaves-module .leave-requests-table-card { overflow:hidden; }
      #crm-leaves-module .leave-requests-table-wrap { overflow:auto; }
      #crm-leaves-module .leave-requests-table { width:100%; min-width:46rem; border-collapse:collapse; }
      #crm-leaves-module .leave-requests-table th { border-bottom:1px solid var(--color-surface-200,#e2e8f0); background:#f8fafc; color:var(--color-secondary-500,#64748b); padding:.75rem .85rem; font-size:.68rem; font-weight:950; text-align:left; text-transform:uppercase; }
      #crm-leaves-module .leave-requests-table td { border-bottom:1px solid var(--color-surface-200,#e2e8f0); color:var(--color-secondary-700,#334155); padding:.72rem .85rem; font-size:.82rem; font-weight:800; vertical-align:middle; }
      #crm-leaves-module .leave-requests-table tr:hover td { background:rgb(var(--theme-primary) / .025); }
      #crm-leaves-module .leave-table-person { display:inline-grid; grid-template-columns:.75rem minmax(0,1fr); align-items:center; gap:.45rem; min-width:0; }
      #crm-leaves-module .leave-table-person i { width:.68rem; height:.68rem; border-radius:999px; background:var(--person-color,#38bdf8); }
      #crm-leaves-module .leave-table-person strong { min-width:0; overflow:hidden; color:var(--color-secondary-900,#0f172a); font-weight:950; text-overflow:ellipsis; white-space:nowrap; }
      #crm-leaves-module .leaves-button-small { min-height:2rem; border-radius:.65rem; padding:.35rem .6rem; font-size:.74rem; }
      #crm-leaves-module .leave-reporting-card { overflow:hidden; }
      #crm-leaves-module .leave-reporting-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.75rem; padding:1rem; }
      #crm-leaves-module .leave-reporting-grid article { border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.9rem; background:#f8fafc; padding:.9rem; }
      #crm-leaves-module .leave-reporting-grid strong { display:block; color:var(--color-secondary-900,#0f172a); font-size:1.35rem; font-weight:950; line-height:1; }
      #crm-leaves-module .leave-reporting-grid span { display:block; margin-top:.28rem; color:var(--color-secondary-500,#64748b); font-size:.75rem; font-weight:850; }
      #crm-leaves-module .leave-reporting-preview { border-top:1px solid var(--color-surface-200,#e2e8f0); }
      #crm-leaves-module .leaves-modal-backdrop { position:fixed; inset:0; z-index:80; display:flex; align-items:center; justify-content:center; background:rgba(15,23,42,.48); padding:1rem; }
      #crm-leaves-module .leaves-modal { width:min(42rem,100%); max-height:calc(100vh - 2rem); overflow:auto; border-radius:.75rem; background:#fff; padding:1rem; box-shadow:0 24px 80px rgba(15,23,42,.24); }
      #crm-leaves-module .leaves-modal-head { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin-bottom:.85rem; }
      #crm-leaves-module .leaves-modal-head p { margin:.2rem 0 0; color:var(--color-secondary-500,#64748b); font-size:.78rem; font-weight:700; }
      #crm-leaves-module .leaves-export-modal { width:min(40rem,100%); }
      #crm-leaves-module .leaves-form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.75rem; }
      #crm-leaves-module .leaves-field { display:grid; gap:.32rem; }
      #crm-leaves-module .leaves-field-full { grid-column:1/-1; }
      #crm-leaves-module .leaves-check { display:flex; align-items:center; gap:.55rem; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.75rem; background:var(--color-surface-50,#f8fafc); padding:.75rem; color:var(--color-secondary-700,#334155); font-size:.82rem; font-weight:850; }
      #crm-leaves-module .leaves-check input { width:1rem; min-height:1rem; accent-color:rgb(var(--theme-primary)); }
      #crm-leaves-module .leaves-export-members { display:grid; gap:.55rem; }
      #crm-leaves-module .leaves-export-members-head { display:flex; align-items:center; justify-content:space-between; gap:.75rem; }
      #crm-leaves-module .leaves-button-small { min-height:2rem; padding:.4rem .6rem; font-size:.73rem; }
      #crm-leaves-module .leaves-export-member-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.5rem; max-height:18rem; overflow:auto; padding:.1rem; }
      #crm-leaves-module .leaves-export-member { display:grid; grid-template-columns:1rem minmax(0,1fr); align-items:center; gap:.55rem; min-width:0; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.75rem; background:#fff; padding:.65rem; }
      #crm-leaves-module .leaves-export-member input { width:1rem; min-height:1rem; accent-color:rgb(var(--theme-primary)); }
      #crm-leaves-module .leaves-export-member strong { display:block; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--color-secondary-900,#0f172a); font-size:.82rem; font-weight:900; }
      #crm-leaves-module .leaves-export-member small { display:block; margin-top:.12rem; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--color-secondary-500,#64748b); font-size:.68rem; font-weight:750; }
      #crm-leaves-module .leave-person-card { display:flex; min-height:2.8rem; align-items:center; gap:.65rem; border:1px solid var(--color-surface-200,#e2e8f0); border-radius:.75rem; background:var(--color-surface-50,#f8fafc); padding:.7rem .8rem; }
      #crm-leaves-module .leave-person-card strong { min-width:0; flex:1 1 auto; overflow:hidden; color:var(--color-secondary-900,#0f172a); font-size:.88rem; font-weight:900; text-overflow:ellipsis; white-space:nowrap; }
      #crm-leaves-module label { color:#475569; font-size:.76rem; font-weight:800; }
      #crm-leaves-module input, #crm-leaves-module select, #crm-leaves-module textarea { min-height:2.4rem; width:100%; border:1px solid #cbd5e1; border-radius:.55rem; background:#fff; color:#0f172a; padding:.5rem .65rem; font-size:.85rem; }
      #crm-leaves-module input:disabled, #crm-leaves-module select:disabled, #crm-leaves-module textarea:disabled { background:var(--color-surface-50,#f8fafc); color:var(--color-secondary-500,#64748b); }
      #crm-leaves-module textarea { min-height:5.2rem; resize:vertical; }
      #crm-leaves-module .leaves-actions { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; }
      #crm-leaves-module .leaves-button { display:inline-flex; min-height:2.35rem; align-items:center; justify-content:center; border:1px solid #e2e8f0; border-radius:.55rem; background:#fff; color:#334155; padding:.55rem .75rem; font-size:.84rem; font-weight:800; line-height:1; text-decoration:none; }
      #crm-leaves-module .leaves-button:hover { color:rgb(var(--theme-primary)); border-color:rgb(var(--theme-primary) / .45); }
      #crm-leaves-module .leaves-button:disabled { cursor:not-allowed; opacity:.55; }
      #crm-leaves-module .leaves-button-primary { border-color:rgb(var(--theme-primary)); background:rgb(var(--theme-primary)); color:#fff; }
      #crm-leaves-module .leaves-button-primary:hover { color:#fff; filter:brightness(.97); }
      #crm-leaves-module .leaves-button-approve { border-color:#bbf7d0; background:#f0fdf4; color:#15803d; }
      #crm-leaves-module .leaves-button-refuse { border-color:#fecaca; background:#fef2f2; color:#be123c; }
      #crm-leaves-module .leaves-notice { border:1px solid #fecaca; border-radius:.75rem; background:#fef2f2; color:#991b1b; padding:.75rem; font-size:.85rem; }
      .dark #crm-leaves-module .leave-summary-card,
      .dark #crm-leaves-module .leave-card,
      .dark #crm-leaves-module .leave-day-card,
      .dark #crm-leaves-module .leave-users-card,
      .dark #crm-leaves-module .leave-date,
      .dark #crm-leaves-module .leave-day-row,
      .dark #crm-leaves-module .leave-user-row,
      .dark #crm-leaves-module .leaves-modal { background:var(--color-surface-900,#0f172a); border-color:var(--color-surface-700,#334155); }
      .dark #crm-leaves-module .leave-weekdays,
      .dark #crm-leaves-module .leave-date.is-other { background:var(--color-surface-800,#1e293b); }
      .dark #crm-leaves-module .leave-date.has-absences:not(.is-selected) { background:linear-gradient(180deg,var(--color-surface-900,#0f172a) 0%,var(--color-surface-800,#1e293b) 100%); }
      @media (min-width:640px) {
        #crm-leaves-module .leaves-header { flex-direction:row; align-items:flex-start; justify-content:space-between; }
        #crm-leaves-module .leave-summary { grid-template-columns:repeat(4,minmax(0,1fr)); }
        #crm-leaves-module .leave-month-nav { flex-direction:row; align-items:center; justify-content:space-between; }
        #crm-leaves-module .leave-users-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
      }
      @media (min-width:1180px) {
        #crm-leaves-module .leave-workspace { grid-template-columns:minmax(0,1fr); align-items:start; }
      }
      @media (max-width:760px) {
        #crm-leaves-module .leave-date { --line-x-pad:.32rem; min-height:5.35rem; padding:.42rem .32rem; }
        #crm-leaves-module .leave-day-items { gap:.16rem; margin-top:.32rem; }
        #crm-leaves-module .leave-line,
        #crm-leaves-module .leave-lane-spacer { height:.16rem; box-shadow:none; }
        #crm-leaves-module .leaves-form-grid { grid-template-columns:1fr; }
        #crm-leaves-module .leaves-export-member-list { grid-template-columns:1fr; max-height:16rem; }
      }
      #crm-leaves-module .leaves-page { gap:1.15rem; }
      #crm-leaves-module .leave-workspace { display:grid; grid-template-columns:minmax(0,1fr); gap:1.2rem; align-items:start; }
      #crm-leaves-module .leave-main-column { display:grid; min-width:0; gap:1.2rem; }
      #crm-leaves-module .leave-card,
      #crm-leaves-module .leave-day-card,
      #crm-leaves-module .leave-users-card,
      #crm-leaves-module .leave-summary-card {
        border-radius:1.05rem;
        box-shadow:0 16px 42px rgba(15,23,42,.06);
      }
      #crm-leaves-module .leave-calendar { overflow:hidden; }
      #crm-leaves-module .leave-planning-head {
        display:grid;
        grid-template-columns:3.1rem minmax(0,1fr) 3.1rem;
        align-items:center;
        gap:.75rem;
        padding:1.15rem 1.1rem .75rem;
      }
      #crm-leaves-module .leave-planning-title { min-width:0; text-align:center; }
      #crm-leaves-module .leave-month-title {
        color:var(--color-secondary-900,#0f172a);
        font-size:1.55rem;
        font-weight:900;
        line-height:1.1;
        text-align:center;
      }
      #crm-leaves-module .leave-month-subtitle {
        margin:.25rem 0 0;
        color:var(--color-secondary-500,#64748b);
        font-size:.86rem;
        font-weight:700;
        text-align:center;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
      }
      #crm-leaves-module .leave-nav-button {
        width:3.1rem;
        height:3.1rem;
        border-radius:.9rem;
        background:var(--color-surface-50,#f8fafc);
        font-size:1.35rem;
        box-shadow:0 8px 22px rgba(15,23,42,.05);
      }
      #crm-leaves-module .leave-tabs {
        display:grid;
        grid-template-columns:repeat(3,minmax(0,1fr));
        gap:.55rem;
        padding:0 1.1rem 1rem;
      }
      #crm-leaves-module .leave-tab {
        min-width:0;
        min-height:2.8rem;
        border:0;
        border-radius:.9rem;
        background:var(--color-surface-50,#f8fafc);
        color:var(--color-secondary-500,#64748b);
        font-size:.86rem;
        font-weight:900;
      }
      #crm-leaves-module .leave-tab.is-active {
        background:rgb(var(--theme-primary));
        color:#fff;
        box-shadow:0 12px 28px rgb(var(--theme-primary) / .23);
      }
      #crm-leaves-module .leave-weekdays {
        border-top:1px solid var(--color-surface-200,#e2e8f0);
        background:#fff;
      }
      #crm-leaves-module .leave-weekdays span {
        padding:.82rem .35rem;
        font-size:.74rem;
      }
      #crm-leaves-module .leave-calendar-grid { border-radius:0; overflow:hidden; }
      #crm-leaves-module .leave-date {
        --line-x-pad:.48rem;
        min-height:6.75rem;
        padding:.58rem .48rem .5rem;
      }
      #crm-leaves-module .leave-date.is-selected {
        background:#fff;
      }
      #crm-leaves-module .leave-date.is-today .leave-number {
        background:transparent;
        color:inherit;
        box-shadow:none;
      }
      #crm-leaves-module .leave-date.is-selected .leave-number {
        background:rgb(var(--theme-primary));
        color:#fff;
        box-shadow:none;
      }
      #crm-leaves-module .leave-date.is-sunday.is-selected .leave-number {
        color:#fff;
      }
      #crm-leaves-module .leave-date.is-sunday.is-today:not(.is-selected) .leave-number {
        color:#be123c;
      }
      #crm-leaves-module .leave-number {
        width:1.85rem;
        height:1.85rem;
        font-size:.85rem;
      }
      #crm-leaves-module .leave-day-items {
        display:grid;
        align-content:start;
        gap:.18rem;
        margin-top:.4rem;
      }
      #crm-leaves-module .leave-line,
      #crm-leaves-module .leave-lane-spacer { height:.18rem; }
      #crm-leaves-module .leave-legend {
        display:flex;
        flex-wrap:wrap;
        align-items:center;
        gap:.75rem 1rem;
        border-top:1px solid var(--color-surface-200,#e2e8f0);
        padding:1rem 1.15rem;
        color:var(--color-secondary-500,#64748b);
        font-size:.8rem;
        font-weight:850;
      }
      #crm-leaves-module .leave-legend-item {
        display:inline-flex;
        min-width:0;
        align-items:center;
        gap:.45rem;
      }
      #crm-leaves-module .leave-legend-item span:last-child {
        min-width:0;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
      }
      #crm-leaves-module .leave-legend-dot {
        width:.68rem;
        height:.68rem;
        flex:0 0 auto;
        border-radius:999px;
        background:var(--legend-color);
      }
      #crm-leaves-module .leave-legend-more {
        display:inline-flex;
        min-height:1.45rem;
        align-items:center;
        border-radius:999px;
        background:var(--color-surface-100,#f1f5f9);
        padding:.15rem .5rem;
        color:var(--color-secondary-500,#64748b);
      }
      #crm-leaves-module .leave-card-head,
      #crm-leaves-module .leave-users-head {
        padding:1.1rem 1.15rem;
      }
      #crm-leaves-module .leave-card-title,
      #crm-leaves-module .leave-users-title {
        font-size:1.05rem;
      }
      #crm-leaves-module .leave-add-day {
        min-height:2.75rem;
        border-radius:.85rem;
        padding:.65rem .9rem;
        box-shadow:0 12px 28px rgb(var(--theme-primary) / .2);
      }
      #crm-leaves-module .leave-day-list,
      #crm-leaves-module .leave-users-grid {
        padding:1.05rem 1.15rem;
      }
      #crm-leaves-module .leave-day-row,
      #crm-leaves-module .leave-user-row {
        border-radius:.85rem;
        padding:.7rem .75rem;
      }
      #crm-leaves-module .leave-summary {
        gap:.75rem;
      }
      #crm-leaves-module .leave-summary-card {
        padding:.95rem;
      }
      #crm-leaves-module .leave-summary-card strong { font-size:1.25rem; }
      .dark #crm-leaves-module .leave-tab,
      .dark #crm-leaves-module .leave-nav-button,
      .dark #crm-leaves-module .leave-legend-more {
        background:var(--color-surface-800,#1e293b);
      }
      .dark #crm-leaves-module .leave-weekdays {
        background:var(--color-surface-900,#0f172a);
      }
      .dark #crm-leaves-module .leave-date.is-selected {
        background:var(--color-surface-900,#0f172a);
      }
      @media (min-width:1180px) {
        #crm-leaves-module .leave-workspace { gap:1.2rem; }
        #crm-leaves-module .leave-date { min-height:7rem; }
      }
      @media (max-width:1080px) {
        #crm-leaves-module .leave-filter-card { grid-template-columns:repeat(2,minmax(0,1fr)); }
        #crm-leaves-module .leave-search-field,
        #crm-leaves-module .leave-filter-reset { grid-column:span 2; }
        #crm-leaves-module .leave-hr-layout { grid-template-columns:1fr; }
        #crm-leaves-module .leave-side-panel { grid-template-columns:repeat(2,minmax(0,1fr)); }
      }
      @media (max-width:760px) {
        #crm-leaves-module .leaves-page { gap:1rem; }
        #crm-leaves-module .leave-hero { grid-template-columns:1fr; align-items:start; gap:1rem; padding:1rem; border-radius:.9rem; }
        #crm-leaves-module .leave-view-tabs { display:flex; overflow-x:auto; gap:.55rem; padding-bottom:.1rem; }
        #crm-leaves-module .leave-view-tab { min-width:9.6rem; padding:.72rem .8rem; }
        #crm-leaves-module .leave-filter-card { grid-template-columns:1fr; gap:.55rem; padding:.7rem; border-radius:.9rem; }
        #crm-leaves-module .leave-search-field,
        #crm-leaves-module .leave-filter-reset { grid-column:auto; }
        #crm-leaves-module .leave-side-panel { grid-template-columns:1fr; gap:.75rem; }
        #crm-leaves-module .leave-balance-card,
        #crm-leaves-module .leave-type-card { border-radius:.9rem; padding:.85rem; }
        #crm-leaves-module .leave-reporting-grid { grid-template-columns:1fr; gap:.55rem; padding:.75rem; }
        #crm-leaves-module .leave-main-column { gap:1rem; }
        #crm-leaves-module .leave-planning-head {
          grid-template-columns:2.75rem minmax(0,1fr) 2.75rem;
          gap:.55rem;
          padding:1rem .9rem .65rem;
        }
        #crm-leaves-module .leave-nav-button {
          width:2.75rem;
          height:2.75rem;
          border-radius:.8rem;
        }
        #crm-leaves-module .leave-month-title { font-size:1.38rem; }
        #crm-leaves-module .leave-month-subtitle { font-size:.78rem; }
        #crm-leaves-module .leave-tabs {
          gap:.42rem;
          padding:0 .9rem .85rem;
        }
        #crm-leaves-module .leave-tab {
          min-height:2.55rem;
          font-size:.8rem;
        }
        #crm-leaves-module .leave-weekdays span {
          padding:.65rem .2rem;
          font-size:.68rem;
        }
        #crm-leaves-module .leave-date {
          --line-x-pad:.28rem;
          min-height:5.45rem;
          padding:.43rem .28rem;
        }
        #crm-leaves-module .leave-number {
          width:1.65rem;
          height:1.65rem;
          font-size:.78rem;
        }
        #crm-leaves-module .leave-day-items {
          gap:.13rem;
          margin-top:.32rem;
        }
        #crm-leaves-module .leave-line,
        #crm-leaves-module .leave-lane-spacer {
          height:.14rem;
          box-shadow:none;
        }
        #crm-leaves-module .leave-legend {
          gap:.55rem .8rem;
          padding:.85rem .95rem;
          font-size:.74rem;
        }
        #crm-leaves-module .leave-legend-item span:last-child { max-width:5.5rem; }
        #crm-leaves-module .leave-card-head,
        #crm-leaves-module .leave-users-head {
          padding:1rem;
        }
        #crm-leaves-module .leave-day-list,
        #crm-leaves-module .leave-users-grid {
          padding:1rem;
        }
        #crm-leaves-module .leave-summary {
          grid-template-columns:repeat(2,minmax(0,1fr));
          gap:.55rem;
        }
        #crm-leaves-module .leave-summary-card {
          grid-template-columns:2.35rem minmax(0,1fr);
          gap:.55rem;
          padding:.75rem .65rem;
          border-radius:.85rem;
        }
        #crm-leaves-module .leave-summary-icon {
          width:2.35rem;
          height:2.35rem;
        }
        #crm-leaves-module .leave-summary-card strong { font-size:1.05rem; }
        #crm-leaves-module .leave-summary-card em { white-space:normal; }
        #crm-leaves-module .leave-request-row {
          grid-template-columns:1fr;
          gap:.45rem;
        }
        #crm-leaves-module .leave-request-actions {
          justify-content:flex-start;
          padding-left:1.6rem;
        }
        #crm-leaves-module .leave-request-main {
          grid-template-columns:.85rem minmax(0,1fr);
        }
        #crm-leaves-module .leave-request-main .leave-status-pill {
          grid-column:2;
          justify-self:start;
          margin-top:.15rem;
        }
        #crm-leaves-module .pdf-sheet { min-width:48rem; }
        #crm-leaves-module .leave-wall-actions { width:100%; justify-content:flex-start; }
        #crm-leaves-module .leave-wall-scroll { padding:.65rem; }
        #crm-leaves-module .leave-wall-sheet { --wall-user-width:5.8rem; --wall-day-width:1.28rem; padding:.25rem; }
      }
      #crm-leaves-module .leaves-page {
        gap:1.15rem;
        color:#172033;
      }
      #crm-leaves-module .leave-app-header {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:1rem;
        border-bottom:1px solid rgba(15,23,42,.08);
        background:#fffdfa;
        padding:.75rem 1rem;
      }
      #crm-leaves-module .leave-header-main {
        display:flex;
        min-width:0;
        align-items:center;
        gap:1.25rem;
      }
      #crm-leaves-module .leave-header-main h1 {
        margin:0;
        color:#172033;
        font-size:1.25rem;
        font-weight:950;
        line-height:1;
        letter-spacing:0;
      }
      #crm-leaves-module .leave-view-tabs {
        display:flex;
        min-width:0;
        gap:1rem;
        overflow-x:auto;
      }
      #crm-leaves-module .leave-view-tab {
        position:relative;
        min-width:max-content;
        border:0;
        border-radius:0;
        background:transparent;
        box-shadow:none;
        color:#6b7280;
        padding:.78rem 0 .72rem;
        font-size:.78rem;
        font-weight:850;
        line-height:1;
      }
      #crm-leaves-module .leave-view-tab:hover {
        background:transparent;
        color:#172033;
      }
      #crm-leaves-module .leave-view-tab.is-active {
        background:transparent;
        color:#172033;
        box-shadow:none;
      }
      #crm-leaves-module .leave-view-tab.is-active::after {
        content:"";
        position:absolute;
        left:0;
        right:0;
        bottom:0;
        height:2px;
        border-radius:999px;
        background:#172033;
      }
      #crm-leaves-module .leave-header-icons {
        display:flex;
        flex:0 0 auto;
        align-items:center;
        gap:.55rem;
      }
      #crm-leaves-module .leave-round-icon {
        display:grid;
        place-items:center;
        width:2.1rem;
        height:2.1rem;
        border:0;
        border-radius:999px;
        background:transparent;
        color:#172033;
        font-size:1rem;
        font-weight:850;
      }
      #crm-leaves-module .leave-round-icon:hover {
        background:#f0efeb;
      }
      #crm-leaves-module .leave-hr-layout {
        display:grid;
        grid-template-columns:16.5rem minmax(0,1fr);
        align-items:start;
        gap:1rem;
      }
      #crm-leaves-module .leave-hr-layout.is-full {
        grid-template-columns:minmax(0,1fr);
      }
      #crm-leaves-module .leave-work-area {
        display:grid;
        min-width:0;
        gap:1rem;
      }
      #crm-leaves-module .leave-absences-sidebar {
        display:grid;
        gap:.8rem;
      }
      #crm-leaves-module .leave-profile-card,
      #crm-leaves-module .leave-sidebar-card,
      #crm-leaves-module .leave-card {
        border:1px solid rgba(15,23,42,.08);
        border-radius:.55rem;
        background:#fffdfa;
        box-shadow:0 12px 30px rgba(15,23,42,.045);
      }
      #crm-leaves-module .leave-profile-card {
        display:grid;
        justify-items:center;
        gap:.45rem;
        padding:1.45rem 1rem 1rem;
      }
      #crm-leaves-module .leave-profile-avatar {
        display:grid;
        place-items:center;
        width:4.7rem;
        height:4.7rem;
        border-radius:999px;
        background:linear-gradient(135deg,#fff 0%,#f8e9ef 100%);
        color:rgb(var(--theme-primary));
        font-size:1.2rem;
        font-weight:950;
        box-shadow:inset 0 0 0 .18rem #fff, 0 10px 24px rgba(15,23,42,.08);
      }
      #crm-leaves-module .leave-profile-card h2 {
        margin:.2rem 0 0;
        color:#172033;
        font-size:.98rem;
        font-weight:950;
        line-height:1.1;
        text-align:center;
      }
      #crm-leaves-module .leave-profile-card p {
        margin:0;
        color:#6b7280;
        font-size:.76rem;
        font-weight:750;
        text-align:center;
      }
      #crm-leaves-module .leave-balance-grid {
        width:100%;
        grid-template-columns:1fr;
        gap:.35rem;
        margin-top:.55rem;
      }
      #crm-leaves-module .leave-balance-grid span {
        background:#f4f2ee;
        border-radius:.45rem;
        padding:.48rem .55rem;
      }
      #crm-leaves-module .leave-sidebar-card {
        padding:1rem;
      }
      #crm-leaves-module .leave-sidebar-card h3,
      #crm-leaves-module .leave-type-card h3 {
        margin:0 0 .75rem;
        color:#172033;
        font-size:.88rem;
        font-weight:950;
      }
      #crm-leaves-module .leave-type-row {
        grid-template-columns:.8rem minmax(0,1fr) auto;
        min-height:2.05rem;
        border:0;
        border-radius:.45rem;
        background:transparent;
        padding:.34rem .25rem;
      }
      #crm-leaves-module .leave-type-row span {
        width:.8rem;
        height:.8rem;
        border:1px solid color-mix(in srgb,var(--type-color) 60%,#fff);
        border-radius:.22rem;
        background:color-mix(in srgb,var(--type-color) 22%,#fff);
      }
      #crm-leaves-module .leave-type-row strong,
      #crm-leaves-module .leave-type-row em {
        font-size:.78rem;
      }
      #crm-leaves-module .leave-convention-card p,
      #crm-leaves-module .leave-ical-card p {
        margin:.25rem 0;
        color:#4b5563;
        font-size:.78rem;
        font-weight:720;
      }
      #crm-leaves-module .leave-ical-card {
        justify-items:center;
        text-align:center;
      }
      #crm-leaves-module .leave-ical-emoji {
        display:grid;
        place-items:center;
        width:3.5rem;
        height:3.5rem;
        margin:0 auto .4rem;
        border-radius:999px;
        background:#f4f2ee;
        color:rgb(var(--theme-primary));
        font-size:1.35rem;
        font-weight:950;
      }
      #crm-leaves-module .leave-app-filters {
        grid-template-columns:minmax(14rem,1.7fr) repeat(3,minmax(8rem,1fr)) auto;
        border-radius:.55rem;
        background:#f8f7f4;
        box-shadow:none;
      }
      #crm-leaves-module .leave-search-field,
      #crm-leaves-module .leave-filter-card select,
      #crm-leaves-module .leave-search-field input {
        border-radius:.42rem;
        background:#fffdfa;
      }
      #crm-leaves-module .leave-calendar-toolbar,
      #crm-leaves-module .leave-team-toolbar {
        display:grid;
        grid-template-columns:auto auto minmax(0,1fr) auto;
        align-items:center;
        gap:.8rem;
        padding:1rem;
      }
      #crm-leaves-module .leave-view-mode {
        display:inline-grid;
        grid-auto-flow:column;
        overflow:hidden;
        border:1px solid rgba(15,23,42,.1);
        border-radius:.45rem;
        background:#f4f2ee;
      }
      #crm-leaves-module .leave-view-mode button {
        min-width:2.55rem;
        min-height:2.25rem;
        border:0;
        border-right:1px solid rgba(15,23,42,.08);
        background:transparent;
        color:#4b5563;
        padding:.4rem .65rem;
        font-weight:850;
      }
      #crm-leaves-module .leave-view-mode button:last-child {
        border-right:0;
      }
      #crm-leaves-module .leave-view-mode button.is-active {
        background:#dedbd3;
        color:#172033;
      }
      #crm-leaves-module .leave-year-nav {
        display:inline-grid;
        grid-template-columns:2rem auto 2rem;
        align-items:center;
        gap:.25rem;
        border:1px solid rgba(15,23,42,.1);
        border-radius:.45rem;
        background:#f4f2ee;
        padding:.15rem;
      }
      #crm-leaves-module .leave-year-nav button {
        width:2rem;
        height:2rem;
        border:0;
        border-radius:.35rem;
        background:transparent;
        color:#4b5563;
        font-size:1.1rem;
        font-weight:900;
      }
      #crm-leaves-module .leave-year-nav button:hover {
        background:#fffdfa;
        color:#172033;
      }
      #crm-leaves-module .leave-year-nav strong {
        min-width:4.1rem;
        color:#172033;
        font-size:.86rem;
        font-weight:850;
        text-align:center;
      }
      #crm-leaves-module .leave-stat-strip {
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:.8rem;
      }
      #crm-leaves-module .leave-stat-strip article {
        display:grid;
        grid-template-columns:2.45rem minmax(0,1fr);
        min-width:0;
        align-items:center;
        gap:.72rem;
        border:1px solid var(--color-surface-200,#e2e8f0);
        border-radius:.5rem;
        background:#fff;
        box-shadow:0 12px 28px rgba(15,23,42,.05);
        padding:.9rem;
      }
      #crm-leaves-module .leave-stat-strip strong {
        display:block;
        margin:.25rem 0 0;
        color:#172033;
        font-size:1.25rem;
        font-weight:900;
        line-height:1.1;
        letter-spacing:0;
      }
      #crm-leaves-module .leave-stat-icon {
        display:grid;
        place-items:center;
        width:2.45rem;
        height:2.45rem;
        border-radius:.55rem;
        background:color-mix(in srgb,var(--leave-stat-color,#95002e) 14%,white);
        color:var(--leave-stat-color,#95002e);
      }
      #crm-leaves-module .leave-stat-icon .leave-summary-icon-svg {
        width:1.2rem;
        height:1.2rem;
      }
      #crm-leaves-module .leave-stat-copy {
        min-width:0;
      }
      #crm-leaves-module .leave-stat-copy small {
        display:block;
        overflow:hidden;
        color:var(--color-secondary-500,#64748b);
        font-size:.73rem;
        font-weight:900;
        text-overflow:ellipsis;
        text-transform:uppercase;
        white-space:nowrap;
      }
      #crm-leaves-module .leave-calendar-actions,
      #crm-leaves-module .leave-wall-actions {
        display:flex;
        flex-wrap:wrap;
        justify-content:flex-end;
        gap:.5rem;
      }
      #crm-leaves-module .leave-calendar-period {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:.8rem;
        padding:0 1rem .9rem;
        color:#6b7280;
        font-size:.8rem;
        font-weight:760;
      }
      #crm-leaves-module .leave-calendar-period strong {
        color:#172033;
        font-weight:850;
      }
      #crm-leaves-module .leave-year-calendar {
        display:grid;
        grid-template-columns:repeat(3,minmax(0,1fr));
        gap:1.1rem 1.35rem;
        border-top:1px solid rgba(15,23,42,.08);
        padding:1.25rem 1.6rem 1.4rem;
      }
      #crm-leaves-module .leave-mini-month {
        min-width:0;
      }
      #crm-leaves-module .leave-mini-month h3 {
        margin:0 0 .55rem;
        color:#172033;
        font-size:.88rem;
        font-weight:950;
        text-transform:capitalize;
      }
      #crm-leaves-module .leave-mini-grid {
        display:grid;
        grid-template-columns:repeat(7,minmax(0,1fr));
        gap:.18rem;
      }
      #crm-leaves-module .leave-mini-day {
        position:relative;
        display:grid;
        place-items:center;
        min-width:0;
        height:1.55rem;
        border:0;
        border-radius:.18rem;
        background:transparent;
        color:#172033;
        font-size:.74rem;
        font-weight:760;
      }
      #crm-leaves-module .leave-mini-day.is-muted {
        color:#b5b8be;
      }
      #crm-leaves-module .leave-mini-day.has-leave {
        background:color-mix(in srgb,var(--absence-color) 24%,#fff);
        box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--absence-color) 58%,#fff);
      }
      #crm-leaves-module .leave-mini-day.is-today {
        color:rgb(var(--theme-primary));
      }
      #crm-leaves-module .leave-mini-day.is-today span {
        width:auto;
        height:auto;
        border:0;
        border-radius:0;
        color:inherit;
      }
      #crm-leaves-module .leave-mini-day.is-selected {
        background:rgb(var(--theme-primary));
        color:#fff;
      }
      #crm-leaves-module .leave-team-count {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:.75rem;
        padding:0 1rem .8rem;
        color:#172033;
        font-size:.8rem;
        font-weight:850;
      }
      #crm-leaves-module .leave-filter-chip {
        display:inline-flex;
        align-items:center;
        min-height:2.25rem;
        border:1px solid rgba(15,23,42,.08);
        border-radius:.45rem;
        background:#f4f2ee;
        color:#4b5563;
        padding:.35rem .7rem;
        font-size:.76rem;
        font-weight:820;
      }
      #crm-leaves-module .leave-team-scroll,
      #crm-leaves-module .leave-balance-table-wrap,
      #crm-leaves-module .leave-settings-table-wrap {
        overflow:auto;
        border-top:1px solid rgba(15,23,42,.08);
      }
      #crm-leaves-module .leave-team-timeline {
        min-width:58rem;
        width:100%;
        table-layout:fixed;
        border-collapse:collapse;
      }
      #crm-leaves-module .leave-team-user-col {
        width:13rem;
      }
      #crm-leaves-module .leave-team-day-col {
        width:calc((100% - 13rem) / var(--day-count));
      }
      #crm-leaves-module .leave-team-timeline th,
      #crm-leaves-module .leave-team-timeline td {
        height:2.55rem;
        border-bottom:1px solid rgba(15,23,42,.08);
        padding:0 .15rem;
        text-align:center;
        vertical-align:middle;
      }
      #crm-leaves-module .leave-team-timeline thead th {
        height:3rem;
        color:#6b7280;
        font-size:.68rem;
        font-weight:760;
      }
      #crm-leaves-module .leave-team-timeline thead th span,
      #crm-leaves-module .leave-team-timeline thead th strong {
        display:block;
      }
      #crm-leaves-module .leave-team-timeline thead th strong {
        margin-top:.14rem;
        color:#172033;
        font-size:.76rem;
        font-weight:850;
      }
      #crm-leaves-module .leave-team-timeline th:first-child,
      #crm-leaves-module .leave-team-timeline tbody th {
        position:sticky;
        left:0;
        z-index:1;
        background:#fffdfa;
        text-align:left;
      }
      #crm-leaves-module .leave-team-timeline .is-weekend {
        background:#fafafa;
      }
      #crm-leaves-module .leave-team-person {
        display:inline-flex;
        min-width:0;
        align-items:center;
        gap:.55rem;
      }
      #crm-leaves-module .leave-team-person i {
        display:grid;
        place-items:center;
        width:1.7rem;
        height:1.7rem;
        flex:0 0 auto;
        border-radius:999px;
        background:color-mix(in srgb,var(--person-color,#38bdf8) 25%,#fff);
        color:#172033;
        font-size:.58rem;
        font-style:normal;
        font-weight:950;
      }
      #crm-leaves-module .leave-team-person strong {
        min-width:0;
        overflow:hidden;
        color:#172033;
        font-size:.78rem;
        font-weight:850;
        text-overflow:ellipsis;
        white-space:nowrap;
      }
      #crm-leaves-module .leave-team-pill {
        display:block;
        width:100%;
        height:1.35rem;
        border:1px solid color-mix(in srgb,var(--absence-color) 65%,#fff);
        border-radius:.42rem;
        background:color-mix(in srgb,var(--absence-color) 26%,#fff);
      }
      #crm-leaves-module .leave-team-pill.is-pending {
        border-style:dashed;
        background:#fffdfa;
      }
      #crm-leaves-module .leave-balance-table,
      #crm-leaves-module .leave-settings-table,
      #crm-leaves-module .leave-requests-table {
        min-width:42rem;
        width:100%;
        border-collapse:collapse;
      }
      #crm-leaves-module .leave-balance-table th,
      #crm-leaves-module .leave-balance-table td,
      #crm-leaves-module .leave-settings-table th,
      #crm-leaves-module .leave-settings-table td {
        border-bottom:1px solid rgba(15,23,42,.08);
        padding:.9rem 1rem;
        color:#4b5563;
        font-size:.86rem;
        text-align:left;
      }
      #crm-leaves-module .leave-balance-table th,
      #crm-leaves-module .leave-settings-table th {
        background:#f8f7f4;
        color:#6b7280;
        font-size:.72rem;
        font-weight:850;
      }
      #crm-leaves-module .leave-settings-board {
        max-width:62rem;
        margin:0 auto;
        padding:1.4rem;
      }
      #crm-leaves-module .leave-settings-head {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:1rem;
      }
      #crm-leaves-module .leave-settings-head h2 {
        margin:0;
        color:#172033;
        font-size:1.65rem;
        font-weight:950;
        line-height:1.1;
      }
      #crm-leaves-module .leave-settings-head p {
        max-width:33rem;
        margin:.45rem 0 0;
        color:#4b5563;
        font-size:.98rem;
        line-height:1.45;
      }
      #crm-leaves-module .leave-settings-search {
        margin:1.25rem 0;
      }
      #crm-leaves-module .leave-color-swatch {
        display:inline-block;
        width:3rem;
        height:.78rem;
        border:2px solid color-mix(in srgb,var(--type-color) 70%,#fff);
        border-radius:999px;
        background:color-mix(in srgb,var(--type-color) 20%,#fff);
      }
      #crm-leaves-module {
        --leave-accent:#a30038;
        --leave-ink:#111827;
        --leave-muted:#767d89;
        --leave-panel:#fbfaf7;
        --leave-panel-soft:#f4f2ee;
      }
      #crm-leaves-module .leaves-page {
        background:#f4f6f8;
        border-radius:.05rem;
      }
      #crm-leaves-module .leave-app-header {
        background:var(--leave-panel);
        border:1px solid rgba(17,24,39,.06);
        border-radius:.1rem;
        box-shadow:none;
      }
      #crm-leaves-module .leave-header-main h1 {
        font-size:1.18rem;
        letter-spacing:-.01em;
      }
      #crm-leaves-module .leave-view-tabs {
        gap:1.25rem;
      }
      #crm-leaves-module .leave-view-tab {
        color:#6f7581;
        font-size:.76rem;
        padding:.84rem 0 .8rem;
      }
      #crm-leaves-module .leave-view-tab.is-active,
      #crm-leaves-module .leave-view-tab:hover {
        color:var(--leave-ink);
      }
      #crm-leaves-module .leave-view-tab.is-active::after {
        height:2px;
        background:var(--leave-ink);
      }
      #crm-leaves-module .leave-round-icon {
        color:#111827;
        font-size:.96rem;
      }
      #crm-leaves-module .leave-hr-layout {
        grid-template-columns:15.6rem minmax(0,1fr);
        gap:1.15rem;
      }
      #crm-leaves-module .leave-hr-layout.is-full {
        grid-template-columns:minmax(0,1fr);
      }
      #crm-leaves-module .leave-profile-card,
      #crm-leaves-module .leave-sidebar-card,
      #crm-leaves-module .leave-card {
        background:var(--leave-panel);
        border-color:rgba(17,24,39,.075);
        border-radius:.5rem;
        box-shadow:0 18px 46px rgba(17,24,39,.055);
      }
      #crm-leaves-module .leave-profile-card {
        padding:1.35rem 1rem .95rem;
      }
      #crm-leaves-module .leave-profile-avatar {
        width:4.5rem;
        height:4.5rem;
        background:linear-gradient(135deg,#fff 0%,#f6edf1 100%);
        color:var(--leave-accent);
      }
      #crm-leaves-module .leave-profile-card h2 {
        color:var(--leave-ink);
        font-size:.95rem;
      }
      #crm-leaves-module .leave-profile-card p,
      #crm-leaves-module .leave-convention-card p,
      #crm-leaves-module .leave-ical-card p {
        color:var(--leave-muted);
      }
      #crm-leaves-module .leave-balance-grid span {
        background:var(--leave-panel-soft);
      }
      #crm-leaves-module .leave-app-filters {
        background:var(--leave-panel);
        border-color:rgba(17,24,39,.075);
        border-radius:.5rem;
        padding:.8rem;
      }
      #crm-leaves-module .leave-filter-card label span {
        color:#747b88;
        font-size:.66rem;
      }
      #crm-leaves-module .leave-search-field,
      #crm-leaves-module .leave-filter-card select,
      #crm-leaves-module .leave-search-field input {
        background:#fff;
        border-color:rgba(17,24,39,.1);
        border-radius:.38rem;
      }
      #crm-leaves-module .leave-calendar-toolbar {
        grid-template-columns:auto auto minmax(22rem,1fr) auto;
        gap:.65rem 1rem;
        padding:1rem 1rem .8rem;
      }
      #crm-leaves-module .leave-team-board {
        overflow:hidden;
      }
      #crm-leaves-module .leave-team-toolbar {
        display:flex;
        flex-wrap:wrap;
        align-items:center;
        justify-content:space-between;
        gap:.7rem;
        padding:.85rem 1rem .65rem;
      }
      #crm-leaves-module .leave-team-filter-strip {
        display:grid;
        grid-template-columns:minmax(18rem,1.45fr) repeat(3,minmax(8rem,1fr)) auto;
        align-items:end;
        gap:.55rem;
        margin:0 1rem .85rem;
        padding:.6rem;
        border:1px solid rgba(17,24,39,.075);
        border-radius:.45rem;
        background:#fff;
      }
      #crm-leaves-module .leave-team-filter-strip label {
        display:grid;
        gap:.18rem;
        min-width:0;
      }
      #crm-leaves-module .leave-team-filter-strip label span {
        color:#747b88;
        font-size:.61rem;
        font-weight:850;
        text-transform:uppercase;
      }
      #crm-leaves-module .leave-team-filter-strip select {
        width:100%;
        min-height:2.12rem;
        border:1px solid rgba(17,24,39,.1);
        border-radius:.38rem;
        background:#fff;
        color:#172033;
        padding:.35rem .6rem;
        font-size:.78rem;
        font-weight:820;
      }
      #crm-leaves-module .leave-view-mode,
      #crm-leaves-module .leave-year-nav {
        background:var(--leave-panel-soft);
        border-color:rgba(17,24,39,.105);
        border-radius:.42rem;
      }
      #crm-leaves-module .leave-view-mode button.is-active {
        background:#ddd9cf;
      }
      #crm-leaves-module .leave-year-nav strong {
        color:var(--leave-ink);
      }
      #crm-leaves-module .leave-stat-strip {
        justify-self:end;
        width:min(100%,37rem);
        border:0;
        border-radius:0;
        background:transparent;
      }
      #crm-leaves-module .leave-stat-strip article {
        min-height:4.25rem;
        padding:.9rem;
      }
      #crm-leaves-module .leave-stat-strip strong {
        color:var(--leave-ink);
        font-size:1.25rem;
        line-height:1;
      }
      #crm-leaves-module .leave-stat-copy small {
        color:var(--color-secondary-500,#64748b);
        font-size:.73rem;
        font-weight:900;
      }
      #crm-leaves-module .leave-calendar-actions {
        grid-column:3 / 5;
        align-items:center;
      }
      #crm-leaves-module .leaves-button,
      #crm-leaves-module .leave-filter-reset {
        min-height:2.38rem;
        border-radius:.38rem;
        background:#fff;
        color:#1f2937;
      }
      #crm-leaves-module .leaves-button-primary,
      #crm-leaves-module .leaves-button-export,
      #crm-leaves-module .leave-add-day {
        border-color:var(--leave-accent)!important;
        background:var(--leave-accent)!important;
        color:#fff!important;
        box-shadow:0 12px 28px rgba(163,0,56,.16);
      }
      #crm-leaves-module .leave-calendar-period {
        padding:0 1rem .95rem;
      }
      #crm-leaves-module .leave-calendar-period strong {
        color:var(--leave-ink);
      }
      #crm-leaves-module .leave-year-calendar {
        grid-template-columns:repeat(3,minmax(13.5rem,1fr));
        gap:1.35rem 2rem;
        padding:1.35rem 1.7rem 1.55rem;
      }
      #crm-leaves-module .leave-mini-month h3 {
        margin-bottom:.48rem;
        color:var(--leave-ink);
        font-size:.83rem;
      }
      #crm-leaves-module .leave-mini-grid {
        gap:.08rem 0;
      }
      #crm-leaves-module .leave-mini-day {
        height:1.42rem;
        border-radius:.18rem;
        color:#1f2937;
        font-size:.72rem;
        font-weight:640;
      }
      #crm-leaves-module .leave-mini-day.is-muted {
        color:#b5bbc4;
      }
      #crm-leaves-module .leave-mini-day.has-leave {
        z-index:1;
        border:1px solid color-mix(in srgb,var(--absence-color) 62%,#fff);
        background:color-mix(in srgb,var(--absence-color) 28%,#fff);
        box-shadow:none;
      }
      #crm-leaves-module .leave-mini-day.has-leave.is-range-start {
        border-right-width:0;
        border-top-right-radius:0;
        border-bottom-right-radius:0;
      }
      #crm-leaves-module .leave-mini-day.has-leave.is-range-middle {
        border-right-width:0;
        border-left-width:0;
        border-radius:0;
      }
      #crm-leaves-module .leave-mini-day.has-leave.is-range-end {
        border-left-width:0;
        border-top-left-radius:0;
        border-bottom-left-radius:0;
      }
      #crm-leaves-module .leave-mini-day.has-leave.is-pending {
        background:#fff;
        box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--absence-color) 70%,#fff), inset 0 -2px 0 color-mix(in srgb,var(--absence-color) 45%,#fff);
      }
      #crm-leaves-module .leave-mini-day.is-today {
        color:var(--leave-accent);
      }
      #crm-leaves-module .leave-mini-day.is-today span {
        width:auto;
        height:auto;
        border:0;
        border-radius:0;
        color:inherit;
      }
      #crm-leaves-module .leave-mini-day.is-selected {
        background:#fff;
        color:var(--leave-accent);
        box-shadow:inset 0 0 0 1.5px var(--leave-accent);
      }
      #crm-leaves-module .leave-team-rangebar {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:.75rem;
        padding:.55rem 1rem .75rem;
        border-top:1px solid rgba(17,24,39,.07);
        color:#172033;
        font-size:.78rem;
        font-weight:850;
      }
      #crm-leaves-module .leave-team-rangebar strong {
        margin-left:auto;
        color:#172033;
        font-size:.78rem;
        font-weight:850;
      }
      #crm-leaves-module .leave-team-rangebar em {
        color:#3f51a3;
        font-size:.72rem;
        font-style:normal;
        font-weight:820;
      }
      #crm-leaves-module .leave-team-scroll {
        overflow:auto;
        border-top:1px solid rgba(17,24,39,.08);
        background:#fff;
      }
      #crm-leaves-module .leave-team-timeline {
        min-width:58rem;
        width:100%;
        table-layout:fixed;
        border-collapse:collapse;
      }
      #crm-leaves-module .leave-team-timeline.is-day {
        min-width:22rem;
      }
      #crm-leaves-module .leave-team-timeline.is-week {
        min-width:38rem;
      }
      #crm-leaves-module .leave-team-user-col {
        width:12rem;
      }
      #crm-leaves-module .leave-team-day-col {
        width:calc((100% - 12rem) / var(--day-count));
      }
      #crm-leaves-module .leave-team-timeline th,
      #crm-leaves-module .leave-team-timeline td {
        height:2.16rem;
        border-bottom:1px solid rgba(17,24,39,.09);
        padding:0;
        text-align:center;
        vertical-align:middle;
      }
      #crm-leaves-module .leave-team-timeline thead th {
        height:2.2rem;
        color:#7b818c;
        font-size:.62rem;
        font-weight:720;
      }
      #crm-leaves-module .leave-team-timeline .leave-team-month-head th {
        height:1.55rem;
        background:#fff;
        border-bottom:1px solid rgba(17,24,39,.08);
        color:#6b7280;
        font-size:.65rem;
        font-weight:760;
        text-align:left;
        text-transform:capitalize;
      }
      #crm-leaves-module .leave-team-timeline .leave-team-month-head th:not(:first-child) {
        padding-left:.6rem;
      }
      #crm-leaves-module .leave-team-timeline thead th span,
      #crm-leaves-module .leave-team-timeline thead th strong {
        display:block;
      }
      #crm-leaves-module .leave-team-timeline thead th span {
        color:#172033;
        font-size:.72rem;
        font-weight:850;
        text-transform:uppercase;
      }
      #crm-leaves-module .leave-team-timeline thead th strong {
        margin-top:.08rem;
        color:#172033;
        font-size:.72rem;
        font-weight:850;
      }
      #crm-leaves-module .leave-team-timeline th:first-child,
      #crm-leaves-module .leave-team-timeline tbody th {
        position:sticky;
        left:0;
        z-index:1;
        background:#fffdfa;
        text-align:left;
      }
      #crm-leaves-module .leave-team-timeline .is-alternate {
        background:#fbfaf6;
      }
      #crm-leaves-module .leave-team-timeline .is-weekend {
        background:#f3f6fb;
      }
      #crm-leaves-module .leave-team-person {
        display:inline-flex;
        width:100%;
        min-width:0;
        align-items:center;
        gap:.5rem;
        padding-left:.75rem;
      }
      #crm-leaves-module .leave-team-person i {
        display:grid;
        place-items:center;
        width:1.55rem;
        height:1.55rem;
        flex:0 0 auto;
        border-radius:999px;
        background:color-mix(in srgb,var(--person-color,var(--user-color,#38bdf8)) 26%,#fff);
        color:#172033;
        font-size:.56rem;
        font-style:normal;
        font-weight:950;
      }
      #crm-leaves-module .leave-team-person strong {
        min-width:0;
        overflow:hidden;
        color:#172033;
        font-size:.76rem;
        font-weight:860;
        text-overflow:ellipsis;
        white-space:nowrap;
      }
      #crm-leaves-module .leave-team-pill {
        display:block;
        width:100%;
        height:1.25rem;
        border:1px solid color-mix(in srgb,var(--absence-color) 68%,#fff);
        border-inline-width:0;
        border-radius:0;
        background:color-mix(in srgb,var(--absence-color) 30%,#fff);
      }
      #crm-leaves-module .leave-team-pill.is-range-single {
        width:calc(100% - .24rem);
        margin-inline:.12rem;
        border-inline-width:1px;
        border-radius:.34rem;
      }
      #crm-leaves-module .leave-team-pill.is-range-start {
        width:calc(100% - .12rem);
        margin-left:.12rem;
        border-left-width:1px;
        border-top-left-radius:.34rem;
        border-bottom-left-radius:.34rem;
      }
      #crm-leaves-module .leave-team-pill.is-range-end {
        width:calc(100% - .12rem);
        margin-right:.12rem;
        border-right-width:1px;
        border-top-right-radius:.34rem;
        border-bottom-right-radius:.34rem;
      }
      #crm-leaves-module .leave-team-pill.is-range-middle {
        border-inline-width:0;
      }
      #crm-leaves-module .leave-team-pill.is-pending {
        border-style:dashed;
        background:#fffdfa;
      }
      #crm-leaves-module .leave-profile-avatar,
      #crm-leaves-module .leave-team-avatar,
      #crm-leaves-module .leave-table-avatar,
      #crm-leaves-module .leave-user-dot {
        display:grid;
        place-items:center;
        overflow:hidden;
        flex:0 0 auto;
        border-radius:999px;
        background:color-mix(in srgb,var(--person-color,#38bdf8) 26%,#fff);
        color:#172033;
        font-style:normal;
        font-weight:950;
      }
      #crm-leaves-module .leave-team-avatar {
        width:1.55rem;
        height:1.55rem;
        font-size:.56rem;
      }
      #crm-leaves-module .leave-table-avatar {
        width:1.15rem;
        height:1.15rem;
        font-size:.48rem;
      }
      #crm-leaves-module .leave-user-dot {
        width:1.05rem;
        height:1.05rem;
        font-size:.46rem;
      }
      #crm-leaves-module .leave-table-person {
        grid-template-columns:1.15rem minmax(0,1fr);
      }
      #crm-leaves-module .leave-user-row {
        grid-template-columns:1.05rem minmax(0,1fr) auto;
      }
      #crm-leaves-module .leave-profile-avatar.has-photo,
      #crm-leaves-module .leave-team-avatar.has-photo,
      #crm-leaves-module .leave-table-avatar.has-photo,
      #crm-leaves-module .leave-user-dot.has-photo {
        background:#fff;
      }
      #crm-leaves-module .leave-profile-avatar img,
      #crm-leaves-module .leave-team-avatar img,
      #crm-leaves-module .leave-table-avatar img,
      #crm-leaves-module .leave-user-dot img {
        display:block;
        width:100%;
        height:100%;
        object-fit:cover;
      }
      #crm-leaves-module .leave-legend {
        padding:.85rem 1rem;
        background:var(--leave-panel);
        font-size:.75rem;
      }
      @media (max-width:1180px) {
        #crm-leaves-module .leave-calendar-toolbar,
        #crm-leaves-module .leave-team-toolbar {
          grid-template-columns:1fr auto;
        }
        #crm-leaves-module .leave-stat-strip,
        #crm-leaves-module .leave-calendar-actions,
        #crm-leaves-module .leave-wall-actions {
          grid-column:1/-1;
        }
        #crm-leaves-module .leave-stat-strip {
          justify-self:stretch;
          width:100%;
        }
        #crm-leaves-module .leave-year-calendar {
          grid-template-columns:repeat(2,minmax(0,1fr));
        }
        #crm-leaves-module .leave-app-filters {
          grid-template-columns:repeat(2,minmax(0,1fr));
        }
        #crm-leaves-module .leave-team-filter-strip {
          grid-template-columns:minmax(16rem,1fr) repeat(2,minmax(8rem,1fr));
        }
        #crm-leaves-module .leave-team-filter-strip .leave-filter-reset {
          grid-column:auto;
        }
        #crm-leaves-module .leave-team-rangebar {
          flex-wrap:wrap;
        }
      }
      @media (max-width:760px) {
        #crm-leaves-module .leave-app-header {
          align-items:flex-start;
          padding:.85rem .85rem .6rem;
        }
        #crm-leaves-module .leave-header-main {
          display:grid;
          gap:.55rem;
        }
        #crm-leaves-module .leave-view-tabs {
          max-width:calc(100vw - 7rem);
          gap:.9rem;
        }
        #crm-leaves-module .leave-header-icons {
          padding-top:.05rem;
        }
        #crm-leaves-module .leave-hr-layout {
          grid-template-columns:1fr;
        }
        #crm-leaves-module .leave-absences-sidebar {
          grid-template-columns:1fr;
        }
        #crm-leaves-module .leave-profile-card {
          align-items:center;
          justify-items:start;
          grid-template-columns:3.6rem minmax(0,1fr);
          padding:1rem;
        }
        #crm-leaves-module .leave-profile-avatar {
          grid-row:span 2;
          width:3.4rem;
          height:3.4rem;
          font-size:.92rem;
        }
        #crm-leaves-module .leave-profile-card h2,
        #crm-leaves-module .leave-profile-card p {
          text-align:left;
        }
        #crm-leaves-module .leave-balance-grid {
          grid-column:1/-1;
          grid-template-columns:repeat(3,minmax(0,1fr));
        }
        #crm-leaves-module .leave-app-filters {
          grid-template-columns:1fr;
        }
        #crm-leaves-module .leave-calendar-toolbar,
        #crm-leaves-module .leave-team-toolbar {
          grid-template-columns:1fr;
          gap:.65rem;
          padding:.85rem;
        }
        #crm-leaves-module .leave-team-toolbar {
          align-items:stretch;
        }
        #crm-leaves-module .leave-team-filter-strip {
          grid-template-columns:1fr;
          margin:0 .85rem .75rem;
          padding:.55rem;
        }
        #crm-leaves-module .leave-team-rangebar {
          display:grid;
          justify-items:start;
          padding:.6rem .85rem .75rem;
        }
        #crm-leaves-module .leave-team-rangebar strong {
          margin-left:0;
        }
        #crm-leaves-module .leave-stat-strip {
          grid-template-columns:repeat(2,minmax(0,1fr));
          gap:.65rem;
        }
        #crm-leaves-module .leave-calendar-actions,
        #crm-leaves-module .leave-wall-actions {
          justify-content:stretch;
        }
        #crm-leaves-module .leave-calendar-actions .leaves-button,
        #crm-leaves-module .leave-wall-actions .leaves-button {
          flex:1 1 auto;
        }
        #crm-leaves-module .leave-calendar-period {
          display:grid;
          padding:0 .85rem .75rem;
        }
        #crm-leaves-module .leave-year-calendar {
          grid-template-columns:1fr;
          gap:1rem;
          padding:1rem;
        }
        #crm-leaves-module .leave-mini-day {
          height:1.85rem;
          font-size:.82rem;
        }
        #crm-leaves-module .leave-team-scroll {
          margin:0 -.15rem;
        }
        #crm-leaves-module .leave-settings-board {
          padding:1rem;
        }
        #crm-leaves-module .leave-settings-head {
          display:grid;
        }
      }
    `;
    style.textContent =
      root instanceof ShadowRoot
        ? css.replace(/\.dark #crm-leaves-module/g, ':host-context(.dark)').replace(/#crm-leaves-module/g, ':host')
        : css;
  }

  function renderMiniMonth(monthDate) {
    const days = monthGridDays(monthDate);
    const today = formatDate(new Date());

    return `
      <article class="leave-mini-month">
        <h3>${esc(monthDate.toLocaleDateString('fr-FR', { month: 'long' }))}</h3>
        <div class="leave-mini-grid">
          ${days
            .map((day) => {
              const date = formatDate(day);
              const leaves = leavesForMiniDay(day);
              const primary = leaves[0];
              const color = primary ? normalizeColor(typeMeta(primary.type).color, '#7dd3fc') : '';
              const weekDayIndex = (day.getDay() + 6) % 7;
              const continuesBefore = Boolean(primary && primary.startDate < date && weekDayIndex > 0);
              const continuesAfter = Boolean(primary && primary.endDate > date && weekDayIndex < 6);
              const classes = [
                'leave-mini-day',
                day.getMonth() !== monthDate.getMonth() ? 'is-muted' : '',
                date === today ? 'is-today' : '',
                date === state.selectedDate ? 'is-selected' : '',
                leaves.length ? 'has-leave' : '',
                primary && continuesBefore && continuesAfter ? 'is-range-middle' : '',
                primary && !continuesBefore && continuesAfter ? 'is-range-start' : '',
                primary && continuesBefore && !continuesAfter ? 'is-range-end' : '',
                primary?.status === 'pending' ? 'is-pending' : '',
              ]
                .filter(Boolean)
                .join(' ');

              return `
                <button type="button" class="${classes}" data-day data-date="${date}" style="${primary ? `--absence-color:${esc(color)}` : ''}" aria-label="${esc(dateLabel(date))}">
                  <span>${day.getDate()}</span>
                </button>
              `;
            })
            .join('')}
        </div>
      </article>
    `;
  }

  function renderYearCalendar() {
    return Array.from({ length: 12 }, (_, index) => new Date(state.month.getFullYear(), index, 1))
      .map((monthDate) => renderMiniMonth(monthDate))
      .join('');
  }

  function renderCalendar() {
    const employee = selectedEmployee();
    const subtitle = employee ? employee.name : activeSiteName();
    return `
      <section class="leave-card leave-calendar leave-year-card" aria-label="Calendrier conges">
        <div class="leave-calendar-toolbar">
          <div class="leave-view-mode">
            <button type="button" class="is-active" aria-label="Calendrier">▣</button>
            <button type="button" data-focus-selected aria-label="Liste">☷</button>
          </div>
          <div class="leave-year-nav">
            <button type="button" data-prev aria-label="Annee precedente">‹</button>
            <strong>${state.month.getFullYear()}</strong>
            <button type="button" data-next aria-label="Annee suivante">›</button>
          </div>
          ${renderTopMetrics()}
          <div class="leave-calendar-actions">
            ${canExport() ? '<button type="button" class="leaves-button" data-export-pdf>Exporter</button>' : ''}
            ${canCreateRequest() ? '<button type="button" class="leaves-button leaves-button-primary" data-add-request>+ Demander une absence</button>' : ''}
          </div>
        </div>
        <div class="leave-calendar-period">
          <strong>${esc(yearPeriodLabel())}</strong>
          <span>${esc(subtitle)}</span>
        </div>
        <div class="leave-year-calendar">
          ${renderYearCalendar()}
        </div>
      </section>
    `;
  }

  function renderDay(day, weekEmployeeIds = []) {
    const date = formatDate(day);
    const leaves = leavesForDate(date);
    const leavesByEmployee = new Map();
    leaves.forEach((leave) => {
      const employeeId = Number(leave.employeeId);
      if (!leavesByEmployee.has(employeeId)) leavesByEmployee.set(employeeId, leave);
    });
    const otherMonth = day.getMonth() !== state.month.getMonth();
    const sunday = day.getDay() === 0;
    const today = date === formatDate(new Date());
    const selected = date === state.selectedDate;
    const classes = [
      'leave-date',
      otherMonth ? 'is-other' : '',
      sunday ? 'is-sunday' : '',
      today ? 'is-today' : '',
      selected ? 'is-selected' : '',
      leaves.length ? 'has-absences' : '',
    ]
      .filter(Boolean)
      .join(' ');
    const visibleEmployeeIds = weekEmployeeIds.slice(0, 6);
    const leaveLines = visibleEmployeeIds
      .map((employeeId) => {
        const leave = leavesByEmployee.get(Number(employeeId));
        if (!leave) return '<span class="leave-lane-spacer" aria-hidden="true"></span>';

        const color = normalizeColor(leave.employeeColor || typeMeta(leave.type).color || '#38bdf8');
        const period = ['morning', 'afternoon'].includes(leave.period) ? leave.period : 'full';
        const status = String(leave.status || 'approved').replace(/[^a-z0-9_-]/gi, '') || 'approved';
        const label = `${leave.employeeName} - ${typeMeta(leave.type).label} - ${periodLabel(leave.period)}`;
        const weekDayIndex = (day.getDay() + 6) % 7;
        const continuationClasses =
          period === 'full'
            ? [
                leave.startDate < date && weekDayIndex > 0 ? 'is-continued-before' : '',
                leave.endDate > date && weekDayIndex < 6 ? 'is-continued-after' : '',
              ]
                .filter(Boolean)
                .join(' ')
            : '';
        return `
        <span class="leave-line is-${esc(period)} is-${esc(status)} ${esc(continuationClasses)}" style="--line-color:${esc(color)};--line-border:${esc(color)}66;--line-shadow:${esc(color)}24" title="${esc(label)}" aria-hidden="true"></span>
      `;
      })
      .join('');
    const ariaLabel = leaves.length
      ? `${dateLabel(date)} - ${leaves.length} absent(s): ${leaves.map((leave) => leave.employeeName).join(', ')}`
      : dateLabel(date);

    return `
      <button type="button" class="${classes}" data-day data-date="${date}" aria-label="${esc(ariaLabel)}">
        <span class="leave-day-head">
          <span class="leave-number">${day.getDate()}</span>
        </span>
        <span class="leave-day-items">${leaveLines}</span>
      </button>
    `;
  }

  function renderSummary() {
    const usersCount = employees().length;
    const pendingDays = monthLeaves()
      .filter((leave) => leave.status === 'pending')
      .reduce((sum, leave) => sum + daysCount(leave), 0);
    const usedDays = yearLeaves()
      .filter((leave) => leave.status === 'approved')
      .reduce((sum, leave) => sum + daysCount(leave), 0);
    const today = formatDate(new Date());
    const todayCount = leavesForDate(today).length;
    const sicknessCount = monthLeaves().filter((leave) => leave.type === 'maladie').length;

    const cards = [
      {
        label: 'Utilisateurs',
        value: usersCount,
        detail: activeSiteName(),
        icon: 'users',
        tone: '#2563eb',
      },
      {
        label: 'A valider',
        value: `${formatDaysCount(pendingDays)} j`,
        detail: pendingDays ? monthLabel(state.month) : 'Aucune demande',
        icon: 'clock',
        tone: '#f59e0b',
      },
      {
        label: 'Poses',
        value: `${formatDaysCount(usedDays)} j`,
        detail: `Annee ${state.month.getFullYear()}`,
        icon: 'check',
        tone: 'rgb(var(--theme-primary,149 0 46))',
      },
      {
        label: 'Absences',
        value: todayCount,
        detail: sicknessCount ? `${sicknessCount} arrêt(s) ce mois` : 'Aujourd hui',
        icon: 'alert',
        tone: '#0f766e',
      },
    ];

    return `
      <div class="leave-summary">
        ${cards
          .map(
            (card) => `
          <article class="leave-summary-card" style="--leave-summary-color:${esc(card.tone)}">
            <span class="leave-summary-icon">${icon(card.icon)}</span>
            <div>
              <small>${esc(card.label)}</small>
              <strong>${esc(card.value)}</strong>
              <em>${esc(card.detail)}</em>
            </div>
          </article>
        `,
          )
          .join('')}
      </div>
    `;
  }

  function renderStatusPill(status) {
    return `<span class="leave-status-pill is-${esc(status || 'approved')}">${esc(statusLabel(status))}</span>`;
  }

  function workflowLeaves() {
    const leaves = state.data?.leaves || [];
    const ownEmployee = currentEmployee();

    if (canManage()) {
      return leaves
        .filter((leave) => ['pending', 'planned'].includes(leave.status))
        .sort((a, b) => a.startDate.localeCompare(b.startDate) || a.employeeName.localeCompare(b.employeeName));
    }

    if (!ownEmployee) return [];

    return leaves
      .filter((leave) => Number(leave.employeeId) === Number(ownEmployee.id))
      .sort((a, b) => b.startDate.localeCompare(a.startDate))
      .slice(0, 6);
  }

  function renderWorkflowPanel() {
    const list = workflowLeaves();
    const title = canManage() ? 'Demandes à valider' : 'Mes demandes';
    const subtitle = canManage()
      ? 'Validation direction, refus ou modification avant affichage définitif.'
      : 'Suivez les demandes déposées et leur statut de validation.';

    return `
      <section class="leave-card leave-workflow-card">
        <div class="leave-card-head">
          <div>
            <h2 class="leave-card-title">${esc(title)}</h2>
            <p class="leave-card-subtitle">${esc(subtitle)}</p>
          </div>
          ${canCreateRequest() ? `<button type="button" class="leave-add-day" data-add-request>${canManage() ? '+ Ajouter' : '+ Poser'}</button>` : ''}
        </div>
        <div class="leave-request-list">
          ${
            list.length
              ? list
                  .map((leave) => {
                    const meta = typeMeta(leave.type);
                    const color = normalizeColor(meta.color, '#38bdf8');
                    const range =
                      leave.startDate === leave.endDate
                        ? dateLabel(leave.startDate)
                        : `${dateLabel(leave.startDate)} au ${dateLabel(leave.endDate)}`;
                    const editable = canEditLeave(leave);
                    const canReview = canManage() && ['pending', 'planned'].includes(leave.status);

                    return `
                      <article class="leave-request-row" style="--request-color:${esc(color)}">
                        <button type="button" class="leave-request-main" data-edit-leave="${leave.id}">
                          <span class="leave-request-dot"></span>
                          <span class="leave-request-text">
                            <strong>${esc(leave.employeeName)}</strong>
                            <small>${esc(meta.label)} - ${esc(periodLabel(leave.period))} - ${esc(range)}</small>
                          </span>
                          ${renderStatusPill(leave.status)}
                        </button>
                        <div class="leave-request-actions">
                          ${
                            canReview
                              ? `
                                <button type="button" class="leave-icon-action is-approve" data-approve="${leave.id}" aria-label="Valider">✓</button>
                                <button type="button" class="leave-icon-action is-refuse" data-refuse="${leave.id}" aria-label="Refuser">×</button>
                              `
                              : editable
                                ? '<span class="leave-request-hint">modifiable</span>'
                                : '<span class="leave-request-hint">lecture</span>'
                          }
                        </div>
                      </article>
                    `;
                  })
                  .join('')
              : `<div class="leave-day-empty">${canManage() ? 'Aucune demande en attente.' : 'Aucune demande enregistrée pour le moment.'}</div>`
          }
        </div>
      </section>
    `;
  }

  function renderTeamSheet() {
    const period = wallPeriodBounds();
    const leaves = filteredLeaves().filter((leave) => {
      const first = period.first;
      const last = period.last;

      return leave.startDate <= last && leave.endDate >= first;
    });
    const days = wallVisibleDays();
    const monthGroups = wallMonthGroups(days);
    const rows = exportEmployees(leaves);
    const countEnd = rows.length ? rows.length : 0;

    return `
      <section class="leave-card leave-team-board" aria-label="Planning équipe">
        <div class="leave-team-toolbar">
          <div class="leave-view-mode leave-period-mode">
            <button type="button" class="${state.wallMode === 'day' ? 'is-active' : ''}" data-wall-mode="day">Jour</button>
            <button type="button" class="${state.wallMode === 'week' ? 'is-active' : ''}" data-wall-mode="week">Semaine</button>
            <button type="button" class="${state.wallMode === 'month' ? 'is-active' : ''}" data-wall-mode="month">Mois</button>
          </div>
          <div class="leave-year-nav">
            <button type="button" data-wall-prev aria-label="Période précédente">‹</button>
            <strong>${parseDate(state.wallStartDate).getFullYear()}</strong>
            <button type="button" data-wall-next aria-label="Période suivante">›</button>
          </div>
          <div class="leave-wall-actions">
            <span class="leave-filter-chip">Statut : ${esc(statusLabel(state.filters.status === 'active' ? 'approved' : state.filters.status))}</span>
            ${canExport() ? '<button type="button" class="leaves-button" data-export-pdf>Exporter</button>' : ''}
            ${canCreateRequest() ? '<button type="button" class="leaves-button leaves-button-primary" data-add-request>+ Demander une absence</button>' : ''}
            <button type="button" class="leaves-button" data-wall-today>Aujourd'hui</button>
          </div>
        </div>
        ${renderTeamFilters()}
        <div class="leave-team-rangebar">
          <span>${rows.length ? `1-${countEnd}` : '0'} sur ${employees().length}</span>
          <strong>Du ${esc(dateLabel(period.first))} au ${esc(dateLabel(period.last))}</strong>
          <em>↕ Date</em>
        </div>
        <div class="leave-team-scroll">
          <table class="leave-team-timeline is-${esc(state.wallMode)}" style="--day-count:${days.length}">
            <colgroup>
              <col class="leave-team-user-col">
              ${days.map(() => '<col class="leave-team-day-col">').join('')}
            </colgroup>
            <thead>
              <tr class="leave-team-month-head">
                <th></th>
                ${monthGroups
                  .map((group) => `<th colspan="${group.days.length}">${esc(wallMonthTitle(group.month).toLocaleLowerCase('fr-FR'))}</th>`)
                  .join('')}
              </tr>
              <tr>
                <th></th>
                ${days
                  .map(
                    (day, index) => `
                      <th class="${teamDayClass(day, index)}">
                        <span>${esc(weekdayLetter(day))}</span>
                        <strong>${day.getDate()}</strong>
                      </th>
                    `,
                  )
                  .join('')}
              </tr>
            </thead>
            <tbody>
              ${
                rows.length
                  ? rows
                      .map(
                        (employee) => `
                          <tr>
                            <th>
                              <span class="leave-team-person">
                                ${employeeAvatar(employee, 'leave-team-avatar')}
                                <strong>${esc(employee.name)}</strong>
                              </span>
                            </th>
                            ${days
                              .map((day, index) => {
                                const leave = wallLeavesForDate(employee, day, leaves)[0];
                                const meta = leave ? typeMeta(leave.type) : null;
                                const color = leave ? normalizeColor(meta.color, '#7dd3fc') : '';
                                const rangeClass = leave ? teamRangeClass(leave, day) : '';
                                return `
                                  <td class="${teamDayClass(day, index)}">
                                    ${
                                      leave
                                        ? `<button type="button" class="leave-team-pill ${esc(rangeClass)} ${leave.status === 'pending' ? 'is-pending' : ''}" data-edit-leave="${esc(leave.id)}" style="--absence-color:${esc(color)}" title="${esc(`${employee.name} - ${meta.label}`)}"></button>`
                                        : ''
                                    }
                                  </td>
                                `;
                              })
                              .join('')}
                          </tr>
                        `,
                      )
                      .join('')
                  : `
                    <tr>
                      <th>Aucun membre</th>
                      ${days.map((day, index) => `<td class="${teamDayClass(day, index)}"></td>`).join('')}
                    </tr>
                  `
              }
            </tbody>
          </table>
        </div>
        ${renderExportLegend(monthReportLeaves())}
      </section>
    `;
  }

  function userDays(employeeId) {
    const first = `${state.month.getFullYear()}-01-01`;
    const last = `${state.month.getFullYear()}-12-31`;
    return (state.data?.leaves || [])
      .filter((leave) => Number(leave.employeeId) === Number(employeeId))
      .filter((leave) => leave.status !== 'refused' && leave.startDate <= last && leave.endDate >= first)
      .reduce((sum, leave) => sum + overlapDays(leave, first, last), 0);
  }

  function renderUsers() {
    const selectedId = selectedEmployee()?.id || 'all';
    return `
      <section class="leave-users-card">
        <div class="leave-users-head">
          <div>
            <h2 class="leave-users-title">Utilisateurs</h2>
            <p class="leave-users-site">Comptes HUB du site ${esc(activeSiteName())}</p>
          </div>
          <span class="leave-users-count">${employees().length}</span>
        </div>
        <div class="leave-users-grid">
          ${employees()
            .map((employee) => {
              const days = userDays(employee.id);
              return `
              <button type="button" class="leave-user-row ${Number(selectedId) === Number(employee.id) ? 'is-active' : ''}" data-user-id="${employee.id}" style="--user-color:${esc(normalizeColor(employee.color, '#38bdf8'))}">
                ${employeeAvatar(employee, 'leave-user-dot')}
                <span class="leave-user-name">${esc(employee.name)}</span>
                <span class="leave-user-count">${formatDaysCount(days)}</span>
              </button>
            `;
            })
            .join('')}
          <button type="button" class="leave-user-row leave-all-users ${selectedId === 'all' ? 'is-active' : ''}" data-user-id="all" style="--user-color:#a4a9b2">
            <span class="leave-user-dot"></span>
            <span class="leave-user-name">Tous</span>
            <span class="leave-user-count">${formatDaysCount(yearLeaves().reduce((sum, leave) => sum + daysCount(leave), 0))}</span>
          </button>
        </div>
      </section>
    `;
  }

  function renderBalancesPanel() {
    const rows = employees().map((employee) => {
      const used = yearApprovedDaysForEmployee(employee.id);
      const pending = yearPendingDaysForEmployee(employee.id);
      const total = used + pending;
      return { employee, used, pending, total, remaining: Math.max(0, 25 - total) };
    });

    return `
      <section class="leave-card leave-balance-board">
        <div class="leave-card-head">
          <div>
            <h2 class="leave-card-title">Soldes</h2>
            <p class="leave-card-subtitle">Vue annuelle par membre, demandes validées et en attente.</p>
          </div>
          ${renderTopMetrics()}
        </div>
        <div class="leave-balance-table-wrap">
          <table class="leave-balance-table">
            <thead>
              <tr>
                <th>Membre</th>
                <th>Disponibles</th>
                <th>Utilises</th>
                <th>En attente</th>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              ${rows
                .map(
                  (row) => `
                    <tr>
                      <td>
                        <span class="leave-team-person">
                          ${employeeAvatar(row.employee, 'leave-team-avatar')}
                          <strong>${esc(row.employee.name)}</strong>
                        </span>
                      </td>
                      <td>${esc(formatDaysCount(row.remaining))}j</td>
                      <td>${esc(formatDaysCount(row.used))}j</td>
                      <td>${esc(formatDaysCount(row.pending))}j</td>
                      <td>${esc(formatDaysCount(row.total))}j</td>
                    </tr>
                  `,
                )
                .join('')}
            </tbody>
          </table>
        </div>
      </section>
    `;
  }

  function renderSettingsPanel() {
    const query = state.filters.query.trim().toLowerCase();
    const types = (state.data?.types || []).filter((type) => !query || String(type.label || '').toLowerCase().includes(query));

    return `
      <section class="leave-card leave-settings-board">
        <div class="leave-settings-head">
          <div>
            <h2>Types d'absence</h2>
            <p>Créez, configurez et choisissez une couleur pour les différents types d'absences.</p>
          </div>
          <button type="button" class="leaves-button leaves-button-primary">+ Nouveau type</button>
        </div>
        <div class="leave-search-field leave-settings-search">
          <span aria-hidden="true">⌕</span>
          <input type="search" data-filter-query value="${esc(state.filters.query)}" placeholder="Rechercher">
        </div>
        <div class="leave-settings-table-wrap">
          <table class="leave-settings-table">
            <thead>
              <tr>
                <th>Nom</th>
                <th>Couleur</th>
                <th>Solde à consommer</th>
                <th>Approbation requise</th>
                <th>Envoyer des rappels</th>
              </tr>
            </thead>
            <tbody>
              ${types
                .map((type) => {
                  const total = typeTotal(type.value);
                  return `
                    <tr>
                      <td>${esc(type.label)}</td>
                      <td><span class="leave-color-swatch" style="--type-color:${esc(normalizeColor(type.color, '#38bdf8'))}"></span></td>
                      <td>${total > 0 ? 'Oui' : 'Non'}</td>
                      <td>${type.value === 'conge' || type.value === 'absence' ? 'Oui' : 'Non'}</td>
                      <td>${type.value === 'conge' || type.value === 'absence' ? 'Oui' : 'Non'}</td>
                    </tr>
                  `;
                })
                .join('')}
            </tbody>
          </table>
        </div>
      </section>
    `;
  }

  function renderRequestsTable() {
    const rows = requestList();

    return `
      <section class="leave-card leave-requests-table-card">
        <div class="leave-card-head">
          <div>
            <h2 class="leave-card-title">Toutes les demandes</h2>
            <p class="leave-card-subtitle">${rows.length} demande(s) avec les filtres actuels.</p>
          </div>
          ${canCreateRequest() ? '<button type="button" class="leave-add-day" data-add-request>+ Demande</button>' : ''}
        </div>
        <div class="leave-requests-table-wrap">
          <table class="leave-requests-table">
            <thead>
              <tr>
                <th>Membre</th>
                <th>Type</th>
                <th>Période</th>
                <th>Journée</th>
                <th>Statut</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              ${
                rows.length
                  ? rows
                      .map((leave) => {
                        const meta = typeMeta(leave.type);
                        const range =
                          leave.startDate === leave.endDate
                            ? dateLabel(leave.startDate)
                            : `${dateLabel(leave.startDate)} - ${dateLabel(leave.endDate)}`;
                        const employee = employeeForLeave(leave);

                        return `
                          <tr>
                            <td>
                              <span class="leave-table-person">
                                ${employeeAvatar(employee || { name: leave.employeeName, color: leave.employeeColor }, 'leave-table-avatar')}
                                <strong>${esc(leave.employeeName)}</strong>
                              </span>
                            </td>
                            <td>${esc(meta.label)}</td>
                            <td>${esc(range)}</td>
                            <td>${esc(periodLabel(leave.period))}</td>
                            <td>${renderStatusPill(leave.status)}</td>
                            <td><button type="button" class="leaves-button leaves-button-small" data-edit-leave="${esc(leave.id)}">Voir</button></td>
                          </tr>
                        `;
                      })
                      .join('')
                  : '<tr><td colspan="6"><div class="leave-day-empty">Aucune demande ne correspond aux filtres.</div></td></tr>'
              }
            </tbody>
          </table>
        </div>
      </section>
    `;
  }

  function renderReportsPanel() {
    const leaves = filteredLeaves().filter((leave) => leave.status !== 'refused');
    const approved = leaves.filter((leave) => leave.status === 'approved').reduce((sum, leave) => sum + daysCount(leave), 0);
    const pending = leaves.filter((leave) => leave.status === 'pending').reduce((sum, leave) => sum + daysCount(leave), 0);
    const people = new Set(leaves.map((leave) => Number(leave.employeeId))).size;

    return `
      <section class="leave-card leave-reporting-card">
        <div class="leave-card-head">
          <div>
            <h2 class="leave-card-title">Rapports & exports</h2>
            <p class="leave-card-subtitle">Synthèse instantanée selon les filtres, puis export PDF avec choix des membres.</p>
          </div>
          ${canExport() ? `<button type="button" class="leaves-button leaves-button-export" data-export-pdf>
            <span aria-hidden="true">PDF</span>
            <span>Exporter</span>
          </button>` : ''}
        </div>
        <div class="leave-reporting-grid">
          <article><strong>${esc(formatDaysCount(approved))} j</strong><span>validés</span></article>
          <article><strong>${esc(formatDaysCount(pending))} j</strong><span>à valider</span></article>
          <article><strong>${esc(people)}</strong><span>membre(s)</span></article>
        </div>
        <div class="leave-reporting-preview leave-wall-scroll">
          ${renderWallPlanning(leaves)}
        </div>
      </section>
    `;
  }

  function renderMainView() {
    if (state.view === 'team') {
      if (!canViewTeam()) return renderCalendar();

      return `
        <div class="leave-main-column">
          ${renderTeamSheet()}
        </div>
      `;
    }

    if (state.view === 'balances') {
      if (!canViewBalances()) return renderCalendar();

      return `
        <div class="leave-main-column">
          ${renderBalancesPanel()}
        </div>
      `;
    }

    if (state.view === 'requests') {
      if (!canViewRequests()) return renderCalendar();

      return `
        <div class="leave-main-column">
          ${renderWorkflowPanel()}
          ${renderRequestsTable()}
        </div>
      `;
    }

    if (state.view === 'reports') {
      if (!canViewReports()) return renderCalendar();

      return `
        <div class="leave-main-column">
          ${renderReportsPanel()}
        </div>
      `;
    }

    if (state.view === 'settings') {
      if (!canManageSettings()) return renderCalendar();

      return `
        <div class="leave-main-column">
          ${renderSettingsPanel()}
        </div>
      `;
    }

    return `
      <div class="leave-main-column">
        ${renderCalendar()}
      </div>
    `;
  }

  function renderModal() {
    if (!state.modal) return '';
    const form = state.modal;
    const manager = canManage();
    const readonly = Boolean(form.readonly);
    const disabled = readonly ? 'disabled' : '';
    const employee = employees().find((item) => Number(item.id) === Number(form.employeeId));
    const title = form.id ? (readonly ? 'Détail de la demande' : 'Modifier la demande') : 'Poser une demande';
    const subtitle = form.id
      ? `${employee?.name || 'Utilisateur'} - ${statusLabel(form.status)}`
      : manager
        ? 'Ajout direction ou création pour un membre.'
        : 'La demande sera envoyée à la direction pour validation.';
    const employeeField = manager
      ? `
          <div class="leaves-field leaves-field-full">
            <label>Utilisateur</label>
            <select name="employeeId" required ${disabled}>${employees()
              .map(
                (item) =>
                  `<option value="${item.id}" ${Number(form.employeeId) === Number(item.id) ? 'selected' : ''}>${esc(item.name)}</option>`,
              )
              .join('')}</select>
          </div>
        `
      : `
          <input type="hidden" name="employeeId" value="${esc(form.employeeId || '')}">
          <div class="leaves-field leaves-field-full">
            <label>Utilisateur</label>
            <div class="leave-person-card">
              ${employeeAvatar(employee, 'leave-user-dot', 'Votre compte')}
              <strong>${esc(employee?.name || 'Votre compte')}</strong>
              ${renderStatusPill(form.status)}
            </div>
          </div>
        `;
    const statusField = manager
      ? `<div class="leaves-field"><label>Statut</label><select name="status" ${disabled}><option value="approved" ${form.status === 'approved' ? 'selected' : ''}>Validé</option><option value="planned" ${form.status === 'planned' ? 'selected' : ''}>Planifié</option><option value="pending" ${form.status === 'pending' ? 'selected' : ''}>À valider</option><option value="refused" ${form.status === 'refused' ? 'selected' : ''}>Refusé</option></select></div>`
      : `<input type="hidden" name="status" value="${esc(form.status || 'pending')}">`;
    const reviewActions =
      form.canReview && !readonly
        ? `
          <button type="button" class="leaves-button leaves-button-approve" data-modal-approve="${esc(form.id)}">Valider</button>
          <button type="button" class="leaves-button leaves-button-refuse" data-modal-refuse="${esc(form.id)}">Refuser</button>
        `
        : '';
    const saveAction = readonly
      ? ''
      : `<button type="submit" class="leaves-button leaves-button-primary">${form.id ? 'Enregistrer' : manager ? 'Ajouter' : 'Envoyer la demande'}</button>`;
    const deleteAction = form.canDelete
      ? `<button type="button" class="leaves-button" data-delete="${esc(form.id)}">Supprimer</button>`
      : '';

    return `
      <div class="leaves-modal-backdrop" data-modal-backdrop>
        <div class="leaves-modal">
          <div class="leaves-modal-head">
            <div>
              <strong>${esc(title)}</strong>
              <p>${esc(subtitle)}</p>
            </div>
            <button type="button" class="leaves-button" data-close-modal>Fermer</button>
          </div>
          <form class="leaves-form-grid" data-leave-form>
            <input type="hidden" name="id" value="${esc(form.id || '')}">
            ${employeeField}
            <div class="leaves-field"><label>Début</label><input type="date" name="startDate" value="${esc(form.startDate)}" required ${disabled}></div>
            <div class="leaves-field"><label>Fin</label><input type="date" name="endDate" value="${esc(form.endDate)}" required ${disabled}></div>
            <div class="leaves-field"><label>Type</label><select name="type" ${disabled}>${(state.data?.types || []).map((type) => `<option value="${esc(type.value)}" ${form.type === type.value ? 'selected' : ''}>${esc(type.label)}</option>`).join('')}</select></div>
            <div class="leaves-field"><label>Journée</label><select name="period" ${disabled}>${(state.data?.periods || []).map((period) => `<option value="${esc(period.value)}" ${form.period === period.value ? 'selected' : ''}>${esc(period.label)}</option>`).join('')}</select></div>
            ${statusField}
            <div class="leaves-field leaves-field-full"><label>Notes</label><textarea name="notes" ${disabled}>${esc(form.notes || '')}</textarea></div>
            <div class="leaves-actions leaves-field-full">${reviewActions}${saveAction}${deleteAction}</div>
          </form>
        </div>
      </div>
    `;
  }

  function renderExportModal() {
    if (!state.exportModal) return '';

    const form = state.exportModal;
    const canIncludeOtherSites = Boolean(state.data?.user?.canExportOtherSites || state.data?.export?.canIncludeOtherSites);
    const employeeIds = new Set(form.employeeIds.map(Number));
    const allSelected = form.employees.length > 0 && form.employees.every((employee) => employeeIds.has(Number(employee.id)));
    const disabled = form.loading || form.downloading;

    return `
      <div class="leaves-modal-backdrop" data-export-modal-backdrop>
        <div class="leaves-modal leaves-export-modal">
          <div class="leaves-modal-head">
            <div>
              <strong>Exporter le planning PDF</strong>
              <p>Choisissez la plage de dates et les membres a inclure.</p>
            </div>
            <button type="button" class="leaves-button" data-close-export-modal>Fermer</button>
          </div>
          <form class="leaves-form-grid" data-export-form>
            <div class="leaves-field">
              <label>Debut</label>
              <input type="date" name="fromDate" value="${esc(form.fromDate)}" required ${disabled ? 'disabled' : ''}>
            </div>
            <div class="leaves-field">
              <label>Fin</label>
              <input type="date" name="toDate" value="${esc(form.toDate)}" required ${disabled ? 'disabled' : ''}>
            </div>
            ${
              canIncludeOtherSites
                ? `
                  <label class="leaves-check leaves-field-full">
                    <input type="checkbox" name="includeOtherSites" ${form.includeOtherSites ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
                    <span>Inclure les membres des autres sites autorises</span>
                  </label>
                `
                : ''
            }
            <div class="leaves-export-members leaves-field-full">
              <div class="leaves-export-members-head">
                <label>Membres</label>
                <button type="button" class="leaves-button leaves-button-small" data-export-select-all ${disabled ? 'disabled' : ''}>
                  ${allSelected ? 'Tout enlever' : 'Tout selectionner'}
                </button>
              </div>
              <div class="leaves-export-member-list">
                ${
                  form.loading
                    ? '<div class="leave-day-empty">Chargement des membres...</div>'
                    : form.employees
                        .map((employee) => {
                          const sites = (employee.siteNames || []).join(', ');
                          return `
                            <label class="leaves-export-member">
                              <input type="checkbox" data-export-employee value="${esc(employee.id)}" ${employeeIds.has(Number(employee.id)) ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
                              <span>
                                <strong>${esc(employee.name)}</strong>
                                ${sites ? `<small>${esc(sites)}</small>` : ''}
                              </span>
                            </label>
                          `;
                        })
                        .join('')
                }
              </div>
            </div>
            ${form.error ? `<div class="leaves-notice leaves-field-full">${esc(form.error)}</div>` : ''}
            <div class="leaves-actions leaves-field-full">
              <button type="submit" class="leaves-button leaves-button-primary" ${disabled || !form.employeeIds.length ? 'disabled' : ''}>
                ${form.downloading ? 'Generation...' : 'Telecharger le PDF'}
              </button>
            </div>
          </form>
        </div>
      </div>
    `;
  }

  function render() {
    if (!canRender()) return;

    syncEmployeeFilter();
    ensureAllowedView();
    root.innerHTML = `
      <div class="leaves-page">
        ${renderHeader()}
        <div class="leave-hr-layout ${state.view === 'calendar' ? '' : 'is-full'}">
          ${state.view === 'calendar' ? renderBalancePanel() : ''}
          <main class="leave-work-area">
            ${['calendar', 'team'].includes(state.view) ? '' : renderFilters()}
            ${renderMainView()}
          </main>
        </div>
        ${renderModal()}
        ${renderExportModal()}
      </div>
    `;
    styles();
    bind();
  }

  function bind() {
    root.querySelector('[data-export-pdf]')?.addEventListener('click', exportPdf);
    root.querySelectorAll('[data-add-request]').forEach((button) =>
      button.addEventListener('click', () => openModal(null)),
    );
    root.querySelectorAll('[data-view]').forEach((button) =>
      button.addEventListener('click', () => {
        const nextView = button.dataset.view || 'calendar';
        if (!allowedViewKeys().includes(nextView)) return;

        state.view = nextView;
        render();
      }),
    );
    root.querySelector('[data-filter-focus]')?.addEventListener('click', () => {
      root.querySelector('[data-filter-query]')?.focus();
    });
    root.querySelectorAll('[data-filter-query]').forEach((input) =>
      input.addEventListener('input', (event) => {
        state.filters.query = event.currentTarget.value || '';
        if (filterRenderTimer) window.clearTimeout(filterRenderTimer);
        filterRenderTimer = window.setTimeout(() => {
          filterRenderTimer = null;
          render();
        }, 220);
      }),
    );
    root.querySelectorAll('[data-filter-employee]').forEach((select) =>
      select.addEventListener('change', (event) => {
        state.filters.employeeId = event.currentTarget.value || 'all';
        render();
      }),
    );
    root.querySelectorAll('[data-filter-type]').forEach((select) =>
      select.addEventListener('change', (event) => {
        state.filters.type = event.currentTarget.value || 'all';
        render();
      }),
    );
    root.querySelectorAll('[data-filter-status]').forEach((select) =>
      select.addEventListener('change', (event) => {
        state.filters.status = event.currentTarget.value || 'active';
        render();
      }),
    );
    root.querySelectorAll('[data-filter-reset]').forEach((button) =>
      button.addEventListener('click', () => {
        state.filters = {
          employeeId: 'all',
          type: 'all',
          status: 'active',
          query: '',
        };
        render();
      }),
    );
    root.querySelectorAll('[data-type-filter]').forEach((button) =>
      button.addEventListener('click', () => {
        state.filters.type = button.dataset.typeFilter || 'all';
        render();
      }),
    );
    root.querySelector('[data-prev]')?.addEventListener('click', () => {
      state.month = new Date(state.month.getFullYear() - 1, state.month.getMonth(), 1);
      render();
    });
    root.querySelector('[data-next]')?.addEventListener('click', () => {
      state.month = new Date(state.month.getFullYear() + 1, state.month.getMonth(), 1);
      render();
    });
    root.querySelector('[data-today]')?.addEventListener('click', () => {
      const today = new Date();
      state.month = new Date(today.getFullYear(), today.getMonth(), 1);
      state.selectedDate = formatDate(today);
      render();
    });
    root.querySelector('[data-focus-selected]')?.addEventListener('click', () => {
      root.querySelector('.leave-day-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    root.querySelectorAll('[data-wall-prev]').forEach((button) =>
      button.addEventListener('click', () => {
        moveWallPeriod(-1);
      }),
    );
    root.querySelectorAll('[data-wall-next]').forEach((button) =>
      button.addEventListener('click', () => {
        moveWallPeriod(1);
      }),
    );
    root.querySelectorAll('[data-wall-today]').forEach((button) =>
      button.addEventListener('click', () => {
        state.wallStartDate = formatDate(wallModeStartDate(state.wallMode, new Date()));
        render();
      }),
    );
    root.querySelectorAll('[data-wall-mode]').forEach((button) =>
      button.addEventListener('click', () => {
        setWallMode(button.dataset.wallMode || 'month');
      }),
    );
    root.querySelectorAll('[data-user-id]').forEach((button) =>
      button.addEventListener('click', () => {
        state.filters.employeeId = button.dataset.userId || 'all';
        render();
      }),
    );
    root.querySelectorAll('[data-add-day]').forEach((button) =>
      button.addEventListener('click', () =>
        openModal({
          employeeId: selectedEmployee()?.id || employees()[0]?.id,
          startDate: state.selectedDate,
          endDate: state.selectedDate,
        }),
      ),
    );
    root.querySelectorAll('[data-day]').forEach((button) =>
      button.addEventListener('click', () => {
        state.selectedDate = button.dataset.date || state.selectedDate;
        render();
      }),
    );
    root.querySelectorAll('[data-edit-leave]').forEach((button) =>
      button.addEventListener('click', () => {
        const leave = state.data.leaves.find((item) => Number(item.id) === Number(button.dataset.editLeave));
        if (leave) openModal(leave);
      }),
    );
    root.querySelectorAll('[data-approve]').forEach((button) =>
      button.addEventListener('click', () => reviewLeave(button.dataset.approve, true)),
    );
    root.querySelectorAll('[data-refuse]').forEach((button) =>
      button.addEventListener('click', () => reviewLeave(button.dataset.refuse, false)),
    );
    root.querySelector('[data-modal-approve]')?.addEventListener('click', (event) => {
      reviewLeave(event.currentTarget.dataset.modalApprove, true);
    });
    root.querySelector('[data-modal-refuse]')?.addEventListener('click', (event) => {
      reviewLeave(event.currentTarget.dataset.modalRefuse, false);
    });
    root.querySelector('[data-close-modal]')?.addEventListener('click', () => {
      state.modal = null;
      render();
    });
    root.querySelector('[data-modal-backdrop]')?.addEventListener('click', (event) => {
      if (event.target === event.currentTarget) {
        state.modal = null;
        render();
      }
    });
    root.querySelector('[data-close-export-modal]')?.addEventListener('click', () => {
      state.exportModal = null;
      render();
    });
    root.querySelector('[data-export-modal-backdrop]')?.addEventListener('click', (event) => {
      if (event.target === event.currentTarget) {
        state.exportModal = null;
        render();
      }
    });
    root.querySelector('[name="includeOtherSites"]')?.addEventListener('change', (event) => {
      loadExportOptions(Boolean(event.currentTarget.checked));
    });
    root.querySelector('[data-export-select-all]')?.addEventListener('click', () => {
      if (!state.exportModal) return;

      const allIds = state.exportModal.employees.map((employee) => Number(employee.id));
      const allSelected = allIds.length > 0 && allIds.every((id) => state.exportModal.employeeIds.map(Number).includes(id));
      state.exportModal.employeeIds = allSelected ? [] : allIds;
      render();
    });
    root.querySelectorAll('[data-export-employee]').forEach((input) =>
      input.addEventListener('change', () => {
        if (!state.exportModal) return;

        state.exportModal.employeeIds = Array.from(root.querySelectorAll('[data-export-employee]:checked')).map((item) =>
          Number(item.value),
        );
        render();
      }),
    );
    root.querySelector('[data-export-form]')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!state.exportModal) return;

      const form = Object.fromEntries(new FormData(event.currentTarget).entries());
      state.exportModal.fromDate = String(form.fromDate || state.exportModal.fromDate);
      state.exportModal.toDate = String(form.toDate || state.exportModal.toDate);
      await downloadExportPdf();
    });
    root.querySelector('[data-export-form] input[name="fromDate"]')?.addEventListener('change', (event) => {
      if (!state.exportModal) return;
      state.exportModal.fromDate = event.currentTarget.value;
    });
    root.querySelector('[data-export-form] input[name="toDate"]')?.addEventListener('change', (event) => {
      if (!state.exportModal) return;
      state.exportModal.toDate = event.currentTarget.value;
    });
    root.querySelector('[data-leave-form]')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const payload = Object.fromEntries(new FormData(event.currentTarget).entries());
      payload.id = payload.id ? Number(payload.id) : undefined;
      payload.employeeId = Number(payload.employeeId);
      try {
        await request('save_leave', payload);
        state.modal = null;
        await refreshData();
        render();
      } catch (error) {
        alert(error instanceof Error ? error.message : 'Enregistrement impossible');
      }
    });
    root.querySelector('[data-delete]')?.addEventListener('click', async (event) => {
      if (!confirm('Supprimer ce conge ?')) return;
      try {
        await request('delete_leave', { id: Number(event.currentTarget.dataset.delete) });
        state.modal = null;
        await refreshData();
        render();
      } catch (error) {
        alert(error instanceof Error ? error.message : 'Suppression impossible');
      }
    });
  }

  function boot(rootNode) {
    if (!rootNode) return false;

    if (!isLeavesRoute()) {
      teardown();
      return false;
    }

    if (mountedRoots.has(rootNode)) {
      hostNode = rootNode;
      root = rootNode.shadowRoot || root;
      return true;
    }

    if (mountTimer) {
      window.clearTimeout(mountTimer);
      mountTimer = null;
    }

    root = rootNode.shadowRoot || rootNode.attachShadow({ mode: 'open' });
    hostNode = rootNode;
    mountedRoots.add(rootNode);
    load();
    return true;
  }

  function isLeavesRoute() {
    return window.location.pathname.replace(/\/$/, '') === '/conges';
  }

  function canRender() {
    return Boolean(root && hostNode && isLeavesRoute() && document.getElementById(rootId) === hostNode);
  }

  function teardown() {
    if (mountTimer) {
      window.clearTimeout(mountTimer);
      mountTimer = null;
    }
    if (filterRenderTimer) {
      window.clearTimeout(filterRenderTimer);
      filterRenderTimer = null;
    }

    const host = hostNode || document.getElementById(rootId);

    if (host) {
      mountedRoots.delete(host);
      host.remove();
    }

    root = null;
    hostNode = null;
  }

  function scheduleBoot(reset = false) {
    if (reset) mountAttempts = 0;
    if (mountTimer) window.clearTimeout(mountTimer);

    if (!isLeavesRoute()) {
      teardown();
      return;
    }

    if (boot(document.getElementById(rootId))) return;

    mountTimer = window.setTimeout(
      () => {
        mountTimer = null;
        if (!isLeavesRoute()) return;
        if (boot(document.getElementById(rootId))) return;

        mountAttempts += 1;
        if (mountAttempts < 18) scheduleBoot(false);
      },
      mountAttempts < 6 ? 60 : 150,
    );
  }

  window.addEventListener('popstate', () => scheduleBoot(true));
  window.addEventListener('crm:navigation', () => scheduleBoot(true));
  window.addEventListener('crm:route-changed', () => scheduleBoot(true));
  window.addEventListener(routeEvent, () => scheduleBoot(true));
  window.addEventListener('resize', () => {
    if (!canRender()) return;
    if (wallResizeTimer) window.clearTimeout(wallResizeTimer);
    wallResizeTimer = window.setTimeout(() => {
      wallResizeTimer = null;
      if (canRender()) render();
    }, 160);
  });
  window.addEventListener('crm:active-site-changed', () => {
    if (!isLeavesRoute() || !root) return;
    state.modal = null;
    state.filters.employeeId = 'all';
    load();
  });
  document.addEventListener('DOMContentLoaded', () => scheduleBoot(true));

  scheduleBoot(true);
})();

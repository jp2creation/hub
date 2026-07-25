<?php

namespace Tests\Feature;

use Tests\TestCase;

class CrmLeaveExportUiAssetTest extends TestCase
{
    public function test_leave_export_uses_a_monthly_spreadsheet_layout(): void
    {
        $source = (string) file_get_contents(base_path('Modules/CrmLeaves/resources/assets/crm-conges.js'));
        $public = (string) file_get_contents(public_path('modules/crm-leaves/crm-conges.js'));

        $this->assertSame($source, $public);
        $this->assertStringContainsString('Tableau des congés et absences', $public);
        $this->assertStringContainsString('class="pdf-planning-table"', $public);
        $this->assertStringContainsString('class="pdf-month-band"', $public);
        $this->assertStringContainsString('class="pdf-employee-cell"', $public);
        $this->assertStringContainsString('class="pdf-absence-chip', $public);
        $this->assertStringContainsString('function renderExportPlanning', $public);
        $this->assertStringContainsString('function leaveTypeCode', $public);
        $this->assertStringNotContainsString('class="pdf-calendar"', $public);
        $this->assertStringNotContainsString('function renderExportRows', $public);
    }

    public function test_leave_export_uses_server_pdf_download_with_member_selection(): void
    {
        $source = (string) file_get_contents(base_path('Modules/CrmLeaves/resources/assets/crm-conges.js'));
        $public = (string) file_get_contents(public_path('modules/crm-leaves/crm-conges.js'));

        $this->assertSame($source, $public);
        $this->assertStringContainsString('function renderExportModal()', $public);
        $this->assertStringContainsString("request('export_options'", $public);
        $this->assertStringContainsString("action: 'export_pdf'", $public);
        $this->assertStringContainsString('data-export-employee', $public);
        $this->assertStringContainsString('includeOtherSites', $public);
        $this->assertStringContainsString('Telecharger le PDF', $public);
        $this->assertStringNotContainsString('window.open(', $public);
    }

    public function test_leave_module_mounts_without_a_global_dom_observer(): void
    {
        $source = (string) file_get_contents(base_path('Modules/CrmLeaves/resources/assets/crm-conges.js'));
        $public = (string) file_get_contents(public_path('modules/crm-leaves/crm-conges.js'));

        $this->assertSame($source, $public);
        $this->assertStringContainsString("const rootId = 'crm-leaves-module';", $public);
        $this->assertStringContainsString("const routeEvent = 'crm:leaves-route-changed';", $public);
        $this->assertStringContainsString('function scheduleBoot(reset = false)', $public);
        $this->assertStringContainsString('mountAttempts < 18', $public);
        $this->assertStringContainsString("window.addEventListener('crm:navigation', () => scheduleBoot(true))", $public);
        $this->assertStringNotContainsString('history.pushState =', $public);
        $this->assertStringNotContainsString('history.replaceState =', $public);
        $this->assertStringNotContainsString('observer.observe(document.documentElement', $public);
        $this->assertStringNotContainsString('new MutationObserver(() => tryBoot())', $public);
    }

    public function test_leave_calendar_matches_the_hr_absence_layout(): void
    {
        $source = (string) file_get_contents(base_path('Modules/CrmLeaves/resources/assets/crm-conges.js'));
        $public = (string) file_get_contents(public_path('modules/crm-leaves/crm-conges.js'));

        $this->assertSame($source, $public);
        $this->assertStringContainsString('function renderTopMetrics()', $public);
        $this->assertStringContainsString('function renderYearCalendar()', $public);
        $this->assertStringContainsString('function renderMiniMonth(monthDate)', $public);
        $this->assertStringContainsString('class="leave-app-header"', $public);
        $this->assertStringContainsString('class="leave-stat-strip"', $public);
        $this->assertStringContainsString('class="leave-year-calendar"', $public);
        $this->assertStringContainsString('class="leave-mini-month"', $public);
        $this->assertStringContainsString('--leave-accent:#a30038', $public);
        $this->assertStringContainsString('is-range-start', $public);
        $this->assertStringContainsString('is-range-middle', $public);
        $this->assertStringContainsString('is-range-end', $public);
        $this->assertStringContainsString('.leave-mini-day.is-today', $public);
        $this->assertStringContainsString('color:var(--leave-accent)', $public);
        $this->assertStringContainsString('.leave-mini-day.has-leave.is-selected', $public);
        $this->assertStringContainsString('background:transparent;', $public);
        $this->assertStringContainsString('box-shadow:none;', $public);
        $this->assertStringNotContainsString('box-shadow:inset 0 0 0 1.5px var(--leave-accent)', $public);
        $this->assertStringContainsString('gap:.08rem 0', $public);
        $this->assertStringContainsString('border-right-width:0', $public);
        $this->assertStringContainsString('border-left-width:0', $public);
        $this->assertStringNotContainsString('<i aria-hidden="true"></i>', $public);
        $this->assertStringContainsString('Disponibles', $public);
        $this->assertStringContainsString('En attente', $public);
        $this->assertStringContainsString('class="leave-stat-card"', $public);
        $this->assertStringContainsString('class="leave-stat-icon"', $public);
        $this->assertStringNotContainsString('<em aria-hidden="true">i</em>', $public);
        $this->assertStringContainsString('${renderHeader()}', $public);
        $this->assertStringContainsString('${[\'calendar\', \'team\'].includes(state.view) ? \'\' : renderFilters()}', $public);
        $this->assertStringContainsString('leave-hr-layout ${state.view === \'calendar\' ? \'\' : \'is-full\'}', $public);
        $this->assertStringContainsString('${state.view === \'calendar\' ? renderBalancePanel() : \'\'}', $public);
        $this->assertStringContainsString('.leave-hr-layout.is-full', $public);
        $this->assertStringContainsString('${renderMainView()}', $public);
        $this->assertStringNotContainsString('Convention et calendrier', $public);
        $this->assertStringNotContainsString('Vos absences peuvent être ajoutées à votre calendrier.', $public);
        $this->assertStringNotContainsString('Lien iCal', $public);
        $this->assertStringNotContainsString('Absents le', $public);
        $this->assertStringNotContainsString('Selectionne une autre date ou pose une demande sur ce jour.', $public);
        $this->assertStringNotContainsString('Legende utilisateurs', $public);
        $this->assertStringNotContainsString('${renderSummary()}', $public);
        $this->assertStringNotContainsString('${renderUsers()}\\n            ${renderSummary()}', $public);
        $this->assertStringNotContainsString('<aside class="leave-side">', $public);
        $this->assertStringNotContainsString('${renderUsers()}\\n          </aside>', $public);
        $this->assertStringNotContainsString('grid-template-columns:minmax(0,1fr) minmax(22rem,28rem)', $public);
        $this->assertStringNotContainsString('grid-template-columns:minmax(0,1fr) minmax(21rem,25rem)', $public);
    }

    public function test_leave_ui_contains_request_workflow_and_review_actions(): void
    {
        $source = (string) file_get_contents(base_path('Modules/CrmLeaves/resources/assets/crm-conges.js'));
        $public = (string) file_get_contents(public_path('modules/crm-leaves/crm-conges.js'));

        $this->assertSame($source, $public);
        $this->assertStringContainsString('function renderWorkflowPanel()', $public);
        $this->assertStringContainsString('Demandes à valider', $public);
        $this->assertStringContainsString('Mes demandes', $public);
        $this->assertStringContainsString("request(approved ? 'approve_leave' : 'refuse_leave'", $public);
        $this->assertStringContainsString('data-approve', $public);
        $this->assertStringContainsString('data-refuse', $public);
        $this->assertStringContainsString('function renderTeamSheet()', $public);
        $this->assertStringContainsString('function renderTeamFilters()', $public);
        $this->assertStringContainsString('Planning équipe', $public);
        $this->assertStringContainsString('function renderWallPlanning', $public);
        $this->assertStringContainsString('wallStartDate: formatDate(new Date())', $public);
        $this->assertStringContainsString('function wallDayCount()', $public);
        $this->assertStringContainsString('function wallVisibleDays()', $public);
        $this->assertStringContainsString('function wallMonthGroups(days)', $public);
        $this->assertStringContainsString('function teamRangeClass(leave, day)', $public);
        $this->assertStringContainsString("wallMode: 'month'", $public);
        $this->assertStringContainsString('function setWallMode(mode)', $public);
        $this->assertStringContainsString('function moveWallPeriod(direction)', $public);
        $this->assertStringContainsString('function teamDayClass(day, index)', $public);
        $this->assertStringContainsString('data-wall-mode="day"', $public);
        $this->assertStringContainsString('data-wall-mode="week"', $public);
        $this->assertStringContainsString('data-wall-mode="month"', $public);
        $this->assertStringContainsString('data-wall-prev', $public);
        $this->assertStringContainsString('data-wall-today', $public);
        $this->assertStringContainsString('data-wall-next', $public);
        $this->assertStringContainsString("setWallMode(button.dataset.wallMode || 'month')", $public);
        $this->assertStringContainsString('${esc(weekdayLetter(day))}', $public);
        $this->assertStringContainsString('is-alternate', $public);
        $this->assertStringContainsString('class="leave-team-filter-strip"', $public);
        $this->assertStringContainsString('class="leave-team-rangebar"', $public);
        $this->assertStringContainsString('class="leave-team-month-head"', $public);
        $this->assertStringContainsString('class="leave-team-timeline is-${esc(state.wallMode)}"', $public);
        $this->assertStringContainsString('class="leave-team-pill', $public);
        $this->assertStringContainsString('is-range-start', $public);
        $this->assertStringContainsString('is-range-middle', $public);
        $this->assertStringContainsString('is-range-end', $public);
        $this->assertStringContainsString('data-edit-leave="${esc(primary.id)}"', $public);
    }

    public function test_leave_module_contains_hr_tabs_search_and_filters(): void
    {
        $source = (string) file_get_contents(base_path('Modules/CrmLeaves/resources/assets/crm-conges.js'));
        $public = (string) file_get_contents(public_path('modules/crm-leaves/crm-conges.js'));

        $this->assertSame($source, $public);
        $this->assertStringContainsString('function renderViewTabs()', $public);
        $this->assertStringContainsString('function ensureAllowedView()', $public);
        $this->assertStringContainsString('function canViewTeam()', $public);
        $this->assertStringContainsString('function employeeAvatar(employee', $public);
        $this->assertStringContainsString('function renderFilters()', $public);
        $this->assertStringContainsString('function renderBalancePanel()', $public);
        $this->assertStringContainsString('function renderBalancesPanel()', $public);
        $this->assertStringContainsString('function renderRequestsTable()', $public);
        $this->assertStringContainsString('function renderReportsPanel()', $public);
        $this->assertStringContainsString('function renderSettingsPanel()', $public);
        $this->assertStringContainsString('Congés &amp; Absences', $public);
        $this->assertStringContainsString('Mon calendrier', $public);
        $this->assertStringContainsString('Mon équipe', $public);
        $this->assertStringContainsString('Soldes', $public);
        $this->assertStringContainsString('Gestion', $public);
        $this->assertStringContainsString('canManageSettings()', $public);
        $this->assertStringContainsString('if (!allowedViewKeys().includes(nextView)) return;', $public);
        $this->assertStringContainsString('leave-profile-avatar', $public);
        $this->assertStringContainsString('leave-team-avatar', $public);
        $this->assertStringContainsString('leave-table-avatar', $public);
        $this->assertStringContainsString('photoUrl', $public);
        $this->assertStringContainsString("Types d'absence", $public);
        $this->assertStringContainsString('Approbation requise', $public);
        $this->assertStringContainsString('Envoyer des rappels', $public);
        $this->assertStringContainsString('Rechercher un membre, motif, statut...', $public);
        $this->assertStringContainsString('data-filter-query', $public);
        $this->assertStringContainsString('data-filter-employee', $public);
        $this->assertStringContainsString('data-filter-type', $public);
        $this->assertStringContainsString('data-filter-status', $public);
        $this->assertStringContainsString('data-filter-reset', $public);
        $this->assertStringContainsString('leave-hr-layout', $public);
        $this->assertStringContainsString('leave-side-panel', $public);
        $this->assertStringContainsString('leave-profile-card', $public);
        $this->assertStringContainsString('leave-requests-table', $public);
        $this->assertStringContainsString('leave-reporting-card', $public);
    }
}

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
        $this->assertStringContainsString('Disponibles', $public);
        $this->assertStringContainsString('En attente', $public);
        $this->assertStringContainsString('${renderHeader()}', $public);
        $this->assertStringContainsString('${renderFilters()}', $public);
        $this->assertStringContainsString('${renderBalancePanel()}', $public);
        $this->assertStringContainsString('${renderMainView()}', $public);
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
        $this->assertStringContainsString('Planning équipe', $public);
        $this->assertStringContainsString('function renderWallPlanning', $public);
        $this->assertStringContainsString('wallStartDate: formatDate(new Date())', $public);
        $this->assertStringContainsString('function wallDayCount()', $public);
        $this->assertStringContainsString('function wallVisibleDays()', $public);
        $this->assertStringContainsString('function wallMonthGroups(days)', $public);
        $this->assertStringContainsString('data-wall-prev', $public);
        $this->assertStringContainsString('data-wall-today', $public);
        $this->assertStringContainsString('data-wall-next', $public);
        $this->assertStringContainsString('class="leave-team-timeline"', $public);
        $this->assertStringContainsString('class="leave-team-pill', $public);
        $this->assertStringContainsString('data-edit-leave="${esc(primary.id)}"', $public);
    }

    public function test_leave_module_contains_hr_tabs_search_and_filters(): void
    {
        $source = (string) file_get_contents(base_path('Modules/CrmLeaves/resources/assets/crm-conges.js'));
        $public = (string) file_get_contents(public_path('modules/crm-leaves/crm-conges.js'));

        $this->assertSame($source, $public);
        $this->assertStringContainsString('function renderViewTabs()', $public);
        $this->assertStringContainsString('function renderFilters()', $public);
        $this->assertStringContainsString('function renderBalancePanel()', $public);
        $this->assertStringContainsString('function renderBalancesPanel()', $public);
        $this->assertStringContainsString('function renderRequestsTable()', $public);
        $this->assertStringContainsString('function renderReportsPanel()', $public);
        $this->assertStringContainsString('function renderSettingsPanel()', $public);
        $this->assertStringContainsString('Absences', $public);
        $this->assertStringContainsString('Mon calendrier', $public);
        $this->assertStringContainsString('Mon équipe', $public);
        $this->assertStringContainsString('Soldes', $public);
        $this->assertStringContainsString('Gestion', $public);
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

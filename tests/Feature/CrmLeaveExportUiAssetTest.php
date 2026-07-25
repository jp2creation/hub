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

    public function test_leave_summary_matches_the_native_crm_dashboard_cards(): void
    {
        $source = (string) file_get_contents(base_path('Modules/CrmLeaves/resources/assets/crm-conges.js'));
        $public = (string) file_get_contents(public_path('modules/crm-leaves/crm-conges.js'));

        $this->assertSame($source, $public);
        $this->assertStringContainsString('function renderSummary()', $public);
        $this->assertStringContainsString("label: 'Utilisateurs'", $public);
        $this->assertStringContainsString("label: 'A valider'", $public);
        $this->assertStringContainsString("label: 'Poses'", $public);
        $this->assertStringContainsString("label: 'Absences'", $public);
        $this->assertStringContainsString('class="leave-summary-icon"', $public);
        $this->assertStringContainsString('grid-template-columns:repeat(4,minmax(0,1fr))', $public);
        $this->assertStringContainsString('grid-template-columns:repeat(2,minmax(0,1fr));', $public);
        $this->assertStringContainsString('${renderHeader()}', $public);
        $this->assertStringContainsString('${renderSummary()}', $public);
        $this->assertStringContainsString('${renderWorkflowPanel()}', $public);
        $this->assertStringContainsString('${renderCalendar()}', $public);
        $this->assertStringContainsString('${renderSelectedDay()}', $public);
        $this->assertStringContainsString('${renderTeamSheet()}', $public);
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
        $this->assertStringContainsString('Planning global', $public);
        $this->assertStringContainsString('function renderWallPlanning', $public);
        $this->assertStringContainsString('class="leave-wall-table"', $public);
        $this->assertStringContainsString('class="leave-wall-zone ${isZoneA(day) ? \'is-zone-a\' : \'\'}"', $public);
        $this->assertStringContainsString('class="leave-wall-cell ${wallWeekendClass(day)} ${primary ? \'has-leave\' : \'\'}"', $public);
        $this->assertStringContainsString('data-edit-leave="${esc(primary.id)}"', $public);
    }
}

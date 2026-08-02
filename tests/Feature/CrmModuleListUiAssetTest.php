<?php

namespace Tests\Feature;

use Tests\TestCase;

class CrmModuleListUiAssetTest extends TestCase
{
    public function test_sales_visits_are_rendered_as_a_real_table(): void
    {
        $source = (string) file_get_contents(base_path('Modules/CrmSalesTours/resources/assets/crm-tournees-representants.js'));
        $public = (string) file_get_contents(public_path('modules/crm-sales-tours/crm-tournees-representants.js'));

        $this->assertSame($source, $public);
        $this->assertStringContainsString('class="visit-table-wrap"', $public);
        $this->assertStringContainsString('<table class="visit-table">', $public);
        $this->assertStringContainsString('<th>Client</th>', $public);
        $this->assertStringContainsString('<th>Contact</th>', $public);
        $this->assertStringContainsString('<th>Suite</th>', $public);
        $this->assertStringContainsString('class="visit-chip"', $public);
        $this->assertStringContainsString('"crm-leaves-module"', $public);
        $this->assertStringContainsString('const reclaimableHostIds = [', $public);
        $this->assertStringContainsString('return false;', $public);
        $this->assertStringNotContainsString('outlet.replaceWith(host)', $public);
        $this->assertStringNotContainsString('outlet.replaceChildren(host)', $public);
        $this->assertStringContainsString('html.crm-sales-tours-pending #crm-leaves-module', $public);
        $this->assertStringNotContainsString('class="visit-list"', $public);
        $this->assertStringNotContainsString('class="visit-card"', $public);
    }

    public function test_team_members_keep_adminex_like_contacts_table_across_widths(): void
    {
        $source = (string) file_get_contents(base_path('Modules/CrmTeams/resources/assets/crm-equipes.js'));
        $public = (string) file_get_contents(public_path('modules/crm-teams/crm-equipes.js'));

        $this->assertSame($source, $public);
        $this->assertStringContainsString('class="teams-table-wrap"', $public);
        $this->assertStringContainsString('<table class="teams-table" aria-label="Liste des membres">', $public);
        $this->assertStringContainsString('<th>Rôle</th>', $public);
        $this->assertStringNotContainsString('class="teams-role-pill"', $public);
        $this->assertStringNotContainsString('Compte HUB', $public);
        $this->assertStringContainsString('class="teams-filter-card"', $public);
        $this->assertStringContainsString('class="teams-contact-count"', $public);
        $this->assertStringContainsString('class="teams-card-count"', $public);
        $this->assertStringNotContainsString('class="teams-mobile-list"', $public);
        $this->assertStringNotContainsString('function renderMemberCard', $public);
        $this->assertStringNotContainsString('class="teams-person-card"', $public);
        $this->assertStringContainsString('searchAllSites: false', $public);
        $this->assertStringContainsString('params.set("allSites", "1")', $public);
        $this->assertStringContainsString('function renderSearchScope(searchAllSites)', $public);
        $this->assertStringContainsString('function scheduleFilteredMembersRender(input)', $public);
        $this->assertStringContainsString('function clearFilterRenderTimer()', $public);
        $this->assertStringContainsString('function renderFilteredMembers()', $public);
        $this->assertStringContainsString('function restoreSearchFocus(input, selectionStart, selectionEnd)', $public);
        $this->assertStringContainsString('data-teams-stats', $public);
        $this->assertStringContainsString('data-teams-members-card', $public);
        $this->assertStringContainsString('.teams-search:focus-within{border-color:var(--teams-border);box-shadow:0 10px 24px rgba(15,23,42,.04)}', $public);
        $this->assertStringContainsString('.teams-search input:focus{outline:none;box-shadow:none}', $public);
        $this->assertStringContainsString('data-teams-all-sites', $public);
        $this->assertStringContainsString('if (root?.contains(event.target)) return;', $public);
        $this->assertStringContainsString('} else if (!root.querySelector(".teams-page")) {', $public);
        $this->assertStringContainsString('Tous les sites', $public);
        $this->assertStringContainsString('<th>Site principal</th>', $public);
        $this->assertStringContainsString('function memberPrimarySite(member)', $public);
        $this->assertStringContainsString('member?.primarySiteName', $public);
        $this->assertStringNotContainsString('function memberSites(member)', $public);
        $this->assertStringNotContainsString('input?.focus()', $public);
        $this->assertStringNotContainsString('input?.setSelectionRange', $public);
        $this->assertStringContainsString('html.crm-teams-route main .layout-container.layout-page{width:100%;max-width:100%;min-width:0;overflow-x:hidden}', $public);
        $this->assertStringNotContainsString('html.crm-teams-route .layout-container.layout-page{width:100%;max-width:100%;min-width:0;overflow-x:hidden}', $public);
        $this->assertStringNotContainsString('.teams-table-wrap{display:none}', $public);
        $this->assertStringNotContainsString('container-name:teams-card', $public);
        $this->assertStringNotContainsString('@container teams-card (max-width:58rem)', $public);
        $this->assertStringContainsString('grid-template-columns:repeat(auto-fit,minmax(min(100%,13.5rem),1fr))', $public);
        $this->assertStringContainsString('.teams-filter-card{display:grid;grid-template-columns:minmax(0,1fr) auto;', $public);
        $this->assertStringContainsString('.teams-table{width:100%;min-width:50rem;', $public);
        $this->assertStringContainsString('.teams-table{min-width:46rem}', $public);
        $this->assertStringNotContainsString('.teams-table{min-width:54rem}', $public);
        $this->assertStringNotContainsString('teams-header-tools', $public);
        $this->assertStringNotContainsString('class="teams-sites"', $public);
        $this->assertStringNotContainsString('data-site-id', $public);
        $this->assertStringNotContainsString('function renderSiteButton', $public);
        $this->assertStringNotContainsString('.teams-sites{display:flex;gap:.55rem;overflow:auto', $public);
        $this->assertStringNotContainsString('.layout-container.layout-page > :not(#${rootId}){display:none!important}', $public);
    }
}

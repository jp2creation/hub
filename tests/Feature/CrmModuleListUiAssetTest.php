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

    public function test_team_members_keep_table_desktop_and_cards_mobile(): void
    {
        $source = (string) file_get_contents(base_path('Modules/CrmTeams/resources/assets/crm-equipes.js'));
        $public = (string) file_get_contents(public_path('modules/crm-teams/crm-equipes.js'));

        $this->assertSame($source, $public);
        $this->assertStringContainsString('class="teams-table-wrap"', $public);
        $this->assertStringContainsString('<table class="teams-table">', $public);
        $this->assertStringNotContainsString('<th>Rôle</th>', $public);
        $this->assertStringNotContainsString('class="teams-role-pill"', $public);
        $this->assertStringNotContainsString('Compte HUB', $public);
        $this->assertStringContainsString('class="teams-mobile-list"', $public);
        $this->assertStringContainsString('function renderMemberCard', $public);
        $this->assertStringContainsString('class="teams-person-card"', $public);
        $this->assertStringContainsString('.teams-table-wrap{display:none}', $public);
        $this->assertStringContainsString('container-name:teams-card', $public);
        $this->assertStringContainsString('@container teams-card (max-width:58rem)', $public);
        $this->assertStringContainsString('grid-template-columns:repeat(auto-fit,minmax(min(100%,8.75rem),1fr))', $public);
        $this->assertStringContainsString('.teams-mobile-list{display:none;grid-template-columns:repeat(auto-fit,minmax(min(100%,18rem),1fr))}', $public);
        $this->assertStringContainsString('.teams-table{width:100%;min-width:50rem;', $public);
        $this->assertStringNotContainsString('.teams-table{min-width:54rem}', $public);
        $this->assertStringNotContainsString('.teams-sites{display:flex;gap:.55rem;overflow:auto', $public);
        $this->assertStringNotContainsString('.layout-container.layout-page > :not(#${rootId}){display:none!important}', $public);
    }
}

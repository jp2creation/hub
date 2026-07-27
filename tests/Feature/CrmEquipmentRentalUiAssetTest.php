<?php

namespace Tests\Feature;

use Tests\TestCase;

class CrmEquipmentRentalUiAssetTest extends TestCase
{
    public function test_equipment_rental_module_is_native_and_uses_current_api_route(): void
    {
        $equipmentAsset = (string) file_get_contents(base_path('Modules/CrmEquipmentRentals/resources/assets/crm-equipment-rentals.js'));
        $hosts = (string) file_get_contents(resource_path('frontend/crm/modules/hosts.ts'));
        $modules = (string) file_get_contents(resource_path('frontend/crm/modules/register.ts'));
        $equipmentAsset = str_replace("'", '"', $equipmentAsset);

        $this->assertStringContainsString('const api = "/api/equipment-rentals"', $equipmentAsset);
        $this->assertStringNotContainsString('/api/equipment-rentals.php', $equipmentAsset);
        $this->assertStringContainsString('credentials: "same-origin"', $equipmentAsset);
        $this->assertStringContainsString('"X-CSRF-TOKEN": csrfToken()', $equipmentAsset);
        $this->assertStringContainsString('create_rental', $equipmentAsset);
        $this->assertStringContainsString('update_rental', $equipmentAsset);
        $this->assertStringContainsString('delete_rental', $equipmentAsset);
        $this->assertStringContainsString('canDeleteRental', $equipmentAsset);
        $this->assertStringContainsString('equipment_rentals.delete_own', $equipmentAsset);
        $this->assertStringContainsString('equipment_rentals.delete_any', $equipmentAsset);
        $this->assertStringContainsString('if (!state.selectedItemId) return null;', $equipmentAsset);
        $this->assertStringContainsString('siteItemsWithoutCategory', $equipmentAsset);
        $this->assertStringContainsString('renderPlanningSections(item)', $equipmentAsset);
        $this->assertStringContainsString('data-rent-planning', $equipmentAsset);
        $this->assertStringContainsString('data-rent-calendar', $equipmentAsset);
        $this->assertStringContainsString('scrollPlanningIntoView', $equipmentAsset);
        $this->assertStringContainsString('[data-rent-calendar]', $equipmentAsset);
        $this->assertStringContainsString('scrollIntoView({ behavior: "smooth", block: "start" })', $equipmentAsset);
        $this->assertStringContainsString('if (state.view === "month") scrollPlanningIntoView();', $equipmentAsset);
        $this->assertStringContainsString('state.selectedItemId = null;', $equipmentAsset);
        $this->assertStringContainsString('state.view = "month";', $equipmentAsset);
        $this->assertStringNotContainsString('|| items[0] || null', $equipmentAsset);
        $this->assertStringContainsString('rent-period-morning', $equipmentAsset);
        $this->assertStringContainsString('rent-period-afternoon', $equipmentAsset);
        $this->assertStringContainsString('rent-period-day', $equipmentAsset);
        $this->assertStringNotContainsString('window.MartinSolsUi.renderProductGrid', $equipmentAsset);
        $this->assertStringNotContainsString('window.MartinSolsUi.renderSegmentControl', $equipmentAsset);
        $this->assertStringContainsString('view: "month"', $equipmentAsset);
        $this->assertStringContainsString('rent-planning-header', $equipmentAsset);
        $this->assertStringContainsString('rent-month-dots', $equipmentAsset);
        $this->assertStringContainsString('data-view="today"', $equipmentAsset);
        $this->assertStringContainsString('data-view="today" class=""', $equipmentAsset);
        $this->assertStringContainsString('state.month = new Date(today.getFullYear(), today.getMonth(), 1)', $equipmentAsset);
        $this->assertStringContainsString('rentalPeriods', $equipmentAsset);
        $this->assertStringContainsString('periodPayload', $equipmentAsset);
        $this->assertStringContainsString('periodType: "day"', $equipmentAsset);
        $this->assertStringContainsString('slot: "full_day"', $equipmentAsset);
        $this->assertStringContainsString('Toutes catégories', $equipmentAsset);
        $this->assertStringNotContainsString('data-rent-see-all', $equipmentAsset);
        $this->assertStringNotContainsString('Prochaines locations', $equipmentAsset);
        $this->assertStringNotContainsString('Toutes les locations à venir', $equipmentAsset);
        $this->assertStringContainsString('data-delete-rental', $equipmentAsset);
        $this->assertStringContainsString('rent-summary-image', $equipmentAsset);
        $this->assertStringContainsString('showHalfDayPrice !== false', $equipmentAsset);
        $this->assertStringContainsString('item.rentalMode === "day_only"', $equipmentAsset);
        $this->assertStringContainsString('Tarif masqué', $equipmentAsset);
        $this->assertStringContainsString('document.readyState ===', $equipmentAsset);

        $this->assertStringContainsString("id: 'crm-equipment-rentals-module'", $hosts);
        $this->assertStringContainsString("paths: ['/locations-materiel']", $hosts);
        $this->assertStringContainsString("prefix: '/locations-materiel/'", $hosts);
        $this->assertStringContainsString("equipmentRentals: () => import('../../../../Modules/CrmEquipmentRentals/resources/assets/crm-equipment-rentals.js')", $modules);
        $this->assertStringNotContainsString("loadLegacyAsset('equipment-rentals-Codex2.js')", $modules);
        $this->assertFileDoesNotExist(resource_path('frontend/static/assets/equipment-rentals-Codex2.js'));
    }
}

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
        $this->assertStringContainsString('background:var(--rent-green);color:#fff', $equipmentAsset);
        $this->assertStringContainsString('rent-period.is-reserved{background:var(--rent-red)', $equipmentAsset);
        $this->assertStringContainsString('background:#16a34a;margin-right:.35rem"></span>Disponible', $equipmentAsset);
        $this->assertStringContainsString('background:#dc2626;margin-right:.35rem"></span>Réservé / loué', $equipmentAsset);
        $this->assertStringNotContainsString('rent-period-morning{background:#14b8a6}', $equipmentAsset);
        $this->assertStringNotContainsString('rent-period-afternoon{background:#ff5c57}', $equipmentAsset);
        $this->assertStringNotContainsString('rent-period-day{background:#4f6df5}', $equipmentAsset);
        $this->assertStringNotContainsString('window.MartinSolsUi.renderProductGrid', $equipmentAsset);
        $this->assertStringNotContainsString('window.MartinSolsUi.renderSegmentControl', $equipmentAsset);
        $this->assertStringContainsString('view: "month"', $equipmentAsset);
        $this->assertStringContainsString('rent-planning-header', $equipmentAsset);
        $this->assertStringContainsString('rent-month-dots', $equipmentAsset);
        $this->assertStringContainsString('rent-month-board', $equipmentAsset);
        $this->assertStringContainsString('rent-month-dot-morning{background:#f7b711}', $equipmentAsset);
        $this->assertStringContainsString('rent-month-dot-afternoon{background:#95002e}', $equipmentAsset);
        $this->assertStringContainsString('rent-month-dot-day{background:#000000}', $equipmentAsset);
        $this->assertStringContainsString('background:#f7b711;margin-right:.35rem"></span>Matin', $equipmentAsset);
        $this->assertSame(1, substr_count($equipmentAsset, 'background:#95002e;margin-right:.35rem"></span>'));
        $this->assertSame(1, substr_count($equipmentAsset, 'background:#000000;margin-right:.35rem"></span>'));
        $this->assertStringNotContainsString('rent-month-dot-morning{background:#14b8a6}', $equipmentAsset);
        $this->assertStringNotContainsString('rent-month-dot-afternoon{background:#ff5c57}', $equipmentAsset);
        $this->assertStringNotContainsString('rent-month-dot-day{background:#4f6df5}', $equipmentAsset);
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
        $this->assertStringContainsString('data-pending-returns', $equipmentAsset);
        $this->assertStringContainsString('data-receive-rental', $equipmentAsset);
        $this->assertStringContainsString('statusOnly: true', $equipmentAsset);
        $this->assertStringContainsString('status: String(data.get("status") || existing?.status || "reserved")', $equipmentAsset);
        $this->assertStringContainsString('focusReturnsRequested', $equipmentAsset);
        $this->assertStringContainsString('retours: focusReturnsRequested() ? 1 : ""', $equipmentAsset);
        $this->assertStringContainsString('rent-summary-image', $equipmentAsset);
        $this->assertStringContainsString('mode !== "day_only"', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card{position:relative;display:flex;min-width:0;min-height:16.6rem', $equipmentAsset);
        $this->assertStringContainsString('rent-product-image{position:relative;display:grid;place-items:center;aspect-ratio:1/1;width:100%;border-bottom:1px solid var(--rent-border);background:#fff', $equipmentAsset);
        $this->assertStringContainsString('rent-product-image::before{content:"";position:absolute;inset:.7rem;border-radius:.75rem;background:#f8fafc}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-image img{position:relative;z-index:1;width:100%;height:100%;max-width:100%;max-height:100%;object-fit:contain!important;object-position:center center;padding:.88rem;background:transparent}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-initials', $equipmentAsset);
        $this->assertStringContainsString('rent-product-status', $equipmentAsset);
        $this->assertStringContainsString('${busy ? "Réservé" : "Disponible"}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card{min-height:13.8rem;border-radius:.82rem}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-image img{padding:.58rem}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-name{font-size:.87rem;line-height:1.14}', $equipmentAsset);
        $this->assertStringNotContainsString('has-no-visible-price', $equipmentAsset);
        $this->assertStringNotContainsString('normalizeProductPhotoFrames(root)', $equipmentAsset);
        $this->assertStringNotContainsString('averageCornerColor(pixels, width, height, corner)', $equipmentAsset);
        $this->assertStringNotContainsString('productPhotoFrame(objectX, objectY, objectWidth, objectHeight, width, height, targetRatio)', $equipmentAsset);
        $this->assertStringNotContainsString('image.dataset.rentPhotoTrimmed', $equipmentAsset);
        $this->assertStringNotContainsString('crossorigin="anonymous"', $equipmentAsset);
        $this->assertStringNotContainsString('rent-product-image::after', $equipmentAsset);
        $this->assertStringNotContainsString('linear-gradient(180deg,rgba(15,23,42,0),rgba(15,23,42,.66))', $equipmentAsset);
        $this->assertStringNotContainsString('priceLabel(item)', $equipmentAsset);
        $this->assertStringNotContainsString('rent-product-meta', $equipmentAsset);
        $this->assertStringNotContainsString('showHalfDayPrice !== false', $equipmentAsset);
        $this->assertStringNotContainsString('EUR/j', $equipmentAsset);
        $this->assertStringNotContainsString('EUR/½j', $equipmentAsset);
        $this->assertStringNotContainsString('Tarif masqué', $equipmentAsset);
        $this->assertStringContainsString('document.readyState ===', $equipmentAsset);

        $this->assertStringContainsString("id: 'crm-equipment-rentals-module'", $hosts);
        $this->assertStringContainsString("paths: ['/locations-materiel']", $hosts);
        $this->assertStringContainsString("prefix: '/locations-materiel/'", $hosts);
        $this->assertStringContainsString("equipmentRentals: () => import('../../../../Modules/CrmEquipmentRentals/resources/assets/crm-equipment-rentals.js')", $modules);
        $this->assertStringNotContainsString("loadLegacyAsset('equipment-rentals-Codex2.js')", $modules);
        $this->assertFileDoesNotExist(resource_path('frontend/static/assets/equipment-rentals-Codex2.js'));
    }
}

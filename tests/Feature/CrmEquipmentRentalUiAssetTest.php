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
        $this->assertStringContainsString('mode !== "day_only"', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card has-no-visible-price', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card.has-no-visible-price{min-height:16rem;border-radius:.95rem;background:#fff}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card.has-no-visible-price .rent-product-image{position:relative;aspect-ratio:auto;width:100%;height:12.35rem;border-bottom:1px solid var(--rent-border);background:#fff;padding:0}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card.has-no-visible-price .rent-product-image img{width:100%;height:100%;max-width:100%;max-height:100%;object-fit:contain!important;object-position:center center;padding:0!important;background:#fff}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card.has-no-visible-price.is-rent-photo-portrait .rent-product-image{height:13.2rem}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-initials', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card.has-no-visible-price .rent-product-body{position:relative;z-index:1;justify-content:center;min-height:3.65rem;padding:.58rem .82rem .82rem;background:#fff}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card.has-no-visible-price .rent-product-name{color:var(--rent-text);font-size:.95rem;text-shadow:none}', $equipmentAsset);
        $this->assertStringContainsString('normalizeProductPhotoFrames(root)', $equipmentAsset);
        $this->assertStringContainsString('averageCornerColor(pixels, width, height, corner)', $equipmentAsset);
        $this->assertStringContainsString('productPhotoFrame(objectX, objectY, objectWidth, objectHeight, width, height, targetRatio)', $equipmentAsset);
        $this->assertStringContainsString('const threshold = 18;', $equipmentAsset);
        $this->assertStringContainsString('card?.classList.toggle("is-rent-photo-portrait", cropRatio < 0.82)', $equipmentAsset);
        $this->assertStringContainsString('const targetRatio = objectRatio < 0.68 ? 0.78 : objectRatio < 0.96 ? 0.92 : Math.min(1.28, objectRatio)', $equipmentAsset);
        $this->assertStringContainsString('image.dataset.rentPhotoAspect = cropRatio.toFixed(3)', $equipmentAsset);
        $this->assertStringContainsString('image.dataset.rentPhotoFrame = `${frame.width}x${frame.height}`', $equipmentAsset);
        $this->assertStringContainsString('image.dataset.rentPhotoTrimmed = "1"', $equipmentAsset);
        $this->assertStringContainsString('crossorigin="anonymous"', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card.has-no-visible-price{min-height:15.75rem}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card.has-no-visible-price .rent-product-image{height:clamp(10.85rem,50vw,12.2rem);padding:0}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card.has-no-visible-price.is-rent-photo-portrait .rent-product-image{height:clamp(11.75rem,55vw,13.15rem)}', $equipmentAsset);
        $this->assertStringContainsString('rent-product-card.has-no-visible-price .rent-product-name{font-size:.88rem;line-height:1.14}', $equipmentAsset);
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

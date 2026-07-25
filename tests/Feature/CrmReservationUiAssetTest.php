<?php

namespace Tests\Feature;

use Tests\TestCase;

class CrmReservationUiAssetTest extends TestCase
{
    public function test_vehicle_reservation_module_is_native_and_uses_current_api_route(): void
    {
        $reservationAsset = (string) file_get_contents(base_path('Modules/CrmReservations/resources/assets/crm-reservations.js'));
        $hosts = (string) file_get_contents(resource_path('frontend/crm/modules/hosts.ts'));
        $modules = (string) file_get_contents(resource_path('frontend/crm/modules/register.ts'));
        $reservationAsset = str_replace("'", '"', $reservationAsset);

        $this->assertStringContainsString('const api = "/api/reservations"', $reservationAsset);
        $this->assertStringNotContainsString('/api/reservations.php', $reservationAsset);
        $this->assertStringContainsString('credentials: "same-origin"', $reservationAsset);
        $this->assertStringContainsString('"X-CSRF-TOKEN": csrfToken()', $reservationAsset);
        $this->assertStringContainsString('create_reservation', $reservationAsset);
        $this->assertStringContainsString('update_reservation', $reservationAsset);
        $this->assertStringContainsString('delete_reservation', $reservationAsset);
        $this->assertStringContainsString('canDeleteReservation', $reservationAsset);
        $this->assertStringContainsString('reservations.delete_own', $reservationAsset);
        $this->assertStringContainsString('reservations.delete_any', $reservationAsset);
        $this->assertStringContainsString('reservation-day-board', $reservationAsset);
        $this->assertStringContainsString('reservation-mobile-slot-button', $reservationAsset);
        $this->assertStringContainsString('reservation-day-cell-button', $reservationAsset);
        $this->assertStringContainsString('renderSlotColumn("Matin", morning, "morning")', $reservationAsset);
        $this->assertStringContainsString('renderSlotColumn("Après-midi", afternoon, "afternoon")', $reservationAsset);
        $this->assertStringContainsString('reservation-day-row-track-${esc(period)}', $reservationAsset);
        $this->assertStringContainsString('vehicleDaySlots', $reservationAsset);
        $this->assertStringContainsString('vehicleDefaultDayHours', $reservationAsset);
        $this->assertStringContainsString('dayStartTime', $reservationAsset);
        $this->assertStringContainsString('dayEndTime', $reservationAsset);
        $this->assertStringContainsString('reservationCellIsSelected', $reservationAsset);
        $this->assertStringContainsString('reservationSelectionCellLabel', $reservationAsset);
        $this->assertStringNotContainsString('window.MartinSolsUi.renderProductGrid', $reservationAsset);
        $this->assertStringNotContainsString('window.MartinSolsUi.renderSegmentControl', $reservationAsset);
        $this->assertStringContainsString('if (!state.selectedVehicleId) return null;', $reservationAsset);
        $this->assertStringContainsString('data-resa-planning', $reservationAsset);
        $this->assertStringContainsString('data-resa-calendar', $reservationAsset);
        $this->assertStringContainsString('data-resa-selection', $reservationAsset);
        $this->assertStringContainsString('scrollPlanningIntoView', $reservationAsset);
        $this->assertStringContainsString('scrollSelectionIntoView', $reservationAsset);
        $this->assertStringContainsString('scrollToReservationTarget', $reservationAsset);
        $this->assertStringContainsString('findReservationScroller', $reservationAsset);
        $this->assertStringContainsString('isScrollableElement', $reservationAsset);
        $this->assertStringContainsString('scrollElementIntoReservationView', $reservationAsset);
        $this->assertStringContainsString('smoothWindowScrollTo', $reservationAsset);
        $this->assertStringContainsString('smoothElementScrollTo', $reservationAsset);
        $this->assertStringContainsString('reservationHeaderOffset', $reservationAsset);
        $this->assertStringContainsString('document.querySelector(".crm-native-header")', $reservationAsset);
        $this->assertStringContainsString('document.querySelector(".crm-native-main"), document.querySelector(".crm-native-content")', $reservationAsset);
        $this->assertStringContainsString('window.scrollTo({ top: nextTop, behavior: "smooth" });', $reservationAsset);
        $this->assertStringContainsString('window.scrollTo(0, nextTop);', $reservationAsset);
        $this->assertStringContainsString('scroller.scrollTo({ top: nextTop, behavior: "smooth" });', $reservationAsset);
        $this->assertStringContainsString('scroller.scrollTop = nextTop;', $reservationAsset);
        $this->assertStringContainsString('[90, 240, 520].forEach((delay) => {', $reservationAsset);
        $this->assertStringContainsString('[data-resa-planning]`) ||', $reservationAsset);
        $this->assertStringContainsString('[data-resa-calendar]', $reservationAsset);
        $this->assertStringContainsString('[data-resa-selection]', $reservationAsset);
        $this->assertStringContainsString('scrollToReservationTarget(() => document.querySelector(`#${rootId} [data-resa-selection]`), "end");', $reservationAsset);
        $this->assertStringContainsString('if (state.view === "month" || state.view === "day") scrollPlanningIntoView();', $reservationAsset);
        $this->assertStringContainsString("state.month = new Date(today.getFullYear(), today.getMonth(), 1);\n          state.view = \"day\";\n          state.selection = null;\n          render();\n          scrollPlanningIntoView();", $reservationAsset);
        $this->assertStringContainsString("state.selectedDate = button.dataset.date;\n        state.view = \"day\";\n        state.selection = null;\n        render();\n        scrollPlanningIntoView();", $reservationAsset);
        $this->assertStringContainsString("state.selection.endAt = endAt;\n    render();\n    scrollSelectionIntoView();", $reservationAsset);
        $this->assertStringContainsString('state.selectedVehicleId = null;', $reservationAsset);
        $this->assertStringNotContainsString('|| vehicles[0] || null', $reservationAsset);
        $this->assertStringNotContainsString('vehicles[0]?.id || null', $reservationAsset);
        $this->assertStringContainsString('view: "month"', $reservationAsset);
        $this->assertStringContainsString('state.view = "month";', $reservationAsset);
        $this->assertStringContainsString('resa-planning-header', $reservationAsset);
        $this->assertStringContainsString('resa-month-dots', $reservationAsset);
        $this->assertStringContainsString('data-view="today"', $reservationAsset);
        $this->assertStringContainsString('data-view="today" class=""', $reservationAsset);
        $this->assertStringContainsString('state.month = new Date(today.getFullYear(), today.getMonth(), 1)', $reservationAsset);
        $this->assertStringContainsString('Début choisi', $reservationAsset);
        $this->assertStringContainsString('return "Fin";', $reservationAsset);
        $this->assertStringContainsString('return "Inclus";', $reservationAsset);
        $this->assertStringNotContainsString('Fin choisie', $reservationAsset);
        $this->assertStringNotContainsString('return "Sélectionné";', $reservationAsset);
        $this->assertStringContainsString('#16a34a', $reservationAsset);
        $this->assertStringContainsString('#dc2626', $reservationAsset);
        $this->assertStringContainsString('Disponible', $reservationAsset);
        $this->assertStringContainsString('Réservé', $reservationAsset);
        $this->assertStringContainsString('Matin', $reservationAsset);
        $this->assertStringContainsString('Après-midi', $reservationAsset);
        $this->assertStringContainsString('reservation-fast-actions', $reservationAsset);
        $this->assertStringContainsString('grid-template-columns:1fr 1fr', $reservationAsset);
        $this->assertStringContainsString('data-delete-reservation', $reservationAsset);
        $this->assertStringNotContainsString('data-resa-see-all', $reservationAsset);
        $this->assertStringNotContainsString('Prochaines réservations', $reservationAsset);
        $this->assertStringNotContainsString('Toutes les réservations à venir', $reservationAsset);
        $this->assertStringContainsString('document.readyState ===', $reservationAsset);

        $this->assertStringContainsString("id: 'crm-reservations-module'", $hosts);
        $this->assertStringContainsString("paths: ['/reservations']", $hosts);
        $this->assertStringContainsString("prefix: '/reservations/'", $hosts);
        $this->assertStringContainsString("reservations: () => import('../../../../Modules/CrmReservations/resources/assets/crm-reservations.js')", $modules);
        $this->assertStringNotContainsString("loadLegacyAsset('reservations-CSr_CND1.js')", $modules);
        $this->assertFileDoesNotExist(resource_path('frontend/static/assets/reservations-CSr_CND1.js'));
    }
}

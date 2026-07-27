<?php

namespace Tests\Feature;

use Tests\TestCase;

class CrmThemeSourceTest extends TestCase
{
    public function test_module_assets_do_not_embed_legacy_brand_colors(): void
    {
        $files = glob(base_path('Modules/*/resources/assets/*.js')) ?: [];

        $this->assertNotEmpty($files);

        $legacyBrandTokens = [
            '#95002e',
            '#a50034',
            '#a30038',
            '#b0003a',
            'rgb(var(--theme-primary,149 0 46)',
            'rgb(var(--theme-primary, 149 0 46)',
            'rgba(149,0,46',
            'rgba(149, 0, 46',
            'rgb(149 0 46',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach ($legacyBrandTokens as $token) {
                $this->assertStringNotContainsString($token, $source, $file.' contains '.$token);
            }
        }
    }

    public function test_runtime_php_brand_defaults_use_crm_theme(): void
    {
        $files = [
            base_path('Modules/CrmCore/app/Services/DashboardService.php'),
            base_path('Modules/CrmCore/app/Support/CrmReferenceCache.php'),
            base_path('Modules/CrmDocuments/app/Services/DocumentLibraryService.php'),
            base_path('Modules/CrmEquipmentRentals/app/Filament/Resources/CrmEquipmentItems/CrmEquipmentItemResource.php'),
            base_path('Modules/CrmEquipmentRentals/app/Services/EquipmentRentalService.php'),
            base_path('Modules/CrmReservations/app/Filament/Resources/CrmVehicles/CrmVehicleResource.php'),
            base_path('Modules/CrmReservations/app/Services/ReservationService.php'),
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringContainsString('CrmTheme::primaryHex()', $source, $file.' does not use CrmTheme');
            $this->assertStringNotContainsString("'#95002e'", $source, $file.' embeds the old primary color');
            $this->assertStringNotContainsString("'#a50034'", $source, $file.' embeds the old primary color');
        }
    }

    public function test_generated_export_html_receives_current_theme_variables(): void
    {
        $files = [
            base_path('Modules/CrmCashControl/resources/assets/crm-controle-caisse.js'),
            base_path('Modules/CrmCheckRemittances/resources/assets/crm-remise-cheques.js'),
            base_path('Modules/CrmLeaves/resources/assets/crm-conges.js'),
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringContainsString('function themeCssVariables()', $source);
            $this->assertStringContainsString('themeHex(', $source);
            $this->assertStringContainsString('--theme-primary-color', $source);
        }
    }

    public function test_active_site_switcher_uses_site_colored_martin_mark(): void
    {
        $switcher = (string) file_get_contents(base_path('Modules/CrmCore/resources/assets/crm-active-site.js'));
        $shell = (string) file_get_contents(resource_path('frontend/crm/styles/shell.css'));

        $this->assertStringContainsString('function martinSiteMark(className)', $switcher);
        $this->assertStringContainsString('crm-site-mark-primary', $switcher);
        $this->assertStringContainsString('color-mix(in srgb,var(--site-mark-primary) 50%,#fff)', $switcher);
        $this->assertStringContainsString('crm-active-site-option-mark', $switcher);
        $this->assertStringNotContainsString('crm-active-site-option-dot', $switcher);
        $this->assertStringNotContainsString('crm-active-site-option-icon', $switcher);
        $this->assertStringNotContainsString('crm-active-site-dot', $switcher);
        $this->assertStringContainsString('.crm-native-header-actions .crm-active-site-mark', $shell);
        $this->assertStringNotContainsString('.crm-native-header-actions .crm-active-site-dot', $shell);
    }

    public function test_standalone_blade_views_receive_shared_theme_variables(): void
    {
        $pages = (string) file_get_contents(base_path('Modules/CrmPages/resources/views/pages.blade.php'));
        $maintenance = (string) file_get_contents(resource_path('views/errors/503.blade.php'));

        $this->assertStringContainsString('CrmTheme::styleAttribute()', $pages);
        $this->assertStringContainsString('CrmTheme::frontendConfig()', $pages);
        $this->assertStringContainsString('CrmTheme::primaryRgb()', $pages);
        $this->assertStringContainsString('CrmTheme::accentRgb()', $pages);
        $this->assertStringContainsString('CrmTheme::styleAttribute()', $maintenance);
        $this->assertStringContainsString('--accent: var(--theme-primary-color);', $maintenance);
    }

    public function test_android_webview_settings_overlay_uses_shared_theme_variables(): void
    {
        $source = (string) file_get_contents(base_path('mobile/android/app/src/main/assets/app-settings-override.js'));

        $this->assertStringContainsString('var(--theme-primary-color)', $source);
        $this->assertStringContainsString('rgb(var(--theme-primary) / .08)', $source);
        $this->assertStringNotContainsString('#95002e', $source);
        $this->assertStringNotContainsString('rgb(149 0 46', $source);
    }
}

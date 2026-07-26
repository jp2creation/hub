<?php

namespace Tests\Unit;

use App\Support\CrmTheme;
use Tests\TestCase;

class CrmThemeTest extends TestCase
{
    public function test_theme_values_are_normalized_for_php_and_css_consumers(): void
    {
        config([
            'crm_theme.colors.primary' => '#123456',
            'crm_theme.colors.primary_dark' => null,
            'crm_theme.colors.accent' => '#abcdef',
        ]);

        $this->assertSame('#123456', CrmTheme::primaryHex());
        $this->assertSame('18 52 86', CrmTheme::primaryRgb());
        $this->assertSame('#abcdef', CrmTheme::accentHex());
        $this->assertSame('171 205 239', CrmTheme::accentRgb());
        $this->assertStringContainsString('--theme-primary: 18 52 86;', CrmTheme::styleAttribute());
        $this->assertStringContainsString('--crm-primary: #123456;', CrmTheme::styleAttribute());
    }
}

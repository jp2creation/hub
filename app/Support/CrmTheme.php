<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

final class CrmTheme
{
    public static function primaryHex(): string
    {
        return self::hex(config('crm_theme.colors.primary'), '#95002e');
    }

    public static function primaryDarkHex(): string
    {
        $configured = config('crm_theme.colors.primary_dark');

        if (is_string($configured) && trim($configured) !== '') {
            return self::hex($configured, self::darken(self::primaryHex()));
        }

        return self::darken(self::primaryHex());
    }

    public static function accentHex(): string
    {
        return self::hex(config('crm_theme.colors.accent'), '#f5b212');
    }

    public static function primaryRgb(): string
    {
        return self::rgb(self::primaryHex());
    }

    public static function accentRgb(): string
    {
        return self::rgb(self::accentHex());
    }

    public static function primaryRgba(float $alpha): string
    {
        return self::rgba(self::primaryHex(), $alpha);
    }

    /**
     * @return array<string, string>
     */
    public static function frontendConfig(): array
    {
        return [
            'primary' => self::primaryRgb(),
            'primaryHex' => self::primaryHex(),
            'accent' => self::accentRgb(),
            'accentHex' => self::accentHex(),
        ];
    }

    public static function styleAttribute(): string
    {
        return self::declarations(' ');
    }

    public static function styleTag(): HtmlString
    {
        return new HtmlString('<style data-crm-theme>:root{'.self::declarations().'}</style>');
    }

    /**
     * @return array<string, string>
     */
    public static function cssVariables(): array
    {
        $primaryHex = self::primaryHex();
        $primaryDarkHex = self::primaryDarkHex();
        $primaryRgb = self::primaryRgb();
        $accentHex = self::accentHex();
        $accentRgb = self::accentRgb();

        return [
            '--theme-primary' => $primaryRgb,
            '--theme-primary-color' => $primaryHex,
            '--theme-primary-dark' => self::rgb($primaryDarkHex),
            '--theme-primary-dark-color' => $primaryDarkHex,
            '--theme-accent' => $accentRgb,
            '--theme-accent-color' => $accentHex,
            '--crm-primary' => $primaryHex,
            '--crm-primary-display' => $primaryHex,
            '--crm-primary-soft' => 'rgb('.$primaryRgb.' / 0.1)',
            '--crm-primary-border' => 'rgb('.$primaryRgb.' / 0.18)',
            '--crm-accent' => $accentHex,
            '--livewire-progress-bar-color' => $primaryHex,
        ];
    }

    private static function declarations(string $separator = ''): string
    {
        $declarations = [];

        foreach (self::cssVariables() as $name => $value) {
            $declarations[] = $name.': '.$value.';';
        }

        return implode($separator, $declarations);
    }

    private static function hex(mixed $value, string $fallback): string
    {
        $candidate = is_scalar($value) ? trim((string) $value) : '';

        if (preg_match('/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $candidate, $matches) !== 1) {
            $candidate = $fallback;
            preg_match('/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $candidate, $matches);
        }

        $hex = strtolower($matches[1] ?? ltrim($fallback, '#'));

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }

    private static function rgb(string $hex): string
    {
        [$red, $green, $blue] = self::rgbParts($hex);

        return $red.' '.$green.' '.$blue;
    }

    private static function rgba(string $hex, float $alpha): string
    {
        [$red, $green, $blue] = self::rgbParts($hex);
        $alpha = max(0, min(1, $alpha));

        return sprintf('rgba(%d, %d, %d, %.3F)', $red, $green, $blue, $alpha);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgbParts(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function darken(string $hex): string
    {
        [$red, $green, $blue] = self::rgbParts($hex);

        return sprintf(
            '#%02x%02x%02x',
            (int) round($red * 0.9),
            (int) round($green * 0.9),
            (int) round($blue * 0.9),
        );
    }
}

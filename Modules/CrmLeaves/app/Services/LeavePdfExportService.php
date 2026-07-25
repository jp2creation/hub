<?php

namespace Modules\CrmLeaves\Services;

use App\Enums\CrmLeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class LeavePdfExportService
{
    public const PageWidth = 841.89;

    public const PageHeight = 595.28;

    private const MarginX = 18.0;

    private const MarginY = 14.0;

    private const EmployeeColumnWidth = 72.0;

    /**
     * @param  Collection<int, array{id:int,name:string,color:string}>  $employees
     * @param  Collection<int, array{employee_id:int,start_date:string,end_date:string,type:string,period:string,status:string}>  $entries
     * @param  array<int, string>  $siteNames
     */
    public function render(Collection $employees, Collection $entries, CarbonImmutable $from, CarbonImmutable $to, array $siteNames): string
    {
        $document = new SimpleLeavePdfDocument;
        $page = $document->addPage();
        $cursorY = self::MarginY;

        $this->drawDocumentTitle($page, $from, $to, $siteNames);
        $cursorY += 22.0;

        foreach ($this->monthsBetween($from, $to) as $month) {
            $monthStart = $month->startOfMonth()->max($from);
            $monthEnd = $month->endOfMonth()->min($to);
            $blockHeight = $this->monthBlockHeight($employees->count());

            if ($cursorY + $blockHeight > self::PageHeight - self::MarginY) {
                $page = $document->addPage();
                $cursorY = self::MarginY;
            }

            $this->drawMonth($page, $employees, $entries, $monthStart, $monthEnd, $cursorY);
            $cursorY += $blockHeight + 7.0;
        }

        return $document->output();
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    private function monthsBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        $cursor = $from->startOfMonth();

        while ($cursor <= $to) {
            $months[] = $cursor;
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    private function monthBlockHeight(int $employeeCount): float
    {
        return 9.0 + 8.0 + 8.0 + 8.0 + 8.0 + max(1, $employeeCount) * 10.0;
    }

    /**
     * @param  array<int, string>  $siteNames
     */
    private function drawDocumentTitle(SimpleLeavePdfPage $page, CarbonImmutable $from, CarbonImmutable $to, array $siteNames): void
    {
        $title = 'Planning conges';
        $period = $this->dateLabel($from).' - '.$this->dateLabel($to);
        $sites = implode(', ', $siteNames);

        $page->text($title, self::MarginX, self::MarginY + 1.5, 9.0, 'bold', '#111827');
        $page->text($period, 160.0, self::MarginY + 1.8, 7.0, 'bold', '#111827', 'center', 180.0);
        $page->text($sites !== '' ? $sites : 'Sites autorises', self::PageWidth - self::MarginX - 220.0, self::MarginY + 2.0, 6.5, 'regular', '#475569', 'right', 220.0);
    }

    /**
     * @param  Collection<int, array{id:int,name:string,color:string}>  $employees
     * @param  Collection<int, array{employee_id:int,start_date:string,end_date:string,type:string,period:string,status:string}>  $entries
     */
    private function drawMonth(
        SimpleLeavePdfPage $page,
        Collection $employees,
        Collection $entries,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        float $top,
    ): void {
        $days = $this->daysBetween($monthStart, $monthEnd);
        $left = self::MarginX + 78.0;
        $tableWidth = self::PageWidth - self::MarginX - $left;
        $dayWidth = $tableWidth / max(1, count($days));
        $employeeColumnX = $left - self::EmployeeColumnWidth;
        $y = $top;

        $page->rect($employeeColumnX, $y, self::EmployeeColumnWidth + $tableWidth, 9.0, '#ffffff', '#111827', 0.45);
        $page->text($this->monthTitle($monthStart), $employeeColumnX, $y + 1.4, 6.3, 'bold', '#111827', 'center', self::EmployeeColumnWidth + $tableWidth);
        $y += 9.0;

        $this->drawLabelRow($page, 'zone A', $days, $employeeColumnX, $y, $dayWidth, fn (CarbonImmutable $day): ?string => $this->isZoneA($day) ? '#ff0000' : null);
        $y += 8.0;

        $this->drawLabelRow($page, 'semaine', $days, $employeeColumnX, $y, $dayWidth, fn (): ?string => null, fn (CarbonImmutable $day, int $index): string => ($day->isMonday() || $index === 0) ? (string) $day->isoWeek() : '');
        $y += 8.0;

        $this->drawLabelRow($page, '', $days, $employeeColumnX, $y, $dayWidth, fn (CarbonImmutable $day): ?string => $this->weekendColor($day), fn (CarbonImmutable $day): string => $this->weekdayLetter($day));
        $y += 8.0;

        $this->drawLabelRow($page, '', $days, $employeeColumnX, $y, $dayWidth, fn (CarbonImmutable $day): ?string => $this->weekendColor($day), fn (CarbonImmutable $day): string => (string) $day->day);
        $y += 8.0;

        $rows = $employees->isNotEmpty() ? $employees : collect([['id' => 0, 'name' => 'Aucun membre', 'color' => '#facc15']]);
        foreach ($rows as $employee) {
            $page->rect($employeeColumnX, $y, self::EmployeeColumnWidth, 10.0, '#ffffff', '#111827', 0.35);
            $page->text($this->employeeLabel((string) $employee['name']), $employeeColumnX + 2.0, $y + 2.4, 5.7, 'bold', '#111827', 'center', self::EmployeeColumnWidth - 4.0);

            foreach ($days as $index => $day) {
                $x = $left + $index * $dayWidth;
                $fill = $this->entryFillForDate((int) $employee['id'], $day, $entries) ?? $this->weekendColor($day) ?? '#ffffff';
                $page->rect($x, $y, $dayWidth, 10.0, $fill, '#111827', 0.35);
            }

            $y += 10.0;
        }
    }

    /**
     * @param  array<int, CarbonImmutable>  $days
     * @param  callable(CarbonImmutable): (?string)  $fillCallback
     * @param  callable(CarbonImmutable, int): string|null  $labelCallback
     */
    private function drawLabelRow(
        SimpleLeavePdfPage $page,
        string $label,
        array $days,
        float $labelX,
        float $y,
        float $dayWidth,
        callable $fillCallback,
        ?callable $labelCallback = null,
    ): void {
        $page->rect($labelX, $y, self::EmployeeColumnWidth, 8.0, '#ffffff', '#111827', 0.35);
        $page->text($label, $labelX + 2.0, $y + 2.1, 5.2, 'bold', '#111827', 'center', self::EmployeeColumnWidth - 4.0);

        $left = $labelX + self::EmployeeColumnWidth;
        foreach ($days as $index => $day) {
            $x = $left + $index * $dayWidth;
            $fill = $fillCallback($day) ?? '#ffffff';
            $page->rect($x, $y, $dayWidth, 8.0, $fill, '#111827', 0.35);

            if ($labelCallback !== null) {
                $page->text($labelCallback($day, $index), $x, $y + 2.0, 5.3, 'regular', '#111827', 'center', $dayWidth);
            }
        }
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    private function daysBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        $cursor = $from;

        while ($cursor <= $to) {
            $days[] = $cursor;
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * @param  Collection<int, array{employee_id:int,start_date:string,end_date:string,type:string,period:string,status:string}>  $entries
     */
    private function entryFillForDate(int $employeeId, CarbonImmutable $date, Collection $entries): ?string
    {
        if ($employeeId <= 0) {
            return null;
        }

        $dateValue = $date->toDateString();
        $entry = $entries->first(
            fn (array $entry): bool => (int) $entry['employee_id'] === $employeeId
                && $entry['start_date'] <= $dateValue
                && $entry['end_date'] >= $dateValue,
        );

        if (! $entry) {
            return null;
        }

        return match ($entry['type']) {
            CrmLeaveType::Rtt->value => '#7dd3fc',
            CrmLeaveType::Absence->value => '#fb7185',
            CrmLeaveType::Training->value => '#c4b5fd',
            CrmLeaveType::SickLeave->value => '#cbd5e1',
            default => '#ffff00',
        };
    }

    private function isZoneA(CarbonImmutable $date): bool
    {
        $ranges = [
            ['2026-02-07', '2026-02-23'],
            ['2026-04-04', '2026-04-20'],
            ['2026-05-14', '2026-05-17'],
            ['2026-07-04', '2026-08-31'],
            ['2026-12-19', '2027-01-04'],
        ];

        foreach ($ranges as [$from, $to]) {
            if ($date->betweenIncluded(CarbonImmutable::parse($from), CarbonImmutable::parse($to))) {
                return true;
            }
        }

        return false;
    }

    private function weekendColor(CarbonImmutable $date): ?string
    {
        return match ($date->dayOfWeekIso) {
            6 => '#e7e6fb',
            7 => '#82b7f6',
            default => null,
        };
    }

    private function monthTitle(CarbonImmutable $date): string
    {
        $months = [
            1 => 'JANVIER',
            2 => 'FEVRIER',
            3 => 'MARS',
            4 => 'AVRIL',
            5 => 'MAI',
            6 => 'JUIN',
            7 => 'JUILLET',
            8 => 'AOUT',
            9 => 'SEPTEMBRE',
            10 => 'OCTOBRE',
            11 => 'NOVEMBRE',
            12 => 'DECEMBRE',
        ];

        return ($months[(int) $date->month] ?? 'MOIS').' '.$date->year;
    }

    private function weekdayLetter(CarbonImmutable $date): string
    {
        return ['L', 'M', 'M', 'J', 'V', 'S', 'D'][$date->dayOfWeekIso - 1] ?? '';
    }

    private function employeeLabel(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        return mb_strtoupper((string) ($parts[0] ?? $name));
    }

    private function dateLabel(CarbonImmutable $date): string
    {
        return $date->format('d/m/Y');
    }
}

class SimpleLeavePdfDocument
{
    /** @var array<int, SimpleLeavePdfPage> */
    private array $pages = [];

    public function addPage(): SimpleLeavePdfPage
    {
        $page = new SimpleLeavePdfPage(LeavePdfExportService::PageWidth, LeavePdfExportService::PageHeight);
        $this->pages[] = $page;

        return $page;
    }

    public function output(): string
    {
        $objects = [];
        $catalogId = 1;
        $pagesId = 2;
        $fontRegularId = 3;
        $fontBoldId = 4;
        $nextId = 5;
        $pageIds = [];

        foreach ($this->pages as $page) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageIds[] = $pageId;
            $stream = $page->content();
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
            $objects[$pageId] = "<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 {$page->width()} {$page->height()}] /Resources << /Font << /F1 {$fontRegularId} 0 R /F2 {$fontBoldId} 0 R >> >> /Contents {$contentId} 0 R >>";
        }

        $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $objects[$pagesId] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $id): string => "{$id} 0 R", $pageIds)).'] /Count '.count($pageIds).' >>';
        $objects[$fontRegularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root {$catalogId} 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }
}

class SimpleLeavePdfPage
{
    /** @var array<int, string> */
    private array $commands = [];

    public function __construct(private readonly float $width, private readonly float $height) {}

    public function width(): float
    {
        return $this->width;
    }

    public function height(): float
    {
        return $this->height;
    }

    public function content(): string
    {
        return implode("\n", $this->commands);
    }

    public function rect(float $x, float $y, float $width, float $height, ?string $fill, string $stroke = '#111827', float $lineWidth = 0.35): void
    {
        $pdfY = $this->height - $y - $height;
        $operator = $fill ? 'B' : 'S';
        $fillColor = $fill ? $this->colorCommand($fill, 'rg') : '';
        $strokeColor = $this->colorCommand($stroke, 'RG');

        $this->commands[] = sprintf(
            'q %.3F w %s %s %.3F %.3F %.3F %.3F re %s Q',
            $lineWidth,
            $fillColor,
            $strokeColor,
            $x,
            $pdfY,
            $width,
            $height,
            $operator,
        );
    }

    public function text(string $text, float $x, float $y, float $size, string $weight = 'regular', string $color = '#111827', string $align = 'left', ?float $maxWidth = null): void
    {
        $encoded = $this->encodeText($maxWidth ? $this->truncate($text, $size, $maxWidth) : $text);
        $font = $weight === 'bold' ? 'F2' : 'F1';
        $textWidth = strlen($encoded) * $size * 0.46;
        $textX = match ($align) {
            'center' => $x + (($maxWidth ?? 0.0) - $textWidth) / 2,
            'right' => $x + (($maxWidth ?? 0.0) - $textWidth),
            default => $x,
        };
        $pdfY = $this->height - $y - $size;

        $this->commands[] = sprintf(
            'BT /%s %.3F Tf %s %.3F %.3F Td (%s) Tj ET',
            $font,
            $size,
            $this->colorCommand($color, 'rg'),
            max(0.0, $textX),
            $pdfY,
            $this->escapePdfString($encoded),
        );
    }

    private function truncate(string $text, float $size, float $maxWidth): string
    {
        $maxCharacters = max(1, (int) floor($maxWidth / ($size * 0.48)));

        return mb_strlen($text) > $maxCharacters
            ? mb_substr($text, 0, max(1, $maxCharacters - 1)).'.'
            : $text;
    }

    private function encodeText(string $text): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return $encoded !== false ? $encoded : $text;
    }

    private function escapePdfString(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function colorCommand(string $hex, string $operator): string
    {
        $hex = ltrim($hex, '#');
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '111827';
        }

        $red = hexdec(substr($hex, 0, 2)) / 255;
        $green = hexdec(substr($hex, 2, 2)) / 255;
        $blue = hexdec(substr($hex, 4, 2)) / 255;

        return sprintf('%.3F %.3F %.3F %s', $red, $green, $blue, $operator);
    }
}

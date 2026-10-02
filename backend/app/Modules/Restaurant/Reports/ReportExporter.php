<?php

namespace App\Modules\Restaurant\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a restaurant report result (rows + totals already computed and permission-filtered)
 * as Excel or PDF. No figures are calculated here; values are only formatted.
 */
class ReportExporter
{
    /** Maximum rows per export (PDF rendering is far heavier than streaming XLSX). Lowered in tests. */
    public static array $limits = ['xlsx' => 50000, 'pdf' => 2000];

    private const MONEY_FORMAT = '#,##0.00';

    /**
     * @param  list<array{label: string, type: string, value: callable, total?: callable}>  $columns
     * @param  array{items: array<int, array>, totals?: array}  $result
     * @param  array<string, string>  $meta  header lines, e.g. ['Period' => '...']
     * @param  array{name: string, lines: list<string>}|null  $letterhead  business name/address/contact printed above the title
     */
    public function xlsx(string $title, array $columns, array $result, array $meta, string $filename, ?array $letterhead = null): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'restaurant-report');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $title), 0, 31));

        $bold = (new Style)->withFontBold(true);
        $money = (new Style)->withFormat(self::MONEY_FORMAT);
        $boldMoney = (new Style)->withFontBold(true)->withFormat(self::MONEY_FORMAT);

        if ($letterhead !== null) {
            $writer->addRow(self::row([$letterhead['name']], [], (new Style)->withFontBold(true)->withFontSize(14)));
            foreach ($letterhead['lines'] as $line) {
                $writer->addRow(self::row([$line]));
            }
            $writer->addRow(self::row([]));
        }
        $writer->addRow(self::row([$title], [], (new Style)->withFontBold(true)->withFontSize($letterhead !== null ? 12 : 14)));
        foreach ($meta as $label => $value) {
            $writer->addRow(self::row([$label, $value]));
        }
        $writer->addRow(self::row([]));
        $writer->addRow(self::row(array_column($columns, 'label'), [], $bold));

        $styles = [];
        foreach ($columns as $i => $c) {
            if ($c['type'] === 'money') {
                $styles[$i] = $money;
            }
        }
        foreach ($result['items'] as $row) {
            $writer->addRow(self::row(array_map(fn ($c) => self::cell($c['type'], ($c['value'])($row)), $columns), $styles));
        }

        if (isset($result['totals']) && self::hasTotals($columns) && $result['items'] !== []) {
            $values = [];
            $totalStyles = [];
            foreach ($columns as $i => $c) {
                $values[] = $i === 0 ? 'Total' : (isset($c['total']) ? self::cell($c['type'], ($c['total'])($result['totals'])) : null);
                $totalStyles[$i] = $c['type'] === 'money' ? $boldMoney : $bold;
            }
            $writer->addRow(self::row($values, $totalStyles));
        }

        foreach ($columns as $i => $c) {
            $writer->getCurrentSheet()->setColumnWidth($c['type'] === 'money' ? 16 : max(12, min(40, strlen($c['label']) + 4)), $i + 1);
        }
        $writer->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * @param  list<array{label: string, type: string, value: callable, total?: callable}>  $columns
     */
    public function pdf(string $title, array $columns, array $result, array $meta, string $filename, ?array $letterhead = null): Response
    {
        $rows = array_map(fn ($row) => array_map(fn ($c) => self::display($c['type'], ($c['value'])($row)), $columns), $result['items']);

        $totals = null;
        if (isset($result['totals']) && self::hasTotals($columns)) {
            $totals = array_map(
                fn ($c, $i) => $i === 0 ? 'Total' : (isset($c['total']) ? self::display($c['type'], ($c['total'])($result['totals'])) : ''),
                $columns, array_keys($columns),
            );
        }

        [$orientation, $fontSize] = count($columns) > 6 ? ['landscape', '8pt'] : ['portrait', '8.5pt'];

        return Pdf::loadView('restaurant.report', [
            'title' => $title,
            'letterhead' => $letterhead,
            'meta' => $meta,
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
            'fontSize' => $fontSize,
        ])
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper('a4', $orientation)
            ->download($filename);
    }

    /**
     * Builds a row cell by cell. Text is always written as a plain string cell: OpenSpout would otherwise
     * turn values starting with "=" into live formulas (spreadsheet formula injection from user-entered names).
     *
     * @param  array<int, mixed>  $values
     * @param  array<int, Style>  $styles  per-column styles
     */
    private static function row(array $values, array $styles = [], ?Style $rowStyle = null): Row
    {
        $cells = [];
        foreach (array_values($values) as $i => $value) {
            $style = $styles[$i] ?? $rowStyle;
            $cells[] = is_string($value) && $value !== '' ? new StringCell($value, $style) : Cell::fromValue($value, $style);
        }

        return new Row($cells);
    }

    private static function hasTotals(array $columns): bool
    {
        return collect($columns)->contains(fn ($c) => isset($c['total']));
    }

    /** Spreadsheet cell: money as a number (so it can be summed), everything else as-is. */
    private static function cell(string $type, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'money' => (float) $value,
            'int' => (int) $value,
            default => (string) $value,
        };
    }

    /** PDF text: money grouped with commas using string operations only (no float rounding). */
    private static function display(string $type, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($type === 'money') {
            $negative = str_starts_with((string) $value, '-');
            [$whole, $fraction] = array_pad(explode('.', ltrim((string) $value, '-'), 2), 2, '00');

            return ($negative ? '-' : '').strrev(implode(',', str_split(strrev($whole), 3))).'.'.str_pad($fraction, 2, '0');
        }

        return (string) $value;
    }
}

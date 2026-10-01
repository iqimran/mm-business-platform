<?php

namespace App\Modules\Car\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a report result (rows + totals already computed and permission-filtered by the
 * report) as Excel or PDF. No figures are calculated here; values are only formatted.
 */
class ReportExporter
{
    /** Maximum rows per export (PDF rendering is far heavier than streaming XLSX). Lowered in tests. */
    public static array $limits = ['xlsx' => 50000, 'pdf' => 2000];

    private const MONEY_FORMAT = '#,##0.00';

    /**
     * @param  array<int, array{label: string, type: string, value: callable, total?: callable}>  $columns
     * @param  array{items: array<int, array>, totals?: array}  $result
     * @param  array<string, string>  $meta  header lines, e.g. ['Generated' => '...', 'Filters' => '...']
     */
    public function xlsx(string $title, array $columns, array $result, array $meta, string $filename, array $summaries = []): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'report');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $title), 0, 31));

        $bold = (new Style)->withFontBold(true);
        $money = (new Style)->withFormat(self::MONEY_FORMAT);
        $boldMoney = (new Style)->withFontBold(true)->withFormat(self::MONEY_FORMAT);

        $writer->addRow(Row::fromValuesWithStyle([$title], (new Style)->withFontBold(true)->withFontSize(14)));
        foreach ($meta as $label => $value) {
            $writer->addRow(Row::fromValues([$label, $value]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyle(array_column($columns, 'label'), $bold));

        $moneyStyles = [];
        foreach ($columns as $i => $c) {
            if ($c['type'] === 'money') {
                $moneyStyles[$i] = $money;
            }
        }

        foreach ($result['items'] as $row) {
            $values = array_map(fn ($c) => self::cell($c['type'], ($c['value'])($row)), $columns);
            $writer->addRow(Row::fromValuesWithStyles($values, $moneyStyles));
        }

        if (isset($result['totals']) && $this->hasTotals($columns)) {
            $values = [];
            $styles = [];
            foreach ($columns as $i => $c) {
                $values[] = $i === 0 ? 'Total' : (isset($c['total']) ? self::cell($c['type'], ($c['total'])($result['totals'])) : null);
                $styles[$i] = $c['type'] === 'money' ? $boldMoney : $bold;
            }
            $writer->addRow(Row::fromValuesWithStyles($values, $styles));
        }

        foreach ($summaries as $summary) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValuesWithStyle([$summary['title']], $bold));
            $writer->addRow(Row::fromValuesWithStyle(array_column($summary['columns'], 'label'), $bold));
            $styles = [];
            foreach ($summary['columns'] as $i => $c) {
                if ($c['type'] === 'money') {
                    $styles[$i] = $money;
                }
            }
            foreach ($summary['rows'] as $row) {
                $writer->addRow(Row::fromValuesWithStyles(array_map(fn ($c) => self::cell($c['type'], ($c['value'])($row)), $summary['columns']), $styles));
            }
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
     * @param  array<int, array{label: string, type: string, value: callable, total?: callable}>  $columns
     */
    public function pdf(string $title, array $columns, array $result, array $meta, string $filename, array $summaries = []): Response
    {
        $rows = array_map(
            fn ($row) => array_map(fn ($c) => self::display($c['type'], ($c['value'])($row)), $columns),
            $result['items'],
        );

        $totals = null;
        if (isset($result['totals']) && $this->hasTotals($columns)) {
            $totals = array_map(
                fn ($c, $i) => $i === 0 ? 'Total' : (isset($c['total']) ? self::display($c['type'], ($c['total'])($result['totals'])) : ''),
                $columns, array_keys($columns),
            );
        }

        // Wide reports get a larger page and smaller type so no column is cut off.
        $count = count($columns);
        [$paper, $orientation, $fontSize] = match (true) {
            $count > 12 => ['a3', 'landscape', '7.5pt'],
            $count > 6 => ['a4', 'landscape', '8pt'],
            default => ['a4', 'portrait', '8.5pt'],
        };

        return Pdf::loadView('reports.car-report', [
            'title' => $title,
            'meta' => $meta,
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
            'fontSize' => $fontSize,
            'summaries' => array_map(fn ($s) => [
                'title' => $s['title'],
                'columns' => $s['columns'],
                'rows' => array_map(fn ($row) => array_map(fn ($c) => self::display($c['type'], ($c['value'])($row)), $s['columns']), $s['rows']),
            ], $summaries),
        ])
            // Embed only the glyphs used (keeps files small).
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper($paper, $orientation)
            ->download($filename);
    }

    private function hasTotals(array $columns): bool
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

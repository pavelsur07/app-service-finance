<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Export;

use App\MarketplaceAnalytics\Infrastructure\Query\UnitExtendedQuery;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

final readonly class UnitExtendedXlsxExporter
{
    private const FORMAT_MONEY = '#,##0.00 "₽"';
    private const FORMAT_PERCENT = '0.00"%"';
    private const FORMAT_INTEGER = '#,##0';

    private const HEADER_BG_COLOR = '2563EB';

    /**
     * Column definitions: [label, field, type].
     * Type: string|money|integer|percent.
     *
     * @var list<array{label: string, field: string, type: string}>
     */
    private const COLUMNS = [
        ['label' => 'SKU',             'field' => 'sku',            'type' => 'string'],
        ['label' => 'Наименование',    'field' => 'title',          'type' => 'string'],
        ['label' => 'Маркетплейс',     'field' => 'marketplace',    'type' => 'string'],
        ['label' => 'Выручка',         'field' => 'revenue',        'type' => 'money'],
        ['label' => 'Кол-во',          'field' => 'quantity',       'type' => 'integer'],
        ['label' => 'Возвраты',        'field' => 'returnsTotal',   'type' => 'money'],
        ['label' => 'Себестоимость',   'field' => 'costPriceTotal', 'type' => 'money'],
        ['label' => 'Себест. ед.',     'field' => 'costPriceUnit',  'type' => 'money'],
        ['label' => 'Комиссия',        'field' => 'commission',     'type' => 'money'],
        ['label' => 'Логистика',       'field' => 'logistics',      'type' => 'money'],
        ['label' => 'Прочие затраты',  'field' => 'otherCosts',     'type' => 'money'],
        ['label' => 'Итого затрат',    'field' => 'totalCosts',     'type' => 'money'],
        ['label' => 'Прибыль',         'field' => 'profit',         'type' => 'money'],
        ['label' => 'Маржа %',         'field' => 'marginPercent',  'type' => 'percent'],
        ['label' => 'ROI %',           'field' => 'roiPercent',     'type' => 'percent'],
    ];

    public function __construct(
        private UnitExtendedQuery $unitExtendedQuery,
    ) {
    }

    public function export(UnitExtendedExportRequest $request, string $outputPath): void
    {
        // Export should always contain full dataset; override the query's default UI limit.
        $result = $this->unitExtendedQuery->execute(
            $request->companyId,
            $request->marketplace,
            $request->periodFrom,
            $request->periodTo,
            \PHP_INT_MAX,
        );

        $dataStyles = $this->buildDataStyles();
        $totalsStyles = $this->buildTotalsStyles();

        $writer = new Writer();
        $writer->openToFile($outputPath);

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Unit-экономика');

            $writer->addRow($this->buildMetaRow($request));
            $writer->addRow(new Row([]));
            $writer->addRow($this->buildHeaderRow());

            foreach ($result['items'] as $item) {
                $writer->addRow($this->buildDataRow($item, $dataStyles));
            }

            $writer->addRow($this->buildTotalsRow($result['totals'], $totalsStyles));
        } finally {
            $writer->close();
        }
    }

    private function buildMetaRow(UnitExtendedExportRequest $request): Row
    {
        $marketplace = $request->marketplace ?? 'все';
        $generatedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $text = sprintf(
            'Период: %s — %s | Маркетплейс: %s | Сформировано: %s',
            $request->periodFrom,
            $request->periodTo,
            $marketplace,
            $generatedAt,
        );

        $style = (new Style())->setFontBold();

        return new Row([Cell::fromValue($text, $style)]);
    }

    private function buildHeaderRow(): Row
    {
        $style = (new Style())
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(self::HEADER_BG_COLOR)
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);

        $cells = [];
        foreach (self::COLUMNS as $column) {
            $cells[] = Cell::fromValue($column['label'], $style);
        }

        return new Row($cells);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, Style> $styles keyed by column type
     */
    private function buildDataRow(array $item, array $styles): Row
    {
        $cells = [];
        foreach (self::COLUMNS as $column) {
            $value = $item[$column['field']] ?? null;
            $cells[] = $this->buildTypedCell($value, $column['type'], $styles[$column['type']]);
        }

        return new Row($cells);
    }

    /**
     * @param array<string, mixed> $totals
     * @param array<string, Style> $styles keyed by column type, plus 'blank' for filler cells
     */
    private function buildTotalsRow(array $totals, array $styles): Row
    {
        $cells = [];
        foreach (self::COLUMNS as $index => $column) {
            if (0 === $index) {
                $cells[] = Cell::fromValue('ИТОГО', $styles['blank']);

                continue;
            }

            // Totals are computed per-listing and not aggregated for these fields.
            if (in_array($column['field'], ['title', 'marketplace', 'costPriceUnit'], true)) {
                $cells[] = Cell::fromValue('', $styles['blank']);

                continue;
            }

            $value = $totals[$column['field']] ?? null;
            $cells[] = $this->buildTypedCell($value, $column['type'], $styles[$column['type']]);
        }

        return new Row($cells);
    }

    private function buildTypedCell(mixed $value, string $type, Style $style): Cell
    {
        if (null === $value) {
            return Cell::fromValue(null, $style);
        }

        return match ($type) {
            'money', 'percent' => Cell::fromValue((float) $value, $style),
            'integer' => Cell::fromValue((int) $value, $style),
            default => Cell::fromValue((string) $value, $style),
        };
    }

    /**
     * @return array<string, Style>
     */
    private function buildDataStyles(): array
    {
        return [
            'string' => new Style(),
            'money' => (new Style())->setFormat(self::FORMAT_MONEY),
            'integer' => (new Style())->setFormat(self::FORMAT_INTEGER),
            'percent' => (new Style())->setFormat(self::FORMAT_PERCENT),
        ];
    }

    /**
     * @return array<string, Style>
     */
    private function buildTotalsStyles(): array
    {
        return [
            'blank' => (new Style())->setFontBold(),
            'string' => (new Style())->setFontBold(),
            'money' => (new Style())->setFontBold()->setFormat(self::FORMAT_MONEY),
            'integer' => (new Style())->setFontBold()->setFormat(self::FORMAT_INTEGER),
            'percent' => (new Style())->setFontBold()->setFormat(self::FORMAT_PERCENT),
        ];
    }
}

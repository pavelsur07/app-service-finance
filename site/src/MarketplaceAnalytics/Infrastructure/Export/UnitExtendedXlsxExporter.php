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
        $result = $this->unitExtendedQuery->execute(
            $request->companyId,
            $request->marketplace,
            $request->periodFrom,
            $request->periodTo,
        );

        $writer = new Writer();
        $writer->openToFile($outputPath);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Unit-экономика');

        $writer->addRow($this->buildMetaRow($request));
        $writer->addRow(new Row([]));
        $writer->addRow($this->buildHeaderRow());

        foreach ($result['items'] as $item) {
            $writer->addRow($this->buildDataRow($item));
        }

        $writer->addRow($this->buildTotalsRow($result['totals']));

        $writer->close();
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
     */
    private function buildDataRow(array $item): Row
    {
        $cells = [];
        foreach (self::COLUMNS as $column) {
            $value = $item[$column['field']] ?? null;
            $cells[] = $this->buildValueCell($value, $column['type']);
        }

        return new Row($cells);
    }

    /**
     * @param array<string, mixed> $totals
     */
    private function buildTotalsRow(array $totals): Row
    {
        $boldStyle = (new Style())->setFontBold();
        $moneyTotalStyle = (new Style())->setFontBold()->setFormat(self::FORMAT_MONEY);
        $integerTotalStyle = (new Style())->setFontBold()->setFormat(self::FORMAT_INTEGER);
        $percentTotalStyle = (new Style())->setFontBold()->setFormat(self::FORMAT_PERCENT);

        $cells = [];
        foreach (self::COLUMNS as $index => $column) {
            if (0 === $index) {
                $cells[] = Cell::fromValue('ИТОГО', $boldStyle);

                continue;
            }

            if (in_array($column['field'], ['title', 'marketplace', 'costPriceUnit'], true)) {
                $cells[] = Cell::fromValue('', $boldStyle);

                continue;
            }

            $value = $totals[$column['field']] ?? null;

            $style = match ($column['type']) {
                'money' => $moneyTotalStyle,
                'integer' => $integerTotalStyle,
                'percent' => $percentTotalStyle,
                default => $boldStyle,
            };

            if (null === $value) {
                $cells[] = Cell::fromValue(null, $style);

                continue;
            }

            $cells[] = $this->buildTypedCell($value, $column['type'], $style);
        }

        return new Row($cells);
    }

    private function buildValueCell(mixed $value, string $type): Cell
    {
        if (null === $value) {
            return Cell::fromValue(null, $this->styleFor($type));
        }

        return $this->buildTypedCell($value, $type, $this->styleFor($type));
    }

    private function buildTypedCell(mixed $value, string $type, Style $style): Cell
    {
        return match ($type) {
            'money' => Cell::fromValue((float) $value, $style),
            'integer' => Cell::fromValue((int) $value, $style),
            'percent' => Cell::fromValue((float) $value, $style),
            default => Cell::fromValue((string) $value, $style),
        };
    }

    private function styleFor(string $type): Style
    {
        return match ($type) {
            'money' => (new Style())->setFormat(self::FORMAT_MONEY),
            'integer' => (new Style())->setFormat(self::FORMAT_INTEGER),
            'percent' => (new Style())->setFormat(self::FORMAT_PERCENT),
            default => new Style(),
        };
    }
}

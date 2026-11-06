<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Export;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\DTO\StockTurnoverReport;
use App\MarketplaceAnalytics\DTO\StockTurnoverRow;
use App\MarketplaceAnalytics\Enum\TurnoverBucket;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Отчёт «Оборачиваемость» в xlsx: лист строк и лист сводки (та же сводка, что на странице — до фильтра корзины). */
final readonly class StockTurnoverXlsxExporter
{
    private const FORMAT_MONEY = '#,##0.00';
    private const FORMAT_INTEGER = '#,##0';
    private const FORMAT_RATE = '#,##0.00';
    private const HEADER_BG_COLOR = '2563EB';

    private const HEADERS = ['Листинг ID', 'Товар', 'SKU', 'Артикул продавца', 'Источник', 'Остаток', 'Продано за период', 'Продаж в день', 'Дней запаса', 'Себестоимость', 'В деньгах', 'Корзина'];

    public function export(StockTurnoverReport $report, string $outputPath): void
    {
        $headerStyle = (new Style())->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor(self::HEADER_BG_COLOR);
        $money = (new Style())->setFormat(self::FORMAT_MONEY);
        $integer = (new Style())->setFormat(self::FORMAT_INTEGER);
        $rate = (new Style())->setFormat(self::FORMAT_RATE);
        $text = new Style();

        $writer = new Writer();
        $writer->openToFile($outputPath);

        try {
            $writer->getCurrentSheet()->setName('Оборачиваемость');
            $writer->addRow(new Row(array_map(static fn (string $h): Cell => Cell::fromValue($h, $headerStyle), self::HEADERS)));

            foreach ($report->rows as $row) {
                $writer->addRow($this->dataRow($row, $text, $integer, $rate, $money));
            }

            $writer->addNewSheetAndMakeItCurrent()->setName('Сводка');
            $s = $report->summary;
            $lines = [
                ['В товаре, ₽', (float) $s->totalValue, $money],
                ['Заморожено, ₽', (float) $s->frozenValue, $money],
                ['Доля замороженного, %', $s->frozenShare, $rate],
            ];
            foreach (TurnoverBucket::cases() as $bucket) {
                $lines[] = [sprintf('Корзина «%s», ₽', $bucket->label()), (float) ($s->valueByBucket[$bucket->value] ?? '0'), $money];
            }
            $lines[] = ['Листингов без себестоимости', $s->listingsWithoutCostPrice, $integer];
            $lines[] = ['Штук без себестоимости', $s->qtyWithoutCostPrice, $integer];
            $lines[] = ['Листингов с остатком', $s->listingsTotal, $integer];
            foreach ($s->snapshotDateBySource as $source => $date) {
                $lines[] = [sprintf('Остатки %s на дату', $this->sourceLabel($source)), $date, $text];
            }
            foreach ($s->staleSources as $source => $date) {
                $lines[] = [sprintf('Остатки %s устарели, последний снимок', $this->sourceLabel($source)), $date, $text];
            }
            foreach ($lines as [$label, $value, $style]) {
                $writer->addRow(new Row([Cell::fromValue($label, $text), Cell::fromValue($value, $style)]));
            }
        } finally {
            $writer->close();
        }
    }

    private function dataRow(StockTurnoverRow $row, Style $text, Style $integer, Style $rate, Style $money): Row
    {
        return new Row([
            Cell::fromValue($row->listingId, $text),
            Cell::fromValue($row->title ?? '', $text),
            Cell::fromValue($row->sku, $text),
            Cell::fromValue($row->supplierSku ?? '', $text),
            Cell::fromValue($this->sourceLabel($row->marketplace), $text),
            Cell::fromValue($row->stockQty, $integer),
            Cell::fromValue($row->salesQty, $integer),
            Cell::fromValue($row->salesPerDay, $rate),
            Cell::fromValue($row->daysOfCover, $integer),
            Cell::fromValue(null === $row->costPrice ? null : (float) $row->costPrice, $money),
            Cell::fromValue(null === $row->stockValue ? null : (float) $row->stockValue, $money),
            Cell::fromValue($row->bucket->label(), $text),
        ]);
    }

    private function sourceLabel(string $source): string
    {
        return MarketplaceType::tryFrom($source)?->getDisplayName() ?? $source;
    }
}

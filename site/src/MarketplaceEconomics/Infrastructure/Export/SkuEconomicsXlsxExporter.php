<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Infrastructure\Export;

use App\MarketplaceEconomics\Application\DTO\EconomicsDynamicsReport;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsReport;
use App\MarketplaceEconomics\Domain\SkuMetrics;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** Отчёт R1 в xlsx: те же строки и итоги, что на экране; суммы — рубли числом. */
final readonly class SkuEconomicsXlsxExporter
{
    public function response(SkuEconomicsReport $report, bool $allocated): BinaryFileResponse
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'me-sku-');
        $writer = new Writer();
        $writer->openToFile($path);
        try {
            $writer->addRow(new Row([Cell::fromValue(sprintf('Экономика SKU · %s – %s', $report->from->format('d.m.Y'), $report->to->format('d.m.Y')))]));
            $writer->addRow(new Row(array_map(static fn (string $h): Cell => Cell::fromValue($h), self::header($allocated))));
            foreach ($report->rows as $row) {
                $writer->addRow($this->row([$row->sku, (string) $row->title], $row->current, $allocated));
            }
            if (null !== $report->unallocated) {
                $writer->addRow($this->row(['Нераспределено', ''], $report->unallocated, $allocated));
            }
            $writer->addRow($this->row(['Итого', ''], $report->total, $allocated));
        } finally {
            $writer->close();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('economics-sku-%s-%s.xlsx', $report->from->format('Ymd'), $report->to->format('Ymd')));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->deleteFileAfterSend();

        return $response;
    }

    public function dynamicsResponse(EconomicsDynamicsReport $report, bool $allocated): BinaryFileResponse
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'me-dyn-');
        $writer = new Writer();
        $writer->openToFile($path);
        try {
            $writer->addRow(new Row([Cell::fromValue(sprintf('Динамика · %s – %s', $report->from->format('d.m.Y'), $report->to->format('d.m.Y')))]));
            $writer->addRow(new Row(array_map(static fn (string $h): Cell => Cell::fromValue($h), ['С', 'По', ...\array_slice(self::header($allocated), 2)])));
            foreach ($report->steps as $step) {
                $writer->addRow($this->row([$step['from']->format('d.m.Y'), $step['to']->format('d.m.Y')], $step['metrics'], $allocated));
            }
            $writer->addRow($this->row(['Итого', ''], $report->total, $allocated));
        } finally {
            $writer->close();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('economics-dynamics-%s-%s.xlsx', $report->from->format('Ymd'), $report->to->format('Ymd')));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->deleteFileAfterSend();

        return $response;
    }

    /** @return list<string> */
    private static function header(bool $allocated): array
    {
        $columns = ['SKU', 'Название', 'Продано, шт.', 'Возвраты, шт.', 'Выручка', 'Себестоимость', 'Валовая', 'Комиссия', 'Логистика туда', 'Логистика обратно', 'Эквайринг', 'Прочие МД1', 'МД1', 'Реклама', 'МД2'];

        return $allocated ? [...$columns, 'Общие затраты', 'Прибыль полная', 'СПП (справочно)'] : [...$columns, 'СПП (справочно)'];
    }

    /** @param array{0: string, 1: string} $label */
    private function row(array $label, SkuMetrics $m, bool $allocated): Row
    {
        $rub = static fn (int $minor): float => $minor / 100;
        $values = [$label[0], $label[1], $m->salesQty, $m->returnsQty, $rub($m->revenue), $rub($m->costPrice), $rub($m->gross()), $rub($m->commission),
            $rub($m->logisticsTo), $rub($m->logisticsBack), $rub($m->acquiring), $rub($m->otherMd1), $rub($m->md1()), $rub($m->ads), $rub($m->md2())];
        $values = $allocated ? [...$values, $rub($m->overhead), $rub($m->full()), $rub($m->spp)] : [...$values, $rub($m->spp)];

        return new Row(array_map(static fn (string|int|float $v): Cell => Cell::fromValue($v), $values));
    }
}

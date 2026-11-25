<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Infrastructure\Export;

use App\MarketplaceEconomics\Application\DTO\EconomicsDynamicsReport;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsCategoryGroup;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsFilter;
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
        $path = $this->writeFile($report, $allocated);
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('economics-sku-%s-%s.xlsx', $report->from->format('Ymd'), $report->to->format('Ymd')));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->deleteFileAfterSend();

        return $response;
    }

    /** Временный xlsx-файл отчёта; удаляет вызывающий. */
    public function writeFile(SkuEconomicsReport $report, bool $allocated): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'me-sku-');
        $writer = new Writer();
        $writer->openToFile($path);
        try {
            $writer->addRow(new Row([Cell::fromValue(sprintf('Экономика SKU · %s – %s', $report->from->format('d.m.Y'), $report->to->format('d.m.Y')))]));
            // По категориям — первая колонка «Категория», листинги по группам; групповых подытогов нет,
            // чтобы сумма столбца в Excel не задваивалась.
            $byCategory = null !== $report->groups;
            $lead = $byCategory ? ['Категория'] : [];
            $blank = $byCategory ? [''] : [];
            $writer->addRow(new Row(array_map(static fn (string $h): Cell => Cell::fromValue($h), [...$lead, ...self::header($allocated)])));
            $sections = $byCategory
                ? array_map(static fn (SkuEconomicsCategoryGroup $g): array => [[$g->name], $g->rows], $report->groups)
                : [[[], $report->rows]];
            foreach ($sections as [$prefix, $rows]) {
                foreach ($rows as $row) {
                    $writer->addRow($this->row([...$prefix, $row->sku, (string) $row->title, SkuEconomicsFilter::CHANNELS[(string) $row->channel] ?? ''], $row->current, $allocated));
                }
            }
            if (null !== $report->unallocated) {
                $writer->addRow($this->row(['Нераспределено', ...$blank, '', ''], $report->unallocated, $allocated));
            }
            $writer->addRow($this->row(['Итого', ...$blank, '', ''], $report->total, $allocated));
        } finally {
            $writer->close();
        }

        return $path;
    }

    public function dynamicsResponse(EconomicsDynamicsReport $report, bool $allocated): BinaryFileResponse
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'me-dyn-');
        $writer = new Writer();
        $writer->openToFile($path);
        try {
            $writer->addRow(new Row([Cell::fromValue(sprintf('Динамика · %s – %s', $report->from->format('d.m.Y'), $report->to->format('d.m.Y')))]));
            $writer->addRow(new Row(array_map(static fn (string $h): Cell => Cell::fromValue($h), ['С', 'По', ...\array_slice(self::header($allocated), 3)])));
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
        $columns = ['SKU', 'Название', 'Канал', 'Продано, шт.', 'Возвраты, шт.', 'Выручка', 'Выручка с СПП', 'НДС к уплате', 'Выручка без НДС', 'Себестоимость', 'Выручка без НДС − себестоимость',
            'Комиссия', 'Логистика туда', 'Логистика обратно', 'Эквайринг', 'Прочие СМ1', 'СМ1', 'СМ1, %', 'Реклама', 'ДРР, %', 'СМ2', 'СМ2, %'];

        return $allocated ? [...$columns, 'Склад и прочие МП', 'СМ3', 'СМ3, %', 'СПП (справочно)'] : [...$columns, 'СПП (справочно)'];
    }

    /** @param list<string> $label первые колонки строки */
    private function row(array $label, SkuMetrics $m, bool $allocated): Row
    {
        $rub = static fn (int $minor): float => $minor / 100;
        $values = [...$label, $m->salesQty, $m->returnsQty, $rub($m->revenue), $rub($m->revenueWithSpp()), $rub($m->vat), $rub($m->revenueNet()), $rub($m->costPrice), $rub($m->gross()), $rub($m->commission),
            $rub($m->logisticsTo), $rub($m->logisticsBack), $rub($m->acquiring), $rub($m->otherMd1), $rub($m->md1()), $m->percentOfRevenue($m->md1()), $rub($m->ads), $m->drr(), $rub($m->md2()), $m->percentOfRevenue($m->md2())];
        $values = $allocated ? [...$values, $rub($m->overhead), $rub($m->full()), $m->percentOfRevenue($m->full()), $rub($m->spp)] : [...$values, $rub($m->spp)];

        return new Row(array_map(static fn (string|int|float|null $v): Cell => Cell::fromValue($v), $values));
    }
}

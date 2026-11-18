<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Infrastructure\Export;

use App\MarketplaceFunnel\Application\DTO\FunnelReport;
use App\MarketplaceFunnel\Application\DTO\FunnelReportRow;
use App\MarketplaceFunnel\Domain\FunnelRow;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** Экран «Воронка» в xlsx: те же строки и итог; ступень без данных — пустая ячейка, выручка — рубли числом. */
final readonly class FunnelXlsxExporter
{
    private const HEADER = ['Маркетплейс', 'Товар', 'Название', 'Артикул', 'Показы', 'Показы в поиске', 'Переходы в карточку', 'В корзину', 'Заказы', 'Выручка заказов, ₽', 'Выкупы', 'Отмены', 'Возвраты'];

    public function response(FunnelReport $report): BinaryFileResponse
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mfn-');
        $writer = new Writer();
        $writer->openToFile($path);
        try {
            $writer->addRow(new Row([new StringCell(sprintf('Воронка · %s – %s', $report->from->format('d.m.Y'), $report->to->format('d.m.Y')), null)]));
            $writer->addRow(new Row(array_map(static fn (string $h): Cell => new StringCell($h, null), self::HEADER)));
            foreach ([...$report->rows, $report->total] as $row) {
                $writer->addRow($this->row($row));
            }
        } finally {
            $writer->close();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('funnel-%s-%s.xlsx', $report->from->format('Ymd'), $report->to->format('Ymd')));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->deleteFileAfterSend();

        return $response;
    }

    private function row(FunnelReportRow $row): Row
    {
        $cells = [FunnelReport::CHANNELS[$row->channel] ?? '', $row->sku, (string) $row->title, (string) $row->supplierSku];
        foreach (FunnelRow::COLUMNS as $column) {
            $value = $row->values[$column] ?? null;
            $cells[] = null === $value ? '' : ('revenue_minor' === $column ? $value / 100 : $value);
        }

        // текст — только StringCell: Cell::fromValue() превращает строку на «=» в формулу
        return new Row(array_map(static fn (string|int|float $v): Cell => \is_string($v) ? new StringCell($v, null) : Cell::fromValue($v), $cells));
    }
}

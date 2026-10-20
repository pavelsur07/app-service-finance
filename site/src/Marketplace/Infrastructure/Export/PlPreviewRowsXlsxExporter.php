<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Export;

use App\Finance\Enum\PLValueFormat;
use App\Marketplace\Application\DTO\MarketplacePlPreviewReport;
use App\Marketplace\Application\DTO\PlPreviewRowsExport;
use App\Marketplace\Enum\PlPreviewColumnKind;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Предпросмотр ОПиУ строками для план-факта: одна сумма — одна строка «канал × колонка × статья».
 * Имена полей — стабильный контракт (версия FORMAT_VERSION), русские подписи — в «Паспорте».
 * Суммы и знаки — как в отчёте, своих формул выгрузка не вводит.
 */
final readonly class PlPreviewRowsXlsxExporter
{
    public const string FORMAT_VERSION = '1.0';
    private const string DATE_FORMAT = 'yyyy-mm-dd';
    private const string DATETIME_FORMAT = 'yyyy-mm-dd hh:mm:ss';

    /** Поля листа «Данные» в порядке колонок → русская подпись. */
    private const array DATA_FIELDS = [
        'period_from' => 'Начало периода',
        'period_to' => 'Конец периода (включительно)',
        'period_key' => 'Ключ периода',
        'iso_week' => 'ISO-неделя, если период — ровно пн–вс',
        'marketplace' => 'Канал',
        'column_kind' => 'Вид колонки: tag — тег, common — общие / без тега, total — итого',
        'tag_id' => 'ID тега',
        'tag_name' => 'Тег / колонка',
        'pl_category_id' => 'ID статьи ОПиУ',
        'pl_code' => 'Код статьи ОПиУ',
        'row_order' => 'Номер строки в дереве ОПиУ',
        'row_level' => 'Уровень строки в дереве',
        'row_name' => 'Статья ОПиУ',
        'category_type' => 'Тип строки: LEAF_INPUT, SUBTOTAL, KPI',
        'value_format' => 'Формат значения: MONEY, QTY, PERCENT, RATIO',
        'value' => 'Значение; PERCENT и RATIO — доля',
        'generated_at' => 'Момент выгрузки, Europe/Moscow',
        'format_ver' => 'Версия формата',
    ];

    public function export(PlPreviewRowsExport $export, \DateTimeImmutable $generatedAt, string $outputPath): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);

        try {
            $rowsByChannel = $this->writeDataSheet($writer, $export, $generatedAt);

            $writer->addNewSheetAndMakeItCurrent();
            $this->writePassportSheet($writer, $export, $generatedAt, $rowsByChannel);
        } finally {
            $writer->close();
        }
    }

    /**
     * @return array<string, int> канал → число строк
     */
    private function writeDataSheet(Writer $writer, PlPreviewRowsExport $export, \DateTimeImmutable $generatedAt): array
    {
        $this->startSheet($writer, 'Данные', array_keys(self::DATA_FIELDS));

        $periodKey = self::periodKey($export);
        $isoWeek = self::isoWeek($export);
        $rowsByChannel = [];
        foreach ($export->channels as $report) {
            $marketplace = self::marketplace($report);
            $rowsByChannel[$marketplace] = 0;
            foreach ($report->columns as $column) {
                $tagId = PlPreviewColumnKind::TAG === $column->kind ? substr($column->key, \strlen('tag:')) : '';
                foreach ($report->rows as $index => $row) {
                    $writer->addRow(new Row([
                        self::date($export->from),
                        self::date($export->to),
                        self::text($periodKey),
                        self::text($isoWeek),
                        self::text($marketplace),
                        self::text($column->kind->value),
                        self::text($tagId),
                        self::text($column->label),
                        self::text($row->id),
                        self::text($row->code ?? ''),
                        new NumericCell($index + 1, null),
                        new NumericCell($row->level, null),
                        self::text($row->name),
                        self::text($row->type->value),
                        self::text($row->format->value),
                        new NumericCell(self::value($row->values[$column->key] ?? 0.0, $row->format), null),
                        self::dateTime($generatedAt),
                        self::text(self::FORMAT_VERSION),
                    ]));
                    ++$rowsByChannel[$marketplace];
                }
            }
        }

        return $rowsByChannel;
    }

    /**
     * @param array<string, int> $rowsByChannel
     */
    private function writePassportSheet(Writer $writer, PlPreviewRowsExport $export, \DateTimeImmutable $generatedAt, array $rowsByChannel): void
    {
        $this->startSheet($writer, 'Паспорт', ['key', 'value', 'label']);
        $add = static function (string $key, Cell $value, string $label) use ($writer): void {
            $writer->addRow(new Row([self::text($key), $value, self::text($label)]));
        };

        $tags = [];
        foreach ($export->channels[0]->columns ?? [] as $column) {
            if (PlPreviewColumnKind::TAG === $column->kind) {
                $tags[] = substr($column->key, \strlen('tag:')).'='.$column->label;
            }
        }
        $tagsOverlap = false;
        foreach ($export->channels as $report) {
            $tagsOverlap = $tagsOverlap || $report->tagsOverlap;
        }

        $add('format_ver', self::text(self::FORMAT_VERSION), self::DATA_FIELDS['format_ver']);
        $add('generated_at', self::dateTime($generatedAt), self::DATA_FIELDS['generated_at']);
        $add('period_from', self::date($export->from), self::DATA_FIELDS['period_from']);
        $add('period_to', self::date($export->to), self::DATA_FIELDS['period_to']);
        $add('period_key', self::text(self::periodKey($export)), self::DATA_FIELDS['period_key']);
        $add('iso_week', self::text(self::isoWeek($export)), self::DATA_FIELDS['iso_week']);
        $add('marketplaces', self::text(implode(';', array_keys($rowsByChannel))), 'Каналы в файле');
        $add('tags', self::text(implode(';', $tags)), 'Теги в файле: id=название');
        $add('tags_overlap', new NumericCell($tagsOverlap ? 1 : 0, null), 'Листинг с несколькими тегами входит в каждый полной суммой: сумма тегов и «Общих» не равна «Итого»');
        $add('rows_count', new NumericCell(array_sum($rowsByChannel), null), 'Строк в листе «Данные»');
        foreach ($rowsByChannel as $marketplace => $count) {
            $add('rows_count_'.$marketplace, new NumericCell($count, null), 'Строк в листе «Данные» по каналу');
        }

        foreach ($export->channels as $report) {
            $marketplace = self::marketplace($report);
            $byStatus = $report->costTotalsByStatus();
            $add('cost_total_'.$marketplace, self::money($report->costTotal), 'Затраты периода в данных маркетплейса');
            $add('cost_mapped_'.$marketplace, self::money($byStatus['mapped']), 'Затраты: размечено в ОПиУ');
            $add('cost_excluded_'.$marketplace, self::money($byStatus['excluded']), 'Затраты: исключено из ОПиУ');
            $add('cost_unmapped_'.$marketplace, self::money($byStatus['unmapped']), 'Затраты: не размечено');
            $add('cost_delta_'.$marketplace, self::money($report->costReconciliationDelta), 'Затраты: расхождение всего и по статусам (ожидается 0)');
        }

        if (null !== $export->all) {
            foreach ($this->channelsVsAll($export, $export->all) as $key => [$name, $delta]) {
                $add('check_channels_vs_all_'.$key, new NumericCell($delta, null), sprintf('«%s», Итого: сумма каналов − все каналы (контроль, не блокирующий)', $name));
            }
        }

        foreach (self::DATA_FIELDS as $field => $label) {
            $add('field_'.$field, self::text(''), 'Поле «Данных» '.$field.': '.$label);
        }
    }

    /**
     * Сумма «Итого» каналов минус «Итого» общего расчёта по денежным и количественным строкам:
     * проценты и коэффициенты по каналам не складываются.
     *
     * @return array<string, array{0: string, 1: float}> код статьи или её id → [название, разность]
     */
    private function channelsVsAll(PlPreviewRowsExport $export, MarketplacePlPreviewReport $all): array
    {
        $sum = [];
        foreach ($export->channels as $report) {
            foreach ($report->rows as $row) {
                $sum[$row->id] = ($sum[$row->id] ?? 0.0) + self::value($row->values['total'] ?? 0.0, $row->format);
            }
        }

        $checks = [];
        foreach ($all->rows as $row) {
            if (PLValueFormat::MONEY !== $row->format && PLValueFormat::QTY !== $row->format) {
                continue;
            }
            $key = null !== $row->code && '' !== $row->code ? $row->code : $row->id;
            $checks[$key] = [$row->name, round(($sum[$row->id] ?? 0.0) - self::value($row->values['total'] ?? 0.0, $row->format), 2)];
        }

        return $checks;
    }

    /**
     * @param list<string> $headers
     */
    private function startSheet(Writer $writer, string $name, array $headers): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName($name);
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));
        $writer->addRow(new Row(array_map(self::text(...), $headers), (new Style())->setFontBold()));
    }

    private static function marketplace(MarketplacePlPreviewReport $report): string
    {
        return ($report->marketplace ?? throw new \LogicException('Отчёт канала без канала'))->value;
    }

    private static function periodKey(PlPreviewRowsExport $export): string
    {
        return $export->from->format('Y-m-d').'_'.$export->to->format('Y-m-d');
    }

    private static function isoWeek(PlPreviewRowsExport $export): string
    {
        $isWeek = '1' === $export->from->format('N')
            && $export->from->modify('+6 days')->format('Y-m-d') === $export->to->format('Y-m-d');

        return $isWeek ? $export->from->format('o-\WW') : '';
    }

    private static function value(float $value, PLValueFormat $format): float
    {
        return match ($format) {
            PLValueFormat::MONEY, PLValueFormat::QTY => round($value, 2),
            PLValueFormat::PERCENT, PLValueFormat::RATIO => $value,
        };
    }

    private static function money(string $amount): NumericCell
    {
        return new NumericCell(round((float) $amount, 2), null);
    }

    private static function date(\DateTimeImmutable $date): DateTimeCell
    {
        return new DateTimeCell($date, (new Style())->setFormat(self::DATE_FORMAT));
    }

    private static function dateTime(\DateTimeImmutable $moment): DateTimeCell
    {
        return new DateTimeCell($moment, (new Style())->setFormat(self::DATETIME_FORMAT));
    }

    /**
     * Пользовательские названия (теги, статьи) — всегда текст: Cell::fromValue() делает формулу из
     * строки на «=».
     */
    private static function text(string $value): StringCell
    {
        return new StringCell($value, null);
    }
}

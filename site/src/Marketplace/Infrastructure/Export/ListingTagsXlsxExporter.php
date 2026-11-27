<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Export;

use App\Marketplace\Application\DTO\ListingTagsExportRow;
use App\Marketplace\Infrastructure\Import\ListingTagsFileReader;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Файл разметки тегов: лист «Листинги» читает обратно ListingTagsFileReader.
 * Все значения — текстовые ячейки: название вида «=…» не становится формулой,
 * SKU не теряет ведущие нули.
 */
final readonly class ListingTagsXlsxExporter
{
    public const string TAGS_SEPARATOR = '; ';

    private const array HEADERS = [ListingTagsFileReader::ID_HEADER, 'Маркетплейс', 'Артикул продавца', 'SKU площадки', 'Размер', 'Название', ListingTagsFileReader::TAGS_HEADER];

    private const array INSTRUCTION = [
        'Впишите теги в колонку «Теги» через «;» и загрузите файл кнопкой «Загрузить разметку» в реестре листингов.',
        'Колонку listing_id не меняйте: по ней строка находит листинг. Остальные колонки — для удобства, при загрузке не читаются.',
        'Пустая ячейка «Теги» ничего не меняет. «-» в ячейке снимает все теги листинга.',
        'Режим «Добавить» (по умолчанию) дописывает теги к существующим, «Заменить» оставляет ровно теги из ячейки.',
        'Строки можно удалять: листинги, которых нет в файле, не меняются.',
        'Нового тега нет на листе «Теги» — он будет создан. Регистр и пробелы по краям не важны: «Зима» и «зима » — один тег.',
        'Перед записью показывается предпросмотр: что добавится, что снимется, какие строки с ошибками.',
    ];

    /**
     * @param list<ListingTagsExportRow> $rows
     * @param list<string> $allTags
     */
    public function export(array $rows, array $allTags, string $outputPath): void
    {
        $options = new Options();
        $options->setColumnWidth(38, 1);
        $options->setColumnWidth(14, 2, 4, 5);
        $options->setColumnWidth(20, 3);
        $options->setColumnWidth(50, 6);
        $options->setColumnWidth(40, 7);

        $writer = new Writer($options);
        $writer->openToFile($outputPath);

        try {
            $this->startSheet($writer, 'Листинги', self::HEADERS);
            foreach ($rows as $row) {
                $writer->addRow(self::textRow([
                    $row->listingId,
                    $row->marketplace,
                    $row->supplierSku ?? '',
                    $row->marketplaceSku,
                    $row->size,
                    $row->name ?? '',
                    implode(self::TAGS_SEPARATOR, $row->tags),
                ]));
            }

            $writer->addNewSheetAndMakeItCurrent();
            $this->startSheet($writer, 'Теги', ['Существующие теги']);
            foreach ($allTags as $tag) {
                $writer->addRow(self::textRow([$tag]));
            }

            $writer->addNewSheetAndMakeItCurrent();
            $this->startSheet($writer, 'Инструкция', ['Как пользоваться']);
            foreach (self::INSTRUCTION as $line) {
                $writer->addRow(self::textRow([$line]));
            }
        } finally {
            $writer->close();
        }
    }

    /**
     * @param list<string> $headers
     */
    private function startSheet(Writer $writer, string $name, array $headers): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName($name);
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));
        $writer->addRow(self::textRow($headers, (new Style())->setFontBold()));
    }

    /**
     * @param list<string> $values
     */
    private static function textRow(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(static fn (string $value): StringCell => new StringCell($value, null), $values), $style);
    }
}

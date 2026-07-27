<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Export;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Шаблон файла для импорта операций ДДС.
 *
 * Заголовки подобраны под синонимы HeaderAutoMapper: заполненный по шаблону файл
 * раскладывается на шаге /mapping автоматически, без ручного выбора колонок.
 */
final readonly class CashImportTemplateXlsxWriter
{
    public const HEADERS = [
        'Дата операции',
        'Номер документа',
        'Приход',
        'Расход',
        'Контрагент',
        'Назначение платежа',
    ];

    /**
     * Строки-примеры показывают формат даты и то, что сумма идёт ровно в одну
     * из колонок приход/расход. Пользователь удаляет их перед импортом.
     */
    private const EXAMPLE_ROWS = [
        ['01.02.2026', '125', 15000.0, null, 'ООО «Пример»', 'Пример: оплата от покупателя — удалите строку перед импортом'],
        ['02.02.2026', '126', null, 4300.5, '', 'Пример: оплата поставщику — удалите строку перед импортом'],
    ];

    private const FORMAT_MONEY = '#,##0.00';

    private const HEADER_BG_COLOR = '2563EB';

    public function write(string $outputPath): void
    {
        $moneyStyle = (new Style())->setFormat(self::FORMAT_MONEY);

        $writer = new Writer();
        $writer->openToFile($outputPath);

        try {
            $writer->getCurrentSheet()->setName('Операции ДДС');
            $writer->addRow($this->buildHeaderRow());

            foreach (self::EXAMPLE_ROWS as $row) {
                $writer->addRow($this->buildExampleRow($row, $moneyStyle));
            }
        } finally {
            $writer->close();
        }
    }

    private function buildHeaderRow(): Row
    {
        $style = (new Style())
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(self::HEADER_BG_COLOR)
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);

        return new Row(array_map(
            static fn (string $label): Cell => Cell::fromValue($label, $style),
            self::HEADERS,
        ));
    }

    /**
     * @param array{0: string, 1: string, 2: ?float, 3: ?float, 4: string, 5: string} $row
     */
    private function buildExampleRow(array $row, Style $moneyStyle): Row
    {
        return new Row([
            Cell::fromValue($row[0]),
            Cell::fromValue($row[1]),
            Cell::fromValue($row[2], $moneyStyle),
            Cell::fromValue($row[3], $moneyStyle),
            Cell::fromValue($row[4]),
            Cell::fromValue($row[5]),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Export;

use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Выгружает операции ДДС в xlsx: весь отфильтрованный набор, без пагинации.
 */
final readonly class CashTransactionXlsxExporter
{
    public const HEADERS = [
        'Дата',
        'Счет / Касса',
        'Сумма',
        'Категория ДДС',
        'Примечание',
        'Контрагент',
    ];

    private const FORMAT_DATE = 'dd.mm.yyyy';
    // Без суффикса ₽: у операции своя валюта, подписывать всё рублём нельзя.
    private const FORMAT_MONEY = '#,##0.00';

    private const HEADER_BG_COLOR = '2563EB';

    public function __construct(
        private CashTransactionRepository $transactionRepository,
    ) {
    }

    /**
     * @param array<string, string|null> $filters
     */
    public function export(Company $company, array $filters, string $outputPath): void
    {
        $dateStyle = (new Style())->setFormat(self::FORMAT_DATE);
        $moneyStyle = (new Style())->setFormat(self::FORMAT_MONEY);

        $writer = new Writer();
        $writer->openToFile($outputPath);

        try {
            $writer->getCurrentSheet()->setName('Операции ДДС');
            $writer->addRow($this->buildHeaderRow());

            foreach ($this->transactionRepository->iterateByCompanyWithFilters($company, $filters) as $row) {
                $writer->addRow($this->buildDataRow($row, $dateStyle, $moneyStyle));
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
     * @param array{
     *     occurredAt: \DateTimeImmutable,
     *     direction: CashDirection,
     *     amount: string,
     *     accountName: string,
     *     categoryName: ?string,
     *     description: ?string,
     *     counterpartyName: ?string
     * } $row
     */
    private function buildDataRow(array $row, Style $dateStyle, Style $moneyStyle): Row
    {
        $amount = (float) $row['amount'];
        if (CashDirection::OUTFLOW === $row['direction']) {
            $amount = -$amount;
        }

        return new Row([
            Cell::fromValue($row['occurredAt'], $dateStyle),
            Cell::fromValue($row['accountName']),
            Cell::fromValue($amount, $moneyStyle),
            Cell::fromValue($row['categoryName'] ?? ''),
            Cell::fromValue($row['description'] ?? ''),
            Cell::fromValue($row['counterpartyName'] ?? ''),
        ]);
    }
}

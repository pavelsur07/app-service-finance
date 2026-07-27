<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Export;

use App\Cash\Entity\Transaction\CashTransaction;
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

            // ponytail: гидрируем сущности целиком — identity map растёт по ходу выгрузки.
            // Для десятков тысяч строк переключить запрос на выборку нужных колонок (HYDRATE_ARRAY).
            foreach ($this->transactionRepository->iterateByCompanyWithFilters($company, $filters) as $transaction) {
                $writer->addRow($this->buildDataRow($transaction, $dateStyle, $moneyStyle));
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

    private function buildDataRow(CashTransaction $transaction, Style $dateStyle, Style $moneyStyle): Row
    {
        $amount = (float) $transaction->getAmount();
        if (CashDirection::OUTFLOW === $transaction->getDirection()) {
            $amount = -$amount;
        }

        $category = $transaction->getCashflowCategory();
        $counterparty = $transaction->getCounterparty();

        return new Row([
            Cell::fromValue($transaction->getOccurredAt(), $dateStyle),
            Cell::fromValue($transaction->getMoneyAccount()->getName()),
            Cell::fromValue($amount, $moneyStyle),
            Cell::fromValue(null !== $category ? $category->getName() : ''),
            Cell::fromValue($transaction->getDescription() ?? ''),
            Cell::fromValue(null !== $counterparty ? $counterparty->getName() : ''),
        ]);
    }
}

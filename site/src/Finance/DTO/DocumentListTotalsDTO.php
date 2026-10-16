<?php

declare(strict_types=1);

namespace App\Finance\DTO;

/**
 * Итог списка документов ОПиУ по всей выборке фильтра. Валюты не смешиваются: документ
 * из операции ДДС несёт её валюту без пересчёта. income/expense/result — рубли (основная
 * строка полосы итога), прочие валюты — others().
 */
final readonly class DocumentListTotalsDTO
{
    public string $income;
    public string $expense;
    public string $result;
    /** @var list<DocumentCurrencyTotalsDTO> */
    private array $others;

    /**
     * @param list<DocumentCurrencyTotalsDTO> $currencies
     */
    public function __construct(
        public int $documents,
        public int $operations,
        array $currencies,
    ) {
        $rub = null;
        $others = [];
        foreach ($currencies as $totals) {
            if ('RUB' === strtoupper($totals->currency)) {
                $rub = $totals;
            } else {
                $others[] = $totals;
            }
        }
        usort($others, static fn (DocumentCurrencyTotalsDTO $a, DocumentCurrencyTotalsDTO $b): int => $a->currency <=> $b->currency);

        $rub ??= new DocumentCurrencyTotalsDTO('RUB', '0', '0');
        $this->income = $rub->income;
        $this->expense = $rub->expense;
        $this->result = $rub->result;
        $this->others = $others;
    }

    /**
     * Итог из двух агрегатов списка: обычные документы уже просуммированы запросом по валютам,
     * marketplace_pl — подписанные суммы по (документ, статья, природа): модуль берётся от
     * суммы, а не от операции, — списание −X и сторно +X взаимно гасятся, как в регистре ОПиУ.
     *
     * @param list<array{currency: string, documents: int|string, operations: int|string, incomeTotal: string|null, expenseTotal: string|null}> $plain
     * @param list<array{currency: string, bucket: string, signedTotal: string}>                                                               $marketplace
     */
    public static function fromAggregates(array $plain, array $marketplace): self
    {
        $documents = 0;
        $operations = 0;
        /** @var array<string, array{income: string, expense: string}> $byCurrency */
        $byCurrency = [];
        foreach ($plain as $row) {
            $documents += (int) $row['documents'];
            $operations += (int) $row['operations'];
            $byCurrency[$row['currency']] = [
                'income' => bcadd((string) ($row['incomeTotal'] ?? '0'), '0', 2),
                'expense' => bcadd((string) ($row['expenseTotal'] ?? '0'), '0', 2),
            ];
        }
        foreach ($marketplace as $row) {
            $key = 'INCOME' === $row['bucket'] ? 'income' : 'expense';
            $sum = (string) $row['signedTotal'];
            $abs = -1 === bccomp($sum, '0', 2) ? bcmul($sum, '-1', 2) : $sum;
            $byCurrency[$row['currency']] ??= ['income' => '0.00', 'expense' => '0.00'];
            $byCurrency[$row['currency']][$key] = bcadd($byCurrency[$row['currency']][$key], $abs, 2);
        }

        $currencies = [];
        foreach ($byCurrency as $currency => $sums) {
            $currencies[] = new DocumentCurrencyTotalsDTO($currency, $sums['income'], $sums['expense']);
        }

        return new self($documents, $operations, $currencies);
    }

    /**
     * @return list<DocumentCurrencyTotalsDTO>
     */
    public function others(): array
    {
        return $this->others;
    }
}

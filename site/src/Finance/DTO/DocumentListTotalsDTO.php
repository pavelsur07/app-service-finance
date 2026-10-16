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
     * @return list<DocumentCurrencyTotalsDTO>
     */
    public function others(): array
    {
        return $this->others;
    }
}

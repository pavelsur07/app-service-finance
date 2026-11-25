<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/** Консолидированный отчёт ДДС группы за период: только RUB, другие валюты — отдельно. */
final readonly class CashflowGroupReport
{
    /**
     * @param array<string, string> $companies companyId => название, головная первой
     * @param list<CashflowGroupReportLine> $lines статьи эталона в порядке дерева головной
     * @param array<string, array<string, string>> $otherCurrencies валюта => companyId => сумма
     * @param array<string, string> $companyInns
     * @param list<CashflowAccountStatus> $accounts
     * @param list<string> $companiesWithoutInn названия: их обороты внутригрупповыми не распознать
     */
    public function __construct(
        public string $headCompanyId,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $companies,
        public array $lines,
        public CashflowGroupReportLine $unmatched,
        public CashflowGroupReportLine $totals,
        public array $otherCurrencies,
        public array $companiesWithoutInn,
        public CashflowReconciliation $reconciliation,
        public array $accounts,
        public string $withoutUnpaired,
        private array $companyInns,
    ) {
    }

    /** @return list<CashflowAccountStatus> счета встречной компании, чья выписка заканчивается до операции */
    public function missingDataHints(IntragroupTransaction $transaction): array
    {
        $day = $transaction->occurredAt->format('Y-m-d');

        return array_values(array_filter($this->accounts, fn (CashflowAccountStatus $account): bool => $account->companyId !== $transaction->companyId
            && $this->companyInns[$account->companyId] === $transaction->counterpartyInn
            && null !== $account->lastDate && $account->lastDate < $day));
    }
}

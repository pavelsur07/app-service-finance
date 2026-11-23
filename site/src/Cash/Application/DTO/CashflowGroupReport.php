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
    ) {
    }
}

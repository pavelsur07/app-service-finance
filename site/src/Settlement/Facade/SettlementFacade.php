<?php

declare(strict_types=1);

namespace App\Settlement\Facade;

use App\Settlement\Application\DTO\SettlementCardPage;
use App\Settlement\Application\DTO\SettlementReportFilter;
use App\Settlement\Application\DTO\SettlementReportPage;
use App\Settlement\Query\SettlementReportQuery;

final readonly class SettlementFacade implements SettlementFacadeInterface
{
    public function __construct(private SettlementReportQuery $query)
    {
    }

    public function report(string $companyId, SettlementReportFilter $filter): SettlementReportPage
    {
        return $this->query->report($companyId, $filter);
    }

    public function card(string $companyId, string $counterpartyId, SettlementReportFilter $filter): SettlementCardPage
    {
        return $this->query->card($companyId, $counterpartyId, $filter);
    }
}

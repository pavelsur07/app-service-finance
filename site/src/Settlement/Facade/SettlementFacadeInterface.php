<?php

declare(strict_types=1);

namespace App\Settlement\Facade;

use App\Settlement\Application\DTO\SettlementCardPage;
use App\Settlement\Application\DTO\SettlementReportFilter;
use App\Settlement\Application\DTO\SettlementReportPage;

interface SettlementFacadeInterface
{
    public function report(string $companyId, SettlementReportFilter $filter): SettlementReportPage;

    public function card(string $companyId, string $counterpartyId, SettlementReportFilter $filter): SettlementCardPage;
}

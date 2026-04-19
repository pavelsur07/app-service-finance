<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Enum\AdRawDocumentStatus;

interface AdRawDocumentRepositoryInterface
{
    /**
     * Количество raw-документов компании за период для конкретного маркетплейса.
     *
     * Реализация обязана:
     *  - ограничивать выборку `company_id = :companyId` (IDOR-guard на уровне SQL);
     *  - сравнивать `report_date` включительно по обеим границам `[$from, $to]`;
     *  - при переданном `$statusFilter` добавлять условие `status = :status`.
     *
     * @param string                   $marketplace  значение {@see \App\Marketplace\Enum\MarketplaceType::value}
     * @param AdRawDocumentStatus|null $statusFilter null — считать во всех статусах
     */
    public function countByCompanyMarketplaceAndDateRange(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?AdRawDocumentStatus $statusFilter = null,
    ): int;
}

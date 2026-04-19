<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;

interface AdRawDocumentRepositoryInterface
{
    /**
     * Загружает документ по ID с обязательной IDOR-проверкой company_id.
     */
    public function findByIdAndCompany(string $id, string $companyId): ?AdRawDocument;

    /**
     * Помечает документ FAILED через raw DBAL UPDATE минуя UoW.
     *
     * Идемпотентно: повторный вызов на уже FAILED документе вернёт 0;
     * IDOR-safe: company_id в WHERE.
     *
     * @return int число обновлённых строк (1 — успешно, 0 — уже FAILED либо не наш)
     */
    public function markFailedWithReason(string $documentId, string $companyId, string $reason): int;

    /**
     * Количество raw-документов компании за период для конкретного маркетплейса.
     *
     * Реализация обязана:
     *  - ограничивать выборку `company_id = :companyId` (IDOR-guard на уровне SQL);
     *  - сравнивать `report_date` включительно по обеим границам `[$from, $to]`;
     *  - при переданном `$statusFilter` добавлять условие `status = :status`.
     *
     * @param string $marketplace значение {@see \App\Marketplace\Enum\MarketplaceType::value}
     * @param AdRawDocumentStatus|null $statusFilter null — считать во всех статусах
     */
    public function countByCompanyMarketplaceAndDateRange(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?AdRawDocumentStatus $statusFilter = null,
    ): int;

    /**
     * Документы компании за период для конкретного маркетплейса, отсортированные по report_date DESC.
     *
     * Реализация обязана:
     *  - ограничивать выборку `company_id = :companyId` (IDOR-guard на уровне SQL);
     *  - сравнивать `report_date` включительно по обеим границам `[$from, $to]`.
     *
     * @param string $marketplace значение {@see \App\Marketplace\Enum\MarketplaceType::value}
     *
     * @return list<AdRawDocument>
     */
    public function findByCompanyMarketplaceAndDateRange(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array;
}

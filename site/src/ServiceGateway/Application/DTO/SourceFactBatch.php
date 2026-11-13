<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

/**
 * Пачка фактов сервиса (`source-fact-envelope` v1): загруженная ревизия периода кабинета.
 * `companyId` определяет Symfony по `connectionRef`; null — активного подключения нет.
 */
final readonly class SourceFactBatch
{
    public function __construct(
        public string $service,
        public string $batchId,
        public string $connectionRef,
        public ?string $companyId,
        public string $resourceType,
        public string $periodKey,
        public int $revision,
        public string $contentHash,
        public int $pageCount,
    ) {
    }

    public function withCompanyId(?string $companyId): self
    {
        return new self($this->service, $this->batchId, $this->connectionRef, $companyId, $this->resourceType, $this->periodKey, $this->revision, $this->contentHash, $this->pageCount);
    }
}

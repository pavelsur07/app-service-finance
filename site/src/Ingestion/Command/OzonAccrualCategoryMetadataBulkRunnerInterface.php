<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

interface OzonAccrualCategoryMetadataBulkRunnerInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function targets(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        ?string $companyId,
        ?string $shopRef,
    ): array;

    /**
     * @param list<array<string, mixed>> $targets
     *
     * @return array{
     *     totals: array<string, int>,
     *     failedRawRecords: list<array<string, string>>,
     *     failedTargets: list<array<string, string>>
     * }
     */
    public function refreshTargets(
        array $targets,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        int $limitPerShop,
        bool $dryRun,
    ): array;
}

<?php

declare(strict_types=1);

namespace App\Ingestion\Facade;

use App\Ingestion\Application\Action\ConfirmOzonAccrualTypeAction;
use App\Ingestion\Application\Action\ImportOzonAccrualDetailsAction;
use App\Ingestion\Application\Action\ResolveOzonAccrualTypeGroupDisputeAction;
use App\Ingestion\Application\DTO\CategoryGroupView;
use App\Ingestion\Application\DTO\OzonAccrualDetailsImportResult;
use App\Ingestion\Application\Service\OzonAccrualTypeQueue;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Infrastructure\Query\OzonAccrualTypeQueueQuery;
use App\Ingestion\Infrastructure\Xlsx\OzonAccrualDetailsXlsxReader;
use App\Ingestion\Repository\CategoryGroupRepository;

final class OzonAccrualTypesAdminFacade implements OzonAccrualTypesAdminFacadeInterface
{
    public function __construct(
        private readonly OzonAccrualTypeQueue $typeQueue,
        private readonly OzonAccrualTypeQueueQuery $typeQueueQuery,
        private readonly CategoryGroupRepository $groupRepository,
        private readonly ConfirmOzonAccrualTypeAction $confirmType,
        private readonly ResolveOzonAccrualTypeGroupDisputeAction $resolveDispute,
        private readonly OzonAccrualDetailsXlsxReader $detailsReader,
        private readonly ImportOzonAccrualDetailsAction $importDetails,
    ) {
    }

    public function typeQueue(int $limit = 100): array
    {
        return $this->typeQueue->items($limit);
    }

    public function groupDisputes(): array
    {
        return $this->typeQueueQuery->disputes();
    }

    public function ozonGroups(): array
    {
        return array_values(array_map(
            static fn ($group): CategoryGroupView => new CategoryGroupView($group->getCode(), $group->getLabel()),
            $this->groupRepository->findBySourceIndexedByCode(IngestSource::OZON),
        ));
    }

    public function confirmType(int $typeId, string $groupCode, string $label, string $actorUserId): int
    {
        return ($this->confirmType)($typeId, $groupCode, $label, $actorUserId);
    }

    public function resolveGroupDispute(int $typeId, string $expectedGroupCode, string $expectedDisputedGroupCode, bool $accept, string $actorUserId): void
    {
        ($this->resolveDispute)($typeId, $expectedGroupCode, $expectedDisputedGroupCode, $accept, $actorUserId);
    }

    public function importDetailsReport(string $companyId, string $path, bool $dryRun, string $actorUserId): OzonAccrualDetailsImportResult
    {
        $report = $this->detailsReader->read($path);

        return ($this->importDetails)($companyId, $report->rows, $dryRun, $actorUserId)->withSkippedRows($report->skippedRows);
    }
}

<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\EnsureOzonAccrualCursorCommand;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Repository\IngestCursorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class EnsureOzonAccrualCursorAction
{
    /**
     * Legacy cursor rows are kept for audit and may be cleaned in a separate operational follow-up.
     *
     * @var list<string>
     */
    private const LEGACY_OZON_RESOURCE_TYPES = [
        'ozon_seller_daily_report',
        'ozon_seller_realization',
    ];

    public function __construct(
        private IngestCursorRepository $cursorRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(EnsureOzonAccrualCursorCommand $command): void
    {
        if ([] !== $this->cursorRepository->findByResource($command->companyId, $command->connectionRef, OzonResourceType::ACCRUAL_BY_DAY)) {
            return;
        }

        $seedValue = $this->legacySeedCursorValue($command->companyId, $command->connectionRef)
            ?? (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');

        $cursor = $this->cursorRepository->getOrCreate(
            $command->companyId,
            $command->connectionRef,
            OzonResourceType::ACCRUAL_BY_DAY,
            $command->connectionRef,
        );
        $cursor->advance($seedValue, Uuid::uuid7()->toString());
        $this->entityManager->flush();
    }

    private function legacySeedCursorValue(string $companyId, string $connectionRef): ?string
    {
        $seed = null;
        foreach (self::LEGACY_OZON_RESOURCE_TYPES as $resourceType) {
            foreach ($this->cursorRepository->findByResource($companyId, $connectionRef, $resourceType) as $cursor) {
                $cursorValue = $this->normalizedCursorDate($cursor->getCursorValue());
                if (null === $cursorValue) {
                    continue;
                }

                if (null === $seed || $cursorValue < $seed) {
                    $seed = $cursorValue;
                }
            }
        }

        return $seed;
    }

    private function normalizedCursorDate(string $cursorValue): ?string
    {
        try {
            return (new \DateTimeImmutable($cursorValue))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Entity\IngestionTenantProbe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TenantVisibilityMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantVisibilityRecorder $recorder,
    ) {
    }

    public function __invoke(TenantVisibilityMessage $message): void
    {
        $visibleProbeIds = $this->entityManager->createQueryBuilder()
            ->select('probe.id')
            ->from(IngestionTenantProbe::class, 'probe')
            ->orderBy('probe.createdAt', 'ASC')
            ->addOrderBy('probe.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        $this->recorder->record($visibleProbeIds);
    }
}

<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\StartIncrementalCommand;
use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Exception\ActiveBackfillExistsException;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\Repository\SyncJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class StartIncrementalAction
{
    public function __construct(
        private SyncJobRepository $syncJobRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(StartIncrementalCommand $command): string
    {
        $activeJob = $this->syncJobRepository->findLatestForResource(
            $command->companyId,
            $command->connectionRef,
            $command->resourceType,
            $command->shopRef,
        );

        if (null !== $activeJob) {
            throw new ActiveBackfillExistsException('Sync job for requested resource is already active.');
        }

        $job = new SyncJob(
            companyId: $command->companyId,
            connectionRef: $command->connectionRef,
            source: $command->source,
            resourceType: $command->resourceType,
            kind: SyncJobKind::INCREMENTAL,
            shopRef: $command->shopRef,
        );

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new RunSyncChunkMessage($command->companyId, $job->getId()));

        return $job->getId();
    }
}

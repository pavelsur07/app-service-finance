<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\SplitJobCommand;
use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Exception\InvalidJobForSplitException;
use App\Ingestion\Exception\JobAlreadySplitException;
use App\Ingestion\Exception\SyncJobNotFoundException;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\Repository\SyncJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final readonly class SplitJobIntoChunksAction
{
    public function __construct(
        private SyncJobRepository $syncJobRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @return list<string>
     */
    public function __invoke(SplitJobCommand $command): array
    {
        $parent = $this->syncJobRepository->findByIdAndCompany($command->parentJobId, $command->companyId);
        if (null === $parent) {
            throw new SyncJobNotFoundException('Sync job was not found.');
        }

        if (SyncJobKind::BACKFILL !== $parent->getKind() || null === $parent->getWindowFrom() || null === $parent->getWindowTo()) {
            throw new InvalidJobForSplitException('Sync job cannot be split into chunks.');
        }

        if ($parent->getProgressTotal() > 0) {
            throw new JobAlreadySplitException('Sync job is already split into chunks.');
        }

        $chunkIds = [];
        foreach ($this->buildWindows($parent->getWindowFrom(), $parent->getWindowTo(), $command->chunkSizeInDays) as [$from, $to]) {
            $chunk = new SyncJob(
                companyId: $parent->getCompanyId(),
                connectionRef: $parent->getConnectionRef(),
                source: $parent->getSource(),
                resourceType: $parent->getResourceType(),
                kind: SyncJobKind::BACKFILL,
                windowFrom: $from,
                windowTo: $to,
                shopRef: $parent->getShopRef(),
                parentJobId: $parent->getId(),
            );

            $this->entityManager->persist($chunk);
            $chunkIds[] = $chunk->getId();
        }

        $parent->setProgressTotal(count($chunkIds));
        $parent->markRunning();
        $this->entityManager->flush();

        foreach ($chunkIds as $index => $chunkId) {
            $delaySeconds = $command->initialDelaySeconds + ($index * $command->chunkDelayStepSeconds);
            $stamps = $delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : [];

            $this->messageBus->dispatch(new RunSyncChunkMessage($command->companyId, $chunkId), $stamps);
        }

        return $chunkIds;
    }

    /**
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function buildWindows(\DateTimeImmutable $from, \DateTimeImmutable $to, int $chunkSizeInDays): array
    {
        $windows = [];
        $cursor = $from->setTime(0, 0);
        $lastDay = $to->setTime(0, 0);

        while ($cursor <= $lastDay) {
            $chunkTo = $cursor->modify(sprintf('+%d days', $chunkSizeInDays - 1));
            if ($chunkTo > $lastDay) {
                $chunkTo = $lastDay;
            }

            $windows[] = [$cursor, $chunkTo];
            $cursor = $chunkTo->modify('+1 day');
        }

        return $windows;
    }
}

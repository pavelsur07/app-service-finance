<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\MessageHandler;

use App\MarketplaceAnalytics\Domain\Service\SnapshotRecalcPolicy;
use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
use App\MarketplaceAnalytics\Message\RecalcSnapshotsMessage;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RecalcSnapshotsMessageHandler
{
    public function __construct(
        private readonly SnapshotRecalcPolicy $snapshotRecalcPolicy,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppLogger $logger,
    ) {}

    public function __invoke(RecalcSnapshotsMessage $message): void
    {
        try {
            $period = AnalysisPeriod::custom(
                new \DateTimeImmutable($message->dateFrom),
                new \DateTimeImmutable($message->dateTo),
            );

            $this->snapshotRecalcPolicy->recalcByUserRequest(
                $message->companyId,
                $period,
            );

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Recalc snapshots failed', $e, [
                'companyId' => $message->companyId,
                'dateFrom' => $message->dateFrom,
                'dateTo' => $message->dateTo,
            ]);

            throw $e;
        }
    }
}

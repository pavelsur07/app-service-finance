<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application;

use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
use App\MarketplaceAnalytics\Message\RecalcSnapshotsMessage;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecalcSnapshotAction
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function __invoke(string $companyId, AnalysisPeriod $period): string
    {
        $jobId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new RecalcSnapshotsMessage(
            companyId: $companyId,
            dateFrom: $period->dateFrom->format('Y-m-d'),
            dateTo: $period->dateTo->format('Y-m-d'),
        ));

        return $jobId;
    }
}

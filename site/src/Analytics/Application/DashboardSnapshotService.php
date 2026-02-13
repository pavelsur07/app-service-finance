<?php

namespace App\Analytics\Application;

use App\Analytics\Api\Response\SnapshotContextResponse;
use App\Analytics\Api\Response\SnapshotResponse;
use App\Analytics\Domain\Period;
use App\Company\Entity\Company;
use DateTimeImmutable;

final class DashboardSnapshotService
{
    public function getSnapshot(Company $company, Period $period): SnapshotResponse
    {
        $prevPeriod = $period->prevPeriod();

        return new SnapshotResponse(
            new SnapshotContextResponse(
                companyId: (string) $company->getId(),
                from: $period->getFrom(),
                to: $period->getTo(),
                days: $period->days(),
                prevFrom: $prevPeriod->getFrom(),
                prevTo: $prevPeriod->getTo(),
                vatMode: $company->getTaxSystem()?->value,
                lastUpdatedAt: new DateTimeImmutable(),
            ),
        );
    }
}

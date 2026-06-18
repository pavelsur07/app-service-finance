<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Application;

use App\Finance\Application\Action\MarkPnlPeriodDirtyAction;
use App\Finance\Application\Command\MarkPnlPeriodDirtyCommand;
use App\Ingestion\Enum\PLDirtyPeriodReason;
use App\Ingestion\Enum\PLDirtyPeriodStatus;
use App\Ingestion\Repository\PLDirtyPeriodRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class MarkPnlPeriodDirtyActionTest extends IntegrationTestCase
{
    public function testMarkDirtyIsIdempotentAndReopensFailedOrBlockedPeriod(): void
    {
        $companyId = Uuid::uuid7()->toString();
        /** @var MarkPnlPeriodDirtyAction $action */
        $action = self::getContainer()->get(MarkPnlPeriodDirtyAction::class);
        /** @var PLDirtyPeriodRepository $repository */
        $repository = self::getContainer()->get(PLDirtyPeriodRepository::class);

        $command = new MarkPnlPeriodDirtyCommand($companyId, 2026, 2, '', PLDirtyPeriodReason::INGEST);
        $action($command);
        $action($command);

        self::assertSame(1, $repository->countByStatus($companyId, PLDirtyPeriodStatus::PENDING));

        $period = $repository->findOne($companyId, 2026, 2, '');
        self::assertNotNull($period);
        $period->markRebuilding();
        $period->markFailed('temporary');
        $this->em->flush();

        $action($command);
        $this->em->clear();

        self::assertSame(PLDirtyPeriodStatus::PENDING, $repository->findOne($companyId, 2026, 2, '')?->getStatus());

        $period = $repository->findOne($companyId, 2026, 2, '');
        self::assertNotNull($period);
        $period->markBlockedByClose('closed month');
        $this->em->flush();

        $action($command);
        $this->em->clear();

        self::assertSame(PLDirtyPeriodStatus::PENDING, $repository->findOne($companyId, 2026, 2, '')?->getStatus());
    }
}

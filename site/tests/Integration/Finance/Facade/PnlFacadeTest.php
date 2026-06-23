<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Facade;

use App\Finance\Application\Command\MarkPnlPeriodDirtyCommand;
use App\Finance\Facade\PnlFacade;
use App\Ingestion\Enum\PLDirtyPeriodReason;
use App\Ingestion\Enum\PLDirtyPeriodStatus;
use App\Ingestion\Repository\PLDirtyPeriodRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Confirms PnlFacade::markPeriodDirty is a usable single entry point for marking
 * pnl_dirty_periods from outside App\Ingestion — the contract TASK-FIX-06 relies on.
 */
final class PnlFacadeTest extends IntegrationTestCase
{
    public function testMarkPeriodDirtyCreatesThenReopensTerminalPeriod(): void
    {
        $companyId = Uuid::uuid7()->toString();

        /** @var PnlFacade $facade */
        $facade = self::getContainer()->get(PnlFacade::class);
        /** @var PLDirtyPeriodRepository $repository */
        $repository = self::getContainer()->get(PLDirtyPeriodRepository::class);

        $command = new MarkPnlPeriodDirtyCommand($companyId, 2026, 3, '', PLDirtyPeriodReason::INGEST);

        // First call creates a pending period; a repeated call is idempotent.
        $facade->markPeriodDirty($command);
        $facade->markPeriodDirty($command);
        $this->em->clear();

        self::assertSame(1, $repository->countByStatus($companyId, PLDirtyPeriodStatus::PENDING));

        // Drive it to a terminal status, then a fresh markPeriodDirty reopens it.
        $period = $repository->findOne($companyId, 2026, 3, '');
        self::assertNotNull($period);
        $period->markRebuilding();
        $period->markDone();
        $this->em->flush();
        $this->em->clear();

        $facade->markPeriodDirty($command);
        $this->em->clear();

        self::assertSame(
            PLDirtyPeriodStatus::PENDING,
            $repository->findOne($companyId, 2026, 3, '')?->getStatus(),
        );
    }
}

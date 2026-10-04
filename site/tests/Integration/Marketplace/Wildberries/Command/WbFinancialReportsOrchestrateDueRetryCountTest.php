<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Wildberries\Command;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Marketplace\Wildberries\Command\WbFinancialReportsOrchestrateCommand;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Принцип health-gates: область проверки = область repair. Оркестратор вызывает planDueRetry()
 * только если собственный счётчик due-retry > 0, поэтому счётчик обязан видеть те же зависшие
 * RAW_LOADED/PROCESSING дни, что и MarketplaceFinancialReportSyncStatusRepository::findRetryDueDays().
 */
final class WbFinancialReportsOrchestrateDueRetryCountTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const CONNECTION_ID = '22222222-2222-4222-8222-222222222222';

    public function testDueRetryCounterSeesStaleProcessingAndRawLoadedButNotFreshOrYoung(): void
    {
        $repository = self::getContainer()->get(MarketplaceFinancialReportSyncStatusRepository::class);
        $command = self::getContainer()->get(WbFinancialReportsOrchestrateCommand::class);

        foreach (['2026-02-01' => '-7 hours', '2026-02-02' => '-7 hours', '2026-02-03' => '-3 hours', '2026-02-04' => '-1 minutes'] as $day => $age) {
            $entity = new MarketplaceFinancialReportSyncStatus(
                Uuid::uuid7()->toString(),
                self::COMPANY_ID,
                self::CONNECTION_ID,
                MarketplaceType::WILDBERRIES,
                'sales_report',
                'endpoint',
                new \DateTimeImmutable($day),
            );
            $entity->markLoading(FinancialReportSyncMode::DAILY);
            $entity->markRawLoaded(Uuid::uuid4()->toString(), 10, 'hash');
            if ('2026-02-01' !== $day) {
                $entity->markProcessing();
            }
            $repository->save($entity);
        }
        $this->em->flush();

        foreach (['2026-02-01' => '-7 hours', '2026-02-02' => '-7 hours', '2026-02-03' => '-3 hours', '2026-02-04' => '-1 minutes'] as $day => $age) {
            $this->em->getConnection()->executeStatement(
                'UPDATE marketplace_financial_report_sync_statuses SET updated_at = :ts WHERE company_id = :c AND business_date = :d',
                ['ts' => (new \DateTimeImmutable($age))->format('Y-m-d H:i:s'), 'c' => self::COMPANY_ID, 'd' => $day],
            );
        }

        $count = (new \ReflectionMethod($command, 'countDueRetry'))->invoke(
            $command,
            self::COMPANY_ID,
            self::CONNECTION_ID,
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-02-28'),
        );

        self::assertSame(2, $count, 'RAW_LOADED (01) и PROCESSING (02) старше 6 ч считаются due-retry; 3 ч и свежий — нет.');
    }
}

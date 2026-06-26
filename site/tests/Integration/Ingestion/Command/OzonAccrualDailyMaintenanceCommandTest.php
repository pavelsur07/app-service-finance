<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Command\OzonAccrualCategoryMetadataBulkRunnerInterface;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonAccrualDailyMaintenanceCommandTest extends IntegrationTestCase
{
    public function testExecuteRunsFullMaintenancePipeline(): void
    {
        $runner = new FakeOzonAccrualCategoryMetadataBulkRunner();
        self::getContainer()->set(OzonAccrualCategoryMetadataBulkRunnerInterface::class, $runner);

        $tester = $this->tester();
        $exit = $tester->execute([
            '--from' => '2026-06-01',
            '--to' => '2026-06-25',
            '--limit-per-shop' => '10',
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('Ozon accrual daily maintenance finished.', $tester->getDisplay());
        self::assertSame('2026-06-01', $runner->from?->format('Y-m-d'));
        self::assertSame('2026-06-25', $runner->to?->format('Y-m-d'));
        self::assertSame(10, $runner->limitPerShop);
        self::assertFalse($runner->dryRun);
    }

    public function testReturnsFailureAfterProcessingWhenBulkRefreshHasFailures(): void
    {
        $runner = new FakeOzonAccrualCategoryMetadataBulkRunner();
        $runner->totals['failedRawRecords'] = 1;
        self::getContainer()->set(OzonAccrualCategoryMetadataBulkRunnerInterface::class, $runner);

        $tester = $this->tester();
        $exit = $tester->execute([
            '--from' => '2026-06-01',
            '--to' => '2026-06-25',
            '--execute' => true,
        ]);

        self::assertSame(Command::FAILURE, $exit, $tester->getDisplay());
        self::assertStringContainsString('finished with failures', $tester->getDisplay());
        self::assertFalse($runner->dryRun);
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:ingestion:ozon-accrual:daily-maintenance'));
    }
}

final class FakeOzonAccrualCategoryMetadataBulkRunner implements OzonAccrualCategoryMetadataBulkRunnerInterface
{
    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;
    public int $limitPerShop = 0;
    public bool $dryRun = false;

    /**
     * @var array<string, int>
     */
    public array $totals = [
        'targets' => 1,
        'rawRecords' => 1,
        'scanned' => 3,
        'matched' => 3,
        'updated' => 2,
        'unchanged' => 1,
        'missing' => 0,
        'failedRawRecords' => 0,
        'failedTargets' => 0,
        'rawRecordPages' => 1,
    ];

    public function targets(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        ?string $companyId,
        ?string $shopRef,
    ): array {
        $this->from = $from;
        $this->to = $to;

        return [[
            'company_id' => Uuid::uuid7()->toString(),
            'shop_ref' => Uuid::uuid7()->toString(),
            'window_from' => '2026-06-01',
            'window_to' => '2026-06-25',
            'raw_count' => 1,
        ]];
    }

    public function refreshTargets(
        array $targets,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        int $limitPerShop,
        bool $dryRun,
    ): array {
        $this->from = $from;
        $this->to = $to;
        $this->limitPerShop = $limitPerShop;
        $this->dryRun = $dryRun;

        return [
            'totals' => $this->totals,
            'failedRawRecords' => [],
            'failedTargets' => [],
        ];
    }
}

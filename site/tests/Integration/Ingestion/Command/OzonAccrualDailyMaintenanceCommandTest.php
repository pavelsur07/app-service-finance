<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Command\OzonAccrualCategoryMetadataBulkRunnerInterface;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Shared\Domain\ValueObject\Money;
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

    public function testExecuteSucceedsWhenOnlyUnmappedCategoriesRemain(): void
    {
        $runner = new FakeOzonAccrualCategoryMetadataBulkRunner();
        self::getContainer()->set(OzonAccrualCategoryMetadataBulkRunnerInterface::class, $runner);
        $this->em->persist(new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
            normalizedKey: 'code:reviewonlyunmapped',
            externalCode: 'ReviewOnlyUnmapped',
            externalName: 'ReviewOnlyUnmapped',
            status: ExternalCategoryStatus::NEW,
            seenAt: new \DateTimeImmutable('2026-06-25 12:00:00+00:00'),
        ));
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([
            '--from' => '2026-06-01',
            '--to' => '2026-06-25',
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('categories are awaiting mapping', $tester->getDisplay());
    }

    public function testScopedExecuteIgnoresGlobalHealthFailures(): void
    {
        $runner = new FakeOzonAccrualCategoryMetadataBulkRunner();
        self::getContainer()->set(OzonAccrualCategoryMetadataBulkRunnerInterface::class, $runner);
        $this->persistUnclassifiedTransaction();

        $tester = $this->tester();
        $exit = $tester->execute([
            '--from' => '2026-06-01',
            '--to' => '2026-06-25',
            '--company-id' => Uuid::uuid7()->toString(),
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('taxonomy health is informational', $tester->getDisplay());
    }

    /**
     * Изменение поведения, а не исправление: раньше любая неклассифицированная строка
     * внутри окна давала error. Строка с type_id ставится discovery в очередь на разбор
     * в этом же прогоне, поэтому теперь это warning — будить дежурного не за чем.
     */
    public function testExecuteWarnsWhenUnclassifiedTransactionIsDiscoverableByTypeId(): void
    {
        $runner = new FakeOzonAccrualCategoryMetadataBulkRunner();
        self::getContainer()->set(OzonAccrualCategoryMetadataBulkRunnerInterface::class, $runner);
        $this->persistUnclassifiedTransaction('2026-06-10 00:00:00+03:00');

        $tester = $this->tester();
        $exit = $tester->execute([
            '--from' => '2026-06-01',
            '--to' => '2026-06-25',
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('categories are awaiting mapping', $tester->getDisplay());
    }

    /**
     * Прод-сценарий issue 237: код начисления зарегистрирован в очереди на ручной
     * разбор (status new), человек его ещё не сопоставил. Это ожидаемое состояние
     * с существующей операцией починки, поэтому warning и exit 0, а не ночной error.
     */
    public function testExecuteWarnsInsteadOfFailingWhenUnclassifiedCodeIsQueuedForMapping(): void
    {
        $runner = new FakeOzonAccrualCategoryMetadataBulkRunner();
        self::getContainer()->set(OzonAccrualCategoryMetadataBulkRunnerInterface::class, $runner);
        $this->em->persist(new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
            normalizedKey: 'code:queuedforreview',
            externalCode: 'QueuedForReview',
            externalName: 'QueuedForReview',
            status: ExternalCategoryStatus::NEW,
            seenAt: new \DateTimeImmutable('2026-06-10 12:00:00+00:00'),
        ));
        $this->em->flush();
        $this->persistUnclassifiedTransaction('2026-06-10 00:00:00+03:00', 'QueuedForReview');

        $tester = $this->tester();
        $exit = $tester->execute([
            '--from' => '2026-06-01',
            '--to' => '2026-06-25',
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('categories are awaiting mapping', $tester->getDisplay());
        self::assertStringNotContainsString('finished with failures', $tester->getDisplay());
    }

    /**
     * Обратная сторона и guard: discovery отбирает строки только с type_id, поэтому строку
     * без него он не поставит в очередь никогда. Сопоставить её нечем — это поломка самого
     * пайплайна таксономии, и она обязана оставаться error.
     */
    public function testExecuteStillFailsWhenUnclassifiedRowCannotBeQueuedForReview(): void
    {
        $runner = new FakeOzonAccrualCategoryMetadataBulkRunner();
        self::getContainer()->set(OzonAccrualCategoryMetadataBulkRunnerInterface::class, $runner);
        $this->persistUnclassifiedTransaction('2026-06-10 00:00:00+03:00', 'NeverDiscovered', typeId: null);

        $tester = $this->tester();
        $exit = $tester->execute([
            '--from' => '2026-06-01',
            '--to' => '2026-06-25',
            '--execute' => true,
        ]);

        self::assertSame(Command::FAILURE, $exit, $tester->getDisplay());
        self::assertStringContainsString('finished with failures', $tester->getDisplay());
    }

    public function testExecuteIgnoresUnclassifiedTransactionOutsideWindow(): void
    {
        $runner = new FakeOzonAccrualCategoryMetadataBulkRunner();
        self::getContainer()->set(OzonAccrualCategoryMetadataBulkRunnerInterface::class, $runner);
        $this->persistUnclassifiedTransaction('2026-05-20 00:00:00+03:00');

        $tester = $this->tester();
        $exit = $tester->execute([
            '--from' => '2026-06-01',
            '--to' => '2026-06-25',
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('Ozon accrual daily maintenance finished.', $tester->getDisplay());
    }

    public function testRejectsOutOfRangeIntegerOptions(): void
    {
        $runner = new FakeOzonAccrualCategoryMetadataBulkRunner();
        self::getContainer()->set(OzonAccrualCategoryMetadataBulkRunnerInterface::class, $runner);

        $tester = $this->tester();
        $exit = $tester->execute([
            '--from' => '2026-06-01',
            '--to' => '2026-06-25',
            '--limit-per-shop' => '99999',
            '--execute' => true,
        ]);

        self::assertSame(Command::FAILURE, $exit, $tester->getDisplay());
        self::assertStringContainsString('The --limit-per-shop option must be an integer from 1 to 500.', $tester->getDisplay());
        self::assertSame(0, $runner->limitPerShop);
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:ingestion:ozon-accrual:daily-maintenance'));
    }

    private function persistUnclassifiedTransaction(string $occurredAt = '2026-06-25 00:00:00+03:00', ?string $externalCode = null, ?string $typeId = '999'): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $rawRecordId = Uuid::uuid7()->toString();

        $this->em->persist(new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            externalId: sprintf('ozon:accrual-by-day:test-unclassified:%s:%s', $externalCode ?? 'no-code', $typeId ?? 'no-type'),
            externalUpdatedAt: new \DateTimeImmutable('2026-06-25 00:00:00+00:00'),
            operationGroupId: Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:unclassified', $companyId))->toString(),
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor(100, 'RUB'),
            occurredAt: new \DateTimeImmutable($occurredAt),
            rawRecordId: $rawRecordId,
            description: 'Ozon accrual unclassified test',
            sourceData: array_filter([
                '_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY,
                '_ingestion_type_id' => $typeId,
                '_ingestion_external_code' => $externalCode,
                '_ozon_category_label' => 'Неизвестный type_id Ozon: 999',
                '_ozon_category_group' => 'Требует классификации',
                '_ozon_category_known' => false,
            ], static fn (mixed $value): bool => null !== $value),
            sourceTz: 'Europe/Moscow',
        ));
        $this->em->flush();
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

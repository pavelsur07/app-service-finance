<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Command;

use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Inventory\Enum\StockStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Разовая пере-нормализация: выбор целей и безопасность повторного прогона.
 */
final class RenormalizeInventorySnapshotsCommandTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000901';

    private const UNMAPPED_SESSION = '22222222-2222-2222-2222-000000000901';
    private const MAPPED_SESSION = '22222222-2222-2222-2222-000000000902';
    private const OLD_SESSION_SAME_DAY = '22222222-2222-2222-2222-000000000903';
    private const NEW_SESSION_SAME_DAY = '22222222-2222-2222-2222-000000000904';
    private const TIE_LOW_SESSION = '22222222-2222-2222-2222-00000000090a';
    private const TIE_HIGH_SESSION = '22222222-2222-2222-2222-00000000090b';

    private const LISTING_ID = '55555555-5555-5555-5555-000000000901';

    protected function setUp(): void
    {
        parent::setUp();

        // 27.08 — сессия с непривязанной строкой: цель пере-нормализации.
        $this->persist(self::UNMAPPED_SESSION, '2026-08-27', '2026-08-27T04:05:00+00:00', StockSnapshotMappingStatus::Unmapped, null, 'SKU-A');
        // 28.08 — полностью смапленная сессия: целью быть не должна.
        $this->persist(self::MAPPED_SESSION, '2026-08-28', '2026-08-28T04:05:00+00:00', StockSnapshotMappingStatus::Mapped, self::LISTING_ID, 'SKU-B');
        // 29.08 — два прогона за один день, у обоих есть непривязанные строки.
        $this->persist(self::OLD_SESSION_SAME_DAY, '2026-08-29', '2026-08-29T04:05:00+00:00', StockSnapshotMappingStatus::Unmapped, null, 'SKU-C');
        $this->persist(self::NEW_SESSION_SAME_DAY, '2026-08-29', '2026-08-29T21:30:00+00:00', StockSnapshotMappingStatus::Unmapped, null, 'SKU-D');
        // 30.08 — две сессии с ОДИНАКОВЫМ snapshot_at: решает tie-break по session_id DESC.
        $this->persist(self::TIE_LOW_SESSION, '2026-08-30', '2026-08-30T04:05:00+00:00', StockSnapshotMappingStatus::Unmapped, null, 'SKU-E');
        $this->persist(self::TIE_HIGH_SESSION, '2026-08-30', '2026-08-30T04:05:00+00:00', StockSnapshotMappingStatus::Unmapped, null, 'SKU-F');

        $this->em->flush();
    }

    public function testDryRunCountsTargetsAndDispatchesNothing(): void
    {
        $tester = $this->runCommand(['--from' => '2026-08-25', '--to' => '2026-08-31']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('dry run: nothing changed', $tester->getDisplay());
        self::assertStringContainsString('sessions count: 3', $tester->getDisplay());
        self::assertStringNotContainsString('processed count', $tester->getDisplay());
    }

    public function testDryRunLeavesRowsUntouched(): void
    {
        // «Ничего не изменилось» должно быть проверяемым фактом, а не надписью в выводе.
        $before = $this->mappingState();

        $this->runCommand(['--from' => '2026-08-25', '--to' => '2026-08-31']);

        self::assertSame($before, $this->mappingState());
    }

    public function testFullyMappedSessionIsNotATarget(): void
    {
        $tester = $this->runCommand(['--from' => '2026-08-28', '--to' => '2026-08-28']);

        self::assertStringContainsString('sessions count: 0', $tester->getDisplay());
        self::assertStringNotContainsString(self::MAPPED_SESSION, $tester->getDisplay());
    }

    public function testOnlyLatestSessionOfADayIsTargeted(): void
    {
        // Пере-нормализация старой сессии дня затёрла бы строки, записанные новой:
        // ключ upsert включает snapshot_date, но не сессию.
        $tester = $this->runCommand(['--from' => '2026-08-29', '--to' => '2026-08-29']);

        self::assertStringContainsString(self::NEW_SESSION_SAME_DAY, $tester->getDisplay());
        self::assertStringNotContainsString(self::OLD_SESSION_SAME_DAY, $tester->getDisplay());
        self::assertStringContainsString('sessions count: 1', $tester->getDisplay());
    }

    public function testExecuteProcessesEachTargetSession(): void
    {
        $tester = $this->runCommand(['--from' => '2026-08-25', '--to' => '2026-08-31', '--execute' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('processed count: 3', $tester->getDisplay());
        self::assertStringContainsString('errors count: 0', $tester->getDisplay());
    }

    public function testEqualSnapshotAtIsResolvedByGreaterSessionId(): void
    {
        // Вторая половина правила выбора: при одинаковом snapshot_at решает
        // snapshot_session_id DESC. Без неё выбор был бы недетерминированным.
        $tester = $this->runCommand(['--from' => '2026-08-30', '--to' => '2026-08-30']);

        self::assertStringContainsString(self::TIE_HIGH_SESSION, $tester->getDisplay());
        self::assertStringNotContainsString(self::TIE_LOW_SESSION, $tester->getDisplay());
        self::assertStringContainsString('sessions count: 1', $tester->getDisplay());
    }

    public function testRangeIncludingTodayIsRejected(): void
    {
        // Новая сессия всегда пишется текущей датой, поэтому сегодняшний день ещё
        // дописывается — брать его в пере-нормализацию значит гоняться с загрузкой.
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $tester = $this->runCommand(['--from' => $today, '--to' => $today]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('must be earlier than today', $tester->getDisplay());
    }

    public function testRangeOutsideDataDispatchesNothing(): void
    {
        $tester = $this->runCommand(['--from' => '2026-07-01', '--to' => '2026-07-31', '--execute' => true]);

        self::assertStringContainsString('sessions count: 0', $tester->getDisplay());
        self::assertStringContainsString('processed count: 0', $tester->getDisplay());
    }

    public function testInvalidDateIsRejectedWithoutDispatch(): void
    {
        $tester = $this->runCommand(['--from' => '31-08-2026', '--to' => '2026-08-31', '--execute' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('invalid input', $tester->getDisplay());
        self::assertStringNotContainsString('processed count', $tester->getDisplay());
    }

    public function testReversedRangeIsRejected(): void
    {
        $tester = $this->runCommand(['--from' => '2026-08-31', '--to' => '2026-08-01']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--from must not be later than --to', $tester->getDisplay());
    }

    public function testUnknownSourceIsRejected(): void
    {
        $tester = $this->runCommand(['--from' => '2026-08-25', '--to' => '2026-08-31', '--source' => 'avito']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--source must be one of', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options): CommandTester
    {
        $app = new Application(self::bootKernel());
        $tester = new CommandTester($app->find('app:inventory:renormalize-snapshots'));
        $tester->execute($options);

        return $tester;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mappingState(): array
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection->fetchAllAssociative(
            'SELECT source_sku, snapshot_session_id, mapping_status, listing_id, quantity
             FROM inventory_stock_snapshots
             WHERE company_id = :companyId
             ORDER BY source_sku',
            ['companyId' => self::COMPANY_ID],
        );
    }

    private function persist(
        string $sessionId,
        string $snapshotDate,
        string $snapshotAt,
        StockSnapshotMappingStatus $mappingStatus,
        ?string $listingId,
        string $sourceSku,
    ): void {
        $this->em->persist(
            StockSnapshotBuilder::aStockSnapshot()
                ->withCompanyId(self::COMPANY_ID)
                ->withSnapshotSessionId($sessionId)
                ->withSnapshotDate(new \DateTimeImmutable($snapshotDate))
                ->withSnapshotAt(new \DateTimeImmutable($snapshotAt))
                ->withSource(MarketplaceType::OZON)
                ->withListingId($listingId)
                ->withProductId(null)
                ->withMappingStatus($mappingStatus)
                ->withStatus(StockStatus::Available)
                ->withSourceSku($sourceSku)
                ->withQuantity('4.000')
                ->build(),
        );
    }
}

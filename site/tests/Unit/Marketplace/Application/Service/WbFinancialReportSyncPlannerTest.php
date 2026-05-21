<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlanner;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\ActiveWbConnectionsQuery;
use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class WbFinancialReportSyncPlannerTest extends IntegrationTestCase
{
    private const REPORT_TYPE = 'sales_report';

    private ActiveWbConnectionsQuery $connectionsQuery;
    private MarketplaceFinancialReportSyncStatusRepository $statusRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionsQuery = self::getContainer()->get(ActiveWbConnectionsQuery::class);
        $this->statusRepository = self::getContainer()->get(MarketplaceFinancialReportSyncStatusRepository::class);
    }

    public function testActiveWbConnectionsQueryReturnsLegacyAndNewConnectionKeys(): void
    {
        [$companyId, $connectionId] = $this->seedActiveConnection();

        $rows = $this->connectionsQuery->execute($companyId, $connectionId);

        self::assertCount(1, $rows);
        self::assertSame($connectionId, $rows[0]['id']);
        self::assertSame($connectionId, $rows[0]['connection_id']);
        self::assertSame($companyId, $rows[0]['company_id']);
    }

    public function testPlanDailyWithoutForceSkipsSuccessAndDispatchesMissing(): void
    {
        [$companyId, $connectionId] = $this->seedActiveConnection();
        $day = new \DateTimeImmutable('2026-05-20 00:00:00 Europe/Moscow');
        $this->persistStatus($companyId, $connectionId, $day, FinancialReportSyncStatus::SUCCESS);

        $bus = new InMemoryMessageBus();
        $planner = $this->planner($bus, new \DateTimeImmutable('2026-05-21 00:00:00 Europe/Moscow'));

        self::assertSame(0, $planner->planDaily($companyId, $connectionId, false));

        $otherConnectionId = '44444444-4444-4444-4444-444444444444';
        $this->seedConnectionForExistingCompany($companyId, $otherConnectionId);

        self::assertSame(1, $planner->planDaily($companyId, $otherConnectionId, false));
    }

    public function testPlanDailyWithForceDispatchesSuccessEmptyButNotInFlight(): void
    {
        [$companyIdA, $connectionIdA] = $this->seedActiveConnection('11111111-1111-1111-1111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        [$companyIdB, $connectionIdB] = $this->seedActiveConnection('22222222-2222-2222-2222-222222222222', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        [$companyIdC, $connectionIdC] = $this->seedActiveConnection('33333333-3333-3333-3333-333333333333', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc');

        $day = new \DateTimeImmutable('2026-05-20 00:00:00 Europe/Moscow');
        $this->persistStatus($companyIdA, $connectionIdA, $day, FinancialReportSyncStatus::SUCCESS);
        $this->persistStatus($companyIdB, $connectionIdB, $day, FinancialReportSyncStatus::EMPTY);
        $this->persistStatus($companyIdC, $connectionIdC, $day, FinancialReportSyncStatus::LOADING);

        $bus = new InMemoryMessageBus();
        $planner = $this->planner($bus, new \DateTimeImmutable('2026-05-21 00:00:00 Europe/Moscow'));

        $count = $planner->planDaily(force: true);

        self::assertSame(2, $count);
        self::assertCount(2, $bus->messages);
        self::assertTrue($bus->messages[0]->forceRefresh);
        self::assertTrue($bus->messages[1]->forceRefresh);
    }

    public function testPlanRefresh14DaysSkipsInFlightAndUsesForceRefresh(): void
    {
        [$companyId, $connectionId] = $this->seedActiveConnection();
        $dayInFlight = new \DateTimeImmutable('2026-05-19');
        $this->persistStatus($companyId, $connectionId, $dayInFlight, FinancialReportSyncStatus::LOADING);

        $bus = new InMemoryMessageBus();
        $planner = $this->planner($bus, new \DateTimeImmutable('2026-05-21 12:00:00 Europe/Moscow'));

        $count = $planner->planRefresh14Days($companyId, $connectionId);
        self::assertGreaterThan(0, $count);

        foreach ($bus->messages as $message) {
            self::assertTrue($message->forceRefresh);
            self::assertNotSame('2026-05-19', $message->businessDate);
        }
    }

    public function testPlanMissingDispatchesRetryDueAndMissingWithMaxDaysLimit(): void
    {
        [$companyId, $connectionId] = $this->seedActiveConnection();

        $bus = new InMemoryMessageBus();
        $planner = $this->planner($bus, new \DateTimeImmutable('2026-01-05 12:00:00 Europe/Moscow'));

        $this->persistStatus($companyId, $connectionId, new \DateTimeImmutable('2026-01-01'), FinancialReportSyncStatus::SUCCESS);
        $this->persistStatus($companyId, $connectionId, new \DateTimeImmutable('2026-01-02'), FinancialReportSyncStatus::FAILED, null);
        $this->persistStatus($companyId, $connectionId, new \DateTimeImmutable('2026-01-03'), FinancialReportSyncStatus::FAILED, new \DateTimeImmutable('+1 day'));
        // 2026-01-04 status missing

        $count = $planner->planMissing($companyId, $connectionId, 2);

        self::assertSame(2, $count);
        self::assertCount(2, $bus->messages);
        self::assertSame(['2026-01-02', '2026-01-04'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->businessDate, $bus->messages));
        self::assertSame(['missing', 'missing'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->mode, $bus->messages));
        self::assertSame([false, false], array_map(static fn (SyncWbFinancialReportDayMessage $m): bool => $m->forceRefresh, $bus->messages));
    }

    private function planner(InMemoryMessageBus $bus, \DateTimeImmutable $clockNow): WbFinancialReportSyncPlanner
    {
        $resolver = new WbFinancialReportPeriodResolver(new MockClock($clockNow));

        return new WbFinancialReportSyncPlanner(
            $this->connectionsQuery,
            $resolver,
            $this->statusRepository,
            $bus,
        );
    }

    private function persistStatus(
        string $companyId,
        string $connectionId,
        \DateTimeImmutable $day,
        FinancialReportSyncStatus $status,
        ?\DateTimeImmutable $nextRetryAt = null,
    ): void {
        $entity = new MarketplaceFinancialReportSyncStatus(
            Uuid::uuid7()->toString(),
            $companyId,
            $connectionId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'endpoint',
            $day,
        );

        match ($status) {
            FinancialReportSyncStatus::SUCCESS => $entity->markSuccess(),
            FinancialReportSyncStatus::EMPTY => $entity->markEmpty(),
            FinancialReportSyncStatus::LOADING => $entity->markLoading(FinancialReportSyncMode::DAILY),
            FinancialReportSyncStatus::PROCESSING => $entity->markProcessing(),
            FinancialReportSyncStatus::FAILED => $entity->markFailedRetryable('TestException', 'failed', 500, null, $nextRetryAt),
            default => null,
        };

        $this->statusRepository->save($entity);
        $this->em->flush();
    }

    /** @return array{0:string,1:string} */
    private function seedActiveConnection(?string $companyId = null, ?string $connectionId = null): array
    {
        $cid = $companyId ?? '11111111-1111-1111-1111-111111111111';
        $connection = $connectionId ?? '22222222-2222-4222-8222-222222222222';

        $existing = $this->em->find(Company::class, $cid);
        if (!$existing instanceof Company) {
            $company = CompanyBuilder::aCompany()->withId($cid)->build();
            $this->em->persist($company->getOwner());
            $this->em->persist($company);
            $this->em->flush();
        }

        $this->seedConnectionForExistingCompany($cid, $connection);

        return [$cid, $connection];
    }

    private function seedConnectionForExistingCompany(string $companyId, string $connectionId): void
    {
        $this->em->getConnection()->insert('marketplace_connections', [
            'id' => $connectionId,
            'company_id' => $companyId,
            'marketplace' => 'wildberries',
            'connection_type' => 'seller',
            'api_key' => 'encrypted-key',
            'is_active' => true,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}

final class InMemoryMessageBus implements MessageBusInterface
{
    /** @var list<SyncWbFinancialReportDayMessage> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($message instanceof SyncWbFinancialReportDayMessage) {
            $this->messages[] = $message;
        }

        return new Envelope($message, $stamps);
    }
}

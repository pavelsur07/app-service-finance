<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Company\Entity\Company;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\Repository\IngestCursorRepository;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RunIncrementalCommandTest extends IntegrationTestCase
{
    public function testSeedsAccrualCursorFromLegacyCursorAndDispatches(): void
    {
        $company = $this->seedCompany(1001);
        $connection = $this->seedConnection($company, '77777777-7777-7777-7777-000000001001');
        $this->seedCursor($company->getId(), $connection->getId(), 'ozon_seller_daily_report', 'legacy-shop', '2026-06-18');

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:run-incremental');
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Dispatched 1 incremental jobs', $tester->getDisplay());
        self::assertSame(1, $this->incrementalJobCount($company->getId()));
        self::assertCount(1, $transport->getSent());

        /** @var IngestCursorRepository $cursorRepository */
        $cursorRepository = self::getContainer()->get(IngestCursorRepository::class);
        $cursor = $cursorRepository->findOne($company->getId(), $connection->getId(), OzonResourceType::ACCRUAL_BY_DAY, $connection->getId());

        self::assertNotNull($cursor);
        self::assertSame('2026-06-18', $cursor->getCursorValue());
    }

    public function testSeedsAccrualCursorFromFirstDayOfCurrentMonthWithoutLegacyCursor(): void
    {
        $company = $this->seedCompany(1011);
        $connection = $this->seedConnection($company, '77777777-7777-7777-7777-000000001011');

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:run-incremental');
        $exit = $tester->execute(['--company-id' => $company->getId()]);

        $expectedSeed = (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $isSeedDue = new \DateTimeImmutable($expectedSeed) <= (new \DateTimeImmutable('today'))->modify('-1 day')->setTime(0, 0);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString($isSeedDue ? 'Dispatched 1 incremental jobs' : 'not due: 1', $tester->getDisplay());
        self::assertSame($isSeedDue ? 1 : 0, $this->incrementalJobCount($company->getId()));
        self::assertCount($isSeedDue ? 1 : 0, $transport->getSent());

        /** @var IngestCursorRepository $cursorRepository */
        $cursorRepository = self::getContainer()->get(IngestCursorRepository::class);
        $cursor = $cursorRepository->findOne($company->getId(), $connection->getId(), OzonResourceType::ACCRUAL_BY_DAY, $connection->getId());

        self::assertNotNull($cursor);
        self::assertSame($expectedSeed, $cursor->getCursorValue());
    }

    public function testDispatchesOneIncrementalJobPerExistingCursor(): void
    {
        $company = $this->seedCompany(1002);
        $connection = $this->seedConnection($company, '77777777-7777-7777-7777-000000001002');
        $this->seedCursor($company->getId(), $connection->getId(), OzonResourceType::ACCRUAL_BY_DAY, 'shop-a', '2026-06-18');

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:run-incremental');
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Dispatched 1 incremental jobs', $tester->getDisplay());
        self::assertSame(1, $this->incrementalJobCount($company->getId()));

        $envelopes = $transport->getSent();
        self::assertCount(1, $envelopes);
        foreach ($envelopes as $envelope) {
            self::assertInstanceOf(RunSyncChunkMessage::class, $envelope->getMessage());
        }
    }

    public function testCompanyFilterDispatchesOnlyRequestedCompany(): void
    {
        $companyA = $this->seedCompany(1003);
        $connectionA = $this->seedConnection($companyA, '77777777-7777-7777-7777-000000001003');
        $this->seedCursor($companyA->getId(), $connectionA->getId(), OzonResourceType::ACCRUAL_BY_DAY, 'shop-a', '2026-06-18');

        $companyB = $this->seedCompany(1004);
        $connectionB = $this->seedConnection($companyB, '77777777-7777-7777-7777-000000001004');
        $this->seedCursor($companyB->getId(), $connectionB->getId(), OzonResourceType::ACCRUAL_BY_DAY, 'shop-b', '2026-06-18');

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:run-incremental');
        $exit = $tester->execute(['--company-id' => $companyB->getId()]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(0, $this->incrementalJobCount($companyA->getId()));
        self::assertSame(1, $this->incrementalJobCount($companyB->getId()));
        self::assertCount(1, $transport->getSent());
    }

    public function testLimitSelectsOldestEligibleCursorWorkInsteadOfFirstActiveCompanies(): void
    {
        $newerCompany = $this->seedCompany(1005);
        $newerConnection = $this->seedConnection($newerCompany, '77777777-7777-7777-7777-000000001005');
        $this->seedCursor(
            $newerCompany->getId(),
            $newerConnection->getId(),
            OzonResourceType::ACCRUAL_BY_DAY,
            'shop-newer',
            '2026-06-18',
            new \DateTimeImmutable('2026-06-18 10:00:00'),
        );

        $olderCompany = $this->seedCompany(1006);
        $olderConnection = $this->seedConnection($olderCompany, '77777777-7777-7777-7777-000000001006');
        $this->seedCursor(
            $olderCompany->getId(),
            $olderConnection->getId(),
            OzonResourceType::ACCRUAL_BY_DAY,
            'shop-older',
            '2026-06-18',
            new \DateTimeImmutable('2026-06-01 10:00:00'),
        );

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:run-incremental');
        $exit = $tester->execute(['--limit' => '1']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(0, $this->incrementalJobCount($newerCompany->getId()));
        self::assertSame(1, $this->incrementalJobCount($olderCompany->getId()));
        self::assertCount(1, $transport->getSent());
    }

    public function testSkipsAccrualCursorThatIsNotDueYet(): void
    {
        $company = $this->seedCompany(1007);
        $connection = $this->seedConnection($company, '77777777-7777-7777-7777-000000001007');
        $this->seedCursor(
            $company->getId(),
            $connection->getId(),
            OzonResourceType::ACCRUAL_BY_DAY,
            'shop-future',
            (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
        );

        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        $tester = $this->tester('app:ingestion:run-incremental');
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('not due: 1', $tester->getDisplay());
        self::assertSame(0, $this->incrementalJobCount($company->getId()));
        self::assertCount(0, $transport->getSent());
    }

    public function testWildberriesSourceIsSkipped(): void
    {
        $tester = $this->tester('app:ingestion:run-incremental');
        $exit = $tester->execute(['--source' => 'wildberries']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('not supported yet', $tester->getDisplay());
    }

    private function seedCompany(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function seedConnection(Company $company, string $id): MarketplaceConnection
    {
        $connection = new MarketplaceConnection(
            id: $id,
            company: $company,
            marketplace: MarketplaceType::OZON,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key');
        $connection->setClientId('test-client-id');
        $connection->setIsActive(true);

        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    private function seedCursor(
        string $companyId,
        string $connectionRef,
        string $resourceType,
        string $shopRef,
        string $cursorValue,
        ?\DateTimeImmutable $lastFetchedAt = null,
    ): void {
        /** @var IngestCursorRepository $cursorRepository */
        $cursorRepository = self::getContainer()->get(IngestCursorRepository::class);
        $cursor = $cursorRepository->getOrCreate($companyId, $connectionRef, $resourceType, $shopRef);
        $cursor->advance($cursorValue, Uuid::uuid7()->toString(), $lastFetchedAt ?? new \DateTimeImmutable('2026-06-18 10:00:00'));
        $this->em->flush();
    }

    private function incrementalJobCount(string $companyId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM ingest_sync_jobs
             WHERE company_id = :companyId AND kind = :kind',
            [
                'companyId' => $companyId,
                'kind' => SyncJobKind::INCREMENTAL->value,
            ],
        );
    }

    private function tester(string $commandName): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find($commandName));
    }

    private function getIngestFetchTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.ingest_fetch');

        return $transport;
    }
}

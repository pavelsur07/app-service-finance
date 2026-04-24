<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Command;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end тесты {@see \App\MarketplaceAds\Command\AdDailySyncCommand}:
 * реальный Postgres + mocked {@see OzonAdClient} (используется внутри
 * {@see \App\MarketplaceAds\Application\Service\AdBatchPlanner}).
 *
 * Покрывают acceptance criteria Task-13b:
 *  - две компании (с connection + без) → только у первой создан RUNNING job;
 *  - идемпотентность: повторный запуск в тот же день → всё skipped;
 *  - компания без Ozon Performance не попадает в список.
 */
final class AdDailySyncCommandTest extends IntegrationTestCase
{
    private const COMPANY_WITH_CONN = '11111111-1111-1111-1111-000000000001';
    private const COMPANY_WITHOUT_CONN = '11111111-1111-1111-1111-000000000002';
    private const OWNER_WITH = '22222222-2222-2222-2222-000000000001';
    private const OWNER_WITHOUT = '22222222-2222-2222-2222-000000000002';

    private AdLoadJobRepository $jobRepo;
    private AdScheduledBatchRepository $batchRepo;

    /** @var OzonAdClient&MockObject */
    private OzonAdClient $clientMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobRepo = self::getContainer()->get(AdLoadJobRepository::class);
        $this->batchRepo = self::getContainer()->get(AdScheduledBatchRepository::class);

        // AdBatchPlanner зовёт OzonAdClient::listAllSkuCampaigns — перехватываем,
        // чтобы не делать реальный HTTP из интеграционного теста.
        $this->clientMock = $this->createMock(OzonAdClient::class);
        self::getContainer()->set(OzonAdClient::class, $this->clientMock);
    }

    public function testOnlyCompanyWithOzonPerformanceConnectionGetsJob(): void
    {
        $withConn = $this->seedCompany(self::COMPANY_WITH_CONN, self::OWNER_WITH, 'a@example.test');
        $this->seedCompany(self::COMPANY_WITHOUT_CONN, self::OWNER_WITHOUT, 'b@example.test');
        $this->em->persist($this->buildConnection($withConn, MarketplaceConnectionType::PERFORMANCE, true));
        $this->em->flush();

        $this->clientMock
            ->expects(self::once())
            ->method('listAllSkuCampaigns')
            ->with(self::COMPANY_WITH_CONN)
            ->willReturn([['id' => 'camp-1'], ['id' => 'camp-2']]);

        $tester = $this->makeTester();
        $exit = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exit);

        $display = $tester->getDisplay();
        self::assertStringContainsString('created=1 skipped=0 failed=0', $display);

        $jobs = $this->jobRepo->findRecentByCompanyAndMarketplace(
            self::COMPANY_WITH_CONN,
            MarketplaceType::OZON,
        );
        self::assertCount(1, $jobs);
        $job = $jobs[0];
        self::assertSame(AdLoadJobStatus::RUNNING, $job->getStatus(), 'Job должен перейти в RUNNING для Finalizer');

        $yesterday = (new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC')))->setTime(0, 0);
        self::assertSame($yesterday->format('Y-m-d'), $job->getDateFrom()->format('Y-m-d'));
        self::assertSame($yesterday->format('Y-m-d'), $job->getDateTo()->format('Y-m-d'));

        $batches = $this->batchRepo->findByJobId($job->getId(), self::COMPANY_WITH_CONN);
        self::assertNotEmpty($batches, 'Planner должен создать хотя бы один PLANNED batch');
        self::assertSame(AdScheduledBatchState::PLANNED, $batches[0]->getState());

        // У компании без connection job не появился.
        $noConnJobs = $this->jobRepo->findRecentByCompanyAndMarketplace(
            self::COMPANY_WITHOUT_CONN,
            MarketplaceType::OZON,
        );
        self::assertSame([], $noConnJobs);
    }

    public function testRerunInSameDayIsIdempotentAndSkipsExistingJob(): void
    {
        $company = $this->seedCompany(self::COMPANY_WITH_CONN, self::OWNER_WITH, 'a@example.test');
        $this->em->persist($this->buildConnection($company, MarketplaceConnectionType::PERFORMANCE, true));
        $this->em->flush();

        $this->clientMock
            ->method('listAllSkuCampaigns')
            ->willReturn([['id' => 'camp-1']]);

        // Первый запуск — создаёт job.
        $exit1 = $this->makeTester()->execute([]);
        self::assertSame(Command::SUCCESS, $exit1);

        $jobsAfterFirst = $this->jobRepo->findRecentByCompanyAndMarketplace(
            self::COMPANY_WITH_CONN,
            MarketplaceType::OZON,
        );
        self::assertCount(1, $jobsAfterFirst);
        $firstJobId = $jobsAfterFirst[0]->getId();

        $this->em->clear();

        // Второй запуск в тот же день — должен skipped'ить всё.
        $tester2 = $this->makeTester();
        $exit2 = $tester2->execute([]);
        self::assertSame(Command::SUCCESS, $exit2);
        self::assertStringContainsString('created=0 skipped=1 failed=0', $tester2->getDisplay());

        $jobsAfterSecond = $this->jobRepo->findRecentByCompanyAndMarketplace(
            self::COMPANY_WITH_CONN,
            MarketplaceType::OZON,
        );
        self::assertCount(1, $jobsAfterSecond, 'Второй запуск не должен создать ещё один job');
        self::assertSame($firstJobId, $jobsAfterSecond[0]->getId());
    }

    public function testSellerOnlyOzonConnectionIsNotPickedUp(): void
    {
        $company = $this->seedCompany(self::COMPANY_WITH_CONN, self::OWNER_WITH, 'a@example.test');
        // SELLER — общий Ozon, а не Performance: не должен попасть в список.
        $this->em->persist($this->buildConnection($company, MarketplaceConnectionType::SELLER, true));
        $this->em->flush();

        $this->clientMock->expects(self::never())->method('listAllSkuCampaigns');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString(
            'No companies with active Ozon Performance connection',
            $tester->getDisplay(),
        );
    }

    public function testPlannerFailureMarksJobFailedAndDoesNotBlockOtherCompanies(): void
    {
        $failing = $this->seedCompany(self::COMPANY_WITH_CONN, self::OWNER_WITH, 'a@example.test');
        $ok = $this->seedCompany(self::COMPANY_WITHOUT_CONN, self::OWNER_WITHOUT, 'b@example.test');
        $this->em->persist($this->buildConnection($failing, MarketplaceConnectionType::PERFORMANCE, true));
        $this->em->persist($this->buildConnection($ok, MarketplaceConnectionType::PERFORMANCE, true));
        $this->em->flush();

        // Для failing-компании Ozon возвращает пустой список → Planner бросает.
        $this->clientMock->method('listAllSkuCampaigns')
            ->willReturnCallback(function (string $companyId): array {
                if (self::COMPANY_WITH_CONN === $companyId) {
                    return [];
                }

                return [['id' => 'camp-1']];
            });

        $tester = $this->makeTester();
        $exit = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('created=1 skipped=0 failed=1', $tester->getDisplay());

        // У failing-компании job остался, но в FAILED — existsByDateRange()
        // в следующий запуск заметит его и не создаст дубликат.
        $failingJobs = $this->jobRepo->findRecentByCompanyAndMarketplace(
            self::COMPANY_WITH_CONN,
            MarketplaceType::OZON,
        );
        self::assertCount(1, $failingJobs);
        self::assertSame(AdLoadJobStatus::FAILED, $failingJobs[0]->getStatus());
        self::assertStringContainsString('Planning error', (string) $failingJobs[0]->getFailureReason());

        // OK-компания получила RUNNING job и batch'и.
        $okJobs = $this->jobRepo->findRecentByCompanyAndMarketplace(
            self::COMPANY_WITHOUT_CONN,
            MarketplaceType::OZON,
        );
        self::assertCount(1, $okJobs);
        self::assertSame(AdLoadJobStatus::RUNNING, $okJobs[0]->getStatus());
    }

    public function testExitsSuccessWhenNoCompaniesAtAll(): void
    {
        $this->clientMock->expects(self::never())->method('listAllSkuCampaigns');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString(
            'No companies with active Ozon Performance connection',
            $tester->getDisplay(),
        );
    }

    private function makeTester(): CommandTester
    {
        $app = new Application(self::$kernel);
        $command = $app->find('app:marketplace-ads:daily-sync');

        return new CommandTester($command);
    }

    private function seedCompany(string $companyId, string $ownerId, string $email): Company
    {
        $owner = UserBuilder::aUser()
            ->withId($ownerId)
            ->withEmail($email)
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function buildConnection(
        Company $company,
        MarketplaceConnectionType $type,
        bool $isActive,
    ): MarketplaceConnection {
        $connection = new MarketplaceConnection(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
            $type,
        );
        $connection->setApiKey('api-key');
        $connection->setClientId('client-id');
        $connection->setIsActive($isActive);

        return $connection;
    }
}

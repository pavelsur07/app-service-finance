<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Command;

use App\Company\Entity\Company;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Entity\AdScheduledBatch;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Exception\OzonRateLimitException;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Builders\MarketplaceAds\AdScheduledBatchBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end тесты {@see \App\MarketplaceAds\Command\AdBatchSchedulerCommand}:
 * реальный Postgres + mocked {@see OzonAdClient}.
 *
 * Покрывают все acceptance criteria Task-11.5:
 *  - empty DB → SUCCESS, "No PLANNED batches";
 *  - happy path → POST UUID, IN_FLIGHT, startedAt, ozonUuid;
 *  - OzonRateLimitException (429) → PLANNED + scheduled_at+5min + retry++;
 *  - OzonPermanentApiException → FAILED + finishedAt + lastError;
 *  - transient (\RuntimeException) → rollback, batch unchanged;
 *  - FOR UPDATE SKIP LOCKED → параллельный worker при заблокированном row
 *    видит null и не перехватывает тот же батч.
 */
final class AdBatchSchedulerCommandTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-000000000001';

    private AdScheduledBatchRepository $batchRepo;
    private AdLoadJobRepository $jobRepo;
    /** @var OzonAdClient&MockObject */
    private OzonAdClient $clientMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batchRepo = self::getContainer()->get(AdScheduledBatchRepository::class);
        $this->jobRepo = self::getContainer()->get(AdLoadJobRepository::class);

        $this->clientMock = $this->createMock(OzonAdClient::class);
        self::getContainer()->set(OzonAdClient::class, $this->clientMock);
    }

    public function testEmptyDbExitsSuccessWithMessage(): void
    {
        $this->clientMock->expects(self::never())->method('postStatistics');

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No PLANNED batches', $tester->getDisplay());
    }

    public function testHappyPathDispatchesBatchAndMarksInFlight(): void
    {
        $job = $this->seedJob();
        $batch = $this->persistBatch($job, batchIndex: 0);

        $this->clientMock->expects(self::once())
            ->method('postStatistics')
            ->with(
                self::COMPANY_ID,
                $batch->getCampaignIds(),
                self::callback(fn (\DateTimeImmutable $d): bool => '2026-03-01' === $d->format('Y-m-d')),
                self::callback(fn (\DateTimeImmutable $d): bool => '2026-03-10' === $d->format('Y-m-d')),
            )
            ->willReturn('ozon-uuid-abc-123');

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);

        $this->em->clear();
        $reloaded = $this->batchRepo->find($batch->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdScheduledBatchState::IN_FLIGHT, $reloaded->getState());
        self::assertSame('ozon-uuid-abc-123', $reloaded->getOzonUuid());
        self::assertNotNull($reloaded->getStartedAt());
        self::assertNull($reloaded->getFinishedAt());
        self::assertNull($reloaded->getLastError());
        self::assertSame(0, $reloaded->getRetryCount());
    }

    public function testRateLimitReschedulesAndIncrementsRetry(): void
    {
        $job = $this->seedJob();
        $batch = $this->persistBatch($job, batchIndex: 0);

        $this->clientMock->method('postStatistics')
            ->willThrowException(new OzonRateLimitException('Ozon Performance: HTTP 429, rate-limited'));

        $before = new \DateTimeImmutable();

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit, '429 — не фатально; cron возвращает SUCCESS, batch перепланирован');

        $this->em->clear();
        $reloaded = $this->batchRepo->find($batch->getId());
        self::assertNotNull($reloaded);

        self::assertSame(AdScheduledBatchState::PLANNED, $reloaded->getState(), 'state остаётся PLANNED');
        self::assertNull($reloaded->getOzonUuid());
        self::assertNull($reloaded->getStartedAt());
        self::assertNull($reloaded->getFinishedAt());
        self::assertSame(1, $reloaded->getRetryCount());
        self::assertNotNull($reloaded->getLastError());
        self::assertStringContainsString('429', (string) $reloaded->getLastError());

        // scheduled_at должен сдвинуться примерно на +5 минут от момента запуска.
        $delta = $reloaded->getScheduledAt()->getTimestamp() - $before->getTimestamp();
        self::assertGreaterThanOrEqual(4 * 60, $delta, 'scheduled_at сдвинулся на >= 4 мин');
        self::assertLessThanOrEqual(6 * 60, $delta, 'scheduled_at сдвинулся на <= 6 мин');
    }

    public function testPermanentFailureMarksBatchFailed(): void
    {
        $job = $this->seedJob();
        $batch = $this->persistBatch($job, batchIndex: 0);

        $this->clientMock->method('postStatistics')
            ->willThrowException(new OzonPermanentApiException('Ozon Performance: POST /api/client/statistics вернул 403'));

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit, 'Permanent failure — не rollback, запись FAILED коммитится');

        $this->em->clear();
        $reloaded = $this->batchRepo->find($batch->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdScheduledBatchState::FAILED, $reloaded->getState());
        self::assertNotNull($reloaded->getFinishedAt());
        self::assertNull($reloaded->getOzonUuid());
        self::assertNotNull($reloaded->getLastError());
        self::assertStringContainsString('permanent', (string) $reloaded->getLastError());
    }

    public function testTransientFailureRollsBackAndLeavesBatchUnchanged(): void
    {
        $job = $this->seedJob();
        $batch = $this->persistBatch($job, batchIndex: 0);
        $originalScheduledAt = $batch->getScheduledAt();

        // 5xx / сеть — обычный \RuntimeException.
        $this->clientMock->method('postStatistics')
            ->willThrowException(new \RuntimeException('Ozon Performance: POST вернул HTTP 502'));

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit, 'Transient — exit FAILURE, чтобы cron-обвязка видела факт');

        $this->em->clear();
        $reloaded = $this->batchRepo->find($batch->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdScheduledBatchState::PLANNED, $reloaded->getState(), 'state не поменялся');
        self::assertNull($reloaded->getOzonUuid());
        self::assertNull($reloaded->getStartedAt());
        self::assertNull($reloaded->getFinishedAt());
        self::assertNull($reloaded->getLastError(), 'lastError не перезаписан при rollback');
        self::assertSame(0, $reloaded->getRetryCount(), 'retryCount не инкрементирован при rollback');
        self::assertSame(
            $originalScheduledAt->format('Y-m-d H:i:s'),
            $reloaded->getScheduledAt()->format('Y-m-d H:i:s'),
            'scheduled_at не сдвинут',
        );
    }

    public function testParallelWorkerSkipsLockedRow(): void
    {
        $job = $this->seedJob();
        $batch = $this->persistBatch($job, batchIndex: 0);

        // Открываем второе DBAL-соединение (имитация параллельного cron-worker'а),
        // захватываем PLANNED row FOR UPDATE и держим транзакцию открытой.
        $conn = $this->em->getConnection();
        $params = $conn->getParams();
        $other = DriverManager::getConnection($params);
        $other->beginTransaction();
        $other->executeQuery(
            'SELECT id FROM marketplace_ad_scheduled_batches WHERE id = :id FOR UPDATE',
            ['id' => $batch->getId()],
        );

        try {
            $this->clientMock->expects(self::never())->method('postStatistics');

            $tester = $this->makeCommandTester();
            $exit = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exit);
            self::assertStringContainsString('No PLANNED batches', $tester->getDisplay());
        } finally {
            $other->rollBack();
            $other->close();
        }

        $this->em->clear();
        $reloaded = $this->batchRepo->find($batch->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdScheduledBatchState::PLANNED, $reloaded->getState(), 'Батч не трогали');
    }

    public function testHelpOutputsDescription(): void
    {
        $tester = $this->makeCommandTester();
        $tester->execute(['--help' => true]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('app:marketplace-ads:scheduler', $display);
        self::assertStringContainsString('PLANNED', $display);
    }

    private function makeCommandTester(): CommandTester
    {
        $app = new Application(self::$kernel);
        $command = $app->find('app:marketplace-ads:scheduler');

        return new CommandTester($command);
    }

    private function seedJob(): AdLoadJob
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();

        $this->jobRepo->save($job);
        $this->em->flush();

        return $job;
    }

    private function persistBatch(AdLoadJob $job, int $batchIndex): AdScheduledBatch
    {
        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex($batchIndex)
            ->withCampaignIds(['camp-1', 'camp-2'])
            ->withDateRange(
                new \DateTimeImmutable('2026-03-01'),
                new \DateTimeImmutable('2026-03-10'),
            )
            ->withScheduledAt(new \DateTimeImmutable('-1 hour')) // Готов к подхвату
            ->build();

        $this->batchRepo->save($batch);
        $this->em->flush();

        return $batch;
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
}

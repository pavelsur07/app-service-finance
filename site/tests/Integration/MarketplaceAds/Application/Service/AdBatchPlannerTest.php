<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Application\Service;

use App\Company\Entity\Company;
use App\MarketplaceAds\Application\Service\AdBatchPlanner;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

/**
 * Integration-тесты {@see AdBatchPlanner} с реальной БД и реальным
 * `AdScheduledBatchRepository`. {@see OzonAdClient} стабится — planner
 * не должен ходить в Ozon в тестах.
 */
final class AdBatchPlannerTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-000000000001';

    /** @var OzonAdClient&MockObject */
    private OzonAdClient $ozonClient;
    private AdScheduledBatchRepository $batchRepo;
    private AdLoadJobRepository $jobRepo;
    private AdBatchPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batchRepo = self::getContainer()->get(AdScheduledBatchRepository::class);
        $this->jobRepo = self::getContainer()->get(AdLoadJobRepository::class);
        $this->ozonClient = $this->createMock(OzonAdClient::class);

        $this->planner = new AdBatchPlanner(
            $this->ozonClient,
            $this->batchRepo,
            $this->em,
            new NullLogger(),
        );
    }

    public function testPersistsBatchesToDatabase(): void
    {
        $job = $this->seedJob();

        $this->ozonClient->method('listAllSkuCampaigns')->willReturn(
            $this->buildCampaigns(25),
        );

        $count = $this->planner->planBatchesForJob(
            $job->getId(),
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-10'),
        );

        self::assertSame(3, $count, '25 кампаний → 3 батча (10+10+5)');

        $conn = $this->em->getConnection();
        $dbCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM marketplace_ad_scheduled_batches WHERE job_id = :jobId',
            ['jobId' => $job->getId()],
        );
        self::assertSame(3, $dbCount);

        $this->em->clear();
        $batches = $this->batchRepo->findByJobId($job->getId(), self::COMPANY_ID);
        self::assertCount(3, $batches);
        foreach ($batches as $b) {
            self::assertSame(AdScheduledBatchState::PLANNED, $b->getState());
            self::assertSame(0, $b->getRetryCount());
            self::assertSame(self::COMPANY_ID, $b->getCompanyId());
            self::assertSame('ozon', $b->getMarketplace());
        }
    }

    public function testIdempotentOnRepeatedCall(): void
    {
        $job = $this->seedJob();

        // Первый вызов: Ozon возвращает 15 кампаний → 2 батча.
        $this->ozonClient->expects(self::once())
            ->method('listAllSkuCampaigns')
            ->willReturn($this->buildCampaigns(15));

        $firstCount = $this->planner->planBatchesForJob(
            $job->getId(),
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-10'),
        );
        self::assertSame(2, $firstCount);
        $this->em->clear();

        // Повторный вызов: planner видит существующие батчи, не вызывает Ozon
        // и не создаёт новых (UNIQUE (job_id, batch_index) не нарушается).
        $secondCount = $this->planner->planBatchesForJob(
            $job->getId(),
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-10'),
        );
        self::assertSame(2, $secondCount, 'Идемпотентный повтор возвращает количество существующих');

        $conn = $this->em->getConnection();
        $dbCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM marketplace_ad_scheduled_batches WHERE job_id = :jobId',
            ['jobId' => $job->getId()],
        );
        self::assertSame(2, $dbCount, 'В БД должно остаться 2 записи, а не 4');
    }

    public function testUniqueIndexOnJobIdBatchIndexIsRespected(): void
    {
        $job = $this->seedJob();

        $this->ozonClient->method('listAllSkuCampaigns')->willReturn(
            $this->buildCampaigns(30),
        );

        $this->planner->planBatchesForJob(
            $job->getId(),
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-10'),
        );
        $this->em->clear();

        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT batch_index FROM marketplace_ad_scheduled_batches WHERE job_id = :jobId ORDER BY batch_index',
            ['jobId' => $job->getId()],
        );
        self::assertSame([0, 1, 2], array_map(static fn (array $r): int => (int) $r['batch_index'], $rows));
    }

    public function testScheduledAtIsSpaced120SecondsApart(): void
    {
        $job = $this->seedJob();

        $this->ozonClient->method('listAllSkuCampaigns')->willReturn(
            $this->buildCampaigns(30), // 3 батча
        );

        $this->planner->planBatchesForJob(
            $job->getId(),
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-10'),
        );
        $this->em->clear();

        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT batch_index, EXTRACT(EPOCH FROM scheduled_at)::bigint AS ts '
            . 'FROM marketplace_ad_scheduled_batches WHERE job_id = :jobId ORDER BY batch_index',
            ['jobId' => $job->getId()],
        );

        self::assertCount(3, $rows);
        $base = (int) $rows[0]['ts'];
        self::assertSame(120, (int) $rows[1]['ts'] - $base);
        self::assertSame(240, (int) $rows[2]['ts'] - $base);
    }

    /**
     * Регрессия Task-11.9a-fix: scheduled_at первого батча должен быть «due»
     * при сравнении с Postgres NOW() (UTC) сразу после planBatchesForJob.
     *
     * До фикса PHP писал DateTimeImmutable в локальном TZ (Europe/Moscow +3),
     * а `b.scheduled_at <= NOW()` в {@see AdScheduledBatchRepository::findNextPlanned()}
     * сравнивает с UTC → 3-часовой лаг. Тест падал бы с отрицательным delta.
     */
    public function testFirstBatchIsDueImmediatelyVsPostgresNow(): void
    {
        $job = $this->seedJob();

        $this->ozonClient->method('listAllSkuCampaigns')->willReturn(
            $this->buildCampaigns(5), // 1 батч — batchIndex=0 без сдвига
        );

        $this->planner->planBatchesForJob(
            $job->getId(),
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-10'),
        );

        $conn = $this->em->getConnection();
        $deltaSeconds = (int) $conn->fetchOne(
            'SELECT EXTRACT(EPOCH FROM (scheduled_at - NOW()))::bigint '
            . 'FROM marketplace_ad_scheduled_batches '
            . 'WHERE job_id = :jobId AND batch_index = 0',
            ['jobId' => $job->getId()],
        );

        // scheduled_at должен быть ≤ NOW() в пределах секунды. Допускаем
        // небольшую положительную погрешность (например, clock skew), но
        // 3-часовой дрейф (старый баг) завалит тест с запасом.
        self::assertLessThanOrEqual(
            1,
            $deltaSeconds,
            sprintf(
                'scheduled_at первого батча должен быть <= NOW() в пределах 1с, получили delta=%ds (TZ-баг?)',
                $deltaSeconds,
            ),
        );
        self::assertGreaterThanOrEqual(
            -5,
            $deltaSeconds,
            'scheduled_at не должен быть «давно в прошлом» — это тоже симптом TZ-расхождения',
        );
    }

    public function testEmptyCampaignsThrowsAndPersistsNothing(): void
    {
        $job = $this->seedJob();

        $this->ozonClient->method('listAllSkuCampaigns')->willReturn([]);

        try {
            $this->planner->planBatchesForJob(
                $job->getId(),
                self::COMPANY_ID,
                new \DateTimeImmutable('2026-04-01'),
                new \DateTimeImmutable('2026-04-10'),
            );
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertMatchesRegularExpression('/No SKU campaigns found/', $e->getMessage());
        }

        $conn = $this->em->getConnection();
        $dbCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_ad_scheduled_batches');
        self::assertSame(0, $dbCount, 'При исключении planner не должен оставлять partial-state');
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

    /**
     * @return list<array{id: string, title: string, state: string}>
     */
    private function buildCampaigns(int $count): array
    {
        /** @var list<array{id: string, title: string, state: string}> $campaigns */
        $campaigns = [];
        for ($i = 0; $i < $count; ++$i) {
            $campaigns[] = [
                'id' => sprintf('camp-%06d', $i),
                'title' => sprintf('Campaign %d', $i),
                'state' => 'CAMPAIGN_STATE_RUNNING',
            ];
        }

        return $campaigns;
    }
}

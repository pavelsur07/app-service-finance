<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds;

use App\Company\Entity\Company;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Entity\AdScheduledBatch;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Builders\MarketplaceAds\AdScheduledBatchBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;

final class AdScheduledBatchRepositoryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-000000000001';

    private AdScheduledBatchRepository $repository;
    private AdLoadJobRepository $jobRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(AdScheduledBatchRepository::class);
        $this->jobRepository = self::getContainer()->get(AdLoadJobRepository::class);
    }

    public function testSavePersistsWithoutFlush(): void
    {
        $job = $this->seedJob();

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->build();

        $this->repository->save($batch);

        // save() делает только persist — до flush в БД ничего нет
        // (консистентно с AdLoadJobRepository::save()).
        $conn = $this->em->getConnection();
        $count = (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_ad_scheduled_batches');
        self::assertSame(0, $count);

        $this->em->flush();
        $count = (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_ad_scheduled_batches');
        self::assertSame(1, $count);
    }

    public function testSaveAndReloadRoundTrip(): void
    {
        $job = $this->seedJob();

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withCampaignIds(['c-1', 'c-2', 'c-3'])
            ->withDateRange(
                new \DateTimeImmutable('2026-03-01'),
                new \DateTimeImmutable('2026-03-10'),
            )
            ->build();

        $this->repository->save($batch);
        $id = $batch->getId();
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->repository->find($id);

        self::assertNotNull($loaded);
        self::assertSame($id, $loaded->getId());
        self::assertSame($job->getId(), $loaded->getJobId());
        self::assertSame(self::COMPANY_ID, $loaded->getCompanyId());
        self::assertSame('ozon', $loaded->getMarketplace());
        self::assertSame(['c-1', 'c-2', 'c-3'], $loaded->getCampaignIds());
        self::assertSame('2026-03-01', $loaded->getDateFrom()->format('Y-m-d'));
        self::assertSame('2026-03-10', $loaded->getDateTo()->format('Y-m-d'));
        self::assertSame(0, $loaded->getBatchIndex());
        self::assertSame(AdScheduledBatchState::PLANNED, $loaded->getState());
        self::assertSame(0, $loaded->getRetryCount());
        self::assertNull($loaded->getStartedAt());
        self::assertNull($loaded->getFinishedAt());
        self::assertNull($loaded->getOzonUuid());
        self::assertNull($loaded->getStoragePath());
    }

    public function testFindNextPlannedReturnsNullWhenEmpty(): void
    {
        $this->seedJob();

        $result = $this->repository->findNextPlanned();

        self::assertNull($result);
    }

    public function testFindNextPlannedPicksOldestScheduledAt(): void
    {
        $job = $this->seedJob();

        $older = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(5)
            ->withScheduledAt(new \DateTimeImmutable('2026-03-01 08:00:00'))
            ->build();

        $newer = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withScheduledAt(new \DateTimeImmutable('2026-03-01 09:00:00'))
            ->build();

        // newer batch_index=0 идёт перед older по вторичной сортировке, но primary — scheduled_at.
        $this->repository->save($newer);
        $this->repository->save($older);
        $this->em->flush();
        $this->em->clear();

        $picked = $this->repository->findNextPlanned();

        self::assertNotNull($picked);
        self::assertSame($older->getId(), $picked->getId(), 'findNextPlanned обязан выбрать самый старый scheduled_at');
    }

    public function testFindNextPlannedTieBreaksByBatchIndex(): void
    {
        $job = $this->seedJob();
        $sameScheduledAt = new \DateTimeImmutable('2026-03-01 09:00:00');

        $high = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(10)
            ->withScheduledAt($sameScheduledAt)
            ->build();

        $low = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->withScheduledAt($sameScheduledAt)
            ->build();

        $this->repository->save($high);
        $this->repository->save($low);
        $this->em->flush();
        $this->em->clear();

        $picked = $this->repository->findNextPlanned();

        self::assertNotNull($picked);
        self::assertSame($low->getId(), $picked->getId(), 'Ties — по batch_index ASC');
    }

    public function testFindNextPlannedSkipsBatchesScheduledInFuture(): void
    {
        $job = $this->seedJob();

        $future = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withScheduledAt((new \DateTimeImmutable())->modify('+1 hour'))
            ->build();

        $this->repository->save($future);
        $this->em->flush();
        $this->em->clear();

        $picked = $this->repository->findNextPlanned();

        self::assertNull($picked, 'Батч со scheduled_at в будущем не должен выбираться (retry/backoff).');
    }

    public function testFindNextPlannedPicksDueBatchEvenIfAnotherIsFurtherInFuture(): void
    {
        $job = $this->seedJob();

        $due = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(5)
            ->withScheduledAt((new \DateTimeImmutable())->modify('-1 minute'))
            ->build();

        $future = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withScheduledAt((new \DateTimeImmutable())->modify('+1 hour'))
            ->build();

        $this->repository->save($future);
        $this->repository->save($due);
        $this->em->flush();
        $this->em->clear();

        $picked = $this->repository->findNextPlanned();

        self::assertNotNull($picked);
        self::assertSame($due->getId(), $picked->getId(), 'Выбран должен быть готовый батч, future игнорируется несмотря на меньший batch_index.');
    }

    public function testFindNextPlannedSkipsNonPlannedStates(): void
    {
        $job = $this->seedJob();

        $inFlight = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withState(AdScheduledBatchState::IN_FLIGHT)
            ->withScheduledAt(new \DateTimeImmutable('2026-03-01 01:00:00'))
            ->build();

        $ok = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->withState(AdScheduledBatchState::OK)
            ->withScheduledAt(new \DateTimeImmutable('2026-03-01 02:00:00'))
            ->build();

        $planned = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(2)
            ->withScheduledAt(new \DateTimeImmutable('2026-03-01 03:00:00'))
            ->build();

        $this->repository->save($inFlight);
        $this->repository->save($ok);
        $this->repository->save($planned);
        $this->em->flush();
        $this->em->clear();

        $picked = $this->repository->findNextPlanned();

        self::assertNotNull($picked);
        self::assertSame($planned->getId(), $picked->getId(), 'findNextPlanned берёт только PLANNED');
    }

    public function testFindNextPlannedSkipsLockedRowConcurrently(): void
    {
        $job = $this->seedJob();

        $first = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withScheduledAt(new \DateTimeImmutable('2026-03-01 08:00:00'))
            ->build();

        $second = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->withScheduledAt(new \DateTimeImmutable('2026-03-01 09:00:00'))
            ->build();

        $this->repository->save($first);
        $this->repository->save($second);
        $this->em->flush();
        $this->em->clear();

        // Открываем отдельное DBAL-соединение (имитация другого worker'а) и
        // захватываем первую строку через FOR UPDATE. Основной em тоже в своей
        // транзакции возьмёт SKIP LOCKED → вторую.
        $conn = $this->em->getConnection();
        $params = $conn->getParams();
        $other = DriverManager::getConnection($params);
        $other->beginTransaction();
        $other->executeQuery(
            'SELECT id FROM marketplace_ad_scheduled_batches WHERE id = :id FOR UPDATE',
            ['id' => $first->getId()],
        );

        try {
            $this->em->beginTransaction();
            $picked = $this->repository->findNextPlanned();
            self::assertNotNull($picked, 'Должен получить второй (незаблокированный) батч');
            self::assertSame($second->getId(), $picked->getId());
            $this->em->commit();
        } finally {
            $other->rollBack();
            $other->close();
        }
    }

    public function testFindAllInFlightReturnsOnlyInFlightOrderedByStartedAt(): void
    {
        $job = $this->seedJob();

        $older = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withState(AdScheduledBatchState::IN_FLIGHT)
            ->build();
        $this->setProperty($older, 'startedAt', new \DateTimeImmutable('2026-03-01 08:00:00'));

        $newer = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->withState(AdScheduledBatchState::IN_FLIGHT)
            ->build();
        $this->setProperty($newer, 'startedAt', new \DateTimeImmutable('2026-03-01 10:00:00'));

        $planned = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(2)
            ->build();

        $this->repository->save($planned);
        $this->repository->save($newer);
        $this->repository->save($older);
        $this->em->flush();
        $this->em->clear();

        $result = $this->repository->findAllInFlight();

        self::assertCount(2, $result);
        self::assertSame($older->getId(), $result[0]->getId());
        self::assertSame($newer->getId(), $result[1]->getId());
    }

    public function testFindByJobIdReturnsAllStatesOrderedByBatchIndex(): void
    {
        $jobA = $this->seedJob();
        $jobB = $this->seedJob('bbbbbbbb-bbbb-aaaa-aaaa-222222222222', '33333333-3333-3333-3333-000000000002', 'b@example.test');

        $a1 = AdScheduledBatchBuilder::aBatch()
            ->withJobId($jobA->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(2)
            ->build();
        $a0 = AdScheduledBatchBuilder::aBatch()
            ->withJobId($jobA->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withState(AdScheduledBatchState::OK)
            ->build();
        $other = AdScheduledBatchBuilder::aBatch()
            ->withJobId($jobB->getId())
            ->withCompanyId('bbbbbbbb-bbbb-aaaa-aaaa-222222222222')
            ->withIndex(0)
            ->build();

        $this->repository->save($a1);
        $this->repository->save($a0);
        $this->repository->save($other);
        $this->em->flush();
        $this->em->clear();

        $result = $this->repository->findByJobId($jobA->getId(), self::COMPANY_ID);

        self::assertCount(2, $result);
        self::assertSame(0, $result[0]->getBatchIndex());
        self::assertSame(2, $result[1]->getBatchIndex());
    }

    public function testFindByJobIdReturnsEmptyForForeignCompanyIDOR(): void
    {
        $job = $this->seedJob();
        $otherCompanyId = '11111111-1111-1111-1111-000000000002';

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->build();

        $this->repository->save($batch);
        $this->em->flush();
        $this->em->clear();

        // Своя компания — видит
        self::assertCount(1, $this->repository->findByJobId($job->getId(), self::COMPANY_ID));
        // Чужая — не видит, даже зная jobId
        self::assertCount(0, $this->repository->findByJobId($job->getId(), $otherCompanyId));
    }

    public function testCountStatesForJobReturnsAggregatesByState(): void
    {
        $job = $this->seedJob();

        $cfg = [
            [0, AdScheduledBatchState::PLANNED],
            [1, AdScheduledBatchState::PLANNED],
            [2, AdScheduledBatchState::PLANNED],
            [3, AdScheduledBatchState::IN_FLIGHT],
            [4, AdScheduledBatchState::OK],
            [5, AdScheduledBatchState::OK],
            [6, AdScheduledBatchState::FAILED],
        ];

        foreach ($cfg as [$idx, $state]) {
            $batch = AdScheduledBatchBuilder::aBatch()
                ->withJobId($job->getId())
                ->withCompanyId(self::COMPANY_ID)
                ->withIndex($idx)
                ->withState($state)
                ->build();
            $this->repository->save($batch);
        }
        $this->em->flush();
        $this->em->clear();

        $counts = $this->repository->countStatesForJob($job->getId(), self::COMPANY_ID);

        self::assertSame(3, $counts['PLANNED'] ?? 0);
        self::assertSame(1, $counts['IN_FLIGHT'] ?? 0);
        self::assertSame(2, $counts['OK'] ?? 0);
        self::assertSame(1, $counts['FAILED'] ?? 0);
        self::assertArrayNotHasKey('ABANDONED', $counts, 'Пустые буксеты не должны появляться');
    }

    public function testCountStatesForJobReturnsEmptyForForeignCompanyIDOR(): void
    {
        $job = $this->seedJob();
        $otherCompanyId = '11111111-1111-1111-1111-000000000002';

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->build();

        $this->repository->save($batch);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(
            [],
            $this->repository->countStatesForJob($job->getId(), $otherCompanyId),
            'Чужой company_id не должен видеть агрегаты job\'а.',
        );
    }

    public function testCountStatesForJobReturnsEmptyArrayForUnknownJob(): void
    {
        $this->seedJob();

        $counts = $this->repository->countStatesForJob('99999999-9999-9999-9999-999999999999', self::COMPANY_ID);

        self::assertSame([], $counts);
    }

    public function testFindDownloadableByJobIdReturnsOnlyBatchesWithStorage(): void
    {
        $job = $this->seedJob();

        $withFile = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withState(AdScheduledBatchState::OK)
            ->withStorage('marketplace-ads/file-1.csv', 'hash1', 1024)
            ->build();

        $noFile = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->withState(AdScheduledBatchState::OK)
            ->build();

        $this->repository->save($withFile);
        $this->repository->save($noFile);
        $this->em->flush();
        $this->em->clear();

        $result = $this->repository->findDownloadableByJobId($job->getId(), self::COMPANY_ID);

        self::assertCount(1, $result);
        self::assertSame($withFile->getId(), $result[0]->getId());
        self::assertSame('marketplace-ads/file-1.csv', $result[0]->getStoragePath());
        self::assertSame('hash1', $result[0]->getFileHash());
        self::assertSame(1024, $result[0]->getFileSize());
    }

    public function testFindDownloadableByJobIdReturnsEmptyForForeignCompanyIDOR(): void
    {
        $job = $this->seedJob();
        $otherCompanyId = '11111111-1111-1111-1111-000000000002';

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withState(AdScheduledBatchState::OK)
            ->withStorage('marketplace-ads/file-1.csv', 'hash1', 1024)
            ->build();

        $this->repository->save($batch);
        $this->em->flush();
        $this->em->clear();

        // Своя компания — видит ссылку
        self::assertCount(1, $this->repository->findDownloadableByJobId($job->getId(), self::COMPANY_ID));
        // Чужая — нет, даже при валидном jobId (UI Task-11.8 не должна подтянуть файлы через подмену id)
        self::assertCount(0, $this->repository->findDownloadableByJobId($job->getId(), $otherCompanyId));
    }

    public function testUpdatedAtAdvancesOnSetters(): void
    {
        $job = $this->seedJob();

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->build();

        $this->repository->save($batch);
        $createdUpdatedAt = $batch->getUpdatedAt();

        // гарантируем дельту >= 1с, чтобы сравнение было устойчивым
        usleep(1_100_000);

        $batch->setState(AdScheduledBatchState::IN_FLIGHT);
        self::assertGreaterThan($createdUpdatedAt, $batch->getUpdatedAt());
    }

    public function testConstructorRejectsInvertedDateRange(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/dateFrom не может быть позже dateTo/');

        new AdScheduledBatch(
            id: 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            jobId: AdScheduledBatchBuilder::DEFAULT_JOB_ID,
            companyId: self::COMPANY_ID,
            campaignIds: ['c-1'],
            dateFrom: new \DateTimeImmutable('2026-03-10'),
            dateTo: new \DateTimeImmutable('2026-03-01'),
            batchIndex: 0,
            scheduledAt: new \DateTimeImmutable(),
        );
    }

    public function testConstructorRejectsNonStringCampaignIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/campaignIds должны быть строками/');

        new AdScheduledBatch(
            id: 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            jobId: AdScheduledBatchBuilder::DEFAULT_JOB_ID,
            companyId: self::COMPANY_ID,
            campaignIds: ['c-1', 42, 'c-3'], // @phpstan-ignore-line
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-10'),
            batchIndex: 0,
            scheduledAt: new \DateTimeImmutable(),
        );
    }

    public function testSetFileSizeRejectsNegative(): void
    {
        $job = $this->seedJob();
        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->build();

        $this->expectException(\InvalidArgumentException::class);
        $batch->setFileSize(-1);
    }

    private function seedJob(
        string $companyId = self::COMPANY_ID,
        string $ownerId = self::OWNER_ID,
        string $email = 'a@example.test',
    ): AdLoadJob {
        $this->seedCompany($companyId, $ownerId, $email);
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId($companyId)
            ->withIndex(random_int(1, 999_999))
            ->build();

        $this->jobRepository->save($job);
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

    private function setProperty(AdScheduledBatch $batch, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty(AdScheduledBatch::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($batch, $value);
    }
}

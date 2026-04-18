<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class AdLoadJobRepositoryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000002';
    private const OWNER_ID = '22222222-2222-2222-2222-000000000001';
    private const OTHER_OWNER_ID = '22222222-2222-2222-2222-000000000002';

    private AdLoadJobRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(AdLoadJobRepository::class);
    }

    public function testSavePersistsJobWithoutFlush(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();

        $this->repository->save($job);

        // save() делает только persist, без flush — в БД ничего нет
        $conn = $this->em->getConnection();
        $count = (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_ad_load_jobs');
        self::assertSame(0, $count);

        // После flush — появляется
        $this->em->flush();
        $count = (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_ad_load_jobs');
        self::assertSame(1, $count);
    }

    public function testSaveAndFindByIdAndCompanyRoundTrip(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->withDateRange(
                new \DateTimeImmutable('2026-04-01'),
                new \DateTimeImmutable('2026-04-10'),
            )
            ->build();

        $this->repository->save($job);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->repository->findByIdAndCompany($job->getId(), self::COMPANY_ID);

        self::assertNotNull($loaded);
        self::assertSame($job->getId(), $loaded->getId());
        self::assertSame(self::COMPANY_ID, $loaded->getCompanyId());
        self::assertSame(MarketplaceType::OZON, $loaded->getMarketplace());
        self::assertSame(10, $loaded->getTotalDays());
        self::assertSame(AdLoadJobStatus::PENDING, $loaded->getStatus());
    }

    public function testFindByIdAndCompanyReturnsNullForOtherCompanyIDOR(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->seedCompany(self::OTHER_COMPANY_ID, self::OTHER_OWNER_ID, 'b@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();

        $this->repository->save($job);
        $this->em->flush();
        $this->em->clear();

        // Чужая компания не должна найти задание
        $leaked = $this->repository->findByIdAndCompany($job->getId(), self::OTHER_COMPANY_ID);
        self::assertNull($leaked);

        // Своя находит
        $own = $this->repository->findByIdAndCompany($job->getId(), self::COMPANY_ID);
        self::assertNotNull($own);
    }

    public function testFindLatestActiveJobByCompanyAndMarketplace(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        // Старое завершённое задание
        $completed = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->asCompleted()
            ->build();

        // Активное задание (RUNNING) — должно быть найдено
        $running = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(2)
            ->asRunning()
            ->build();

        // Активное для другого маркетплейса — не должно быть найдено при фильтре OZON
        $otherMarketplace = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(3)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->asRunning()
            ->build();

        foreach ([$completed, $running, $otherMarketplace] as $job) {
            $this->repository->save($job);
        }
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository->findLatestActiveJobByCompanyAndMarketplace(
            self::COMPANY_ID,
            MarketplaceType::OZON,
        );

        self::assertNotNull($found);
        self::assertSame($running->getId(), $found->getId());
    }

    public function testFindLatestActiveReturnsNullWhenOnlyTerminalJobs(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $completed = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->asCompleted()
            ->build();

        $failed = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(2)
            ->asFailed('test')
            ->build();

        foreach ([$completed, $failed] as $job) {
            $this->repository->save($job);
        }
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository->findLatestActiveJobByCompanyAndMarketplace(
            self::COMPANY_ID,
            MarketplaceType::OZON,
        );

        self::assertNull($found);
    }

    public function testFindActiveJobCoveringDate(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->withDateRange(
                new \DateTimeImmutable('2026-04-01'),
                new \DateTimeImmutable('2026-04-10'),
            )
            ->asRunning()
            ->build();

        $this->repository->save($job);
        $this->em->flush();
        $this->em->clear();

        // Дата внутри диапазона — найдено
        $inside = $this->repository->findActiveJobCoveringDate(
            self::COMPANY_ID,
            MarketplaceType::OZON,
            new \DateTimeImmutable('2026-04-05'),
        );
        self::assertNotNull($inside);
        self::assertSame($job->getId(), $inside->getId());

        // Граничные даты — включительно
        $fromBorder = $this->repository->findActiveJobCoveringDate(
            self::COMPANY_ID,
            MarketplaceType::OZON,
            new \DateTimeImmutable('2026-04-01'),
        );
        self::assertNotNull($fromBorder);

        $toBorder = $this->repository->findActiveJobCoveringDate(
            self::COMPANY_ID,
            MarketplaceType::OZON,
            new \DateTimeImmutable('2026-04-10'),
        );
        self::assertNotNull($toBorder);

        // Дата вне диапазона — не найдено
        $outside = $this->repository->findActiveJobCoveringDate(
            self::COMPANY_ID,
            MarketplaceType::OZON,
            new \DateTimeImmutable('2026-04-11'),
        );
        self::assertNull($outside);
    }

    public function testIncrementLoadedDaysIsAtomicAndIDORSafe(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->seedCompany(self::OTHER_COMPANY_ID, self::OTHER_OWNER_ID, 'b@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();
        $this->repository->save($job);
        $this->em->flush();
        $jobId = $job->getId();
        $this->em->clear();

        // 5 последовательных инкрементов (имитация параллельных воркеров:
        // каждый вызов — независимая SQL-транзакция, read-modify-write race исключён).
        for ($i = 0; $i < 5; ++$i) {
            $affected = $this->repository->incrementLoadedDays($jobId, self::COMPANY_ID);
            self::assertSame(1, $affected);
        }

        $reloaded = $this->repository->findByIdAndCompany($jobId, self::COMPANY_ID);
        self::assertNotNull($reloaded);
        self::assertSame(5, $reloaded->getLoadedDays());

        // IDOR-guard: попытка инкрементить под чужой компанией — 0 затронутых строк
        $leaked = $this->repository->incrementLoadedDays($jobId, self::OTHER_COMPANY_ID);
        self::assertSame(0, $leaked);

        $this->em->clear();
        $reloaded = $this->repository->findByIdAndCompany($jobId, self::COMPANY_ID);
        self::assertNotNull($reloaded);
        self::assertSame(5, $reloaded->getLoadedDays(), 'Счётчик не должен измениться от чужого company_id');
    }

    public function testIncrementProcessedAndFailedDays(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();
        $this->repository->save($job);
        $this->em->flush();
        $jobId = $job->getId();
        $this->em->clear();

        $this->repository->incrementProcessedDays($jobId, self::COMPANY_ID, 3);
        $this->repository->incrementFailedDays($jobId, self::COMPANY_ID, 2);

        $reloaded = $this->repository->findByIdAndCompany($jobId, self::COMPANY_ID);
        self::assertNotNull($reloaded);
        self::assertSame(3, $reloaded->getProcessedDays());
        self::assertSame(2, $reloaded->getFailedDays());
        self::assertSame(0, $reloaded->getLoadedDays());
    }

    /**
     * @dataProvider nonPositiveDeltaProvider
     */
    public function testIncrementRejectsNonPositiveDelta(int $delta): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();
        $this->repository->save($job);
        $this->em->flush();
        $jobId = $job->getId();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Инкремент должен быть >= 1/');

        $this->repository->incrementLoadedDays($jobId, self::COMPANY_ID, $delta);
    }

    public static function nonPositiveDeltaProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'large negative' => [-100],
        ];
    }

    public function testIncrementProcessedAndFailedAlsoRejectNonPositiveDelta(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();
        $this->repository->save($job);
        $this->em->flush();
        $jobId = $job->getId();

        try {
            $this->repository->incrementProcessedDays($jobId, self::COMPANY_ID, 0);
            self::fail('Expected InvalidArgumentException for processed delta = 0');
        } catch (\InvalidArgumentException) {
        }

        try {
            $this->repository->incrementFailedDays($jobId, self::COMPANY_ID, -5);
            self::fail('Expected InvalidArgumentException for failed delta = -5');
        } catch (\InvalidArgumentException) {
        }

        // Счётчики остались по нулям — невалидный вызов не дошёл до SQL.
        $this->em->clear();
        $reloaded = $this->repository->findByIdAndCompany($jobId, self::COMPANY_ID);
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->getProcessedDays());
        self::assertSame(0, $reloaded->getFailedDays());
    }

    public function testIncrementChunksCompletedIsAtomicAndIDORSafe(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->seedCompany(self::OTHER_COMPANY_ID, self::OTHER_OWNER_ID, 'b@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();
        $this->repository->save($job);
        $this->em->flush();
        $jobId = $job->getId();
        $this->em->clear();

        // 100 последовательных инкрементов: каждый вызов — независимая SQL-транзакция,
        // read-modify-write race исключён (значение хранится только в БД).
        for ($i = 0; $i < 100; ++$i) {
            $affected = $this->repository->incrementChunksCompleted($jobId, self::COMPANY_ID);
            self::assertSame(1, $affected);
        }

        $reloaded = $this->repository->findByIdAndCompany($jobId, self::COMPANY_ID);
        self::assertNotNull($reloaded);
        self::assertSame(100, $reloaded->getChunksCompleted());

        // IDOR-guard: попытка инкрементить под чужой компанией — 0 затронутых строк.
        $leaked = $this->repository->incrementChunksCompleted($jobId, self::OTHER_COMPANY_ID);
        self::assertSame(0, $leaked);

        $this->em->clear();
        $reloaded = $this->repository->findByIdAndCompany($jobId, self::COMPANY_ID);
        self::assertNotNull($reloaded);
        self::assertSame(100, $reloaded->getChunksCompleted(), 'Счётчик не должен измениться от чужого company_id');
    }

    /**
     * @dataProvider nonPositiveDeltaProvider
     */
    public function testIncrementChunksCompletedRejectsNonPositiveDelta(int $delta): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();
        $this->repository->save($job);
        $this->em->flush();
        $jobId = $job->getId();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Инкремент должен быть >= 1/');

        $this->repository->incrementChunksCompleted($jobId, self::COMPANY_ID, $delta);
    }

    public function testFindReturnsJobWithoutCompanyCheck(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();
        $this->repository->save($job);
        $this->em->flush();
        $jobId = $job->getId();
        $this->em->clear();

        // find() — trusted-контекст (Messenger-хендлеры); company_id не фигурирует в WHERE.
        $found = $this->repository->find($jobId);

        self::assertNotNull($found);
        self::assertSame($jobId, $found->getId());
        self::assertSame(self::COMPANY_ID, $found->getCompanyId());
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $found = $this->repository->find('00000000-0000-0000-0000-000000000000');

        self::assertNull($found);
    }

    public function testIncrementBypassesUnitOfWorkAndUpdatesDirectly(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();
        $this->repository->save($job);
        $this->em->flush();
        $jobId = $job->getId();

        // Entity уже в UoW, loadedDays=0. Инкрементируем через raw SQL.
        $this->repository->incrementLoadedDays($jobId, self::COMPANY_ID, 7);

        // БД должна показать 7 даже без em->flush() / em->refresh() — raw SQL шёл напрямую.
        $conn = $this->em->getConnection();
        $dbValue = (int) $conn->fetchOne(
            'SELECT loaded_days FROM marketplace_ad_load_jobs WHERE id = :id',
            ['id' => $jobId],
        );
        self::assertSame(7, $dbValue);
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

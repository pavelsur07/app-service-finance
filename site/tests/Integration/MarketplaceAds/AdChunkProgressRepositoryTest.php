<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds;

use App\Company\Entity\Company;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Repository\AdChunkProgressRepository;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class AdChunkProgressRepositoryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000002';
    private const OWNER_ID = '22222222-2222-2222-2222-000000000001';
    private const OTHER_OWNER_ID = '22222222-2222-2222-2222-000000000002';

    private AdChunkProgressRepository $repository;
    private AdLoadJobRepository $jobRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(AdChunkProgressRepository::class);
        $this->jobRepository = self::getContainer()->get(AdLoadJobRepository::class);
    }

    public function testMarkChunkCompletedReturnsTrueOnFirstInsert(): void
    {
        $job = $this->seedJob(self::COMPANY_ID);

        $inserted = $this->repository->markChunkCompleted(
            $job->getId(),
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-03'),
        );

        self::assertTrue($inserted);
        self::assertSame(1, $this->repository->countCompletedChunks($job->getId(), self::COMPANY_ID));
    }

    public function testMarkChunkCompletedReturnsFalseOnDuplicate(): void
    {
        $job = $this->seedJob(self::COMPANY_ID);
        $dateFrom = new \DateTimeImmutable('2026-03-01');
        $dateTo = new \DateTimeImmutable('2026-03-03');

        $first = $this->repository->markChunkCompleted($job->getId(), self::COMPANY_ID, $dateFrom, $dateTo);
        $second = $this->repository->markChunkCompleted($job->getId(), self::COMPANY_ID, $dateFrom, $dateTo);

        self::assertTrue($first, 'Первая вставка должна пройти');
        self::assertFalse($second, 'Повторная вставка того же чанка должна вернуть false (ON CONFLICT DO NOTHING)');
        self::assertSame(1, $this->repository->countCompletedChunks($job->getId(), self::COMPANY_ID));
    }

    public function testMarkChunkCompletedAllowsDifferentRangesOnSameJob(): void
    {
        $job = $this->seedJob(self::COMPANY_ID);

        $a = $this->repository->markChunkCompleted(
            $job->getId(),
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-03'),
        );
        $b = $this->repository->markChunkCompleted(
            $job->getId(),
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-04'),
            new \DateTimeImmutable('2026-03-06'),
        );

        self::assertTrue($a);
        self::assertTrue($b);
        self::assertSame(2, $this->repository->countCompletedChunks($job->getId(), self::COMPANY_ID));
    }

    public function testCountCompletedChunksReturnsZeroForFreshJob(): void
    {
        $job = $this->seedJob(self::COMPANY_ID);

        self::assertSame(0, $this->repository->countCompletedChunks($job->getId(), self::COMPANY_ID));
    }

    public function testCountCompletedChunksReflectsMultipleInserts(): void
    {
        $job = $this->seedJob(self::COMPANY_ID);

        // 5 разных чанков × 3 дня
        for ($i = 0; $i < 5; ++$i) {
            $this->repository->markChunkCompleted(
                $job->getId(),
                self::COMPANY_ID,
                new \DateTimeImmutable(sprintf('2026-03-%02d', 1 + $i * 3)),
                new \DateTimeImmutable(sprintf('2026-03-%02d', 3 + $i * 3)),
            );
        }

        self::assertSame(5, $this->repository->countCompletedChunks($job->getId(), self::COMPANY_ID));
    }

    public function testMarkChunkCompletedRejectsForeignCompanyIDOR(): void
    {
        $job = $this->seedJob(self::COMPANY_ID);
        $this->seedCompany(self::OTHER_COMPANY_ID, self::OTHER_OWNER_ID, 'b@example.test');
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/не принадлежит компании/');

        $this->repository->markChunkCompleted(
            $job->getId(),
            self::OTHER_COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-03'),
        );
    }

    public function testCountCompletedChunksRejectsForeignCompanyIDOR(): void
    {
        $job = $this->seedJob(self::COMPANY_ID);
        $this->seedCompany(self::OTHER_COMPANY_ID, self::OTHER_OWNER_ID, 'b@example.test');
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/не принадлежит компании/');

        $this->repository->countCompletedChunks($job->getId(), self::OTHER_COMPANY_ID);
    }

    public function testMarkChunkCompletedRejectsUnknownJob(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/не найден или не принадлежит/');

        $this->repository->markChunkCompleted(
            '00000000-0000-0000-0000-000000000000',
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-03'),
        );
    }

    public function testMarkChunkCompletedRejectsInvertedDateRange(): void
    {
        $job = $this->seedJob(self::COMPANY_ID);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/dateFrom не может быть позже dateTo/');

        $this->repository->markChunkCompleted(
            $job->getId(),
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-10'),
            new \DateTimeImmutable('2026-03-01'),
        );
    }

    public function testMarkChunkCompletedRejectsMalformedUuid(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/не найден или не принадлежит/');

        $this->repository->markChunkCompleted(
            'not-a-uuid',
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-03'),
        );
    }

    public function testCountCompletedChunksRejectsMalformedUuid(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/не найден или не принадлежит/');

        $this->repository->countCompletedChunks('not-a-uuid', self::COMPANY_ID);
    }

    public function testCascadeDeleteRemovesProgressWhenJobIsDeleted(): void
    {
        $job = $this->seedJob(self::COMPANY_ID);

        $this->repository->markChunkCompleted(
            $job->getId(),
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-03'),
        );
        $this->repository->markChunkCompleted(
            $job->getId(),
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-04'),
            new \DateTimeImmutable('2026-03-06'),
        );

        // Sanity: прогресс есть.
        $conn = $this->em->getConnection();
        self::assertSame(
            2,
            (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_ad_chunk_progress WHERE job_id = :id', ['id' => $job->getId()]),
        );

        // Удаляем родительский job — FK ON DELETE CASCADE обязан убрать прогресс.
        $managedJob = $this->jobRepository->find($job->getId());
        self::assertNotNull($managedJob);
        $this->em->remove($managedJob);
        $this->em->flush();

        self::assertSame(
            0,
            (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_ad_chunk_progress WHERE job_id = :id', ['id' => $job->getId()]),
        );
    }

    private function seedJob(string $companyId): AdLoadJob
    {
        $this->seedCompany($companyId, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId($companyId)
            ->withIndex(1)
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
}

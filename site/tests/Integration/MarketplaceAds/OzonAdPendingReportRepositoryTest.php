<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds;

use App\Company\Entity\Company;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class OzonAdPendingReportRepositoryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-000000000001';

    private OzonAdPendingReportRepository $repository;
    private AdLoadJobRepository $jobRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(OzonAdPendingReportRepository::class);
        $this->jobRepository = self::getContainer()->get(AdLoadJobRepository::class);
    }

    public function testCreatePersistsRecordImmediatelyInRequestedState(): void
    {
        $this->seedCompany();
        $this->em->flush();

        $entity = $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-create-1',
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-03'),
            campaignIds: ['111', '222'],
            jobId: null,
        );

        self::assertSame(OzonAdPendingReportState::REQUESTED, $entity->getState());

        // Сразу читаем из БД без em->flush() — create() обязан flush'ить сам.
        $this->em->clear();
        $found = $this->repository->findByOzonUuid(self::COMPANY_ID, 'uuid-create-1');

        self::assertInstanceOf(OzonAdPendingReport::class, $found);
        self::assertSame(self::COMPANY_ID, $found->getCompanyId());
        self::assertSame('uuid-create-1', $found->getOzonUuid());
        self::assertSame(['111', '222'], $found->getCampaignIds());
        self::assertSame(OzonAdPendingReportState::REQUESTED, $found->getState());
        self::assertSame(0, $found->getPollAttempts());
        self::assertNull($found->getLastCheckedAt());
        self::assertNull($found->getFirstNonPendingAt());
        self::assertNull($found->getFinalizedAt());
    }

    public function testCreateLinksJobIdWhenProvided(): void
    {
        $job = $this->seedJob();

        $entity = $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-with-job',
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-03'),
            campaignIds: ['111'],
            jobId: $job->getId(),
        );

        self::assertSame($job->getId(), $entity->getJobId());
    }

    public function testUpdateStateAdvancesAttemptAndTimestamps(): void
    {
        $this->seedCompany();
        $this->em->flush();

        $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-upd-1',
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-03'),
            campaignIds: ['111'],
            jobId: null,
        );

        $firstNow = new \DateTimeImmutable('2026-04-01 10:00:00');
        $rows = $this->repository->updateState(self::COMPANY_ID, 'uuid-upd-1', 'NOT_STARTED', $firstNow, 1);
        self::assertSame(1, $rows);

        $this->em->clear();
        $afterFirst = $this->repository->findByOzonUuid(self::COMPANY_ID, 'uuid-upd-1');
        self::assertNotNull($afterFirst);
        self::assertSame('NOT_STARTED', $afterFirst->getState());
        self::assertSame(1, $afterFirst->getPollAttempts());
        self::assertNotNull($afterFirst->getLastCheckedAt());
        self::assertSame($firstNow->format('Y-m-d H:i:s'), $afterFirst->getLastCheckedAt()->format('Y-m-d H:i:s'));
        self::assertNull($afterFirst->getFirstNonPendingAt());

        // Second iteration — state moves off NOT_STARTED, firstNonPendingAt должен зафиксироваться.
        $secondNow = new \DateTimeImmutable('2026-04-01 10:00:05');
        $this->repository->updateState(self::COMPANY_ID, 'uuid-upd-1', 'IN_PROGRESS', $secondNow, 2, $secondNow);

        $this->em->clear();
        $afterSecond = $this->repository->findByOzonUuid(self::COMPANY_ID, 'uuid-upd-1');
        self::assertNotNull($afterSecond);
        self::assertSame('IN_PROGRESS', $afterSecond->getState());
        self::assertSame(2, $afterSecond->getPollAttempts());
        self::assertNotNull($afterSecond->getFirstNonPendingAt());
        self::assertSame($secondNow->format('Y-m-d H:i:s'), $afterSecond->getFirstNonPendingAt()->format('Y-m-d H:i:s'));

        // Third iteration — firstNonPendingAt уже установлен → COALESCE обязан оставить исходный.
        $thirdNow = new \DateTimeImmutable('2026-04-01 10:00:10');
        $this->repository->updateState(self::COMPANY_ID, 'uuid-upd-1', 'IN_PROGRESS', $thirdNow, 3, $thirdNow);

        $this->em->clear();
        $afterThird = $this->repository->findByOzonUuid(self::COMPANY_ID, 'uuid-upd-1');
        self::assertNotNull($afterThird);
        self::assertSame(3, $afterThird->getPollAttempts());
        self::assertNotNull($afterThird->getFirstNonPendingAt());
        self::assertSame(
            $secondNow->format('Y-m-d H:i:s'),
            $afterThird->getFirstNonPendingAt()->format('Y-m-d H:i:s'),
            'COALESCE в updateState() не должен перезаписывать уже зафиксированный firstNonPendingAt',
        );
    }

    public function testUpdateStateReturnsZeroForUnknownUuid(): void
    {
        $this->seedCompany();
        $this->em->flush();

        $rows = $this->repository->updateState(
            self::COMPANY_ID,
            'no-such-uuid',
            'IN_PROGRESS',
            new \DateTimeImmutable('2026-04-01 10:00:00'),
            1,
        );

        self::assertSame(0, $rows);
    }

    public function testMarkFinalizedSetsTerminalStateAndTimestamp(): void
    {
        $this->seedCompany();
        $this->em->flush();

        $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-fin-ok',
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-03'),
            campaignIds: ['111'],
            jobId: null,
        );

        $rows = $this->repository->markFinalized(self::COMPANY_ID, 'uuid-fin-ok', OzonAdPendingReportState::OK);
        self::assertSame(1, $rows);

        $this->em->clear();
        $finalized = $this->repository->findByOzonUuid(self::COMPANY_ID, 'uuid-fin-ok');
        self::assertNotNull($finalized);
        self::assertSame(OzonAdPendingReportState::OK, $finalized->getState());
        self::assertNotNull($finalized->getFinalizedAt());
        self::assertNull($finalized->getErrorMessage());
    }

    public function testMarkFinalizedStoresErrorMessage(): void
    {
        $this->seedCompany();
        $this->em->flush();

        $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-fin-err',
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-03'),
            campaignIds: ['111'],
            jobId: null,
        );

        $this->repository->markFinalized(
            self::COMPANY_ID,
            'uuid-fin-err',
            OzonAdPendingReportState::ERROR,
            'Ozon API вернул ERROR: нет прав',
        );

        $this->em->clear();
        $finalized = $this->repository->findByOzonUuid(self::COMPANY_ID, 'uuid-fin-err');
        self::assertNotNull($finalized);
        self::assertSame(OzonAdPendingReportState::ERROR, $finalized->getState());
        self::assertSame('Ozon API вернул ERROR: нет прав', $finalized->getErrorMessage());
    }

    public function testMarkFinalizedIsIdempotent(): void
    {
        $this->seedCompany();
        $this->em->flush();

        $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-fin-idem',
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-03'),
            campaignIds: ['111'],
            jobId: null,
        );

        $first = $this->repository->markFinalized(self::COMPANY_ID, 'uuid-fin-idem', OzonAdPendingReportState::ERROR, 'boom');
        $second = $this->repository->markFinalized(self::COMPANY_ID, 'uuid-fin-idem', OzonAdPendingReportState::OK, 'should not overwrite');

        self::assertSame(1, $first, 'Первая финализация обязана обновить строку');
        self::assertSame(0, $second, 'Вторая финализация не должна затронуть ни одну строку');

        $this->em->clear();
        $finalized = $this->repository->findByOzonUuid(self::COMPANY_ID, 'uuid-fin-idem');
        self::assertNotNull($finalized);
        self::assertSame(OzonAdPendingReportState::ERROR, $finalized->getState());
        self::assertSame('boom', $finalized->getErrorMessage());
    }

    public function testMarkFinalizedRejectsNonTerminalState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/только терминальные state/');

        $this->repository->markFinalized(self::COMPANY_ID, 'any-uuid', OzonAdPendingReportState::IN_PROGRESS);
    }

    public function testFindInFlightByJobReturnsOnlyInFlightRecords(): void
    {
        $job = $this->seedJob();
        $otherJob = $this->seedJob(index: 2);

        // In-flight на нашем job'е: 2 записи (REQUESTED + IN_PROGRESS).
        $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-if-1',
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-03'),
            campaignIds: ['111'],
            jobId: $job->getId(),
        );
        $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-if-2',
            dateFrom: new \DateTimeImmutable('2026-03-04'),
            dateTo: new \DateTimeImmutable('2026-03-06'),
            campaignIds: ['222'],
            jobId: $job->getId(),
        );
        $this->repository->updateState(
            self::COMPANY_ID,
            'uuid-if-2',
            'IN_PROGRESS',
            new \DateTimeImmutable('2026-04-01 10:00:00'),
            1,
            new \DateTimeImmutable('2026-04-01 10:00:00'),
        );

        // Финализированная на нашем job'е — не должна попадать в выборку.
        $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-done',
            dateFrom: new \DateTimeImmutable('2026-03-07'),
            dateTo: new \DateTimeImmutable('2026-03-09'),
            campaignIds: ['333'],
            jobId: $job->getId(),
        );
        $this->repository->markFinalized(self::COMPANY_ID, 'uuid-done', OzonAdPendingReportState::OK);

        // На другом job'е — тоже in-flight, но из другого job'а → не должна попасть.
        $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-other',
            dateFrom: new \DateTimeImmutable('2026-03-10'),
            dateTo: new \DateTimeImmutable('2026-03-12'),
            campaignIds: ['444'],
            jobId: $otherJob->getId(),
        );

        $this->em->clear();
        $inFlight = $this->repository->findInFlightByJob(self::COMPANY_ID, $job->getId());

        self::assertCount(2, $inFlight);
        $uuids = array_map(static fn (OzonAdPendingReport $r): string => $r->getOzonUuid(), $inFlight);
        sort($uuids);
        self::assertSame(['uuid-if-1', 'uuid-if-2'], $uuids);
    }

    public function testUpdateStateIgnoresRowOfForeignCompany(): void
    {
        // Запись создана под self::COMPANY_ID — любая попытка обновить её под
        // другой company должна вернуть 0 строк и оставить state нетронутым.
        // Это guard от IDOR: ozon_uuid уникален, но WHERE-clause дополнительно
        // проверяет принадлежность к company.
        $this->seedCompany();
        $this->em->flush();

        $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-foreign',
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-03'),
            campaignIds: ['111'],
            jobId: null,
        );

        $foreignCompanyId = '99999999-9999-9999-9999-999999999999';

        $updated = $this->repository->updateState(
            $foreignCompanyId,
            'uuid-foreign',
            'IN_PROGRESS',
            new \DateTimeImmutable('2026-04-01 10:00:00'),
            1,
            new \DateTimeImmutable('2026-04-01 10:00:00'),
        );
        self::assertSame(0, $updated, 'updateState не должен трогать строку чужой company');

        $finalized = $this->repository->markFinalized(
            $foreignCompanyId,
            'uuid-foreign',
            OzonAdPendingReportState::ERROR,
            'foreign write',
        );
        self::assertSame(0, $finalized, 'markFinalized не должен трогать строку чужой company');

        $this->em->clear();

        // С корректной company — запись остаётся в REQUESTED, не финализирована.
        $owned = $this->repository->findByOzonUuid(self::COMPANY_ID, 'uuid-foreign');
        self::assertNotNull($owned);
        self::assertSame(OzonAdPendingReportState::REQUESTED, $owned->getState());
        self::assertNull($owned->getFinalizedAt());
        self::assertNull($owned->getErrorMessage());

        // С чужой company — findByOzonUuid обязан вернуть null.
        $foreign = $this->repository->findByOzonUuid($foreignCompanyId, 'uuid-foreign');
        self::assertNull($foreign, 'findByOzonUuid не должен возвращать строку чужой company');
    }

    public function testGetPollStartTimeReturnsRequestedAtTimestamp(): void
    {
        $this->seedCompany();
        $this->em->flush();

        $before = microtime(true);
        $entity = $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-poll-start',
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-03'),
            campaignIds: ['111'],
            jobId: null,
        );
        $after = microtime(true);

        $ts = $this->repository->getPollStartTime($entity);

        self::assertNotNull($ts);
        // Допуск в 1 секунду — Entity использует new DateTimeImmutable() без микросекунд,
        // Unix-timestamp может оказаться чуть раньше $before при округлении.
        self::assertGreaterThanOrEqual((float) ((int) $before) - 1, $ts);
        self::assertLessThanOrEqual($after + 1, $ts);
        self::assertSame((float) $entity->getRequestedAt()->getTimestamp(), $ts);
    }

    public function testUniqueConstraintOnOzonUuid(): void
    {
        $this->seedCompany();
        $this->em->flush();

        $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-dup',
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-03'),
            campaignIds: ['111'],
            jobId: null,
        );

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->repository->create(
            companyId: self::COMPANY_ID,
            ozonUuid: 'uuid-dup',
            dateFrom: new \DateTimeImmutable('2026-03-01'),
            dateTo: new \DateTimeImmutable('2026-03-03'),
            campaignIds: ['222'],
            jobId: null,
        );
    }

    private function seedJob(int $index = 1): AdLoadJob
    {
        $this->seedCompany();
        $this->em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex($index)
            ->build();

        $this->jobRepository->save($job);
        $this->em->flush();

        return $job;
    }

    private function seedCompany(): Company
    {
        $existing = $this->em->getRepository(Company::class)->find(self::COMPANY_ID);
        if (null !== $existing) {
            return $existing;
        }

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('owner@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }
}

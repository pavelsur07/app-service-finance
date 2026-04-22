<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Repository;

use App\Company\Entity\Company;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Integration-тесты poll-cron половины {@see OzonAdPendingReportRepository}:
 * findCompanyIdsWithDueReports, updateSchedule.
 *
 * Покрываются:
 *  - пустой results на чистой БД;
 *  - фильтрация по finalized_at IS NULL + (next_poll_at IS NULL OR <= now);
 *  - DISTINCT по company_id;
 *  - исключение company, у которой ВСЕ in-flight next_poll_at > now;
 *  - updateSchedule: обновляет 4 поля, не трогает state/error/finalized_at;
 *  - updateSchedule: идемпотентно пропускает финализированные записи.
 */
final class OzonAdPendingReportRepositoryPollTest extends IntegrationTestCase
{
    private OzonAdPendingReportRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = self::getContainer()->get(OzonAdPendingReportRepository::class);
    }

    public function testFindCompanyIdsWithDueReportsReturnsEmptyWhenNoInFlight(): void
    {
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');

        $ids = $this->repo->findCompanyIdsWithDueReports($now);

        self::assertSame([], $ids);
    }

    public function testFindCompanyIdsWithDueReportsFiltersByFinalizedAndNextPoll(): void
    {
        $companyA = $this->seedCompany()->getId();
        $companyB = $this->seedCompany()->getId();
        $companyC = $this->seedCompany()->getId();
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');

        // A: in-flight, next_poll_at <= now → due
        $this->persistReport($companyA, nextPollAt: $now->modify('-30 seconds'), finalizedAt: null);
        // B: in-flight, next_poll_at IS NULL → due (legacy / fresh REQUESTED)
        $this->persistReport($companyB, nextPollAt: null, finalizedAt: null);
        // C: in-flight, next_poll_at > now → NOT due
        $this->persistReport($companyC, nextPollAt: $now->modify('+60 seconds'), finalizedAt: null);
        // A also has a finalized row — must be ignored, but companyA still due because of the first row
        $this->persistReport($companyA, nextPollAt: null, finalizedAt: $now->modify('-1 hour'));

        $this->em->flush();

        $ids = $this->repo->findCompanyIdsWithDueReports($now);
        sort($ids);
        $expected = [$companyA, $companyB];
        sort($expected);

        self::assertSame($expected, $ids);
    }

    public function testFindCompanyIdsWithDueReportsDeduplicates(): void
    {
        $companyA = $this->seedCompany()->getId();
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');

        // Three in-flight due rows for same company.
        $this->persistReport($companyA, nextPollAt: null, finalizedAt: null);
        $this->persistReport($companyA, nextPollAt: $now->modify('-10 seconds'), finalizedAt: null);
        $this->persistReport($companyA, nextPollAt: $now->modify('-1 hour'), finalizedAt: null);
        $this->em->flush();

        $ids = $this->repo->findCompanyIdsWithDueReports($now);

        self::assertSame([$companyA], $ids);
    }

    public function testFindCompanyIdsWithDueReportsExcludesCompanyWithAllFutureSchedule(): void
    {
        $companyA = $this->seedCompany()->getId();
        $now = new \DateTimeImmutable('2026-04-22 12:00:00');

        // All in-flight rows have next_poll_at > now.
        $this->persistReport($companyA, nextPollAt: $now->modify('+60 seconds'), finalizedAt: null);
        $this->persistReport($companyA, nextPollAt: $now->modify('+300 seconds'), finalizedAt: null);
        $this->em->flush();

        $ids = $this->repo->findCompanyIdsWithDueReports($now);

        self::assertSame([], $ids);
    }

    public function testUpdateScheduleUpdatesSchedulingFieldsOnly(): void
    {
        $companyA = $this->seedCompany()->getId();
        $uuid = 'uuid-sched-1';
        $requestedAt = new \DateTimeImmutable('2026-04-22 11:00:00');
        $initialState = OzonAdPendingReportState::NOT_STARTED;
        $initialErr = 'pre-existing error message';

        $this->persistReport(
            $companyA,
            nextPollAt: null,
            finalizedAt: null,
            ozonUuid: $uuid,
            requestedAt: $requestedAt,
            state: $initialState,
            errorMessage: $initialErr,
        );
        $this->em->flush();
        $this->em->clear();

        $checkedAt = new \DateTimeImmutable('2026-04-22 12:00:00');
        $nextPoll = new \DateTimeImmutable('2026-04-22 12:00:30');

        $rows = $this->repo->updateSchedule($companyA, $uuid, $checkedAt, $nextPoll, 7);
        self::assertSame(1, $rows);

        $refreshed = $this->repo->findByOzonUuid($companyA, $uuid);
        self::assertNotNull($refreshed);

        // Scheduling fields updated.
        self::assertNotNull($refreshed->getLastCheckedAt());
        self::assertSame($checkedAt->format('Y-m-d H:i:s'), $refreshed->getLastCheckedAt()->format('Y-m-d H:i:s'));
        self::assertSame(7, $refreshed->getPollAttempts());

        // State / errorMessage / finalizedAt untouched.
        self::assertSame($initialState, $refreshed->getState());
        self::assertSame($initialErr, $refreshed->getErrorMessage());
        self::assertNull($refreshed->getFinalizedAt());
    }

    public function testUpdateScheduleIsNoOpOnFinalizedRow(): void
    {
        $companyA = $this->seedCompany()->getId();
        $uuid = 'uuid-sched-fin';
        $finalizedAt = new \DateTimeImmutable('2026-04-22 11:55:00');

        $this->persistReport(
            $companyA,
            nextPollAt: null,
            finalizedAt: $finalizedAt,
            ozonUuid: $uuid,
            state: OzonAdPendingReportState::OK,
        );
        $this->em->flush();
        $this->em->clear();

        $rows = $this->repo->updateSchedule(
            $companyA,
            $uuid,
            new \DateTimeImmutable('2026-04-22 12:00:00'),
            new \DateTimeImmutable('2026-04-22 12:01:00'),
            99,
        );

        self::assertSame(0, $rows, 'updateSchedule must not touch finalized rows');

        $refreshed = $this->repo->findByOzonUuid($companyA, $uuid);
        self::assertNotNull($refreshed);
        self::assertNotNull($refreshed->getFinalizedAt());
        self::assertSame(0, $refreshed->getPollAttempts(), 'poll_attempts left untouched on finalized row');
    }

    public function testUpdateScheduleIsNoOpOnForeignCompany(): void
    {
        $companyA = $this->seedCompany()->getId();
        $uuid = 'uuid-sched-foreign';
        $this->persistReport($companyA, nextPollAt: null, finalizedAt: null, ozonUuid: $uuid);
        $this->em->flush();
        $this->em->clear();

        $foreignCompanyId = Uuid::uuid7()->toString();

        $rows = $this->repo->updateSchedule(
            $foreignCompanyId,
            $uuid,
            new \DateTimeImmutable('2026-04-22 12:00:00'),
            new \DateTimeImmutable('2026-04-22 12:01:00'),
            3,
        );

        self::assertSame(0, $rows, 'updateSchedule must not cross company boundary');

        $refreshed = $this->repo->findByOzonUuid($companyA, $uuid);
        self::assertNotNull($refreshed);
        self::assertSame(0, $refreshed->getPollAttempts());
    }

    private function persistReport(
        string $companyId,
        ?\DateTimeImmutable $nextPollAt,
        ?\DateTimeImmutable $finalizedAt,
        string $ozonUuid = '',
        ?\DateTimeImmutable $requestedAt = null,
        ?string $state = null,
        ?string $errorMessage = null,
    ): OzonAdPendingReport {
        if ('' === $ozonUuid) {
            $ozonUuid = Uuid::uuid7()->toString();
        }

        $report = new OzonAdPendingReport(
            companyId: $companyId,
            ozonUuid: $ozonUuid,
            dateFrom: new \DateTimeImmutable('2026-04-01'),
            dateTo: new \DateTimeImmutable('2026-04-01'),
            campaignIds: ['1'],
            jobId: null,
        );

        $ref = new \ReflectionClass($report);

        if (null !== $requestedAt) {
            $p = $ref->getProperty('requestedAt');
            $p->setAccessible(true);
            $p->setValue($report, $requestedAt);
        }

        if (null !== $nextPollAt) {
            $p = $ref->getProperty('nextPollAt');
            $p->setAccessible(true);
            $p->setValue($report, $nextPollAt);
        }

        if (null !== $finalizedAt) {
            $p = $ref->getProperty('finalizedAt');
            $p->setAccessible(true);
            $p->setValue($report, $finalizedAt);
        }

        if (null !== $state) {
            $p = $ref->getProperty('state');
            $p->setAccessible(true);
            $p->setValue($report, $state);
        }

        if (null !== $errorMessage) {
            $p = $ref->getProperty('errorMessage');
            $p->setAccessible(true);
            $p->setValue($report, $errorMessage);
        }

        $this->em->persist($report);

        return $report;
    }

    private function seedCompany(): Company
    {
        $companyId = Uuid::uuid7()->toString();
        $ownerId = Uuid::uuid7()->toString();

        // Use the random tail of UUID v7 (last 12 chars). The first 12 chars
        // are a millisecond timestamp — three seedCompany() calls within the
        // same test run collide on that prefix.
        $owner = UserBuilder::aUser()
            ->withId($ownerId)
            ->withEmail(sprintf('owner+%s@example.test', substr($ownerId, -12)))
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

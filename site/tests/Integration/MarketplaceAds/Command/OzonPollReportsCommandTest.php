<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Command;

use App\Company\Entity\Company;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end тесты {@see \App\MarketplaceAds\Command\OzonPollReportsCommand}:
 * boot kernel + реальный Postgres + mock'нутый {@see OzonAdClient}.
 *
 * Покрываются (v1.17 per-UUID polling):
 *  - чистый БД → SUCCESS, "No companies with due reports";
 *  - --dry-run не дёргает Ozon;
 *  - happy path: одна компания, один OK + один IN_PROGRESS → OK row
 *    в state=OK без finalize, IN_PROGRESS row в state=IN_PROGRESS с nextPollAt;
 *  - per-company isolation: pollOneReport бросает для компании A,
 *    компания B всё равно обрабатывается, exit=FAILURE.
 */
final class OzonPollReportsCommandTest extends IntegrationTestCase
{
    private OzonAdPendingReportRepository $repo;
    /** @var OzonAdClient&MockObject */
    private OzonAdClient $clientMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = self::getContainer()->get(OzonAdPendingReportRepository::class);

        $this->clientMock = $this->createMock(OzonAdClient::class);
        self::getContainer()->set(OzonAdClient::class, $this->clientMock);
    }

    public function testEmptyDbExitsSuccessWithMessage(): void
    {
        $tester = $this->makeCommandTester();
        $this->clientMock->expects(self::never())->method('pollOneReport');

        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No companies with due reports', $tester->getDisplay());
    }

    public function testDryRunDoesNotCallOzon(): void
    {
        $companyId = $this->seedCompany()->getId();
        $this->persistReport($companyId, nextPollAt: null, finalizedAt: null, ozonUuid: 'uuid-dry-1');
        $this->em->flush();

        $this->clientMock->expects(self::never())->method('pollOneReport');

        $tester = $this->makeCommandTester();
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('DRY', $display);
        self::assertStringContainsString($companyId, $display);
        self::assertStringContainsString('in_flight=1', $display);
    }

    public function testHappyPathOneCompanyMixedStates(): void
    {
        $companyId = $this->seedCompany()->getId();

        // Один готов (OK), один всё ещё формируется (IN_PROGRESS).
        $this->persistReport(
            $companyId,
            nextPollAt: null,
            finalizedAt: null,
            ozonUuid: 'uuid-ok',
            requestedAt: new \DateTimeImmutable('-5 minutes'),
        );
        $this->persistReport(
            $companyId,
            nextPollAt: null,
            finalizedAt: null,
            ozonUuid: 'uuid-inprogress',
            requestedAt: new \DateTimeImmutable('-1 minute'),
        );
        $this->em->flush();

        $this->clientMock
            ->method('pollOneReport')
            ->willReturnCallback(static function (string $cid, string $uuid): array {
                return match ($uuid) {
                    'uuid-ok' => ['state' => 'OK', 'raw' => ['UUID' => $uuid, 'state' => 'OK']],
                    'uuid-inprogress' => ['state' => 'IN_PROGRESS', 'raw' => ['UUID' => $uuid, 'state' => 'IN_PROGRESS']],
                    default => ['state' => 'UNKNOWN', 'raw' => []],
                };
            });

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);

        $this->em->clear();
        $okRow = $this->repo->findByOzonUuid($companyId, 'uuid-ok');
        self::assertNotNull($okRow);
        self::assertSame(OzonAdPendingReportState::OK, $okRow->getState());
        self::assertNull($okRow->getFinalizedAt(), 'OK row stays unfinalized — step 4 downloads and finalizes');
        self::assertNull($okRow->getNextPollAt(), 'next_poll_at cleared after OK');
        self::assertSame(1, $okRow->getPollAttempts());

        $ipRow = $this->repo->findByOzonUuid($companyId, 'uuid-inprogress');
        self::assertNotNull($ipRow);
        self::assertSame(OzonAdPendingReportState::IN_PROGRESS, $ipRow->getState());
        self::assertNotNull($ipRow->getNextPollAt(), 'IN_PROGRESS row rescheduled with backoff');
        self::assertSame(1, $ipRow->getPollAttempts());
        self::assertNull($ipRow->getFinalizedAt());
    }

    public function testPerCompanyIsolation(): void
    {
        $companyA = $this->seedCompany()->getId();
        $companyB = $this->seedCompany()->getId();
        $this->persistReport($companyA, nextPollAt: null, finalizedAt: null, ozonUuid: 'uuid-a-1');
        $this->persistReport($companyB, nextPollAt: null, finalizedAt: null, ozonUuid: 'uuid-b-1');
        $this->em->flush();

        // A: pollOneReport бросает → per-UUID exception изолирована (errors=1),
        //     но row НЕ финализируется (ждём следующий tick).
        // B: OK → state=OK без finalize (download отдельно).
        $this->clientMock
            ->method('pollOneReport')
            ->willReturnCallback(function (string $companyId, string $uuid) use ($companyA): array {
                if ($companyId === $companyA) {
                    throw new \RuntimeException('network blip for company A');
                }

                return ['state' => 'OK', 'raw' => ['UUID' => $uuid, 'state' => 'OK']];
            });

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([]);

        // errors > 0 (company A returned errors=1) → FAILURE
        self::assertSame(Command::FAILURE, $exit);

        $this->em->clear();

        $rowA = $this->repo->findByOzonUuid($companyA, 'uuid-a-1');
        self::assertNotNull($rowA);
        self::assertNull($rowA->getFinalizedAt(), 'company A per-UUID exception — row оставлен для следующего тика');

        $rowB = $this->repo->findByOzonUuid($companyB, 'uuid-b-1');
        self::assertNotNull($rowB);
        self::assertSame(OzonAdPendingReportState::OK, $rowB->getState());
        self::assertNull($rowB->getFinalizedAt(), 'company B processed independently, OK не финализирует');
    }

    private function makeCommandTester(): CommandTester
    {
        $app = new Application(self::$kernel);
        $command = $app->find('app:marketplace-ads:ozon-poll-reports');

        return new CommandTester($command);
    }

    private function seedCompany(): Company
    {
        $companyId = Uuid::uuid7()->toString();
        $ownerId = Uuid::uuid7()->toString();

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

    private function persistReport(
        string $companyId,
        ?\DateTimeImmutable $nextPollAt,
        ?\DateTimeImmutable $finalizedAt,
        string $ozonUuid,
        ?\DateTimeImmutable $requestedAt = null,
    ): OzonAdPendingReport {
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

        $this->em->persist($report);

        return $report;
    }
}

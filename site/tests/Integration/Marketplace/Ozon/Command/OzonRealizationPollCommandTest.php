<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Command;

use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\ProcessOzonRealizationMessage;
use App\Marketplace\Message\SyncOzonRealizationMessage;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationReport;
use App\Marketplace\Ozon\Command\OzonRealizationPollCommand;
use App\Marketplace\Ozon\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\ParameterType;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OzonRealizationPollCommandTest extends IntegrationTestCase
{
    private const IN_WINDOW = '2026-10-03 10:00:00 Europe/Moscow';

    /** @var \ArrayObject<int, object> */
    private \ArrayObject $bus;

    private MarketplaceFinancialReportSyncStatusRepository $statuses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statuses = self::getContainer()->get(MarketplaceFinancialReportSyncStatusRepository::class);
        $this->bus = new \ArrayObject();
    }

    public function testOutsideWindowDoesNothing(): void
    {
        $company = $this->seedCompanyWithConnection(1);

        $tester = $this->runCommand('2026-10-15 10:00:00 Europe/Moscow');

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('outside poll window', $tester->getDisplay());
        self::assertCount(0, $this->bus);
        self::assertNull($this->pairStatus($company));
    }

    public function testInWindowQueuesOneLoadPerCompanyForPreviousMonth(): void
    {
        $companyA = $this->seedCompanyWithConnection(1);
        $companyB = $this->seedCompanyWithConnection(2);

        $tester = $this->runCommand(self::IN_WINDOW);

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(2, $this->bus);
        $byCompany = [];
        foreach ($this->bus as $message) {
            self::assertInstanceOf(SyncOzonRealizationMessage::class, $message);
            self::assertSame(2026, $message->year);
            self::assertSame(9, $message->month);
            $byCompany[$message->companyId] = true;
        }
        $queuedCompanies = array_keys($byCompany);
        sort($queuedCompanies);
        self::assertSame([$companyA, $companyB], $queuedCompanies);
        self::assertSame(FinancialReportSyncMode::POLL, $this->pairStatus($companyA)?->getMode());
        self::assertStringContainsString('queued: 2', $tester->getDisplay());
    }

    public function testSecondRunInTheSameHourDoesNotQueueAgain(): void
    {
        $this->seedCompanyWithConnection(1);

        $this->runCommand(self::IN_WINDOW);
        $this->bus->exchangeArray([]);
        $this->alignStatusClock();
        $second = $this->runCommand(self::IN_WINDOW);

        // Пара в QUEUED без nextRetryAt свежая — повторно её не берём.
        self::assertCount(0, $this->bus);
        self::assertStringContainsString('queued: 0', $second->getDisplay());
    }

    public function testEmptyStatusWithDueRetryIsPolledAgainButFutureRetryIsNot(): void
    {
        $company = $this->seedCompanyWithConnection(1);
        $this->runCommand(self::IN_WINDOW);
        $this->bus->exchangeArray([]);

        $status = $this->pairStatus($company);
        self::assertNotNull($status);
        $status->markLoading(FinancialReportSyncMode::POLL);
        $status->markEmpty();
        $status->scheduleNextRetryAt(new \DateTimeImmutable('2026-10-03 11:00:00 Europe/Moscow'));
        $this->statuses->save($status);
        $this->em->flush();

        // 10:30 — повтор в 11:00 ещё не наступил.
        $this->runCommand('2026-10-03 10:30:00 Europe/Moscow');
        self::assertCount(0, $this->bus);

        // 11:05 — пора.
        $this->runCommand('2026-10-03 11:05:00 Europe/Moscow');
        self::assertCount(1, $this->bus);
    }

    public function testTerminalStatusesAreSkipped(): void
    {
        $company = $this->seedCompanyWithConnection(1);
        $this->runCommand(self::IN_WINDOW);
        $this->bus->exchangeArray([]);

        $status = $this->pairStatus($company);
        self::assertNotNull($status);
        $status->markSuccess();
        $this->statuses->save($status);
        $this->em->flush();

        $tester = $this->runCommand('2026-10-04 10:00:00 Europe/Moscow');

        self::assertCount(0, $this->bus);
        self::assertStringContainsString('SKIP company '.$company.': success', $tester->getDisplay());
    }

    public function testInactiveConnectionAndOtherCompaniesAreIgnored(): void
    {
        $active = $this->seedCompanyWithConnection(1);
        $inactive = $this->seedCompanyWithConnection(2, false);

        $this->runCommand(self::IN_WINDOW);

        self::assertCount(1, $this->bus, 'неактивная компания не берётся');
        self::assertInstanceOf(SyncOzonRealizationMessage::class, $this->bus[0]);
        self::assertSame($active, $this->bus[0]->companyId);
        self::assertNull($this->pairStatus($inactive));

        // Выбор одной компании: чужие не трогаются.
        $other = $this->seedCompanyWithConnection(3);
        $this->bus->exchangeArray([]);
        $this->runCommand(self::IN_WINDOW, ['--company-id' => $other]);
        self::assertCount(1, $this->bus);
        self::assertInstanceOf(SyncOzonRealizationMessage::class, $this->bus[0]);
        self::assertSame($other, $this->bus[0]->companyId);
    }

    public function testStuckLoadedReportGetsProcessingRequeuedButFreshOneDoesNot(): void
    {
        $company = $this->seedCompanyWithConnection(1);
        $connectionId = (string) $this->connection->fetchOne('SELECT id FROM marketplace_connections WHERE company_id = :c', ['c' => $company]);
        $rawDocId = Uuid::uuid4()->toString();
        $status = $this->statuses->findOrCreateForDay($connectionId, $company, MarketplaceType::OZON, OzonRealizationReport::REPORT_TYPE, OzonRealizationReport::apiEndpoint(), new \DateTimeImmutable('2026-09-01'));
        $status->markRawLoaded($rawDocId, 5, 'hash');
        $this->statuses->save($status);
        $this->em->flush();

        // Обновлена только что: обработка ещё идёт, ничего не ставим.
        $this->connection->executeStatement("UPDATE marketplace_financial_report_sync_statuses SET updated_at = '2026-10-03 09:45:00'");
        $this->em->clear();
        $fresh = $this->runCommand(self::IN_WINDOW);
        self::assertCount(0, $this->bus);
        self::assertStringContainsString('raw_loaded in progress', $fresh->getDisplay());

        // Залипла на час и больше: ставим именно обработку, а не загрузку.
        $this->connection->executeStatement("UPDATE marketplace_financial_report_sync_statuses SET updated_at = '2026-10-03 08:30:00'");
        $this->em->clear();
        $stuck = $this->runCommand(self::IN_WINDOW);

        self::assertCount(1, $this->bus);
        $message = $this->bus[0];
        self::assertInstanceOf(ProcessOzonRealizationMessage::class, $message);
        self::assertSame($rawDocId, $message->rawDocumentId);
        self::assertSame(9, $message->month);
        self::assertStringContainsString('processing re-queued: 1', $stuck->getDisplay());
    }

    public function testDryRunWritesAndDispatchesNothing(): void
    {
        $company = $this->seedCompanyWithConnection(1);

        $tester = $this->runCommand(self::IN_WINDOW, ['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('WOULD QUEUE company '.$company, $tester->getDisplay());
        self::assertCount(0, $this->bus);
        self::assertNull($this->pairStatus($company));
    }

    public function testCompanyIdOptionIsValidated(): void
    {
        $tester = $this->runCommand(self::IN_WINDOW, ['--company-id' => 'nope']);

        self::assertSame(2, $tester->getStatusCode());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(string $now, array $options = []): CommandTester
    {
        $bus = new class($this->bus) implements MessageBusInterface {
            /** @param \ArrayObject<int, object> $dispatched */
            public function __construct(private readonly \ArrayObject $dispatched)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched->append($message);

                return new Envelope($message);
            }
        };

        $tester = new CommandTester(new OzonRealizationPollCommand(
            new ActiveOzonConnectionsQuery($this->connection),
            $this->statuses,
            $bus,
            new MockClock($now),
            new NullLogger(),
        ));
        $tester->execute($options);

        return $tester;
    }

    /**
     * Метки времени статуса ставятся настоящими часами, а команда идёт по MockClock: выравниваем, чтобы свежая постановка не выглядела «залипшей».
     */
    private function alignStatusClock(): void
    {
        $this->connection->executeStatement("UPDATE marketplace_financial_report_sync_statuses SET updated_at = '2026-10-03 10:00:00'");
        $this->em->clear();
    }

    private function pairStatus(string $companyId): ?\App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus
    {
        $this->em->clear();

        return $this->statuses->findByBusinessDay($companyId, MarketplaceType::OZON, OzonRealizationReport::REPORT_TYPE, new \DateTimeImmutable('2026-09-01'));
    }

    private function seedCompanyWithConnection(int $index, bool $active = true): string
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();
        $companyId = (string) $company->getId();
        $this->seedConnection($companyId, $active);

        return $companyId;
    }

    private function seedConnection(string $companyId, bool $active): void
    {
        $this->connection->insert('marketplace_connections', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $companyId, 'marketplace' => 'ozon', 'api_key' => 'x', 'is_active' => $active,
            'connection_type' => 'seller', 'auth_status' => 'ok', 'auth_failure_count' => 0, 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
        ], ['is_active' => ParameterType::BOOLEAN]);
    }
}

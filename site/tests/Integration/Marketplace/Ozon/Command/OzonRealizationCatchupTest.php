<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Command;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\ProcessOzonRealizationMessage;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationReport;
use App\Marketplace\Ozon\Command\OzonRealizationCatchupCommand;
use App\Marketplace\Ozon\Command\OzonRealizationUnappliedCheckCommand;
use App\Marketplace\Ozon\Infrastructure\Query\OzonUnappliedRealizationQuery;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\ParameterType;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OzonRealizationCatchupTest extends IntegrationTestCase
{
    /** Октябрь: окно «3 месяца» — июль, август, сентябрь. */
    private const NOW = '2026-10-04 09:00:00 Europe/Moscow';

    /** @var \ArrayObject<int, object> */
    private \ArrayObject $bus;

    /** @var \ArrayObject<int, array{level: string, message: string, context: array<mixed>}> */
    private \ArrayObject $logs;

    private MarketplaceFinancialReportSyncStatusRepository $statuses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bus = new \ArrayObject();
        $this->logs = new \ArrayObject();
        $this->statuses = self::getContainer()->get(MarketplaceFinancialReportSyncStatusRepository::class);
    }

    public function testQueuesOnlyLoadedUnappliedDocumentsOfTheWindowNewestFirst(): void
    {
        $company = $this->seedCompany(1);
        $sept = $this->seedDocument($company, '2026-09-01', 100, 0);
        $aug = $this->seedDocument($company, '2026-08-01', 100, 0);
        $jul = $this->seedDocument($company, '2026-07-01', 100, 0);
        $this->seedDocument($company, '2026-06-01', 100, 0);   // старше окна
        $this->seedDocument($company, '2026-10-01', 100, 0);   // текущий месяц ещё не закрыт
        $applied = $this->seedDocument($company, '2026-05-01', 100, 100);

        $tester = $this->runCatchup();

        self::assertSame(0, $tester->getStatusCode());
        $messages = $this->bus->getArrayCopy();
        self::assertCount(3, $messages);
        self::assertContainsOnlyInstancesOf(ProcessOzonRealizationMessage::class, $messages);
        self::assertSame([$sept, $aug, $jul], array_map(static fn (ProcessOzonRealizationMessage $m): string => $m->rawDocumentId, $messages));
        self::assertSame([9, 8, 7], array_map(static fn (ProcessOzonRealizationMessage $m): int => $m->month, $messages));
        self::assertNotContains($applied, array_map(static fn (ProcessOzonRealizationMessage $m): string => $m->rawDocumentId, $messages));
        self::assertStringContainsString('queued count: 3', $tester->getDisplay());
    }

    public function testSkipsAppliedTerminalEmptyAndForeignOrInactiveDocuments(): void
    {
        $company = $this->seedCompany(1);
        $success = $this->seedDocument($company, '2026-09-01', 100, 0);
        $conflict = $this->seedDocument($company, '2026-08-01', 100, 0);
        $this->seedPair($company, '2026-09-01', 'success');
        $this->seedPair($company, '2026-08-01', 'conflict');
        $noRows = $this->seedCompany(2);
        $this->seedDocument($noRows, '2026-09-01', 0, 0);
        $inactive = $this->seedCompany(3, false);
        $this->seedDocument($inactive, '2026-09-01', 100, 0);

        $this->runCatchup();

        self::assertCount(0, $this->bus);
        self::assertNotSame($success, $conflict);
    }

    public function testFailedPairWaitsForRetryTimeAndStuckPairsAreRequeued(): void
    {
        $company = $this->seedCompany(1);
        $failed = $this->seedDocument($company, '2026-09-01', 100, 0);
        $this->seedPair($company, '2026-09-01', 'failed', nextRetry: '2026-10-04 12:00:00');
        $stuck = $this->seedDocument($company, '2026-08-01', 100, 0);
        $this->seedPair($company, '2026-08-01', 'processing', updatedAt: '2026-10-04 07:00:00');
        $fresh = $this->seedDocument($company, '2026-07-01', 100, 0);
        $this->seedPair($company, '2026-07-01', 'raw_loaded', updatedAt: '2026-10-04 08:45:00');

        $this->runCatchup();

        // Сентябрь ждёт срока повтора (12:00), июль обновлялся 15 минут назад; в очередь идёт только залипший август.
        self::assertSame([$stuck], array_map(static fn (ProcessOzonRealizationMessage $m): string => $m->rawDocumentId, $this->messages()));
        self::assertNotSame($failed, $fresh);

        $this->bus->exchangeArray([]);
        $this->connection->executeStatement("UPDATE marketplace_financial_report_sync_statuses SET next_retry_at = '2026-10-04 08:00:00' WHERE status = 'failed'");
        $this->runCatchup();
        self::assertContains($failed, array_map(static fn (ProcessOzonRealizationMessage $m): string => $m->rawDocumentId, $this->messages()));
    }

    public function testLimitDryRunAndOptionValidation(): void
    {
        $company = $this->seedCompany(1);
        $this->seedDocument($company, '2026-09-01', 100, 0);
        $this->seedDocument($company, '2026-08-01', 100, 0);
        $this->seedDocument($company, '2026-07-01', 100, 0);

        $this->runCatchup(['--limit' => '2']);
        self::assertCount(2, $this->bus);

        $this->bus->exchangeArray([]);
        $dry = $this->runCatchup(['--dry-run' => true]);
        self::assertCount(0, $this->bus);
        self::assertStringContainsString('WOULD QUEUE company '.$company, $dry->getDisplay());

        self::assertSame(2, $this->runCatchup(['--limit' => '0'])->getStatusCode());
        self::assertSame(2, $this->runCatchup(['--months-back' => '13'])->getStatusCode());
        self::assertSame(2, $this->runCatchup(['--months-back' => 'abc'])->getStatusCode());

        // Окно в один месяц: только сентябрь.
        $this->bus->exchangeArray([]);
        $this->runCatchup(['--months-back' => '1']);
        self::assertCount(1, $this->bus);
    }

    public function testGateIsRedOnlyForDocumentsLoadedMoreThanADayAgoAndIgnoresConflicts(): void
    {
        $company = $this->seedCompany(1);
        $old = $this->seedDocument($company, '2026-09-01', 100, 0, syncedAt: '2026-10-02 08:00:00');
        $this->seedDocument($company, '2026-08-01', 100, 0, syncedAt: '2026-10-04 06:00:00');   // загружена утром — ещё в пределах суток
        $conflict = $this->seedDocument($company, '2026-07-01', 100, 0, syncedAt: '2026-09-01 08:00:00');
        $this->seedPair($company, '2026-07-01', 'conflict');

        $tester = $this->runGate();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('UNAPPLIED company '.$company.' 2026-09 document '.$old, $tester->getDisplay());
        self::assertStringContainsString('unapplied count: 1', $tester->getDisplay());
        self::assertStringNotContainsString($conflict, $tester->getDisplay());
        $errors = array_values(array_filter($this->logs->getArrayCopy(), static fn (array $r): bool => 'error' === $r['level']));
        self::assertCount(1, $errors);
        self::assertSame(1, $errors[0]['context']['unapplied']);
        self::assertSame(['no_pair' => 1], $errors[0]['context']['states']);

        $this->logs->exchangeArray([]);
        self::assertSame(0, $this->runGate(['--report-only' => true])->getStatusCode());
        self::assertCount(0, $this->logs);
    }

    public function testGateIsGreenWhenEverythingIsApplied(): void
    {
        $company = $this->seedCompany(1);
        $this->seedDocument($company, '2026-09-01', 100, 100, syncedAt: '2026-10-01 08:00:00');
        $this->seedDocument($company, '2026-08-01', 100, 0, syncedAt: '2026-10-01 08:00:00');
        $this->seedPair($company, '2026-08-01', 'success');

        self::assertSame(0, $this->runGate()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCatchup(array $options = []): CommandTester
    {
        $tester = new CommandTester(new OzonRealizationCatchupCommand(
            new OzonUnappliedRealizationQuery($this->connection),
            $this->bus(),
            new MockClock(self::NOW),
            $this->logger(),
        ));
        $tester->execute($options);

        return $tester;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runGate(array $options = []): CommandTester
    {
        $tester = new CommandTester(new OzonRealizationUnappliedCheckCommand(
            new OzonUnappliedRealizationQuery($this->connection),
            new MockClock(self::NOW),
            $this->logger(),
        ));
        $tester->execute($options);

        return $tester;
    }

    /**
     * @return list<ProcessOzonRealizationMessage>
     */
    private function messages(): array
    {
        $messages = $this->bus->getArrayCopy();
        self::assertContainsOnlyInstancesOf(ProcessOzonRealizationMessage::class, $messages);

        return array_values($messages);
    }

    private function bus(): MessageBusInterface
    {
        return new class($this->bus) implements MessageBusInterface {
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
    }

    private function logger(): AbstractLogger
    {
        return new class($this->logs) extends AbstractLogger {
            /** @param \ArrayObject<int, array{level: string, message: string, context: array<mixed>}> $logs */
            public function __construct(private readonly \ArrayObject $logs)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs->append(['level' => (string) $level, 'message' => (string) $message, 'context' => $context]);
            }
        };
    }

    private function seedCompany(int $index, bool $active = true): string
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();
        $companyId = (string) $company->getId();
        $this->connection->insert('marketplace_connections', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $companyId, 'marketplace' => 'ozon', 'api_key' => 'x', 'is_active' => $active,
            'connection_type' => 'seller', 'auth_status' => 'ok', 'auth_failure_count' => 0, 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
        ], ['is_active' => ParameterType::BOOLEAN]);

        return $companyId;
    }

    private function seedDocument(string $companyId, string $periodFrom, int $records, int $created, string $syncedAt = '2026-10-01 08:00:00'): string
    {
        $company = $this->em->find(Company::class, $companyId);
        self::assertNotNull($company);
        $from = new \DateTimeImmutable($periodFrom);
        $doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('realization')->withPeriod($from, $from->modify('last day of this month'))->build();
        $doc->setApiEndpoint(MarketplaceRawFormat::OZON_REALIZATION_V2->value);
        $doc->setRecordsCount($records);
        $doc->setRecordsCreated($created);
        $this->em->persist($doc);
        $this->em->flush();
        $this->connection->executeStatement('UPDATE marketplace_raw_documents SET synced_at = :s WHERE id = :id', ['s' => $syncedAt, 'id' => (string) $doc->getId()]);

        return (string) $doc->getId();
    }

    private function seedPair(string $companyId, string $month, string $status, ?string $nextRetry = null, ?string $updatedAt = null): void
    {
        $connectionId = (string) $this->connection->fetchOne('SELECT id FROM marketplace_connections WHERE company_id = :c', ['c' => $companyId]);
        $pair = $this->statuses->findOrCreateForDay($connectionId, $companyId, MarketplaceType::OZON, OzonRealizationReport::REPORT_TYPE, OzonRealizationReport::apiEndpoint(), new \DateTimeImmutable($month));
        $this->statuses->save($pair);
        $this->em->flush();
        $this->connection->executeStatement(
            'UPDATE marketplace_financial_report_sync_statuses SET status = :status, next_retry_at = :retry, updated_at = COALESCE(:updated, updated_at) WHERE id = :id',
            ['status' => $status, 'retry' => $nextRetry, 'updated' => $updatedAt, 'id' => $pair->getId()],
        );
        $this->em->clear();
    }
}

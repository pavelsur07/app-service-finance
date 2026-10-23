<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Application\CloseLostCashFileImportJobsAction;
use App\Cash\Application\CloseOpenImportLogsAction;
use App\Cash\Application\RecalculateAllocatedAmountsAction;
use App\Cash\Command\CashIntegrityCheckCommand;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Infrastructure\Messenger\ImportQueueProbeInterface;
use App\Cash\Infrastructure\Query\CashIntegrityCompaniesQuery;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Ночной гейт целостности ДДС: чисто — exit 0 без `error`; потерянное задание импорта или
 * устаревший allocated_amount — один агрегированный `error` + exit 1; очередь недоступна —
 * отдельный `error`. Данные гейт не меняет.
 */
final class CashIntegrityCheckCommandTest extends IntegrationTestCase
{
    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new TestHandler();
    }

    public function testCleanDataPassesWithoutError(): void
    {
        $company = $this->company();
        $fresh = $this->job($company);
        $queued = $this->job($company);
        $this->transaction($company, '500.00');
        $this->em->flush();
        $this->age($queued, '-3 days');

        $tester = $this->runGate([(string) $queued->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('lost import jobs: 0', $tester->getDisplay());
        self::assertFalse($this->log->hasErrorRecords());
        self::assertSame(CashFileImportJob::STATUS_QUEUED, $this->jobStatus($fresh));
    }

    public function testLostJobAndStaleAllocatedGiveOneErrorAndChangeNothing(): void
    {
        $company = $this->company();
        $lost = $this->job($company);
        $stale = $this->transaction($company, '500.00');
        $this->em->flush();
        $this->age($lost, '-3 days');
        $this->connection->executeStatement(
            "UPDATE cash_transaction SET allocated_amount = '300.00' WHERE id = :id",
            ['id' => $stale->getId()],
        );

        $tester = $this->runGate();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(1, $this->log->getRecords());
        self::assertTrue($this->log->hasErrorThatContains('Cash integrity check failed'));
        $context = $this->log->getRecords()[0]->context;
        self::assertSame([(string) $company->getId() => 1], $context['lost_import_jobs_by_company']);
        self::assertSame([(string) $company->getId() => 1], $context['stale_allocated_amount_by_company']);
        self::assertSame(CashFileImportJob::STATUS_QUEUED, $this->jobStatus($lost));
        self::assertSame('300.00', (string) $this->connection->fetchOne(
            'SELECT allocated_amount FROM cash_transaction WHERE id = :id',
            ['id' => $stale->getId()],
        ));
    }

    public function testOpenImportLogFailsGateButLogOfLiveJobDoesNot(): void
    {
        $company = $this->company();
        $orphan = self::getContainer()->get(ImportLogger::class)->start($company, 'bank1c', false, null, 'a.txt');
        $queued = $this->job($company);
        $queued->setImportLog(self::getContainer()->get(ImportLogger::class)->start($company, 'cash:file', false, null, 'a.csv'));
        $this->em->flush();
        $this->connection->executeStatement(
            'UPDATE import_log SET started_at = :at WHERE company_id = :company',
            ['at' => (new \DateTimeImmutable('-3 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'company' => $company->getId()],
        );

        // Задание свежее и в очереди: его журнал не находка.
        $tester = $this->runGate();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('open import logs: 1', $tester->getDisplay());
        self::assertSame([(string) $company->getId() => 1], $this->log->getRecords()[0]->context['open_import_logs_by_company']);
        self::assertNull($this->connection->fetchOne('SELECT finished_at FROM import_log WHERE id = :id', ['id' => $orphan->getId()]));
    }

    public function testUnavailableQueueIsSeparateError(): void
    {
        $company = $this->company();
        $job = $this->job($company);
        $this->em->flush();
        $this->age($job, '-3 days');

        $tester = $this->runGate(null);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('UNAVAILABLE', $tester->getDisplay());
        self::assertCount(1, $this->log->getRecords());
        self::assertTrue($this->log->hasErrorThatContains('Cash integrity check unavailable'));
    }

    /**
     * @param list<string>|null $queuedInRedis ID заданий в живой очереди; null — Redis недоступен
     */
    private function runGate(?array $queuedInRedis = []): CommandTester
    {
        $probe = new class($queuedInRedis) implements ImportQueueProbeInterface {
            /** @param list<string>|null $queued */
            public function __construct(private readonly ?array $queued)
            {
            }

            public function findQueued(array $jobIds): array
            {
                if (null === $this->queued) {
                    throw new \RuntimeException('Redis очереди импорта недоступен.');
                }

                return array_values(array_intersect($jobIds, $this->queued));
            }
        };
        $container = self::getContainer();
        $command = new CashIntegrityCheckCommand(
            $container->get(CashIntegrityCompaniesQuery::class),
            new CloseLostCashFileImportJobsAction(
                $container->get(Connection::class),
                $container->get(EntityManagerInterface::class),
                $container->get(ImportLogger::class),
                $probe,
            ),
            $container->get(RecalculateAllocatedAmountsAction::class),
            $container->get(CloseOpenImportLogsAction::class),
            new Logger('test', [$this->log]),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private function company(): Company
    {
        $owner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(sprintf('user+%s@example.test', Uuid::uuid4()->toString()))
            ->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function account(Company $company): MoneyAccount
    {
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Счёт '.Uuid::uuid4()->toString())
            ->build();
        $this->em->persist($account);

        return $account;
    }

    private function job(Company $company): CashFileImportJob
    {
        $job = new CashFileImportJob(Uuid::uuid4()->toString(), $company, $this->account($company), 'cash:file', 'a.csv', 'hash', []);
        $this->em->persist($job);

        return $job;
    }

    private function age(CashFileImportJob $job, string $createdAt): void
    {
        $this->connection->executeStatement(
            'UPDATE cash_file_import_jobs SET created_at = :at WHERE id = :id',
            ['at' => (new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'), 'id' => $job->getId()],
        );
    }

    private function jobStatus(CashFileImportJob $job): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM cash_file_import_jobs WHERE id = :id', ['id' => $job->getId()]);
    }

    private function transaction(Company $company, string $amount): CashTransaction
    {
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withMoneyAccount($this->account($company))
            ->withAmount($amount)
            ->withDirection(CashDirection::OUTFLOW)
            ->build();
        $this->em->persist($transaction);

        return $transaction;
    }
}

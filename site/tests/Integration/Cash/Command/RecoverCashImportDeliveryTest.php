<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Application\DispatchCashFileImportJobAction;
use App\Cash\Command\Import\RecoverCashImportDeliveryCommand;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Infrastructure\Lock\CashImportDeliveryLock;
use App\Cash\Infrastructure\Messenger\ImportQueueProbeInterface;
use App\Cash\Infrastructure\Query\CashFileImportDeliveryQuery;
use App\Cash\Infrastructure\Query\CashIntegrityCompaniesQuery;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecoverCashImportDeliveryTest extends IntegrationTestCase
{
    public function testCommandIsRegistered(): void
    {
        self::assertTrue((new Application(self::$kernel ?? throw new \LogicException('Kernel not booted.')))->has('app:cash:import:recover-delivery'));
    }

    public function testPagesAdvanceThroughSkippedJobsAndQueueIsReadOnceAcrossCompanies(): void
    {
        $company = $this->company();
        $jobs = $this->jobs($company, 201);
        $foreign = $this->jobs($this->company(), 1);
        $present = array_slice($jobs, 0, 200);
        [$tester, $bus, $probe] = $this->command($present);
        $tester->execute(['--execute' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertEqualsCanonicalizing([$jobs[200], $foreign[0]], $bus->ids);
        self::assertSame(1, $probe->calls);
        self::assertCount(202, $probe->seen);
    }

    public function testDryRunAndUnavailableQueueNeverSend(): void
    {
        $this->jobs($this->company(), 1);
        [$tester, $bus] = $this->command();
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $bus->ids);
        [$tester, $bus] = $this->command(failProbe: true);
        $tester->execute(['--execute' => true]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame([], $bus->ids);
        self::assertStringNotContainsString('secret', $tester->getDisplay());
    }

    public function testOnlyOldQueuedWithoutFailedMessagesAreCandidatesAndStatusIsRechecked(): void
    {
        $company = $this->company();
        $ids = $this->jobs($company, 7);
        foreach ([1 => 'processing', 2 => 'done', 3 => 'failed'] as $i => $status) {
            $this->connection->update('cash_file_import_jobs', ['status' => $status], ['id' => $ids[$i]]);
        }
        $this->connection->update('cash_file_import_jobs', ['created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['id' => $ids[4]]);
        $this->connection->insert('messenger_messages', ['body' => $ids[5], 'headers' => '[]', 'queue_name' => 'failed', 'created_at' => '2026-01-01', 'available_at' => '2026-01-01']);
        [$tester, $bus] = $this->command(afterProbe: function () use ($ids): void {
            $this->connection->update('cash_file_import_jobs', ['status' => 'processing'], ['id' => $ids[6]]);
        });
        $tester->execute(['companyId' => $company->getId(), '--execute' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([$ids[0]], $bus->ids);
    }

    public function testFailedSendLeavesJobQueuedAndReportsFailure(): void
    {
        $ids = $this->jobs($this->company(), 1);
        [$tester, $bus] = $this->command(failSend: true);
        $tester->execute(['--execute' => true]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('queued', $this->connection->fetchOne('SELECT status FROM cash_file_import_jobs WHERE id = :id', ['id' => $ids[0]]));
        self::assertSame([], $bus->ids);
    }

    public function testDispatcherLeaseExcludesAnotherConnectionAndIsReleased(): void
    {
        $company = $this->company();
        $this->jobs($company, 1);
        $db = \Doctrine\DBAL\DriverManager::getConnection($this->connection->getParams());
        $lock = new CashImportDeliveryLock($db);
        self::assertTrue($lock->tryAcquire((string) $company->getId()));
        try {
            [$tester, $bus, $probe] = $this->command();
            $tester->execute(['companyId' => $company->getId(), '--execute' => true]);
            self::assertSame(0, $tester->getStatusCode());
            self::assertSame([], $bus->ids);
            self::assertSame(0, $probe->calls);
        } finally {
            $lock->release((string) $company->getId());
            $db->close();
        }
        [$tester, $bus] = $this->command();
        $tester->execute(['companyId' => $company->getId(), '--execute' => true]);
        self::assertCount(1, $bus->ids);
    }

    /** @param list<string> $present
     * @return array{CommandTester, object{ids: list<string>}, object{calls: int, seen: list<string>}}
     */
    private function command(array $present = [], bool $failProbe = false, ?\Closure $afterProbe = null, bool $failSend = false): array
    {
        $level = $this->connection->getTransactionNestingLevel();
        $bus = new class($failSend, $this->connection, $level) implements MessageBusInterface {
            /** @var list<string> */
            public array $ids = [];

            public function __construct(private bool $fail, private \Doctrine\DBAL\Connection $db, private int $level)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($this->level !== $this->db->getTransactionNestingLevel()) {
                    throw new \LogicException('A network operation holds a DB transaction.');
                }
                if ($this->fail) {
                    throw new \Symfony\Component\Messenger\Exception\TransportException('secret');
                }
                if (!$message instanceof \App\Cash\Message\Import\CashFileImportMessage) {
                    throw new \LogicException('Expected import message.');
                }
                $this->ids[] = $message->getJobId();

                return new Envelope($message);
            }
        };
        $probe = new class($present, $failProbe, $afterProbe) implements ImportQueueProbeInterface {
            public int $calls = 0;
            /** @var list<string> */
            public array $seen = [];

            /** @param list<string> $present */
            public function __construct(private array $present, private bool $fail, private ?\Closure $after)
            {
            }

            public function findQueued(array $jobIds): array
            {
                ++$this->calls;
                $this->seen = $jobIds;
                if ($this->fail) {
                    throw new \RuntimeException('secret DSN');
                }
                ($this->after ?? static function (): void {})();

                return array_values(array_intersect($this->present, $jobIds));
            }
        };
        $tester = new CommandTester(new RecoverCashImportDeliveryCommand(
            new CashIntegrityCompaniesQuery($this->connection), new CashFileImportDeliveryQuery($this->connection), new CashImportDeliveryLock($this->connection), $probe,
            new DispatchCashFileImportJobAction($bus, new NullLogger()), new NullLogger(),
        ));

        return [$tester, $bus, $probe];
    }

    private function company(): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    /** @return list<string> */
    private function jobs(Company $company, int $count): array
    {
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($company)->build();
        $this->em->persist($account);
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $id = Uuid::uuid7()->toString();
            $ids[] = $id;
            $this->em->persist(new CashFileImportJob($id, $company, $account, 'cash:file', 'a.csv', 'hash', ['date' => 'Date', 'amount' => 'Amount']));
        }
        $this->em->flush();
        $this->connection->executeStatement('UPDATE cash_file_import_jobs SET created_at = :age WHERE company_id = :company', ['age' => (new \DateTimeImmutable('-3 minutes'))->format('Y-m-d H:i:s'), 'company' => $company->getId()]);
        sort($ids);

        return $ids;
    }
}

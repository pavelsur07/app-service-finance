<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Application\CloseLostCashFileImportJobsAction;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Разовый ремонт данных этапа 4 cash-pl-idempotency: потерянные задания файлового импорта и
 * устаревший allocated_amount. Dry-run ничего не меняет, --execute сверяет число кандидатов,
 * повтор после ремонта — 0.
 */
final class CashRepairCommandsTest extends IntegrationTestCase
{
    public function testLostJobsDryRunChangesNothing(): void
    {
        $company = $this->company();
        $lost = $this->job($company);
        $this->em->flush();
        $this->age($lost, '-3 days');

        $tester = $this->runCommand('app:cash:close-lost-import-jobs', $company);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/candidates\s+1/', $tester->getDisplay());
        self::assertSame(CashFileImportJob::STATUS_QUEUED, $this->jobStatus($lost));
    }

    public function testLostJobsExecuteClosesOnlyLostJobsOfCompany(): void
    {
        $company = $this->company();
        $lost = $this->job($company);
        $fresh = $this->job($company);
        $retryable = $this->job($company);
        $foreign = $this->job($this->company());
        $this->em->flush();
        foreach ([$lost, $retryable, $foreign] as $job) {
            $this->age($job, '-3 days');
        }
        // Сообщение задания лежит в failed-транспорте: его можно повторить, задание не потеряно.
        $this->connection->insert('messenger_messages', [
            'body' => 's:5:"jobId";s:36:"'.$retryable->getId().'";',
            'headers' => '[]',
            'queue_name' => 'failed',
            'created_at' => '2026-02-08 00:00:00',
            'available_at' => '2026-02-08 00:00:00',
        ]);

        $tester = $this->runCommand('app:cash:close-lost-import-jobs', $company, ['--execute' => true, '--expected-count' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(CashFileImportJob::STATUS_FAILED, $this->jobStatus($lost));
        self::assertSame(CloseLostCashFileImportJobsAction::FAILURE_MESSAGE, $this->connection->fetchOne(
            'SELECT error_message FROM cash_file_import_jobs WHERE id = :id',
            ['id' => $lost->getId()],
        ));
        self::assertNotNull($this->connection->fetchOne(
            'SELECT l.finished_at FROM import_log l JOIN cash_file_import_jobs j ON j.import_log_id = l.id WHERE j.id = :id',
            ['id' => $lost->getId()],
        ), 'Журнал потерянного задания закрывается вместе с ним.');
        self::assertSame(CashFileImportJob::STATUS_QUEUED, $this->jobStatus($fresh));
        self::assertSame(CashFileImportJob::STATUS_QUEUED, $this->jobStatus($retryable));
        self::assertSame(CashFileImportJob::STATUS_QUEUED, $this->jobStatus($foreign));

        $repeat = $this->runCommand('app:cash:close-lost-import-jobs', $company);
        self::assertMatchesRegularExpression('/candidates\s+0/', $repeat->getDisplay());
    }

    public function testLostJobsExecuteWithStaleExpectedCountIsRejected(): void
    {
        $company = $this->company();
        $lost = $this->job($company);
        $this->em->flush();
        $this->age($lost, '-3 days');

        $tester = $this->runCommand('app:cash:close-lost-import-jobs', $company, ['--execute' => true, '--expected-count' => '2']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(CashFileImportJob::STATUS_QUEUED, $this->jobStatus($lost));

        $withoutCount = $this->runCommand('app:cash:close-lost-import-jobs', $company, ['--execute' => true]);
        self::assertSame(Command::INVALID, $withoutCount->getStatusCode());
    }

    public function testAllocatedDryRunChangesNothing(): void
    {
        $company = $this->company();
        $stale = $this->transaction($company, '1000.00');
        $this->em->flush();
        $this->setAllocated($stale, '700.00');

        $tester = $this->runCommand('app:cash:recalculate-allocated-amounts', $company);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/candidates\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/stored_allocated_sum\s+700\.00/', $tester->getDisplay());
        self::assertSame('700.00', $this->allocated($stale));
    }

    public function testAllocatedExecuteRecalculatesFromLiveDocuments(): void
    {
        $company = $this->company();
        $withoutDocuments = $this->transaction($company, '1000.00');
        $withDeletedDocument = $this->transaction($company, '1000.00');
        $consistent = $this->transaction($company, '1000.00');
        $foreignCompany = $this->company();
        $foreign = $this->transaction($foreignCompany, '1000.00');
        $live = $this->document($company, '300.00');
        $withDeletedDocument->addDocument($live);
        $deleted = $this->document($company, '200.00');
        $withDeletedDocument->addDocument($deleted);
        $consistent->addDocument($this->document($company, '400.00'));
        $this->em->flush();
        $this->connection->executeStatement('UPDATE documents SET deleted_at = NOW() WHERE id = :id', ['id' => $deleted->getId()]);
        $this->setAllocated($withoutDocuments, '700.00');
        $this->setAllocated($foreign, '700.00');
        $this->em->clear();

        $tester = $this->runCommand('app:cash:recalculate-allocated-amounts', $company, ['--execute' => true, '--expected-count' => '2']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('0.00', $this->allocated($withoutDocuments));
        self::assertSame('300.00', $this->allocated($withDeletedDocument));
        self::assertSame('400.00', $this->allocated($consistent));
        self::assertSame('700.00', $this->allocated($foreign));

        $repeat = $this->runCommand('app:cash:recalculate-allocated-amounts', $company);
        self::assertMatchesRegularExpression('/candidates\s+0/', $repeat->getDisplay());
    }

    public function testAllocatedExecuteWithStaleExpectedCountIsRejected(): void
    {
        $company = $this->company();
        $stale = $this->transaction($company, '1000.00');
        $this->em->flush();
        $this->setAllocated($stale, '700.00');

        $tester = $this->runCommand('app:cash:recalculate-allocated-amounts', $company, ['--execute' => true, '--expected-count' => '3']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('700.00', $this->allocated($stale));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(string $name, Company $company, array $options = []): CommandTester
    {
        \assert(null !== self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find($name));
        $tester->execute(['--company' => (string) $company->getId()] + $options);

        return $tester;
    }

    private function company(): Company
    {
        $owner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(sprintf('user+%s@example.test', Uuid::uuid4()->toString()))
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($owner)
            ->build();
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
        $job->setImportLog(self::getContainer()->get(ImportLogger::class)->start($company, 'cash:file', false, null, 'a.csv'));
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

    private function document(Company $company, string $amount): Document
    {
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Основной');
        $category = (new PLCategory(Uuid::uuid4()->toString(), $company))
            ->setName('Расходы')
            ->setFlow(PLFlow::EXPENSE);
        $this->em->persist($project);
        $this->em->persist($category);

        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setStatus(DocumentStatus::ACTIVE);
        $document->setDate(new \DateTimeImmutable('2026-03-05'));
        $document->setType(DocumentType::OTHER);
        $document->setProjectDirection($project);

        $operation = new DocumentOperation();
        $operation->setPlCategory($category);
        $operation->setProjectDirection($project);
        $operation->setAmount($amount);
        $document->addOperation($operation);
        $this->em->persist($document);

        return $document;
    }

    private function setAllocated(CashTransaction $transaction, string $amount): void
    {
        $this->connection->executeStatement(
            'UPDATE cash_transaction SET allocated_amount = :amount WHERE id = :id',
            ['amount' => $amount, 'id' => $transaction->getId()],
        );
    }

    private function allocated(CashTransaction $transaction): string
    {
        return (string) $this->connection->fetchOne('SELECT allocated_amount FROM cash_transaction WHERE id = :id', ['id' => $transaction->getId()]);
    }
}

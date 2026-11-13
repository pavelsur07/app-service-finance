<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Import\File;

use App\Cash\Application\DTO\BankStatementDayInput;
use App\Cash\Application\DTO\BankStatementLineInput;
use App\Cash\Application\Import\BankStatement\ImportBankStatementDayAction;
use App\Cash\Application\Import\File\ApplyCashFileDescriptionsAction;
use App\Cash\Application\Import\File\StartCashFileImportJobAction;
use App\Cash\Application\Service\Import\File\CashFileDescriptionPlan;
use App\Cash\Application\Service\Import\File\CashFileImportService;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Events;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

final class CashFileDescriptionApplyTest extends CashFileImportTestCase
{
    /** @var list<string> */
    private array $planKeys = [];

    public function testSelectedUpdatePreservesIdentityAndMoneyAndAuditsActorExactlyOnce(): void
    {
        $this->seedTransaction();
        $this->attachFinancialRelations();
        $before = $this->financialSnapshot();
        $actor = Uuid::uuid7()->toString();
        $job = $this->plannedJob('Уточнение платежа', true, $actor);
        $jobId = (string) $job->getId();
        self::assertSame('Исходный платёж', $this->transaction()->getDescription(), 'Preview must not write.');

        $this->handle($jobId);
        $this->em->clear();
        self::assertSame(CashFileImportJob::STATUS_DONE, $this->reloadJob($jobId)->getStatus());
        self::assertSame('Уточнение платежа', $this->transaction()->getDescription());
        self::assertTrue($this->transaction()->isImportedDescriptionOverridden());
        self::assertSame($before, $this->financialSnapshot());
        self::assertSame(1, $this->countActiveTransactions());
        $auditCount = $this->descriptionAudits($actor);
        self::assertSame(1, $auditCount);

        $this->handle($jobId);
        self::assertSame(1, $this->descriptionAudits($actor));
        self::assertSame($before, $this->financialSnapshot());
    }

    public function testPreviewExcludesOtherCompanyAccountAndYear(): void
    {
        $owner = $this->company->getUser();
        self::assertInstanceOf(\App\Company\Entity\User::class, $owner);
        $otherCompany = new \App\Company\Entity\Company(Uuid::uuid7()->toString(), $owner);
        $otherCompany->setName('Other company');
        $foreignAccount = new \App\Cash\Entity\Accounts\MoneyAccount(Uuid::uuid7()->toString(), $otherCompany, \App\Cash\Enum\Accounts\MoneyAccountType::BANK, 'Foreign account', 'RUB');
        $otherAccount = new \App\Cash\Entity\Accounts\MoneyAccount(Uuid::uuid7()->toString(), $this->company, \App\Cash\Enum\Accounts\MoneyAccountType::BANK, 'Other account', 'RUB');
        foreach ([$otherCompany, $foreignAccount, $otherAccount] as $entity) {
            $this->em->persist($entity);
        }
        foreach ([[$otherCompany, $foreignAccount, '2026-10-01'], [$this->company, $otherAccount, '2026-10-01'], [$this->company, $this->account, '2025-10-01']] as [$company, $account, $date]) {
            $transaction = new CashTransaction(Uuid::uuid7()->toString(), $company, $account, \App\Cash\Enum\Transaction\CashDirection::OUTFLOW, '100.00', 'RUB', new \DateTimeImmutable($date));
            $transaction->setDocNumber('101')->setDescription('Исходный платёж');
            $this->em->persist($transaction);
        }
        $this->em->flush();
        $job = $this->plannedJob('Уточнение платежа', false);
        $plan = self::getContainer()->get(CashFileDescriptionPlan::class)->load((string) $this->company->getId(), (string) $job->getOptions()['description_plan']);
        self::assertSame('create', $plan['rows']['r2']['action']);
        self::assertNull($plan['rows']['r2']['transactionId']);
        self::assertSame(2, $this->countActiveTransactions(), 'Preview did not create a transaction.');
    }

    public function testRelativeDateReparsedOnAnotherDayDoesNotCreateUnapprovedOperation(): void
    {
        $job = $this->queueCsvImport([['date' => 'today', 'amount' => '100,00', 'description' => 'Relative-date payment']]);
        $this->attachPlan($job, false, null);
        $plans = self::getContainer()->get(CashFileDescriptionPlan::class);
        $plan = $plans->load((string) $this->company->getId(), (string) $job->getOptions()['description_plan']);
        // Simulate an approved plan created before midnight for an unchanged
        // relative-date file. Its bytes are identical; the parsed day differs.
        self::assertArrayHasKey('r2', $plan['rows']);
        $plan['rows']['r2']['input']['date'] = (new \DateTimeImmutable($plan['rows']['r2']['input']['date']))->modify('-1 day')->format('Y-m-d');
        $hash = $plans->save($plan);
        $this->planKeys[] = 'cash-file-plans/'.$this->company->getId().'/'.$hash.'.json';
        $options = $job->getOptions();
        $options['description_plan'] = $hash;
        $job->setOptions($options);
        $this->em->flush();
        $this->handle((string) $job->getId());
        self::assertSame(0, $this->countActiveTransactions());
        self::assertArrayHasKey('r2', $this->reloadJob((string) $job->getId())->getOptions()['creation_conflicts']);
    }

    public function testDateChangesOnlyDuringActualImportCannotCreateUnapprovedOperation(): void
    {
        $normalizer = new \App\Cash\Application\Service\Import\File\CashFileRowNormalizer();
        $calls = 0;
        $changing = $this->createMock(\App\Cash\Application\Service\Import\File\CashFileRowNormalizer::class);
        $changing->expects(self::exactly(3))->method('normalize')->willReturnCallback(static function (array $row, array $mapping, string $currency) use ($normalizer, &$calls): array {
            $normalized = $normalizer->normalize($row, $mapping, $currency);
            ++$calls;
            $normalized['occurredAt'] = new \DateTimeImmutable($calls <= 2 ? '2026-10-01' : '2026-10-02', new \DateTimeZone('Europe/Moscow'));

            return $normalized;
        });
        $rows = new \App\Cash\Application\Service\Import\File\CashFileNormalizedRows(self::getContainer()->get(\App\Cash\Infrastructure\Import\File\FileTabularReader::class), $changing);
        $reflection = new \ReflectionClass(CashFileImportService::class);
        $original = self::getContainer()->get(CashFileImportService::class);
        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $arguments[] = 'rows' === $parameter->getName() ? $rows : $reflection->getProperty($parameter->getName())->getValue($original);
        }
        $importer = $reflection->newInstanceArgs($arguments);
        $plans = new CashFileDescriptionPlan(
            $rows, self::getContainer()->get(\App\Cash\Infrastructure\Import\File\CashFileStorage::class), self::getContainer()->get(\App\Cash\Application\Service\Import\File\CashFileDescriptionMatcher::class),
            self::getContainer()->get(\App\Cash\Repository\Transaction\CashTransactionRepository::class),
            self::getContainer()->get(ObjectStorageInterface::class),
            new \App\Company\Domain\Service\CounterpartyNameNormalizer(),
        );
        $job = $this->queueCsvImport([$this->row('Новая операция')]);
        $plan = self::getContainer()->get(\App\Cash\Infrastructure\Import\File\CashFileStorage::class)->withJobFile($job, fn (string $path): array => $plans->build($path, $job->getMoneyAccount(), $job->getMapping(), $job->getFileHash(), null));
        $hash = $plans->save($plan);
        $this->planKeys[] = 'cash-file-plans/'.$this->company->getId().'/'.$hash.'.json';
        $job->setOptions($job->getOptions() + ['description_plan' => $hash, 'selected_descriptions' => []]);
        $this->em->flush();
        $apply = new ApplyCashFileDescriptionsAction(
            $plans, self::getContainer()->get(\App\Cash\Infrastructure\Import\File\CashFileStorage::class), self::getContainer()->get(\App\Cash\Repository\Transaction\CashTransactionRepository::class),
            $this->em, self::getContainer()->get(\App\Cash\Application\Service\AutoRule\AutoRuleDispatchGuard::class),
        );
        $allowed = $apply($job);
        self::assertSame(2, $calls, 'Both preview and final re-plan must still see the approved date.');
        $importer->import($job, fn () => $this->em->flush(), $allowed);
        $this->em->flush();
        self::assertSame(0, $this->countActiveTransactions());
        self::assertArrayHasKey('r2', $job->getOptions()['creation_conflicts']);
    }

    public function testOverlongUnicodeDescriptionIsInvalidWhileAnotherSelectedUpdateSucceeds(): void
    {
        $initialRows = [
            $this->row('Исходный платёж'),
            ['date' => '01.10.2026', 'amount' => '200,00', 'description' => 'Вторая операция', 'doc_number' => '102'],
        ];
        $initial = $this->queueCsvImport($initialRows);
        $this->handle((string) $initial->getId());
        $long = str_repeat('Ж', 1025);
        $job = $this->queueCsvImport([
            $this->row($long),
            ['date' => '01.10.2026', 'amount' => '200,00', 'description' => 'Уточнение второй операции', 'doc_number' => '102'],
        ]);
        $this->attachPlan($job, true, null);
        $plan = self::getContainer()->get(CashFileDescriptionPlan::class)->load((string) $this->company->getId(), (string) $job->getOptions()['description_plan']);
        self::assertSame('invalid', $plan['rows']['r2']['action']);
        self::assertSame($long, $plan['rows']['r2']['input']['description'], 'Oversize text must not be silently truncated.');
        self::assertContains('Описание не должно превышать 1024 символа.', $plan['rows']['r2']['input']['errors']);
        self::assertSame(['r3'], $job->getOptions()['selected_descriptions']);
        $this->handle((string) $job->getId());
        $job = $this->reloadJob((string) $job->getId());
        self::assertSame(CashFileImportJob::STATUS_DONE, $job->getStatus());
        self::assertSame(2, $this->countActiveTransactions());
        self::assertSame('Исходный платёж', $this->transaction()->getDescription());
        $second = $this->em->getRepository(CashTransaction::class)->findOneBy(['company' => $this->company->getId(), 'docNumber' => '102']);
        self::assertInstanceOf(CashTransaction::class, $second);
        self::assertSame('Уточнение второй операции', $second->getDescription());
        self::assertSame('200.00', $second->getAmount());
        $log = $job->getImportLog();
        self::assertNotNull($log);
        self::assertSame(1, $log->getErrorsCount());
    }

    public function testSkippedAndInvalidPlanRowsAreReflectedInImportLog(): void
    {
        $this->seedTransaction();
        $job = $this->queueCsvImport([
            $this->row('Исходный платёж'),
            ['date' => 'bad-date', 'amount' => 'bad-amount', 'description' => 'Некорректная строка'],
        ]);
        $this->attachPlan($job, false, null);
        $this->handle((string) $job->getId());
        $log = $this->reloadJob((string) $job->getId())->getImportLog();
        self::assertNotNull($log);
        self::assertSame(1, $log->getErrorsCount());
        self::assertSame(1, $log->getSkippedDuplicates());
        self::assertSame(0, $log->getCreatedCount());
        self::assertSame(1, $this->countActiveTransactions());
    }

    public function testUnselectedUpdateIsSkippedWithoutCreatingAnotherTransaction(): void
    {
        $this->seedTransaction();
        $job = $this->plannedJob('Уточнение платежа', false);
        $this->handle((string) $job->getId());
        self::assertSame(1, $this->countActiveTransactions());
        self::assertSame('Исходный платёж', $this->transaction()->getDescription());
        self::assertFalse($this->transaction()->isImportedDescriptionOverridden());
    }

    public function testStaleDescriptionBecomesConflictWithoutOverwritingManualChange(): void
    {
        $this->seedTransaction();
        $job = $this->plannedJob('Уточнение платежа', true);
        $this->transaction()->setDescription('Ручная правка после preview');
        $this->em->flush();
        $this->handle((string) $job->getId());
        self::assertSame(1, $this->countActiveTransactions());
        self::assertSame('Ручная правка после preview', $this->transaction()->getDescription());
        self::assertContains('conflict', $this->reloadJob((string) $job->getId())->getOptions()['description_finished']);
    }

    public function testOldAndNewFileRepetitionDoNotCreateDuplicatesOrRevertText(): void
    {
        $this->seedTransaction();
        $updated = $this->plannedJob('Уточнение платежа', true);
        $this->handle((string) $updated->getId());
        $repeat = $this->plannedJob('Уточнение платежа', false);
        $this->handle((string) $repeat->getId());
        $old = $this->plannedJob('Исходный платёж', false);
        $this->handle((string) $old->getId());
        self::assertSame(1, $this->countActiveTransactions());
        self::assertSame('Уточнение платежа', $this->transaction()->getDescription());
    }

    public function testSelectedUpdatesAreLockedAndCommittedInChunksAndResumeWithoutDuplicateAudit(): void
    {
        $rows = $this->distinctRows(201);
        $initial = $this->queueCsvImport($rows);
        $this->handle((string) $initial->getId());
        foreach ($rows as &$row) {
            $row['description'] .= ' — уточнение';
        }
        unset($row);
        $job = $this->queueCsvImport($rows);
        $actor = Uuid::uuid7()->toString();
        $this->attachPlan($job, true, $actor);
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        $managedBefore = $this->em->getUnitOfWork()->size();
        self::getContainer()->get(ApplyCashFileDescriptionsAction::class)($job);
        self::assertLessThanOrEqual($managedBefore + 10, $this->em->getUnitOfWork()->size(), 'Selected rows must not accumulate in the worker identity map.');
        $lockingQueries = array_filter($holder->getData()['default'] ?? [], static fn (array $query): bool => str_contains((string) $query['sql'], 'FOR UPDATE'));
        self::assertCount(3, $lockingQueries);
        self::assertCount(201, $job->getOptions()['description_finished']);
        $this->handle((string) $job->getId());
        self::assertSame(201, $this->countActiveTransactions());
        self::assertSame(201, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM audit_log WHERE company_id = :company AND actor_user_id = :actor AND jsonb_exists(diff::jsonb, 'description')",
            ['company' => $this->company->getId(), 'actor' => $actor],
        ));
        self::assertSame(201, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM cash_transaction WHERE company_id = :company AND imported_description_overridden = true',
            ['company' => $this->company->getId()],
        ));
    }

    public function testSecondChunkFailureKeepsCommittedProgressAndResumesWithFreshManager(): void
    {
        $rows = $this->distinctRows(201);
        $initial = $this->queueCsvImport($rows);
        $this->handle((string) $initial->getId());
        foreach ($rows as &$row) {
            $row['description'] .= ' — уточнение';
        }
        unset($row);
        $job = $this->queueCsvImport($rows);
        $this->attachPlan($job, true, null);
        $job->start();
        $this->em->flush();
        $jobId = (string) $job->getId();
        $listener = new class {
            private int $chunks = 0;

            public function onFlush(\Doctrine\ORM\Event\OnFlushEventArgs $event): void
            {
                foreach ($event->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
                    if ($entity instanceof CashTransaction) {
                        ++$this->chunks;
                        if (2 === $this->chunks) {
                            throw new \RuntimeException('synthetic second chunk failure');
                        }
                        break;
                    }
                }
            }
        };
        $events = $this->em->getEventManager();
        $events->addEventListener([Events::onFlush], $listener);
        try {
            self::getContainer()->get(ApplyCashFileDescriptionsAction::class)($job);
            self::fail('Second chunk must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('synthetic second chunk failure', $error->getMessage());
        } finally {
            $events->removeEventListener([Events::onFlush], $listener);
        }
        $options = json_decode((string) $this->connection->fetchOne('SELECT options FROM cash_file_import_jobs WHERE id = :id', ['id' => $jobId]), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(100, $options['description_finished']);
        self::assertSame(100, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = :id AND imported_description_overridden', ['id' => $this->company->getId()]));
        self::assertSame(100, (int) $this->connection->fetchOne("SELECT count(*) FROM audit_log WHERE company_id = :id AND jsonb_exists(diff::jsonb, 'description')", ['id' => $this->company->getId()]));
        self::assertSame(101, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = :id AND NOT imported_description_overridden', ['id' => $this->company->getId()]));
        $manager = self::getContainer()->get(\Doctrine\Persistence\ManagerRegistry::class)->resetManager();
        self::assertInstanceOf(\Doctrine\ORM\EntityManagerInterface::class, $manager);
        $this->em = $manager;
        $this->connection = $manager->getConnection();
        $this->handle($jobId);
        self::assertSame(CashFileImportJob::STATUS_DONE, $this->reloadJob($jobId)->getStatus());
        self::assertCount(201, $this->reloadJob($jobId)->getOptions()['description_finished']);
        self::assertSame(201, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = :id AND imported_description_overridden', ['id' => $this->company->getId()]));
        self::assertSame(201, (int) $this->connection->fetchOne("SELECT count(*) FROM audit_log WHERE company_id = :id AND jsonb_exists(diff::jsonb, 'description')", ['id' => $this->company->getId()]));
    }

    public function testManyCounterpartiesAreFetchedWithChunkRatherThanPerTransaction(): void
    {
        $rows = $this->distinctRows(25);
        foreach ($rows as $index => &$row) {
            $row['counterparty'] = 'ООО Контрагент '.($index + 1);
        }
        unset($row);
        $initial = $this->queueCsvImport($rows);
        $this->handle((string) $initial->getId());
        foreach ($rows as &$row) {
            $row['description'] .= ' — уточнение';
        }
        unset($row);
        $job = $this->queueCsvImport($rows);
        $this->attachPlan($job, true, null);
        self::assertCount(25, $job->getOptions()['selected_descriptions']);
        $job = $this->reloadJob((string) $job->getId());
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        self::getContainer()->get(ApplyCashFileDescriptionsAction::class)($job);
        $counterpartyQueries = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => 1 === preg_match('/FROM\s+"?counterparty"?\s/i', (string) $query['sql']),
        );
        self::assertCount(0, $counterpartyQueries, 'Counterparty names must be hydrated in the chunk query, not through one SELECT per row.');
        self::assertCount(25, $job->getOptions()['description_finished']);
        self::assertSame(['updated'], array_values(array_unique($job->getOptions()['description_finished'])));
    }

    public function testLockedCommittedTransactionRejectsAnotherConnectionUpdateUntilRollback(): void
    {
        // A DAMA fixture alone would give a false positive: the other connection
        // would wait on its uncommitted INSERT even without a FOR UPDATE lock.
        $other = DriverManager::getConnection($this->connection->getParams());
        $seedManager = new \Doctrine\ORM\EntityManager($other, $this->em->getConfiguration(), new \Doctrine\Common\EventManager());
        $owner = new \App\Company\Entity\User(Uuid::uuid7()->toString());
        $owner->setEmail('row-lock-'.Uuid::uuid7()->toString().'@example.test');
        $owner->setPassword('test');
        $company = new \App\Company\Entity\Company(Uuid::uuid7()->toString(), $owner);
        $company->setName('Committed row lock fixture');
        $account = new \App\Cash\Entity\Accounts\MoneyAccount(Uuid::uuid7()->toString(), $company, \App\Cash\Enum\Accounts\MoneyAccountType::BANK, 'Row lock fixture', 'RUB');
        $transaction = new CashTransaction(Uuid::uuid7()->toString(), $company, $account, \App\Cash\Enum\Transaction\CashDirection::INFLOW, '100.00', 'RUB', new \DateTimeImmutable('2026-10-01'));
        foreach ([$owner, $company, $account, $transaction] as $entity) {
            $seedManager->persist($entity);
        }
        $seedManager->flush(); // Separate physical connection commits this fixture.
        $nested = false;
        try {
            $this->connection->beginTransaction();
            $nested = true;
            $locked = self::getContainer()->get(\App\Cash\Repository\Transaction\CashTransactionRepository::class)->findForDescriptionUpdate((string) $company->getId(), (string) $account->getId(), [(string) $transaction->getId()]);
            self::assertCount(1, $locked);
            $other->executeStatement("SET lock_timeout = '100ms'");
            $other->beginTransaction();
            try {
                $other->executeStatement('UPDATE cash_transaction SET description = :text WHERE id = :id', ['text' => 'Concurrent edit', 'id' => $transaction->getId()]);
                self::fail('Another connection must wait for the description row lock.');
            } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState(), 'PostgreSQL lock_timeout must reject the concurrent update.');
            } finally {
                $other->rollBack();
            }
            // PostgreSQL releases locks acquired after the savepoint on rollback.
            $this->connection->rollBack();
            $nested = false;
            self::assertSame(1, $other->executeStatement('UPDATE cash_transaction SET description = :text WHERE id = :id', ['text' => 'Edit after lock release', 'id' => $transaction->getId()]));
        } finally {
            if ($nested) {
                $this->connection->rollBack();
            }
            $other->executeStatement('DELETE FROM cash_transaction WHERE id = :id', ['id' => $transaction->getId()]);
            $other->executeStatement('DELETE FROM money_account WHERE id = :id', ['id' => $account->getId()]);
            $other->executeStatement('DELETE FROM companies WHERE id = :id', ['id' => $company->getId()]);
            $other->executeStatement('DELETE FROM "user" WHERE id = :id', ['id' => $owner->getId()]);
            $other->close();
        }
    }

    public function testSelectedUpdateWithNewOperationCreatesOnlyNewOperation(): void
    {
        $this->seedTransaction();
        $job = $this->queueCsvImport([
            $this->row('Уточнение платежа'),
            ['date' => '02.10.2026', 'amount' => '200,00', 'description' => 'Новая операция', 'doc_number' => '102'],
        ]);
        $this->attachPlan($job, true, null);
        $this->handle((string) $job->getId());
        self::assertSame(2, $this->countActiveTransactions());
        self::assertSame('Уточнение платежа', $this->transaction()->getDescription());
        self::assertSame(CashFileImportJob::STATUS_DONE, $this->reloadJob((string) $job->getId())->getStatus());
    }

    public function testXlsxPreviewAndConfirmedUpdateUseSameRules(): void
    {
        $this->seedTransaction();
        $job = $this->queueCsvImport([$this->row('Уточнение платежа')]);
        $path = tempnam(sys_get_temp_dir(), 'description-xlsx-');
        self::assertIsString($path);
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile($path);
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(array_values(self::MAPPING)));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['01.10.2026', '100,00', 'Уточнение платежа', '', '101']));
        $writer->close();
        try {
            $key = 'cash-file-imports/'.$job->getFileHash().'.xlsx';
            $bytes = file_get_contents($path);
            self::assertIsString($bytes);
            self::getContainer()->get(ObjectStorageInterface::class)->write($key, $bytes);
            $this->planKeys[] = $key;
        } finally {
            unlink($path);
        }
        $job->setFilename('statement.xlsx');
        $job->setOptions(['stored_ext' => 'xlsx']);
        $this->attachPlan($job, true, null);
        $this->handle((string) $job->getId());
        self::assertSame('Уточнение платежа', $this->transaction()->getDescription());
        self::assertSame(1, $this->countActiveTransactions());
    }

    public function testMatchingAutoRuleIsNotDispatchedOnDescriptionReplacement(): void
    {
        $this->seedTransaction();
        $target = CashflowCategoryBuilder::aCashflowCategory()->withId(Uuid::uuid7()->toString())->withCompany($this->company)->build();
        $rule = new CashTransactionAutoRule(Uuid::uuid7()->toString(), $this->company, 'Matches new description', CashTransactionAutoRuleAction::UPDATE, CashTransactionAutoRuleOperationType::ANY, $target);
        $rule->addCondition(new CashTransactionAutoRuleCondition(null, $rule, CashTransactionAutoRuleConditionField::DESCRIPTION, CashTransactionAutoRuleConditionOperator::CONTAINS, 'Уточнение'));
        $this->em->persist($target);
        $this->em->persist($rule);
        $this->em->flush();
        $job = $this->plannedJob('Уточнение платежа', true);
        $transport = self::getContainer()->get('messenger.transport.async_pipeline');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $sent = count($transport->getSent());
        $this->handle((string) $job->getId());
        $transaction = $this->transaction();
        self::assertTrue(self::getContainer()->get(CashTransactionAutoRuleService::class)->match($transaction)->hasWinners(), 'Fixture must actually match the new description.');
        self::assertNull($transaction->getCashflowCategory());
        self::assertCount($sent, $transport->getSent());
    }

    public function testChangedFinancialAmountAfterPreviewRemainsUntouched(): void
    {
        $this->seedTransaction();
        $job = $this->plannedJob('Уточнение платежа', true);
        $transaction = $this->transaction();
        $transaction->setAmount('101.00');
        $this->em->flush();
        $this->handle((string) $job->getId());
        self::assertSame('101.00', $transaction->getAmount());
        self::assertSame('Исходный платёж', $transaction->getDescription());
        self::assertSame(1, $this->countActiveTransactions());
        self::assertContains('conflict', $this->reloadJob((string) $job->getId())->getOptions()['description_finished']);
    }

    public function testReplacedSourceBytesCannotChangeApprovedAmounts(): void
    {
        $this->seedTransaction();
        $job = $this->plannedJob('Уточнение платежа', true);
        $storage = self::getContainer()->get(ObjectStorageInterface::class);
        $key = 'cash-file-imports/'.$job->getFileHash().'.csv';
        $storage->write($key, str_replace('100,00', '500,00', $storage->read($key)));
        try {
            self::getContainer()->get(ApplyCashFileDescriptionsAction::class)($job);
            self::fail('Changed file must be rejected before update.');
        } catch (\DomainException) {
            self::assertSame('Исходный платёж', $this->transaction()->getDescription());
            self::assertSame('100.00', $this->transaction()->getAmount());
            self::assertSame(1, $this->countActiveTransactions());
        }
    }

    public function testExtraIdenticalOperationIsCreatedOnceAcrossBatchBoundary(): void
    {
        $same = $this->row('Одинаковые платежи');
        $initialRows = array_merge($this->distinctRows(199), [$same, $same]);
        $initial = $this->queueCsvImport($initialRows);
        $this->handle((string) $initial->getId());
        self::assertSame(201, $this->countActiveTransactions());
        $rows = array_merge($initialRows, [$same]);
        $job = $this->queueCsvImport($rows);
        $this->attachPlan($job, false, null);
        $this->handle((string) $job->getId());
        self::assertSame(202, $this->countActiveTransactions());
        $repeat = $this->queueCsvImport($rows);
        $this->attachPlan($repeat, false, null);
        $this->handle((string) $repeat->getId());
        self::assertSame(202, $this->countActiveTransactions());
    }

    public function testDeletedCandidateBecomesConflictWithoutRestoration(): void
    {
        $this->seedTransaction();
        $job = $this->plannedJob('Уточнение платежа', true);
        $this->transaction()->markDeleted('test', 'removed after preview');
        $this->em->flush();
        $this->handle((string) $job->getId());
        self::assertSame(0, $this->countActiveTransactions());
        self::assertSame('Исходный платёж', $this->transaction()->getDescription());
        self::assertContains('conflict', $this->reloadJob((string) $job->getId())->getOptions()['description_finished']);
    }

    public function testConcurrentCreationAfterPreviewIsNotCreatedAgain(): void
    {
        $job = $this->plannedJob('Исходный платёж', false);
        $this->seedTransaction();
        $this->handle((string) $job->getId());
        self::assertSame(1, $this->countActiveTransactions());
        self::assertSame('Исходный платёж', $this->transaction()->getDescription());
    }

    public function testCorruptedStoredPlanCannotApplyDescription(): void
    {
        $this->seedTransaction();
        $job = $this->plannedJob('Уточнение платежа', true);
        $hash = $job->getOptions()['description_plan'];
        self::getContainer()->get(ObjectStorageInterface::class)->write(
            'cash-file-plans/'.(string) $this->company->getId().'/'.$hash.'.json', '{}',
        );
        try {
            self::getContainer()->get(ApplyCashFileDescriptionsAction::class)($job);
            self::fail('Corrupted plan must be rejected.');
        } catch (\DomainException $error) {
            self::assertSame('План импорта повреждён.', $error->getMessage());
        }
        self::assertSame('Исходный платёж', $this->transaction()->getDescription());
        self::assertSame(1, $this->countActiveTransactions());
    }

    public function testRepeatedConfirmationReturnsSameJobAndRejectsDifferentSelection(): void
    {
        $this->seedTransaction();
        $job = $this->plannedJob('Уточнение платежа', true);
        $plan = self::getContainer()->get(CashFileDescriptionPlan::class)->load((string) $this->company->getId(), $job->getOptions()['description_plan']);
        $start = self::getContainer()->get(StartCashFileImportJobAction::class);
        $first = $start($this->company, $this->account, 'cash:file', $job->getFilename(), $job->getFileHash(), $job->getMapping(), $job->getOptions(), null, $plan['id']);
        $second = $start($this->company, $this->account, 'cash:file', $job->getFilename(), $job->getFileHash(), $job->getMapping(), $job->getOptions(), null, $plan['id']);
        self::assertSame((string) $first->getId(), (string) $second->getId());
        $different = $job->getOptions();
        $different['selected_descriptions'] = [];
        $this->expectException(\DomainException::class);
        $start($this->company, $this->account, 'cash:file', $job->getFilename(), $job->getFileHash(), $job->getMapping(), $different, null, $plan['id']);
    }

    public function testBankRepeatPreservesConfirmedDescriptionAndExternalIdentity(): void
    {
        $day = new BankStatementDayInput(new \DateTimeImmutable('2026-10-01'), [
            new BankStatementLineInput('bank-id-101', true, '100.00', 'RUB', new \DateTimeImmutable('2026-10-01'), 'Исходный платёж'),
        ]);
        $import = self::getContainer()->get(ImportBankStatementDayAction::class);
        $this->em->wrapInTransaction(fn () => $import((string) $this->company->getId(), (string) $this->account->getId(), 'alfa', $day));
        $before = $this->financialSnapshot();
        $job = $this->plannedJob('Уточнение платежа', true);
        $this->handle((string) $job->getId());
        $this->em->wrapInTransaction(fn () => $import((string) $this->company->getId(), (string) $this->account->getId(), 'alfa', $day));
        self::assertSame('Уточнение платежа', $this->transaction()->getDescription());
        self::assertSame($before, $this->financialSnapshot());
        self::assertSame(1, $this->countActiveTransactions());
    }

    public function testResumeAfterCommittedDescriptionDoesNotRepeatAudit(): void
    {
        $this->seedTransaction();
        $actor = Uuid::uuid7()->toString();
        $job = $this->plannedJob('Уточнение платежа', true, $actor);
        $job->start();
        $this->em->flush();
        self::getContainer()->get(ApplyCashFileDescriptionsAction::class)($job);
        self::assertSame(1, $this->descriptionAudits($actor));
        // The worker died after committing the update and before finishing the job.
        $this->handle((string) $job->getId());
        self::assertSame(CashFileImportJob::STATUS_DONE, $this->reloadJob((string) $job->getId())->getStatus());
        self::assertSame('Уточнение платежа', $this->transaction()->getDescription());
        self::assertSame(1, $this->descriptionAudits($actor));
        self::assertSame(1, $this->countActiveTransactions());
    }

    public function testFailedFlushRollsBackDescriptionAuditAndProgressTogether(): void
    {
        $this->seedTransaction();
        $job = $this->plannedJob('Уточнение платежа', true, Uuid::uuid7()->toString());
        $listener = new class {
            public function onFlush(): void
            {
                throw new \RuntimeException('synthetic flush failure');
            }
        };
        $events = $this->em->getEventManager();
        $events->addEventListener([Events::onFlush], $listener);
        try {
            self::getContainer()->get(ApplyCashFileDescriptionsAction::class)($job);
            self::fail('Flush must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('synthetic flush failure', $error->getMessage());
        } finally {
            $events->removeEventListener([Events::onFlush], $listener);
        }
        self::assertSame('Исходный платёж', $this->connection->fetchOne(
            'SELECT description FROM cash_transaction WHERE company_id = :id',
            ['id' => (string) $this->company->getId()],
        ));
        $options = json_decode($this->connection->fetchOne(
            'SELECT options FROM cash_file_import_jobs WHERE id = :id',
            ['id' => (string) $job->getId()],
        ), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('description_finished', $options);
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM audit_log WHERE company_id = :id AND jsonb_exists(diff::jsonb, 'description')",
            ['id' => (string) $this->company->getId()],
        ));
    }

    public function testDescriptionJobDefersWhileAnotherConnectionOwnsAccountLock(): void
    {
        $this->seedTransaction();
        $job = $this->plannedJob('Уточнение платежа', true);
        $other = DriverManager::getConnection($this->connection->getParams());
        self::assertTrue((bool) $other->fetchOne(
            "SELECT pg_try_advisory_lock(hashtext('cash_import_account'), hashtext(:key))",
            ['key' => (string) $this->company->getId().':'.(string) $this->account->getId()],
        ));
        try {
            $this->handle((string) $job->getId());
            self::fail('Busy account must defer import.');
        } catch (RecoverableMessageHandlingException) {
            self::assertSame('Исходный платёж', $this->transaction()->getDescription());
        } finally {
            $other->close();
        }
        $this->handle((string) $job->getId());
        self::assertSame('Уточнение платежа', $this->transaction()->getDescription());
        self::assertSame(1, $this->countActiveTransactions());
    }

    /** @return array{date: string, amount: string, description: string, doc_number: string} */
    private function row(string $description): array
    {
        return ['date' => '01.10.2026', 'amount' => '100,00', 'description' => $description, 'doc_number' => '101'];
    }

    private function attachFinancialRelations(): void
    {
        $transaction = $this->transaction();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withId(Uuid::uuid7()->toString())->withCompany($this->company)->build();
        $this->em->persist($category);
        $transaction->replaceSplits([new CashTransactionSplit($transaction, $category, '100.00', CashTransactionSplitSource::IMPORT)]);
        $document = new Document(Uuid::uuid7()->toString(), $this->company);
        $document->setCashTransaction($transaction);
        $operation = new DocumentOperation();
        $operation->setAmount('100.00');
        $document->addOperation($operation);
        $transaction->addDocument($document);
        $this->em->persist($document);
        $this->em->flush();
    }

    private function seedTransaction(): void
    {
        $job = $this->queueCsvImport([$this->row('Исходный платёж')]);
        $this->handle((string) $job->getId());
    }

    private function plannedJob(string $description, bool $selected, ?string $actor = null): CashFileImportJob
    {
        $job = $this->queueCsvImport([$this->row($description)]);
        $this->attachPlan($job, $selected, $actor);

        return $job;
    }

    private function attachPlan(CashFileImportJob $job, bool $selected, ?string $actor): void
    {
        $plans = self::getContainer()->get(CashFileDescriptionPlan::class);
        $importer = self::getContainer()->get(CashFileImportService::class);
        $plan = self::getContainer()->get(\App\Cash\Infrastructure\Import\File\CashFileStorage::class)->withJobFile($job, fn (string $path): array => $plans->build(
            $path, $job->getMoneyAccount(), $job->getMapping(), $job->getFileHash(), $actor,
        ));
        $hash = $plans->save($plan);
        $this->planKeys[] = 'cash-file-plans/'.(string) $this->company->getId().'/'.$hash.'.json';
        $chosen = [];
        if ($selected) {
            foreach ($plan['rows'] as $id => $row) {
                if ('update_description' === $row['action']) {
                    $chosen[] = (string) $id;
                }
            }
            self::assertNotEmpty($chosen, json_encode($plan, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
        }
        $job->setOptions($job->getOptions() + ['description_plan' => $hash, 'selected_descriptions' => $chosen]);
        $this->em->flush();
    }

    private function transaction(): CashTransaction
    {
        $transaction = $this->em->getRepository(CashTransaction::class)->findOneBy([
            'company' => (string) $this->company->getId(), 'amount' => '100.00',
        ]);
        self::assertInstanceOf(CashTransaction::class, $transaction);

        return $transaction;
    }

    /** @return array<string, mixed> */
    private function financialSnapshot(): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT id, company_id, amount, direction, currency, money_account_id, occurred_at, booked_at, counterparty_id, doc_number, raw_data, external_id, import_source, allocated_amount, cashflow_category_id, project_direction_id, responsibility_center_id, is_transfer, vat_rate_percent, vat_amount FROM cash_transaction WHERE company_id = :company AND amount = :amount',
            ['company' => (string) $this->company->getId(), 'amount' => '100.00'],
        ) ?: [];
        $id = $row['id'] ?? '';

        return [
            'transaction' => $row,
            'splits' => $this->connection->fetchAllAssociative('SELECT id, company_id, cashflow_category_id, amount, source FROM cash_transaction_split WHERE cash_transaction_id = :id ORDER BY id', ['id' => $id]),
            'documents' => $this->connection->fetchAllAssociative('SELECT id, company_id, cash_transaction_id, type, status, deleted_at FROM documents WHERE cash_transaction_id = :id ORDER BY id', ['id' => $id]),
            'operations' => $this->connection->fetchAllAssociative('SELECT o.id, o.document_id, o.category_id, o.amount, o.counterparty_id, o.project_direction_id, o.responsibility_center_id FROM document_operations o INNER JOIN documents d ON d.id = o.document_id WHERE d.cash_transaction_id = :id ORDER BY o.id', ['id' => $id]),
            'balances' => $this->connection->fetchAllAssociative('SELECT date, opening_balance, inflow, outflow, closing_balance, currency FROM money_account_daily_balance WHERE company_id = :company AND money_account_id = :account ORDER BY date', ['company' => (string) $this->company->getId(), 'account' => (string) $this->account->getId()]),
        ];
    }

    private function descriptionAudits(string $actor): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM audit_log WHERE company_id = :company AND actor_user_id = :actor AND entity_id = :id AND jsonb_exists(diff::jsonb, 'description')",
            ['company' => (string) $this->company->getId(), 'actor' => $actor, 'id' => $this->transaction()->getId()],
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->planKeys as $key) {
            self::getContainer()->get(ObjectStorageInterface::class)->delete($key);
        }
        parent::tearDown();
    }
}

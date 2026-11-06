<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Cash\Application\BulkDeleteCashTransactionsAction;
use App\Cash\Application\DeleteCashTransactionAction;
use App\Cash\Application\UpdateCashTransactionAction;
use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Company\Entity\Company;
use App\Finance\Application\UpdateManualDocumentAction;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Application\CreateSettlementFromFinanceAction;
use App\Settlement\Application\DTO\CreateSettlementFromFinanceInput;
use App\Settlement\Application\DTO\RegisterCashSettlementInput;
use App\Settlement\Application\PostSettlementDocumentAction;
use App\Settlement\Application\RegisterCashSettlementAction;
use App\Settlement\Enum\SettlementBucket;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class SettlementSourceMutationGuardTest extends IntegrationTestCase
{
    private Company $company;
    private CashTransaction $cash;
    private string $companyId;
    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        $user = UserBuilder::aUser()->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($this->company)->build();
        $this->cash = CashTransactionBuilder::aCashTransaction()->forCompany($this->company)->withAmount('40000.00')->build();
        $date = new \DateTimeImmutable('2026-11-01');
        $this->cash->setOccurredAt($date)->setCounterparty($party);
        foreach ([$user, $this->company, $party, $this->cash->getMoneyAccount(), $this->cash] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $companyId = $this->company->getId();
        $actorId = $user->getId();
        $cashId = $this->cash->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($actorId);
        self::assertNotNull($cashId);
        $this->companyId = $companyId;
        $this->actorId = $actorId;
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, $actorId, $date);
        (self::getContainer()->get(RegisterCashSettlementAction::class))($companyId, $actorId, new RegisterCashSettlementInput(Uuid::uuid7()->toString(), $cashId, false, $date));
    }

    public function testLinkedCashCannotBeDeleted(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Источник участвует во взаиморасчётах');
        (self::getContainer()->get(DeleteCashTransactionAction::class))($this->cash, $this->actorId);
    }

    public function testBulkDeleteIsAtomicWhenOneSourceIsLinked(): void
    {
        $other = CashTransactionBuilder::aCashTransaction()->forCompany($this->company)->withMoneyAccount($this->cash->getMoneyAccount())->withAmount('1.00')->build();
        $other->setOccurredAt(new \DateTimeImmutable('2026-11-01'));
        $this->em->persist($other->getMoneyAccount());
        $this->em->persist($other);
        $this->em->flush();
        try {
            (self::getContainer()->get(BulkDeleteCashTransactionsAction::class))($this->company, [$other->getId(), $this->cash->getId()], $this->actorId);
            self::fail('Linked source deletion must fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Источник участвует во взаиморасчётах', $exception->getMessage());
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cash_transaction WHERE company_id = ? AND deleted_at IS NOT NULL', [$this->companyId]));
        }
    }

    private function cashInput(): CashTransactionDTO
    {
        $dto = new CashTransactionDTO();
        $dto->companyId = $this->companyId;
        $dto->moneyAccountId = (string) $this->cash->getMoneyAccount()->getId();
        $dto->counterpartyId = $this->cash->getCounterparty()?->getId();
        $dto->direction = $this->cash->getDirection();
        $dto->amount = $this->cash->getAmount();
        $dto->currency = $this->cash->getCurrency();
        $dto->occurredAt = $this->cash->getOccurredAt();

        return $dto;
    }

    public function testAmountChangeCannotInvalidatePayment(): void
    {
        $dto = $this->cashInput();
        $dto->amount = '40001.00';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Источник участвует во взаиморасчётах');
        (self::getContainer()->get(UpdateCashTransactionAction::class))($this->cash, $dto);
    }

    public function testDescriptionOnlyChangeRemainsAllowed(): void
    {
        $dto = $this->cashInput();
        $dto->description = 'Новый комментарий';
        (self::getContainer()->get(UpdateCashTransactionAction::class))($this->cash, $dto);
        self::assertSame('Новый комментарий', $this->connection->fetchOne('SELECT description FROM cash_transaction WHERE company_id = ? AND id = ?', [$this->companyId, $this->cash->getId()]));
    }

    public function testPnlOperationChangeCannotInvalidateObligation(): void
    {
        $date = new \DateTimeImmutable('2026-11-01');
        $source = (new Document(Uuid::uuid7()->toString(), $this->company))->setDate($date)->setCounterparty($this->cash->getCounterparty());
        $operation = (new DocumentOperation())->setDocument($source)->setAmount('100000.00');
        $this->em->persist($source);
        $this->em->persist($operation);
        $this->em->flush();
        $sourceId = $source->getId();
        self::assertNotNull($sourceId);
        $draft = (self::getContainer()->get(CreateSettlementFromFinanceAction::class))($this->companyId, $this->actorId, new CreateSettlementFromFinanceInput(Uuid::uuid7()->toString(), $sourceId, SettlementBucket::PAYABLE, Money::fromString('120000.00', 'RUB'), $date, null, 'Акт'));
        (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
        $initialStatus = $source->getStatus();
        $operation->setAmount('100001.00');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Источник участвует во взаиморасчётах');
        (self::getContainer()->get(UpdateManualDocumentAction::class))($source, $initialStatus, $date);
    }

    public function testCorrectionCannotCreatePaymentMoneyWithoutCash(): void
    {
        $itemId = (string) $this->connection->fetchOne('SELECT id FROM settlement_items WHERE company_id = ?', [$this->companyId]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Фактическую оплату нельзя изменять корректировкой');
        (self::getContainer()->get(\App\Settlement\Application\CorrectSettlementItemAction::class))($this->companyId, $this->actorId, new \App\Settlement\Application\DTO\CorrectSettlementItemInput(Uuid::uuid7()->toString(), $itemId, Money::fromString('1.00', 'RUB'), true, new \DateTimeImmutable('2026-11-02'), 'Изменение оплаты'));
    }

    public function testRawSqlDeleteCommandCannotBypassSourceGuard(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:cash:soft-delete-company-transactions'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Источник участвует во взаиморасчётах');
        $tester->execute(['companyId' => $this->companyId, '--execute' => true, '--reason' => 'Проверка защиты']);
    }

    public function testExistingImportMatchCannotChangeLinkedCounterparty(): void
    {
        $this->cash->setImportSource('test-settlement')->setExternalId('existing-payment');
        $this->em->flush();
        $dto = $this->cashInput();
        $dto->importSource = 'test-settlement';
        $dto->externalId = 'existing-payment';
        $dto->counterpartyId = null;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Источник участвует во взаиморасчётах');
        (self::getContainer()->get(\App\Cash\Application\AddCashTransactionAction::class))($dto);
    }

    public function testPnlDescriptionOnlyChangeRemainsAllowed(): void
    {
        $date = new \DateTimeImmutable('2026-11-01');
        $source = (new Document(Uuid::uuid7()->toString(), $this->company))->setDate($date)->setCounterparty($this->cash->getCounterparty());
        $operation = (new DocumentOperation())->setDocument($source)->setAmount('100000.00');
        $this->em->persist($source);
        $this->em->persist($operation);
        $this->em->flush();
        $sourceId = $source->getId();
        self::assertNotNull($sourceId);
        $draft = (self::getContainer()->get(CreateSettlementFromFinanceAction::class))($this->companyId, $this->actorId, new CreateSettlementFromFinanceInput(Uuid::uuid7()->toString(), $sourceId, SettlementBucket::PAYABLE, Money::fromString('120000.00', 'RUB'), $date, null, 'Акт'));
        (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
        $source->setDescription('Комментарий к акту');
        (self::getContainer()->get(UpdateManualDocumentAction::class))($source, $source->getStatus(), $date);
        self::assertSame('Комментарий к акту', $this->connection->fetchOne('SELECT description FROM documents WHERE company_id = ? AND id = ?', [$this->companyId, $sourceId]));
    }

    public function testBank1cOverwriteRejectionRollsBackBatchAndFinishesImportLog(): void
    {
        $container = self::getContainer();
        $service = $this->bankImporter();
        $account = $this->cash->getMoneyAccount();
        $account->setAccountNumber('40702810900000000003');
        $this->em->flush();
        $row = $this->bankRow();
        $service->import([$row], $account, false, fn () => $this->em->flush());
        $cashId = (string) $this->connection->fetchOne('SELECT id FROM cash_transaction WHERE company_id = ? AND id <> ?', [$this->companyId, $this->cash->getId()]);
        $imported = $this->em->getRepository(CashTransaction::class)->findOneBy(['company' => $this->companyId, 'id' => $cashId]);
        self::assertInstanceOf(CashTransaction::class, $imported);
        $imported->setCounterparty($this->cash->getCounterparty());
        $this->em->flush();
        (self::getContainer()->get(RegisterCashSettlementAction::class))($this->companyId, $this->actorId, new RegisterCashSettlementInput(Uuid::uuid7()->toString(), $cashId, false, new \DateTimeImmutable('2026-11-01')));
        $newRow = $row;
        $newRow['docNumber'] = 'SETTLE-NEW';
        $newRow['amount'] = '1.00';
        $action = new \App\Cash\Application\ImportBank1CStatementAction($service, $container->get(\App\Cash\Service\Import\ImportLogger::class), $container->get(\App\Cash\Infrastructure\Lock\CashImportLogLease::class), $this->em, $container->get(\App\Cash\Infrastructure\Lock\CashImportAccountLock::class));
        try {
            $action($this->company, [$newRow, $row], $account, true, $this->actorId, 'fixture.txt', []);
            self::fail('Linked overwrite must abort entire batch.');
        } catch (\App\Settlement\Exception\SettlementSourceInUseException) {
            self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cash_transaction WHERE company_id = ?', [$this->companyId]));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM import_log WHERE company_id = ? AND finished_at IS NOT NULL AND errors_count = 1 AND created_count = 0', [$this->companyId]));
        }
    }

    /** @return array{Document, DocumentOperation} */
    private function linkedFinanceSource(): array
    {
        $date = new \DateTimeImmutable('2026-11-01');
        $source = (new Document(Uuid::uuid7()->toString(), $this->company))->setDate($date)->setCounterparty($this->cash->getCounterparty());
        $operation = (new DocumentOperation())->setDocument($source)->setAmount('100000.00');
        $this->em->persist($source);
        $this->em->persist($operation);
        $this->em->flush();
        $sourceId = $source->getId();
        self::assertNotNull($sourceId);
        $draft = (self::getContainer()->get(CreateSettlementFromFinanceAction::class))($this->companyId, $this->actorId, new CreateSettlementFromFinanceInput(Uuid::uuid7()->toString(), $sourceId, SettlementBucket::PAYABLE, Money::fromString('120000.00', 'RUB'), $date, null, 'Акт'));
        (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);

        return [$source, $operation];
    }

    public function testLinkedFinanceSourceCannotBeSoftDeleted(): void
    {
        [$source] = $this->linkedFinanceSource();
        $id = $source->getId();
        self::assertNotNull($id);
        $this->expectException(\App\Settlement\Exception\SettlementSourceInUseException::class);
        (self::getContainer()->get(\App\Finance\Application\SoftDeleteDocumentAction::class))($this->companyId, $id, $this->actorId);
    }

    public function testLinkedFinanceSourceCannotBeHardDeleted(): void
    {
        [$source] = $this->linkedFinanceSource();
        $id = $source->getId();
        self::assertNotNull($id);
        $this->expectException(\App\Settlement\Exception\SettlementSourceInUseException::class);
        (self::getContainer()->get(\App\Finance\Application\DeletePLDocumentAction::class))($this->companyId, $id);
    }

    private function bankImporter(): \App\Cash\Service\Import\ClientBank1CImportService
    {
        $container = self::getContainer();
        $active = new class($this->company) extends \App\Shared\Service\ActiveCompanyService {
            public function __construct(private Company $company)
            {
            }

            public function getActiveCompany(): Company
            {
                return $this->company;
            }
        };
        $project = new \App\Company\Entity\ProjectDirection(Uuid::uuid7()->toString(), $this->company, 'Общий', \App\Company\Entity\ProjectDirection::CODE_GENERAL);
        $center = new \App\Company\Entity\FinancialResponsibilityCenter($this->companyId, \App\Company\Entity\FinancialResponsibilityCenter::CODE_GENERAL, \App\Company\Entity\FinancialResponsibilityCenter::NAME_GENERAL);
        $this->em->persist($project);
        $this->em->persist($center);
        $this->em->persist(new \App\Company\Entity\FinancialResponsibilityCenterProject($this->companyId, $project, $center));
        $this->em->flush();
        $service = new \App\Cash\Service\Import\ClientBank1CImportService($container->get(\App\Cash\Application\Service\CashSettlementSourceLifecycle::class), $active, new \App\Company\Domain\Service\CounterpartyNameNormalizer(), $container->get(\App\Company\Facade\CounterpartyFacade::class), $container->get(\App\Cash\Repository\Transaction\CashTransactionRepository::class), $container->get(\App\Cash\Service\Import\ImportLogger::class), $this->em, $container->get(\App\Cash\Service\Accounts\AccountBalanceService::class), $container->get(\App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver::class), $container->get(\App\Cash\Infrastructure\Lock\CashImportAccountLock::class));

        return $service;
    }

    /** @return array<string, mixed> */
    private function bankRow(): array
    {
        return ['docType' => 'Платежное поручение', 'docNumber' => 'SETTLE-1', 'docDate' => '2026-11-01', 'amount' => '40000.00', 'payerName' => 'ООО Плательщик', 'payerInn' => '7701000000', 'payerAccount' => '40702810900000000003', 'receiverName' => 'ООО Получатель', 'receiverInn' => '7712000000', 'receiverAccount' => '40702810900000000004', 'dateDebit' => '2026-11-01', 'dateCredit' => null, 'purpose' => 'Оплата', 'direction' => 'outflow'];
    }

    public function testAllowedBankOverwritePreservesRecomputedDedupeHash(): void
    {
        $service = $this->bankImporter();
        $account = $this->cash->getMoneyAccount();
        $account->setAccountNumber('40702810900000000003');
        $this->em->flush();
        $row = $this->bankRow();
        $service->import([$row], $account, false, fn () => $this->em->flush());
        $cashId = (string) $this->connection->fetchOne('SELECT id FROM cash_transaction WHERE company_id = ? AND id <> ?', [$this->companyId, $this->cash->getId()]);
        $this->connection->executeStatement('UPDATE cash_transaction SET dedupe_hash = NULL WHERE company_id = ? AND id = ?', [$this->companyId, $cashId]);
        (self::getContainer()->get(RegisterCashSettlementAction::class))($this->companyId, $this->actorId, new RegisterCashSettlementInput(Uuid::uuid7()->toString(), $cashId, false, new \DateTimeImmutable('2026-11-01')));
        $container = self::getContainer();
        $import = new \App\Cash\Application\ImportBank1CStatementAction($service, $container->get(\App\Cash\Service\Import\ImportLogger::class), $container->get(\App\Cash\Infrastructure\Lock\CashImportLogLease::class), $this->em, $container->get(\App\Cash\Infrastructure\Lock\CashImportAccountLock::class));
        $import($this->company, [$row], $account, true, $this->actorId, 'fixture.txt', []);
        $hash = $this->connection->fetchOne('SELECT dedupe_hash FROM cash_transaction WHERE company_id = ? AND id = ?', [$this->companyId, $cashId]);
        self::assertIsString($hash);
        self::assertSame(64, strlen($hash));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cash_transaction WHERE company_id = ?', [$this->companyId]));
    }

    public function testBankAccountLockSurvivesServiceReturnUntilOuterCommit(): void
    {
        $service = $this->bankImporter();
        $otherConnection = \Doctrine\DBAL\DriverManager::getConnection($this->connection->getParams());
        $otherLock = new \App\Cash\Infrastructure\Lock\CashImportAccountLock($otherConnection);
        $account = $this->cash->getMoneyAccount();
        $accountId = (string) $account->getId();
        $proxy = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $proxy->method('flush')->willReturnCallback(fn () => $this->em->flush());
        $proxy->method('lock')->willReturnCallback(fn ($entity, $mode) => $this->em->lock($entity, $mode));
        $proxy->method('refresh')->willReturnCallback(fn ($entity) => $this->em->refresh($entity));
        $proxy->method('isOpen')->willReturnCallback(fn (): bool => $this->em->isOpen());
        $proxy->method('getConnection')->willReturn($this->connection);
        $proxy->method('wrapInTransaction')->willReturnCallback(function (callable $callback) use ($otherLock, $accountId): mixed {
            return $this->em->wrapInTransaction(function () use ($callback, $otherLock, $accountId): mixed {
                $result = $callback();
                // The actual outer commit has not happened yet; probe via another PG session.
                $acquired = $otherLock->tryAcquire($this->companyId, $accountId);
                if ($acquired) {
                    $otherLock->release($this->companyId, $accountId);
                }
                self::assertFalse($acquired, 'Another importer must not enter before commit.');

                return $result;
            });
        });
        $container = self::getContainer();
        $action = new \App\Cash\Application\ImportBank1CStatementAction($service, $container->get(\App\Cash\Service\Import\ImportLogger::class), $container->get(\App\Cash\Infrastructure\Lock\CashImportLogLease::class), $proxy, $container->get(\App\Cash\Infrastructure\Lock\CashImportAccountLock::class));
        try {
            $action($this->company, [], $account, false, $this->actorId, 'fixture.txt', []);
            self::assertTrue($otherLock->tryAcquire($this->companyId, $accountId), 'Lock must be released after commit.');
            $otherLock->release($this->companyId, $accountId);
        } finally {
            $otherConnection->close();
        }
    }
}

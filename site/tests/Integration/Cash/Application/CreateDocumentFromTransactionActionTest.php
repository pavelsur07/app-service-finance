<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\CreateDocumentFromTransactionAction;
use App\Cash\Application\CreatePnlDocumentFromTransactionAction;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CreateDocumentFromTransactionActionTest extends IntegrationTestCase
{
    private CreateDocumentFromTransactionAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = self::getContainer()->get(CreateDocumentFromTransactionAction::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCompany(): Company
    {
        $user = UserBuilder::aUser()
            ->withEmail(Uuid::uuid4()->toString().'@test.com')
            ->withPasswordHash('hash')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($user)
            ->build();

        $this->em->persist($user);
        $this->em->persist($company);

        return $company;
    }

    private function makeMoneyAccount(Company $company): MoneyAccount
    {
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->build();

        $this->em->persist($account);

        return $account;
    }

    private function makePLCategory(Company $company): PLCategory
    {
        $plCategory = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->build();

        $this->em->persist($plCategory);

        return $plCategory;
    }

    private function makeCashflowCategory(Company $company, ?PLCategory $plCategory = null): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Test Cashflow Category');
        $category->setAllowPlDocument(true);
        $category->setPlCategory($plCategory);

        $this->em->persist($category);

        return $category;
    }

    private function makeTx(
        Company $company,
        MoneyAccount $account,
        string $amount = '1000.00',
        ?CashflowCategory $cashflowCategory = null,
    ): CashTransaction {
        $tx = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->withAmount($amount)
            ->withDirection(CashDirection::OUTFLOW)
            ->withCashflowCategory($cashflowCategory)
            ->build();

        $this->em->persist($tx);

        return $tx;
    }

    /**
     * @return array{0: ProjectDirection, 1: FinancialResponsibilityCenter}
     */
    private function makeResponsibilityPair(Company $company): array
    {
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Продажа компьютеров');
        $center = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_KRD', 'Краснодар');

        $this->em->persist($project);
        $this->em->persist($center);
        $this->em->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $project, $center));

        return [$project, $center];
    }

    // -------------------------------------------------------------------------
    // Сценарий A — полный маппинг, создание без подтверждения
    // -------------------------------------------------------------------------

    public function testScenarioACreateDocumentWithFullMapping(): void
    {
        $company = $this->makeCompany();
        $account = $this->makeMoneyAccount($company);
        $plCategory = $this->makePLCategory($company);
        $cashflowCategory = $this->makeCashflowCategory($company, $plCategory);
        $tx = $this->makeTx($company, $account, '1000.00', $cashflowCategory);

        $this->em->flush();

        $result = ($this->action)($tx, confirmed: false);

        $this->assertFalse($result->needsConfirmation);
        $this->assertFalse($result->hasViolation);
        $this->assertNotNull($result->documentId);

        $this->em->clear();

        /** @var Document $doc */
        $doc = $this->em->find(Document::class, $result->documentId);
        $this->assertNotNull($doc);
        $this->assertFalse($doc->isCreatedWithViolation());

        /** @var DocumentOperation $op */
        $op = $doc->getOperations()->first();
        $this->assertInstanceOf(DocumentOperation::class, $op);
        $this->assertSame('1000.00', $op->getAmount());
        $this->assertSame($plCategory->getId(), $op->getCategory()?->getId());

        /** @var CashTransaction $txReloaded */
        $txReloaded = $this->em->find(CashTransaction::class, $tx->getId());
        $this->assertSame(1000.0, $txReloaded->getAllocatedAmount());
        $this->assertSame(0.0, $txReloaded->getRemainingAmount());
        $this->assertFalse($txReloaded->isHasViolatedDocument());
    }

    public function testCopiesResponsibilityCenterFromCashTransaction(): void
    {
        $company = $this->makeCompany();
        $account = $this->makeMoneyAccount($company);
        $plCategory = $this->makePLCategory($company);
        $cashflowCategory = $this->makeCashflowCategory($company, $plCategory);
        [$project, $center] = $this->makeResponsibilityPair($company);
        $tx = $this->makeTx($company, $account, '1000.00', $cashflowCategory);
        $tx
            ->setProjectDirection($project)
            ->setResponsibilityCenterId($center->getId());

        $this->em->flush();

        $result = ($this->action)($tx, confirmed: false);

        self::assertNotNull($result->documentId);

        $this->em->clear();

        /** @var Document $doc */
        $doc = $this->em->find(Document::class, $result->documentId);
        self::assertSame($project->getId(), $doc->getProjectDirection()?->getId());
        self::assertSame($center->getId(), $doc->getResponsibilityCenterId());

        /** @var DocumentOperation $op */
        $op = $doc->getOperations()->first();
        self::assertSame($project->getId(), $op->getProjectDirection()?->getId());
        self::assertSame($center->getId(), $op->getResponsibilityCenterId());
    }

    // -------------------------------------------------------------------------
    // Сценарий A — частичное разнесение (уже есть документ на 300)
    // -------------------------------------------------------------------------

    public function testScenarioAPartialAllocation(): void
    {
        $company = $this->makeCompany();
        $account = $this->makeMoneyAccount($company);
        $plCategory = $this->makePLCategory($company);
        $cashflowCategory = $this->makeCashflowCategory($company, $plCategory);

        $tx = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->withAmount('1000.00')
            ->withDirection(CashDirection::OUTFLOW)
            ->withCashflowCategory($cashflowCategory)
            ->build();

        $existingDocument = new Document(Uuid::uuid4()->toString(), $company);
        $existingOperation = new DocumentOperation(Uuid::uuid4()->toString());
        $existingOperation->setAmount('300.00');
        $existingDocument->addOperation($existingOperation);
        $tx->addDocument($existingDocument);

        $this->em->persist($tx);
        $this->em->persist($existingDocument);
        $this->em->flush();

        $result = ($this->action)($tx, confirmed: false);

        $this->assertNotNull($result->documentId);

        $this->em->clear();

        /** @var DocumentOperation $op */
        $doc = $this->em->find(Document::class, $result->documentId);
        $op = $doc->getOperations()->first();
        $this->assertSame('700.00', $op->getAmount());

        /** @var CashTransaction $txReloaded */
        $txReloaded = $this->em->find(CashTransaction::class, $tx->getId());
        $this->assertSame(1000.0, $txReloaded->getAllocatedAmount());
    }

    // -------------------------------------------------------------------------
    // Сценарий B, шаг 1 — нет PLCategory, confirmed=false → needsConfirmation
    // -------------------------------------------------------------------------

    public function testScenarioBStep1ReturnsNeedsConfirmation(): void
    {
        $company = $this->makeCompany();
        $account = $this->makeMoneyAccount($company);
        $cashflowCategory = $this->makeCashflowCategory($company, null); // без PLCategory
        $tx = $this->makeTx($company, $account, '1000.00', $cashflowCategory);

        $this->em->flush();

        $result = ($this->action)($tx, confirmed: false);

        $this->assertTrue($result->needsConfirmation);
        $this->assertNull($result->documentId);
        $this->assertNotEmpty($result->warningMessage);

        // allocatedAmount не изменился
        $this->em->clear();
        $txReloaded = $this->em->find(CashTransaction::class, $tx->getId());
        $this->assertSame(0.0, $txReloaded->getAllocatedAmount());
    }

    // -------------------------------------------------------------------------
    // Сценарий B, шаг 2 — нет PLCategory, confirmed=true → violation
    // -------------------------------------------------------------------------

    public function testScenarioBStep2CreateDocumentWithViolation(): void
    {
        $company = $this->makeCompany();
        $account = $this->makeMoneyAccount($company);
        $cashflowCategory = $this->makeCashflowCategory($company, null);
        $tx = $this->makeTx($company, $account, '1000.00', $cashflowCategory);

        $this->em->flush();

        $result = ($this->action)($tx, confirmed: true);

        $this->assertFalse($result->needsConfirmation);
        $this->assertTrue($result->hasViolation);
        $this->assertNotNull($result->documentId);

        $this->em->clear();

        /** @var Document $doc */
        $doc = $this->em->find(Document::class, $result->documentId);
        $this->assertNotNull($doc);
        $this->assertTrue($doc->isCreatedWithViolation());

        /** @var DocumentOperation $op */
        $op = $doc->getOperations()->first();
        $this->assertNull($op->getCategory());

        /** @var CashTransaction $txReloaded */
        $txReloaded = $this->em->find(CashTransaction::class, $tx->getId());
        $this->assertTrue($txReloaded->isHasViolatedDocument());
        $this->assertSame(1000.0, $txReloaded->getAllocatedAmount());
    }

    // -------------------------------------------------------------------------
    // Сценарий B, шаг 2 — нет cashflowCategory вообще, confirmed=true → violation
    // -------------------------------------------------------------------------

    public function testScenarioBStep2NoCashflowCategory(): void
    {
        $company = $this->makeCompany();
        $account = $this->makeMoneyAccount($company);
        $tx = $this->makeTx($company, $account, '1000.00', null); // без категории

        $this->em->flush();

        $result = ($this->action)($tx, confirmed: true);

        $this->assertFalse($result->needsConfirmation);
        $this->assertTrue($result->hasViolation);
        $this->assertNotNull($result->documentId);

        $this->em->clear();

        $doc = $this->em->find(Document::class, $result->documentId);
        $this->assertTrue($doc->isCreatedWithViolation());

        $txReloaded = $this->em->find(CashTransaction::class, $tx->getId());
        $this->assertTrue($txReloaded->isHasViolatedDocument());
        $this->assertSame(1000.0, $txReloaded->getAllocatedAmount());
    }

    // -------------------------------------------------------------------------
    // Исключения
    // -------------------------------------------------------------------------

    public function testThrowsOnTransfer(): void
    {
        $company = $this->makeCompany();
        $account = $this->makeMoneyAccount($company);

        $tx = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->asTransfer()
            ->build();

        $this->em->persist($tx);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Для переводов нельзя создать документ ОПиУ.');

        ($this->action)($tx, confirmed: false);
    }

    public function testThrowsOnZeroRemainingAmount(): void
    {
        $company = $this->makeCompany();
        $account = $this->makeMoneyAccount($company);

        $tx = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->withAmount('1000.00')
            ->build();

        $document = new Document(Uuid::uuid4()->toString(), $company);
        $operation = new DocumentOperation(Uuid::uuid4()->toString());
        $operation->setAmount('1000.00');
        $document->addOperation($operation);
        $tx->addDocument($document);

        $this->em->persist($tx);
        $this->em->persist($document);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Транзакция уже полностью разнесена.');

        ($this->action)($tx, confirmed: false);
    }
    // -------------------------------------------------------------------------
    // Параллельный запрос: документ на весь остаток уже создан другим запросом
    // -------------------------------------------------------------------------

    public function testSeesDocumentCreatedByConcurrentRequest(): void
    {
        [$tx, $company] = $this->makeLoadedTxWithConcurrentDocument();

        try {
            ($this->action)($tx, confirmed: false);
            self::fail('Второй документ на тот же остаток создан.');
        } catch (\DomainException $exception) {
            self::assertSame('Транзакция уже полностью разнесена.', $exception->getMessage());
        }

        self::assertSame(1, $this->countDocuments((string) $tx->getId()));
    }

    public function testPnlActionSeesDocumentCreatedByConcurrentRequest(): void
    {
        [$tx] = $this->makeLoadedTxWithConcurrentDocument();

        try {
            self::getContainer()->get(CreatePnlDocumentFromTransactionAction::class)($tx);
            self::fail('Второй документ на тот же остаток создан.');
        } catch (\DomainException $exception) {
            // Остаток под блокировкой — 0: документ на нулевую сумму не создаётся.
            self::assertSame('Сумма документа должна быть больше нуля.', $exception->getMessage());
        }

        self::assertSame(1, $this->countDocuments((string) $tx->getId()));
    }

    public function testDocumentAndRegisterAreCommittedTogether(): void
    {
        $company = $this->makeCompany();
        $account = $this->makeMoneyAccount($company);
        $plCategory = $this->makePLCategory($company);
        $cashflowCategory = $this->makeCashflowCategory($company, $plCategory);
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Основной');
        $this->em->persist($project);
        $tx = $this->makeTx($company, $account, '1000.00', $cashflowCategory);
        $tx->setProjectDirection($project);
        $this->em->flush();

        $result = ($this->action)($tx, confirmed: false);

        self::assertNotNull($result->documentId);
        self::assertSame('1000.00', $this->connection->fetchOne(
            'SELECT SUM(amount_income + amount_expense) FROM pl_daily_totals WHERE company_id = :company',
            ['company' => $company->getId()],
        ));
    }

    /**
     * Транзакция на 1000 загружена вместе с коллекцией документов, после чего «другой запрос»
     * создаёт документ на весь остаток в обход этого EntityManager.
     *
     * @return array{0: CashTransaction, 1: Company}
     */
    private function makeLoadedTxWithConcurrentDocument(): array
    {
        $company = $this->makeCompany();
        $account = $this->makeMoneyAccount($company);
        $plCategory = $this->makePLCategory($company);
        $cashflowCategory = $this->makeCashflowCategory($company, $plCategory);
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Основной');
        $this->em->persist($project);
        $tx = $this->makeTx($company, $account, '1000.00', $cashflowCategory);
        $tx->setProjectDirection($project);
        $this->em->flush();

        self::assertTrue($tx->getRemainingMoney()->isPositive());

        $documentId = Uuid::uuid4()->toString();
        $this->connection->insert('documents', [
            'id' => $documentId,
            'company_id' => $company->getId(),
            'date' => $tx->getOccurredAt()->format('Y-m-d 00:00:00'),
            'type' => 'CASHFLOW_EXPENSE',
            'status' => 'ACTIVE',
            'cash_transaction_id' => $tx->getId(),
        ]);
        $this->connection->insert('document_operations', [
            'id' => Uuid::uuid4()->toString(),
            'document_id' => $documentId,
            'amount' => '1000.00',
            'category_id' => $plCategory->getId(),
        ]);

        return [$tx, $company];
    }

    private function countDocuments(string $transactionId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE cash_transaction_id = :tx AND deleted_at IS NULL',
            ['tx' => $transactionId],
        );
    }
}

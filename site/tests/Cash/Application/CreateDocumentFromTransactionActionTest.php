<?php

declare(strict_types=1);

namespace App\Tests\Cash\Application;

use App\Cash\Application\CreateDocumentFromTransactionAction;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
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
            ->withEmail(Uuid::uuid4()->toString() . '@test.com')
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

    // -------------------------------------------------------------------------
    // Сценарий A — полный маппинг, создание без подтверждения
    // -------------------------------------------------------------------------

    public function testScenarioA_createDocumentWithFullMapping(): void
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

    // -------------------------------------------------------------------------
    // Сценарий A — частичное разнесение (уже есть документ на 300)
    // -------------------------------------------------------------------------

    public function testScenarioA_partialAllocation(): void
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
            ->withAllocatedAmount('300.00')
            ->withDirection(CashDirection::OUTFLOW)
            ->withCashflowCategory($cashflowCategory)
            ->build();

        $this->em->persist($tx);
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

    public function testScenarioB_step1_returnsNeedsConfirmation(): void
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

        // Документ не создан
        $count = $this->em->getRepository(Document::class)->count([]);
        $this->assertSame(0, $count);

        // allocatedAmount не изменился
        $this->em->clear();
        $txReloaded = $this->em->find(CashTransaction::class, $tx->getId());
        $this->assertSame(0.0, $txReloaded->getAllocatedAmount());
    }

    // -------------------------------------------------------------------------
    // Сценарий B, шаг 2 — нет PLCategory, confirmed=true → violation
    // -------------------------------------------------------------------------

    public function testScenarioB_step2_createDocumentWithViolation(): void
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

    public function testScenarioB_step2_noCashflowCategory(): void
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
            ->withAllocatedAmount('1000.00')
            ->build();

        $this->em->persist($tx);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Транзакция уже полностью разнесена.');

        ($this->action)($tx, confirmed: false);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Регрессия для production-инцидента март 2026, company b57d7682-…:
 * pl_daily_totals удваивал storno-операции marketplace_pl, потому что
 * PLRegisterUpdater::aggregateDocuments() применял abs() ко всем суммам,
 * не учитывая знаковую семантику документов type=marketplace_pl.
 *
 * Δ = 2 × |положительные операции| по EXPENSE-категориям с storno.
 *
 * Тесты фиксируют:
 *  - для marketplace_pl используется знаковая семантика (charge -X / storno +X
 *    / sale +X / return -X);
 *  - для legacy типов (CASHFLOW_EXPENSE, TAXES, LOANS, PAYROLL и др.)
 *    сохраняется old-school abs()-семантика (amount всегда положительный,
 *    направление = категория.flow).
 */
final class PLRegisterUpdaterStornoSymmetryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000099';
    private const USER_ID = '22222222-2222-2222-2222-000000000099';
    private const PROJECT_ID = '55555555-5555-5555-5555-000000000099';

    private PLRegisterUpdater $updater;
    private Company $company;
    private ProjectDirection $project;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId(self::USER_ID)
            ->withEmail('storno-symmetry@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->withName('Storno Symmetry Co.')
            ->build();

        $this->project = new ProjectDirection(self::PROJECT_ID, $this->company, 'Default project');

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->persist($this->project);
        $this->em->flush();

        $this->updater = self::getContainer()->get(PLRegisterUpdater::class);
    }

    public function testMarketplacePlStornoReducesExpense(): void
    {
        $category = $this->createCategory('COGS_MP_COMMISSION', PLFlow::EXPENSE);
        $document = $this->createDocument(DocumentType::MARKETPLACE_PL, '2026-03-15');
        $this->addOperation($document, $category, '-4006358.98');
        $this->addOperation($document, $category, '11022.05');

        $this->em->persist($document);
        $this->em->flush();

        $this->updater->updateForDocument($document);

        $row = $this->fetchTotal($category);

        self::assertSame('0.00', $row['amount_income']);
        self::assertEqualsWithDelta(3995336.93, (float) $row['amount_expense'], 0.005);
    }

    public function testCashflowExpenseLegacySemanticsPreserved(): void
    {
        $category = $this->createCategory('OVERHEAD_ADMIN_PAYROLL', PLFlow::EXPENSE);
        $document = $this->createDocument(DocumentType::CASHFLOW_EXPENSE, '2026-03-20');
        $this->addOperation($document, $category, '241800.00');

        $this->em->persist($document);
        $this->em->flush();

        $this->updater->updateForDocument($document);

        $row = $this->fetchTotal($category);

        self::assertSame('0.00', $row['amount_income']);
        self::assertEqualsWithDelta(241800.00, (float) $row['amount_expense'], 0.005);
    }

    public function testMarketplacePlIncomeWithReturn(): void
    {
        $category = $this->createCategory('REVENUE_MP_SALES', PLFlow::INCOME);
        $document = $this->createDocument(DocumentType::MARKETPLACE_PL, '2026-03-10');
        $this->addOperation($document, $category, '5032223.29');
        $this->addOperation($document, $category, '-12509.17');

        $this->em->persist($document);
        $this->em->flush();

        $this->updater->updateForDocument($document);

        $row = $this->fetchTotal($category);

        self::assertEqualsWithDelta(5019714.12, (float) $row['amount_income'], 0.005);
        self::assertSame('0.00', $row['amount_expense']);
    }

    public function testMarketplacePlPositiveExpenseCategory(): void
    {
        // REV_SPP_RETURNS-аналог: marketplace_pl + EXPENSE-категория, но операция
        // приходит с положительным amount (is_negative=false в потоке storno-бакетов).
        // До фикса инверсия per-operation давала expense=-12509.17 → SQLSTATE 23514
        // на CHECK chk_pl_daily_totals_amounts. Сейчас abs() после агрегации.
        $category = $this->createCategory('REV_SPP_RETURNS', PLFlow::EXPENSE);
        $document = $this->createDocument(DocumentType::MARKETPLACE_PL, '2026-03-22');
        $this->addOperation($document, $category, '12509.17');

        $this->em->persist($document);
        $this->em->flush();

        $this->updater->updateForDocument($document);

        $row = $this->fetchTotal($category);

        self::assertSame('0.00', $row['amount_income']);
        self::assertEqualsWithDelta(12509.17, (float) $row['amount_expense'], 0.005);
    }

    public function testNetReportValueMatchesDocumentOperationsSumForMarketplace(): void
    {
        $category = $this->createCategory('COGS_MP_COMMISSION_NET', PLFlow::EXPENSE);
        $document = $this->createDocument(DocumentType::MARKETPLACE_PL, '2026-03-31');
        $this->addOperation($document, $category, '-4006355.98');
        $this->addOperation($document, $category, '11022.05');
        $this->addOperation($document, $category, '-3.00');

        $this->em->persist($document);
        $this->em->flush();

        $this->updater->updateForDocument($document);

        $row = $this->fetchTotal($category);

        $netReport = (float) $row['amount_income'] - (float) $row['amount_expense'];

        $opsSum = (float) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM document_operations WHERE document_id = :doc',
            ['doc' => $document->getId()],
        );

        self::assertEqualsWithDelta(-3995336.93, $netReport, 0.005);
        self::assertEqualsWithDelta(-3995336.93, $opsSum, 0.005);
        self::assertEqualsWithDelta(0.0, abs($netReport - $opsSum), 0.005);
    }

    private function createCategory(string $code, PLFlow $flow): PLCategory
    {
        $category = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $category->setName($code);
        $category->setCode($code);
        $category->setFlow($flow);

        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function createDocument(DocumentType $type, string $date): Document
    {
        $document = new Document(Uuid::uuid4()->toString(), $this->company);
        $document->setType($type);
        $document->setStatus(DocumentStatus::ACTIVE);
        $document->setDate(new \DateTimeImmutable($date));
        $document->setProjectDirection($this->project);

        return $document;
    }

    private function addOperation(Document $document, PLCategory $category, string $amount): void
    {
        $operation = new DocumentOperation();
        $operation->setPlCategory($category);
        $operation->setProjectDirection($this->project);
        $operation->setAmount($amount);

        $document->addOperation($operation);
    }

    /**
     * @return array{amount_income: string, amount_expense: string}
     */
    private function fetchTotal(PLCategory $category): array
    {
        /** @var Connection $connection */
        $connection = $this->em->getConnection();

        $row = $connection->fetchAssociative(
            'SELECT amount_income, amount_expense
             FROM pl_daily_totals
             WHERE company_id = :company AND pl_category_id = :category',
            [
                'company' => $this->company->getId(),
                'category' => $category->getId(),
            ],
        );

        self::assertIsArray($row, 'pl_daily_totals row must exist for category ' . (string) $category->getId());

        return [
            'amount_income' => (string) $row['amount_income'],
            'amount_expense' => (string) $row['amount_expense'],
        ];
    }
}

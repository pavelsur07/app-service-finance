<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Application\CreateManualDocumentAction;
use App\Finance\Application\UpdateManualDocumentAction;
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

/**
 * Ручной документ ОПиУ из формы: документ, распределённая сумма транзакции ДДС и регистр — один
 * commit; правка проверяет остаток транзакции под блокировкой по свежей коллекции документов.
 */
final class ManualDocumentActionsTest extends IntegrationTestCase
{
    private Company $company;
    private ProjectDirection $project;
    private PLCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(Uuid::uuid4()->toString().'@test.com')
            ->withPasswordHash('hash')
            ->build();
        $this->company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($user)
            ->build();
        $this->project = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'Основной');
        $this->category = (new PLCategory(Uuid::uuid4()->toString(), $this->company))
            ->setName('Расходы')
            ->setFlow(PLFlow::EXPENSE);

        foreach ([$user, $this->company, $this->project, $this->category] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testCreateCommitsDocumentWithRegister(): void
    {
        $document = $this->makeDocument('2026-03-05', '250.00');

        self::getContainer()->get(CreateManualDocumentAction::class)($document);

        self::assertSame(1, $this->countDocuments());
        self::assertSame(['2026-03-05' => '250.00'], $this->registerExpenseByDay());
    }

    public function testCreateRollsBackDocumentWhenRegisterFails(): void
    {
        $this->failRegisterInserts();
        $document = $this->makeDocument('2026-03-05', '250.00');

        try {
            self::getContainer()->get(CreateManualDocumentAction::class)($document);
            self::fail('Сбой регистра не дошёл до вызывающего.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('pl register is down', $exception->getMessage());
        }

        self::assertSame(0, $this->countDocuments());
    }

    public function testUpdateMovesRegisterToNewDay(): void
    {
        $document = $this->makeDocument('2026-03-05', '250.00');
        self::getContainer()->get(CreateManualDocumentAction::class)($document);

        $initialStatus = $document->getStatus();
        $initialDate = $document->getDate();
        $document->setDate(new \DateTimeImmutable('2026-03-07'));

        self::getContainer()->get(UpdateManualDocumentAction::class)($document, $initialStatus, $initialDate);

        self::assertSame(['2026-03-07' => '250.00'], $this->registerExpenseByDay());
    }

    public function testUpdateSeesDocumentCreatedByConcurrentRequest(): void
    {
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($this->company)
            ->build();
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($this->company)
            ->withMoneyAccount($account)
            ->withAmount('1000.00')
            ->withDirection(CashDirection::OUTFLOW)
            ->build();
        $this->em->persist($account);
        $this->em->persist($transaction);

        $document = $this->makeDocument('2026-03-05', '400.00');
        $transaction->addDocument($document);
        self::getContainer()->get(CreateManualDocumentAction::class)($document);

        // Коллекция документов транзакции загружена — как в форме правки до отправки.
        self::assertCount(1, $transaction->getDocuments());
        $this->insertConcurrentDocument($transaction, '500.00');

        $operation = $document->getOperations()->first();
        self::assertInstanceOf(DocumentOperation::class, $operation);
        $operation->setAmount('600.00');

        try {
            self::getContainer()->get(UpdateManualDocumentAction::class)(
                $document,
                DocumentStatus::ACTIVE,
                $document->getDate(),
            );
            self::fail('Документ превысил остаток транзакции ДДС, распределённый параллельным запросом.');
        } catch (\DomainException) {
        }

        self::assertSame('400.00', $this->connection->fetchOne(
            'SELECT SUM(amount) FROM document_operations WHERE document_id = :id',
            ['id' => $document->getId()],
        ));
        self::assertSame(['2026-03-05' => '400.00'], $this->registerExpenseByDay());
    }

    private function makeDocument(string $day, string $amount): Document
    {
        $document = new Document(Uuid::uuid4()->toString(), $this->company);
        $document->setStatus(DocumentStatus::ACTIVE);
        $document->setDate(new \DateTimeImmutable($day));
        $document->setType(DocumentType::OTHER);
        $document->setProjectDirection($this->project);

        $operation = new DocumentOperation();
        $operation->setPlCategory($this->category);
        $operation->setProjectDirection($this->project);
        $operation->setAmount($amount);
        $document->addOperation($operation);

        return $document;
    }

    private function insertConcurrentDocument(CashTransaction $transaction, string $amount): void
    {
        $documentId = Uuid::uuid4()->toString();
        $this->connection->insert('documents', [
            'id' => $documentId,
            'company_id' => $this->company->getId(),
            'date' => '2026-03-06 00:00:00',
            'type' => 'CASHFLOW_EXPENSE',
            'status' => 'ACTIVE',
            'cash_transaction_id' => $transaction->getId(),
        ]);
        $this->connection->insert('document_operations', [
            'id' => Uuid::uuid4()->toString(),
            'document_id' => $documentId,
            'amount' => $amount,
            'category_id' => $this->category->getId(),
        ]);
    }

    /**
     * Триггер живёт в транзакции теста и откатывается вместе с ней.
     */
    private function failRegisterInserts(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE FUNCTION pg_temp.fail_pl_register() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'pl register is down';
            END
            $$
            SQL);
        $this->connection->executeStatement(
            'CREATE TRIGGER fail_pl_register BEFORE INSERT ON pl_daily_totals FOR EACH ROW EXECUTE FUNCTION pg_temp.fail_pl_register()',
        );
    }

    private function countDocuments(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :company',
            ['company' => $this->company->getId()],
        );
    }

    /**
     * @return array<string, string>
     */
    private function registerExpenseByDay(): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT TO_CHAR(date, \'YYYY-MM-DD\'), SUM(amount_expense)::text FROM pl_daily_totals WHERE company_id = :company GROUP BY date HAVING SUM(amount_expense) <> 0 ORDER BY date',
            ['company' => $this->company->getId()],
        );

        return $rows;
    }
}

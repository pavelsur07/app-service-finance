<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\ProjectDirection;
use App\Finance\Application\DeletePLDocumentAction;
use App\Finance\Application\RestoreDocumentAction;
use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Application\SoftDeleteDocumentAction;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Finance\Repository\DocumentRepository;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class DocumentSoftDeleteActionTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000009101';
    private const USER_ID = '22222222-2222-2222-2222-000000009101';

    private Document $document;
    private CashTransaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $user = UserBuilder::aUser()
            ->withId(self::USER_ID)
            ->withEmail('pnl-soft-delete@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($user)
            ->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId('33333333-3333-3333-3333-000000009101')
            ->forCompany($company)
            ->build();

        $this->transaction = new CashTransaction(
            '44444444-4444-4444-4444-000000009101',
            $company,
            $account,
            CashDirection::OUTFLOW,
            '100.00',
            'RUB',
            new \DateTimeImmutable('2026-08-01'),
        );
        $this->document = $this->createDocument($company, '100.00');
        $this->transaction->addDocument($this->document);

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->persist($this->transaction);
        $this->em->persist($this->document);
        $this->em->flush();
    }

    public function testSoftDeleteAndRestoreKeepDocumentAndOperations(): void
    {
        $this->softDeleteAction()(
            self::COMPANY_ID,
            (string) $this->document->getId(),
            self::USER_ID,
            'manual-ui',
        );

        self::assertTrue($this->document->isDeleted());
        self::assertSame(self::USER_ID, $this->document->getDeletedBy());
        self::assertSame('manual-ui', $this->document->getDeleteReason());
        self::assertSame(0.0, $this->transaction->getAllocatedAmount());
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM document_operations WHERE document_id = :documentId',
            ['documentId' => $this->document->getId()],
        ));

        $this->restoreAction()(self::COMPANY_ID, (string) $this->document->getId());

        self::assertFalse($this->document->isDeleted());
        self::assertNull($this->document->getDeletedBy());
        self::assertNull($this->document->getDeleteReason());
        self::assertSame(100.0, $this->transaction->getAllocatedAmount());
    }

    public function testRestoreRejectsOverAllocationAndKeepsDocumentDeleted(): void
    {
        $this->softDeleteAction()(self::COMPANY_ID, (string) $this->document->getId());

        $replacement = $this->createDocument($this->document->getCompany(), '100.00');
        $this->transaction->addDocument($replacement);
        $this->em->persist($replacement);
        $this->em->flush();

        try {
            $this->restoreAction()(self::COMPANY_ID, (string) $this->document->getId());
            self::fail('Expected restore to reject an over-allocated cash transaction.');
        } catch (\DomainException $exception) {
            self::assertSame('Сумма документа превышает доступный остаток транзакции ДДС.', $exception->getMessage());
        }

        self::assertTrue($this->document->isDeleted());
        self::assertSame(100.0, $this->transaction->getAllocatedAmount());
    }

    public function testSoftDeleteAndRestoreRemoveAndReturnRegisterTotals(): void
    {
        $project = new ProjectDirection(
            '55555555-5555-5555-5555-000000009101',
            $this->document->getCompany(),
            'P&L project',
        );
        $category = new PLCategory(
            '66666666-6666-6666-6666-000000009101',
            $this->document->getCompany(),
        );
        $category->setName('P&L expense');
        $category->setFlow(PLFlow::EXPENSE);
        $this->document->setProjectDirection($project);
        $operation = $this->document->getOperations()->first();
        self::assertInstanceOf(DocumentOperation::class, $operation);
        $operation->setCategory($category);

        $this->em->persist($project);
        $this->em->persist($category);
        $this->em->flush();
        $this->plRegisterUpdater()->updateForDocument($this->document);

        self::assertSame(1, $this->countRegisterRows());

        $this->softDeleteAction()(self::COMPANY_ID, (string) $this->document->getId());

        self::assertSame(0, $this->countRegisterRows());

        $this->restoreAction()(self::COMPANY_ID, (string) $this->document->getId());

        self::assertSame(1, $this->countRegisterRows());
    }

    public function testSoftDeleteIsTenantSafe(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Документ ОПиУ не найден.');

        $this->softDeleteAction()(
            '11111111-1111-1111-1111-000000009199',
            (string) $this->document->getId(),
        );
    }

    public function testTechnicalDeleteRemainsPhysical(): void
    {
        $documentId = (string) $this->document->getId();

        $this->deleteAction()(self::COMPANY_ID, $documentId);

        self::assertNull($this->em->find(Document::class, $documentId));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM document_operations WHERE document_id = :documentId',
            ['documentId' => $documentId],
        ));
    }

    private function createDocument(\App\Company\Entity\Company $company, string $amount): Document
    {
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setDate(new \DateTimeImmutable('2026-08-01'));

        $operation = new DocumentOperation();
        $operation->setAmount($amount);
        $document->addOperation($operation);

        return $document;
    }

    private function softDeleteAction(): SoftDeleteDocumentAction
    {
        return new SoftDeleteDocumentAction(
            self::getContainer()->get(DocumentRepository::class),
            $this->em,
            self::getContainer()->get(PLRegisterUpdater::class),
        );
    }

    private function restoreAction(): RestoreDocumentAction
    {
        return new RestoreDocumentAction(
            self::getContainer()->get(DocumentRepository::class),
            $this->em,
            self::getContainer()->get(PLRegisterUpdater::class),
        );
    }

    private function deleteAction(): DeletePLDocumentAction
    {
        return self::getContainer()->get(DeletePLDocumentAction::class);
    }

    private function plRegisterUpdater(): PLRegisterUpdater
    {
        return self::getContainer()->get(PLRegisterUpdater::class);
    }

    private function countRegisterRows(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM pl_daily_totals WHERE company_id = :companyId',
            ['companyId' => self::COMPANY_ID],
        );
    }
}

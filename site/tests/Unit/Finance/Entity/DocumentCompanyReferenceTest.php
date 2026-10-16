<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Entity;

use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Exception\DocumentForeignReferenceException;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Справочники документа ОПиУ и его операций — только компании документа: FK компанию не
 * несут, инвариант держит сущность на каждом пути записи (форма, Cash, маркетплейсы, импорт).
 */
final class DocumentCompanyReferenceTest extends TestCase
{
    private Company $own;
    private Company $foreign;

    protected function setUp(): void
    {
        $this->own = CompanyBuilder::aCompany()->withIndex(1)->build();
        $this->foreign = CompanyBuilder::aCompany()->withIndex(2)->build();
    }

    public function testDocumentAcceptsOwnReferencesAndNull(): void
    {
        $document = $this->document();
        $transaction = CashTransactionBuilder::aCashTransaction()->withId(Uuid::uuid4()->toString())->forCompany($this->own)->build();

        $document->setCounterparty($this->counterparty($this->own));
        $document->setProjectDirection($this->project($this->own));
        $document->setCashTransaction($transaction);
        self::assertSame($transaction, $document->getCashTransaction());

        $document->setCounterparty(null)->setProjectDirection(null)->setCashTransaction(null);
        self::assertNull($document->getCounterparty());
    }

    public function testSameCompanyAsAnotherInstanceIsAccepted(): void
    {
        // Прокси Doctrine и загруженный объект — разные экземпляры одной компании.
        $sameCompany = CompanyBuilder::aCompany()->withId((string) $this->own->getId())->build();

        $this->document()->setCounterparty($this->counterparty($sameCompany));
        $this->addToAssertionCount(1);
    }

    public function testDocumentRejectsForeignCounterparty(): void
    {
        $this->expectForeign('counterparty');
        $this->document()->setCounterparty($this->counterparty($this->foreign));
    }

    public function testDocumentRejectsForeignProject(): void
    {
        $this->expectForeign('project direction');
        $this->document()->setProjectDirection($this->project($this->foreign));
    }

    public function testDocumentRejectsForeignCashTransaction(): void
    {
        $this->expectForeign('cash transaction');
        $this->document()->setCashTransaction(
            CashTransactionBuilder::aCashTransaction()->withId(Uuid::uuid4()->toString())->forCompany($this->foreign)->build(),
        );
    }

    public function testAttachedOperationRejectsForeignCategoryCounterpartyAndProject(): void
    {
        $document = $this->document();
        $operation = new DocumentOperation();
        $document->addOperation($operation);

        $operation->setCategory($this->category($this->own));
        $operation->setCounterparty($this->counterparty($this->own));
        $operation->setProjectDirection($this->project($this->own));

        foreach ([
            'P&L category' => fn () => $operation->setCategory($this->category($this->foreign)),
            'counterparty' => fn () => $operation->setCounterparty($this->counterparty($this->foreign)),
            'project direction' => fn () => $operation->setProjectDirection($this->project($this->foreign)),
        ] as $reference => $set) {
            try {
                $set();
                self::fail('Чужой справочник принят: '.$reference);
            } catch (DocumentForeignReferenceException $exception) {
                self::assertSame($reference, $exception->getReference());
            }
        }

        self::assertSame($this->own->getId(), $operation->getPlCategory()?->getCompany()->getId(), 'Отказ не меняет операцию.');
    }

    /**
     * Форма и импорт заполняют операцию до привязки: проверка — при addOperation.
     */
    public function testOperationFilledBeforeAttachIsCheckedOnAddOperation(): void
    {
        $document = $this->document();
        $operation = (new DocumentOperation())->setCategory($this->category($this->foreign));

        try {
            $document->addOperation($operation);
            self::fail('Операция с чужой статьёй привязана к документу.');
        } catch (DocumentForeignReferenceException $exception) {
            self::assertSame('P&L category', $exception->getReference());
        }

        self::assertCount(0, $document->getOperations());
        self::assertNull($operation->getDocument());
    }

    public function testSetDocumentDirectlyIsCheckedToo(): void
    {
        $this->expectForeign('project direction');
        (new DocumentOperation())->setProjectDirection($this->project($this->foreign))->setDocument($this->document());
    }

    public function testChangingCompanyRechecksReferences(): void
    {
        $document = $this->document();
        $operation = (new DocumentOperation())->setCategory($this->category($this->own));
        $document->addOperation($operation);

        try {
            $document->setCompany($this->foreign);
            self::fail('Документ сменил компанию со статьёй прежней компании.');
        } catch (DocumentForeignReferenceException $exception) {
            self::assertSame('P&L category', $exception->getReference());
        }

        self::assertSame($this->own, $document->getCompany(), 'При отказе компания документа прежняя.');
    }

    private function expectForeign(string $reference): void
    {
        $this->expectException(DocumentForeignReferenceException::class);
        $this->expectExceptionMessage(sprintf('reference %s "', $reference));
    }

    private function document(): Document
    {
        return new Document(Uuid::uuid4()->toString(), $this->own);
    }

    private function counterparty(Company $company): Counterparty
    {
        return CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($company)->withName('Контрагент')->build();
    }

    private function project(Company $company): ProjectDirection
    {
        return new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Проект');
    }

    private function category(Company $company): PLCategory
    {
        return PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Статья')->build();
    }
}

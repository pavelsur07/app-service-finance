<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Entity;

use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Правило «один документ ОПиУ — один контрагент»: контрагент операции — всегда контрагент шапки.
 */
final class DocumentCounterpartyRuleTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->build();
    }

    public function testOperationHasNoOwnCounterpartySetter(): void
    {
        self::assertFalse(method_exists(DocumentOperation::class, 'setCounterparty'));
    }

    public function testAddedOperationTakesHeaderCounterparty(): void
    {
        $counterparty = $this->counterparty();
        $document = $this->document()->setCounterparty($counterparty);

        $operation = new DocumentOperation();
        $document->addOperation($operation);

        self::assertSame($counterparty, $operation->getCounterparty());
    }

    public function testDirectlyAttachedOperationTakesHeaderCounterparty(): void
    {
        $counterparty = $this->counterparty();
        $document = $this->document()->setCounterparty($counterparty);

        $operation = (new DocumentOperation())->setDocument($document);

        self::assertSame($counterparty, $operation->getCounterparty());
    }

    public function testHeaderChangeIsCarriedToEveryOperation(): void
    {
        $document = $this->document()->setCounterparty($this->counterparty());
        $first = new DocumentOperation();
        $second = new DocumentOperation();
        $document->addOperation($first)->addOperation($second);

        $replacement = $this->counterparty();
        $document->setCounterparty($replacement);

        self::assertSame($replacement, $first->getCounterparty());
        self::assertSame($replacement, $second->getCounterparty());
    }

    public function testHeaderResetClearsOperations(): void
    {
        $document = $this->document()->setCounterparty($this->counterparty());
        $operation = new DocumentOperation();
        $document->addOperation($operation);

        $document->setCounterparty(null);

        self::assertNull($operation->getCounterparty());
    }

    public function testOperationMovedToAnotherDocumentTakesItsCounterparty(): void
    {
        $operation = new DocumentOperation();
        $this->document()->setCounterparty($this->counterparty())->addOperation($operation);

        $target = $this->counterparty();
        $this->document()->setCounterparty($target)->addOperation($operation);

        self::assertSame($target, $operation->getCounterparty());
    }

    public function testInheritWithoutDocumentChangesNothing(): void
    {
        $operation = new DocumentOperation();
        $operation->inheritCounterparty();

        self::assertNull($operation->getCounterparty());
    }

    private function document(): Document
    {
        return new Document(Uuid::uuid4()->toString(), $this->company);
    }

    private function counterparty(): Counterparty
    {
        return CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($this->company)->withName('Контрагент')->build();
    }
}

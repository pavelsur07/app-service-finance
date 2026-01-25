<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Company\Enum\CounterpartyType;
use App\Company\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\Document;
use App\Company\Entity\User;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class DocumentTest extends TestCase
{
    public function testCounterpartyIsNullByDefault(): void
    {
        $document = $this->createDocument();

        self::assertNull($document->getCounterparty());
    }

    public function testCounterpartyCanBeAssignedAndCleared(): void
    {
        $document = $this->createDocument();
        $counterparty = new Counterparty(Uuid::uuid4()->toString(), $document->getCompany(), 'Test', CounterpartyType::LEGAL_ENTITY);

        $document->setCounterparty($counterparty);

        self::assertSame($counterparty, $document->getCounterparty());

        $document->setCounterparty(null);

        self::assertNull($document->getCounterparty());
    }

    private function createDocument(): Document
    {
        $user = new User(Uuid::uuid4()->toString());
        $company = new Company(Uuid::uuid4()->toString(), $user);

        return new Document(Uuid::uuid4()->toString(), $company);
    }
}

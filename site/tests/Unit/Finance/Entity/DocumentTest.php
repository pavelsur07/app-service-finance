<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Entity;

use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\User;
use App\Company\Enum\CounterpartyType;
use App\Finance\Entity\Document;
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
        $counterparty = new Counterparty(Uuid::uuid4()->toString(), $document->getCompany(), (new CounterpartyNameNormalizer())->normalize('Test'), CounterpartyType::LEGAL_ENTITY);

        $document->setCounterparty($counterparty);

        self::assertSame($counterparty, $document->getCounterparty());

        $document->setCounterparty(null);

        self::assertNull($document->getCounterparty());
    }

    public function testSoftDeleteLifecycleIsIdempotent(): void
    {
        $document = $this->createDocument();

        $document->markDeleted('user-id', 'manual');
        $deletedAt = $document->getDeletedAt();

        self::assertTrue($document->isDeleted());
        self::assertInstanceOf(\DateTimeImmutable::class, $deletedAt);
        self::assertSame('user-id', $document->getDeletedBy());
        self::assertSame('manual', $document->getDeleteReason());

        $document->markDeleted('other-user', 'other-reason');

        self::assertSame($deletedAt, $document->getDeletedAt());
        self::assertSame('user-id', $document->getDeletedBy());
        self::assertSame('manual', $document->getDeleteReason());

        $document->restore();
        $document->restore();

        self::assertFalse($document->isDeleted());
        self::assertNull($document->getDeletedAt());
        self::assertNull($document->getDeletedBy());
        self::assertNull($document->getDeleteReason());
    }

    private function createDocument(): Document
    {
        $user = new User(Uuid::uuid4()->toString());
        $company = new Company(Uuid::uuid4()->toString(), $user);

        return new Document(Uuid::uuid4()->toString(), $company);
    }
}

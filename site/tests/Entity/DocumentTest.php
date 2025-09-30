<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Company;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class DocumentTest extends TestCase
{
    public function testDefaultTypeIsOther(): void
    {
        $document = $this->createDocument();

        self::assertSame(DocumentType::OTHER, $document->getType());
        self::assertSame('OTHER', $document->getTypeValue());
    }

    public function testSetTypeUpdatesValue(): void
    {
        $document = $this->createDocument();
        $document->setType(DocumentType::PAYROLL_ACCRUAL);

        self::assertSame(DocumentType::PAYROLL_ACCRUAL, $document->getType());
        self::assertSame('PAYROLL_ACCRUAL', $document->getTypeValue());
    }

    private function createDocument(): Document
    {
        $user = new User(Uuid::uuid4()->toString());
        $company = new Company(Uuid::uuid4()->toString(), $user);

        return new Document(Uuid::uuid4()->toString(), $company);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Entity\SettlementItem;
use App\Settlement\Entity\SettlementMovement;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class SettlementSchemaTest extends IntegrationTestCase
{
    public function testMoneyRoundTripAndCompanyScopedReferences(): void
    {
        $company = Uuid::uuid7()->toString();
        $party = Uuid::uuid7()->toString();
        $date = new \DateTimeImmutable('2026-11-01');
        $document = new SettlementDocument($company, Uuid::uuid7()->toString(), SettlementDocumentKind::OBLIGATION, $party, SettlementBucket::PAYABLE, Money::fromString('0.01', 'RUB'), $date, null, 'Акт', Uuid::uuid7()->toString());
        $this->em->persist($document);
        $item = new SettlementItem($company, $document->getId(), $party, SettlementBucket::PAYABLE, Money::fromString('0.01', 'RUB'), $date);
        $this->em->persist($item);
        $movement = new SettlementMovement($company, $document->getId(), $item->getId(), SettlementBucket::PAYABLE, Money::fromString('-0.01', 'RUB'), $date);
        $this->em->persist($movement);
        $this->em->flush();
        $this->em->clear();
        $row = $this->em->find(SettlementMovement::class, $movement->getId());
        self::assertInstanceOf(SettlementMovement::class, $row);
        self::assertSame(-1, $row->getDelta()->amountMinor());
        self::assertSame('RUB', $row->getDelta()->currency());
        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $this->connection->executeStatement('UPDATE settlement_movements SET company_id = :other WHERE id = :id', ['other' => Uuid::uuid7()->toString(), 'id' => $movement->getId()]);
    }
}

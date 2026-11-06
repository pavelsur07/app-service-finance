<?php

declare(strict_types=1);

namespace App\Tests\Builders\Settlement;

use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Shared\Domain\ValueObject\Money;
use Ramsey\Uuid\Uuid;

final class SettlementDocumentBuilder
{
    public static function obligation(string $companyId, string $counterpartyId, string $actorId): SettlementDocument
    {
        return new SettlementDocument($companyId, Uuid::uuid7()->toString(), SettlementDocumentKind::OBLIGATION, $counterpartyId, SettlementBucket::PAYABLE, Money::fromString('100.00', 'RUB'), new \DateTimeImmutable('2026-11-01'), null, 'Акт', $actorId);
    }
}

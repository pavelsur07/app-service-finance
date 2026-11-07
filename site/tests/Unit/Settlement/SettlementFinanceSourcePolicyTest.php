<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settlement;

use App\Finance\Application\DTO\FinanceSettlementSource;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLDocumentSource;
use App\Settlement\Domain\SettlementFinanceSourcePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class SettlementFinanceSourcePolicyTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, DocumentStatus, DocumentType, ?PLDocumentSource}> */
    public static function invalidSources(): iterable
    {
        yield 'deleted' => [true, true, DocumentStatus::ACTIVE, DocumentType::OTHER, null];
        yield 'missing party' => [false, false, DocumentStatus::ACTIVE, DocumentType::OTHER, null];
        yield 'draft' => [false, true, DocumentStatus::DRAFT, DocumentType::OTHER, null];
        yield 'marketplace type' => [false, true, DocumentStatus::ACTIVE, DocumentType::MARKETPLACE_PL, null];
        yield 'loan' => [false, true, DocumentStatus::ACTIVE, DocumentType::LOANS, null];
        yield 'technical source' => [false, true, DocumentStatus::ACTIVE, DocumentType::OTHER, PLDocumentSource::MARKETPLACE_WB];
        yield 'manual source flag' => [false, true, DocumentStatus::ACTIVE, DocumentType::OTHER, PLDocumentSource::MANUAL];
    }

    #[DataProvider('invalidSources')]
    public function testIneligiblePnlCannotBecomeDebt(bool $deleted, bool $hasParty, DocumentStatus $status, DocumentType $type, ?PLDocumentSource $origin): void
    {
        $source = new FinanceSettlementSource(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), $hasParty ? Uuid::uuid7()->toString() : null, new \DateTimeImmutable('2026-11-01'), $deleted, $status, $type, [], 'fingerprint', $origin, null);
        $this->expectException(\DomainException::class);
        (new SettlementFinanceSourcePolicy())->assertUsable($source);
    }

    public function testEligibleManualDocumentTypesAreAccepted(): void
    {
        foreach ([DocumentType::OTHER, DocumentType::OBLIGATIONS, DocumentType::CASHFLOW_EXPENSE] as $type) {
            (new SettlementFinanceSourcePolicy())->assertUsable(new FinanceSettlementSource(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-01'), false, DocumentStatus::ACTIVE, $type, [], 'fingerprint', null, null));
        }
        $this->addToAssertionCount(3);
    }
}

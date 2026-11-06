<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settlement;

use App\Cash\Enum\Transaction\CashDirection;
use App\Settlement\Domain\SettlementPostingPolicy;
use App\Settlement\Enum\SettlementBucket as Bucket;
use App\Settlement\Enum\SettlementDocumentKind as Kind;
use App\Settlement\Enum\SettlementSourceType as Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class SettlementBaseDocumentPolicyTest extends TestCase
{
    /** @return iterable<string,array{Kind,Bucket,Source,bool,string}> */
    public static function forbiddenBaseDocuments(): iterable
    {
        yield 'obligation cannot create advance' => [Kind::OBLIGATION, Bucket::ADVANCE_GIVEN, Source::MANUAL, false, '2026-11-01'];
        yield 'opening is manual' => [Kind::OPENING, Bucket::PAYABLE, Source::FINANCE, true, '2026-11-01'];
        yield 'opening cannot be unallocated' => [Kind::OPENING, Bucket::UNALLOCATED_OUT, Source::MANUAL, false, '2026-11-01'];
        yield 'opening at cutoff only' => [Kind::OPENING, Bucket::PAYABLE, Source::MANUAL, false, '2026-11-02'];
        yield 'payment requires cash' => [Kind::PAYMENT, Bucket::ADVANCE_GIVEN, Source::MANUAL, false, '2026-11-01'];
        yield 'payment requires source id' => [Kind::PAYMENT, Bucket::UNALLOCATED_OUT, Source::CASH, false, '2026-11-01'];
        yield 'finance requires source id' => [Kind::OBLIGATION, Bucket::PAYABLE, Source::FINANCE, false, '2026-11-01'];
        yield 'manual forbids external id' => [Kind::OBLIGATION, Bucket::PAYABLE, Source::MANUAL, true, '2026-11-01'];
        yield 'auxiliary event is not a base' => [Kind::CORRECTION, Bucket::PAYABLE, Source::MANUAL, false, '2026-11-01'];
    }

    #[DataProvider('forbiddenBaseDocuments')]
    public function testFinanciallyInvalidBaseIsRejected(Kind $kind, Bucket $bucket, Source $source, bool $hasId, string $date): void
    {
        $this->expectException(\DomainException::class);
        (new SettlementPostingPolicy())->assertBaseDocument($kind, $bucket, $source, $hasId ? Uuid::uuid7()->toString() : null, new \DateTimeImmutable($date), new \DateTimeImmutable('2026-11-01'));
    }

    /** @return iterable<string,array{CashDirection,bool,Bucket}> */
    public static function paymentBuckets(): iterable
    {
        yield 'outgoing advance' => [CashDirection::OUTFLOW, true, Bucket::ADVANCE_GIVEN];
        yield 'outgoing unallocated' => [CashDirection::OUTFLOW, false, Bucket::UNALLOCATED_OUT];
        yield 'incoming advance' => [CashDirection::INFLOW, true, Bucket::ADVANCE_RECEIVED];
        yield 'incoming unallocated' => [CashDirection::INFLOW, false, Bucket::UNALLOCATED_IN];
    }

    #[DataProvider('paymentBuckets')]
    public function testActualDirectionAndExplicitConfirmationChooseTheBucket(CashDirection $direction, bool $advance, Bucket $expected): void
    {
        self::assertSame($expected, (new SettlementPostingPolicy())->paymentBucket($direction, $advance));
    }
}

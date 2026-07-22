<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Message;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;
use App\Cash\Message\ApplyAutoRulesForTransaction;
use App\Cash\Message\EnqueueAutoRulesForRange;
use PHPUnit\Framework\TestCase;

final class AutoRuleMessageCompatibilityTest extends TestCase
{
    private const LEGACY_RANGE_PAYLOAD = 'O:41:"App\Cash\Message\EnqueueAutoRulesForRange":4:{s:9:"companyId";s:10:"company-id";s:4:"from";N;s:2:"to";N;s:15:"moneyAccountIds";N;}';

    private const LEGACY_TRANSACTION_PAYLOAD = 'O:45:"App\Cash\Message\ApplyAutoRulesForTransaction":4:{s:13:"transactionId";s:14:"transaction-id";s:9:"companyId";s:10:"company-id";s:9:"createdAt";O:17:"DateTimeImmutable":3:{s:4:"date";s:26:"2026-07-16 06:26:01.000000";s:13:"timezone_type";i:1;s:8:"timezone";s:6:"+00:00";}s:13:"correlationId";s:14:"correlation-id";}';

    private const OLDEST_TRANSACTION_PAYLOAD = 'O:45:"App\Cash\Message\ApplyAutoRulesForTransaction":3:{s:13:"transactionId";s:14:"transaction-id";s:9:"companyId";s:10:"company-id";s:9:"createdAt";O:17:"DateTimeImmutable":3:{s:4:"date";s:26:"2026-07-16 06:26:01.000000";s:13:"timezone_type";i:1;s:8:"timezone";s:6:"+00:00";}}';

    public function testLegacyRangePayloadDefaultsCorrelationIdToNull(): void
    {
        $message = unserialize(self::LEGACY_RANGE_PAYLOAD);

        self::assertInstanceOf(EnqueueAutoRulesForRange::class, $message);
        self::assertSame('company-id', $message->companyId);
        self::assertNull($message->correlationId);
        self::assertSame(CashTransactionAutoRuleApplyMode::SAFE, $message->mode);
        self::assertNull($message->initiatedByUserId);
    }

    public function testLegacyTransactionPayloadPreservesCorrelationIdAndDefaultsNewFields(): void
    {
        $createdAt = new \DateTimeImmutable('2026-07-16T06:26:01+00:00');
        $message = unserialize(self::LEGACY_TRANSACTION_PAYLOAD);

        self::assertInstanceOf(ApplyAutoRulesForTransaction::class, $message);
        self::assertSame('transaction-id', $message->transactionId);
        self::assertSame('company-id', $message->companyId);
        self::assertEquals($createdAt, $message->createdAt);
        self::assertSame('correlation-id', $message->correlationId);
        self::assertSame(CashTransactionAutoRuleApplyMode::SAFE, $message->mode);
        self::assertNull($message->initiatedByUserId);
    }

    public function testOldestTransactionPayloadDefaultsAllOptionalFields(): void
    {
        $message = unserialize(self::OLDEST_TRANSACTION_PAYLOAD);

        self::assertInstanceOf(ApplyAutoRulesForTransaction::class, $message);
        self::assertNull($message->correlationId);
        self::assertSame(CashTransactionAutoRuleApplyMode::SAFE, $message->mode);
        self::assertNull($message->initiatedByUserId);
    }

    public function testNewRangePayloadPreservesProperties(): void
    {
        $from = new \DateTimeImmutable('2026-07-01');
        $to = new \DateTimeImmutable('2026-07-16');
        $message = unserialize(serialize(new EnqueueAutoRulesForRange(
            'company-id',
            $from,
            $to,
            ['account-id'],
            'correlation-id',
            CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED,
            'actor-id',
        )));

        self::assertInstanceOf(EnqueueAutoRulesForRange::class, $message);
        self::assertSame('company-id', $message->companyId);
        self::assertEquals($from, $message->from);
        self::assertEquals($to, $message->to);
        self::assertSame(['account-id'], $message->moneyAccountIds);
        self::assertSame('correlation-id', $message->correlationId);
        self::assertSame(CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED, $message->mode);
        self::assertSame('actor-id', $message->initiatedByUserId);
    }

    public function testNewTransactionPayloadPreservesProperties(): void
    {
        $createdAt = new \DateTimeImmutable('2026-07-16T06:26:01+00:00');
        $message = unserialize(serialize(new ApplyAutoRulesForTransaction(
            'transaction-id',
            'company-id',
            $createdAt,
            'correlation-id',
            CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED,
            'actor-id',
        )));

        self::assertInstanceOf(ApplyAutoRulesForTransaction::class, $message);
        self::assertSame('transaction-id', $message->transactionId);
        self::assertSame('company-id', $message->companyId);
        self::assertEquals($createdAt, $message->createdAt);
        self::assertSame('correlation-id', $message->correlationId);
        self::assertSame(CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED, $message->mode);
        self::assertSame('actor-id', $message->initiatedByUserId);
    }
}

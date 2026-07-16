<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Message;

use App\Cash\Message\ApplyAutoRulesForTransaction;
use App\Cash\Message\EnqueueAutoRulesForRange;
use PHPUnit\Framework\TestCase;

final class AutoRuleMessageCompatibilityTest extends TestCase
{
    public function testLegacyRangePayloadDefaultsCorrelationIdToNull(): void
    {
        $message = unserialize($this->withoutCorrelationId(
            new EnqueueAutoRulesForRange('company-id'),
            5,
        ));

        self::assertInstanceOf(EnqueueAutoRulesForRange::class, $message);
        self::assertSame('company-id', $message->companyId);
        self::assertNull($message->correlationId);
    }

    public function testLegacyTransactionPayloadDefaultsCorrelationIdToNull(): void
    {
        $createdAt = new \DateTimeImmutable('2026-07-16T06:26:01+00:00');
        $message = unserialize($this->withoutCorrelationId(
            new ApplyAutoRulesForTransaction('transaction-id', 'company-id', $createdAt),
            4,
        ));

        self::assertInstanceOf(ApplyAutoRulesForTransaction::class, $message);
        self::assertSame('transaction-id', $message->transactionId);
        self::assertSame('company-id', $message->companyId);
        self::assertEquals($createdAt, $message->createdAt);
        self::assertNull($message->correlationId);
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
        )));

        self::assertInstanceOf(EnqueueAutoRulesForRange::class, $message);
        self::assertSame('company-id', $message->companyId);
        self::assertEquals($from, $message->from);
        self::assertEquals($to, $message->to);
        self::assertSame(['account-id'], $message->moneyAccountIds);
        self::assertSame('correlation-id', $message->correlationId);
    }

    public function testNewTransactionPayloadPreservesProperties(): void
    {
        $createdAt = new \DateTimeImmutable('2026-07-16T06:26:01+00:00');
        $message = unserialize(serialize(new ApplyAutoRulesForTransaction(
            'transaction-id',
            'company-id',
            $createdAt,
            'correlation-id',
        )));

        self::assertInstanceOf(ApplyAutoRulesForTransaction::class, $message);
        self::assertSame('transaction-id', $message->transactionId);
        self::assertSame('company-id', $message->companyId);
        self::assertEquals($createdAt, $message->createdAt);
        self::assertSame('correlation-id', $message->correlationId);
    }

    private function withoutCorrelationId(object $message, int $propertyCount): string
    {
        $payload = preg_replace(
            sprintf('/^(O:\\d+:"[^"]+":)%d:/', $propertyCount),
            sprintf('${1}%d:', $propertyCount - 1),
            serialize($message),
            1,
        );
        self::assertIsString($payload);

        return str_replace('s:13:"correlationId";N;', '', $payload);
    }
}

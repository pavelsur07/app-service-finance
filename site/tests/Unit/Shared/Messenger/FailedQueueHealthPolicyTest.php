<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Messenger;

use App\Shared\Messenger\FailedQueueHealthPolicy;
use App\Shared\Messenger\FailedQueueSnapshot;
use App\Shared\Messenger\FailedQueueVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailedQueueHealthPolicyTest extends TestCase
{
    private const AGE = 86400;
    private const DEPTH = 10;

    private FailedQueueHealthPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new FailedQueueHealthPolicy(self::AGE, self::DEPTH);
    }

    public function testEmptyQueueIsOk(): void
    {
        $assessment = $this->policy->evaluate(new FailedQueueSnapshot(0, null, null));

        self::assertSame(FailedQueueVerdict::OK, $assessment->verdict);
        self::assertSame([], $assessment->reasons);
    }

    public function testOneRecentMessageIsWarningNotHealthy(): void
    {
        $assessment = $this->policy->evaluate($this->snapshot(1, 600));

        self::assertSame(FailedQueueVerdict::WARNING, $assessment->verdict);
        self::assertStringContainsString('1 сообщений', $assessment->reasons[0]);
    }

    public function testOldMessageIsError(): void
    {
        $assessment = $this->policy->evaluate($this->snapshot(1, 3 * 86400 + 4 * 3600));

        self::assertSame(FailedQueueVerdict::ERROR, $assessment->verdict);
        self::assertStringContainsString('3d 4h', $assessment->reasons[0]);
    }

    public function testHighDepthIsErrorEvenIfMessagesAreFresh(): void
    {
        $assessment = $this->policy->evaluate($this->snapshot(self::DEPTH, 60));

        self::assertSame(FailedQueueVerdict::ERROR, $assessment->verdict);
        self::assertStringContainsString('10 сообщений', $assessment->reasons[0]);
    }

    /** @return iterable<string, array{int, FailedQueueVerdict}> */
    public static function ageBoundaries(): iterable
    {
        yield 'threshold - 1 sec' => [self::AGE - 1, FailedQueueVerdict::WARNING];
        yield 'threshold' => [self::AGE, FailedQueueVerdict::ERROR];
        yield 'threshold + 1 sec' => [self::AGE + 1, FailedQueueVerdict::ERROR];
    }

    #[DataProvider('ageBoundaries')]
    public function testAgeBoundary(int $ageSeconds, FailedQueueVerdict $expected): void
    {
        self::assertSame($expected, $this->policy->evaluate($this->snapshot(1, $ageSeconds))->verdict);
    }

    /** @return iterable<string, array{int, FailedQueueVerdict}> */
    public static function depthBoundaries(): iterable
    {
        yield 'depth - 1' => [self::DEPTH - 1, FailedQueueVerdict::WARNING];
        yield 'depth' => [self::DEPTH, FailedQueueVerdict::ERROR];
        yield 'depth + 1' => [self::DEPTH + 1, FailedQueueVerdict::ERROR];
    }

    #[DataProvider('depthBoundaries')]
    public function testDepthBoundary(int $count, FailedQueueVerdict $expected): void
    {
        self::assertSame($expected, $this->policy->evaluate($this->snapshot($count, 60))->verdict);
    }

    public function testBothReasonsAreReported(): void
    {
        $assessment = $this->policy->evaluate($this->snapshot(15, 5 * 86400));

        self::assertSame(FailedQueueVerdict::ERROR, $assessment->verdict);
        self::assertCount(2, $assessment->reasons);
    }

    /** @return iterable<string, array{int, string}> */
    public static function ages(): iterable
    {
        yield 'seconds' => [45, '0m 45s'];
        yield 'minutes' => [125, '2m 5s'];
        yield 'hours' => [3 * 3600 + 20 * 60, '3h 20m'];
        yield 'days' => [3 * 86400 + 4 * 3600 + 59, '3d 4h'];
        yield 'negative clamps to zero' => [-5, '0m 0s'];
    }

    #[DataProvider('ages')]
    public function testFormatAge(int $seconds, string $expected): void
    {
        self::assertSame($expected, FailedQueueHealthPolicy::formatAge($seconds));
    }

    public function testThresholdsMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FailedQueueHealthPolicy(0, 10);
    }

    private function snapshot(int $count, int $ageSeconds): FailedQueueSnapshot
    {
        return new FailedQueueSnapshot($count, $ageSeconds, new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('UTC')));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Domain\Policy;

use App\Cash\Application\DTO\CashAiAutoApplyGateStats;
use App\Cash\Domain\Policy\CashAiAutoApplyPolicy as Policy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CashAiAutoApplyPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, int, int, ?string}>
     */
    public static function gates(): iterable
    {
        yield 'flag off, quality enough' => [false, 100, 0, Policy::REASON_DISABLED];
        yield 'no decisions yet' => [true, 0, 0, Policy::REASON_TOO_FEW_DECISIONS];
        yield 'one decision short' => [true, 99, 0, Policy::REASON_TOO_FEW_DECISIONS];
        yield 'enough decisions, precision just below' => [true, 94, 6, Policy::REASON_LOW_PRECISION];
        yield 'precision exactly at the threshold' => [true, 95, 5, null];
        yield 'all right' => [true, 100, 0, null];
    }

    #[DataProvider('gates')]
    public function testGate(bool $enabled, int $right, int $wrong, ?string $expected): void
    {
        self::assertSame($expected, Policy::gateClosedReason($enabled, new CashAiAutoApplyGateStats($right, $wrong, 0, 0)));
    }

    public function testCorrectedAutoApplicationsCountAsWrongDecisionsInTheGate(): void
    {
        // 95 верных из 100, но одно автоприменение исправлено человеком: 95 / 101 < 95%.
        $stats = new CashAiAutoApplyGateStats(right: 95, wrong: 6, autoApplied: 3, autoCorrected: 1);

        self::assertSame(Policy::REASON_LOW_PRECISION, Policy::gateClosedReason(true, $stats));
    }

    /**
     * @return iterable<string, array{float, ?string, bool}>
     */
    public static function suggestions(): iterable
    {
        yield 'confident, no review' => [0.95, 'accepted', true];
        yield 'confidence exactly at the threshold' => [Policy::MIN_CONFIDENCE, 'accepted', true];
        yield 'below the threshold' => [0.89, 'accepted', false];
        yield 'service asked for review' => [0.99, 'needs_review', false];
        yield 'no verdict' => [0.99, null, false];
    }

    #[DataProvider('suggestions')]
    public function testSuggestionQualifies(float $confidence, ?string $verdict, bool $expected): void
    {
        self::assertSame($expected, Policy::suggestionQualifies($confidence, $verdict));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAnalytics\Enum;

use App\MarketplaceAnalytics\Enum\TurnoverBucket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TurnoverBucketTest extends TestCase
{
    /**
     * @return iterable<string, array{?int, TurnoverBucket}>
     */
    public static function boundaries(): iterable
    {
        yield 'нет продаж' => [null, TurnoverBucket::NoSales];
        yield '0 дней' => [0, TurnoverBucket::Fast];
        yield '29 дней' => [29, TurnoverBucket::Fast];
        yield '30 дней' => [30, TurnoverBucket::Medium];
        yield '59 дней' => [59, TurnoverBucket::Medium];
        yield '60 дней' => [60, TurnoverBucket::Slow];
        yield '90 дней' => [90, TurnoverBucket::Slow];
        yield '91 день' => [91, TurnoverBucket::Frozen];
        yield '400 дней' => [400, TurnoverBucket::Frozen];
    }

    #[DataProvider('boundaries')]
    public function testBucketBoundaries(?int $days, TurnoverBucket $expected): void
    {
        self::assertSame($expected, TurnoverBucket::fromDaysOfCover($days));
    }

    public function testLabelsAndFrozenFlag(): void
    {
        self::assertSame('до 30 дней', TurnoverBucket::Fast->label());
        self::assertSame('30–60 дней', TurnoverBucket::Medium->label());
        self::assertSame('60–90 дней', TurnoverBucket::Slow->label());
        self::assertSame('свыше 90 дней', TurnoverBucket::Frozen->label());
        self::assertSame('нет продаж', TurnoverBucket::NoSales->label());

        self::assertSame(
            [TurnoverBucket::Frozen, TurnoverBucket::NoSales],
            array_values(array_filter(TurnoverBucket::cases(), static fn (TurnoverBucket $b): bool => $b->isFrozen())),
        );
        $backingValues = array_map(
            static fn (\ReflectionEnumBackedCase $case): string => (string) $case->getBackingValue(),
            (new \ReflectionEnum(TurnoverBucket::class))->getCases(),
        );
        self::assertSame(['fast', 'medium', 'slow', 'frozen', 'no_sales'], $backingValues);
    }
}

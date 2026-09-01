<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Service;

use App\Ingestion\Application\Service\AbstractHourlyCursorIncrementalStrategy;
use App\Ingestion\Enum\IngestSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AbstractHourlyCursorIncrementalStrategyTest extends TestCase
{
    #[DataProvider('cursorCases')]
    public function testCursorIsDueRespectsTheHourlyInterval(string $cursorValue, bool $expected): void
    {
        self::assertSame($expected, $this->strategy()->cursorIsDue($cursorValue));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function cursorCases(): iterable
    {
        yield 'час назад — ровно на границе' => ['{"since":"2026-09-01T11:00:00+00:00"}', true];
        yield 'два часа назад' => ['{"since":"2026-09-01T10:00:00+00:00"}', true];
        yield 'полчаса назад — рано' => ['{"since":"2026-09-01T11:30:00+00:00"}', false];
        yield 'голая отметка времени' => ['2026-09-01T09:00:00+00:00', true];
        yield 'мусор считаем просроченным' => ['не-дата', true];
        yield 'пустая строка' => ['', true];
    }

    /**
     * Интервал задаётся конструктором, чтобы ресурс с другой каденцией не
     * требовал нового класса.
     */
    public function testIntervalIsConfigurable(): void
    {
        $strategy = $this->strategy(minIntervalMinutes: 15);

        self::assertTrue($strategy->cursorIsDue('{"since":"2026-09-01T11:40:00+00:00"}'));
        self::assertFalse($strategy->cursorIsDue('{"since":"2026-09-01T11:50:00+00:00"}'));
    }

    private function strategyAt(MockClock $clock): AbstractHourlyCursorIncrementalStrategy
    {
        return new readonly class($clock) extends AbstractHourlyCursorIncrementalStrategy {
            public function source(): IngestSource
            {
                return IngestSource::OZON;
            }

            public function resourceType(): string
            {
                return 'test_orders';
            }

            public function supportsConnection(array $connection): bool
            {
                return true;
            }

            public function ensureCursor(string $companyId, string $connectionRef): void
            {
            }
        };
    }

    private function strategy(int $minIntervalMinutes = 60): AbstractHourlyCursorIncrementalStrategy
    {
        return new readonly class(new MockClock('2026-09-01 12:00:00'), $minIntervalMinutes) extends AbstractHourlyCursorIncrementalStrategy {
            public function source(): IngestSource
            {
                return IngestSource::OZON;
            }

            public function resourceType(): string
            {
                return 'test_orders';
            }

            public function supportsConnection(array $connection): bool
            {
                return true;
            }

            public function ensureCursor(string $companyId, string $connectionRef): void
            {
            }
        };
    }

    /**
     * Регрессия: при пороге ровно в 60 минут курсор, записанный воркером на
     * минуту позже запуска крона, отодвигал следующий обход на целый час —
     * ресурс молча становился двухчасовым.
     */
    public function testCursorSlightlyYoungerThanCronPeriodIsStillDue(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-09-01T13:35:00+00:00'));
        $strategy = $this->strategyAt($clock);

        // Прошлый прогон отработал в 12:36 — 59 минут назад.
        self::assertTrue($strategy->cursorIsDue(json_encode(['since' => '2026-09-01T12:36:00+00:00'], \JSON_THROW_ON_ERROR)));
    }

    public function testFreshCursorWithinToleranceIsNotDue(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-09-01T13:35:00+00:00'));
        $strategy = $this->strategyAt($clock);

        // 50 минут назад — внутри порога, повторный обход не нужен.
        self::assertFalse($strategy->cursorIsDue(json_encode(['since' => '2026-09-01T12:45:00+00:00'], \JSON_THROW_ON_ERROR)));
    }
}

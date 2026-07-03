<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Service;

use App\Ingestion\Application\Service\AbstractDailyCursorIncrementalStrategy;
use App\Ingestion\Enum\IngestSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AbstractDailyCursorIncrementalStrategyTest extends TestCase
{
    public function testJsonCursorDateIsUsedForDueCheck(): void
    {
        $strategy = new readonly class(new MockClock('2026-07-03 10:00:00')) extends AbstractDailyCursorIncrementalStrategy {
            public function source(): IngestSource
            {
                return IngestSource::WILDBERRIES;
            }

            public function resourceType(): string
            {
                return 'wildberries_finance_sales_report_detailed';
            }

            public function supportsConnection(array $connection): bool
            {
                return true;
            }

            public function ensureCursor(string $companyId, string $connectionRef): void
            {
            }
        };

        self::assertTrue($strategy->cursorIsDue('{"date":"2026-07-02","rrdId":123}'));
        self::assertFalse($strategy->cursorIsDue('{"date":"2026-07-03","rrdId":123}'));
    }
}

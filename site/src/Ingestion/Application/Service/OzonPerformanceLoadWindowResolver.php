<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use Symfony\Component\Clock\ClockInterface;

final readonly class OzonPerformanceLoadWindowResolver
{
    public const WINDOW_ROLLING = 'rolling';
    public const WINDOW_MONTH_TO_DATE = 'month-to-date';

    public function __construct(private ClockInterface $clock)
    {
    }

    public function resolve(string $window, int $daysBack): OzonPerformanceLoadWindow
    {
        return match ($window) {
            self::WINDOW_ROLLING => $this->rolling($daysBack),
            self::WINDOW_MONTH_TO_DATE => $this->monthToDate(),
            default => throw new \InvalidArgumentException('Invalid --window. Allowed values: rolling, month-to-date.'),
        };
    }

    /**
     * @return list<string>
     */
    public static function allowedWindows(): array
    {
        return [self::WINDOW_ROLLING, self::WINDOW_MONTH_TO_DATE];
    }

    private function rolling(int $daysBack): OzonPerformanceLoadWindow
    {
        $today = $this->clock->now()->setTime(0, 0);

        return new OzonPerformanceLoadWindow(
            from: $today->modify(sprintf('-%d days', $daysBack)),
            to: $today->modify('-1 day'),
            label: sprintf('last-%d-days', $daysBack),
        );
    }

    private function monthToDate(): OzonPerformanceLoadWindow
    {
        $yesterday = $this->clock->now()->setTime(0, 0)->modify('-1 day');

        return new OzonPerformanceLoadWindow(
            from: $yesterday->modify('first day of this month'),
            to: $yesterday,
            label: 'month-to-date',
        );
    }
}

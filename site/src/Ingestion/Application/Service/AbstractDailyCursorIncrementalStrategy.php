<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use Symfony\Component\Clock\ClockInterface;

abstract readonly class AbstractDailyCursorIncrementalStrategy implements IncrementalResourceStrategyInterface
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function cursorIsDue(string $cursorValue): bool
    {
        $cursorDate = $this->normalizedCursorDate($cursorValue);
        if (null === $cursorDate) {
            return true;
        }

        return $cursorDate <= $this->yesterdayDate();
    }

    private function normalizedCursorDate(string $cursorValue): ?string
    {
        try {
            $payload = json_decode($cursorValue, true, 512, \JSON_THROW_ON_ERROR);
            if (is_array($payload) && is_string($payload['date'] ?? null)) {
                $cursorValue = $payload['date'];
            }
        } catch (\JsonException) {
        }

        try {
            return (new \DateTimeImmutable($cursorValue))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function yesterdayDate(): string
    {
        return $this->clock->now()->modify('-1 day')->format('Y-m-d');
    }
}

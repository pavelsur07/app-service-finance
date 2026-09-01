<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use Symfony\Component\Clock\ClockInterface;

/**
 * База для ресурсов, которые обходятся чаще раза в сутки.
 *
 * Отличается от {@see AbstractDailyCursorIncrementalStrategy} не только
 * частотой: суточный курсор — дата `Y-m-d`, и её формат сам по себе не даёт
 * опрашивать ресурс дважды за день. Часовому курсору нужна отметка времени, и
 * интервал приходится проверять явно.
 *
 * Каденция принадлежит ресурсу, а не расписанию крона: `run-incremental` может
 * запускаться сколь угодно часто, а `cursorIsDue()` решает, пора ли.
 */
abstract readonly class AbstractHourlyCursorIncrementalStrategy implements IncrementalResourceStrategyInterface
{
    public function __construct(
        private ClockInterface $clock,
        private int $minIntervalMinutes = 60,
    ) {
    }

    public function cursorIsDue(string $cursorValue): bool
    {
        $cursorInstant = $this->normalizedCursorInstant($cursorValue);
        if (null === $cursorInstant) {
            // Нечитаемый курсор считаем просроченным: лишний запрос дешевле
            // навсегда застывшего ресурса.
            return true;
        }

        return $cursorInstant <= $this->clock->now()->modify(sprintf('-%d minutes', $this->minIntervalMinutes));
    }

    private function normalizedCursorInstant(string $cursorValue): ?\DateTimeImmutable
    {
        $value = trim($cursorValue);
        if ('' === $value) {
            return null;
        }

        try {
            $payload = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
            if (is_array($payload) && is_string($payload['since'] ?? null)) {
                $value = $payload['since'];
            }
        } catch (\JsonException) {
            // Не JSON — пробуем как голую отметку времени.
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}

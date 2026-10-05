<?php

declare(strict_types=1);

namespace App\Shared\Messenger;

/**
 * Политика оценки очереди failed. Пороги — параметры `app.failed_queue.*` (config/services.yaml).
 *
 * OK      — очередь пуста;
 * WARNING — есть сообщения, но самое старое моложе порога возраста и их меньше порога глубины;
 * ERROR   — самое старое пролежало не меньше порога возраста ИЛИ в очереди не меньше порога глубины.
 *
 * Границы включительные: возраст == порогу и глубина == порогу уже ERROR.
 *
 * Сообщение, только что попавшее в failed, один раз уже подняло `critical` из воркера Messenger;
 * гейт нужен для забытых: он краснеет, когда сообщение пережило окно разбора.
 */
final readonly class FailedQueueHealthPolicy
{
    public function __construct(
        private int $errorAgeSeconds,
        private int $errorDepth,
    ) {
        if ($errorAgeSeconds <= 0 || $errorDepth <= 0) {
            throw new \InvalidArgumentException('Пороги failed-очереди должны быть положительными.');
        }
    }

    public function evaluate(FailedQueueSnapshot $snapshot): FailedQueueAssessment
    {
        if ($snapshot->isEmpty()) {
            return new FailedQueueAssessment(FailedQueueVerdict::OK, []);
        }

        $reasons = [];

        if (null !== $snapshot->oldestAgeSeconds && $snapshot->oldestAgeSeconds >= $this->errorAgeSeconds) {
            $reasons[] = sprintf('самое старое сообщение лежит %s (порог %s)', self::formatAge($snapshot->oldestAgeSeconds), self::formatAge($this->errorAgeSeconds));
        }

        if ($snapshot->count >= $this->errorDepth) {
            $reasons[] = sprintf('в очереди %d сообщений (порог %d)', $snapshot->count, $this->errorDepth);
        }

        if ([] !== $reasons) {
            return new FailedQueueAssessment(FailedQueueVerdict::ERROR, $reasons);
        }

        return new FailedQueueAssessment(FailedQueueVerdict::WARNING, [
            sprintf('%d сообщений, самое старое — %s', $snapshot->count, self::formatAge($snapshot->oldestAgeSeconds ?? 0)),
        ]);
    }

    public static function formatAge(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return sprintf('%dd %dh', $days, $hours);
        }

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm %ds', $minutes, $seconds % 60);
    }
}

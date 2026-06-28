<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Sentry;

use Psr\Clock\ClockInterface;
use Sentry\Event;
use Sentry\EventHint;

/**
 * In-process rate-limiter для событий Sentry/GlitchTip.
 *
 * Защита от заливки одинаковыми событиями из тесных циклов: первые $limit событий
 * с одинаковым ключом (класс+сообщение исключения, либо текст сообщения) за окно
 * $windowSeconds проходят, остальные дропаются (return null). GlitchTip всё равно
 * сгруппирует повторы server-side, а первые N покажут, что ошибка рекуррентная.
 *
 * Сервис — singleton, состояние живёт в рамках процесса (для воркеров — пока жив воркер).
 * Карта ограничена по размеру, чтобы исключить рост памяти.
 *
 * НЕ readonly: хранит изменяемое состояние счётчиков.
 */
final class SentryRateLimiter
{
    /** @var array<string, array{windowStart: int, count: int}> */
    private array $buckets = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly int $limit = 10,
        private readonly int $windowSeconds = 60,
        private readonly int $maxKeys = 1000,
    ) {
    }

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        $now = $this->clock->now()->getTimestamp();
        $key = $this->keyFor($event);

        $bucket = $this->buckets[$key] ?? null;

        // Новое или истёкшее окно — пропускаем и начинаем отсчёт заново.
        if (null === $bucket || ($now - $bucket['windowStart']) >= $this->windowSeconds) {
            $this->buckets[$key] = ['windowStart' => $now, 'count' => 1];

            // Чистку/обрезку держим вне горячего пути: запускаем только когда карта
            // реально разрослась сверх лимита (для остальных событий путь O(1)).
            if (\count($this->buckets) > $this->maxKeys) {
                $this->pruneExpired($now);
                $this->enforceCap();
            }

            return $event;
        }

        ++$bucket['count'];
        $this->buckets[$key] = $bucket;

        // Сверх лимита в текущем окне — троттлим.
        if ($bucket['count'] > $this->limit) {
            return null;
        }

        return $event;
    }

    private function keyFor(Event $event): string
    {
        $parts = [];
        foreach ($event->getExceptions() as $exception) {
            $parts[] = $exception->getType().':'.$exception->getValue();
        }

        if ([] === $parts) {
            $level = $event->getLevel();
            $parts[] = $event->getMessage() ?? (null !== $level ? (string) $level : 'unknown');
        }

        return md5(implode('|', $parts));
    }

    private function pruneExpired(int $now): void
    {
        foreach ($this->buckets as $key => $bucket) {
            if (($now - $bucket['windowStart']) >= $this->windowSeconds) {
                unset($this->buckets[$key]);
            }
        }
    }

    /**
     * Патологический случай: >maxKeys различных сигнатур в окне.
     * Оставляем самые свежие maxKeys ключей (массив хранит порядок вставки),
     * отбрасываем только старейшие — троттлинг активных ошибок сохраняется,
     * рост памяти исключён.
     */
    private function enforceCap(): void
    {
        if (\count($this->buckets) <= $this->maxKeys) {
            return;
        }

        $this->buckets = \array_slice($this->buckets, -$this->maxKeys, null, true);
    }
}

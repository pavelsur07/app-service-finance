<?php

declare(strict_types=1);

namespace App\Shared\Messenger;

/**
 * Состояние очереди неудавшихся сообщений Messenger (failure transport) на момент проверки.
 *
 * Только числа и имена классов: тела сообщений, заголовки и тексты ошибок сюда не попадают.
 */
final readonly class FailedQueueSnapshot
{
    /**
     * @param int $count сколько сообщений в очереди (глубина)
     * @param int|null $oldestAgeSeconds возраст самого старого от created_at (момент отправки в failed), null — очередь пуста
     * @param \DateTimeImmutable|null $oldestCreatedAt created_at самого старого, UTC
     * @param list<array{message_class: string, original_transport: string, count: int}> $breakdown разбивка по классу и исходному транспорту
     * @param int $breakdownSampled по скольким сообщениям посчитана разбивка (выборка, а не вся очередь)
     */
    public function __construct(
        public int $count,
        public ?int $oldestAgeSeconds,
        public ?\DateTimeImmutable $oldestCreatedAt,
        public array $breakdown = [],
        public int $breakdownSampled = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->count;
    }
}

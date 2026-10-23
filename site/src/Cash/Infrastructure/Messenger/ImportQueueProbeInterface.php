<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Messenger;

/**
 * Ищет сообщения заданий импорта в живой очереди транспорта (не в failed).
 */
interface ImportQueueProbeInterface
{
    /**
     * @param list<string> $jobIds
     *
     * @return list<string> ID заданий, сообщение которых ещё лежит в очереди или ждёт повтора
     *
     * @throws \RuntimeException очередь недоступна — потерю сообщения доказать нельзя
     */
    public function findQueued(array $jobIds): array;
}

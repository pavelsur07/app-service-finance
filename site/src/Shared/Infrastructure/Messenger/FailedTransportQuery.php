<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Messenger\FailedQueueSnapshot;
use Doctrine\DBAL\Connection;

/**
 * Чтение состояния failure transport. ТОЛЬКО SELECT: сообщения не подтверждаются, не
 * перепосылаются, не удаляются и не изменяются (никакого ACK/RETRY/DELETE/UPDATE).
 *
 * Хранилище — Doctrine-транспорт `failed: doctrine://default?queue_name=failed`
 * (config/packages/messenger.yaml): таблица `messenger_messages`, строки с queue_name = 'failed'.
 * failure_transport один на всю шину, поэтому в нём лежат сбои всех транспортов (async_sync,
 * async_pipeline, async_wb_finance, ingest_*); ingest_* делят Redis-стримы с async_sync/pipeline.
 *
 * Время: Messenger пишет created_at/available_at в UTC без зоны (new DateTimeImmutable('UTC')),
 * в отличие от прикладных таблиц (там МСК). Возраст считается в БД как
 * (NOW() AT TIME ZONE 'UTC') − MIN(created_at): обе стороны в UTC, зона PHP не участвует.
 * created_at — момент, когда сообщение отправлено в failed (исчерпаны ретраи), а не момент
 * создания исходного сообщения.
 */
final class FailedTransportQuery
{
    /** Должно совпадать с queue_name в DSN транспорта `failed` (config/packages/messenger.yaml); сверяется тестом. */
    public const QUEUE_NAME = 'failed';

    /** Разбивка читает тела сообщений, поэтому считается по ограниченной выборке старейших. */
    public const BREAKDOWN_SAMPLE_LIMIT = 500;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Глубина и возраст — агрегатный SQL по индексу queue_name; тела не читаются.
     * Исключение не глотается: недоступность хранилища не должна выглядеть как «очередь пуста».
     */
    public function snapshot(): FailedQueueSnapshot
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS cnt,
                    MIN(created_at) AS oldest_created_at,
                    CAST(EXTRACT(EPOCH FROM ((NOW() AT TIME ZONE 'UTC') - MIN(created_at))) AS BIGINT) AS oldest_age_seconds
             FROM messenger_messages
             WHERE queue_name = :queue",
            ['queue' => self::QUEUE_NAME],
        );

        $count = (int) ($row['cnt'] ?? 0);
        if (0 === $count || null === ($row['oldest_created_at'] ?? null)) {
            return new FailedQueueSnapshot(0, null, null);
        }

        [$breakdown, $sampled] = $this->breakdown($count);

        return new FailedQueueSnapshot(
            $count,
            max(0, (int) $row['oldest_age_seconds']),
            new \DateTimeImmutable((string) $row['oldest_created_at'], new \DateTimeZone('UTC')),
            $breakdown,
            $sampled,
        );
    }

    /**
     * Класс сообщения и исходный транспорт берутся регулярным выражением из тела `addslashes(serialize(Envelope))`
     * без unserialize: заголовки у PHP-сериализатора пустые. Содержимое сообщения наружу не выходит —
     * из SQL возвращаются только два извлечённых имени и счётчик. Вспомогательные данные: при сбое
     * разбивки основная проверка не страдает.
     *
     * @return array{0: list<array{message_class: string, original_transport: string, count: int}>, 1: int}
     */
    private function breakdown(int $count): array
    {
        $bs = 'chr(92)';
        $classPattern = "'message[^;]*;O:[0-9]+:[^A-Za-z]*([A-Za-z0-9_' || {$bs} || {$bs} || ']+)'";
        $transportPattern = "'originalReceiverName[^A-Za-z]*s:[0-9]+:[^A-Za-z0-9_]*([A-Za-z0-9_.-]+)'";

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT substring(body from {$classPattern}) AS message_class,
                        substring(body from {$transportPattern}) AS original_transport,
                        COUNT(*) AS cnt
                 FROM (
                     SELECT body FROM messenger_messages
                     WHERE queue_name = :queue
                     ORDER BY id
                     LIMIT ".self::BREAKDOWN_SAMPLE_LIMIT.'
                 ) sample
                 GROUP BY 1, 2
                 ORDER BY cnt DESC, message_class',
                ['queue' => self::QUEUE_NAME],
            );
        } catch (\Throwable) {
            return [[], 0];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'message_class' => self::normalizeClass($row['message_class'] ?? null),
                'original_transport' => '' !== (string) ($row['original_transport'] ?? '') ? (string) $row['original_transport'] : 'unknown',
                'count' => (int) $row['cnt'],
            ];
        }

        return [$result, min($count, self::BREAKDOWN_SAMPLE_LIMIT)];
    }

    /** В теле имена классов экранированы addslashes (разделитель — два обратных слеша, на конце — слеш от кавычки). */
    private static function normalizeClass(?string $raw): string
    {
        if (null === $raw || '' === $raw) {
            return 'unknown';
        }

        return rtrim(str_replace('\\\\', '\\', $raw), '\\');
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Predis\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Снимок Redis-очередей Messenger для отчёта M1. ТОЛЬКО чтение, O(1)/O(log n):
 * XLEN, XPENDING (сводка), XRANGE … COUNT 1, ZCARD. Ни ACK, ни XCLAIM, ни удаления.
 *
 * Транспорт Redis удаляет запись после ACK (delete_after_ack по умолчанию), поэтому
 * XLEN — сообщения, ещё не подтверждённые: ожидающие и находящиеся в обработке
 * (последние — XPENDING). Возраст старейшей записи берётся из её id (`<unix ms>-<seq>`).
 * Отложенные (DelayStamp, повторы) лежат в sorted set `<stream>__queue`.
 *
 * Читаются только потоки на том же Redis, что и REDIS_DSN; иначе поток помечается
 * недоступным, а не опрашивается чужим клиентом.
 */
final readonly class MessengerQueueSnapshotQuery
{
    private const GROUP = 'symfony';

    /**
     * @param array<string, string> $transportDsns
     */
    public function __construct(
        private Client $redisClient,
        #[Autowire('%env(REDIS_DSN)%')]
        private string $redisDsn,
        #[Autowire([
            'async_sync' => '%env(MESSENGER_TRANSPORT_DSN_SYNC)%',
            'async_pipeline' => '%env(MESSENGER_TRANSPORT_DSN_PIPELINE)%',
            'async_wb_finance' => '%env(MESSENGER_TRANSPORT_DSN_WB_FINANCE)%',
            'async_ads' => '%env(MESSENGER_TRANSPORT_DSN_ADS)%',
        ])]
        private array $transportDsns,
    ) {
    }

    /**
     * @return list<array{stream: string, transports: list<string>, status: string, length: ?int, pending: ?int, delayed: ?int, oldest_age_seconds: ?int}>
     */
    public function snapshot(?int $nowMs = null): array
    {
        $nowMs ??= (int) floor(microtime(true) * 1000);
        $redis = $this->endpoint($this->redisDsn);

        $streams = [];
        foreach ($this->transportDsns as $transport => $dsn) {
            $endpoint = $this->endpoint($dsn);
            $stream = $endpoint['stream'] ?? null;
            if (null === $stream || null === $endpoint['host']) {
                continue;
            }
            $key = $endpoint['host'].':'.$endpoint['port'].'/'.$stream;
            $streams[$key] ??= ['stream' => $stream, 'transports' => [], 'sameServer' => $endpoint['host'] === $redis['host'] && $endpoint['port'] === $redis['port']];
            $streams[$key]['transports'][] = $transport;
        }

        $result = [];
        foreach ($streams as $entry) {
            $row = ['stream' => $entry['stream'], 'transports' => $entry['transports'], 'status' => 'ok', 'length' => null, 'pending' => null, 'delayed' => null, 'oldest_age_seconds' => null];

            if (!$entry['sameServer']) {
                $result[] = ['status' => 'unavailable: stream on another Redis'] + $row;
                continue;
            }

            try {
                $row['length'] = (int) $this->redisClient->executeRaw(['XLEN', $entry['stream']]);
                $row['delayed'] = (int) $this->redisClient->executeRaw(['ZCARD', $entry['stream'].'__queue']);

                $oldest = $this->redisClient->executeRaw(['XRANGE', $entry['stream'], '-', '+', 'COUNT', '1']);
                $oldestId = \is_array($oldest) && isset($oldest[0][0]) && \is_string($oldest[0][0]) ? $oldest[0][0] : null;
                if (null !== $oldestId && 1 === preg_match('/^(\d+)-\d+$/', $oldestId, $m)) {
                    $row['oldest_age_seconds'] = max(0, intdiv($nowMs - (int) $m[1], 1000));
                }

                $error = false;
                $pending = $this->redisClient->executeRaw(['XPENDING', $entry['stream'], self::GROUP], $error);
                // Группы ещё нет (поток не создан ни одним воркером) — не ошибка: в обработке ничего.
                $row['pending'] = !$error && \is_array($pending) && isset($pending[0]) ? (int) $pending[0] : 0;
            } catch (\Throwable $e) {
                $row['status'] = 'error: '.$e::class;
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * Хост, порт и имя потока из DSN без пароля: пароль из DSN наружу не выходит.
     *
     * @return array{host: ?string, port: int, stream: ?string}
     */
    private function endpoint(string $dsn): array
    {
        $parts = parse_url($dsn);
        if (false === $parts) {
            return ['host' => null, 'port' => 6379, 'stream' => null];
        }

        $path = trim($parts['path'] ?? '', '/');
        $stream = '' === $path ? null : explode('/', $path)[0];

        return ['host' => $parts['host'] ?? null, 'port' => $parts['port'] ?? 6379, 'stream' => $stream];
    }
}

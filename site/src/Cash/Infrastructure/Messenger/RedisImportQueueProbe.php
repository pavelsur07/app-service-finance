<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Messenger;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Читает Redis-транспорт Messenger напрямую: `{stream}__queue` (отложенные повторы, в т. ч. при
 * занятом счёте) и stream (недоставленные и неподтверждённые сообщения). Ищет ID задания в
 * сериализованном сообщении.
 *
 * Messenger переносит повтор из `__queue` в stream (ZPOPMIN, затем XADD), поэтому:
 * - сначала читается `__queue`, потом stream — перенос после чтения `__queue` попадает в stream;
 * - проход повторяется после паузы — сообщение в промежутке между ZPOPMIN и XADD второй проход
 *   застаёт уже в stream. Доставленное сообщение остаётся в stream до ack.
 * Остаточная гонка доставки допустима: Handler сериализует и идемпотентно обрабатывает дубли.
 */
final readonly class RedisImportQueueProbe implements ImportQueueProbeInterface
{
    private const PAGE = 500;
    private const PASSES = 2;

    /** @var \Closure(): void */
    private \Closure $pause;

    /**
     * @param (\Closure(): void)|null $pause между проходами; в тестах — детерминированная вставка
     */
    public function __construct(
        #[Autowire('%env(MESSENGER_TRANSPORT_DSN_SYNC)%')]
        private string $dsn,
        ?\Closure $pause = null,
    ) {
        $this->pause = $pause ?? static function (): void {
            usleep(1_000_000);
        };
    }

    public function findQueued(array $jobIds): array
    {
        if ([] === $jobIds) {
            return [];
        }

        [$redis, $stream] = $this->connect();

        $wanted = array_fill_keys($jobIds, true);
        $found = [];
        try {
            for ($pass = 1; $pass <= self::PASSES; ++$pass) {
                if ($pass > 1) {
                    ($this->pause)();
                }
                foreach ($this->payloads($redis, $stream) as $payload) {
                    preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $payload, $matches);
                    foreach ($matches[0] as $jobId) {
                        if (isset($wanted[$jobId])) {
                            $found[$jobId] = true;
                        }
                    }
                }
            }
        } finally {
            $redis->close();
        }

        return array_keys($found);
    }

    /**
     * @return array{\Redis, string}
     */
    private function connect(): array
    {
        $parts = parse_url($this->dsn);
        if (false === $parts || 'redis' !== ($parts['scheme'] ?? null) || !isset($parts['host'])) {
            throw new \RuntimeException('Транспорт импорта не Redis (или не один хост): очередь не проверить.');
        }
        parse_str($parts['query'] ?? '', $query);
        $path = explode('/', trim($parts['path'] ?? '', '/'))[0];
        // Как Symfony Redis Connection: stream из пути, иначе из query, иначе по умолчанию.
        $stream = '' !== $path ? $path : (\is_string($query['stream'] ?? null) && '' !== $query['stream'] ? $query['stream'] : 'messages');
        $dbIndex = $query['dbindex'] ?? 0;
        if (!\is_string($dbIndex) && !\is_int($dbIndex) || !ctype_digit((string) $dbIndex)) {
            throw new \RuntimeException('Некорректный dbindex в DSN очереди импорта.');
        }

        $redis = new \Redis();
        if (!$redis->connect($parts['host'], $parts['port'] ?? 6379, 5.0)) {
            throw new \RuntimeException('Redis очереди импорта недоступен.');
        }
        if (isset($parts['pass'])) {
            // `redis://:password@host` — авторизация одним паролем, как у Symfony.
            $redis->auth('' !== ($parts['user'] ?? '')
                ? [rawurldecode($parts['user']), rawurldecode($parts['pass'])]
                : rawurldecode($parts['pass']));
        }
        if (!$redis->select((int) $dbIndex)) {
            throw new \RuntimeException('Redis очереди импорта: база dbindex недоступна.');
        }

        return [$redis, $stream];
    }

    /**
     * Отложенные повторы, затем stream.
     *
     * @return iterable<string>
     */
    private function payloads(\Redis $redis, string $stream): iterable
    {
        $cursor = null;
        do {
            // Rank offsets shift during Messenger ZPOPMIN; SCAN retains members present throughout the scan.
            $delayed = $redis->zScan($stream.'__queue', $cursor, null, self::PAGE);
            if (!is_array($delayed)) {
                if (false !== $delayed || $redis->getLastError()) {
                    throw new \RuntimeException('Чтение отложенных сообщений очереди импорта не удалось.');
                }
                $delayed = [];
            }
            foreach ($delayed as $payload => $score) {
                yield (string) $payload;
            }
        } while (0 !== $cursor);

        $start = '-';
        do {
            $entries = $redis->xRange($stream, $start, '+', self::PAGE);
            if (!\is_array($entries)) {
                throw new \RuntimeException('Чтение stream очереди импорта не удалось.');
            }
            foreach ($entries as $id => $fields) {
                yield implode("\n", array_map('strval', $fields));
                $start = '('.$id;
            }
        } while (self::PAGE === \count($entries));
    }
}

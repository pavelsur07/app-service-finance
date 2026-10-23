<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Messenger;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Читает Redis-транспорт Messenger напрямую: stream (недоставленные и неподтверждённые
 * сообщения) и `{stream}__queue` (отложенные повторы, в т. ч. при занятом счёте). Ищет ID задания
 * в сериализованном сообщении.
 */
final readonly class RedisImportQueueProbe implements ImportQueueProbeInterface
{
    private const PAGE = 500;

    public function __construct(
        #[Autowire('%env(MESSENGER_TRANSPORT_DSN_SYNC)%')]
        private string $dsn,
    ) {
    }

    public function findQueued(array $jobIds): array
    {
        if ([] === $jobIds) {
            return [];
        }

        $parts = parse_url($this->dsn);
        if (false === $parts || 'redis' !== ($parts['scheme'] ?? null) || !isset($parts['host'])) {
            throw new \RuntimeException('Транспорт импорта не Redis: очередь не проверить.');
        }
        $stream = explode('/', trim($parts['path'] ?? '', '/'))[0];
        $stream = '' === $stream ? 'messages' : $stream;

        $redis = new \Redis();
        if (!$redis->connect($parts['host'], $parts['port'] ?? 6379, 5.0)) {
            throw new \RuntimeException('Redis очереди импорта недоступен.');
        }
        if (isset($parts['pass'])) {
            $redis->auth(isset($parts['user']) ? [urldecode($parts['user']), urldecode($parts['pass'])] : urldecode($parts['pass']));
        }

        $found = [];
        $match = static function (string $payload) use ($jobIds, &$found): void {
            foreach ($jobIds as $jobId) {
                if (str_contains($payload, $jobId)) {
                    $found[$jobId] = true;
                }
            }
        };

        $start = '-';
        do {
            $entries = $redis->xRange($stream, $start, '+', self::PAGE);
            if (!\is_array($entries)) {
                throw new \RuntimeException('Чтение stream очереди импорта не удалось.');
            }
            foreach ($entries as $id => $fields) {
                $match(implode("\n", array_map('strval', $fields)));
                $start = '('.$id;
            }
        } while (self::PAGE === \count($entries));

        $delayed = $redis->zRange($stream.'__queue', 0, -1);
        if (!\is_array($delayed)) {
            throw new \RuntimeException('Чтение отложенных сообщений очереди импорта не удалось.');
        }
        foreach ($delayed as $payload) {
            $match((string) $payload);
        }

        return array_keys($found);
    }
}

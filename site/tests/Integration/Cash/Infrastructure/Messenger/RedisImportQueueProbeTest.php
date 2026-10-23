<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Infrastructure\Messenger;

use App\Cash\Infrastructure\Messenger\RedisImportQueueProbe;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Задание импорта не считается потерянным, пока его сообщение лежит в stream транспорта или ждёт
 * отложенного повтора в `{stream}__queue`.
 */
final class RedisImportQueueProbeTest extends TestCase
{
    private \Redis $redis;
    private string $stream;
    private string $dsn;

    protected function setUp(): void
    {
        $redisDsn = getenv('REDIS_DSN') ?: ($_ENV['REDIS_DSN'] ?? '');
        $parts = parse_url((string) $redisDsn);
        if (false === $parts || !isset($parts['host'])) {
            self::markTestSkipped('REDIS_DSN не задан.');
        }

        $this->redis = new \Redis();
        $this->redis->connect($parts['host'], $parts['port'] ?? 6379);
        $this->stream = 'test_import_probe_'.bin2hex(random_bytes(4));
        $this->dsn = sprintf('redis://%s:%d/%s', $parts['host'], $parts['port'] ?? 6379, $this->stream);
    }

    protected function tearDown(): void
    {
        if (isset($this->stream)) {
            $this->redis->del($this->stream, $this->stream.'__queue');
        }
    }

    public function testFindsJobsInStreamAndDelayedQueue(): void
    {
        $inStream = Uuid::uuid4()->toString();
        $delayed = Uuid::uuid4()->toString();
        $lost = Uuid::uuid4()->toString();
        // Как Symfony Redis transport: JSON {body, headers} с сериализованным сообщением.
        $this->redis->xAdd($this->stream, '*', ['message' => json_encode(['body' => 's:5:"jobId";s:36:"'.$inStream.'";', 'headers' => []])]);
        $this->redis->zAdd($this->stream.'__queue', (float) (microtime(true) * 1000 + 60_000), json_encode(['body' => 's:5:"jobId";s:36:"'.$delayed.'";', 'headers' => [], 'uniqid' => 'x']));

        $found = (new RedisImportQueueProbe($this->dsn))->findQueued([$inStream, $delayed, $lost]);
        sort($found);
        $expected = [$inStream, $delayed];
        sort($expected);

        self::assertSame($expected, $found);
    }

    public function testUnreachableRedisRefuses(): void
    {
        $this->expectException(\RuntimeException::class);

        (new RedisImportQueueProbe('doctrine://default'))->findQueued([Uuid::uuid4()->toString()]);
    }
}

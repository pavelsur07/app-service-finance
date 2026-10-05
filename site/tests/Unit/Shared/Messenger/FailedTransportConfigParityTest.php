<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Messenger;

use App\Shared\Infrastructure\Messenger\FailedTransportQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Гейт читает очередь по константе QUEUE_NAME; если failure transport переименуют или уведут в другое
 * хранилище, мониторинг иначе молча начал бы смотреть в пустое место.
 */
final class FailedTransportConfigParityTest extends TestCase
{
    public function testQueueNameMatchesTheFailureTransportDsn(): void
    {
        /** @var array{framework: array{messenger: array{failure_transport: string, transports: array<string, mixed>}}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/../config/packages/messenger.yaml');
        $messenger = $config['framework']['messenger'];

        self::assertSame('failed', $messenger['failure_transport']);

        $dsn = $messenger['transports']['failed'];
        self::assertIsString($dsn);
        self::assertStringStartsWith('doctrine://', $dsn);
        self::assertStringContainsString('queue_name='.FailedTransportQuery::QUEUE_NAME, $dsn);
    }
}

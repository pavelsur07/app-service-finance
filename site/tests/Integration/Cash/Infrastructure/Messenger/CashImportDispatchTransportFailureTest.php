<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Infrastructure\Messenger;

use App\Cash\Application\Import\File\DispatchCashFileImportJobAction;
use App\Cash\Message\Import\CashFileImportMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class CashImportDispatchTransportFailureTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('initializationModes')]
    public function testRealRedisConnectFailureIsDeferredByDispatchAction(bool $lazy): void
    {
        $factory = static fn () => new RedisTransport(Connection::fromDsn('redis://127.0.0.1:1/messages?timeout=0.1&lazy='.($lazy ? '1' : '0')), new PhpSerializer());
        $bus = new MessageBus([new SendMessageMiddleware(new SendersLocator([CashFileImportMessage::class => ['test']], new ServiceLocator(['test' => $factory])))]);
        self::assertFalse((new DispatchCashFileImportJobAction($bus, new NullLogger()))('company', 'job'));
    }

    /** @return iterable<string, array{bool}> */
    public static function initializationModes(): iterable
    {
        yield 'eager transport initialization' => [false];
        yield 'lazy connection initialization' => [true];
    }
}

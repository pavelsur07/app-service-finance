<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TestMessengerPing;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TestMessengerPingHandler
{
    public function __construct(
        private readonly CacheItemPoolInterface $cacheApp, // alias: cache.app
        private readonly LoggerInterface $logger           // channel: app
    ) {}

    public function __invoke(TestMessengerPing $msg): void
    {
        $host = gethostname() ?: 'worker';
        $item = $this->cacheApp->getItem('messenger:ping:' . $msg->id);
        $item->expiresAfter(300); // 5 минут
        $item->set([
            'handledAt' => (new \DateTimeImmutable())->format('H:i:s'),
            'host'      => $host,
            'companyId' => $msg->companyId,
        ]);
        $this->cacheApp->save($item);

        $this->logger->info('TestMessengerPing handled', [
            'id' => $msg->id, 'host' => $host, 'companyId' => $msg->companyId,
        ]);
    }
}

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
        private readonly CacheItemPoolInterface $cacheApp,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(TestMessengerPing $msg): void
    {
        $host = gethostname() ?: 'worker';

        // ✅ безопасный ключ (без : и прочих зарезервированных)
        $cacheKey = 'messenger_ping_' . preg_replace('/[{}()\/\\\\@:]/', '-', $msg->id);

        $item = $this->cacheApp->getItem($cacheKey);
        $item->expiresAfter(300);
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

<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Message\Import\CashFileImportMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

/** The durable queued job survives a transport outage and can be dispatched again. */
final readonly class DispatchCashFileImportJobAction
{
    public function __construct(private MessageBusInterface $bus, private LoggerInterface $logger)
    {
    }

    public function __invoke(string $companyId, string $jobId): bool
    {
        try {
            $this->bus->dispatch(new CashFileImportMessage($jobId));
        } catch (TransportException|\RedisException|\RedisClusterException $exception) {
            $this->logger->warning('Cash import delivery deferred', ['companyId' => $companyId, 'jobId' => $jobId, 'exception_class' => $exception::class]);

            return false;
        }

        return true;
    }
}

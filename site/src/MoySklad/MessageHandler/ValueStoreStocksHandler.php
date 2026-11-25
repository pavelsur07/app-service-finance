<?php

declare(strict_types=1);

namespace App\MoySklad\MessageHandler;

use App\MoySklad\Application\Action\ValueStoreStocksAction;
use App\MoySklad\Exception\StockSyncException;
use App\MoySklad\Message\ValueStoreStocksMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class ValueStoreStocksHandler
{
    public function __construct(private ValueStoreStocksAction $action, private MessageBusInterface $bus, private LoggerInterface $logger)
    {
    }

    public function __invoke(ValueStoreStocksMessage $message): void
    {
        $context = ['companyId' => $message->companyId, 'connectionId' => $message->connectionId, 'snapshotId' => $message->snapshotId, 'attempt' => $message->attempt];
        try {
            $outcome = ($this->action)($message->companyId, $message->connectionId, $message->snapshotId);
            $this->logger->info('MoySklad store valuation finished', $context + ['outcome' => $outcome ?? 'not_run']);
        } catch (StockSyncException $error) {
            $this->logger->warning('MoySklad store valuation failed', $context + ['category' => $error->category]);
            if (!in_array($error->category, ['rate_limited', 'temporary'], true) || $message->attempt >= 3) {
                return; // статус прогона failed с категорией виден на странице; догон — cron requeue
            }
            $delay = min(3_600_000, max($error->retryAfterMs ?? 0, 10_000 * (2 ** $message->attempt)));
            $this->bus->dispatch(new ValueStoreStocksMessage($message->companyId, $message->connectionId, $message->snapshotId, $message->attempt + 1), [new DelayStamp($delay)]);
        }
    }
}

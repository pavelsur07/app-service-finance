<?php

declare(strict_types=1);

namespace App\MoySklad\MessageHandler;

use App\MoySklad\Application\Action\SyncCatalogAction;
use App\MoySklad\Exception\CatalogSyncException;
use App\MoySklad\Message\SyncCatalogMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class SyncCatalogHandler
{
    public function __construct(private SyncCatalogAction $action, private MessageBusInterface $bus, private LoggerInterface $logger)
    {
    }

    public function __invoke(SyncCatalogMessage $message): void
    {
        $context = ['companyId' => $message->companyId, 'connectionId' => $message->connectionId, 'attempt' => $message->attempt];
        $this->logger->info('MoySklad catalog sync message started', $context);
        $outcome = 'failed';
        $runId = null;
        $category = null;
        try {
            $outcome = ($this->action)($message->companyId, $message->connectionId) ? 'succeeded' : 'skipped';
        } catch (CatalogSyncException $error) {
            $runId = $error->runId;
            $category = $error->category;
            if (!in_array($error->category, ['rate_limited', 'temporary'], true)) {
                return;
            }
            if ($message->attempt >= 3) {
                $outcome = 'retry_exhausted';
                $this->logger->error('MoySklad catalog sync retry budget exhausted', [...$context, 'runId' => $runId, 'category' => $category]);

                return;
            }
            $delay = min(3_600_000, max($error->retryAfterMs ?? 0, 10_000 * (2 ** $message->attempt)));
            $this->bus->dispatch(new SyncCatalogMessage($message->companyId, $message->connectionId, $message->attempt + 1), [new DelayStamp($delay)]);
            $outcome = 'retry_scheduled';
        } finally {
            $this->logger->info('MoySklad catalog sync message finished', [...$context, 'runId' => $runId, 'outcome' => $outcome, 'category' => $category]);
        }
    }
}

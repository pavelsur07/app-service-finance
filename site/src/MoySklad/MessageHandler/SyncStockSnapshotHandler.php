<?php

declare(strict_types=1);

namespace App\MoySklad\MessageHandler;

use App\MoySklad\Application\Action\SyncStockSnapshotAction;
use App\MoySklad\Exception\StockSyncException;
use App\MoySklad\Message\SyncStockSnapshotMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class SyncStockSnapshotHandler
{
    public function __construct(private SyncStockSnapshotAction $action, private MessageBusInterface $bus, private LoggerInterface $logger)
    {
    }

    public function __invoke(SyncStockSnapshotMessage $message): void
    {
        $context = [
            'companyId' => $message->companyId,
            'connectionId' => $message->connectionId,
            'attempt' => $message->attempt,
        ];
        $this->logger->info('MoySklad stock snapshot sync message started', $context);
        $outcome = 'failed';
        $snapshotId = null;
        $runId = null;
        $category = null;
        try {
            $snapshotId = ($this->action)($message->companyId, $message->connectionId);
            $outcome = null === $snapshotId ? 'skipped' : 'succeeded';
        } catch (StockSyncException $error) {
            $runId = $error->runId;
            $category = $error->category;
            if (!in_array($category, ['rate_limited', 'temporary'], true)) {
                return;
            }
            if ($message->attempt >= 3) {
                $outcome = 'retry_exhausted';
                $this->logger->error('MoySklad stock snapshot sync retry budget exhausted', [
                    ...$context,
                    'runId' => $runId,
                    'category' => $category,
                ]);

                return;
            }
            $delay = min(3_600_000, max($error->retryAfterMs ?? 0, 10_000 * (2 ** $message->attempt)));
            $this->bus->dispatch(new SyncStockSnapshotMessage($message->companyId, $message->connectionId, $message->attempt + 1), [new DelayStamp($delay)]);
            $outcome = 'retry_scheduled';
        } finally {
            $this->logger->info('MoySklad stock snapshot sync message finished', [
                ...$context,
                'snapshotId' => $snapshotId,
                'runId' => $runId,
                'outcome' => $outcome,
                'category' => $category,
            ]);
        }
    }
}

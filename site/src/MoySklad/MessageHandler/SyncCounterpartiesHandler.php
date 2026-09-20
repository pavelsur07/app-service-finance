<?php

declare(strict_types=1);

namespace App\MoySklad\MessageHandler;

use App\MoySklad\Application\Action\SyncCounterpartiesAction;
use App\MoySklad\Exception\CounterpartySyncException;
use App\MoySklad\Message\SyncCounterpartiesMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class SyncCounterpartiesHandler
{
    public function __construct(private SyncCounterpartiesAction $action, private MessageBusInterface $bus, private LoggerInterface $logger)
    {
    }

    public function __invoke(SyncCounterpartiesMessage $message): void
    {
        $context = [
            'companyId' => $message->companyId,
            'connectionId' => $message->connectionId,
            'entityType' => 'counterparty',
            'attempt' => $message->attempt,
        ];
        $this->logger->info('MoySklad counterparty sync message started', $context);
        $outcome = 'failed';
        $runId = null;
        $category = null;
        try {
            $run = ($this->action)($message->companyId, $message->connectionId);
            if (null === $run) {
                $outcome = 'skipped';

                return;
            }
            $runId = $run->getId();
            $outcome = 'succeeded';
        } catch (CounterpartySyncException $error) {
            $runId = $error->runId;
            $category = $error->category;
            if (!in_array($error->category, ['rate_limited', 'temporary'], true)) {
                return;
            }
            if ($message->attempt >= 3) {
                $outcome = 'retry_exhausted';
                $this->logger->error('MoySklad counterparty sync retry budget exhausted', [
                    ...$context,
                    'runId' => $error->runId,
                    'category' => $error->category,
                ]);

                return;
            }

            $delay = min(3_600_000, max($error->retryAfterMs ?? 0, 10_000 * (2 ** $message->attempt)));
            $this->bus->dispatch(new SyncCounterpartiesMessage($message->companyId, $message->connectionId, $message->attempt + 1), [new DelayStamp($delay)]);
            $outcome = 'retry_scheduled';
        } finally {
            $this->logger->info('MoySklad counterparty sync message finished', [
                ...$context,
                'runId' => $runId,
                'outcome' => $outcome,
                'category' => $category,
            ]);
        }
    }
}

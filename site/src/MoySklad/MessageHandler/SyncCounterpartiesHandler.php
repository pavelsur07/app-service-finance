<?php

declare(strict_types=1);

namespace App\MoySklad\MessageHandler;

use App\MoySklad\Application\Action\SyncCounterpartiesAction;
use App\MoySklad\Exception\CounterpartySyncException;
use App\MoySklad\Message\SyncCounterpartiesMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class SyncCounterpartiesHandler
{
    public function __construct(private SyncCounterpartiesAction $action, private MessageBusInterface $bus)
    {
    }

    public function __invoke(SyncCounterpartiesMessage $message): void
    {
        try {
            ($this->action)($message->companyId, $message->connectionId);
        } catch (CounterpartySyncException $error) {
            if (!in_array($error->category, ['rate_limited', 'temporary'], true) || $message->attempt >= 3) {
                return;
            }

            $delay = min(3_600_000, max($error->retryAfterMs ?? 0, 10_000 * (2 ** $message->attempt)));
            $this->bus->dispatch(new SyncCounterpartiesMessage($message->companyId, $message->connectionId, $message->attempt + 1), [new DelayStamp($delay)]);
        }
    }
}

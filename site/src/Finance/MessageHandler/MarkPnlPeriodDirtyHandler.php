<?php

declare(strict_types=1);

namespace App\Finance\MessageHandler;

use App\Finance\Application\Action\MarkPnlPeriodDirtyAction;
use App\Finance\Application\Command\MarkPnlPeriodDirtyCommand;
use App\Finance\Message\MarkPnlPeriodDirtyMessage;
use App\Ingestion\Enum\PLDirtyPeriodReason;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class MarkPnlPeriodDirtyHandler
{
    public function __construct(private MarkPnlPeriodDirtyAction $action)
    {
    }

    public function __invoke(MarkPnlPeriodDirtyMessage $message): void
    {
        ($this->action)(new MarkPnlPeriodDirtyCommand(
            companyId: $message->companyId,
            year: $message->year,
            month: $message->month,
            shopRef: $message->shopRef,
            reason: PLDirtyPeriodReason::from($message->reasonValue),
        ));
    }
}

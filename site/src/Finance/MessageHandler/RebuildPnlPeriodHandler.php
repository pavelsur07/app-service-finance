<?php

declare(strict_types=1);

namespace App\Finance\MessageHandler;

use App\Finance\Application\Action\RebuildPnlPeriodAction;
use App\Finance\Application\Command\RebuildPnlPeriodCommand;
use App\Finance\Message\RebuildPnlPeriodMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RebuildPnlPeriodHandler
{
    public function __construct(private RebuildPnlPeriodAction $action)
    {
    }

    public function __invoke(RebuildPnlPeriodMessage $message): void
    {
        ($this->action)(new RebuildPnlPeriodCommand(
            companyId: $message->companyId,
            year: $message->year,
            month: $message->month,
            shopRef: $message->shopRef,
        ));
    }
}

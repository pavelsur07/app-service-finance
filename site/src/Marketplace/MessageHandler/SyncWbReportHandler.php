<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Message\SyncWbReportMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Legacy WB report sync entrypoint.
 *
 * Disabled after migration to the day-by-day finance pipeline.
 */
#[AsMessageHandler]
final class SyncWbReportHandler
{
    private const ERROR_MESSAGE = 'Legacy SyncWbReportMessage is disabled. Use the new pipeline SyncWbFinancialReportDayMessage.';

    public function __invoke(SyncWbReportMessage $message): void
    {
        throw new UnrecoverableMessageHandlingException(self::ERROR_MESSAGE);
    }
}

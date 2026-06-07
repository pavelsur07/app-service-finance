<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use App\Marketplace\Message\SyncWbReportMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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

    public function __construct(
        #[Autowire(service: 'monolog.logger.legacy_wb_sync')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncWbReportMessage $message): void
    {
        $this->logger->error('Legacy WB report message fail-fast triggered.', [
            'legacy_event' => 'legacy_wb_sync_fail_fast',
            'company_id' => $message->companyId,
            'connection_id' => $message->connectionId,
            'command_class' => null,
            'message_class' => $message::class,
            'recommended_replacement' => SyncWbFinancialReportDayMessage::class,
        ]);

        throw new UnrecoverableMessageHandlingException(self::ERROR_MESSAGE);
    }
}

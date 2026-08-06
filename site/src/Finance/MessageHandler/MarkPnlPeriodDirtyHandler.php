<?php

declare(strict_types=1);

namespace App\Finance\MessageHandler;

use App\Finance\Message\MarkPnlPeriodDirtyMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class MarkPnlPeriodDirtyHandler
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(MarkPnlPeriodDirtyMessage $message): void
    {
        $this->logger->info('Discarded legacy Ingestion P&L dirty-period message.', [
            'companyId' => $message->companyId,
            'year' => $message->year,
            'month' => $message->month,
            'shopRef' => $message->shopRef,
            'reason' => $message->reasonValue,
        ]);
    }
}

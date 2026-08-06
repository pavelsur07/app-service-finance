<?php

declare(strict_types=1);

namespace App\Finance\MessageHandler;

use App\Finance\Message\RebuildPnlPeriodMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RebuildPnlPeriodHandler
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(RebuildPnlPeriodMessage $message): void
    {
        $this->logger->info('Discarded legacy Ingestion P&L rebuild message.', [
            'companyId' => $message->companyId,
            'year' => $message->year,
            'month' => $message->month,
            'shopRef' => $message->shopRef,
        ]);
    }
}

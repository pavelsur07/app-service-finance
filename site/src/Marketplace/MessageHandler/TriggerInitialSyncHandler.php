<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Service\MarketplaceWeekPartitionService;
use App\Marketplace\Message\InitialSyncMessage;
use App\Marketplace\Message\TriggerInitialSyncMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Нарезает текущий год на недельные партии с учётом границ месяца и диспатчит первую.
 * Каждая следующая партия диспатчится из InitialSyncHandler после успеха предыдущей.
 *
 * Период: 01.01 текущего года → вчера (с времени 00:00:00 → 23:59:59).
 * За сегодня данные ещё неполные — их загрузит ежедневный cron завтра.
 */
#[AsMessageHandler]
final class TriggerInitialSyncHandler
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly MarketplaceWeekPartitionService $partitionService,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(TriggerInitialSyncMessage $message): void
    {
        $yesterday = $this->clock->now()->modify('-1 day')->setTime(0, 0, 0);
        $yearStart = new \DateTimeImmutable((int) $yesterday->format('Y') . '-01-01 00:00:00');
        $weeks = $this->partitionService->buildPartitions($yearStart, $yesterday);

        if (empty($weeks)) {
            $this->logger->warning('InitialSync: no weeks to sync', [
                'company_id'    => $message->companyId,
                'connection_id' => $message->connectionId,
            ]);

            return;
        }

        // Диспатчим только первую партию — цепочка продолжится из InitialSyncHandler
        $first  = $weeks[0];
        $second = $weeks[1] ?? null;

        $this->messageBus->dispatch(new InitialSyncMessage(
            companyId:    $message->companyId,
            connectionId: $message->connectionId,
            marketplace:  $message->marketplace,
            dateFrom:     $first['from'],
            dateTo:       $first['to'],
            nextDateFrom: $second ? $second['from'] : null,
            nextDateTo:   $second ? $second['to']   : null,
        ));

        $this->logger->info('InitialSync: dispatched first batch', [
            'company_id'    => $message->companyId,
            'marketplace'   => $message->marketplace,
            'date_from'     => $first['from'],
            'date_to'       => $first['to'],
            'total_batches' => count($weeks),
        ]);
    }

}

<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Service\MarketplaceWeekPartitionService;
use App\Marketplace\Application\Service\WbInitialSyncStartDateResolver;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Message\InitialSyncMessage;
use App\Marketplace\Message\TriggerInitialSyncMessage;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
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
    private const WB_RATE_LIMIT_FALLBACK_DELAY_SECONDS = 600;
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly MarketplaceWeekPartitionService $partitionService,
        private readonly ClockInterface $clock,
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly WbInitialSyncStartDateResolver $wbStartDateResolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(TriggerInitialSyncMessage $message): void
    {
        // InitialSync — это бэкфилл продаж/возвратов/затрат через Seller API.
        // Для Performance-подключений исторических данных по этой цепочке нет,
        // поэтому триггер выполняется только для SELLER.
        $connection = $this->connectionRepository->find($message->connectionId);

        if (null === $connection || MarketplaceConnectionType::SELLER !== $connection->getConnectionType()) {
            $this->logger->warning('InitialSync: skipped — connection missing or not SELLER', [
                'company_id'      => $message->companyId,
                'connection_id'   => $message->connectionId,
                'connection_type' => $connection?->getConnectionType()->value,
            ]);

            return;
        }

        $yesterday = $this->clock->now()->modify('-1 day')->setTime(0, 0, 0);
        $yearStart = new \DateTimeImmutable((int) $yesterday->format('Y') . '-01-01 00:00:00');
        $syncStart = $yearStart;

        if (MarketplaceType::WILDBERRIES === $connection->getMarketplace()) {
            try {
                $resolved = $this->wbStartDateResolver->resolve($connection->getCompany(), $connection);
            } catch (MarketplaceRateLimitException $e) {
                $retrySeconds = max(1, $e->getRetryAfter() ?? self::WB_RATE_LIMIT_FALLBACK_DELAY_SECONDS);
                $this->logger->warning('InitialSync trigger delayed by WB rate limit during discovery', [
                    'company_id' => $message->companyId,
                    'connection_id' => $message->connectionId,
                    'marketplace' => $message->marketplace,
                    'date_from' => $e->getDateFrom(),
                    'date_to' => $e->getDateTo(),
                    'retry_after' => $e->getRetryAfter(),
                ]);

                throw new RecoverableMessageHandlingException(
                    'InitialSync trigger rate limited by Wildberries discovery.',
                    retryDelay: $retrySeconds * 1000,
                    previous: $e,
                );
            }

            if (null === $resolved) {
                $connection->markSyncSuccess();
                $this->entityManager->flush();
                $this->logger->info('InitialSync: WB no data found for current year, sync completed without batches.', [
                    'company_id' => $message->companyId,
                    'connection_id' => $message->connectionId,
                    'marketplace' => $message->marketplace,
                ]);

                return;
            }

            $syncStart = $resolved;
        }

        $weeks = $this->partitionService->buildPartitions($syncStart, $yesterday);

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

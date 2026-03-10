<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Message\InitialSyncMessage;
use App\Marketplace\Message\TriggerInitialSyncMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Нарезает текущий год на недельные партии Пн–Вс и диспатчит первую.
 * Каждая следующая партия диспатчится из InitialSyncHandler после успеха предыдущей.
 *
 * Правило первой партии:
 * - Берём 01.01 текущего года
 * - Если это не Пн — откатываемся до ближайшего предыдущего Пн (захватываем конец прошлого года)
 */
#[AsMessageHandler]
final class TriggerInitialSyncHandler
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TriggerInitialSyncMessage $message): void
    {
        $weeks = $this->buildWeeks();

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

    /**
     * Строит список недельных партий от первого Пн ≤ 01.01 до сегодня.
     *
     * @return array<int, array{from: string, to: string}>
     */
    private function buildWeeks(): array
    {
        $today = new \DateTimeImmutable('today');
        $yearStart = new \DateTimeImmutable((int) $today->format('Y') . '-01-01');

        // Откатываемся до ближайшего предыдущего Пн (или оставляем если уже Пн)
        $dayOfWeek = (int) $yearStart->format('N'); // 1=Пн, 7=Вс
        $firstMonday = $dayOfWeek === 1
            ? $yearStart
            : $yearStart->modify('-' . ($dayOfWeek - 1) . ' days');

        $weeks  = [];
        $monday = $firstMonday;

        while ($monday <= $today) {
            $sunday = $monday->modify('+6 days'); // Вс

            // Последняя партия — до сегодня если неделя неполная
            $partitionEnd = $sunday > $today ? $today : $sunday;

            $weeks[] = [
                'from' => $monday->format('Y-m-d'),
                'to'   => $partitionEnd->format('Y-m-d'),
            ];

            // Следующий Пн
            $monday = $monday->modify('+7 days');
        }

        return $weeks;
    }
}

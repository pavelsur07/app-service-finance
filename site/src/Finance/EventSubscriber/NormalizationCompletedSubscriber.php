<?php

declare(strict_types=1);

namespace App\Finance\EventSubscriber;

use App\Finance\Application\Service\PnlPeriodResolver;
use App\Finance\Message\MarkPnlPeriodDirtyMessage;
use App\Ingestion\Domain\Event\AffectedPeriod;
use App\Ingestion\Domain\Event\NormalizationCompletedEvent;
use App\Ingestion\Enum\PLDirtyPeriodReason;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class NormalizationCompletedSubscriber implements EventSubscriberInterface
{
    private const GLOBAL_SHOP_REF = '';

    public function __construct(
        private PnlPeriodResolver $periodResolver,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            NormalizationCompletedEvent::class => 'onNormalizationCompleted',
        ];
    }

    public function onNormalizationCompleted(NormalizationCompletedEvent $event): void
    {
        $dispatched = [];

        foreach ($event->affectedPeriods as $period) {
            if (!$period instanceof AffectedPeriod) {
                continue;
            }

            [$newYear, $newMonth] = $this->periodResolver->from($period->newOccurredAt);
            $this->dispatchOnce($dispatched, $event->companyId, $newYear, $newMonth, self::GLOBAL_SHOP_REF, PLDirtyPeriodReason::INGEST);

            if (null === $period->oldOccurredAt) {
                continue;
            }

            [$oldYear, $oldMonth] = $this->periodResolver->from($period->oldOccurredAt);
            if ($oldYear !== $newYear || $oldMonth !== $newMonth) {
                $this->dispatchOnce($dispatched, $event->companyId, $oldYear, $oldMonth, self::GLOBAL_SHOP_REF, PLDirtyPeriodReason::MONTH_CHANGE);
            }
        }
    }

    /**
     * @param array<string, true> $dispatched
     */
    private function dispatchOnce(
        array &$dispatched,
        string $companyId,
        int $year,
        int $month,
        string $shopRef,
        PLDirtyPeriodReason $reason,
    ): void {
        $key = sprintf('%s:%04d-%02d:%s', $companyId, $year, $month, $shopRef);
        if (isset($dispatched[$key])) {
            return;
        }

        $dispatched[$key] = true;
        $this->messageBus->dispatch(new MarkPnlPeriodDirtyMessage(
            companyId: $companyId,
            year: $year,
            month: $month,
            shopRef: $shopRef,
            reasonValue: $reason->value,
        ));
    }
}

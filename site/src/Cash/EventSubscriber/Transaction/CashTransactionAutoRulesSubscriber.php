<?php

namespace App\Cash\EventSubscriber\Transaction;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Message\EnqueueAutoRulesForRange;
use App\Service\DebouncedRangeEnqueuer;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsDoctrineListener(event: Events::postPersist)]
final class CashTransactionAutoRulesSubscriber implements EventSubscriber
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly DebouncedRangeEnqueuer $debouncer,
        #[Autowire(service: 'monolog.logger.autorules')]
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::postPersist];
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof CashTransaction) {
            return;
        }

        $companyId = $entity->getCompany()->getId();
        $occurredAt = $entity->getOccurredAt();
        $dayStart = $occurredAt->setTime(0, 0, 0);
        $dayEnd = $occurredAt->setTime(23, 59, 59);

        if ($this->debouncer->shouldEnqueueCompanyDay($companyId, $dayStart)) {
            $filters = ['moneyAccountId' => $entity->getMoneyAccount()?->getId()];
            $this->bus->dispatch(
                new EnqueueAutoRulesForRange($companyId, $dayStart, $dayEnd, $filters),
                [new DelayStamp(10000)]
            );
            $this->logger?->info('[AutoRules] enqueued', [
                'companyId' => $companyId,
                'day' => $dayStart->format('Y-m-d'),
                'filters' => $filters,
            ]);
        } else {
            $this->logger?->debug('[AutoRules] skipped_duplicate', [
                'companyId' => $companyId,
                'day' => $dayStart->format('Y-m-d'),
            ]);
        }
    }
}

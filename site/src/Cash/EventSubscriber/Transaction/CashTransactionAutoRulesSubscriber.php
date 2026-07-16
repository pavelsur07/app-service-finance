<?php

namespace App\Cash\EventSubscriber\Transaction;

use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Application\Service\DebouncedRangeEnqueuer;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Message\ApplyAutoRulesForTransaction;
use App\Cash\Message\EnqueueAutoRulesForRange;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class CashTransactionAutoRulesSubscriber implements EventSubscriber
{
    private const MATCH_INPUT_FIELDS = [
        'moneyAccount',
        'counterparty',
        'direction',
        'amount',
        'occurredAt',
        'description',
    ];

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly DebouncedRangeEnqueuer $debouncer,
        private readonly AutoRuleDispatchGuard $dispatchGuard,
        #[Autowire(service: 'monolog.logger.autorules')]
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::postPersist, Events::postUpdate];
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof CashTransaction || $this->dispatchGuard->isSuppressed()) {
            return;
        }

        $changeSet = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity);
        if ([] === array_intersect(self::MATCH_INPUT_FIELDS, array_keys($changeSet))) {
            return;
        }

        $this->enqueue($entity);
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof CashTransaction || $this->dispatchGuard->isSuppressed()) {
            return;
        }

        $this->enqueue($entity);
    }

    private function enqueue(CashTransaction $entity): void
    {
        $companyId = (string) $entity->getCompany()->getId();
        $moneyAccountId = (string) $entity->getMoneyAccount()->getId();
        $occurredAt = $entity->getOccurredAt();
        $dayStart = $occurredAt->setTime(0, 0, 0);
        $dayEnd = $occurredAt->setTime(23, 59, 59);
        $correlationId = Uuid::uuid7()->toString();

        if ($this->debouncer->shouldEnqueueCompanyDay($companyId, $dayStart, $moneyAccountId)) {
            $accountIds = [$moneyAccountId];
            $this->bus->dispatch(
                new EnqueueAutoRulesForRange($companyId, $dayStart, $dayEnd, $accountIds, $correlationId),
                [new DelayStamp(10000)]
            );
            $this->logger?->info('[AutoRules] enqueued', [
                'companyId' => $companyId,
                'correlationId' => $correlationId,
                'day' => $dayStart->format('Y-m-d'),
                'moneyAccountIds' => $accountIds,
            ]);
        } else {
            $this->bus->dispatch(
                new ApplyAutoRulesForTransaction((string) $entity->getId(), $companyId, new \DateTimeImmutable(), $correlationId),
                [new DelayStamp(10000)]
            );
            $this->logger?->debug('[AutoRules] enqueued_transaction_fallback', [
                'companyId' => $companyId,
                'correlationId' => $correlationId,
                'day' => $dayStart->format('Y-m-d'),
                'moneyAccountId' => $moneyAccountId,
                'transactionId' => $entity->getId(),
            ]);
        }
    }
}

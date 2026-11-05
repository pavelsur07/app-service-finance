<?php

declare(strict_types=1);

namespace App\Telegram\EventListener;

use App\Company\Event\CompanyDeletionRequestedEvent;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\ReportSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Компания помечена к удалению: бот не принимает операции от её клиентов (привязки
 * блокируются) и не шлёт отчёты (подписки выключаются). Только меняет сущности —
 * коммитит Action запроса удаления. При восстановлении компании включает пользователь.
 */
#[AsEventListener]
final readonly class BlockBindingsOnCompanyDeletionRequested
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(CompanyDeletionRequestedEvent $event): void
    {
        /** @var list<ClientBinding> $bindings */
        $bindings = $this->entityManager->getRepository(ClientBinding::class)
            ->findBy(['company' => $event->companyId, 'status' => ClientBinding::STATUS_ACTIVE]);
        foreach ($bindings as $binding) {
            $binding->block();
        }

        /** @var list<ReportSubscription> $subscriptions */
        $subscriptions = $this->entityManager->getRepository(ReportSubscription::class)
            ->findBy(['company' => $event->companyId, 'isEnabled' => true]);
        foreach ($subscriptions as $subscription) {
            $subscription->disable();
        }
    }
}

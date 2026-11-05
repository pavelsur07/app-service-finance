<?php

declare(strict_types=1);

namespace App\BankSync\EventListener;

use App\BankSync\Enum\BankConnectionStatus;
use App\BankSync\Repository\BankConnectionRepository;
use App\Company\Event\CompanyDeletionRequestedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Компания помечена к удалению: выписки из банков больше не загружаются. Подключения
 * в ACTIVE / NEEDS_REAUTH переводятся в DISABLED; отозванные и отключённые не трогаются.
 * Согласие в банке не отзывается — это внешний вызов, ему не место в транзакции запроса.
 * Только меняет сущности — коммитит Action запроса удаления.
 */
#[AsEventListener]
final readonly class DisableConnectionsOnCompanyDeletionRequested
{
    public function __construct(private BankConnectionRepository $connections)
    {
    }

    public function __invoke(CompanyDeletionRequestedEvent $event): void
    {
        foreach ($this->connections->findByCompanyId($event->companyId) as $connection) {
            if ($connection->canSync() || BankConnectionStatus::NEEDS_REAUTH === $connection->getStatus()) {
                $connection->disable();
            }
        }
    }
}

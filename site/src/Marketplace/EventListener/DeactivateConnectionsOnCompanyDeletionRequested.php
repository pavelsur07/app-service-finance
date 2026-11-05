<?php

declare(strict_types=1);

namespace App\Marketplace\EventListener;

use App\Company\Event\CompanyDeletionRequestedEvent;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Компания помечена к удалению: синхронизации с маркетплейсами (Seller и Performance,
 * в т.ч. ozon-fetcher / wb-fetcher по `is_active`) больше не запускаются. Только меняет
 * сущности — коммитит Action запроса удаления. При восстановлении компании подключения
 * включает пользователь.
 */
#[AsEventListener]
final readonly class DeactivateConnectionsOnCompanyDeletionRequested
{
    public function __construct(private MarketplaceConnectionRepository $connections)
    {
    }

    public function __invoke(CompanyDeletionRequestedEvent $event): void
    {
        foreach ($this->connections->findActiveByCompanyId($event->companyId) as $connection) {
            $connection->setIsActive(false);
        }
    }
}

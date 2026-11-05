<?php

declare(strict_types=1);

namespace App\MoySklad\EventListener;

use App\Company\Event\CompanyDeletionRequestedEvent;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Компания помечена к удалению: синхронизации с МойСклад больше не запускаются.
 * Только меняет сущности — коммитит Action запроса удаления.
 */
#[AsEventListener]
final readonly class DeactivateConnectionsOnCompanyDeletionRequested
{
    public function __construct(private MoySkladConnectionWriteRepository $connections)
    {
    }

    public function __invoke(CompanyDeletionRequestedEvent $event): void
    {
        $afterId = null;
        do {
            $batch = $this->connections->findBatchForCompany($event->companyId, $afterId);
            foreach ($batch as $connection) {
                if ($connection->isActive()) {
                    $connection->setIsActive(false);
                }
                $afterId = $connection->getId();
            }
        } while (100 === \count($batch));
    }
}

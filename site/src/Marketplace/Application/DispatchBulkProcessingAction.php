<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\DTO\BulkProcessMonthCommand;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Message\ProcessRawDocumentStepMessage;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Сбрасывает статус обработки всех sales_report-документов за месяц
 * и диспатчит три асинхронных шага (sales/returns/costs) для каждого.
 *
 * flush() вызывается один раз после цикла.
 */
final class DispatchBulkProcessingAction
{
    public function __construct(
        private readonly MarketplaceRawDocumentRepository $repository,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(BulkProcessMonthCommand $cmd): int
    {
        $documents = $this->repository->findForBulkProcessing(
            $cmd->companyId,
            $cmd->marketplace,
            $cmd->year,
            $cmd->month,
        );

        foreach ($documents as $doc) {
            $doc->resetProcessingStatus();

            foreach (PipelineStep::cases() as $step) {
                $this->bus->dispatch(new ProcessRawDocumentStepMessage(
                    rawDocumentId: (string) $doc->getId(),
                    step: $step->value,
                    companyId: $cmd->companyId,
                ));
            }
        }

        $this->entityManager->flush();

        return count($documents);
    }
}

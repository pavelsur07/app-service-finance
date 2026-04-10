<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\ProcessRawDocumentStepMessage;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Запускает daily pipeline (sales/returns/costs) для всех sales_report
 * документов за указанную дату компании+маркетплейса.
 *
 * Идемпотентен: перезапускает pipeline даже если за эту дату уже был запуск.
 */
#[AsMessageHandler]
final class ProcessDayReportHandler
{
    public function __construct(
        private readonly MarketplaceRawDocumentRepository $repository,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessDayReportMessage $message): void
    {
        $marketplace = MarketplaceType::from($message->marketplace);
        $date = new \DateTimeImmutable($message->date);

        $documents = $this->repository->findByCompanyAndPeriod(
            $message->companyId,
            $marketplace,
            $date,
            $date,
            'sales_report',
        );

        if ($documents === []) {
            $this->logger->warning('No sales_report documents found for auto-processing', [
                'company_id'  => $message->companyId,
                'marketplace' => $message->marketplace,
                'date'        => $message->date,
            ]);

            return;
        }

        foreach ($documents as $doc) {
            $doc->resetProcessingStatus();
        }

        $this->entityManager->flush();

        foreach ($documents as $doc) {
            foreach (PipelineStep::cases() as $step) {
                $this->bus->dispatch(new ProcessRawDocumentStepMessage(
                    rawDocumentId: $doc->getId(),
                    step: $step->value,
                    companyId: $message->companyId,
                ));
            }
        }

        $this->logger->info('Auto-dispatched pipeline for day report', [
            'company_id'      => $message->companyId,
            'marketplace'     => $message->marketplace,
            'date'            => $message->date,
            'documents_count' => count($documents),
        ]);
    }
}

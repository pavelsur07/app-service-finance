<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\ProcessRawDocumentStepMessage;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Запускает daily pipeline (sales/returns/costs) для конкретного
 * MarketplaceRawDocument после его загрузки.
 *
 * Идемпотентен: перезапускает pipeline даже если документ уже был обработан.
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
        $doc = $this->repository->find($message->rawDocumentId);

        if ($doc === null) {
            throw new UnrecoverableMessageHandlingException(
                sprintf('MarketplaceRawDocument not found: %s', $message->rawDocumentId),
            );
        }

        if ((string) $doc->getCompany()->getId() !== $message->companyId) {
            throw new UnrecoverableMessageHandlingException(
                sprintf('IDOR: document %s does not belong to company %s', $message->rawDocumentId, $message->companyId),
            );
        }

        $doc->resetProcessingStatus();
        $this->entityManager->flush();

        foreach (PipelineStep::cases() as $step) {
            $this->bus->dispatch(new ProcessRawDocumentStepMessage(
                rawDocumentId: $doc->getId(),
                step: $step->value,
                companyId: $message->companyId,
            ));
        }

        $this->logger->info('Auto-dispatched pipeline for raw document', [
            'company_id'      => $message->companyId,
            'raw_document_id' => $message->rawDocumentId,
            'marketplace'     => $doc->getMarketplace()->value,
        ]);
    }
}

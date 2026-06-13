<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Service\WbFinancialReportSyncStatusUpdaterInterface;
use App\Marketplace\Application\Service\WbGeneratedRowsSafeReplaceServiceInterface;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Exception\WbGeneratedRowsConflictException;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\ProcessRawDocumentStepMessage;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusLookupInterface;
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
        private readonly WbGeneratedRowsSafeReplaceServiceInterface $safeReplaceService,
        private readonly MarketplaceFinancialReportSyncStatusLookupInterface $syncStatusRepository,
        private readonly WbFinancialReportSyncStatusUpdaterInterface $syncStatusUpdater,
    ) {
    }

    public function __invoke(ProcessDayReportMessage $message): void
    {
        $doc = $this->repository->find($message->rawDocumentId);

        if (null === $doc) {
            throw new UnrecoverableMessageHandlingException(sprintf('MarketplaceRawDocument not found: %s', $message->rawDocumentId));
        }

        if ((string) $doc->getCompany()->getId() !== $message->companyId) {
            throw new UnrecoverableMessageHandlingException(sprintf('IDOR: document %s does not belong to company %s', $message->rawDocumentId, $message->companyId));
        }

        if ($message->forceRefresh
            && MarketplaceType::WILDBERRIES === $doc->getMarketplace()
            && 'sales_report' === $doc->getDocumentType()
        ) {
            try {
                $this->safeReplaceService->cleanupForRawDocument($doc->getCompany(), $doc->getId(), $doc->getPeriodFrom());
            } catch (WbGeneratedRowsConflictException $e) {
                $status = $this->findExactSyncStatusForMessage($message);
                if (null !== $status) {
                    $this->syncStatusUpdater->markConflict($status, $e::class, $e->getMessage());
                    $this->entityManager->flush();
                }

                $this->logger->warning('WB day processing conflict: linked document rows prevent refresh', [
                    'company_id' => $message->companyId,
                    'raw_document_id' => $message->rawDocumentId,
                    'business_date' => $doc->getPeriodFrom()->format('Y-m-d'),
                ]);

                throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
            }
        }

        $doc->resetProcessingStatus();
        $this->entityManager->flush();

        foreach (PipelineStep::cases() as $step) {
            $this->bus->dispatch(new ProcessRawDocumentStepMessage(
                rawDocumentId: $doc->getId(),
                step: $step->value,
                companyId: $message->companyId,
                syncStatusId: $message->syncStatusId,
                connectionId: $message->connectionId,
                marketplace: $message->marketplace,
                reportType: $message->reportType,
                mode: $message->mode,
                businessDate: $message->businessDate,
            ));
        }

        $this->logger->info('Auto-dispatched pipeline for raw document', [
            'company_id' => $message->companyId,
            'raw_document_id' => $message->rawDocumentId,
            'marketplace' => $doc->getMarketplace()->value,
        ]);
    }

    private function findExactSyncStatusForMessage(ProcessDayReportMessage $message): ?MarketplaceFinancialReportSyncStatus
    {
        if (null === $message->connectionId) {
            $this->logger->warning('WB day processing conflict: cannot mark sync status without exact connection context', [
                'company_id' => $message->companyId,
                'raw_document_id' => $message->rawDocumentId,
                'sync_status_id' => $message->syncStatusId,
            ]);

            return null;
        }

        $marketplace = MarketplaceType::tryFrom((string) ($message->marketplace ?? MarketplaceType::WILDBERRIES->value));
        $mode = null === $message->mode ? null : FinancialReportSyncMode::tryFrom($message->mode);
        $businessDate = $this->parseBusinessDate($message->businessDate);

        if (null === $marketplace || null === $mode || null === $businessDate || null === $message->reportType) {
            $this->logger->warning('WB day processing conflict: cannot mark sync status because exact context is incomplete', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'raw_document_id' => $message->rawDocumentId,
                'sync_status_id' => $message->syncStatusId,
                'marketplace' => $message->marketplace,
                'report_type' => $message->reportType,
                'mode' => $message->mode,
                'business_date' => $message->businessDate,
            ]);

            return null;
        }

        $status = $this->syncStatusRepository->findByRawPipelineContext(
            $message->syncStatusId,
            $message->companyId,
            $message->connectionId,
            $marketplace,
            $message->reportType,
            $mode,
            $businessDate,
            $message->rawDocumentId,
        );

        if (null === $status) {
            $this->logger->warning('WB day processing conflict: no sync status matches exact raw document context', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'raw_document_id' => $message->rawDocumentId,
                'sync_status_id' => $message->syncStatusId,
                'marketplace' => $marketplace->value,
                'report_type' => $message->reportType,
                'mode' => $mode->value,
                'business_date' => $businessDate->format('Y-m-d'),
            ]);
        }

        return $status;
    }

    private function parseBusinessDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTime(0, 0);
        } catch (\Throwable) {
            return null;
        }
    }
}

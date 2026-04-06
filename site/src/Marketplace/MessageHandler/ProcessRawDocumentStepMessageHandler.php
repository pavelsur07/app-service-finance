<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Message\ProcessRawDocumentStepMessage;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Выполняет один шаг обработки (sales/returns/costs) для одного RawDocument.
 * Обновляет processingStatus документа — succeeded или failed.
 * При ошибке rethrow-ит исключение чтобы Messenger сделал retry.
 */
#[AsMessageHandler]
final class ProcessRawDocumentStepMessageHandler
{
    public function __construct(
        private readonly MarketplaceRawDocumentRepository $repository,
        private readonly ProcessMarketplaceRawDocumentAction $processAction,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ProcessRawDocumentStepMessage $message): void
    {
        $doc = $this->repository->find($message->rawDocumentId);

        if ($doc === null) {
            throw new \RuntimeException(
                sprintf('MarketplaceRawDocument not found: %s', $message->rawDocumentId),
            );
        }

        if ((string) $doc->getCompany()->getId() !== $message->companyId) {
            throw new \RuntimeException(
                sprintf('IDOR: document %s does not belong to company %s', $message->rawDocumentId, $message->companyId),
            );
        }

        $cmd = new ProcessMarketplaceRawDocumentCommand(
            companyId: $message->companyId,
            rawDocId:  $message->rawDocumentId,
            kind:      $message->step,
        );

        $step = PipelineStep::from($message->step);

        try {
            ($this->processAction)($cmd);
            $doc->markStepSucceeded($step);
        } catch (\Throwable $e) {
            $doc->markStepFailed($step);
            $this->entityManager->flush();
            throw $e;
        }

        $this->entityManager->flush();
    }
}

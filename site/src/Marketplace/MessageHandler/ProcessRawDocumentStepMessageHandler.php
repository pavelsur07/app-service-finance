<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Message\ProcessRawDocumentStepMessage;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

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
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    public function __invoke(ProcessRawDocumentStepMessage $message): void
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

        $cmd = new ProcessMarketplaceRawDocumentCommand(
            companyId:      $message->companyId,
            rawDocId:       $message->rawDocumentId,
            kind:           $message->step,
            forceReprocess: $message->step === PipelineStep::COSTS->value,
        );

        $step = PipelineStep::tryFrom($message->step);
        if ($step === null) {
            throw new UnrecoverableMessageHandlingException(
                sprintf('Invalid pipeline step "%s"', $message->step),
            );
        }

        try {
            ($this->processAction)($cmd);
            // Re-fetch: ProcessMarketplaceRawDocumentAction calls em->clear() after each batch,
            // which detaches $doc. Without re-fetch markStepSucceeded() would modify a ghost object.
            $doc = $this->repository->find($message->rawDocumentId);
            if ($doc === null) {
                throw new UnrecoverableMessageHandlingException(
                    sprintf('MarketplaceRawDocument vanished after processing: %s', $message->rawDocumentId),
                );
            }
            $doc->markStepSucceeded($step);
        } catch (\Throwable $e) {
            if (!$this->entityManager->isOpen()) {
                $this->managerRegistry->resetManager();
            }

            /** @var EntityManagerInterface $em */
            $em = $this->managerRegistry->getManager();
            $doc = $em->getRepository(MarketplaceRawDocument::class)->find($message->rawDocumentId);

            if ($doc !== null) {
                $doc->markStepFailed($step);
                $em->flush();
            }

            throw $e;
        }

        $this->entityManager->flush();
    }
}

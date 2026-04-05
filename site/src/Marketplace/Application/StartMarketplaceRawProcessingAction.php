<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\StartMarketplaceRawProcessingCommand;
use App\Marketplace\Domain\Service\ResolveMarketplaceRawProcessingProfile;
use App\Marketplace\Entity\MarketplaceRawProcessingRun;
use App\Marketplace\Entity\MarketplaceRawProcessingStepRun;
use App\Marketplace\Message\StartMarketplaceRawProcessingMessage;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Запускает полный daily processing run для raw-документа маркетплейса.
 *
 * Действия:
 *   1. Загрузить raw document (c IDOR-проверкой по companyId).
 *   2. Определить processing profile (resolver).
 *   3. Отклонить документы вне daily pipeline (realization и др.).
 *   4. Создать MarketplaceRawProcessingRun.
 *   5. Создать MarketplaceRawProcessingStepRun для каждого обязательного шага.
 *   6. Dispatch StartMarketplaceRawProcessingMessage → worker обрабатывает шаги.
 *
 * НЕ изменяет существующий ручной reprocess-flow.
 */
final class StartMarketplaceRawProcessingAction
{
    public function __construct(
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly ResolveMarketplaceRawProcessingProfile $profileResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * @return string ID созданного MarketplaceRawProcessingRun
     * @throws \DomainException если документ не найден, не принадлежит компании или вне daily pipeline
     */
    public function __invoke(StartMarketplaceRawProcessingCommand $cmd): string
    {
        // 1. Загрузить и авторизовать raw document
        $doc = $this->rawDocumentRepository->find($cmd->rawDocumentId);

        if ($doc === null || $doc->getCompany()->getId() !== $cmd->companyId) {
            throw new \DomainException(sprintf(
                'Raw document "%s" not found for company "%s".',
                $cmd->rawDocumentId,
                $cmd->companyId,
            ));
        }

        // 2. Определить processing profile
        $profile = $this->profileResolver->resolve($doc->getMarketplace(), $doc->getDocumentType());

        // 3. Отклонить документы вне daily pipeline
        if (!$profile->isDailyPipeline) {
            throw new \DomainException(sprintf(
                'Cannot start daily pipeline for document type "%s": %s',
                $doc->getDocumentType(),
                $profile->skipReason,
            ));
        }

        // 4. Создать processing run
        $run = new MarketplaceRawProcessingRun(
            $cmd->companyId,
            $doc->getId(),
            $doc->getMarketplace(),
            $doc->getDocumentType(),
            $cmd->trigger,
            $doc->getDocumentType(), // profileCode: для daily flow совпадает с documentType
        );
        $this->entityManager->persist($run);

        // 5. Создать step runs для каждого обязательного шага (PENDING)
        foreach ($profile->requiredSteps as $step) {
            $stepRun = new MarketplaceRawProcessingStepRun(
                $cmd->companyId,
                $run->getId(),
                $step,
            );
            $this->entityManager->persist($stepRun);
        }

        $this->entityManager->flush();

        // 6. Dispatch — worker запустит обработку шагов
        $this->bus->dispatch(new StartMarketplaceRawProcessingMessage(
            $cmd->companyId,
            $run->getId(),
        ));

        return $run->getId();
    }
}

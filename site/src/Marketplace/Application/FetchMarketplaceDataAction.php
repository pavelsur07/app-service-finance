<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Command\FetchMarketplaceDataCommand;
use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Infrastructure\Api\MarketplaceFetcherRegistry;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class FetchMarketplaceDataAction
{
    public function __construct(
        private MarketplaceFetcherRegistry $fetcherRegistry,
        private MarketplaceRawDocumentRepository $repository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(FetchMarketplaceDataCommand $command): void
    {
        $fetcher = $this->fetcherRegistry->get($command->type);

        foreach ($fetcher->fetch($command->companyId, $command->dateFrom) as $rawData) {
            $company = $this->entityManager->getReference(Company::class, $command->companyId);

            $document = new MarketplaceRawDocument(
                Uuid::uuid4()->toString(),
                $company,
                $command->type,
                $command->documentType,
            );

            $document
                ->setPeriodFrom($command->dateFrom)
                ->setPeriodTo($command->dateFrom)
                ->setApiEndpoint('infrastructure.fetcher')
                ->setRawData($rawData)
                ->setRecordsCount(0);

            $this->repository->save($document);

            $this->messageBus->dispatch(new ProcessMarketplaceRawDocumentCommand(
                companyId: $command->companyId,
                rawDocId: $document->getId(),
                kind: $command->processKind,
            ));

            // Защита от утечки памяти (Memory leak protection)
            $this->entityManager->clear();
        }
    }
}

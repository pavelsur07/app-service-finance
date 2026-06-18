<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\UpdateCursorCommand;
use App\Ingestion\Repository\IngestCursorRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UpdateCursorAction
{
    public function __construct(
        private IngestCursorRepository $cursorRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(UpdateCursorCommand $command): void
    {
        $cursor = $this->cursorRepository->getOrCreate(
            $command->companyId,
            $command->connectionRef,
            $command->resourceType,
            $command->shopRef,
        );

        $cursor->advance($command->newCursorValue, $command->syncJobId, $command->fetchedAt);
        $this->entityManager->flush();
    }
}

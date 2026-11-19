<?php

declare(strict_types=1);

namespace App\Company\Application\ProjectDirection;

use App\Company\Repository\ProjectDirectionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DeleteProjectDirectionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectDirectionRepository $repository,
    ) {
    }

    public function __invoke(string $companyId, string $id): bool
    {
        return $this->entityManager->wrapInTransaction(function () use ($companyId, $id): bool {
            $this->repository->lockDirectory($companyId);
            $project = $this->repository->findForDirectoryWrite($companyId, [$id])[$id] ?? null;
            if (null === $project) {
                return false;
            }
            $this->entityManager->remove($project);
            $this->repository->advanceDirectoryRevision($companyId);

            return true;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Repository\FinancialResponsibilityCenterRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

final readonly class UpdateFinancialResponsibilityCenterAction
{
    public function __construct(
        private FinancialResponsibilityCenterRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(string $companyId, string $id, int $expectedVersion, string $name, int $sort): void
    {
        $center = $this->repository->findOneByIdAndCompanyId($id, $companyId)
            ?? throw new \DomainException('ЦФО не найден.');

        try {
            $this->entityManager->lock($center, LockMode::OPTIMISTIC, $expectedVersion);
            $center->rename($name);
            $center->setSort($sort);
            $this->entityManager->flush();
        } catch (OptimisticLockException $exception) {
            throw new \DomainException('ЦФО был изменён другим пользователем. Обновите страницу.', previous: $exception);
        }
    }
}

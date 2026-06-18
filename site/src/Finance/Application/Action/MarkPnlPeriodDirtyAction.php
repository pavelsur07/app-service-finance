<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Finance\Application\Command\MarkPnlPeriodDirtyCommand;
use App\Ingestion\Entity\PLDirtyPeriod;
use App\Ingestion\Enum\PLDirtyPeriodStatus;
use App\Ingestion\Repository\PLDirtyPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MarkPnlPeriodDirtyAction
{
    public function __construct(
        private PLDirtyPeriodRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(MarkPnlPeriodDirtyCommand $command): void
    {
        $period = $this->repository->findOne($command->companyId, $command->year, $command->month, $command->shopRef);

        if (!$period instanceof PLDirtyPeriod) {
            $this->entityManager->persist(new PLDirtyPeriod(
                companyId: $command->companyId,
                periodYear: $command->year,
                periodMonth: $command->month,
                shopRef: $command->shopRef,
                reason: $command->reason,
            ));
            $this->entityManager->flush();

            return;
        }

        if (
            PLDirtyPeriodStatus::DONE === $period->getStatus()
            || PLDirtyPeriodStatus::FAILED === $period->getStatus()
            || PLDirtyPeriodStatus::BLOCKED_BY_CLOSE === $period->getStatus()
        ) {
            $period->reopen();
            $this->entityManager->flush();
        }
    }
}

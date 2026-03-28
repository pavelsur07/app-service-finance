<?php

declare(strict_types=1);

namespace App\Loan\Application;

use App\Loan\Entity\LoanPaymentSchedule;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateLoanScheduleItemAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(LoanPaymentSchedule $schedule): void
    {
        $schedule->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }
}

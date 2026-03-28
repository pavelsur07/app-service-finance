<?php

declare(strict_types=1);

namespace App\Loan\Application;

use App\Loan\Entity\LoanPaymentSchedule;
use Doctrine\ORM\EntityManagerInterface;

final class DeleteLoanScheduleItemAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(LoanPaymentSchedule $schedule): void
    {
        $this->entityManager->remove($schedule);
        $this->entityManager->flush();
    }
}

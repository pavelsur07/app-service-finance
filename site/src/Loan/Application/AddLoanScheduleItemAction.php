<?php

declare(strict_types=1);

namespace App\Loan\Application;

use App\Loan\Entity\Loan;
use App\Loan\Entity\LoanPaymentSchedule;
use Doctrine\ORM\EntityManagerInterface;

final class AddLoanScheduleItemAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(Loan $loan, LoanPaymentSchedule $schedule): void
    {
        $schedule->setUpdatedAt(new \DateTimeImmutable());
        $loan->addPaymentScheduleItem($schedule);
        $this->entityManager->persist($schedule);
        $this->entityManager->flush();
    }
}

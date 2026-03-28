<?php

declare(strict_types=1);

namespace App\Loan\Application;

use App\Loan\Entity\Loan;
use App\Loan\Entity\LoanPaymentSchedule;
use App\Loan\Repository\LoanPaymentScheduleRepository;
use Doctrine\ORM\EntityManagerInterface;

final class UploadLoanScheduleAction
{
    public function __construct(
        private readonly LoanPaymentScheduleRepository $scheduleRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(Loan $loan, \SplFileObject $csv): void
    {
        foreach ($this->scheduleRepository->findByLoanOrderedByDueDate($loan) as $item) {
            $this->entityManager->remove($item);
        }

        $csv->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $csv->setCsvControl(';');

        $isHeaderSkipped = false;

        foreach ($csv as $row) {
            if (!$isHeaderSkipped) {
                $isHeaderSkipped = true;
                continue;
            }

            if (!is_array($row) || count($row) < 6 || null === $row[0]) {
                continue;
            }

            [$date, $totalPayment, $principalPart, $interestPart, $feePart, $isPaid] = $row;

            $dueDate = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $date);
            if (false === $dueDate) {
                continue;
            }

            $schedule = new LoanPaymentSchedule(
                $loan,
                $dueDate,
                (string) $totalPayment,
                (string) $principalPart,
                (string) $interestPart,
                (string) $feePart
            );
            $schedule->setIsPaid('1' === trim((string) $isPaid));

            $this->entityManager->persist($schedule);
        }

        $this->entityManager->flush();
    }
}

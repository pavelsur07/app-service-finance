<?php

declare(strict_types=1);

namespace App\Loan\Application;

use App\Loan\Entity\Loan;
use App\Loan\Entity\LoanPaymentSchedule;
use App\Loan\Service\LoanScheduleToDocumentService;
use Doctrine\ORM\EntityManagerInterface;

final class CreateDocumentFromLoanScheduleAction
{
    public function __construct(
        private readonly LoanScheduleToDocumentService $scheduleToDocumentService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return string ID созданного документа
     */
    public function __invoke(Loan $loan, LoanPaymentSchedule $schedule): string
    {
        $document = $this->scheduleToDocumentService->createDocumentFromSchedule($loan, $schedule);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document->getId();
    }
}

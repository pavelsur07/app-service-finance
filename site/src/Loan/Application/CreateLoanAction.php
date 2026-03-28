<?php

declare(strict_types=1);

namespace App\Loan\Application;

use App\Loan\Entity\Loan;
use Doctrine\ORM\EntityManagerInterface;

final class CreateLoanAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(Loan $loan): void
    {
        $loan->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($loan);
        $this->entityManager->flush();
    }
}

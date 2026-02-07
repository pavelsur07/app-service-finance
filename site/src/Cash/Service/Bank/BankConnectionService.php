<?php

namespace App\Cash\Service\Bank;

use App\Cash\Entity\Bank\BankConnection;
use Doctrine\ORM\EntityManagerInterface;

class BankConnectionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function create(BankConnection $connection): void
    {
        $this->entityManager->persist($connection);
        $this->entityManager->flush();
    }

    public function update(): void
    {
        $this->entityManager->flush();
    }

    public function delete(BankConnection $connection): void
    {
        $this->entityManager->remove($connection);
        $this->entityManager->flush();
    }
}

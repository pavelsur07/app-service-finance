<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Bank\BankConnection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Удаляет подключение банка.
 */
final class DeleteBankConnectionAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(BankConnection $connection): void
    {
        $this->entityManager->remove($connection);
        $this->entityManager->flush();
    }
}

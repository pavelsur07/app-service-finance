<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Bank\BankConnection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Сохраняет новое или изменённое подключение банка.
 */
final class SaveBankConnectionAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(BankConnection $connection): void
    {
        $this->entityManager->persist($connection);
        $this->entityManager->flush();
    }
}

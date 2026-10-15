<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Accounts\MoneyFundMovement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Сохраняет движение по фонду.
 */
final class CreateMoneyFundMovementAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(MoneyFundMovement $movement): void
    {
        $this->entityManager->persist($movement);
        $this->entityManager->flush();
    }
}

<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Accounts\MoneyAccount;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Сохраняет новый или изменённый счёт. Граница транзакции — здесь, а не в контроллере.
 */
final class SaveMoneyAccountAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(MoneyAccount $account): void
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();
    }
}

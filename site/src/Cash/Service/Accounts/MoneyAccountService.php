<?php

namespace App\Cash\Service\Accounts;

use App\Cash\Entity\Accounts\MoneyAccount;
use Doctrine\ORM\EntityManagerInterface;

class MoneyAccountService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function create(MoneyAccount $account): void
    {
        $this->em->persist($account);
        $this->em->flush();
    }

    public function update(): void
    {
        $this->em->flush();
    }
}

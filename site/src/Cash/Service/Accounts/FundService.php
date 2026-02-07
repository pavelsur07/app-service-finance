<?php

namespace App\Cash\Service\Accounts;

use App\Cash\Entity\Accounts\MoneyFund;
use App\Cash\Entity\Accounts\MoneyFundMovement;
use Doctrine\ORM\EntityManagerInterface;

class FundService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function createFund(MoneyFund $fund): void
    {
        $this->em->persist($fund);
        $this->em->flush();
    }

    public function createMovement(MoneyFundMovement $movement): void
    {
        $this->em->persist($movement);
        $this->em->flush();
    }

    public function update(): void
    {
        $this->em->flush();
    }
}

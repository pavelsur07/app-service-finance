<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Accounts\MoneyFund;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Сохраняет новый или изменённый фонд.
 */
final class SaveMoneyFundAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function createDraft(\App\Company\Entity\Company $company): MoneyFund
    {
        return new MoneyFund(\Ramsey\Uuid\Uuid::uuid7()->toString(), $company, '', 'RUB');
    }

    public function __invoke(MoneyFund $fund): void
    {
        $this->entityManager->persist($fund);
        $this->entityManager->flush();
    }
}

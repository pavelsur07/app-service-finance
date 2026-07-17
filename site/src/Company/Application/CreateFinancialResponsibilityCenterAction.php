<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Entity\FinancialResponsibilityCenter;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class CreateFinancialResponsibilityCenterAction
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(string $companyId, string $name, int $sort = 0): string
    {
        $code = 'CFO_'.\strtoupper(\str_replace('-', '', Uuid::uuid7()->toString()));
        $center = new FinancialResponsibilityCenter($companyId, $code, $name);
        $center->setSort($sort);

        $this->entityManager->persist($center);
        $this->entityManager->flush();

        return $center->getId();
    }
}

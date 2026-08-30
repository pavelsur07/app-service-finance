<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Repository;

use App\Tests\Unit\PHPStan\data\Entity\Currency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Фикстура: сущность без поля компании, определяется через parent::__construct.
 * Нарушений быть не должно.
 */
final class CurrencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Currency::class);
    }

    public function findAllActive(): array
    {
        return [];
    }
}

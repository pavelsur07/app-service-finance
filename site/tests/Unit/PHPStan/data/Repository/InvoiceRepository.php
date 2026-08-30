<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Repository;

use App\Tests\Unit\PHPStan\data\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Фикстура: сущность определяется ТОЛЬКО через parent::__construct,
 * docblock-шаблона нет. Так написаны 59 репозиториев проекта.
 */
final class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /** Нарушение: сущность принадлежит компании, параметра нет. */
    public function findOverdue(\DateTimeImmutable $on): array
    {
        return [$on];
    }
}

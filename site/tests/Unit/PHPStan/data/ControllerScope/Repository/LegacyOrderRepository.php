<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\ControllerScope\Repository;

use Doctrine\ORM\EntityRepository;

/** Без `@extends EntityRepository<Order>`: find() возвращает object|null. */
final class LegacyOrderRepository extends EntityRepository
{
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\ControllerScope\Repository;

use App\Tests\Unit\PHPStan\data\ControllerScope\Entity\Order;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Order>
 */
final class OrderRepository extends EntityRepository
{
}

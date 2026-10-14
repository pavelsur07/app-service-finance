<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\ControllerScope\Repository;

use App\Tests\Unit\PHPStan\data\ControllerScope\Entity\Tariff;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Tariff>
 */
final class TariffRepository extends EntityRepository
{
}

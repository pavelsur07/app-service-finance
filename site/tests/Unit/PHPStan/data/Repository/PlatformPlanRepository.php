<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Repository;

use App\Tests\Unit\PHPStan\data\Entity\PlatformPlan;
use Doctrine\ORM\EntityRepository;

/**
 * Фикстура: репозиторий справочника платформы. Нарушений быть не должно —
 * у сущности нет поля компании, ограничивать выборку нечем.
 *
 * @extends EntityRepository<PlatformPlan>
 */
final class PlatformPlanRepository extends EntityRepository
{
    public function findAllActive(): array
    {
        return [];
    }

    public function findOneByCode(string $code): ?PlatformPlan
    {
        return null !== $code ? null : null;
    }
}

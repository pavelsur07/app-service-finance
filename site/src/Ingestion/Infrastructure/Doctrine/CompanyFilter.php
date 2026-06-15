<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Doctrine;

use App\Ingestion\Domain\TenantOwnedInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

final class CompanyFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $className = $targetEntity->getName();

        if (!str_starts_with($className, 'App\\Ingestion\\Entity\\')) {
            return '';
        }

        if (!is_a($className, TenantOwnedInterface::class, true)) {
            return '';
        }

        if (!$targetEntity->hasField('companyId')) {
            throw new \LogicException(sprintf(
                'Tenant-owned Ingestion entity "%s" must declare a companyId field.',
                $className,
            ));
        }

        return sprintf('%s.company_id = %s', $targetTableAlias, $this->getParameter('companyId'));
    }
}

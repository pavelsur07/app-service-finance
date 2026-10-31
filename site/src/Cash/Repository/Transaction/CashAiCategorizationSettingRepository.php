<?php

declare(strict_types=1);

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashAiCategorizationSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<CashAiCategorizationSetting>
 */
final class CashAiCategorizationSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashAiCategorizationSetting::class);
    }

    public function findForCompany(string $companyId): ?CashAiCategorizationSetting
    {
        Assert::uuid($companyId);

        return $this->find($companyId);
    }

    public function isAutoApplyEnabled(string $companyId): bool
    {
        return $this->findForCompany($companyId)?->isAutoApply() ?? false;
    }
}

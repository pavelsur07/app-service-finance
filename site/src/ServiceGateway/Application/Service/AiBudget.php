<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Service;

use App\ServiceGateway\Application\DTO\AiCompanyBudget;
use App\ServiceGateway\Entity\AiCompanyAccess;
use App\ServiceGateway\Infrastructure\Query\AiUsageQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webmozart\Assert\Assert;

/**
 * Лимит компании (`ai_company_access`) и общий (`ai.monthly_limit_rub`) против расхода месяца (`ai_usage`).
 * Неподключённая компания получает значения по умолчанию — проверку доступа делает `AiServiceAccess`.
 */
final readonly class AiBudget
{
    public function __construct(
        private Connection $db,
        private AiUsageQuery $usage,
        #[Autowire('%ai.monthly_limit_rub%')]
        private float $totalLimitRub,
    ) {
    }

    public function forCompany(string $companyId): AiCompanyBudget
    {
        Assert::uuid($companyId);
        $row = $this->db->fetchAssociative(
            'SELECT monthly_limit_rub, lookback_days FROM ai_company_access WHERE company_id = :company',
            ['company' => strtolower($companyId)],
        );

        return new AiCompanyBudget(
            limitRub: (float) ($row['monthly_limit_rub'] ?? AiCompanyAccess::DEFAULT_MONTHLY_LIMIT_RUB),
            spentRub: $this->usage->spentThisMonth($companyId),
            totalLimitRub: $this->totalLimitRub,
            totalSpentRub: $this->usage->spentThisMonthTotal(),
            lookbackDays: (int) ($row['lookback_days'] ?? AiCompanyAccess::DEFAULT_LOOKBACK_DAYS),
        );
    }
}

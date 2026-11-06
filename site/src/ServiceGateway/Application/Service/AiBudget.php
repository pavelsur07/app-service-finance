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

    /** Момент подключения компании; null — не подключена. */
    public function enabledAt(string $companyId): ?\DateTimeImmutable
    {
        Assert::uuid($companyId);
        $at = $this->db->fetchOne('SELECT enabled_at FROM ai_company_access WHERE company_id = :company', ['company' => strtolower($companyId)]);

        return false === $at || null === $at ? null : new \DateTimeImmutable((string) $at);
    }

    /**
     * Подключённые компании из списка, у которых исчерпан свой лимит или общий — одним-двумя запросами.
     *
     * @param list<string> $companyIds
     *
     * @return list<string>
     */
    public function exhaustedAmong(array $companyIds): array
    {
        if ([] === $companyIds) {
            return [];
        }
        if ($this->usage->spentThisMonthTotal() >= $this->totalLimitRub) {
            return $this->connectedAmong($companyIds);
        }

        return $this->usage->overLimitAmong($companyIds);
    }

    /**
     * @param list<string> $companyIds
     *
     * @return list<string>
     */
    private function connectedAmong(array $companyIds): array
    {
        return array_map('strval', $this->db->fetchFirstColumn(
            'SELECT company_id::text FROM ai_company_access WHERE company_id IN (:ids)',
            ['ids' => array_values(array_unique(array_map('strtolower', $companyIds)))],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        ));
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

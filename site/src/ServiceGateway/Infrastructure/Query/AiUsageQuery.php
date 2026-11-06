<?php

declare(strict_types=1);

namespace App\ServiceGateway\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Расход ИИ за текущий календарный месяц (МСК — время прода) по `ai_usage`.
 */
final readonly class AiUsageQuery
{
    public function __construct(
        private Connection $db,
        private ClockInterface $clock,
    ) {
    }

    /** Стоимость в ₽ за месяц по компании; результаты без цены не входят. */
    public function spentThisMonth(string $companyId): float
    {
        Assert::uuid($companyId);

        return (float) $this->db->fetchOne(
            'SELECT COALESCE(SUM(cost_rub), 0) FROM ai_usage WHERE company_id = :company AND completed_at >= :from',
            ['company' => strtolower($companyId), 'from' => $this->monthStart()],
        );
    }

    /**
     * Стоимость за месяц по всем компаниям.
     */
    public function spentThisMonthTotal(): float
    {
        return (float) $this->db->fetchOne(
            'SELECT COALESCE(SUM(cost_rub), 0) FROM ai_usage WHERE completed_at >= :from',
            ['from' => $this->monthStart()],
        );
    }

    /**
     * Принятые результаты с момента и сколько из них `failed`.
     *
     * @return array{total: int, failed: int}
     */
    public function resultsSince(\DateTimeImmutable $since): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT count(*) AS total, count(*) FILTER (WHERE status = 'failed') AS failed FROM ai_usage WHERE completed_at >= :since",
            ['since' => $since->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s')],
        );

        return ['total' => (int) ($row['total'] ?? 0), 'failed' => (int) ($row['failed'] ?? 0)];
    }

    /**
     * Результаты, токены и стоимость компании за месяц.
     *
     * @return array{results: int, inputTokens: int, outputTokens: int}
     */
    public function monthTotals(string $companyId): array
    {
        Assert::uuid($companyId);
        $row = $this->db->fetchAssociative(
            'SELECT count(*) AS results, COALESCE(SUM(input_tokens), 0) AS input, COALESCE(SUM(output_tokens), 0) AS output
             FROM ai_usage WHERE company_id = :company AND completed_at >= :from',
            ['company' => strtolower($companyId), 'from' => $this->monthStart()],
        );

        return ['results' => (int) ($row['results'] ?? 0), 'inputTokens' => (int) ($row['input'] ?? 0), 'outputTokens' => (int) ($row['output'] ?? 0)];
    }

    /**
     * Какие из компаний потратили за месяц не меньше своего лимита — одним запросом.
     *
     * @param list<string> $companyIds
     *
     * @return list<string>
     */
    public function overLimitAmong(array $companyIds): array
    {
        if ([] === $companyIds) {
            return [];
        }

        return array_map('strval', $this->db->fetchFirstColumn(
            'SELECT a.company_id::text FROM ai_company_access a
             LEFT JOIN (SELECT company_id, SUM(cost_rub) AS spent FROM ai_usage
                        WHERE completed_at >= :from AND company_id IN (:ids) GROUP BY company_id) u ON u.company_id = a.company_id
             WHERE a.company_id IN (:ids) AND COALESCE(u.spent, 0) >= a.monthly_limit_rub',
            ['from' => $this->monthStart(), 'ids' => array_values(array_unique(array_map('strtolower', $companyIds)))],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        ));
    }

    private function monthStart(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-01 00:00:00');
    }
}

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

    private function monthStart(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-01 00:00:00');
    }
}

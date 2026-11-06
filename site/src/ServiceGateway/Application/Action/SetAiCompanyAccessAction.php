<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Action;

use App\ServiceGateway\Application\Service\AiCompanyLimits;
use App\ServiceGateway\Entity\AiCompanyAccess;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Администратор подключает компанию к ИИ или отключает её (`ai_company_access`).
 *
 * Одна инструкция SQL на вызов — своя транзакция. Повтор и гонка дают тот же итог:
 * включение — `ON CONFLICT DO NOTHING` (остаётся первая строка), выключение — удаление
 * отсутствующей строки ничего не меняет. Лимит и глубина задаются при включении; у уже
 * подключённой компании повтор их не меняет — для этого `UpdateAiCompanyLimitsAction`.
 */
final readonly class SetAiCompanyAccessAction
{
    public function __construct(
        private Connection $db,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        string $companyId,
        bool $enabled,
        string $adminUserId,
        string $monthlyLimitRub = AiCompanyAccess::DEFAULT_MONTHLY_LIMIT_RUB,
        int $lookbackDays = AiCompanyAccess::DEFAULT_LOOKBACK_DAYS,
    ): void {
        Assert::uuid($companyId);
        Assert::uuid($adminUserId);
        $companyId = strtolower($companyId);
        $monthlyLimitRub = AiCompanyLimits::limit($monthlyLimitRub);
        $lookbackDays = AiCompanyLimits::lookback($lookbackDays);

        if ($enabled) {
            $changed = $this->db->executeStatement(
                'INSERT INTO ai_company_access (id, company_id, enabled_at, enabled_by_user_id, monthly_limit_rub, lookback_days)
                 VALUES (:id, :company, :at, :admin, :limit, :lookback) ON CONFLICT (company_id) DO NOTHING',
                [
                    'id' => Uuid::uuid7()->toString(),
                    'company' => $companyId,
                    'at' => $this->clock->now()->format('Y-m-d H:i:s'),
                    'admin' => $adminUserId,
                    'limit' => $monthlyLimitRub,
                    'lookback' => $lookbackDays,
                ],
            );
        } else {
            $changed = $this->db->executeStatement(
                'DELETE FROM ai_company_access WHERE company_id = :company',
                ['company' => $companyId],
            );
        }

        $this->logger->info($enabled ? 'AI access enabled for company.' : 'AI access disabled for company.', [
            'companyId' => $companyId,
            'adminUserId' => $adminUserId,
            'changed' => $changed > 0,
            'monthlyLimitRub' => $enabled ? $monthlyLimitRub : null,
            'lookbackDays' => $enabled ? $lookbackDays : null,
        ]);
    }
}

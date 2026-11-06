<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Action;

use App\ServiceGateway\Application\Service\AiCompanyLimits;
use App\ServiceGateway\Exception\InvalidAiCompanyLimitsException;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Администратор меняет лимит расходов ИИ и глубину выгрузки подключённой компании.
 * Одна инструкция SQL — своя транзакция; повтор с теми же значениями ничего не меняет.
 */
final readonly class UpdateAiCompanyLimitsAction
{
    public function __construct(
        private Connection $db,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, string $monthlyLimitRub, int $lookbackDays, string $adminUserId): void
    {
        Assert::uuid($companyId);
        Assert::uuid($adminUserId);
        $limit = AiCompanyLimits::limit($monthlyLimitRub);
        $lookback = AiCompanyLimits::lookback($lookbackDays);

        $updated = $this->db->executeStatement(
            'UPDATE ai_company_access SET monthly_limit_rub = :limit, lookback_days = :lookback WHERE company_id = :company',
            ['limit' => $limit, 'lookback' => $lookback, 'company' => strtolower($companyId)],
        );
        if (0 === $updated) {
            throw new InvalidAiCompanyLimitsException('Кабинет не подключён к ИИ: сначала подключите его.');
        }

        $this->logger->info('AI limits updated for company.', [
            'companyId' => strtolower($companyId),
            'adminUserId' => $adminUserId,
            'monthlyLimitRub' => $limit,
            'lookbackDays' => $lookback,
        ]);
    }
}

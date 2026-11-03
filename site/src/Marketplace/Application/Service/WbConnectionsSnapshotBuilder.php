<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Application\DTO\Internal\ActiveConnectionCredentials;
use App\Marketplace\Application\DTO\Internal\ConnectionConfig;
use App\Marketplace\Application\DTO\Internal\MarketplaceConnectionsSnapshot;
use App\Marketplace\Enum\MarketplaceConnectionType;

/**
 * Снимок активных WB-кабинетов для wb-fetcher
 * (`services/_contracts/marketplace-connections/v1`, `provider=wildberries`).
 *
 * У WB один ключ — токен подключения seller; `client_id` в WB не хранится,
 * поэтому в контракт уходит id подключения: это та же единица лимита отчёта,
 * что бакет Symfony `connection:<id>` ({@see \App\Marketplace\Wildberries\Application\Service\WbFinanceRateLimiter::resolveSalesReportsBucket()}).
 * Кабинет без пригодного токена не выдаётся.
 */
final readonly class WbConnectionsSnapshotBuilder
{
    public const PROVIDER = 'wildberries';

    /** Детальный отчёт о реализации, дневной и недельный (`docs/tasks/wb-fetcher-finance/plan.md`). */
    public const FINANCE_RESOURCE_TYPES = ['wb.finance.sales-report.daily', 'wb.finance.sales-report.weekly'];

    /** Значение списка компаний: все кабинеты. */
    public const ALL_COMPANIES = '*';

    /**
     * @param list<string> $financeCompanyIds компании, которым выдаются финансовые
     *                                        ресурсы; ['*'] — всем, [] — никому
     */
    public function __construct(
        private array $financeCompanyIds = [],
    ) {
    }

    /**
     * @param list<ActiveConnectionCredentials> $rows
     */
    public function build(array $rows, \DateTimeImmutable $generatedAt): MarketplaceConnectionsSnapshot
    {
        $connections = [];
        foreach ($rows as $row) {
            if (MarketplaceConnectionType::SELLER !== $row->type || null === $row->secret || '' === trim($row->secret)) {
                continue;
            }

            $connections[] = new ConnectionConfig(
                connectionRef: $row->connectionId,
                companyId: $row->companyId,
                provider: self::PROVIDER,
                updatedAt: $row->updatedAt,
                seller: ['client_id' => $row->connectionId, 'api_key' => $row->secret],
                performance: null,
                resourceTypes: $this->listed($row->companyId) ? self::FINANCE_RESOURCE_TYPES : [],
            );
        }

        usort($connections, static fn (ConnectionConfig $a, ConnectionConfig $b): int => $a->connectionRef <=> $b->connectionRef);

        return new MarketplaceConnectionsSnapshot($generatedAt, $connections);
    }

    private function listed(string $companyId): bool
    {
        return \in_array(self::ALL_COMPANIES, $this->financeCompanyIds, true)
            || \in_array($companyId, $this->financeCompanyIds, true);
    }
}

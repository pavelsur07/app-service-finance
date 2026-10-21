<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Application\DTO\Internal\ActiveConnectionCredentials;
use App\Marketplace\Application\DTO\Internal\ConnectionConfig;
use App\Marketplace\Application\DTO\Internal\MarketplaceConnectionsSnapshot;
use App\Marketplace\Enum\MarketplaceConnectionType;

/**
 * Снимок активных Ozon-кабинетов для сервисов `services/`
 * (`services/_contracts/marketplace-connections/v1`).
 *
 * Кабинет — компания: seller- и performance-подключение одной компании дают
 * один элемент. `connection_ref` — id seller-подключения, при его отсутствии —
 * id performance: новое подключение получает новый ref, и сервис не смешает
 * данные другого кабинета со старым журналом. Кабинет без пригодных ключей не
 * выдаётся — ему нечего грузить.
 */
final readonly class MarketplaceConnectionsSnapshotBuilder
{
    public const PROVIDER = 'ozon';

    /** Ресурсы первой волны ozon-fetcher (`services/ozon-fetcher/docs/ozon-api.md`). */
    public const SELLER_RESOURCE_TYPES = [
        'ozon.finance.accrual-by-day',
        'ozon.finance.accrual-types',
        'ozon.finance.realization',
        'ozon.finance.mutual-settlement',
    ];

    /**
     * Отправления FBO/FBS (`docs/tasks/ozon-orders-posting-list/stages/stage-1.md`):
     * включаются по списку компаний — сначала один кабинет, затем все.
     */
    public const ORDERS_RESOURCE_TYPES = ['ozon.orders.fbo', 'ozon.orders.fbs'];

    /**
     * Начисления по отправлениям (`docs/tasks/marketplace-data-ingestion/stages/ozon-3-accrual-postings.md`):
     * номера берутся из заказов, поэтому ресурс выдаётся только кабинетам с заказами.
     */
    public const ACCRUAL_POSTINGS_RESOURCE_TYPE = 'ozon.finance.accrual-postings';

    /** Значение списка компаний: все кабинеты с ключом seller. */
    public const ORDERS_ALL_COMPANIES = '*';

    public const PERFORMANCE_RESOURCE_TYPES = ['ozon.ads.statistics'];

    /**
     * @param list<string> $ordersCompanyIds компании, которым выдаются ресурсы
     *                                       заказов; ['*'] — всем, [] — никому
     * @param list<string> $accrualPostingsCompanyIds то же для начислений по отправлениям
     *                                                (из тех, кому выданы заказы)
     */
    public function __construct(
        private array $ordersCompanyIds = [],
        private array $accrualPostingsCompanyIds = [],
    ) {
    }

    /**
     * @param list<ActiveConnectionCredentials> $rows
     */
    public function build(array $rows, \DateTimeImmutable $generatedAt): MarketplaceConnectionsSnapshot
    {
        /** @var array<string, array{seller: ?ActiveConnectionCredentials, performance: ?ActiveConnectionCredentials}> $byCompany */
        $byCompany = [];
        foreach ($rows as $row) {
            $slot = MarketplaceConnectionType::PERFORMANCE === $row->type ? 'performance' : 'seller';
            $byCompany[$row->companyId] ??= ['seller' => null, 'performance' => null];
            $byCompany[$row->companyId][$slot] ??= $row;
        }

        $connections = [];
        foreach ($byCompany as $companyId => $pair) {
            $config = $this->config((string) $companyId, $pair['seller'], $pair['performance']);
            if (null !== $config) {
                $connections[] = $config;
            }
        }

        usort($connections, static fn (ConnectionConfig $a, ConnectionConfig $b): int => $a->connectionRef <=> $b->connectionRef);

        return new MarketplaceConnectionsSnapshot($generatedAt, $connections);
    }

    private function config(string $companyId, ?ActiveConnectionCredentials $seller, ?ActiveConnectionCredentials $performance): ?ConnectionConfig
    {
        $sellerCredentials = null !== $seller && self::usable($seller)
            ? ['client_id' => (string) $seller->clientId, 'api_key' => (string) $seller->secret]
            : null;
        $performanceCredentials = null !== $performance && self::usable($performance)
            ? ['client_id' => (string) $performance->clientId, 'client_secret' => (string) $performance->secret]
            : null;

        if (null === $sellerCredentials && null === $performanceCredentials) {
            return null;
        }

        $anchor = $seller ?? $performance;
        \assert(null !== $anchor);

        $updatedAt = $anchor->updatedAt;
        if (null !== $seller && null !== $performance && $performance->updatedAt > $updatedAt) {
            $updatedAt = $performance->updatedAt;
        }

        return new ConnectionConfig(
            connectionRef: $anchor->connectionId,
            companyId: $companyId,
            provider: self::PROVIDER,
            updatedAt: $updatedAt,
            seller: $sellerCredentials,
            performance: $performanceCredentials,
            resourceTypes: [
                ...(null !== $sellerCredentials ? self::SELLER_RESOURCE_TYPES : []),
                ...(null !== $sellerCredentials && self::listed($this->ordersCompanyIds, $companyId) ? self::ORDERS_RESOURCE_TYPES : []),
                ...(null !== $sellerCredentials && self::listed($this->ordersCompanyIds, $companyId) && self::listed($this->accrualPostingsCompanyIds, $companyId)
                    ? [self::ACCRUAL_POSTINGS_RESOURCE_TYPE] : []),
                ...(null !== $performanceCredentials ? self::PERFORMANCE_RESOURCE_TYPES : []),
            ],
        );
    }

    /**
     * @param list<string> $companyIds
     */
    private static function listed(array $companyIds, string $companyId): bool
    {
        return \in_array(self::ORDERS_ALL_COMPANIES, $companyIds, true)
            || \in_array($companyId, $companyIds, true);
    }

    private static function usable(ActiveConnectionCredentials $row): bool
    {
        return null !== $row->clientId && '' !== trim($row->clientId)
            && null !== $row->secret && '' !== trim($row->secret);
    }
}

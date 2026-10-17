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

    public const PERFORMANCE_RESOURCE_TYPES = ['ozon.ads.statistics'];

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
                ...(null !== $performanceCredentials ? self::PERFORMANCE_RESOURCE_TYPES : []),
            ],
        );
    }

    private static function usable(ActiveConnectionCredentials $row): bool
    {
        return null !== $row->clientId && '' !== trim($row->clientId)
            && null !== $row->secret && '' !== trim($row->secret);
    }
}

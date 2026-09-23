<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Infrastructure\Api;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Marketplace\Repository\MarketplaceConnectionRepository;

/**
 * Проверка ключа WB-подключения (кнопка «Проверить») и имя эндпоинта
 * финансового отчёта, под которым хранятся его сырые документы.
 */
class WildberriesAdapter
{
    public const FINANCE_API_ENDPOINT = 'wildberries::finance-sales-reports-detailed';

    public function __construct(
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly WbFinanceSalesReportClient $salesReportClient,
        private readonly ConnectionApiKeyCodec $connectionApiKeyCodec,
    ) {
    }

    public function authenticate(Company $company): bool
    {
        $connection = $this->getConnection($company);

        if (!$connection) {
            return false;
        }

        return $this->salesReportClient->probeAccess(
            $this->connectionApiKeyCodec->apiKeyFor($connection),
            $this->salesReportClient->resolveSalesReportsBucketId($connection),
        );
    }

    private function getConnection(Company $company): ?MarketplaceConnection
    {
        return $this->connectionRepository->findByMarketplace(
            $company,
            MarketplaceType::WILDBERRIES,
            MarketplaceConnectionType::SELLER,
        );
    }
}

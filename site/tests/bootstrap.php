<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

DG\BypassFinals::enable();
DG\BypassFinals::allowPaths([
    '*/src/Marketplace/Facade/MarketplaceFacade.php',
    '*/src/Marketplace/Application/RebuildPreliminaryForPeriodAction.php',
    '*/src/Marketplace/Infrastructure/Query/UnprocessedCostsQuery.php',
    '*/src/MarketplaceAds/Infrastructure/Query/AdSpendByListingQuery.php',
    '*/src/MarketplaceAds/Infrastructure/Query/ActiveOzonPerformanceConnectionsQuery.php',
    '*/src/MarketplaceAds/Application/ExtractBatchesToRawDocumentsAction.php',
    '*/src/MarketplaceAds/Application/Service/AdBatchPlanner.php',
    '*/src/Marketplace/Application/Service/MarketplaceWeekPartitionService.php',
    '*/src/Marketplace/Infrastructure/Query/ActiveOzonConnectionsQuery.php',
    '*/src/Marketplace/Infrastructure/Query/ActiveSellerConnectionsQuery.php',
    '*/src/MarketplaceAnalytics/Domain/Service/CostMappingResolver.php',
    '*/src/MarketplaceAnalytics/Domain/Service/DefaultCostMappingSeedPolicy.php',
    '*/src/MarketplaceAnalytics/Infrastructure/Query/UnitExtendedQuery.php',
    '*/src/MarketplaceAds/Repository/AdScheduledBatchRepository.php',
    '*/src/MarketplaceAds/Repository/AdRawDocumentRepository.php',
    '*/src/Shared/Service/Storage/StorageService.php',
]);

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

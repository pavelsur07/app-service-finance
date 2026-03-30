<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

DG\BypassFinals::enable();
DG\BypassFinals::allowPaths([
    '*/src/Marketplace/Facade/MarketplaceFacade.php',
    '*/src/MarketplaceAnalytics/Domain/Service/CostMappingResolver.php',
]);

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

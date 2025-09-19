<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    if (!class_exists('Symfony\\Component\\RateLimiter\\RateLimiterFactory')) {
        return;
    }

    $container->extension('framework', [
        'rate_limiter' => [
            'reports_api' => [
                'policy' => 'fixed_window',
                'limit' => 60,
                'interval' => '1 minute',
            ],
        ],
    ]);
};

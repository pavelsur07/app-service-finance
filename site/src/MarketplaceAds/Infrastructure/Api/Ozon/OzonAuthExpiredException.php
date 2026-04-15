<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Ozon;

/**
 * Внутренний сигнал OzonAdClient: токен отвергнут API (HTTP 401/403).
 *
 * Не должен «вылетать» наружу из клиента — withAuthRetry() ловит его,
 * сбрасывает кэш и повторяет операцию с новым токеном.
 */
final class OzonAuthExpiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ozon Performance: access token rejected (401/403)');
    }
}

<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

/**
 * HTTP 429 от Ozon Seller API.
 *
 * Отделён от {@see OzonCatalogApiException}, потому что это транзиентное
 * состояние: обработчик ретраит его, а не помечает прогон неустранимо сломанным.
 */
final class OzonCatalogRateLimitException extends OzonCatalogApiException
{
}

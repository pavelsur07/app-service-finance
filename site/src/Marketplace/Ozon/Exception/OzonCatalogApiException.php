<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Exception;

use App\Marketplace\Exception\MarketplaceApiException;

/**
 * Сбой Ozon Seller API при обходе каталога товаров.
 *
 * Отдельный класс, а не {@see MarketplaceApiException}: тот несёт обязательные
 * `dateFrom`/`dateTo` отчётного периода, которого у выгрузки каталога нет.
 */
class OzonCatalogApiException extends \RuntimeException
{
}

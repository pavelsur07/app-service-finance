<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

/**
 * Сбой Ozon Seller API при обходе каталога товаров.
 *
 * Отдельный класс, а не {@see MarketplaceApiException}: тот несёт обязательные
 * `dateFrom`/`dateTo` отчётного периода, которого у выгрузки каталога нет.
 */
class OzonCatalogApiException extends \RuntimeException
{
}

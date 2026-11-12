<?php

declare(strict_types=1);

namespace App\Inventory\Exception;

/** ozon-fetcher не отдал снимок поставок: сеть, статус не 200/404 или ответ вне контракта. */
final class OzonSupplySnapshotUnavailableException extends \RuntimeException
{
}

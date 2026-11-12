<?php

declare(strict_types=1);

namespace App\Inventory\Exception;

/** ozon-fetcher не отдал снимок поставок: сеть или статус не 200/404 — временно, кабинет пропускается. */
final class OzonSupplySnapshotUnavailableException extends \RuntimeException
{
}

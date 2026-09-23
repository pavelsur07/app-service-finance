<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

/**
 * Для подключения нет пути ручной синхронизации (не WB и не Ozon SELLER).
 */
final class ManualSyncNotSupportedException extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

use App\Marketplace\Enum\CloseStage;

/**
 * Команда проверки готовности данных перед закрытием этапа месяца.
 *
 * $preliminary — проверка перед оперативным закрытием: неразобранные затраты
 * и затраты без решения по ОПиУ его не блокируют, иначе оперативный ОПиУ
 * замирал бы на первой новой услуге маркетплейса.
 */
final class PreflightMonthCloseCommand
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $marketplace,
        public readonly int $year,
        public readonly int $month,
        public readonly CloseStage $stage,
        public readonly bool $preliminary = false,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Facade;

use App\MarketplaceOperations\Application\DTO\DayOperations;

interface MarketplaceOperationsFacadeInterface
{
    /**
     * Заменяет операции дня кабинета целиком и отмечает день грязным для витрины.
     * Транзакцией владеет вызывающий (Action / Handler переводчика); без активной
     * транзакции — `\LogicException`. Повтор с тем же входом даёт тот же набор строк.
     *
     * @return int число записанных операций
     */
    public function replaceDay(DayOperations $day): int;
}

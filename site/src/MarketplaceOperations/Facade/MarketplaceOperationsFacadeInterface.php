<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Facade;

use App\MarketplaceOperations\Application\DTO\DayOperations;
use App\MarketplaceOperations\Application\DTO\DayWindow;
use App\MarketplaceOperations\Application\DTO\OperationRecord;

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

    /**
     * Дни, заменённые после последнего пересчёта витрины, старые первыми; `$markedBefore` — только
     * отмеченные раньше этого момента (гейт отставания).
     *
     * @companyScopeExempt витрина обходит все компании (cron)
     *
     * @return list<DayWindow>
     */
    public function dirtyDays(int $limit, ?\DateTimeImmutable $markedBefore = null): array;

    /**
     * Дни с операциями в окне дат (ночной пересчёт витрины).
     *
     * @companyScopeExempt витрина обходит все компании (cron)
     *
     * @return list<DayWindow>
     */
    public function days(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * Забрать день для пересчёта витрины: блокировка дня (та же, что у `replaceDay`), операции и
     * снятие отметки «грязный». Транзакцией владеет вызывающий: замена дня переводчиком ждёт его
     * commit и снова отметит день; откат вызывающего вернёт отметку. Без транзакции — `\LogicException`.
     *
     * @return list<OperationRecord>
     */
    public function takeDay(DayWindow $day): array;
}

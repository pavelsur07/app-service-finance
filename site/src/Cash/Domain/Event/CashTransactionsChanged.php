<?php

declare(strict_types=1);

namespace App\Cash\Domain\Event;

/**
 * Данные ДДС компании изменились: транзакция создана, изменена, удалена или
 * восстановлена, создан или удалён перевод.
 *
 * Отправляется синхронно после записи в БД и пересчёта остатков. Слушатели —
 * в других модулях (сброс кэша дашборда в Analytics), Cash о них не знает
 * (`PATTERNS.md` §20). Только для последствий, которые можно потерять при сбое
 * процесса; денежные последствия так не передавать.
 */
final readonly class CashTransactionsChanged
{
    public function __construct(public string $companyId)
    {
    }
}

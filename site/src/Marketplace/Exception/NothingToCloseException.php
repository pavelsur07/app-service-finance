<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

/**
 * У этапа нет ни одной строки для документа ОПиУ.
 *
 * Наследник \DomainException, а не \RuntimeException: вызывающие закрытие
 * этапа (контроллер, хендлеры, пересборка) ловят \DomainException как отказ
 * закрытия, и эта причина должна обрабатываться ими так же. Отдельный класс
 * нужен пересборке оперативного ОПиУ: для неё пустой этап — ожидаемый исход
 * (например, все затраты Ozon за месяц нераспознанные), а не сбой.
 */
final class NothingToCloseException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Закрытие невозможно: нет строк для создания документа ОПиУ по этапу.');
    }
}

<?php

declare(strict_types=1);

namespace App\Company\Event;

/**
 * Владелец запросил удаление компании: модули прекращают фоновую работу с ней
 * (подключения к внешним системам отключаются). Диспатчится синхронно внутри
 * транзакции запроса: слушатели только меняют сущности, коммитит Action.
 * Данные не удаляются — окончательная очистка идёт после срока ожидания.
 */
final readonly class CompanyDeletionRequestedEvent
{
    public function __construct(public string $companyId)
    {
    }
}

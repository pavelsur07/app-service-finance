<?php

declare(strict_types=1);

namespace App\ServiceGateway\Facade;

/**
 * Постановка ИИ-задач для модулей-потребителей (ТЗ §4). Результат приходит
 * потребителю через {@see AiTaskResultConsumerInterface} при импорте.
 */
interface AiTaskFacadeInterface
{
    /**
     * Ставит задачу в очередь `async_sync`. Ничего не пишет в БД и не коммитит.
     *
     * Ключ повтора задаёт вызывающий (например, id записи-источника): повтор с тем же
     * ключом сервис сливает в прежнюю задачу. Вызывать после commit своей транзакции.
     *
     * @param array<string, mixed> $payload вход по контракту задачи; деньги — десятичные строки
     *
     * @return string|null task_id; null — сервис выключен для компании, задача не поставлена
     */
    public function requestTask(
        string $companyId,
        string $taskType,
        array $payload,
        string $idempotencyKey,
        ?string $requestedByUserId = null,
    ): ?string;

    public function isEnabledForCompany(string $companyId): bool;
}

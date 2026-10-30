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
     * ключом сервис сливает в прежнюю задачу и возвращается тот же task_id (UUIDv5 от
     * компании, типа задачи и ключа). Вызывать после commit своей транзакции.
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

    /**
     * task_id, который получит задача с этим ключом: потребитель записывает его до постановки
     * (в своей транзакции), а `requestTask` после commit вернёт то же значение.
     */
    public function taskIdFor(string $companyId, string $taskType, string $idempotencyKey): string;

    public function isEnabledForCompany(string $companyId): bool;
}

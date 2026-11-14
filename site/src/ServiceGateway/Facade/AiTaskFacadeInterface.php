<?php

declare(strict_types=1);

namespace App\ServiceGateway\Facade;

use App\ServiceGateway\Application\DTO\AiCompanyBudget;
use App\ServiceGateway\Application\DTO\AiCompanyOverview;

/**
 * Постановка ИИ-задач для модулей-потребителей (ТЗ §4). Результат приходит
 * потребителю через {@see AiTaskResultConsumerInterface} при импорте.
 */
interface AiTaskFacadeInterface
{
    /**
     * Компания платформы — для задач по общим данным (справочник кодов маркетплейсов): не зависит
     * от подключения компаний к ИИ, нужен только общий выключатель; расход идёт в общий лимит, не
     * в бюджет клиента.
     */
    public const PLATFORM_COMPANY_ID = '00000000-0000-7000-8000-000000000001';

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

    /**
     * Какие из компаний сейчас получают ИИ (сервис включён и компания подключена) — одним запросом.
     *
     * @param list<string> $companyIds
     *
     * @return list<string> id в нижнем регистре
     */
    public function enabledCompanyIds(array $companyIds): array;

    /**
     * Все компании, которые сейчас получают ИИ (сервис включён и компания подключена), по id.
     *
     * @return list<string>
     */
    public function connectedCompanyIds(): array;

    /** Общий выключатель `AI_SERVICE_ENABLED` с адресом и токеном. */
    public function isServiceEnabled(): bool;

    /**
     * Какие из компаний подключены администратором, без учёта общего выключателя (админка).
     *
     * @param list<string> $companyIds
     *
     * @return list<string> id в нижнем регистре
     */
    public function grantedCompanyIds(array $companyIds): array;

    /** Лимит и расход компании за месяц, общий лимит, окно выгрузки. */
    public function budgetFor(string $companyId): AiCompanyBudget;

    /** ИИ кабинета для админки: подключение, лимиты, расход и токены месяца, последние запуски. */
    public function companyOverview(string $companyId, int $runs = 50): AiCompanyOverview;

    /**
     * Подключённые компании из списка с исчерпанным лимитом (своим или общим) — одним запросом.
     *
     * @param list<string> $companyIds
     *
     * @return list<string> id в нижнем регистре
     */
    public function exhaustedCompanyIds(array $companyIds): array;

    /**
     * Записать запуск ИИ по компании в журнал (`ai_run`): итог, причина пропуска или класс
     * исключения. Одна инструкция SQL — коммитится сама, если вызывающий не держит транзакцию.
     */
    public function recordRun(
        string $companyId,
        string $taskType,
        int $requested = 0,
        int $tasks = 0,
        int $redispatched = 0,
        int $expired = 0,
        ?string $skippedReason = null,
        ?string $error = null,
    ): void;
}

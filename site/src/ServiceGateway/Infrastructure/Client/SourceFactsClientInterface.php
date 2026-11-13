<?php

declare(strict_types=1);

namespace App\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Application\DTO\SourceFactBatchesPage;
use App\ServiceGateway\Application\DTO\SourceFactPage;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;

/**
 * Выдача фактов одного сервиса (`services/_contracts/source-fact-envelope/v1`).
 */
interface SourceFactsClientInterface
{
    public function service(): string;

    /** Адрес и токен заданы. */
    public function isEnabled(): bool;

    /**
     * Неподтверждённые пачки ресурса; `companyId` пачек не заполнен.
     *
     * @throws ServiceUnavailableException
     * @throws ContractViolationException
     */
    public function fetchBatches(string $resourceType, ?string $after, int $limit): SourceFactBatchesPage;

    /**
     * @throws ServiceUnavailableException сервис недоступен — повтор позже
     * @throws ContractViolationException ответ не по контракту либо страницы нет или она повреждена (404, 502)
     */
    public function fetchPage(string $batchId, int $page): SourceFactPage;

    /**
     * @param list<string> $batchIds
     *
     * @throws ServiceUnavailableException
     * @throws ContractViolationException
     */
    public function ack(array $batchIds): int;
}

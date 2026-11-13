<?php

declare(strict_types=1);

namespace App\ServiceGateway\Facade;

use App\ServiceGateway\Application\DTO\SourceFactBatchesPage;
use App\ServiceGateway\Application\DTO\SourceFactPage;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;

/**
 * Канал фактов «сервис → Symfony» (`source-fact-envelope` v1) для модуля `SourceFacts`.
 * Данных не меняет и ничего не коммитит: только HTTP к сервису и чтение подключений.
 */
interface SourceFactsGatewayFacadeInterface
{
    /**
     * Что забирать: сервис → публикуемые им ресурсы. Сервис без адреса или токена не выдаётся.
     *
     * @return array<string, list<string>>
     */
    public function sources(): array;

    /**
     * Неподтверждённые пачки; компания каждой определена по активному подключению
     * (`companyId` null — подключения нет или оно выключено).
     *
     * @throws ServiceUnavailableException
     * @throws ContractViolationException
     */
    public function batches(string $service, string $resourceType, ?string $after, int $limit): SourceFactBatchesPage;

    /**
     * @throws ServiceUnavailableException
     * @throws ContractViolationException
     */
    public function page(string $service, string $batchId, int $page): SourceFactPage;

    /**
     * @param list<string> $batchIds
     *
     * @throws ServiceUnavailableException
     * @throws ContractViolationException
     */
    public function ack(string $service, array $batchIds): int;
}

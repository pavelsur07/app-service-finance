<?php

declare(strict_types=1);

namespace App\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Application\DTO\ServiceCoverageReport;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;

/**
 * Клиент стандартного GET /internal/coverage одного сервиса
 * (docs/architecture/service-gateway.md §2.3).
 */
interface ServiceCoverageClientInterface
{
    /** Имя сервиса из реестра — ключ витрины. */
    public function service(): string;

    /** Адрес или токен не заданы — обновление выключено. */
    public function isEnabled(): bool;

    /**
     * Окно — по умолчанию сервиса (у ozon-fetcher M-2…конец M).
     *
     * @throws ServiceUnavailableException
     * @throws ContractViolationException
     */
    public function fetchCoverage(): ServiceCoverageReport;
}

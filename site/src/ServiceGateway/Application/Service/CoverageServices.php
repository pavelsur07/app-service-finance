<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Service;

/**
 * Сервисы загрузки на странице «Покрытие Сервис GO»: имя сервиса (ключ
 * витрины и клиента в config/services.yaml) → вкладка. Порядок — порядок вкладок.
 */
final readonly class CoverageServices
{
    public const DEFAULT = 'ozon-fetcher';

    /** @var array<string, array{tab: string, tokenEnv: string}> */
    public const SERVICES = [
        'ozon-fetcher' => ['tab' => 'Ozon', 'tokenEnv' => 'OZON_FETCHER_API_TOKEN'],
        'wb-fetcher' => ['tab' => 'Wildberries', 'tokenEnv' => 'WB_FETCHER_API_TOKEN'],
    ];

    public static function has(string $service): bool
    {
        return isset(self::SERVICES[$service]);
    }
}

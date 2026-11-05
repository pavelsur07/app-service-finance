<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Переключатель ai-service и доступ компаний (ТЗ §4). Выключено, пока не заданы
 * `AI_SERVICE_ENABLED=true`, адрес и токен. Компанию подключает администратор
 * (`ai_company_access`, `/admin/users`); общий откат — переключателем.
 */
final readonly class AiServiceAccess
{
    public function __construct(
        #[Autowire('%env(bool:AI_SERVICE_ENABLED)%')]
        private bool $enabled,
        #[Autowire('%env(default::AI_SERVICE_BASE_URL)%')]
        private ?string $baseUrl,
        #[Autowire('%env(default::AI_SERVICE_API_TOKEN)%')]
        private ?string $token,
        private Connection $db,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && '' !== (string) $this->baseUrl && '' !== (string) $this->token;
    }

    public function isEnabledForCompany(string $companyId): bool
    {
        return [] !== $this->enabledCompanyIds([$companyId]);
    }

    /**
     * @param list<string> $companyIds
     *
     * @return list<string> подключённые к ИИ, в нижнем регистре; пусто при выключенном сервисе
     */
    public function enabledCompanyIds(array $companyIds): array
    {
        return $this->isEnabled() ? $this->grantedCompanyIds($companyIds) : [];
    }

    /**
     * Подключена ли компания администратором — без учёта общего выключателя (для админки).
     *
     * @param list<string> $companyIds
     *
     * @return list<string>
     */
    public function grantedCompanyIds(array $companyIds): array
    {
        if ([] === $companyIds) {
            return [];
        }

        return array_map('strval', $this->db->fetchFirstColumn(
            'SELECT company_id::text FROM ai_company_access WHERE company_id IN (:ids)',
            ['ids' => array_values(array_unique(array_map('strtolower', $companyIds)))],
            ['ids' => ArrayParameterType::STRING],
        ));
    }
}

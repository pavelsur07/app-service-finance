<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Переключатель ai-service и allowlist компаний (ТЗ §4). Выключено, пока не заданы
 * `AI_SERVICE_ENABLED=true`, адрес и токен. Пустой allowlist — ни одной компании,
 * `*` — все: включение идёт поэтапно, откат — переключателем.
 */
final readonly class AiServiceAccess
{
    public function __construct(
        #[Autowire('%env(bool:AI_SERVICE_ENABLED)%')]
        private bool $enabled,
        #[Autowire('%env(default::AI_SERVICE_COMPANY_ALLOWLIST)%')]
        private ?string $allowlist,
        #[Autowire('%env(default::AI_SERVICE_BASE_URL)%')]
        private ?string $baseUrl,
        #[Autowire('%env(default::AI_SERVICE_API_TOKEN)%')]
        private ?string $token,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && '' !== (string) $this->baseUrl && '' !== (string) $this->token;
    }

    public function isEnabledForCompany(string $companyId): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $entries = array_filter(array_map(
            static fn (string $entry): string => strtolower(trim($entry)),
            explode(',', (string) $this->allowlist),
        ));

        return \in_array('*', $entries, true) || \in_array(strtolower($companyId), $entries, true);
    }
}

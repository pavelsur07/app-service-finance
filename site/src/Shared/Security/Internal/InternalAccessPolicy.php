<?php

declare(strict_types=1);

namespace App\Shared\Security\Internal;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Доступ сервисов `services/` к `/internal/*`: static token + IP-allowlist
 * (`services/AGENTS.md` §3). Без обоих значений — закрыто для всех.
 */
final readonly class InternalAccessPolicy
{
    /** Короче — считаем, что токен не задан: слабый общий секрет хуже отсутствующего. */
    public const MIN_TOKEN_LENGTH = 32;

    private string $token;

    /** @var list<string> */
    private array $allowedIps;

    public function __construct(
        #[Autowire('%env(default::INTERNAL_AUTH_TOKEN)%')]
        ?string $token,
        #[Autowire('%env(default::INTERNAL_ALLOWED_IPS)%')]
        ?string $allowedIps,
    ) {
        $this->token = trim((string) $token);
        $this->allowedIps = array_values(array_filter(
            array_map(trim(...), explode(',', (string) $allowedIps)),
            static fn (string $ip): bool => '' !== $ip,
        ));
    }

    public function decide(?string $authorizationHeader, ?string $clientIp): InternalAccessDecision
    {
        if (strlen($this->token) < self::MIN_TOKEN_LENGTH || [] === $this->allowedIps) {
            return InternalAccessDecision::DISABLED;
        }

        $provided = 1 === preg_match('/^Bearer ([^\s]+)$/iD', (string) $authorizationHeader, $matches) ? $matches[1] : '';
        if (!hash_equals($this->token, $provided)) {
            return InternalAccessDecision::AUTH_REQUIRED;
        }

        if (null === $clientIp || !IpUtils::checkIp($clientIp, $this->allowedIps)) {
            return InternalAccessDecision::IP_FORBIDDEN;
        }

        return InternalAccessDecision::GRANTED;
    }
}

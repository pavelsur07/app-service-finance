<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\OAuth;

use Predis\Client;

/**
 * Одноразовость `state` поверх сессии: сессии в Redis без блокировки, два параллельных
 * callback'а могут прочитать одну и ту же запись. Отметка `SET NX` в общем Redis — первый
 * получает право обменять код, второй — отказ: повторный обмен кода банк может счесть
 * атакой и отозвать выданные по нему токены.
 */
final readonly class AuthorizationStateClaims
{
    private const KEY_PREFIX = 'bank_sync:oauth_state_claim:';
    // Дольше жизни состояния входа (10 минут): после неё запись отвергается по сроку.
    private const TTL_SECONDS = 900;

    public function __construct(private Client $redisClient)
    {
    }

    /**
     * @return bool false — `state` уже забрал другой запрос
     */
    public function claim(string $state): bool
    {
        return null !== $this->redisClient->set(self::KEY_PREFIX.hash('sha256', $state), '1', 'EX', self::TTL_SECONDS, 'NX');
    }
}

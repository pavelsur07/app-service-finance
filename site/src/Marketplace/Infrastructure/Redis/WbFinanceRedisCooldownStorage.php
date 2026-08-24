<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Redis;

use App\Marketplace\Application\Service\WbFinanceCooldownStorageInterface;

final readonly class WbFinanceRedisCooldownStorage implements WbFinanceCooldownStorageInterface
{
    public function __construct(private object $redisClient)
    {
    }

    public function getUntilTimestamp(string $key): ?int
    {
        $value = $this->redisClient->get($key);
        if (null === $value || false === $value || '' === $value) {
            return null;
        }

        $timestamp = (int) $value;

        return $timestamp > 0 ? $timestamp : null;
    }

    public function setUntilTimestamp(string $key, int $untilTimestamp, int $ttlSeconds): void
    {
        $script = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if (not current) or (tonumber(current) < tonumber(ARGV[1])) then
    redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
    return ARGV[1]
end
return current
LUA;

        if (is_a($this->redisClient, 'Redis')) {
            $this->redisClient->eval($script, [$key, (string) $untilTimestamp, (string) max(1, $ttlSeconds)], 1);

            return;
        }

        $this->redisClient->eval($script, 1, $key, (string) $untilTimestamp, (string) max(1, $ttlSeconds));
    }
}

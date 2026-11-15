<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Cache;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/** RedisAdapter normally turns fetch errors into misses; immutable indexes require fail-closed reads. */
final class AutomaticMappingRedisAdapter extends RedisAdapter
{
    private bool $readFailed = false;
    private readonly MarshallerInterface $strictMarshaller;

    public function __construct(private readonly \Redis $client, string $namespace = '', int $defaultLifetime = 0, ?MarshallerInterface $marshaller = null)
    {
        $this->strictMarshaller = $marshaller ?? new DefaultMarshaller();
        parent::__construct($client, $namespace, $defaultLifetime, $this->strictMarshaller);
        parent::setLogger(new NullLogger());
    }

    public function setLogger(LoggerInterface $logger): void
    {
        // Pool keys contain session hashes; caller reports safe 503 instead of logging keys.
        parent::setLogger(new NullLogger());
    }

    public function getItem(mixed $key): CacheItem
    {
        $this->readFailed = false;
        $item = parent::getItem($key);
        $this->assertSuccessfulRead();

        return $item;
    }

    private function assertSuccessfulRead(): void
    {
        if ($this->readFailed) {
            throw new \RuntimeException('Automatic mapping cache read failed.');
        }
    }

    /** @param list<string> $ids
     * @return array<string,mixed>
     */
    protected function doFetch(array $ids): iterable
    {
        if ([] === $ids) {
            return [];
        }
        try {
            // Native MGET replies are positional; the extension stub declares string keys.
            /** @var array<array-key,mixed>|false $values */
            $values = $this->client->mget($ids);
            if (!is_array($values) || !array_is_list($values) || count($values) !== count($ids)) {
                throw new \RuntimeException('Incomplete cache read.');
            }
            $result = [];
            foreach ($values as $i => $value) {
                if (false !== $value && null !== $value) {
                    if (!is_string($value)) {
                        throw new \RuntimeException('Invalid cache value.');
                    }
                    $result[$ids[$i]] = $this->strictMarshaller->unmarshall($value);
                }
            }

            return $result;
        } catch (\Throwable) {
            $this->readFailed = true;

            return [];
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Company\Application\ProjectDirection;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

final readonly class ProjectDirectionImportPreviewStore
{
    private const TTL_SECONDS = 900;

    public function __construct(
        #[Autowire(service: 'cache.project_direction_import')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private LockFactory $lockFactory,
    ) {
    }

    public function store(string $sessionId, string $userId, string $companyId, string $json, ?string $revision): ?string
    {
        $key = $this->key($sessionId, $userId, $companyId);
        $lock = $this->lockFactory->createLock('project_direction_import:'.$key);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            $id = bin2hex(random_bytes(16));
            $item = $this->cache->getItem($key);
            $item->set([
                'id' => $id,
                'expiresAt' => $this->clock->now()->getTimestamp() + self::TTL_SECONDS,
                'json' => $json,
                'revision' => $revision,
            ])->expiresAfter(self::TTL_SECONDS);

            return $this->cache->save($item) ? $id : null;
        } finally {
            $lock->release();
        }
    }

    /** @return array{json: string, revision: ?string}|null */
    public function take(string $sessionId, string $userId, string $companyId, string $id): ?array
    {
        if (32 !== \strlen($id) || !ctype_xdigit($id)) {
            return null;
        }
        $key = $this->key($sessionId, $userId, $companyId);
        $lock = $this->lockFactory->createLock('project_direction_import:'.$key);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            $item = $this->cache->getItem($key);
            if (!$item->isHit()) {
                return null;
            }
            /** @var array{id: string, expiresAt: int, json: string, revision: ?string} $draft */
            $draft = $item->get();
            if (!hash_equals($draft['id'], $id) || $draft['expiresAt'] <= $this->clock->now()->getTimestamp() || !$this->cache->deleteItem($key)) {
                return null;
            }

            return ['json' => $draft['json'], 'revision' => $draft['revision']];
        } finally {
            $lock->release();
        }
    }

    private function key(string $sessionId, string $userId, string $companyId): string
    {
        return hash('sha256', $sessionId.':'.$userId.':'.$companyId);
    }
}

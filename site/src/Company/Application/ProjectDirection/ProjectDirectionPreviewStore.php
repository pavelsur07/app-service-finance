<?php

declare(strict_types=1);

namespace App\Company\Application\ProjectDirection;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/** @phpstan-type ImportDraft array{json: string, revision: ?string} */
final readonly class ProjectDirectionPreviewStore
{
    private const TTL_SECONDS = 900;

    public function __construct(
        #[Autowire(service: 'cache.project_direction_import')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private LockFactory $lockFactory,
    ) {
    }

    /** @param ImportDraft|ProjectDirectionCopyDraft $payload */
    public function store(string $sessionId, string $userId, string $scope, array|ProjectDirectionCopyDraft $payload): ?string
    {
        $key = $this->key($sessionId, $userId, $scope);
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
            ] + ($payload instanceof ProjectDirectionCopyDraft ? ['copy' => $payload] : $payload))->expiresAfter(self::TTL_SECONDS);

            return $this->cache->save($item) ? $id : null;
        } finally {
            $lock->release();
        }
    }

    /** @return ImportDraft|ProjectDirectionCopyDraft|null */
    public function take(string $sessionId, string $userId, string $scope, string $id): array|ProjectDirectionCopyDraft|null
    {
        if (32 !== \strlen($id) || !ctype_xdigit($id)) {
            return null;
        }
        $key = $this->key($sessionId, $userId, $scope);
        $lock = $this->lockFactory->createLock('project_direction_import:'.$key);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            $item = $this->cache->getItem($key);
            if (!$item->isHit()) {
                return null;
            }
            /** @var array{id: string, expiresAt: int, json: string, revision: ?string}|array{id: string, expiresAt: int, copy: ProjectDirectionCopyDraft} $draft */
            $draft = $item->get();
            if (!hash_equals($draft['id'], $id) || $draft['expiresAt'] <= $this->clock->now()->getTimestamp() || !$this->cache->deleteItem($key)) {
                return null;
            }

            if (isset($draft['copy'])) {
                return $draft['copy'];
            }

            return ['json' => $draft['json'], 'revision' => $draft['revision']];
        } finally {
            $lock->release();
        }
    }

    private function key(string $sessionId, string $userId, string $scope): string
    {
        return hash('sha256', $sessionId.':'.$userId.':'.$scope);
    }
}

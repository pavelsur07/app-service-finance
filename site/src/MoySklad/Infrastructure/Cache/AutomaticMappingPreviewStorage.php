<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Cache;

use App\MoySklad\Application\DTO\AutomaticMappingApplyResult;
use App\MoySklad\Application\DTO\AutomaticMappingPreview;
use App\MoySklad\Exception\ListingMappingException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Webmozart\Assert\Assert;

final readonly class AutomaticMappingPreviewStorage
{
    public const TTL = 900;
    public const MAX_PREVIEWS = 3;
    public const MAX_RESULTS = 3;

    public function __construct(
        #[Autowire(service: 'cache.moysklad_automatic_mapping')] private CacheItemPoolInterface $cache,
        private LockFactory $locks, private ClockInterface $clock,
    ) {
    }

    public function put(string $sessionId, AutomaticMappingPreview $preview): void
    {
        $this->synchronized($sessionId, function (string $key) use ($preview): void {
            $index = $this->index($key);
            $id = $preview->id;
            $payload = $preview->toArray();
            $existing = $this->read($key.'.'.$id);
            if (null !== $existing) {
                if ($existing !== $payload || !isset($index[$id])) {
                    throw new ListingMappingException('preview_conflict', 'Предпросмотр уже существует с другим содержимым.');
                }

                return;
            }
            if ($preview->expiresAt <= $this->clock->now()->getTimestamp()) {
                throw new ListingMappingException('not_found', 'Предпросмотр истёк. Сформируйте новый.');
            }
            $this->save($key.'.'.$id, $payload, $preview->expiresAt);
            $index[$id] = ['expires' => $preview->expiresAt, 'results' => []];
            while (count($index) > self::MAX_PREVIEWS) {
                array_shift($index);
            }
            $this->save($key, $index, max([1, ...array_column($index, 'expires')]));
        });
    }

    public function get(string $sessionId, string $previewId, string $companyId, string $actorUserId, string $connectionId, string $marketplace): ?AutomaticMappingPreview
    {
        return $this->synchronized($sessionId, fn (string $key): ?AutomaticMappingPreview => $this->bound($key, $previewId, $companyId, $actorUserId, $connectionId, $marketplace));
    }

    public function putResult(string $sessionId, string $previewId, string $attemptId, AutomaticMappingApplyResult $result): void
    {
        Assert::uuid($previewId);
        Assert::uuid($attemptId);
        $this->synchronized($sessionId, function (string $key) use ($previewId, $attemptId, $result): void {
            $index = $this->index($key);
            if (!isset($index[$previewId])) {
                throw new ListingMappingException('not_found', 'Предпросмотр истёк. Некоторые строки могли сохраниться: проверьте историю.');
            }
            $resultKey = $key.'.'.$previewId.'.'.$attemptId;
            $payload = $result->toArray();
            $existing = $this->read($resultKey);
            if (null !== $existing) {
                if ($existing !== $payload || !in_array($attemptId, $index[$previewId]['results'], true)) {
                    throw new ListingMappingException('preview_conflict', 'Результат попытки уже сохранён.');
                }

                return;
            }
            $this->save($resultKey, $payload, $index[$previewId]['expires']);
            $index[$previewId]['results'][] = $attemptId;
            $index[$previewId]['results'] = array_slice($index[$previewId]['results'], -self::MAX_RESULTS);
            $this->save($key, $index, max([1, ...array_column($index, 'expires')]));
        });
    }

    public function getResult(string $sessionId, string $previewId, string $attemptId, string $companyId, string $actorUserId, string $connectionId, string $marketplace): ?AutomaticMappingApplyResult
    {
        Assert::uuid($attemptId);

        return $this->synchronized($sessionId, function (string $key) use ($previewId, $attemptId, $companyId, $actorUserId, $connectionId, $marketplace): ?AutomaticMappingApplyResult {
            if (null === $this->bound($key, $previewId, $companyId, $actorUserId, $connectionId, $marketplace)) {
                return null;
            }
            $index = $this->index($key);
            if (!in_array($attemptId, $index[$previewId]['results'], true)) {
                return null;
            }
            /** @var list<array{listingId:string,status:string,reason:?string,revisionId:?string}>|null $payload */
            $payload = $this->read($key.'.'.$previewId.'.'.$attemptId);

            return null === $payload ? null : AutomaticMappingApplyResult::fromArray($payload);
        });
    }

    private function bound(string $key, string $id, string $company, string $actor, string $connection, string $marketplace): ?AutomaticMappingPreview
    {
        Assert::uuid($id);
        if (!isset($this->index($key)[$id])) {
            return null;
        }
        $data = $this->read($key.'.'.$id);
        if (null === $data) {
            return null;
        }
        $preview = AutomaticMappingPreview::fromArray($data);

        return [$preview->companyId, $preview->actorUserId, $preview->connectionId, $preview->marketplace] === [$company, $actor, $connection, $marketplace]
            && $preview->expiresAt > $this->clock->now()->getTimestamp() ? $preview : null;
    }

    /** @return array<string,array{expires:int,results:list<string>}> */
    private function index(string $key): array
    {
        return array_filter($this->read($key) ?? [], fn (array $entry): bool => $entry['expires'] > $this->clock->now()->getTimestamp());
    }

    /** @return array<mixed>|null */
    private function read(string $key): ?array
    {
        $item = $this->cache->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    /** @param array<mixed> $data */
    private function save(string $key, array $data, int $expiresAt): void
    {
        $item = $this->cache->getItem($key);
        $item->set($data)->expiresAfter(max(1, $expiresAt - $this->clock->now()->getTimestamp()));
        if (!$this->cache->save($item)) {
            throw new \RuntimeException('Cache write failed.');
        }
    }

    /** @template T
     * @param \Closure(string): T $operation
     *
     * @return T
     */
    private function synchronized(string $sessionId, \Closure $operation): mixed
    {
        $lock = null;
        try {
            Assert::minLength($sessionId, 1);
            $hash = hash('sha256', $sessionId);
            $lock = $this->locks->createLock('moysklad-automatic-mapping:'.$hash, 30);
            if (!$lock->acquire(false)) {
                throw new ListingMappingException('preview_busy', 'Предпросмотр занят другим запросом. Повторите действие.');
            }

            return $operation('auto.'.$hash);
        } catch (ListingMappingException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new ListingMappingException('preview_unavailable', 'Хранилище предпросмотра недоступно. Если применяли связи, проверьте историю: некоторые строки могли сохраниться.');
        } finally {
            if (null !== $lock) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // A lost Redis connection expires the short lease automatically.
                }
            }
        }
    }
}

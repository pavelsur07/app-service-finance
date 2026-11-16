<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Infrastructure;

use App\Marketplace\DTO\ListingMetaDTO;
use App\Marketplace\DTO\ListingMetaPageDTO;
use App\MoySklad\Application\DTO\AutomaticMappingDecision;
use App\MoySklad\Application\DTO\AutomaticMappingGuard;
use App\MoySklad\Application\DTO\AutomaticMappingPreview;
use App\MoySklad\Application\DTO\AutomaticMappingPreviewPage;
use App\MoySklad\Application\DTO\CatalogTargetDTO;
use App\MoySklad\Enum\AutomaticMappingDecisionStatus;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Infrastructure\Cache\AutomaticMappingPreviewStorage;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class AutomaticMappingPreviewStorageTest extends TestCase
{
    private const COMPANY = '11111111-1111-4111-8111-000000000001';
    private const ACTOR = '22222222-2222-4222-8222-000000000001';
    private const CONNECTION = '33333333-3333-4333-8333-000000000001';

    public function testRoundtripBindingImmutabilityAndExpiry(): void
    {
        $clock = new MockClock();
        $store = new AutomaticMappingPreviewStorage(new ArrayAdapter(), new LockFactory(new InMemoryStore()), $clock);
        $preview = $this->preview($clock);
        $store->put('session-one', $preview);
        $loaded = $this->get($store, $preview);
        self::assertEquals($preview, $loaded);
        self::assertSame($preview->items[0]->requestId, $loaded?->items[0]->requestId);
        self::assertNull($store->get('other-session', $preview->id, self::COMPANY, self::ACTOR, self::CONNECTION, 'ozon'));
        foreach (['company', 'actor', 'connection', 'marketplace'] as $changed) {
            self::assertNull($store->get('session-one', $preview->id,
                'company' === $changed ? Uuid::uuid7()->toString() : self::COMPANY,
                'actor' === $changed ? Uuid::uuid7()->toString() : self::ACTOR,
                'connection' === $changed ? Uuid::uuid7()->toString() : self::CONNECTION,
                'marketplace' === $changed ? 'wildberries' : 'ozon'));
        }
        $store->put('session-one', $preview);
        $clock->sleep(900);
        self::assertNull($this->get($store, $preview));
    }

    public function testFourthPreviewEvictsOldestAndForeignPayloadCannotOverwrite(): void
    {
        $clock = new MockClock();
        $store = new AutomaticMappingPreviewStorage(new ArrayAdapter(), new LockFactory(new InMemoryStore()), $clock);
        $previews = [];
        foreach (range(1, 4) as $i) {
            $previews[] = $preview = $this->preview($clock);
            $store->put('session-one', $preview);
            $clock->sleep(1);
        }
        self::assertNull($this->get($store, $previews[0]));
        foreach (array_slice($previews, 1) as $preview) {
            self::assertNotNull($this->get($store, $preview));
        }
        $payload = $previews[3]->toArray();
        $payload['q'] = 'different';
        try {
            $store->put('session-one', AutomaticMappingPreview::fromArray($payload));
            self::fail('Immutable preview was replaced.');
        } catch (ListingMappingException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        self::assertEquals($previews[3], $this->get($store, $previews[3]));
    }

    public function testBusySessionRejectsWithoutLosingPreview(): void
    {
        $clock = new MockClock();
        $locks = new LockFactory(new InMemoryStore());
        $store = new AutomaticMappingPreviewStorage(new ArrayAdapter(), $locks, $clock);
        $held = $locks->createLock('moysklad-automatic-mapping:'.hash('sha256', 'session-one'), 30);
        self::assertTrue($held->acquire());
        try {
            $store->put('session-one', $this->preview($clock));
            self::fail('Concurrent index mutation must reject.');
        } catch (ListingMappingException $error) {
            self::assertSame(409, $error->getStatusCode());
        } finally {
            $held->release();
        }
    }

    public function testGuardRejectsMalformedAndUnsupportedRule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AutomaticMappingGuard(Uuid::uuid7()->toString(), 'guessed_rule', str_repeat('a', 64), null, str_repeat('b', 64));
    }

    public function testAttemptsRemainSeparateBoundedAndImmutable(): void
    {
        $clock = new MockClock();
        $store = new AutomaticMappingPreviewStorage(new ArrayAdapter(), new LockFactory(new InMemoryStore()), $clock);
        $preview = $this->preview($clock);
        $store->put('session-one', $preview);
        $attempts = [];
        foreach (range(1, 4) as $i) {
            $attempts[] = $id = Uuid::uuid7()->toString();
            $result = new \App\MoySklad\Application\DTO\AutomaticMappingApplyResult([
                new \App\MoySklad\Application\DTO\AutomaticMappingApplyItem($preview->items[0]->listingId, \App\MoySklad\Enum\AutomaticMappingApplyStatus::ACCEPTED, revisionId: (string) $i),
            ]);
            $store->putResult('session-one', $preview->id, $id, $result);
            self::assertEquals($result, $store->getResult('session-one', $preview->id, $id, self::COMPANY, self::ACTOR, self::CONNECTION, 'ozon'));
        }
        self::assertNull($store->getResult('session-one', $preview->id, $attempts[0], self::COMPANY, self::ACTOR, self::CONNECTION, 'ozon'));
        self::assertNull($store->getResult('other-session', $preview->id, $attempts[3], self::COMPANY, self::ACTOR, self::CONNECTION, 'ozon'));
        $this->expectException(ListingMappingException::class);
        $store->putResult('session-one', $preview->id, $attempts[3], new \App\MoySklad\Application\DTO\AutomaticMappingApplyResult([]));
    }

    public function testCacheOutageIsExplicitServiceUnavailable(): void
    {
        $pool = $this->createMock(\Psr\Cache\CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new \RuntimeException('Unavailable.'));
        $store = new AutomaticMappingPreviewStorage($pool, new LockFactory(new InMemoryStore()), new MockClock());
        try {
            $this->get($store, $this->preview(new MockClock()));
            self::fail('Cache failure cannot look like empty preview.');
        } catch (ListingMappingException $error) {
            self::assertSame(503, $error->getStatusCode());
        }
    }

    public function testRealRedisAdapterReadFailureCannotBecomeCacheMiss(): void
    {
        foreach ([false, [], ['unexpected' => 'not-a-list'], new \RedisException('Test connection failure')] as $failure) {
            $redis = $this->createMock(\Redis::class);
            if ($failure instanceof \Throwable) {
                $redis->method('mget')->willThrowException($failure);
            } else {
                $redis->method('mget')->willReturn($failure);
            }
            $adapter = new \App\MoySklad\Infrastructure\Cache\AutomaticMappingRedisAdapter($redis);
            $store = new AutomaticMappingPreviewStorage($adapter, new LockFactory(new InMemoryStore()), new MockClock());
            try {
                $this->get($store, $this->preview(new MockClock()));
                self::fail('Redis read failure was interpreted as missing preview.');
            } catch (ListingMappingException $error) {
                self::assertSame(503, $error->getStatusCode());
            }
        }
    }

    public function testSessionIdentityDoesNotLeakThroughFrameworkLockLogger(): void
    {
        $handler = new \Monolog\Handler\TestHandler();
        $locks = new LockFactory(new InMemoryStore());
        $locks->setLogger(new \Monolog\Logger('private-preview', [$handler]));
        $clock = new MockClock();
        $store = new AutomaticMappingPreviewStorage(new ArrayAdapter(), $locks, $clock);
        $store->put('private-test-session', $this->preview($clock));
        $formatter = new \Monolog\Formatter\LineFormatter('%message% %context%');
        $messages = implode("\n", array_map($formatter->format(...), $handler->getRecords()));
        self::assertStringNotContainsString('private-test-session', $messages);
        self::assertStringNotContainsString(hash('sha256', 'private-test-session'), $messages);
    }

    private function preview(MockClock $clock): AutomaticMappingPreview
    {
        $source = new ListingMetaDTO(Uuid::uuid7()->toString(), 'Листинг', 'SKU', 'ozon', '001');
        $target = new CatalogTargetDTO('product', Uuid::uuid7()->toString(), null, 'Товар', null, '001', [], true);
        $page = new AutomaticMappingPreviewPage(new ListingMetaPageDTO([$source], 1, 25, 1),
            [$source->id => new AutomaticMappingDecision(AutomaticMappingDecisionStatus::READY, $target, ['direct_article'])], ['ready' => 1, 'total_shown' => 1], '', null);

        return AutomaticMappingPreview::fromPage(self::COMPANY, self::ACTOR, self::CONNECTION, 'ozon', $page, $clock->now());
    }

    private function get(AutomaticMappingPreviewStorage $store, AutomaticMappingPreview $preview): ?AutomaticMappingPreview
    {
        return $store->get('session-one', $preview->id, self::COMPANY, self::ACTOR, self::CONNECTION, 'ozon');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Application\Action\SyncCatalogAction;
use App\MoySklad\Application\CatalogPageParser;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Entity\MoySkladSyncRun;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Exception\CatalogSyncException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladProductRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncCursorRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncRunRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladVariantRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use App\MoySklad\Message\SyncCatalogMessage;
use App\MoySklad\MessageHandler\SyncCatalogHandler;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class SyncCatalogActionTest extends WebTestCaseBase
{
    private const ACCOUNT_ID = '00000000-0000-4000-8000-000000000003';

    public function testLoadsProductsThenVariantsAndRepeatedRunIsIdempotent(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $responses = $this->successfulResponses();

        self::assertTrue(($this->action($responses))($connection->getCompanyId(), $connection->getId()));
        $db = $this->em()->getConnection();
        self::assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_products'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_variants'));
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_sync_cursors WHERE connection_id = ?', [$connection->getId()]));
        $variant = static::getContainer()->get(MoySkladVariantRepository::class)->findByExternalId($connection->getCompanyId(), $connection->getId(), '00000000-0000-4000-8000-000000000101');
        self::assertSame('00000000-0000-4000-8000-000000000001', $variant?->getProductExternalId());

        self::assertTrue(($this->action($this->successfulResponses()))($connection->getCompanyId(), $connection->getId()));
        self::assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_products'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_variants'));
        $run = static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'variant');
        self::assertSame(1, $run?->getUnchanged());
    }

    public function testVariantFailureKeepsProductCursorAndOldVariantCursor(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        ($this->action($this->successfulResponses()))($connection->getCompanyId(), $connection->getId());
        $db = $this->em()->getConnection();
        $previousVariantCursor = $db->fetchOne("SELECT last_completed_at FROM moysklad_sync_cursors WHERE connection_id = ? AND entity_type = 'variant'", [$connection->getId()]);

        $responses = $this->successfulResponses();
        $responses[2] = new MockResponse('', ['http_code' => 429, 'response_headers' => ['X-Lognex-Retry-After: 1200']]);
        try {
            ($this->action(array_values($responses)))($connection->getCompanyId(), $connection->getId());
            self::fail('Rate limit should fail this run.');
        } catch (CatalogSyncException $error) {
            self::assertSame('rate_limited', $error->category);
            self::assertSame(1200, $error->retryAfterMs);
        }
        self::assertSame($previousVariantCursor, $db->fetchOne("SELECT last_completed_at FROM moysklad_sync_cursors WHERE connection_id = ? AND entity_type = 'variant'", [$connection->getId()]));
        self::assertSame('failed', $db->fetchOne("SELECT status FROM moysklad_sync_runs WHERE connection_id = ? AND entity_type = 'variant' ORDER BY started_at DESC, id DESC LIMIT 1", [$connection->getId()]));
    }

    public function testRejectsMissingParentWithoutCreatingVariant(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $variant = json_decode($this->page('Variant/variants_documented_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $variant['rows'][0]['product']['meta']['href'] = 'https://api.moysklad.ru/api/remap/1.2/entity/product/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $responses = $this->successfulResponses();
        $responses[2] = new MockResponse(json_encode($variant, \JSON_THROW_ON_ERROR));

        try {
            ($this->action(array_values($responses)))($connection->getCompanyId(), $connection->getId());
            self::fail('Missing parent should fail this run.');
        } catch (CatalogSyncException $error) {
            self::assertSame('temporary', $error->category);
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_variants'));
    }

    public function testRejectsUnsortedVariantPageWithoutAdvancingCursor(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $variant = json_decode($this->page('Variant/variants_documented_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $later = $variant['rows'][0];
        $later['id'] = '00000000-0000-4000-8000-000000000102';
        $variant['rows'] = [$later, $variant['rows'][0]];
        $variant['meta']['size'] = 2;
        $responses = $this->successfulResponses();
        $responses[2] = new MockResponse(json_encode($variant, \JSON_THROW_ON_ERROR));

        try {
            ($this->action(array_values($responses)))($connection->getCompanyId(), $connection->getId());
            self::fail('Unsorted page must fail.');
        } catch (CatalogSyncException $error) {
            self::assertSame('temporary', $error->category);
        }
        self::assertNull(static::getContainer()->get(MoySkladSyncCursorRepository::class)->findFor($connection->getCompanyId(), $connection->getId(), 'variant'));
    }

    public function testAdvancesOffsetAcrossProductPages(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $page = json_decode($this->page('Product/products_active_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $template = $page['rows'][0];
        $page['rows'] = [];
        for ($i = 1000; $i < 1100; ++$i) {
            $row = $template;
            $row['id'] = sprintf('00000000-0000-4000-8000-%012d', $i);
            $page['rows'][] = $row;
        }
        $page['meta']['size'] = 102;
        $second = json_decode($this->page('Product/products_active_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $second['rows'] = [];
        foreach ([1100, 1101] as $id) {
            $row = $template;
            $row['id'] = sprintf('00000000-0000-4000-8000-%012d', $id);
            $second['rows'][] = $row;
        }
        $second['meta']['size'] = 102;
        $second['meta']['offset'] = 100;
        $secondResponse = new MockResponse(json_encode($second, \JSON_THROW_ON_ERROR));
        $responses = [
            new MockResponse(json_encode($page, \JSON_THROW_ON_ERROR)),
            $secondResponse,
            new MockResponse($this->page('Product/products_archived_empty.json')),
            new MockResponse($this->page('Variant/variants_active_empty.json')),
            new MockResponse($this->page('Variant/variants_archived_empty.json')),
        ];
        self::assertTrue(($this->action($responses))($connection->getCompanyId(), $connection->getId()));
        self::assertStringContainsString('offset=100', $secondResponse->getRequestUrl());
        self::assertSame(102, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_products'));
    }

    public function testChangingPageSizeIsRetriedAsTemporaryDrift(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $first = json_decode($this->page('Product/products_active_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $first['meta']['size'] = 3;
        $second = $first;
        $second['meta']['offset'] = 2;
        $second['meta']['size'] = 4;
        $second['rows'] = [];
        try {
            ($this->action([new MockResponse(json_encode($first, \JSON_THROW_ON_ERROR)), new MockResponse(json_encode($second, \JSON_THROW_ON_ERROR))]))($connection->getCompanyId(), $connection->getId());
            self::fail('Source drift should fail this pass.');
        } catch (CatalogSyncException $error) {
            self::assertSame('temporary', $error->category);
        }
        self::assertNull(static::getContainer()->get(MoySkladSyncCursorRepository::class)->findFor($connection->getCompanyId(), $connection->getId(), 'product'));
    }

    public function testCannotSyncConnectionOfAnotherCompany(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        self::assertFalse(($this->action([]))('22222222-2222-7222-8222-222222222222', $connection->getId()));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_sync_runs'));
    }

    public function testSecondProductPageFailureKeepsCommittedPageButNoCursor(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $page = json_decode($this->page('Product/products_active_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $page['meta']['size'] = 3;
        try {
            ($this->action([
                new MockResponse(json_encode($page, \JSON_THROW_ON_ERROR)),
                new MockResponse('', ['http_code' => 503]),
            ]))($connection->getCompanyId(), $connection->getId());
            self::fail('Second page should fail.');
        } catch (CatalogSyncException $error) {
            self::assertSame('temporary', $error->category);
        }
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_products'));
        self::assertNull(static::getContainer()->get(MoySkladSyncCursorRepository::class)->findFor($connection->getCompanyId(), $connection->getId(), 'product'));
        self::assertSame('failed', static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'product')?->getStatus());
    }

    public function testRestartRepairsStaleRunningRun(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $staleId = 'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa';
        $this->em()->persist(new MoySkladSyncRun($staleId, $connection->getCompanyId(), $connection->getId(), 'product', new \DateTimeImmutable('2026-09-19T09:00:00+00:00')));
        $this->em()->flush();

        self::assertTrue(($this->action($this->successfulResponses()))($connection->getCompanyId(), $connection->getId()));
        $stale = static::getContainer()->get(MoySkladSyncRunRepository::class)->findByIdAndCompanyId($staleId, $connection->getCompanyId());
        self::assertSame('failed', $stale?->getStatus());
        self::assertSame('internal', $stale->getErrorCategory());
        self::assertSame('succeeded', static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'product')?->getStatus());
    }

    public function testTokenDecodeFailureRecordsSafeRunStatus(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $connection->setAccessTokenEncrypted('invalid-encrypted-payload');
        $this->em()->persist($connection);
        $this->em()->flush();

        try {
            ($this->action([]))($connection->getCompanyId(), $connection->getId());
            self::fail('Corrupt token must fail.');
        } catch (CatalogSyncException $error) {
            self::assertSame('internal', $error->category);
        }
        $run = static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'product');
        self::assertSame('failed', $run?->getStatus());
        self::assertSame('internal', $run->getErrorCategory());
    }

    public function testRateLimitSchedulesBoundedRetry(): void
    {
        $this->resetDb();
        $connection = $this->verifiedConnection();
        $this->em()->persist($connection);
        $this->em()->flush();
        $dispatched = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (SyncCatalogMessage $message, array $stamps) use (&$dispatched): Envelope {
            $dispatched = [$message, $stamps];

            return new Envelope($message);
        });
        $handler = new SyncCatalogHandler($this->action([new MockResponse('', ['http_code' => 429, 'response_headers' => ['X-Lognex-Retry-After: 60000']])]), $bus, new NullLogger());
        $handler(new SyncCatalogMessage($connection->getCompanyId(), $connection->getId()));
        self::assertNotNull($dispatched);
        self::assertSame(1, $dispatched[0]->attempt);
        self::assertInstanceOf(DelayStamp::class, $dispatched[1][0]);
        self::assertSame(60_000, $dispatched[1][0]->getDelay());
    }

    private function verifiedConnection(): MoySkladConnection
    {
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $connection->bindAccount(self::ACCOUNT_ID);
        $connection->setAccessToken('test-secret');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());

        return $connection;
    }

    /** @param list<MockResponse> $responses */
    private function action(array $responses): SyncCatalogAction
    {
        return new SyncCatalogAction(
            $this->em(),
            static::getContainer()->get(MoySkladConnectionWriteRepository::class),
            static::getContainer()->get(MoySkladProductRepository::class),
            static::getContainer()->get(MoySkladVariantRepository::class),
            static::getContainer()->get(MoySkladSyncCursorRepository::class),
            static::getContainer()->get(MoySkladSyncRunRepository::class),
            new MoySkladClient(new MockHttpClient($responses), 'https://example.test/api/remap/1.2'),
            new CatalogPageParser(),
            static::getContainer()->get(ConnectionTokenCodec::class),
            new NullLogger(),
        );
    }

    /** @return list<MockResponse> */
    private function successfulResponses(): array
    {
        return [
            new MockResponse($this->page('Product/products_active_page_0.json')),
            new MockResponse($this->page('Product/products_archived_page_0.json')),
            new MockResponse($this->page('Variant/variants_documented_page_0.json')),
            new MockResponse($this->page('Variant/variants_archived_empty.json')),
        ];
    }

    private function page(string $name): string
    {
        $body = file_get_contents(__DIR__.'/../../Fixtures/MoySklad/'.$name);
        self::assertIsString($body);
        $page = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        $page['meta']['limit'] = 100;
        $page['meta']['offset'] = 0;
        $page['meta']['size'] = count($page['rows']);

        return json_encode($page, \JSON_THROW_ON_ERROR);
    }
}

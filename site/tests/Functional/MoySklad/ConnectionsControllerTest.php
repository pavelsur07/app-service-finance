<?php

declare(strict_types=1);

namespace App\Tests\Functional\MoySklad;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\MoySklad\Domain\ProductSnapshot;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Entity\MoySkladProduct;
use App\MoySklad\Entity\MoySkladStockSnapshot;
use App\MoySklad\Entity\MoySkladStockSnapshotLine;
use App\MoySklad\Entity\MoySkladSyncCursor;
use App\MoySklad\Entity\MoySkladSyncRun;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Infrastructure\Query\MoySkladSyncStatusQuery;
use App\MoySklad\Message\SyncCatalogMessage;
use App\MoySklad\Message\SyncCounterpartiesMessage;
use App\MoySklad\Message\SyncStockSnapshotMessage;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MoySklad\MoySkladStoreBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ConnectionsControllerTest extends WebTestCaseBase
{
    public function testEditDoesNotDiscloseStoredToken(): void
    {
        [$client, $connection] = $this->seed();
        $client->request('GET', '/moy-sklad/connections/'.$connection->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('stored-sensitive-token', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('stored-refresh-secret', (string) $client->getResponse()->getContent());
    }

    public function testUnverifiedConnectionIsNotShownAsConnected(): void
    {
        [$client, $connection] = $this->seed();
        $crawler = $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Требуется проверка');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', 'Ещё не запускалось');
        self::assertCount(0, $crawler->filter('form[action="/moy-sklad/connections/'.$connection->getId().'/sync-stock"]'));
        self::assertSelectorExists('a[href="/moy-sklad/connections"]');
    }

    public function testVerifiedConnectionQueuesCounterpartySyncWithCsrf(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $this->em()->flush();
        $crawler = $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[action="/moy-sklad/connections/'.$connection->getId().'/sync-counterparties"]'));
        $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/sync-counterparties', [
            '_token' => $this->csrfToken($client, 'moysklad_sync_counterparties'.$connection->getId()),
        ]);
        self::assertResponseRedirects('/moy-sklad/connections');
        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_sync');
        self::assertCount(1, $transport->getSent());
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(SyncCounterpartiesMessage::class, $message);
        self::assertSame($connection->getCompanyId(), $message->companyId);
        self::assertSame($connection->getId(), $message->connectionId);
        self::assertStringNotContainsString('stored-sensitive-token', (string) $client->getResponse()->getContent());
    }

    public function testVerifiedConnectionQueuesCatalogSyncAndShowsSeparateStatuses(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $now = new \DateTimeImmutable('2026-09-20T09:00:00+00:00');
        $productRun = new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'product', $now);
        $productRun->recordPage(2, 2, 0, 0);
        $productRun->succeed($now);
        $variantRun = new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'variant', $now);
        $variantRun->fail('rate_limited', $now);
        $this->em()->persist($productRun);
        $this->em()->persist($variantRun);
        $this->em()->flush();

        $crawler = $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Товары: Успешно');
        self::assertSelectorTextContains('body', 'Модификации: Ошибка');
        self::assertCount(1, $crawler->filter('form[action="/moy-sklad/connections/'.$connection->getId().'/sync-catalog"]'));
        $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/sync-catalog', [
            '_token' => $this->csrfToken($client, 'moysklad_sync_catalog'.$connection->getId()),
        ]);
        self::assertResponseRedirects('/moy-sklad/connections');
        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_sync');
        self::assertCount(1, $transport->getSent());
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(SyncCatalogMessage::class, $message);
        self::assertSame($connection->getCompanyId(), $message->companyId);
        self::assertSame($connection->getId(), $message->connectionId);
    }

    public function testCatalogSyncRequiresCsrfWriteAccessAndOwnCompany(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $foreign = new MoySkladConnection(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), 'Чужой склад', $connection->getBaseUrl());
        $this->em()->persist($foreign);
        $this->em()->flush();
        $statuses = static::getContainer()->get(MoySkladSyncStatusQuery::class)->forConnections($connection->getCompanyId(), [$connection->getId(), $foreign->getId()], 'product');
        self::assertArrayHasKey($connection->getId(), $statuses);
        self::assertArrayNotHasKey($foreign->getId(), $statuses);

        $url = '/moy-sklad/connections/'.$connection->getId().'/sync-catalog';
        $client->request('POST', $url, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/moy-sklad/connections/'.$foreign->getId().'/sync-catalog');
        self::assertResponseStatusCodeSame(404);
        $this->loginMember($client, $connection->getCompanyId(), ['marketplace' => 'read']);
        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/sync-catalog"]');
        $client->request('POST', $url, ['_token' => $this->csrfToken($client, 'moysklad_sync_catalog'.$connection->getId())]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRunningCatalogSyncAllowsManualRecoveryOfStaleRun(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $this->em()->persist(new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'product', new \DateTimeImmutable('2026-09-20T09:00:00+00:00')));
        $this->em()->flush();

        $crawler = $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Товары: Выполняется');
        self::assertCount(1, $crawler->filter('form[action="/moy-sklad/connections/'.$connection->getId().'/sync-catalog"]'));
        self::assertSelectorTextContains('body', 'Уже выполняется');
    }

    public function testMalformedCatalogConnectionIdReturnsNotFound(): void
    {
        [$client] = $this->seed();
        $client->request('POST', '/moy-sklad/connections/not-a-uuid/sync-catalog');
        self::assertResponseStatusCodeSame(404);
    }

    public function testVerifiedConnectionQueuesStockSnapshotSyncWithScalarTenantIds(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $this->em()->flush();

        $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/sync-stock', [
            '_token' => $this->csrfToken($client, 'moysklad_sync_stock'.$connection->getId()),
        ]);

        self::assertResponseRedirects('/moy-sklad/connections');
        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_sync');
        self::assertCount(1, $transport->getSent());
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(SyncStockSnapshotMessage::class, $message);
        self::assertSame($connection->getCompanyId(), $message->companyId);
        self::assertSame($connection->getId(), $message->connectionId);
    }

    public function testStockSnapshotSyncRouteRejectsGetMalformedUuidAndInvalidCsrf(): void
    {
        [$client, $connection] = $this->seed();
        $url = '/moy-sklad/connections/'.$connection->getId().'/sync-stock';

        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(405);
        $client->request('POST', '/moy-sklad/connections/not-a-uuid/sync-stock');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $url, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testStockSnapshotSyncRejectsForeignTenantAndReadOnlyMember(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $foreign = new MoySkladConnection(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), 'Чужой склад', $connection->getBaseUrl());
        $this->em()->persist($foreign);
        $this->em()->flush();

        $client->request('POST', '/moy-sklad/connections/'.$foreign->getId().'/sync-stock');
        self::assertResponseStatusCodeSame(404);
        $this->loginMember($client, $connection->getCompanyId(), ['marketplace' => 'read']);
        $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/sync-stock', [
            '_token' => $this->csrfToken($client, 'moysklad_sync_stock'.$connection->getId()),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testStockSnapshotSyncDoesNotDispatchForUnverifiedOrInactiveConnection(): void
    {
        [$client, $connection] = $this->seed();
        $url = '/moy-sklad/connections/'.$connection->getId().'/sync-stock';
        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_sync');

        $client->request('POST', $url, ['_token' => $this->csrfToken($client, 'moysklad_sync_stock'.$connection->getId())]);
        self::assertResponseRedirects('/moy-sklad/connections');
        self::assertCount(0, $transport->getSent());

        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $connection->setIsActive(false);
        $this->em()->flush();
        $client->request('POST', $url, ['_token' => $this->csrfToken($client, 'moysklad_sync_stock'.$connection->getId())]);
        self::assertResponseRedirects('/moy-sklad/connections');
        self::assertCount(0, $transport->getSent());
    }

    public function testStockStatusReadModelIsTenantScopedAndKeepsLatestCompletedSnapshot(): void
    {
        [$client, $connection] = $this->seed();
        $companyId = $connection->getCompanyId();
        $second = new MoySkladConnection(Uuid::uuid7()->toString(), $companyId, 'Второе подключение', $connection->getBaseUrl());
        $foreign = new MoySkladConnection(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), 'Чужое подключение', $connection->getBaseUrl());
        $startedAt = new \DateTimeImmutable('2026-09-21T08:00:00+00:00');
        $completedAt = new \DateTimeImmutable('2026-09-21T08:10:00+00:00');
        $store = MoySkladStoreBuilder::aStore()->withTenant($companyId, $connection->getId())->build();
        $secondStore = MoySkladStoreBuilder::aStore()->withIndex(2)->withTenant($companyId, $connection->getId())->build();
        $product = new MoySkladProduct(
            '11111111-1111-7111-8111-111111111112',
            $companyId,
            $connection->getId(),
            new ProductSnapshot('00000000-0000-4000-8000-000000000001', 'Product', 'product-external', null, null, 0, false, $startedAt),
            $startedAt,
        );
        $olderCompleted = new MoySkladStockSnapshot('99999999-9999-7999-8999-999999999999', $companyId, $connection->getId(), $startedAt->modify('-1 hour'));
        foreach ([$second, $foreign, $store, $secondStore, $product, $olderCompleted] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        foreach ([
            new MoySkladStockSnapshotLine('77777777-7777-7777-8777-777777777777', $companyId, $connection->getId(), $olderCompleted->getId(), $store->getExternalId(), 'product', $product->getExternalId(), '1', '0', '0'),
            new MoySkladStockSnapshotLine('88888888-8888-7888-8888-888888888888', $companyId, $connection->getId(), $olderCompleted->getId(), $secondStore->getExternalId(), 'product', $product->getExternalId(), '2', '0', '0'),
        ] as $line) {
            $this->em()->persist($line);
        }
        $this->em()->flush();
        $olderCompleted->complete($completedAt->modify('-1 hour'));
        $this->em()->flush();
        $completed = new MoySkladStockSnapshot('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $companyId, $connection->getId(), $startedAt);
        $this->em()->persist($completed);
        $this->em()->flush();
        $this->em()->persist(new MoySkladStockSnapshotLine('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $companyId, $connection->getId(), $completed->getId(), $store->getExternalId(), 'product', $product->getExternalId(), '1', '0', '0'));
        $this->em()->flush();
        $completed->complete($completedAt);
        $this->em()->flush();
        $failed = new MoySkladStockSnapshot('cccccccc-cccc-7ccc-8ccc-cccccccccccc', $companyId, $connection->getId(), $completedAt->modify('+1 minute'));
        $failed->fail($completedAt->modify('+2 minutes'));
        $building = new MoySkladStockSnapshot('dddddddd-dddd-7ddd-8ddd-dddddddddddd', $companyId, $connection->getId(), $completedAt->modify('+3 minutes'));
        $empty = new MoySkladStockSnapshot('eeeeeeee-eeee-7eee-8eee-eeeeeeeeeeee', $companyId, $second->getId(), $startedAt);
        $empty->complete($completedAt);
        $foreignCompleted = new MoySkladStockSnapshot('ffffffff-ffff-7fff-8fff-ffffffffffff', $foreign->getCompanyId(), $foreign->getId(), $startedAt);
        $foreignCompleted->complete($completedAt);
        $storeRun = new MoySkladSyncRun(Uuid::uuid7()->toString(), $companyId, $connection->getId(), 'store', $startedAt);
        $storeRun->recordPage(1, 1, 0, 0);
        $storeRun->succeed($completedAt);
        $stockRun = new MoySkladSyncRun(Uuid::uuid7()->toString(), $companyId, $connection->getId(), 'stock', $startedAt);
        $stockRun->recordPage(1, 1, 0, 0);
        $stockRun->succeed($completedAt);
        foreach ([$failed, $building, $empty, $foreignCompleted, $storeRun, $stockRun] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $query = static::getContainer()->get(MoySkladSyncStatusQuery::class);
        $connectionIds = [$connection->getId(), $second->getId(), $foreign->getId()];
        self::assertSame('succeeded', $query->forConnections($companyId, $connectionIds, 'store')[$connection->getId()]['status']);
        self::assertSame('succeeded', $query->forConnections($companyId, $connectionIds, 'stock')[$connection->getId()]['status']);
        $snapshots = $query->latestCompletedStockSnapshotsForConnections($companyId, $connectionIds);
        self::assertSame($completed->getId(), $snapshots[$connection->getId()]['id']);
        self::assertEquals($startedAt, $snapshots[$connection->getId()]['startedAt']);
        self::assertEquals($completedAt, $snapshots[$connection->getId()]['completedAt']);
        self::assertSame(1, $snapshots[$connection->getId()]['lineCount']);
        self::assertSame(0, $snapshots[$second->getId()]['lineCount']);
        self::assertArrayNotHasKey($foreign->getId(), $snapshots);
    }

    public function testStockUiShowsEmptyStatesAndAllowsRetryWhileRunning(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $this->em()->flush();

        $crawler = $client->request('GET', '/moy-sklad/connections');
        self::assertSelectorTextContains('[data-sync-stream="store"]', 'Ещё не запускалось');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', 'Ещё не запускалось');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', 'Последний завершённый снимок: Ещё не создавался');
        self::assertCount(1, $crawler->filter('form[action="/moy-sklad/connections/'.$connection->getId().'/sync-stock"]'));

        $this->em()->persist(new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'stock', new \DateTimeImmutable('2026-09-22T09:00:00+00:00')));
        $this->em()->flush();
        $crawler = $client->request('GET', '/moy-sklad/connections');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', 'Выполняется');
        self::assertCount(1, $crawler->filter('form[action="/moy-sklad/connections/'.$connection->getId().'/sync-stock"]'));
        self::assertSelectorTextContains('form[action$="/sync-stock"] button', 'Проверить и повторить загрузку складов и остатков');
        self::assertSelectorTextContains('form[action$="/sync-stock"]', 'Повторный запрос будет пропущен, если загрузка ещё активна');
        self::assertCount(1, $crawler->filter('form[action$="/sync-stock"].mw-100 button.text-wrap'));
    }

    public function testStockUiShowsSuccessfulRunsCursorsSnapshotAndFiveItemHistory(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $completedAt = new \DateTimeImmutable('2026-09-22T09:10:00+00:00');
        foreach (['store', 'stock'] as $entityType) {
            $cursor = new MoySkladSyncCursor(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), $entityType);
            $cursor->completeAt($completedAt);
            $this->em()->persist($cursor);
        }
        for ($index = 0; $index < 6; ++$index) {
            $startedAt = new \DateTimeImmutable(sprintf('2026-09-%02dT09:00:00+00:00', 16 + $index));
            $run = new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'stock', $startedAt);
            $run->recordPage(3, 1, 1, 1);
            $run->succeed($startedAt->modify('+10 minutes'));
            $this->em()->persist($run);
        }
        $storeRun = new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'store', new \DateTimeImmutable('2026-09-22T08:00:00+00:00'));
        $storeRun->recordPage(2, 1, 0, 1);
        $storeRun->succeed(new \DateTimeImmutable('2026-09-22T08:05:00+00:00'));
        $snapshot = new MoySkladStockSnapshot('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId(), new \DateTimeImmutable('2026-09-22T09:00:00+00:00'));
        $snapshot->complete($completedAt);
        $this->em()->persist($storeRun);
        $this->em()->persist($snapshot);
        $this->em()->flush();

        $crawler = $client->request('GET', '/moy-sklad/connections');
        self::assertSelectorTextContains('[data-sync-stream="store"]', 'Успешно');
        self::assertSelectorTextContains('[data-sync-stream="store"]', 'Обработано: 2 · Создано: 1 · Обновлено: 0 · Без изменений: 1');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', 'Успешно');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', 'Последний успешный проход: 22.09.2026 12:10:00');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', 'ID: aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', '0 строк');
        $history = $crawler->filter('[data-sync-history="stock"] li');
        self::assertCount(5, $history);
        self::assertSame(
            ['21.09.2026', '20.09.2026', '19.09.2026', '18.09.2026', '17.09.2026'],
            $history->each(static fn ($node): string => substr(trim($node->text()), 0, 10)),
        );
        self::assertStringNotContainsString('16.09.2026', implode(' ', $history->each(static fn ($node): string => $node->text())));
    }

    public function testStockUiShowsSafeFailureAndKeepsPreviousCompletedSnapshot(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $snapshot = new MoySkladStockSnapshot('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId(), new \DateTimeImmutable('2026-09-21T08:00:00+00:00'));
        $snapshot->complete(new \DateTimeImmutable('2026-09-21T08:10:00+00:00'));
        $failed = new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'stock', new \DateTimeImmutable('2026-09-22T09:00:00+00:00'));
        $failed->fail('invalid_response', new \DateTimeImmutable('2026-09-22T09:01:00+00:00'));
        $this->em()->persist($snapshot);
        $this->em()->persist($failed);
        $this->em()->flush();

        $client->request('GET', '/moy-sklad/connections');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', 'Ошибка');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', 'Некорректный ответ МойСклад');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', 'ID: aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', '21.09.2026 11:10:00');
    }

    public function testInactiveAndReadOnlyUsersSeeStockStatusWithoutSyncForm(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $run = new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'stock', new \DateTimeImmutable('2026-09-22T09:00:00+00:00'));
        $run->fail('temporary', new \DateTimeImmutable('2026-09-22T09:01:00+00:00'));
        $connection->setIsActive(false);
        $this->em()->persist($run);
        $this->em()->flush();

        $crawler = $client->request('GET', '/moy-sklad/connections');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', 'МойСклад временно недоступен');
        self::assertCount(0, $crawler->filter('form[action$="/sync-stock"]'));

        $connection->setIsActive(true);
        $this->em()->flush();
        $this->loginMember($client, $connection->getCompanyId(), ['marketplace' => 'read']);
        $crawler = $client->request('GET', '/moy-sklad/connections');
        self::assertSelectorTextContains('[data-sync-stream="stock"]', 'МойСклад временно недоступен');
        self::assertCount(0, $crawler->filter('form[action$="/sync-stock"]'));
    }

    public function testRunningSyncHidesDuplicateQueueForm(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $this->em()->persist(new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'counterparty', new \DateTimeImmutable('2026-09-20T09:00:00+00:00')));
        $this->em()->flush();

        $crawler = $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Загрузка контрагентов:');
        self::assertCount(0, $crawler->filter('form[action="/moy-sklad/connections/'.$connection->getId().'/sync-counterparties"]'));
    }

    public function testSyncPostRequiresCsrfAndVerifiedActiveConnection(): void
    {
        [$client, $connection] = $this->seed();
        $url = '/moy-sklad/connections/'.$connection->getId().'/sync-counterparties';
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(405);
        $client->request('POST', $url, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url, ['_token' => $this->csrfToken($client, 'moysklad_sync_counterparties'.$connection->getId())]);
        self::assertResponseRedirects('/moy-sklad/connections');
        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_sync');
        self::assertCount(0, $transport->getSent());
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $connection->setIsActive(false);
        $this->em()->flush();
        $client->request('POST', $url, ['_token' => $this->csrfToken($client, 'moysklad_sync_counterparties'.$connection->getId())]);
        self::assertResponseRedirects('/moy-sklad/connections');
        self::assertCount(0, $transport->getSent());
    }

    public function testReadMemberAndForeignCompanyCannotQueueSync(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $foreign = new MoySkladConnection(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), 'Чужой склад', $connection->getBaseUrl());
        $foreign->bindAccount('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        $foreign->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $this->em()->persist($foreign);
        $this->em()->flush();

        $client->request('POST', '/moy-sklad/connections/'.$foreign->getId().'/sync-counterparties');
        self::assertResponseStatusCodeSame(404);
        $this->loginMember($client, $connection->getCompanyId(), ['marketplace' => 'read']);
        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/sync-counterparties"]');
        $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/sync-counterparties', [
            '_token' => $this->csrfToken($client, 'moysklad_sync_counterparties'.$connection->getId()),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testListShowsLastRunCountersAndCompletedCursor(): void
    {
        [$client, $connection] = $this->seed();
        $now = new \DateTimeImmutable('2026-09-20T09:00:00+00:00');
        $cursor = new MoySkladSyncCursor(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'counterparty');
        $cursor->completeAt($now);
        $run = new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'counterparty', $now);
        $run->recordPage(2, 1, 1, 0);
        $run->succeed($now);
        $this->em()->persist($cursor);
        $this->em()->persist($run);
        $this->em()->flush();

        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Последний полный проход');
        self::assertSelectorTextContains('body', 'Обработано: 2');
        self::assertSelectorTextContains('body', 'Создано: 1');
        self::assertSelectorTextContains('body', 'Обновлено: 1');
        self::assertStringNotContainsString('stored-sensitive-token', (string) $client->getResponse()->getContent());
    }

    public function testFailedRunKeepsPreviousCompletedTimeAndHidesForeignStatus(): void
    {
        [$client, $connection] = $this->seed();
        $previous = new \DateTimeImmutable('2026-09-19T09:00:00+00:00');
        $cursor = new MoySkladSyncCursor(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'counterparty');
        $cursor->completeAt($previous);
        $previousRun = new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'counterparty', new \DateTimeImmutable('2026-09-18T09:00:00+00:00'));
        $previousRun->succeed($previous);
        $run = new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'counterparty', new \DateTimeImmutable('2026-09-20T09:00:00+00:00'));
        $run->recordPage(2, 2, 0, 0);
        $run->fail('rate_limited', new \DateTimeImmutable('2026-09-20T09:01:00+00:00'));
        $foreign = new MoySkladConnection(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), 'Чужой склад', $connection->getBaseUrl());
        $foreignRun = new MoySkladSyncRun(Uuid::uuid7()->toString(), $foreign->getCompanyId(), $foreign->getId(), 'counterparty', new \DateTimeImmutable('2026-09-20T09:00:00+00:00'));
        foreach ([$cursor, $previousRun, $run, $foreign, $foreignRun] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $statuses = static::getContainer()->get(MoySkladSyncStatusQuery::class)->forConnections($connection->getCompanyId(), [$connection->getId(), $foreign->getId()]);
        self::assertArrayHasKey($connection->getId(), $statuses);
        self::assertArrayNotHasKey($foreign->getId(), $statuses);
        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Лимит запросов МойСклад');
        self::assertSelectorTextContains('body', '19.09.2026');
        self::assertSelectorTextContains('body', 'История запусков');
        self::assertSelectorTextContains('body', '18.09.2026');
        self::assertSelectorTextNotContains('body', 'Чужой склад');
    }

    public function testCheckThenRenameUsesVersionFromRenderedEditForm(): void
    {
        [$client, $connection] = $this->seed();
        $this->postOperation($client, $connection, 'check');
        self::assertTrue($connection->isVerified());
        $crawler = $client->request('GET', '/moy-sklad/connections/'.$connection->getId().'/edit');
        $client->submit($crawler->selectButton('Сохранить')->form(['moy_sklad_connection[name]' => 'Новое название']));
        self::assertResponseRedirects('/moy-sklad/connections');
        self::assertSame('Новое название', $this->em()->getConnection()->fetchOne('SELECT name FROM moysklad_connections WHERE id = ?', [$connection->getId()]));
    }

    public function testCreateChecksAccountAndEncryptsSubmittedToken(): void
    {
        [$client] = $this->seed();
        $crawler = $client->request('GET', '/moy-sklad/connections/create');
        $client->submit($crawler->selectButton('Проверить и подключить')->form([
            'moy_sklad_connection[name]' => 'Новый склад',
            'moy_sklad_connection[token]' => 'submitted-secret-token',
        ]));
        self::assertResponseRedirects('/moy-sklad/connections');
        $row = $this->em()->getConnection()->fetchAssociative('SELECT account_id, access_token, access_token_encrypted FROM moysklad_connections WHERE name = ?', ['Новый склад']);
        self::assertIsArray($row);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $row['account_id']);
        self::assertNull($row['access_token']);
        self::assertStringNotContainsString('submitted-secret-token', $row['access_token_encrypted']);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Подключено');
        self::assertStringNotContainsString('submitted-secret-token', (string) $client->getResponse()->getContent());
    }

    public function testRejectedTokenKeepsNameAndNeverRepopulatesPassword(): void
    {
        [$client] = $this->seed(401);
        $crawler = $client->request('GET', '/moy-sklad/connections/create');
        $client->submit($crawler->selectButton('Проверить и подключить')->form([
            'moy_sklad_connection[name]' => 'Сохранить название',
            'moy_sklad_connection[token]' => 'rejected-secret-token',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertInputValueSame('moy_sklad_connection[name]', 'Сохранить название');
        self::assertInputValueSame('moy_sklad_connection[token]', '');
        self::assertStringNotContainsString('rejected-secret-token', (string) $client->getResponse()->getContent());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_connections'));
    }

    public function testReadMemberCanListButCannotMutate(): void
    {
        [$client, $connection] = $this->seed();
        $this->loginMember($client, $connection->getCompanyId(), ['marketplace' => 'read']);
        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/moy-sklad/connections/create"]');
        self::assertSelectorNotExists('form[action$="/replace-token"]');
        $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/disable', [
            '_token' => $this->csrfToken($client, 'moysklad_disable'.$connection->getId()),
            'version' => $connection->getVersion(),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testReadMemberCanOpenFormsButCannotSave(): void
    {
        [$client, $connection] = $this->seed();
        $this->loginMember($client, $connection->getCompanyId(), ['marketplace' => 'read']);
        $crawler = $client->request('GET', '/moy-sklad/connections/create');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Проверить и подключить')->form([
            'moy_sklad_connection[name]' => 'Запрещённое создание',
            'moy_sklad_connection[token]' => 'read-member-token',
        ]));
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/moy-sklad/connections/'.$connection->getId().'/edit');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Сохранить')->form(['moy_sklad_connection[name]' => 'Запрещённое изменение']));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_connections'));
        self::assertSame($connection->getName(), $this->em()->getConnection()->fetchOne('SELECT name FROM moysklad_connections WHERE id = ?', [$connection->getId()]));
    }

    public function testMemberWithoutReadCannotList(): void
    {
        [$client, $connection] = $this->seed();
        $this->loginMember($client, $connection->getCompanyId(), []);
        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseStatusCodeSame(403);
    }

    public function testForeignCompanyConnectionIsAbsentAndCannotBeEditedOrDisabled(): void
    {
        [$client, $connection] = $this->seed();
        $foreign = new MoySkladConnection(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), 'Чужой склад', $connection->getBaseUrl());
        $this->em()->persist($foreign);
        $this->em()->flush();
        $client->request('GET', '/moy-sklad/connections');
        self::assertSelectorTextNotContains('body', 'Чужой склад');
        $client->request('GET', '/moy-sklad/connections/'.$foreign->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/moy-sklad/connections/'.$foreign->getId().'/disable');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMutationsRejectGetAndInvalidCsrf(): void
    {
        [$client, $connection] = $this->seed();
        foreach (['check', 'replace-token', 'disable', 'enable', 'delete'] as $operation) {
            $client->request('GET', '/moy-sklad/connections/'.$connection->getId().'/'.$operation);
            self::assertResponseStatusCodeSame(405);
            $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/'.$operation, ['_token' => 'invalid', 'version' => 1]);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testDisableThenEnableChecksAccessBeforeReactivating(): void
    {
        [$client, $connection] = $this->seed();
        $this->postOperation($client, $connection, 'disable');
        self::assertFalse($connection->isActive());
        $this->postOperation($client, $connection, 'enable');
        self::assertTrue($connection->isActive());
        self::assertTrue($connection->isVerified());
    }

    public function testReplacementAndDeleteUseRealPostRoutes(): void
    {
        [$client, $connection] = $this->seed();
        $this->postOperation($client, $connection, 'replace-token', ['token' => 'replacement-secret']);
        self::assertNull($connection->getAccessToken());
        self::assertNotNull($connection->getAccessTokenEncrypted());
        $this->postOperation($client, $connection, 'disable');
        $id = $connection->getId();
        $this->postOperation($client, $connection, 'delete');
        self::assertFalse($this->em()->getConnection()->fetchOne('SELECT id FROM moysklad_connections WHERE id = ?', [$id]));
    }

    public function testPaginationLimitsCardsAndRejectsInvalidPage(): void
    {
        [$client, $connection] = $this->seed();
        for ($index = 1; $index <= 20; ++$index) {
            $this->em()->persist(new MoySkladConnection(Uuid::uuid7()->toString(), $connection->getCompanyId(), 'Склад '.$index, $connection->getBaseUrl()));
        }
        $this->em()->flush();
        $client->request('GET', '/moy-sklad/connections');
        self::assertSelectorCount(20, 'section.card');
        $client->request('GET', '/moy-sklad/connections?page=2');
        self::assertSelectorCount(1, 'section.card');
        $client->request('GET', '/moy-sklad/connections?page=0');
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/moy-sklad/connections?page=3');
        self::assertResponseStatusCodeSame(422);
    }

    /** @param array<string, string> $extra */
    private function postOperation(KernelBrowser $client, MoySkladConnection &$connection, string $operation, array $extra = []): void
    {
        $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/'.$operation, $extra + [
            '_token' => $this->csrfToken($client, 'moysklad_'.$operation.$connection->getId()),
            'version' => $connection->getVersion(),
        ]);
        self::assertResponseRedirects('/moy-sklad/connections');
        if ('delete' !== $operation) {
            $reloaded = $this->em()->find(MoySkladConnection::class, $connection->getId());
            self::assertInstanceOf(MoySkladConnection::class, $reloaded);
            $connection = $reloaded;
        }
    }

    /** @param array<string, string> $permissions */
    private function loginMember(KernelBrowser $client, string $companyId, array $permissions): void
    {
        $company = $this->em()->find(Company::class, $companyId);
        self::assertInstanceOf(Company::class, $company);
        $user = UserBuilder::aUser()->withIndex(2)->withEmail('reader@example.test')->withRoles(['ROLE_COMPANY_USER'])->build();
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Reader', $permissions, $company);
        $member = CompanyMemberBuilder::aMember()->withCompany($company)->withUser($user)->withAccessRole($role)->build();
        foreach ([$user, $role, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
    }

    /** @return array{KernelBrowser, MoySkladConnection} */
    private function seed(int $status = 200): array
    {
        $this->resetDb();
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('moysklad.http_client', new MockHttpClient(new MockResponse('{"accountId":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"}', ['http_code' => $status])));
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $connection = new MoySkladConnection(Uuid::uuid7()->toString(), (string) $company->getId(), 'Склад', 'https://api.moysklad.ru/api/remap/1.2');
        $connection->setAccessToken('stored-sensitive-token')->setRefreshToken('stored-refresh-secret');
        $this->em()->persist($connection);
        $this->em()->flush();
        $limiter = static::getContainer()->get('limiter.moysklad_connection_check');
        $limiter->create($company->getId().':create:'.$user->getId())->reset();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return [$client, $connection];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application;

use App\Company\Entity\Company;
use App\Ingestion\Facade\RawStorageFacade;
use App\Marketplace\Application\RefreshOzonListingCatalogAction;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\OzonCatalogApiException;
use App\Marketplace\Infrastructure\Api\Ozon\OzonProductCatalogClient;
use App\Marketplace\Infrastructure\Normalizer\Ozon\OzonProductCatalogNormalizer;
use App\Marketplace\Infrastructure\Query\MarketplaceCredentialsQuery;
use App\Marketplace\Infrastructure\Query\OzonListingCatalogUpsertQuery;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RefreshOzonListingCatalogActionTest extends IntegrationTestCase
{
    private const PRIMARY_SKU = '308520421';
    private const SECONDARY_SKU = '308520498';
    private const SECOND_PRODUCT_SKU = '2364427751';

    public function testCreatesListingsForProductsThatNeverHadSales(): void
    {
        $company = $this->seedCompany(61);
        $this->seedConnection($company, 61);
        $this->em->flush();

        $result = $this->action()((string) $company->getId(), $this->connectionId(61));

        self::assertSame(2, $result->productsFetched);
        self::assertSame(2, $result->listingsUpserted);
        self::assertSame('Тестовый товар с двумя источниками', $this->listingName($company, self::PRIMARY_SKU));
        self::assertSame('Тестовый товар с одним источником', $this->listingName($company, self::SECOND_PRODUCT_SKU));
    }

    /**
     * Регрессионный тест на дефект задачи.
     *
     * Листинг заведён финансовым документом по FBS-схеме, поэтому его
     * marketplace_sku — ВТОРИЧНЫЙ sku товара (sources[1].sku), которого нет
     * в верхнеуровневом поле карточки. Матчинг только по верхнеуровневому sku
     * этот листинг не нашёл бы, и он навсегда остался бы без имени.
     */
    public function testFillsNameOfListingMatchedOnlyBySecondarySourceSku(): void
    {
        $company = $this->seedCompany(62);
        $this->seedConnection($company, 62);
        $this->seedListing($company, self::SECONDARY_SKU);
        $this->em->flush();

        $this->action()((string) $company->getId(), $this->connectionId(62));

        self::assertSame(
            'Тестовый товар с двумя источниками',
            $this->listingName($company, self::SECONDARY_SKU),
        );
    }

    public function testUpdatesEveryListingOfTheSameProduct(): void
    {
        $company = $this->seedCompany(63);
        $this->seedConnection($company, 63);
        $this->seedListing($company, self::PRIMARY_SKU);
        $this->seedListing($company, self::SECONDARY_SKU);
        $this->em->flush();

        $this->action()((string) $company->getId(), $this->connectionId(63));

        self::assertSame('Тестовый товар с двумя источниками', $this->listingName($company, self::PRIMARY_SKU));
        self::assertSame('Тестовый товар с двумя источниками', $this->listingName($company, self::SECONDARY_SKU));
    }

    public function testStoresRawPagesOfBothEndpoints(): void
    {
        $company = $this->seedCompany(64);
        $this->seedConnection($company, 64);
        $this->em->flush();

        $result = $this->action()((string) $company->getId(), $this->connectionId(64));

        self::assertSame(2, $result->rawRecordsStored);

        $resourceTypes = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT resource_type FROM ingest_raw_records WHERE company_id = :company ORDER BY resource_type',
            ['company' => (string) $company->getId()],
        );

        self::assertSame(['ozon_seller_product_info', 'ozon_seller_product_list'], $resourceTypes);
    }

    /**
     * Решение Владельца: пропажу из каталога разбираем вручную.
     */
    public function testListingMissingFromCatalogKeepsIsActiveAndStaysUnseen(): void
    {
        $company = $this->seedCompany(65);
        $this->seedConnection($company, 65);
        $this->seedListing($company, '111111111');
        $this->em->flush();

        $this->action()((string) $company->getId(), $this->connectionId(65));

        $row = $this->connection->fetchAssociative(
            'SELECT is_active, last_seen_at FROM marketplace_listings WHERE company_id = :company AND marketplace_sku = :sku',
            ['company' => (string) $company->getId(), 'sku' => '111111111'],
        );

        self::assertIsArray($row);
        self::assertTrue((bool) $row['is_active']);
        self::assertNull($row['last_seen_at']);
    }

    public function testSecondRunCreatesNoDuplicates(): void
    {
        $company = $this->seedCompany(66);
        $this->seedConnection($company, 66);
        $this->em->flush();

        $action = $this->action(responses: array_merge($this->catalogResponses(), $this->catalogResponses()));
        $action((string) $company->getId(), $this->connectionId(66));
        $action((string) $company->getId(), $this->connectionId(66));

        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listings WHERE company_id = :company',
            ['company' => (string) $company->getId()],
        ));
    }

    public function testMissingCredentialsAreRejected(): void
    {
        $company = $this->seedCompany(67);
        $this->em->flush();

        $this->expectException(OzonCatalogApiException::class);
        $this->action()((string) $company->getId(), $this->connectionId(67));
    }

    /**
     * Осознанное отступление от плана Stage: глобальной транзакции на прогон
     * нет. Сбой на ВТОРОМ чанке обязан сохранить результат первого — upsert
     * идемпотентен, а транзакция на весь каталог держала бы блокировки на
     * marketplace_listings всё время обхода и мешала бы финансовому pipeline.
     *
     * Тест берёт 1001 товар, чтобы чанков стало два: на одном чанке это
     * поведение недоказуемо.
     */
    public function testFailureOnSecondChunkKeepsResultOfTheFirst(): void
    {
        $company = $this->seedCompany(69);
        $this->seedConnection($company, 69);
        $this->em->flush();

        $action = $this->action(responses: [
            new MockResponse($this->syntheticProductList(1001), ['http_code' => 200]),
            new MockResponse($this->syntheticProductInfo(1, 1000), ['http_code' => 200]),
            new MockResponse('{"message":"boom"}', ['http_code' => 500]),
        ]);

        try {
            $action((string) $company->getId(), $this->connectionId(69));
            self::fail('Expected OzonCatalogApiException.');
        } catch (OzonCatalogApiException) {
            // ожидаемо
        }

        self::assertSame(1000, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listings WHERE company_id = :company AND name IS NOT NULL',
            ['company' => (string) $company->getId()],
        ));
    }

    private function syntheticProductList(int $count): string
    {
        $items = [];
        for ($i = 1; $i <= $count; ++$i) {
            $items[] = ['product_id' => $i, 'offer_id' => 'ART-'.$i, 'sku' => 700000000 + $i];
        }

        return json_encode(
            ['result' => ['items' => $items, 'total' => $count, 'last_id' => '']],
            \JSON_THROW_ON_ERROR,
        );
    }

    private function syntheticProductInfo(int $from, int $to): string
    {
        $items = [];
        for ($i = $from; $i <= $to; ++$i) {
            $items[] = [
                'id' => $i,
                'sku' => 700000000 + $i,
                'offer_id' => 'ART-'.$i,
                'name' => 'Товар '.$i,
                'created_at' => '2025-01-01T00:00:00.000000Z',
                'barcodes' => [],
                'sources' => [['sku' => 700000000 + $i, 'source' => 'fbs']],
            ];
        }

        return json_encode(['items' => $items], \JSON_THROW_ON_ERROR);
    }

    /**
     * Запросили 1000 карточек — получили ноль. Счёт по числу ЭЛЕМЕНТОВ ОТВЕТА
     * этого не ловит: received = 0, usable = 0, и прогон молча отчитывается
     * успехом. Считать надо от числа ЗАПРОШЕННЫХ product_id.
     */
    public function testEmptyProductInfoResponseForNonEmptyChunkIsRejected(): void
    {
        $company = $this->seedCompany(76);
        $this->seedConnection($company, 76);
        $this->em->flush();

        $action = $this->action(responses: [
            new MockResponse($this->fixture('product_list.json'), ['http_code' => 200]),
            new MockResponse('{"items":[]}', ['http_code' => 200]),
        ]);

        $this->expectException(OzonCatalogApiException::class);
        $action((string) $company->getId(), $this->connectionId(76));
    }

    /**
     * Ozon сообщает размер каталога в `result.total`. Пустая первая страница
     * при total = 2 — не пустой каталог, а оборванный обход: прогон, который
     * отчитается успехом, оставит два товара невидимыми до следующей ночи.
     */
    public function testEmptyFirstPageWithPositiveTotalIsRejected(): void
    {
        $company = $this->seedCompany(87);
        $this->seedConnection($company, 87);
        $this->em->flush();

        $action = $this->action(responses: [
            new MockResponse('{"result":{"items":[],"total":2,"last_id":""}}', ['http_code' => 200]),
        ]);

        $this->expectException(OzonCatalogApiException::class);
        $action((string) $company->getId(), $this->connectionId(87));
    }

    /**
     * Обход собрал меньше товаров, чем заявил каталог. Ронять прогон незачем —
     * собранное полезно, — но расхождение обязано быть видно.
     */
    public function testCollectingFewerProductsThanReportedTotalIsWarned(): void
    {
        $company = $this->seedCompany(88);
        $this->seedConnection($company, 88);
        $this->em->flush();

        $page = json_decode($this->fixture('product_list.json'), true, 512, \JSON_THROW_ON_ERROR);
        $page['result']['total'] = 5;

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                self::stringContains('OzonListingCatalog'),
                self::callback(static fn (array $context): bool => 5 === ($context['reported_total'] ?? null)
                    && 2 === ($context['collected'] ?? null)),
            );

        $action = $this->actionWithLogger($logger, [
            new MockResponse(json_encode($page, \JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse($this->fixture('product_info_list.json'), ['http_code' => 200]),
        ]);

        $result = $action((string) $company->getId(), $this->connectionId(88));

        self::assertSame(2, $result->productsFetched);
    }

    /**
     * Два дубля одной карточки в ответе на запрос двух товаров не должны
     * читаться как «пришло всё». Иначе дубликат прячет пропавший товар и
     * прогон отчитывается ложным успехом.
     */
    public function testDuplicateCardsDoNotHideAMissingProduct(): void
    {
        $company = $this->seedCompany(79);
        $this->seedConnection($company, 79);
        $this->em->flush();

        $full = json_decode($this->fixture('product_info_list.json'), true, 512, \JSON_THROW_ON_ERROR);
        $duplicated = ['items' => [$full['items'][0], $full['items'][0]]];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                self::stringContains('OzonListingCatalog'),
                self::callback(static fn (array $context): bool => 2 === ($context['requested'] ?? null)
                    && 1 === ($context['usable'] ?? null)),
            );

        $action = $this->actionWithLogger($logger, [
            new MockResponse($this->fixture('product_list.json'), ['http_code' => 200]),
            new MockResponse(json_encode($duplicated, \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $result = $action((string) $company->getId(), $this->connectionId(79));

        self::assertSame(1, $result->productsFetched);
    }

    /**
     * `items` из одних скаляров непустой, но пригодных строк в нём нет.
     * Диагностировать это обязано доменное исключение каталога, а не
     * RawStorageException чужого модуля.
     */
    public function testScalarOnlyItemsRaiseCatalogErrorNotStorageError(): void
    {
        $company = $this->seedCompany(80);
        $this->seedConnection($company, 80);
        $this->em->flush();

        $action = $this->action(responses: [
            new MockResponse($this->fixture('product_list.json'), ['http_code' => 200]),
            new MockResponse('{"items":[null,"scalar"]}', ['http_code' => 200]),
        ]);

        $this->expectException(OzonCatalogApiException::class);
        $action((string) $company->getId(), $this->connectionId(80));
    }

    /**
     * Каталог, кратный размеру страницы, отдаёт пустую последнюю страницу.
     * Складывать её в raw нельзя — RawStorageFacade отвергает пустой батч, —
     * но и падать прогон не должен: это штатное завершение обхода.
     */
    public function testEmptyTrailingPageDoesNotBreakTheWalk(): void
    {
        $company = $this->seedCompany(78);
        $this->seedConnection($company, 78);
        $this->em->flush();

        $firstPage = json_decode($this->fixture('product_list.json'), true, 512, \JSON_THROW_ON_ERROR);
        $firstPage['result']['last_id'] = 'cursor-1';

        $action = $this->action(responses: [
            new MockResponse(json_encode($firstPage, \JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse('{"result":{"items":[],"total":2,"last_id":""}}', ['http_code' => 200]),
            new MockResponse($this->fixture('product_info_list.json'), ['http_code' => 200]),
        ]);

        $result = $action((string) $company->getId(), $this->connectionId(78));

        self::assertSame(2, $result->productsFetched);
        // Пустая страница в raw не попадает: одна страница + один чанк.
        self::assertSame(2, $result->rawRecordsStored);
    }

    /**
     * Часть запрошенных карточек не вернулась. Это не повод ронять прогон, но
     * и не повод молчать: пропущенные товары остаются без имени до следующей
     * ночи, и это должно быть видно.
     */
    public function testMissingCardsAreReportedAsSkippedWithoutFailing(): void
    {
        $company = $this->seedCompany(77);
        $this->seedConnection($company, 77);
        $this->em->flush();

        $full = json_decode($this->fixture('product_info_list.json'), true, 512, \JSON_THROW_ON_ERROR);
        $half = ['items' => [$full['items'][0]]];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                self::stringContains('OzonListingCatalog'),
                self::callback(static fn (array $context): bool => 2 === ($context['requested'] ?? null)
                    && 1 === ($context['returned'] ?? null)
                    && 1 === ($context['usable'] ?? null)),
            );

        $action = $this->actionWithLogger($logger, [
            new MockResponse($this->fixture('product_list.json'), ['http_code' => 200]),
            new MockResponse(json_encode($half, \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $result = $action((string) $company->getId(), $this->connectionId(77));

        self::assertSame(1, $result->productsFetched);
    }

    /**
     * Сбой на карточках не гасит листинги: каталог не управляет is_active
     * ни в одну сторону.
     */
    public function testApiFailureOnProductInfoPropagatesWithoutTouchingIsActive(): void
    {
        $company = $this->seedCompany(68);
        $this->seedConnection($company, 68);
        $this->seedListing($company, self::PRIMARY_SKU);
        $this->em->flush();

        $action = $this->action(responses: [
            $this->productListResponse(),
            new MockResponse('{"message":"boom"}', ['http_code' => 500]),
        ]);

        try {
            $action((string) $company->getId(), $this->connectionId(68));
            self::fail('Expected OzonCatalogApiException.');
        } catch (OzonCatalogApiException) {
            // ожидаемо
        }

        self::assertTrue((bool) $this->connection->fetchOne(
            'SELECT is_active FROM marketplace_listings WHERE company_id = :company AND marketplace_sku = :sku',
            ['company' => (string) $company->getId(), 'sku' => self::PRIMARY_SKU],
        ));
    }

    /**
     * Ozon вернул карточки, но ни одна не пригодна — ни одного SKU. Это
     * нарушение контракта, а не пустой каталог: прогон, отчитавшийся успехом
     * с нулём товаров, оставил бы каталог невидимым без единого сигнала.
     */
    public function testProductInfoWithItemsButNoneUsableIsRejected(): void
    {
        $company = $this->seedCompany(73);
        $this->seedConnection($company, 73);
        $this->em->flush();

        $action = $this->action(responses: [
            new MockResponse($this->fixture('product_list.json'), ['http_code' => 200]),
            new MockResponse('{"items":[{"offer_id":"A"},{"offer_id":"B"}]}', ['http_code' => 200]),
        ]);

        $this->expectException(OzonCatalogApiException::class);
        $action((string) $company->getId(), $this->connectionId(73));
    }

    public function testProductListPageWithItemsButNoProductIdsIsRejected(): void
    {
        $company = $this->seedCompany(74);
        $this->seedConnection($company, 74);
        $this->em->flush();

        $action = $this->action(responses: [
            new MockResponse(
                '{"result":{"items":[{"offer_id":"A"}],"total":1,"last_id":""}}',
                ['http_code' => 200],
            ),
        ]);

        $this->expectException(OzonCatalogApiException::class);
        $action((string) $company->getId(), $this->connectionId(74));
    }

    /**
     * Частично кривой ответ не роняет прогон: один мусорный товар в каталоге
     * на десять тысяч не должен отменять ночную выгрузку. Но и молчать нельзя —
     * пропуск обязан быть виден в логе.
     */
    public function testPartiallyUnusableItemsAreSkippedWithWarningNotFailure(): void
    {
        $company = $this->seedCompany(75);
        $this->seedConnection($company, 75);
        $this->em->flush();

        $good = json_decode($this->fixture('product_info_list.json'), true, 512, \JSON_THROW_ON_ERROR);
        $good['items'][] = ['offer_id' => 'BROKEN'];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                self::stringContains('OzonListingCatalog'),
                self::callback(static fn (array $context): bool => 1 === ($context['skipped'] ?? null)),
            );

        $action = $this->actionWithLogger($logger, [
            new MockResponse($this->fixture('product_list.json'), ['http_code' => 200]),
            new MockResponse(json_encode($good, \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $result = $action((string) $company->getId(), $this->connectionId(75));

        self::assertSame(2, $result->productsFetched);
    }

    /**
     * Сервис `logger` контейнер уже инициализировал, подменить его нельзя,
     * поэтому Action собирается вручную. Остальные зависимости — настоящие,
     * из контейнера: проверяется поведение, а не проводка моков.
     *
     * @param list<MockResponse> $responses
     */
    private function actionWithLogger(LoggerInterface $logger, array $responses): RefreshOzonListingCatalogAction
    {
        self::getContainer()->set('http_client', new MockHttpClient($responses));
        $container = self::getContainer();

        return new RefreshOzonListingCatalogAction(
            $container->get(EntityManagerInterface::class),
            $container->get(MarketplaceCredentialsQuery::class),
            $container->get(OzonProductCatalogClient::class),
            $container->get(OzonProductCatalogNormalizer::class),
            $container->get(MarketplaceListingRepository::class),
            new OzonListingCatalogUpsertQuery($this->connection),
            $container->get(RawStorageFacade::class),
            $logger,
        );
    }

    /**
     * Обход крупного каталога может идти дольше TTL блокировки. Без продления
     * lease протух бы на живом прогоне, и второе сообщение начало бы
     * параллельный обход — взаимное исключение перестало бы работать.
     * Action обязан звать прогресс-колбэк на границах страниц и чанков.
     */
    public function testProgressCallbackIsCalledOnPageAndChunkBoundaries(): void
    {
        $company = $this->seedCompany(89);
        $this->seedConnection($company, 89);
        $this->em->flush();

        $calls = 0;
        $action = $this->action();
        $action(
            (string) $company->getId(),
            $this->connectionId(89),
            static function () use (&$calls): void {
                ++$calls;
            },
        );

        // Одна страница списка + один чанк карточек.
        self::assertSame(2, $calls);
    }

    /**
     * @param list<MockResponse>|null $responses
     */
    private function action(?array $responses = null): RefreshOzonListingCatalogAction
    {
        self::getContainer()->set('http_client', new MockHttpClient($responses ?? $this->catalogResponses()));

        return self::getContainer()->get(RefreshOzonListingCatalogAction::class);
    }

    /**
     * @return list<MockResponse>
     */
    private function catalogResponses(): array
    {
        return [$this->productListResponse(), $this->productInfoResponse()];
    }

    private function productListResponse(): MockResponse
    {
        return new MockResponse($this->fixture('product_list.json'), ['http_code' => 200]);
    }

    private function productInfoResponse(): MockResponse
    {
        return new MockResponse($this->fixture('product_info_list.json'), ['http_code' => 200]);
    }

    private function fixture(string $file): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/Fixtures/Marketplace/Ozon/'.$file);
    }

    private function listingName(Company $company, string $sku): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT name FROM marketplace_listings WHERE company_id = :company AND marketplace_sku = :sku',
            ['company' => (string) $company->getId(), 'sku' => $sku],
        );

        return false === $value ? null : (string) $value;
    }

    private function connectionId(int $index): string
    {
        return sprintf('66666666-6666-4666-8666-%012d', $index);
    }

    private function seedConnection(Company $company, int $index): void
    {
        $connection = new MarketplaceConnection(
            $this->connectionId($index),
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key')->setClientId('test-client')->setIsActive(true);
        $this->em->persist($connection);
    }

    private function seedListing(Company $company, string $sku): void
    {
        $this->em->persist(
            MarketplaceListingBuilder::aListing()
                ->forCompany($company)
                ->withMarketplace(MarketplaceType::OZON)
                ->withMarketplaceSku($sku)
                ->build(),
        );
    }

    private function seedCompany(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()
            ->withIndex($index)
            ->withOwner($owner)
            ->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }
}

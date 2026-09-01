<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\JobType;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\OzonCatalogApiException;
use App\Marketplace\Exception\OzonCatalogRateLimitException;
use App\Marketplace\Message\SyncOzonListingCatalogMessage;
use App\Marketplace\MessageHandler\SyncOzonListingCatalogHandler;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;

final class SyncOzonListingCatalogHandlerTest extends IntegrationTestCase
{
    private const CONNECTION_ID = '66666666-6666-4666-8666-000000000071';
    private const SECONDARY_SKU = '308520498';

    public function testHandlingMessageFillsListingNameFromCatalog(): void
    {
        $company = $this->seed();

        $this->handler()(new SyncOzonListingCatalogMessage(
            (string) $company->getId(),
            self::CONNECTION_ID,
        ));

        self::assertSame(
            'Тестовый товар с двумя источниками',
            (string) $this->connection->fetchOne(
                'SELECT name FROM marketplace_listings WHERE company_id = :company AND marketplace_sku = :sku',
                ['company' => (string) $company->getId(), 'sku' => self::SECONDARY_SKU],
            ),
        );
    }

    /**
     * 429 не заворачиваем в RecoverableMessageHandlingException: Symfony
     * считает RecoverableExceptionInterface retryable БЕЗУСЛОВНО, в обход
     * max_retries. Постоянный 429 крутил бы сообщение бесконечно, занимая
     * воркер, и никогда не попал бы в failed-очередь, где его видно.
     * Обычное исключение оставляет в силе retry_strategy: 3 попытки, потом
     * failed.
     */
    public function testRateLimitStaysBoundedByTheTransportRetryStrategy(): void
    {
        $company = $this->seed();

        self::getContainer()->set('http_client', new MockHttpClient(
            new MockResponse('{"message":"too many"}', ['http_code' => 429]),
        ));
        $handler = self::getContainer()->get(SyncOzonListingCatalogHandler::class);

        $this->expectException(OzonCatalogRateLimitException::class);
        $handler(new SyncOzonListingCatalogMessage((string) $company->getId(), self::CONNECTION_ID));
    }

    /**
     * Ручной запуск из UI требует обратной связи: пользователь должен видеть,
     * что прогон был, чем кончился и сколько тронул. Без журнала кнопка
     * работает вслепую.
     */
    public function testSuccessfulRunIsRecordedInJobLogWithCounts(): void
    {
        $company = $this->seed();

        $this->handler()(new SyncOzonListingCatalogMessage(
            (string) $company->getId(),
            self::CONNECTION_ID,
        ));

        $row = $this->connection->fetchAssociative(
            'SELECT job_type, status, summary FROM marketplace_job_logs WHERE company_id = :company',
            ['company' => (string) $company->getId()],
        );

        self::assertIsArray($row);
        self::assertSame(JobType::LISTING_CATALOG_SYNC_OZON->value, $row['job_type']);
        self::assertSame('done', $row['status']);

        $summary = json_decode((string) $row['summary'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(2, $summary['products_fetched']);
    }

    public function testFailedRunIsRecordedAsFailed(): void
    {
        $company = $this->seed();

        self::getContainer()->set('http_client', new MockHttpClient(
            new MockResponse('{"message":"boom"}', ['http_code' => 500]),
        ));
        $handler = self::getContainer()->get(SyncOzonListingCatalogHandler::class);

        // self::fail() внутри try перехватывался бы собственным catch, и тест
        // проходил бы, даже перестань обработчик пробрасывать исключение.
        $caught = null;
        try {
            $handler(new SyncOzonListingCatalogMessage((string) $company->getId(), self::CONNECTION_ID));
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(OzonCatalogApiException::class, $caught);

        $row = $this->connection->fetchAssociative(
            'SELECT status, summary FROM marketplace_job_logs WHERE company_id = :company',
            ['company' => (string) $company->getId()],
        );

        self::assertIsArray($row);
        self::assertSame('failed', $row['status']);

        // Формат один для всех ошибок: класс исключения, не его текст.
        $summary = json_decode((string) $row['summary'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(OzonCatalogApiException::class, $summary['error']);
    }

    /**
     * Два триггера — ночной cron и кнопка в UI — могут запустить обход
     * одновременно. Второй прогон обязан отступить, а не дублировать десятки
     * HTTP-запросов и перезаписывать снимок.
     */
    public function testConcurrentRunIsSkippedWhileLockIsHeld(): void
    {
        $company = $this->seed();

        /** @var LockFactory $lockFactory */
        $lockFactory = self::getContainer()->get(LockFactory::class);
        $lock = $lockFactory->createLock(
            sprintf('marketplace_ozon_listing_catalog_%s_%s', (string) $company->getId(), self::CONNECTION_ID),
            3600,
        );
        self::assertTrue($lock->acquire());

        // Ни одного HTTP-ответа: если обход всё-таки пойдёт, тест упадёт.
        self::getContainer()->set('http_client', new MockHttpClient([]));
        $handler = self::getContainer()->get(SyncOzonListingCatalogHandler::class);

        try {
            $handler(new SyncOzonListingCatalogMessage((string) $company->getId(), self::CONNECTION_ID));

            self::assertNull($this->connection->fetchOne(
                'SELECT name FROM marketplace_listings WHERE company_id = :company AND marketplace_sku = :sku',
                ['company' => (string) $company->getId(), 'sku' => self::SECONDARY_SKU],
            ) ?: null);
        } finally {
            // Иначе раннее падение оставило бы ключ в Redis на час и сделало
            // бы повторные прогоны сюиты нестабильными.
            $lock->release();
        }
    }

    /**
     * Сбой внутри чанковой транзакции закрывает EntityManager
     * (`EntityManager::wrapInTransaction()` вызывает `close()` в `finally`).
     * Если терминальный статус журнала писать тем же ORM-репозиторием, запись
     * останется RUNNING навсегда, а исходное исключение подменится
     * EntityManagerClosed — диагноз потеряется.
     */
    public function testFailureWithClosedEntityManagerStillRecordsFailedJobLog(): void
    {
        $company = $this->seed();

        $call = 0;
        $em = $this->em;
        self::getContainer()->set('http_client', new MockHttpClient(
            function () use (&$call, $em): MockResponse {
                ++$call;
                if ($call >= 2) {
                    $em->close();
                }

                return new MockResponse(
                    $this->fixture(1 === $call ? 'product_list.json' : 'product_info_list.json'),
                    ['http_code' => 200],
                );
            },
        ));
        $handler = self::getContainer()->get(SyncOzonListingCatalogHandler::class);

        $caught = null;
        try {
            $handler(new SyncOzonListingCatalogMessage((string) $company->getId(), self::CONNECTION_ID));
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertNotNull($caught, 'Сбой обязан быть проброшен, иначе сообщение не уйдёт в retry.');
        self::assertSame('failed', (string) $this->connection->fetchOne(
            'SELECT status FROM marketplace_job_logs WHERE company_id = :company',
            ['company' => (string) $company->getId()],
        ));
    }

    /**
     * Блокировка обязана освобождаться и при успехе, и при сбое: иначе
     * подключение остаётся заперто до истечения TTL в час.
     */
    public function testLockIsReleasedAfterSuccessfulRun(): void
    {
        $company = $this->seed();

        $this->handler()(new SyncOzonListingCatalogMessage(
            (string) $company->getId(),
            self::CONNECTION_ID,
        ));

        /** @var LockFactory $lockFactory */
        $lockFactory = self::getContainer()->get(LockFactory::class);
        $lock = $lockFactory->createLock(
            sprintf('marketplace_ozon_listing_catalog_%s_%s', (string) $company->getId(), self::CONNECTION_ID),
            60,
        );

        try {
            self::assertTrue($lock->acquire(), 'Блокировка не освобождена после успешного прогона.');
        } finally {
            $lock->release();
        }
    }

    public function testMessageCarriesOnlyScalars(): void
    {
        $message = new SyncOzonListingCatalogMessage(
            '11111111-1111-4111-8111-111111111111',
            self::CONNECTION_ID,
        );

        self::assertSame('11111111-1111-4111-8111-111111111111', $message->companyId);
        self::assertSame(self::CONNECTION_ID, $message->connectionId);
    }

    private function handler(): SyncOzonListingCatalogHandler
    {
        self::getContainer()->set('http_client', new MockHttpClient([
            new MockResponse($this->fixture('product_list.json'), ['http_code' => 200]),
            new MockResponse($this->fixture('product_info_list.json'), ['http_code' => 200]),
        ]));

        return self::getContainer()->get(SyncOzonListingCatalogHandler::class);
    }

    private function fixture(string $file): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/Fixtures/Marketplace/Ozon/'.$file);
    }

    private function seed(): Company
    {
        $owner = UserBuilder::aUser()->withIndex(71)->build();
        $company = CompanyBuilder::aCompany()
            ->withIndex(71)
            ->withOwner($owner)
            ->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        $connection = new MarketplaceConnection(
            self::CONNECTION_ID,
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key')->setClientId('test-client')->setIsActive(true);
        $this->em->persist($connection);

        $this->em->persist(
            MarketplaceListingBuilder::aListing()
                ->forCompany($company)
                ->withMarketplace(MarketplaceType::OZON)
                ->withMarketplaceSku(self::SECONDARY_SKU)
                ->build(),
        );
        $this->em->flush();

        return $company;
    }
}

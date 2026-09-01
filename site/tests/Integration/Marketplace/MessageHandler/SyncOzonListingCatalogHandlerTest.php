<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\OzonCatalogRateLimitException;
use App\Marketplace\Message\SyncOzonListingCatalogMessage;
use App\Marketplace\MessageHandler\SyncOzonListingCatalogHandler;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\RefreshWbListingCatalogAction;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Wildberries\WbProductCardsClient;
use App\Marketplace\Infrastructure\Query\WbBarcodeUpsertQuery;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RefreshWbListingCatalogActionTest extends TestCase
{
    public function testRefreshCreatesVariantAndUpsertsAllBarcodes(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('77777777-7777-4777-8777-777777777777', $company, MarketplaceType::WILDBERRIES);
        $connection->setApiKey('token');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->with(Company::class, $company->getId())->willReturn($company);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback) => $callback($em));
        $em->expects(self::once())->method('flush');
        $created = null;
        $em->expects(self::once())->method('persist')->willReturnCallback(static function (MarketplaceListing $listing) use (&$created): void {
            $created = $listing;
        });

        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository->method('findByIdAndCompany')->willReturn($connection);
        $listingRepository = $this->createMock(MarketplaceListingRepository::class);
        $listingRepository->expects(self::once())->method('findAllByCompanyMarketplaceAndMarketplaceVariantIds')->willReturn([]);
        $listingRepository->expects(self::once())->method('findListingsByNmIdsIndexed')->willReturn([]);
        $listingRepository->expects(self::never())->method('findByNmIdAndSize');
        $listingRepository->expects(self::never())->method('findByMarketplaceVariantId');

        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))->method('executeStatement')->with(
            self::stringContains('DO UPDATE SET listing_id = EXCLUDED.listing_id'),
            self::callback(static fn (array $params): bool => in_array($params['barcode'], ['460001', '460002'], true)),
        );
        $barcodeQuery = new WbBarcodeUpsertQuery($db);
        $resolver = new WbListingResolverService(
            $listingRepository,
            $this->createMock(MarketplaceListingBarcodeRepository::class),
            $barcodeQuery,
            $em,
        );

        $action = new RefreshWbListingCatalogAction(
            $em,
            $connectionRepository,
            $this->client([[
                'nmID' => 123,
                'vendorCode' => 'supplier-123',
                'brand' => 'Brand',
                'subjectName' => 'Shoes',
                'title' => 'Running shoes',
                'sizes' => [[
                    'chrtID' => 9001,
                    'techSize' => '42',
                    'skus' => ['460001', '460002', '460001'],
                ]],
            ]]),
            $resolver,
            $listingRepository,
            $barcodeQuery,
            new NullLogger(),
        );

        self::assertSame(1, $action($company->getId(), $connection->getId()));
        self::assertInstanceOf(MarketplaceListing::class, $created);
        self::assertSame('123', $created->getMarketplaceSku());
        self::assertSame('9001', $created->getMarketplaceVariantId());
        self::assertSame('42', $created->getSize());
        self::assertSame('supplier-123', $created->getSupplierSku());
        self::assertSame('Running shoes', $created->getName());
    }

    /** @param list<array<string, mixed>> $cards */
    #[DataProvider('invalidCatalogs')]
    public function testRefreshRejectsAmbiguousCatalogBeforeTransaction(array $cards): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('77777777-7777-4777-8777-777777777777', $company, MarketplaceType::WILDBERRIES);
        $connection->setApiKey('token');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($company);
        $em->expects(self::never())->method('wrapInTransaction');
        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository->method('findByIdAndCompany')->willReturn($connection);

        $action = new RefreshWbListingCatalogAction(
            $em,
            $connectionRepository,
            $this->client($cards),
            new WbListingResolverService(
                $listingRepository = $this->createMock(MarketplaceListingRepository::class),
                $this->createMock(MarketplaceListingBarcodeRepository::class),
                new WbBarcodeUpsertQuery($this->createMock(Connection::class)),
                $em,
            ),
            $listingRepository,
            new WbBarcodeUpsertQuery($this->createMock(Connection::class)),
            new NullLogger(),
        );

        $this->expectException(\DomainException::class);

        $action($company->getId(), $connection->getId());
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function invalidCatalogs(): iterable
    {
        yield 'same natural key has two variants' => [[[
            'nmID' => 123,
            'sizes' => [
                ['chrtID' => 9001, 'techSize' => '42', 'skus' => []],
                ['chrtID' => 9002, 'techSize' => '42', 'skus' => []],
            ],
        ]]];

        yield 'same barcode has two variants' => [[[
            'nmID' => 123,
            'sizes' => [
                ['chrtID' => 9001, 'techSize' => '42', 'skus' => ['460001']],
                ['chrtID' => 9002, 'techSize' => '44', 'skus' => ['460001']],
            ],
        ]]];
    }

    /** @param list<array<string, mixed>> $cards */
    private function client(array $cards): WbProductCardsClient
    {
        return new WbProductCardsClient(
            new MockHttpClient(new MockResponse(json_encode(['cards' => $cards, 'cursor' => ['total' => count($cards)]], JSON_THROW_ON_ERROR))),
            new RateLimiterFactory(['id' => 'test', 'policy' => 'no_limit'], new InMemoryStorage()),
        );
    }
}

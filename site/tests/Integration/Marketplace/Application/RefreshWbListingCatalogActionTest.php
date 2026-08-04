<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application;

use App\Marketplace\Application\RefreshWbListingCatalogAction;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceListingBarcode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Wildberries\WbProductCardsClient;
use App\Marketplace\Infrastructure\Query\WbBarcodeUpsertQuery;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RefreshWbListingCatalogActionTest extends IntegrationTestCase
{
    public function testRefreshReassignsExistingBarcodeToResolvedVariant(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES);
        $connection->setApiKey('token');
        $source = $this->listing($company, '111', '40', '8001');
        $target = $this->listing($company, '123', '42', '9001');
        $barcode = new MarketplaceListingBarcode(
            Uuid::uuid4()->toString(),
            $source,
            $company->getId(),
            MarketplaceType::WILDBERRIES->value,
            '460001',
        );

        $this->em->persist($company->getUser());
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->persist($source);
        $this->em->persist($target);
        $this->em->persist($barcode);
        $this->em->flush();

        $listingRepository = $this->em->getRepository(MarketplaceListing::class);
        $connectionRepository = $this->em->getRepository(MarketplaceConnection::class);
        $barcodeRepository = $this->em->getRepository(MarketplaceListingBarcode::class);
        self::assertInstanceOf(MarketplaceListingRepository::class, $listingRepository);
        self::assertInstanceOf(MarketplaceConnectionRepository::class, $connectionRepository);
        self::assertInstanceOf(MarketplaceListingBarcodeRepository::class, $barcodeRepository);

        $barcodeQuery = new WbBarcodeUpsertQuery($this->connection);
        $action = new RefreshWbListingCatalogAction(
            $this->em,
            $connectionRepository,
            $this->client(),
            new WbListingResolverService($listingRepository, $barcodeRepository, $barcodeQuery, $this->em),
            $listingRepository,
            $barcodeQuery,
            new NullLogger(),
            self::getContainer()->get(\App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec::class),
        );

        self::assertSame(1, $action($company->getId(), $connection->getId()));

        $this->em->clear();
        self::assertSame(
            $target->getId(),
            $barcodeRepository->findListingIdByBarcode($company->getId(), '460001', MarketplaceType::WILDBERRIES),
        );
    }

    public function testRefreshMarksTrashVariantInactive(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES);
        $connection->setApiKey('token');
        $listing = $this->listing($company, '321', '44', '9101');

        $this->em->persist($company->getUser());
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->persist($listing);
        $this->em->flush();

        $listingRepository = $this->em->getRepository(MarketplaceListing::class);
        $connectionRepository = $this->em->getRepository(MarketplaceConnection::class);
        $barcodeRepository = $this->em->getRepository(MarketplaceListingBarcode::class);
        self::assertInstanceOf(MarketplaceListingRepository::class, $listingRepository);
        self::assertInstanceOf(MarketplaceConnectionRepository::class, $connectionRepository);
        self::assertInstanceOf(MarketplaceListingBarcodeRepository::class, $barcodeRepository);

        $barcodeQuery = new WbBarcodeUpsertQuery($this->connection);
        $action = new RefreshWbListingCatalogAction(
            $this->em,
            $connectionRepository,
            $this->client([], [[
                'nmID' => 321,
                'sizes' => [[
                    'chrtID' => 9101,
                    'techSize' => '44',
                    'skus' => ['460003'],
                ]],
            ]]),
            new WbListingResolverService($listingRepository, $barcodeRepository, $barcodeQuery, $this->em),
            $listingRepository,
            $barcodeQuery,
            new NullLogger(),
            self::getContainer()->get(\App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec::class),
        );

        self::assertSame(1, $action($company->getId(), $connection->getId()));

        $this->em->clear();
        $refreshed = $listingRepository->find($listing->getId());
        self::assertInstanceOf(MarketplaceListing::class, $refreshed);
        self::assertFalse($refreshed->isActive());
        self::assertSame('9101', $refreshed->getMarketplaceVariantId());
        self::assertSame(
            $listing->getId(),
            $barcodeRepository->findListingIdByBarcode($company->getId(), '460003', MarketplaceType::WILDBERRIES),
        );
    }

    private function listing(
        \App\Company\Entity\Company $company,
        string $nmId,
        string $size,
        string $marketplaceVariantId,
    ): MarketplaceListing {
        $listing = new MarketplaceListing(Uuid::uuid4()->toString(), $company, null, MarketplaceType::WILDBERRIES);
        $listing->setMarketplaceSku($nmId);
        $listing->setMarketplaceVariantId($marketplaceVariantId);
        $listing->setSize($size);
        $listing->setPrice('0');

        return $listing;
    }

    /**
     * @param list<array<string, mixed>>|null $activeCards
     * @param list<array<string, mixed>>      $trashCards
     */
    private function client(?array $activeCards = null, array $trashCards = []): WbProductCardsClient
    {
        $activeCards ??= [[
            'nmID' => 123,
            'sizes' => [[
                'chrtID' => 9001,
                'techSize' => '42',
                'skus' => ['460001'],
            ]],
        ]];

        return new WbProductCardsClient(
            new MockHttpClient([
                $this->catalogResponse($activeCards),
                $this->catalogResponse($trashCards),
            ]),
            new RateLimiterFactory(['id' => 'test', 'policy' => 'no_limit'], new InMemoryStorage()),
        );
    }

    /** @param list<array<string, mixed>> $cards */
    private function catalogResponse(array $cards): MockResponse
    {
        return new MockResponse(json_encode([
            'cards' => $cards,
            'cursor' => ['total' => count($cards)],
        ], \JSON_THROW_ON_ERROR));
    }
}

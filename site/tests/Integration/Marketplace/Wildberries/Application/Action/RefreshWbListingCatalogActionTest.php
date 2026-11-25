<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Wildberries\Application\Action;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceListingBarcode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\ListingCategoryRuleQuery;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Wildberries\Application\Action\RefreshWbListingCatalogAction;
use App\Marketplace\Wildberries\Application\Service\WbListingResolverService;
use App\Marketplace\Wildberries\Infrastructure\Api\WbProductCardsClient;
use App\Marketplace\Wildberries\Infrastructure\Query\WbBarcodeUpsertQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingCategoryBuilder;
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
            new ListingCategoryRuleQuery($this->connection),
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
            new ListingCategoryRuleQuery($this->connection),
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

    public function testRefreshStoresSubjectAndAppliesCategoryRule(): void
    {
        $owner = UserBuilder::aUser()->withIndex(951)->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $companyId = (string) $company->getId();
        $connection = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES);
        $connection->setApiKey('token');
        $listing = $this->listing($company, '555', '46', '9201');
        $category = MarketplaceListingCategoryBuilder::aCategory()->forCompanyId($companyId)->withName('Платья')->build();
        foreach ([$owner, $company, $connection, $listing, $category] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $rules = new ListingCategoryRuleQuery($this->connection);
        $rules->upsert($companyId, 'wb:69', $category->getId());

        $container = self::getContainer();
        $listingRepository = $container->get(MarketplaceListingRepository::class);
        $barcodeRepository = $container->get(MarketplaceListingBarcodeRepository::class);
        $barcodeQuery = new WbBarcodeUpsertQuery($this->connection);
        $action = new RefreshWbListingCatalogAction(
            $this->em,
            $container->get(MarketplaceConnectionRepository::class),
            $this->client([[
                'nmID' => 555,
                'subjectID' => 69,
                'subjectName' => 'Платья',
                'sizes' => [['chrtID' => 9201, 'techSize' => '46', 'skus' => ['460055']]],
            ]]),
            new WbListingResolverService($listingRepository, $barcodeRepository, $barcodeQuery, $this->em),
            $listingRepository,
            $barcodeQuery,
            new NullLogger(),
            $container->get(\App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec::class),
            $rules,
        );

        self::assertSame(1, $action($companyId, $connection->getId()));

        $row = $this->connection->fetchAssociative(
            'SELECT marketplace_data, category_id, category_source FROM marketplace_listings WHERE id = ?',
            [$listing->getId()],
        );
        self::assertIsArray($row);
        self::assertSame(
            ['subject_id' => 69, 'subject_name' => 'Платья', 'category_key' => 'wb:69'],
            json_decode((string) $row['marketplace_data'], true, 512, \JSON_THROW_ON_ERROR),
        );
        self::assertSame([$category->getId(), 'rule'], [$row['category_id'], $row['category_source']]);
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
     * @param list<array<string, mixed>> $trashCards
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

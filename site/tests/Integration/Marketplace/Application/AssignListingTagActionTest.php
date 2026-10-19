<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application;

use App\Marketplace\Application\AssignListingTagAction;
use App\Marketplace\Application\DTO\ListingTagPayload;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\ListingFilterTooBroadException;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Marketplace\Infrastructure\Query\MarketplaceListingsQuery;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;

final class AssignListingTagActionTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000914';

    public function testTooBroadFilterAssignsNothingAndCreatesNoTag(): void
    {
        $this->seedListings(3, 'Наволочки');

        try {
            ($this->action(maxFilterMatch: 2))(
                self::COMPANY_ID,
                ListingTagPayload::forAssign(['filter' => ['q' => 'навол'], 'name' => 'Склад 1']),
            );
            self::fail('Ожидалось ListingFilterTooBroadException');
        } catch (ListingFilterTooBroadException $e) {
            self::assertSame(2, $e->limit);
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_listing_tags'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_listing_tag_assignments'));
    }

    public function testFilterExactlyAtLimitIsAssigned(): void
    {
        $this->seedListings(2, 'Наволочки');

        $result = ($this->action(maxFilterMatch: 2))(
            self::COMPANY_ID,
            ListingTagPayload::forAssign(['filter' => ['q' => 'навол'], 'name' => 'Склад 1']),
        );

        self::assertSame(2, $result->matched);
        self::assertSame(2, $result->assigned);
    }

    private function action(int $maxFilterMatch): AssignListingTagAction
    {
        $container = self::getContainer();

        return new AssignListingTagAction(
            $container->get(MarketplaceListingTagRepository::class),
            $container->get(ListingTagAssignmentRepository::class),
            $container->get(MarketplaceListingsQuery::class),
            $this->em,
            new NullLogger(),
            $maxFilterMatch,
        );
    }

    private function seedListings(int $count, string $name): void
    {
        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000914')
            ->withEmail('assign-tag-action@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        for ($i = 1; $i <= $count; ++$i) {
            $this->em->persist(
                MarketplaceListingBuilder::aListing()
                    ->forCompany($company)
                    ->withMarketplace(MarketplaceType::OZON)
                    ->withMarketplaceSku('ozon-assign-'.$i)
                    ->withName($name.' '.$i)
                    ->build(),
            );
        }
        $this->em->flush();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Support\MoySklad;

use App\MoySklad\Entity\MoySkladSyncCursor;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Builders\MoySklad\MoySkladProductBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

trait MappingFixtures
{
    /** @return array{connection:\App\MoySklad\Entity\MoySkladConnection,product:\App\MoySklad\Entity\MoySkladProduct,listing:\App\Marketplace\Entity\MarketplaceListing,company:\App\Company\Entity\Company,actor:string} */
    private function mappingCatalog(EntityManagerInterface $em, bool $ready = true): array
    {
        $actor = '22222222-2222-4222-8222-000000000001';
        $owner = UserBuilder::aUser()->withId($actor)->withEmail('mapping-owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(MoySkladConnectionBuilder::DEFAULT_COMPANY_ID)->withOwner($owner)->build();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-000000000001');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $product = MoySkladProductBuilder::aProduct()->forConnection($connection)->build();
        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(\App\Marketplace\Enum\MarketplaceType::OZON)->withIndex(1)->withMarketplaceSku('OZ-001')->withSupplierSku('001')->withName('Листинг Ozon')->build();
        foreach ([$owner, $company, $connection, $product, $listing] as $entity) {
            $em->persist($entity);
        }
        if ($ready) {
            foreach (['product', 'variant'] as $type) {
                $cursor = new MoySkladSyncCursor(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), $type);
                $cursor->completeAt(new \DateTimeImmutable('2026-11-15T00:00:00+00:00'));
                $em->persist($cursor);
            }
        }
        $em->flush();

        return ['connection' => $connection, 'product' => $product, 'listing' => $listing, 'company' => $company, 'actor' => $actor];
    }
}

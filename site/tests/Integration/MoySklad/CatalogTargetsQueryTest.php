<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Infrastructure\Query\MoySkladCatalogTargetsQuery;
use App\Tests\Builders\MoySklad\MoySkladProductBuilder;
use App\Tests\Builders\MoySklad\MoySkladVariantBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;

final class CatalogTargetsQueryTest extends IntegrationTestCase
{
    use MappingFixtures;

    public function testSuggestIsExactCaseSensitiveAndNeverSelectsVariantParent(): void
    {
        $data = $this->mappingCatalog($this->em);
        $duplicate = MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex(2)->build();
        $different = MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex(3)->withArticle('1')->build();
        $variant = MoySkladVariantBuilder::aVariant()->forProduct($different)->withArticle('001')->build();
        foreach ([$duplicate, $different, $variant] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $query = self::getContainer()->get(MoySkladCatalogTargetsQuery::class);
        $page = $query->suggest($data['connection']->getCompanyId(), $data['connection']->getId(), ' 001 ');
        self::assertSame(3, $page->totalItems);
        self::assertCount(3, $page->items);
        self::assertSame(['product', 'product', 'variant'], array_column($page->items, 'type'));
        self::assertFalse($query->find($data['connection']->getCompanyId(), $data['connection']->getId(), 'product', $different->getExternalId())?->selectable);
        self::assertSame([], $query->suggest($data['connection']->getCompanyId(), $data['connection']->getId(), null)->items);
        self::assertSame([], $query->suggest('99999999-9999-4999-8999-999999999999', $data['connection']->getId(), '001')->items);
    }

    public function testSearchIsBoundedLiteralAndArchivedTargetsRemainInspectable(): void
    {
        $data = $this->mappingCatalog($this->em);
        for ($i = 2; $i <= 23; ++$i) {
            $this->em->persist(MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex($i)->withName('Товар 001%_'.$i)->build());
        }
        $archived = MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex(24)->archived()->build();
        $this->em->persist($archived);
        $this->em->flush();
        $query = self::getContainer()->get(MoySkladCatalogTargetsQuery::class);
        $page = $query->search($data['connection']->getCompanyId(), $data['connection']->getId(), '001%_');
        self::assertSame(22, $page->totalItems);
        self::assertCount(20, $page->items);
        self::assertSame(20, $page->perPage);
        self::assertCount(2, $query->search($data['connection']->getCompanyId(), $data['connection']->getId(), '001%_', 2)->items);
        self::assertSame([], $query->search($data['connection']->getCompanyId(), $data['connection']->getId(), '0')->items);
        self::assertFalse($query->find($data['connection']->getCompanyId(), $data['connection']->getId(), 'product', $archived->getExternalId())?->selectable);
    }

    public function testArticleSuggestionsPreserveCaseAndLeadingZeros(): void
    {
        $data = $this->mappingCatalog($this->em);
        $upper = MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex(2)->withArticle('AbC')->build();
        $lower = MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex(3)->withArticle('abc')->build();
        $this->em->persist($upper);
        $this->em->persist($lower);
        $this->em->flush();
        $query = self::getContainer()->get(MoySkladCatalogTargetsQuery::class);
        $result = $query->suggest($data['connection']->getCompanyId(), $data['connection']->getId(), 'AbC');
        self::assertSame(1, $result->totalItems);
        self::assertSame($upper->getExternalId(), $result->items[0]->externalId);
        self::assertSame([], $query->suggest($data['connection']->getCompanyId(), $data['connection']->getId(), '1')->items);
    }

    public function testSameExternalIdInTwoConnectionsResolvesOnlyRequestedTenant(): void
    {
        $data = $this->mappingCatalog($this->em);
        $other = \App\Tests\Builders\MoySklad\MoySkladConnectionBuilder::aConnection()->withIndex(2)->build();
        $other->setName('Other connection');
        $now = new \DateTimeImmutable();
        $shadow = new \App\MoySklad\Entity\MoySkladProduct('44444444-4444-7444-8444-000000000002', $other->getCompanyId(), $other->getId(),
            new \App\MoySklad\Domain\ProductSnapshot($data['product']->getExternalId(), 'Shadow', '', null, '001', 0, false, $now), $now);
        $this->em->persist($other);
        $this->em->persist($shadow);
        $repo = self::getContainer()->get(\App\MoySklad\Infrastructure\Repository\MoySkladListingMappingRepository::class);
        foreach ([$data['connection'], $other] as $connection) {
            $repo->append(\App\Tests\Builders\MoySklad\MoySkladListingMappingRevisionBuilder::aRevision()->withTenant($connection->getCompanyId(), $connection->getId())->forListing($data['listing']->getId())->build());
        }
        $this->em->flush();
        $query = self::getContainer()->get(MoySkladCatalogTargetsQuery::class);
        $ref = 'product:'.$data['product']->getExternalId();
        self::assertSame('Товар', $query->findMany($other->getCompanyId(), $data['connection']->getId(), [$ref])[$ref]->name);
        self::assertSame('Shadow', $query->find($other->getCompanyId(), $other->getId(), 'product', $data['product']->getExternalId())?->name);
        self::assertNull($query->find('99999999-9999-4999-8999-999999999999', $other->getId(), 'product', $data['product']->getExternalId()));
        $states = self::getContainer()->get(\App\MoySklad\Infrastructure\Query\MoySkladListingMappingsQuery::class);
        self::assertSame('Товар', $states->currentForListings($other->getCompanyId(), $data['connection']->getId(), [$data['listing']->getId()])[$data['listing']->getId()]->target?->name);
        self::assertSame('Shadow', $states->currentForListings($other->getCompanyId(), $other->getId(), [$data['listing']->getId()])[$data['listing']->getId()]->target?->name);
    }

    public function testSearchEscapesLiteralBackslashAndPagePastEnd(): void
    {
        $data = $this->mappingCatalog($this->em);
        $literal = MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex(2)->withName('Path A\\B')->build();
        $this->em->persist($literal);
        $this->em->flush();
        $query = self::getContainer()->get(MoySkladCatalogTargetsQuery::class);
        $page = $query->search($data['connection']->getCompanyId(), $data['connection']->getId(), 'A\\B');
        self::assertSame(1, $page->totalItems);
        self::assertSame($literal->getExternalId(), $page->items[0]->externalId);
        self::assertSame([], $query->search($data['connection']->getCompanyId(), $data['connection']->getId(), 'A\\B', 99)->items);
    }
}

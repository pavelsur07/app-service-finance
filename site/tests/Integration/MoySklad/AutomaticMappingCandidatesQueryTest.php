<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Infrastructure\Query\AutomaticMappingCandidatesQuery;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Builders\MoySklad\MoySkladProductBuilder;
use App\Tests\Builders\MoySklad\MoySkladVariantBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;

final class AutomaticMappingCandidatesQueryTest extends IntegrationTestCase
{
    use MappingFixtures;

    public function testOwnAndParentArticlesKeepArchivedAndStructuralParentsAndDeduplicatePaths(): void
    {
        $data = $this->mappingCatalog($this->em);
        $variant = MoySkladVariantBuilder::aVariant()->forProduct($data['product'])->withArticle('001')->build();
        $archived = MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex(2)->archived()->build();
        $this->em->persist($variant);
        $this->em->persist($archived);
        $this->em->flush();
        $result = $this->candidateGroupsForArticles(self::getContainer()->get(AutomaticMappingCandidatesQuery::class), $data['connection']->getCompanyId(), $data['connection']->getId(), ['001', ' 001 ']);
        $group = $result['article:001'];
        self::assertSame(3, $group->totalCount, 'Own and parent article must not duplicate a variant.');
        $items = [];
        foreach ($group->items as $item) {
            $items[$item->target->getValue()] = $item;
        }
        self::assertCount(3, $items);
        self::assertFalse($items['product:'.$data['product']->getExternalId()]->leaf, 'Actual children override stale variants_count=0.');
        self::assertFalse($items['product:'.$data['product']->getExternalId()]->target->selectable);
        self::assertFalse($items['product:'.$archived->getExternalId()]->target->selectable);
        self::assertTrue($items['variant:'.$variant->getExternalId()]->leaf);
        self::assertSame('001', $items['variant:'.$variant->getExternalId()]->parentArticle);
    }

    public function testBatchScopesCompanyConnectionAndExactNormalizedArticle(): void
    {
        $data = $this->mappingCatalog($this->em);
        $other = MoySkladConnectionBuilder::aConnection()->withIndex(2)->build();
        $other->setName('Other connection');
        $foreign = MoySkladConnectionBuilder::aConnection()->withIndex(3)->withCompanyId('99999999-9999-4999-8999-999999999999')->build();
        foreach ([$other, $foreign] as $connection) {
            $this->em->persist($connection);
            $now = new \DateTimeImmutable();
            $this->em->persist(new \App\MoySklad\Entity\MoySkladProduct(\Ramsey\Uuid\Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(),
                new \App\MoySklad\Domain\ProductSnapshot($data['product']->getExternalId(), 'Other product', 'ext', null, '001', 0, false, $now), $now));
        }
        foreach (['1', 'AbC', 'abc', "\tKEY\r", "\u{00a0}KEY"] as $i => $article) {
            $this->em->persist(MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex($i + 2)->withArticle($article)->build());
        }
        $this->em->flush();
        $query = self::getContainer()->get(AutomaticMappingCandidatesQuery::class);
        $groups = $this->candidateGroupsForArticles($query, $data['connection']->getCompanyId(), $data['connection']->getId(), ['001', '1', 'AbC', " KEY\n", 'absent']);
        self::assertSame(1, $groups['article:001']->totalCount);
        self::assertSame(1, $groups['article:1']->totalCount);
        self::assertSame('AbC', $groups['article:AbC']->items[0]->target->article);
        self::assertSame(1, $groups['article:KEY']->totalCount);
        self::assertSame(0, $groups['article:absent']->totalCount);
        self::assertSame([], $this->candidateGroupsForArticles($query, $data['connection']->getCompanyId(), $data['connection']->getId(), []));
        self::assertSame(0, $this->candidateGroupsForArticles($query, $foreign->getCompanyId(), $data['connection']->getId(), ['001'])['article:001']->totalCount);
    }

    public function testBarcodeCandidatesKeepNullEmptyDuplicatesAndTenantBoundaries(): void
    {
        $d = $this->mappingCatalog($this->em);
        $company = $d['connection']->getCompanyId();
        $connection = $d['connection']->getId();
        $source = new \App\Marketplace\DTO\ListingMetaDTO($d['listing']->getId(), null, 'SKU', 'ozon');
        $query = self::getContainer()->get(AutomaticMappingCandidatesQuery::class);
        $this->connection->executeStatement("UPDATE moysklad_products SET code='OTHER', article=NULL, barcodes=NULL WHERE connection_id=?", [$connection]);
        self::assertSame(0, $query->forListings($company, $connection, [$source], [$source->id => ['000123']], false)[$source->id]->totalCount);
        $this->connection->executeStatement("UPDATE moysklad_products SET barcodes='[]' WHERE connection_id=?", [$connection]);
        self::assertSame(0, $query->forListings($company, $connection, [$source], [$source->id => ['000123']], false)[$source->id]->totalCount);
        $this->connection->executeStatement("UPDATE moysklad_products SET barcodes='[\"000123\"]' WHERE connection_id=?", [$connection]);
        foreach (range(2, 51) as $i) {
            $this->em->persist(MoySkladProductBuilder::aProduct()->forConnection($d['connection'])->withIndex($i)->withArticle('OTHER')->build());
        }
        $this->em->flush();
        $this->connection->executeStatement("UPDATE moysklad_products SET barcodes='[\"000123\"]' WHERE connection_id=?", [$connection]);
        $group = $query->forListings($company, $connection, [$source], [$source->id => ['000123']], false)[$source->id];
        self::assertSame(51, $group->totalCount);
        self::assertCount(50, $group->items);
        self::assertTrue($group->truncated);
        self::assertSame(0, $query->forListings('99999999-9999-4999-8999-999999999999', $connection, [$source], [$source->id => ['000123']], false)[$source->id]->totalCount);
        self::assertSame(0, $query->forListings($company, \Ramsey\Uuid\Uuid::uuid7()->toString(), [$source], [$source->id => ['000123']], false)[$source->id]->totalCount);
    }

    /** @param list<string> $articles
     * @return array<string,\App\MoySklad\Application\DTO\AutomaticMappingCandidates>
     */
    private function candidateGroupsForArticles(AutomaticMappingCandidatesQuery $query, string $companyId, string $connectionId, array $articles): array
    {
        $sources = [];
        foreach ($articles as $i => $article) {
            $sources[] = new \App\Marketplace\DTO\ListingMetaDTO(sprintf('33333333-3333-4333-8333-%012d', $i), null, 'SKU', 'ozon', $article);
        }
        $groups = $query->forListings($companyId, $connectionId, $sources, [], false);
        $result = [];
        foreach ($sources as $source) {
            $result['article:'.\App\MoySklad\Domain\AutomaticListingMappingPolicy::normalizeKey($source->supplierSku)] = $groups[$source->id];
        }

        return $result;
    }

    public function testFiftyOneMatchesAreTruncatedAndHundredInputKeysAreAllowed(): void
    {
        $data = $this->mappingCatalog($this->em);
        for ($i = 2; $i <= 51; ++$i) {
            $this->em->persist(MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex($i)->build());
        }
        $this->em->flush();
        $query = self::getContainer()->get(AutomaticMappingCandidatesQuery::class);
        $group = $this->candidateGroupsForArticles($query, $data['connection']->getCompanyId(), $data['connection']->getId(), ['001'])['article:001'];
        self::assertSame(51, $group->totalCount);
        self::assertCount(50, $group->items);
        self::assertTrue($group->truncated);
        $preview = self::getContainer()->get(\App\MoySklad\Infrastructure\Query\AutomaticMappingPreviewQuery::class)->page($data['connection']->getCompanyId(), $data['connection']->getId(), 'ozon', '', 1, null);
        self::assertSame(1, $preview->counts['too_many_candidates']);
        self::assertNull($preview->decisions[$data['listing']->getId()]->target);
        self::assertCount(100, $this->candidateGroupsForArticles($query, $data['connection']->getCompanyId(), $data['connection']->getId(), array_map(strval(...), range(1, 100))));
        $this->expectException(\InvalidArgumentException::class);
        $this->candidateGroupsForArticles($query, $data['connection']->getCompanyId(), $data['connection']->getId(), array_map(strval(...), range(1, 101)));
    }
}

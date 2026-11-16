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
        $result = self::getContainer()->get(AutomaticMappingCandidatesQuery::class)->forArticles($data['connection']->getCompanyId(), $data['connection']->getId(), ['001', ' 001 ']);
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
        $groups = $query->forArticles($data['connection']->getCompanyId(), $data['connection']->getId(), ['001', '1', 'AbC', " KEY\n", 'absent']);
        self::assertSame(1, $groups['article:001']->totalCount);
        self::assertSame(1, $groups['article:1']->totalCount);
        self::assertSame('AbC', $groups['article:AbC']->items[0]->target->article);
        self::assertSame(1, $groups['article:KEY']->totalCount);
        self::assertSame(0, $groups['article:absent']->totalCount);
        self::assertSame([], $query->forArticles($data['connection']->getCompanyId(), $data['connection']->getId(), []));
        self::assertSame(0, $query->forArticles($foreign->getCompanyId(), $data['connection']->getId(), ['001'])['article:001']->totalCount);
    }

    public function testFiftyOneMatchesAreTruncatedAndHundredInputKeysAreAllowed(): void
    {
        $data = $this->mappingCatalog($this->em);
        for ($i = 2; $i <= 51; ++$i) {
            $this->em->persist(MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex($i)->build());
        }
        $this->em->flush();
        $query = self::getContainer()->get(AutomaticMappingCandidatesQuery::class);
        $group = $query->forArticles($data['connection']->getCompanyId(), $data['connection']->getId(), ['001'])['article:001'];
        self::assertSame(51, $group->totalCount);
        self::assertCount(50, $group->items);
        self::assertTrue($group->truncated);
        $preview = self::getContainer()->get(\App\MoySklad\Infrastructure\Query\AutomaticMappingPreviewQuery::class)->page($data['connection']->getCompanyId(), $data['connection']->getId(), 'ozon', '', 1, null);
        self::assertSame(1, $preview->counts['too_many_candidates']);
        self::assertNull($preview->decisions[$data['listing']->getId()]->target);
        self::assertCount(100, $query->forArticles($data['connection']->getCompanyId(), $data['connection']->getId(), array_map(strval(...), range(1, 100))));
        $this->expectException(\InvalidArgumentException::class);
        $query->forArticles($data['connection']->getCompanyId(), $data['connection']->getId(), array_map(strval(...), range(1, 101)));
    }
}

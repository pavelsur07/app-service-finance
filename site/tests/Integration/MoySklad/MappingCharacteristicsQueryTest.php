<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Domain\VariantSnapshot;
use App\MoySklad\Infrastructure\Query\MappingCharacteristicsQuery;
use App\Tests\Builders\MoySklad\MoySkladVariantBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;

final class MappingCharacteristicsQueryTest extends IntegrationTestCase
{
    use MappingFixtures;

    public function testOpaqueIdsAreScopedAndDifferentIdsWithSameNameAreNotCollapsed(): void
    {
        $data = $this->mappingCatalog($this->em);
        $variant = MoySkladVariantBuilder::aVariant()->forProduct($data['product'])->build();
        $chars = [];
        for ($i = 1; $i <= 21; ++$i) {
            $chars[] = ['id' => sprintf('opaque-%02d', $i), 'name' => 'Размер', 'value' => 'M'];
        }
        $variant->applySnapshot(new VariantSnapshot($variant->getExternalId(), $data['product']->getExternalId(), 'Variant', '', null, null, $chars, false, new \DateTimeImmutable()), new \DateTimeImmutable());
        $this->em->persist($variant);
        $this->em->flush();
        $query = self::getContainer()->get(MappingCharacteristicsQuery::class);
        $first = $query->page($data['connection']->getCompanyId(), $data['connection']->getId());
        self::assertSame(21, $first->totalItems);
        self::assertCount(20, $first->items);
        self::assertSame(21, $query->page($data['connection']->getCompanyId(), $data['connection']->getId(), 'РАЗМЕР')->totalItems);
        self::assertCount(1, $query->page($data['connection']->getCompanyId(), $data['connection']->getId(), '', 2)->items);
        self::assertSame(['id' => 'opaque-21', 'name' => 'Размер'], $query->find($data['connection']->getCompanyId(), $data['connection']->getId(), 'opaque-21'));
        self::assertNull($query->find('99999999-9999-4999-8999-999999999999', $data['connection']->getId(), 'opaque-21'));
        self::assertSame(0, $query->page($data['connection']->getCompanyId(), $data['connection']->getId(), '%_')->totalItems);
        self::assertSame(1, $query->page($data['connection']->getCompanyId(), $data['connection']->getId(), 'opaque-21')->totalItems);
        self::assertSame([], $query->page($data['connection']->getCompanyId(), $data['connection']->getId(), '', 999)->items);
    }
}

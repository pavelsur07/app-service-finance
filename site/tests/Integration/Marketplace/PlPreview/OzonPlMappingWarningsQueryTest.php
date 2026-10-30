<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\PlPreview;

use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\OzonPlMappingWarningsQuery;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class OzonPlMappingWarningsQueryTest extends IntegrationTestCase
{
    public function testConflictOnCustomTargetIsScopedAndReadOnly(): void
    {
        $fixture = new PlPreviewFixture($this->em, 681);
        $query = new OzonPlMappingWarningsQuery($this->connection);
        $companyId = (string) $fixture->company->getId();
        self::assertSame([], $query->fetch($companyId));

        $sale = new MarketplaceSaleMapping(Uuid::uuid4()->toString(), $fixture->company, MarketplaceType::OZON, AmountSource::SALE_REVENUE, $fixture->realization);
        $return = new MarketplaceSaleMapping(Uuid::uuid4()->toString(), $fixture->company, MarketplaceType::OZON, AmountSource::RETURN_REFUND, $fixture->realization);
        $this->em->persist($sale);
        $this->em->persist($return);
        $this->em->flush();
        $query = new OzonPlMappingWarningsQuery($this->connection);
        $before = $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_sale_mappings');
        $warnings = $query->fetch($companyId);
        self::assertCount(2, $warnings);
        self::assertStringContainsString('sale_revenue', implode(' ', $warnings));
        self::assertStringContainsString('return_refund', implode(' ', $warnings));
        self::assertSame([], $query->fetch((string) $fixture->foreign->getId()));
        self::assertSame($before, $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_sale_mappings'));
        self::assertTrue($sale->isActive());
        self::assertTrue($return->isActive());

        $sale->setIsActive(false);
        $return->setIsActive(false);
        $this->em->flush();
        self::assertSame([], (new OzonPlMappingWarningsQuery($this->connection))->fetch($companyId));
    }

    public function testDifferentCustomTargetAndWildberriesAreNotConflicts(): void
    {
        $fixture = new PlPreviewFixture($this->em, 683);
        $this->em->persist(new MarketplaceSaleMapping(Uuid::uuid4()->toString(), $fixture->company, MarketplaceType::OZON, AmountSource::SALE_REVENUE, $fixture->revenue));
        $this->em->persist(new MarketplaceSaleMapping(Uuid::uuid4()->toString(), $fixture->company, MarketplaceType::WILDBERRIES, AmountSource::SALE_REALIZATION, $fixture->realization));
        $this->em->flush();
        self::assertSame([], (new OzonPlMappingWarningsQuery($this->connection))->fetch((string) $fixture->company->getId()));
    }
}

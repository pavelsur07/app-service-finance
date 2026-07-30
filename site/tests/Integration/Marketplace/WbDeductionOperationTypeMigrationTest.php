<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\Document;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260730120000;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

require_once dirname(__DIR__, 3).'/migrations/Version20260730120000.php';

final class WbDeductionOperationTypeMigrationTest extends IntegrationTestCase
{
    public function testCorrectsOnlyNegativeWbDeductionsIncludingClosedCosts(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(834)->build();
        $this->em->persist($company->getUser());
        $this->em->persist($company);

        $rawDocument = MarketplaceRawDocumentBuilder::aDocument()
            ->withIndex(834)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withDocumentType('sales_report')
            ->build();
        $rawDocument->setRawData([
            ['rrdId' => ' 7001 ', 'sellerOperName' => ' Удержание ', 'deduction' => -49155],
            ['rrd_id' => '7002', 'supplier_oper_name' => 'Удержание', 'deduction' => 300],
            ['rrd_id' => '7003', 'supplier_oper_name' => 'Штраф', 'deduction' => -50],
            ['rrd_id' => '7004', 'supplier_oper_name' => 'Удержание', 'deduction' => 'invalid'],
        ]);
        $this->em->persist($rawDocument);

        $category = (new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::WILDBERRIES,
        ))
            ->setCode('wb_dobrovolnaya_vyplata_za_tovary')
            ->setName('Добровольная выплата за товары');
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $this->em->persist($category);
        $this->em->persist($document);

        $negative = $this->cost(
            $company,
            MarketplaceType::WILDBERRIES,
            $category,
            (string) $rawDocument->getId(),
            'wb:7001:wb_dobrovolnaya_vyplata_za_tovary',
        )->setDocument($document);
        $positive = $this->cost(
            $company,
            MarketplaceType::WILDBERRIES,
            $category,
            (string) $rawDocument->getId(),
            'wb:7002:wb_dobrovolnaya_vyplata_za_tovary',
        );
        $otherOperation = $this->cost(
            $company,
            MarketplaceType::WILDBERRIES,
            $category,
            (string) $rawDocument->getId(),
            'wb:7003:wb_dobrovolnaya_vyplata_za_tovary',
        );
        $invalidDeduction = $this->cost(
            $company,
            MarketplaceType::WILDBERRIES,
            $category,
            (string) $rawDocument->getId(),
            'wb:7004:wb_dobrovolnaya_vyplata_za_tovary',
        );
        $this->em->persist($negative);
        $this->em->persist($positive);
        $this->em->persist($otherOperation);
        $this->em->persist($invalidDeduction);

        $ozonRawDocument = MarketplaceRawDocumentBuilder::aDocument()
            ->withIndex(835)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('sales_report')
            ->build();
        $ozonRawDocument->setRawData([
            ['rrd_id' => '8001', 'supplier_oper_name' => 'Удержание', 'deduction' => -100],
        ]);
        $ozonCategory = (new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
        ))
            ->setCode('wb_dobrovolnaya_vyplata_za_tovary')
            ->setName('Не WB');
        $ozonCost = $this->cost(
            $company,
            MarketplaceType::OZON,
            $ozonCategory,
            (string) $ozonRawDocument->getId(),
            'wb:8001:wb_dobrovolnaya_vyplata_za_tovary',
        );
        $this->em->persist($ozonRawDocument);
        $this->em->persist($ozonCategory);
        $this->em->persist($ozonCost);
        $this->em->flush();

        $migration = new Version20260730120000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement(
                $query->getStatement(),
                $query->getParameters(),
                $query->getTypes(),
            );
        }

        $operationTypes = $this->connection->fetchAllKeyValue(
            'SELECT external_id, operation_type FROM marketplace_costs WHERE raw_document_id = :rawDocumentId',
            ['rawDocumentId' => $rawDocument->getId()],
        );

        self::assertSame('storno', $operationTypes[$negative->getExternalId()]);
        self::assertSame('charge', $operationTypes[$positive->getExternalId()]);
        self::assertSame('charge', $operationTypes[$otherOperation->getExternalId()]);
        self::assertSame('charge', $operationTypes[$invalidDeduction->getExternalId()]);
        self::assertSame(
            'charge',
            $this->connection->fetchOne(
                'SELECT operation_type FROM marketplace_costs WHERE id = :id',
                ['id' => $ozonCost->getId()],
            ),
        );
        self::assertSame(
            $document->getId(),
            $this->connection->fetchOne(
                'SELECT document_id FROM marketplace_costs WHERE id = :id',
                ['id' => $negative->getId()],
            ),
        );
    }

    private function cost(
        Company $company,
        MarketplaceType $marketplace,
        MarketplaceCostCategory $category,
        string $rawDocumentId,
        string $externalId,
    ): MarketplaceCost {
        return (new MarketplaceCost(Uuid::uuid4()->toString(), $company, $marketplace, $category))
            ->setAmount('49155.00')
            ->setCostDate(new \DateTimeImmutable('2026-07-15'))
            ->setExternalId($externalId)
            ->setRawDocumentId($rawDocumentId)
            ->setOperationType(MarketplaceCostOperationType::CHARGE);
    }
}

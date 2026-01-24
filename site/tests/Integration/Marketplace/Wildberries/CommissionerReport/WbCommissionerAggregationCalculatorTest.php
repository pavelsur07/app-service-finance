<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Wildberries\CommissionerReport;

use App\Entity\Company;
use App\Entity\PLCategory;
use App\Marketplace\Wildberries\CommissionerReport\Service\WbCommissionerAggregationCalculator;
use App\Marketplace\Wildberries\CommissionerReport\Service\WbCommissionerDimensionExtractor;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbAggregationResult;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbCommissionerReportRowRaw;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbCostMapping;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbCostType;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbDimensionValue;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\KernelTestCaseBase;
use Ramsey\Uuid\Uuid;

final class WbCommissionerAggregationCalculatorTest extends KernelTestCaseBase
{
    private const ACQUIRING_COLUMN = 'Эквайринг/Комиссии за организацию платежей';
    private const ACQUIRING_TYPE_COLUMN = 'Тип платежа за Эквайринг/Комиссии за организацию платежей';

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->resetDb();
    }

    public function testAggregationMapsAcquiringTypeWhenMappingExists(): void
    {
        $em = $this->em();

        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($user)
            ->build();
        $report = $this->createReport($company);

        $row = new WbCommissionerReportRowRaw(
            Uuid::uuid4()->toString(),
            $company,
            $report,
            1,
            [
                self::ACQUIRING_COLUMN => '12.50',
                self::ACQUIRING_TYPE_COLUMN => 'Интернет-эквайринг',
            ]
        );

        $em->persist($user);
        $em->persist($company);
        $em->persist($report);
        $em->persist($row);

        $costTypes = $this->createCostTypes($company);
        $em->flush();

        $extractor = static::getContainer()->get(WbCommissionerDimensionExtractor::class);
        $extractor->extract($company, $report);

        $calculator = static::getContainer()->get(WbCommissionerAggregationCalculator::class);
        $calculator->calculate($company, $report);

        $results = $em->getRepository(WbAggregationResult::class)->findBy(['report' => $report]);
        self::assertCount(1, $results);

        $unmapped = $results[0];
        self::assertSame('unmapped', $unmapped->getStatus());
        self::assertSame('12.50', $unmapped->getAmount());
        self::assertNull($unmapped->getCostType());
        self::assertNotNull($unmapped->getDimensionValue());

        $dimensionValue = $unmapped->getDimensionValue();
        self::assertInstanceOf(WbDimensionValue::class, $dimensionValue);
        self::assertSame('Интернет-эквайринг', $dimensionValue->getNormalizedValue());

        $category = new PLCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('WB acquiring');

        $mapping = new WbCostMapping(
            Uuid::uuid4()->toString(),
            $company,
            $dimensionValue,
            $costTypes['COMMISSION_WB'],
            $category
        );

        $em->persist($category);
        $em->persist($mapping);
        $em->flush();

        $calculator->calculate($company, $report);

        $results = $em->getRepository(WbAggregationResult::class)->findBy(['report' => $report]);
        self::assertCount(1, $results);

        $mapped = $results[0];
        self::assertSame('mapped', $mapped->getStatus());
        self::assertSame('12.50', $mapped->getAmount());
        self::assertNotNull($mapped->getCostType());
        self::assertSame($costTypes['COMMISSION_WB']->getId(), $mapped->getCostType()->getId());
        self::assertNotNull($mapped->getDimensionValue());
    }

    /**
     * @return array<string, WbCostType>
     */
    private function createCostTypes(Company $company): array
    {
        $codes = [
            'DELIVERY_TO_CUSTOMER',
            'COMMISSION_WB',
            'COMMISSION_WB_VAT',
            'PVZ',
            'STORAGE',
            'REBILL_LOGISTICS',
        ];

        $em = $this->em();
        $types = [];
        foreach ($codes as $code) {
            $type = new WbCostType(
                Uuid::uuid4()->toString(),
                $company,
                $code,
                $code
            );
            $em->persist($type);
            $types[$code] = $type;
        }

        return $types;
    }

    private function createReport(Company $company): WildberriesCommissionerXlsxReport
    {
        $report = new WildberriesCommissionerXlsxReport(
            Uuid::uuid4()->toString(),
            $company,
            new \DateTimeImmutable()
        );
        $report->setPeriodStart(new \DateTimeImmutable('2024-01-01'));
        $report->setPeriodEnd(new \DateTimeImmutable('2024-01-31'));
        $report->setOriginalFilename('wb-report.xlsx');
        $report->setStoragePath('tests/wb-report.xlsx');
        $report->setFileHash(str_repeat('b', 64));

        return $report;
    }
}

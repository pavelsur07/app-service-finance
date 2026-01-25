<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Wildberries\CommissionerReport;

use App\Company\Entity\Company;
use App\Marketplace\Wildberries\CommissionerReport\Service\WbCommissionerDimensionExtractor;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbCommissionerReportRowRaw;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbDimensionValue;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\KernelTestCaseBase;
use Ramsey\Uuid\Uuid;

final class WbCommissionerDimensionExtractorTest extends KernelTestCaseBase
{
    private const LOGISTICS_COLUMN = 'Виды логистики, штрафов и корректировок ВВ';
    private const ACQUIRING_TYPE_COLUMN = 'Тип платежа за Эквайринг/Комиссии за организацию платежей';
    private const WITHHOLDING_COLUMN = 'Удержания';

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->resetDb();
    }

    public function testExtractCreatesDimensionValuesFromRowRaw(): void
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
                self::LOGISTICS_COLUMN => 'Логистика WB',
                self::ACQUIRING_TYPE_COLUMN => 'Интернет-эквайринг',
                self::WITHHOLDING_COLUMN => '10.00',
            ]
        );

        $em->persist($user);
        $em->persist($company);
        $em->persist($report);
        $em->persist($row);
        $em->flush();

        $extractor = static::getContainer()->get(WbCommissionerDimensionExtractor::class);
        $result = $extractor->extract($company, $report);

        self::assertSame(3, $result->dimensionsTotal);

        $values = $em->getRepository(WbDimensionValue::class)->findBy(['report' => $report]);
        self::assertCount(3, $values);

        $valuesByKey = [];
        foreach ($values as $value) {
            $valuesByKey[$value->getDimensionKey()] = $value;
        }

        self::assertArrayHasKey('LOGISTICS_KIND', $valuesByKey);
        self::assertArrayHasKey('ACQUIRING_TYPE', $valuesByKey);
        self::assertArrayHasKey('WITHHOLDING_KIND', $valuesByKey);

        self::assertSame('Логистика WB', $valuesByKey['LOGISTICS_KIND']->getValue());
        self::assertSame('Логистика WB', $valuesByKey['LOGISTICS_KIND']->getNormalizedValue());
        self::assertSame(1, $valuesByKey['LOGISTICS_KIND']->getOccurrences());

        self::assertSame('Интернет-эквайринг', $valuesByKey['ACQUIRING_TYPE']->getValue());
        self::assertSame('Интернет-эквайринг', $valuesByKey['ACQUIRING_TYPE']->getNormalizedValue());
        self::assertSame(1, $valuesByKey['ACQUIRING_TYPE']->getOccurrences());

        self::assertSame('Логистика WB', $valuesByKey['WITHHOLDING_KIND']->getValue());
        self::assertSame('Логистика WB', $valuesByKey['WITHHOLDING_KIND']->getNormalizedValue());
        self::assertSame(1, $valuesByKey['WITHHOLDING_KIND']->getOccurrences());
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
        $report->setFileHash(str_repeat('a', 64));

        return $report;
    }
}

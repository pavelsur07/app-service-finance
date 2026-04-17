<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure;

use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\Ozon\OzonReportRowClassifier;
use PHPUnit\Framework\TestCase;

final class OzonReportRowClassifierTest extends TestCase
{
    private OzonReportRowClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new OzonReportRowClassifier();
    }

    public function testOrdersClassifiedAsSale(): void
    {
        $row = ['type' => 'orders', 'operation_type' => 'OperationAgentDeliveredToCustomer'];

        self::assertSame(StagingRecordType::SALE, $this->classifier->classify($row));
    }

    public function testClientReturnClassifiedAsReturn(): void
    {
        $row = ['type' => 'returns', 'operation_type' => 'ClientReturnAgentOperation'];

        self::assertSame(StagingRecordType::RETURN, $this->classifier->classify($row));
    }

    public function testStornoDeliveredToCustomerClassifiedAsSale(): void
    {
        $row = ['type' => 'returns', 'operation_type' => 'OperationAgentStornoDeliveredToCustomer'];

        self::assertSame(StagingRecordType::SALE, $this->classifier->classify($row));
    }

    public function testOperationItemReturnClassifiedAsCost(): void
    {
        $row = ['type' => 'returns', 'operation_type' => 'OperationItemReturn'];

        self::assertSame(StagingRecordType::COST, $this->classifier->classify($row));
    }

    public function testServicesClassifiedAsCost(): void
    {
        $row = ['type' => 'services', 'operation_type' => 'OperationMarketplaceServiceStorage'];

        self::assertSame(StagingRecordType::COST, $this->classifier->classify($row));
    }

    public function testCompensationClassifiedAsCost(): void
    {
        $row = ['type' => 'compensation', 'operation_type' => 'MarketplaceSellerCompensationOperation'];

        self::assertSame(StagingRecordType::COST, $this->classifier->classify($row));
    }

    public function testOtherClassifiedAsCost(): void
    {
        $row = ['type' => 'other', 'operation_type' => 'OperationMarketplaceCostPerClick'];

        self::assertSame(StagingRecordType::COST, $this->classifier->classify($row));
    }

    public function testUnknownTypeClassifiedAsOther(): void
    {
        $row = ['type' => 'unknown', 'operation_type' => ''];

        self::assertSame(StagingRecordType::OTHER, $this->classifier->classify($row));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\MessageHandler;

use App\Company\Entity\Company;
use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Entity\StockSnapshot;
use App\Inventory\Enum\SnapshotSessionStatus;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Message\NormalizeInventorySnapshotMessage;
use App\Inventory\MessageHandler\NormalizeInventorySnapshotHandler;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Inventory\InventoryRawSnapshotBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class NormalizeInventorySnapshotHandlerTest extends IntegrationTestCase
{
    public function testOzonSourceRunsNormalizationViaRealAction(): void
    {
        $company = $this->createCompany(951);
        $session = new InventorySnapshotSession($company->getId(), MarketplaceType::OZON, SnapshotTriggerType::Manual);
        $session->markCompleted();
        $this->em->persist($session);

        $raw = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withCompanyId($company->getId())
            ->withSnapshotSessionId($session->getId())
            ->withSource(MarketplaceType::OZON)
            ->withFetchedAt(new \DateTimeImmutable('2026-05-11T09:00:00+00:00'))
            ->withResponseBody([
                'result' => [
                    'items' => [[
                        'offer_id' => 'OF-951',
                        'stocks' => [[
                            'sku' => 'SKU-951',
                            'type' => 'fbo',
                            'present' => 9,
                            'reserved' => 4,
                        ]],
                    ]],
                ],
            ])
            ->build();
        $this->em->persist($raw);
        $this->em->flush();

        $handler = self::getContainer()->get(NormalizeInventorySnapshotHandler::class);
        $handler(new NormalizeInventorySnapshotMessage($company->getId(), $session->getId(), MarketplaceType::OZON->value));

        $this->em->refresh($raw);
        self::assertTrue($raw->isProcessed());

        $rows = $this->em->getRepository(StockSnapshot::class)->findBy(['companyId' => $company->getId()]);
        self::assertCount(1, $rows);
        self::assertSame('SKU-951', $rows[0]->getSourceSku());
        self::assertSame('9.000', $rows[0]->getQuantity());
        self::assertSame('4.000', $rows[0]->getReservedQuantity());
    }

    public function testUnsupportedSourceDoesNothingAndDoesNotFail(): void
    {
        $company = $this->createCompany(952);
        $session = new InventorySnapshotSession($company->getId(), MarketplaceType::OZON, SnapshotTriggerType::Manual);
        $session->markCompleted();
        $this->em->persist($session);
        $this->em->flush();

        $handler = self::getContainer()->get(NormalizeInventorySnapshotHandler::class);
        $handler(new NormalizeInventorySnapshotMessage($company->getId(), $session->getId(), 'wb'));

        self::assertSame(0, $this->em->getRepository(StockSnapshot::class)->count(['companyId' => $company->getId()]));
        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
    }


    public function testTechnicalExceptionIsRethrownForMessengerRetry(): void
    {
        $company = $this->createCompany(953);

        $handler = self::getContainer()->get(NormalizeInventorySnapshotHandler::class);

        $this->expectException(\Throwable::class);
        $handler(new NormalizeInventorySnapshotMessage($company->getId(), 'not-a-uuid', MarketplaceType::OZON->value));
    }
    private function createCompany(int $index): Company
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }
}


<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Marketplace\Inventory\Application\ImportInventoryCostPriceFromFileAction;
use App\Marketplace\Message\ImportInventoryCostPriceMessage;
use App\Marketplace\Message\RecalculateListingCostPriceMessage;
use App\Marketplace\MessageHandler\ImportInventoryCostPriceHandler;
use App\Marketplace\Repository\MarketplaceJobLogRepository;
use App\Shared\Service\Storage\TemporaryLocalFile;
use DG\BypassFinals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

BypassFinals::allowPaths([
    '*/src/Marketplace/Inventory/Application/ImportInventoryCostPriceFromFileAction.php',
    '*/src/Shared/Service/Storage/TemporaryLocalFile.php',
]);

final class ImportInventoryCostPriceHandlerTest extends TestCase
{
    #[DataProvider('actorPayloadProvider')]
    public function testSchedulesOneConsolidatedCurrentMonthRecalculation(
        bool $legacyPayload,
        string $expectedActorUserId,
    ): void {
        $listingIds = [
            '22222222-2222-4222-8222-222222222221',
            '22222222-2222-4222-8222-222222222222',
        ];
        $result = [
            'imported' => 2,
            'updated_listings' => 2,
            'overwritten_listings' => 0,
            'skipped' => 0,
            'errors' => [],
            'affected_listing_ids' => $listingIds,
        ];

        $temporaryLocalFile = $this->createMock(TemporaryLocalFile::class);
        $temporaryLocalFile->expects(self::once())
            ->method('with')
            ->willReturn($result);

        $jobLogRepository = $this->createMock(MarketplaceJobLogRepository::class);
        $jobLogRepository->expects(self::exactly(2))->method('save');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $message): bool => $message instanceof RecalculateListingCostPriceMessage
                && $listingIds === $message->listingIds
                && '2026-08-01' === $message->dateFrom
                && '2026-08-23' === $message->dateTo
                && $expectedActorUserId === $message->actorUserId
            ))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $handler = new ImportInventoryCostPriceHandler(
            $this->createMock(ImportInventoryCostPriceFromFileAction::class),
            $temporaryLocalFile,
            $jobLogRepository,
            new NullLogger(),
            $messageBus,
            new MockClock('2026-08-23 12:00:00 Europe/Moscow'),
        );

        $handler($legacyPayload
            ? self::legacyQueuedMessage()
            : new ImportInventoryCostPriceMessage(
                companyId: '11111111-1111-1111-1111-111111111111',
                storagePath: 'inventory/cost-import/file.xlsx',
                originalFilename: 'costs.xlsx',
                effectiveFrom: '2026-08-01',
                marketplace: 'ozon',
                actorUserId: '33333333-3333-4333-8333-333333333333',
            ));
    }

    public static function actorPayloadProvider(): iterable
    {
        yield 'current payload keeps real actor' => [
            false,
            '33333333-3333-4333-8333-333333333333',
        ];
        yield 'legacy payload falls back to system actor' => [
            true,
            ImportInventoryCostPriceMessage::SYSTEM_ACTOR_USER_ID,
        ];
    }

    private static function legacyQueuedMessage(): ImportInventoryCostPriceMessage
    {
        $reflection = new \ReflectionClass(ImportInventoryCostPriceMessage::class);
        /** @var ImportInventoryCostPriceMessage $message */
        $message = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'companyId' => '11111111-1111-1111-1111-111111111111',
            'storagePath' => 'inventory/cost-import/file.xlsx',
            'originalFilename' => 'costs.xlsx',
            'effectiveFrom' => '2026-08-01',
            'marketplace' => 'ozon',
            'identifierType' => 'barcode',
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($message, $value);
        }

        self::assertFalse($reflection->getProperty('actorUserId')->isInitialized($message));

        return $message;
    }
}

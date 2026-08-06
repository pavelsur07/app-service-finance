<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\WbGeneratedRowsSafeReplaceServiceInterface;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\ProcessRawDocumentStepMessage;
use App\Marketplace\MessageHandler\ProcessDayReportHandler;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ProcessDayReportHandlerTest extends TestCase
{
    /**
     * Регрессия: раньше linked rows закрытого документа обрывали refresh дня
     * (conflict + UnrecoverableMessageHandlingException без dispatch).
     * Теперь cleanup удаляет только открытые строки, а pipeline всегда запускается.
     */
    public function testWbForceRefreshCleansOpenRowsAndDispatchesAllSteps(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('11111111-1111-4111-8111-111111111111');
        $doc = new MarketplaceRawDocument('22222222-2222-4222-8222-222222222222', $company, MarketplaceType::WILDBERRIES, 'sales_report');
        $doc->setPeriodFrom(new \DateTimeImmutable('2026-05-10'))->setPeriodTo(new \DateTimeImmutable('2026-05-10'));

        $repo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repo->method('find')->willReturn($doc);

        $safe = $this->createMock(WbGeneratedRowsSafeReplaceServiceInterface::class);
        $safe->expects(self::once())
            ->method('cleanupForRawDocument')
            ->with($company, $doc->getId(), self::callback(static fn (\DateTimeImmutable $d): bool => '2026-05-10' === $d->format('Y-m-d')));

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(
                static function (object $message, array $stamps = []) use (&$dispatched): Envelope {
                    $dispatched[] = $message;

                    return new Envelope($message, $stamps);
                },
            );

        $em = $this->createMock(EntityManagerInterface::class);

        $handler = new ProcessDayReportHandler($repo, $bus, $em, new NullLogger(), $safe);
        $handler(new ProcessDayReportMessage(
            companyId: (string) $company->getId(),
            rawDocumentId: $doc->getId(),
            forceRefresh: true,
            syncStatusId: '33333333-3333-4333-8333-333333333333',
            connectionId: '44444444-4444-4444-8444-444444444444',
            marketplace: MarketplaceType::WILDBERRIES->value,
            reportType: 'sales_report',
            businessDate: '2026-05-10',
        ));

        self::assertCount(3, $dispatched);
        foreach ($dispatched as $message) {
            self::assertInstanceOf(ProcessRawDocumentStepMessage::class, $message);
            self::assertTrue($message->forceRefresh);
        }
    }

    public function testNonWbOrNonSalesReportSkipsCleanup(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('11111111-1111-4111-8111-111111111112');
        $doc = new MarketplaceRawDocument('22222222-2222-4222-8222-222222222223', $company, MarketplaceType::OZON, 'sales_report');
        $doc->setPeriodFrom(new \DateTimeImmutable('2026-05-10'))->setPeriodTo(new \DateTimeImmutable('2026-05-10'));

        $repo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repo->method('find')->willReturn($doc);

        $safe = $this->createMock(WbGeneratedRowsSafeReplaceServiceInterface::class);
        $safe->expects(self::never())->method('cleanupForRawDocument');

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(
                static function (object $message, array $stamps = []) use (&$dispatched): Envelope {
                    $dispatched[] = $message;

                    return new Envelope($message, $stamps);
                },
            );
        $em = $this->createMock(EntityManagerInterface::class);

        $handler = new ProcessDayReportHandler($repo, $bus, $em, new NullLogger(), $safe);
        $handler(new ProcessDayReportMessage((string) $company->getId(), $doc->getId(), true));

        self::assertCount(3, $dispatched);
        foreach ($dispatched as $message) {
            self::assertInstanceOf(ProcessRawDocumentStepMessage::class, $message);
            self::assertTrue($message->forceRefresh);
        }
    }
}

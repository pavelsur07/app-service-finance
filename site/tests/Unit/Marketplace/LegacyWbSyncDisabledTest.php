<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Company\Facade\CompanyFacade;
use App\Marketplace\Application\Command\FetchMarketplaceDataCommand;
use App\Marketplace\Application\FetchMarketplaceDataAction;
use App\Marketplace\Application\ProcessRawDocumentAction;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlanner;
use App\Marketplace\Command\MarketplaceSyncCommand;
use App\Marketplace\Command\WbFinancialReportsSyncCommand;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceSyncFacade;
use App\Marketplace\Infrastructure\Api\MarketplaceFetcherRegistry;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFetcher;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class LegacyWbSyncDisabledTest extends TestCase
{
    public function testMarketplaceSyncWildberriesFailsWithNewCommandHint(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $facadeLogger = $this->createMock(LoggerInterface::class);
        $facadeLogger->expects(self::never())->method('error');

        $commandLogger = $this->createMock(LoggerInterface::class);
        $commandLogger->expects(self::once())
            ->method('error')
            ->with(
                'Legacy WB sync fail-fast triggered.',
                self::callback(static function (array $context): bool {
                    self::assertSame('legacy_wb_sync_fail_fast', $context['legacy_event']);
                    self::assertNull($context['company_id']);
                    self::assertNull($context['connection_id']);
                    self::assertSame(MarketplaceSyncCommand::class, $context['command_class']);
                    self::assertNull($context['message_class']);
                    self::assertStringContainsString(WbFinancialReportsSyncCommand::class, $context['recommended_replacement']);
                    self::assertStringContainsString('app:marketplace:wb-financial-reports:sync', $context['recommended_replacement']);

                    return true;
                }),
            );

        $command = new MarketplaceSyncCommand(
            self::uninitialized(MarketplaceConnectionRepository::class),
            new MarketplaceSyncFacade(self::uninitialized(ProcessRawDocumentAction::class), $bus, $facadeLogger),
            self::uninitialized(CompanyFacade::class),
            $commandLogger,
        );
        self::assertSame('marketplace:sync', $command->getName());

        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['marketplace' => MarketplaceType::WILDBERRIES->value]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('app:marketplace:wb-financial-reports:sync', $tester->getDisplay());
    }

    #[DataProvider('legacyWbSyncMethodsProvider')]
    public function testFacadeLegacyWbSyncMethodsDoNotDispatchFetchCommand(string $methodName): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Legacy WB sync facade fail-fast triggered.',
                self::callback(static function (array $context): bool {
                    self::assertSame('legacy_wb_sync_fail_fast', $context['legacy_event']);
                    self::assertSame('company-id', $context['company_id']);
                    self::assertNull($context['connection_id']);
                    self::assertNull($context['command_class']);
                    self::assertSame(MarketplaceSyncFacade::class, $context['entrypoint_class']);
                    self::assertIsString($context['entrypoint_method']);
                    self::assertNull($context['message_class']);
                    self::assertStringContainsString(WbFinancialReportSyncPlanner::class, $context['recommended_replacement']);
                    self::assertStringContainsString('app:marketplace:wb-financial-reports:sync', $context['recommended_replacement']);

                    return true;
                }),
            );

        $facade = new MarketplaceSyncFacade(self::uninitialized(ProcessRawDocumentAction::class), $bus, $logger);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Legacy WB sync отключён');
        $this->expectExceptionMessage('WbFinancialReportSyncPlanner');
        $this->expectExceptionMessage('app:marketplace:wb-financial-reports:sync');

        $facade->{$methodName}(
            'company-id',
            MarketplaceType::WILDBERRIES,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-02'),
        );
    }

    /**
     * @return iterable<string, array{methodName: string}>
     */
    public static function legacyWbSyncMethodsProvider(): iterable
    {
        yield 'sales' => ['methodName' => 'syncSales'];
        yield 'costs' => ['methodName' => 'syncCosts'];
        yield 'returns' => ['methodName' => 'syncReturns'];
    }

    #[DataProvider('nonWbSyncMethodsProvider')]
    public function testFacadeNonWbSyncMethodsDispatchFetchCommand(
        string $methodName,
        string $expectedProcessKind,
        MarketplaceType $marketplace,
    ): void {
        $fromDate = new \DateTimeImmutable('2026-01-01');
        $toDate = new \DateTimeImmutable('2026-01-02');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $message) use ($expectedProcessKind, $fromDate, $marketplace): bool {
                self::assertInstanceOf(FetchMarketplaceDataCommand::class, $message);
                self::assertSame('company-id', $message->companyId);
                self::assertSame($marketplace, $message->type);
                self::assertSame($fromDate, $message->dateFrom);
                self::assertSame('sales_report', $message->documentType);
                self::assertSame($expectedProcessKind, $message->processKind);

                return true;
            }))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $facade = new MarketplaceSyncFacade(self::uninitialized(ProcessRawDocumentAction::class), $bus, $logger);

        self::assertSame(0, $facade->{$methodName}('company-id', $marketplace, $fromDate, $toDate));
    }

    /**
     * @return iterable<string, array{methodName: string, expectedProcessKind: string, marketplace: MarketplaceType}>
     */
    public static function nonWbSyncMethodsProvider(): iterable
    {
        foreach ([
            'ozon' => MarketplaceType::OZON,
            'yandex_market' => MarketplaceType::YANDEX_MARKET,
            'sber_megamarket' => MarketplaceType::SBER_MEGAMARKET,
        ] as $marketplaceName => $marketplace) {
            yield $marketplaceName.' sales' => [
                'methodName' => 'syncSales',
                'expectedProcessKind' => 'sales',
                'marketplace' => $marketplace,
            ];
            yield $marketplaceName.' costs' => [
                'methodName' => 'syncCosts',
                'expectedProcessKind' => 'costs',
                'marketplace' => $marketplace,
            ];
            yield $marketplaceName.' returns' => [
                'methodName' => 'syncReturns',
                'expectedProcessKind' => 'returns',
                'marketplace' => $marketplace,
            ];
        }
    }

    public function testWbFetcherDoesNotParticipateInMarketplaceFetcherTaggedRegistry(): void
    {
        $attributes = (new \ReflectionClass(WbFetcher::class))->getAttributes(AutoconfigureTag::class);

        self::assertSame([], $attributes);
    }

    public function testFetcherRegistryDoesNotReturnWbFetcherForWildberries(): void
    {
        $wbFetcher = self::uninitialized(WbFetcher::class);
        $registry = new MarketplaceFetcherRegistry([$wbFetcher]);

        self::assertFalse($wbFetcher->supports(MarketplaceType::WILDBERRIES));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Marketplace fetcher is not configured for type "wildberries".');

        $registry->get(MarketplaceType::WILDBERRIES);
    }

    public function testFetchMarketplaceDataActionCannotSelectWbFetcher(): void
    {
        $wbFetcher = self::uninitialized(WbFetcher::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getReference');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $action = new FetchMarketplaceDataAction(
            new MarketplaceFetcherRegistry([$wbFetcher]),
            self::uninitialized(MarketplaceRawDocumentRepository::class),
            $entityManager,
            $messageBus,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Marketplace fetcher is not configured for type "wildberries".');

        $action(new FetchMarketplaceDataCommand(
            companyId: 'company-id',
            type: MarketplaceType::WILDBERRIES,
            dateFrom: new \DateTimeImmutable('2026-01-01'),
            documentType: 'sales',
            processKind: 'sales',
        ));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return T
     */
    private static function uninitialized(string $className): object
    {
        return (new \ReflectionClass($className))->newInstanceWithoutConstructor();
    }
}

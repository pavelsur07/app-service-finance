<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Company\Facade\CompanyFacade;
use App\Marketplace\Application\ProcessRawDocumentAction;
use App\Marketplace\Command\MarketplaceSyncCommand;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceSyncFacade;
use App\Marketplace\Infrastructure\Api\MarketplaceFetcherRegistry;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFetcher;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

final class LegacyWbSyncDisabledTest extends TestCase
{
    public function testMarketplaceSyncWildberriesFailsWithNewCommandHint(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $command = new MarketplaceSyncCommand(
            self::uninitialized(MarketplaceConnectionRepository::class),
            new MarketplaceSyncFacade(self::uninitialized(ProcessRawDocumentAction::class), $bus),
            self::uninitialized(CompanyFacade::class),
        );
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

        $facade = new MarketplaceSyncFacade(self::uninitialized(ProcessRawDocumentAction::class), $bus);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Legacy WB sync отключён');

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

    public function testFetcherRegistryDoesNotReturnWbFetcherForWildberries(): void
    {
        $wbFetcher = self::uninitialized(WbFetcher::class);
        $registry = new MarketplaceFetcherRegistry([$wbFetcher]);

        self::assertFalse($wbFetcher->supports(MarketplaceType::WILDBERRIES));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Marketplace fetcher is not configured for type "wildberries".');

        $registry->get(MarketplaceType::WILDBERRIES);
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

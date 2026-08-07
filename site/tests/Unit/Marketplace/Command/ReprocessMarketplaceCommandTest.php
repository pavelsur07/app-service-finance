<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\DTO\ProcessRawDocumentResult;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Application\ProcessOzonRealizationAction;
use App\Marketplace\Application\ReprocessMarketplacePeriodAction;
use App\Marketplace\Command\ReprocessMarketplaceCommand;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use DG\BypassFinals;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

BypassFinals::allowPaths([
    '*/src/Marketplace/Application/ProcessMarketplaceRawDocumentAction.php',
]);

final class ReprocessMarketplaceCommandTest extends TestCase
{
    public function testPartialWbReprocessIsReportedAsWarningWithPreservedRowsCount(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(405)->build();
        $day = new \DateTimeImmutable('2026-04-22');
        $rawDocument = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod($day, $day)
            ->build();

        $rawDocumentRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocumentRepository->method('findByCompanyAndPeriod')->willReturn([$rawDocument]);

        $processAction = $this->createMock(ProcessMarketplaceRawDocumentAction::class);
        $processAction->expects(self::exactly(3))
            ->method('__invoke')
            ->willReturnCallback(static function (ProcessMarketplaceRawDocumentCommand $command): ProcessRawDocumentResult {
                if ('sales' === $command->kind) {
                    return new ProcessRawDocumentResult(4, 2);
                }

                return new ProcessRawDocumentResult(0);
            });

        $action = new ReprocessMarketplacePeriodAction(
            $rawDocumentRepository,
            $processAction,
            self::uninitialized(ProcessOzonRealizationAction::class),
            new NullLogger(),
        );
        $tester = new CommandTester(new ReprocessMarketplaceCommand($action));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'companyId' => (string) $company->getId(),
            'marketplace' => MarketplaceType::WILDBERRIES->value,
            'periodFrom' => '2026-04-22',
            'periodTo' => '2026-04-22',
            '--only' => 'sales_report',
        ]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Продажи:    4', $display);
        self::assertStringContainsString('Частично обработано шагов: 1;', $display);
        self::assertMatchesRegularExpression('/документом строк:\s+2\./u', $display);
        self::assertStringNotContainsString('Переобработка завершена.', $display);
    }

    private static function uninitialized(string $className): object
    {
        return (new \ReflectionClass($className))->newInstanceWithoutConstructor();
    }
}

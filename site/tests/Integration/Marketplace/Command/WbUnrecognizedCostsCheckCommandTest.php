<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Command;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStep;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Гейт нераспознанных затрат WB проверяется на реальном контейнере и настоящем
 * запросе: он читает JSON-счётчики документов, а не мокируемый интерфейс.
 */
final class WbUnrecognizedCostsCheckCommandTest extends IntegrationTestCase
{
    public function testPassesWhenThereAreNoDocuments(): void
    {
        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('checked documents count: 0', $tester->getDisplay());
        self::assertStringContainsString('unrecognized rows count: 0', $tester->getDisplay());
    }

    public function testPassesWhenWindowHasOnlyRecognizedCosts(): void
    {
        $company = $this->seedCompanyWithWbConnection('clean-wb-gate@example.test', '77777777-7777-7777-7777-000000000101');
        $this->seedDocument($company, 1, new \DateTimeImmutable('today -1 day'), []);

        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('checked documents count: 1', $tester->getDisplay());
        self::assertStringContainsString('costs not processed count: 0', $tester->getDisplay());
    }

    public function testFailsAndAggregatesUnrecognizedOperationsInWindow(): void
    {
        $company = $this->seedCompanyWithWbConnection('dirty-wb-gate@example.test', '77777777-7777-7777-7777-000000000102');
        $this->seedDocument($company, 2, new \DateTimeImmutable('today -1 day'), ['Коррекция стоимости доставки' => 3, 'Доставка' => 5]);
        $this->seedDocument($company, 3, new \DateTimeImmutable('today -2 days'), ['Доставка' => 4]);

        $tester = $this->makeTester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('UNRECOGNIZED company '.$company->getId().': «Доставка» — 9 строк в 2 документах', $display);
        self::assertStringContainsString('«Коррекция стоимости доставки» — 3 строк в 1 документах', $display);
        self::assertStringContainsString('unrecognized rows count: 12', $display);
        self::assertStringContainsString('unrecognized operations count: 2', $display);
    }

    public function testDocumentOlderThanWindowDoesNotFailTheGate(): void
    {
        // Охват проверки равен охвату починки: старый остаток чинит только ручной
        // пересчёт, и он не вправе делать ночной гейт вечно красным.
        $company = $this->seedCompanyWithWbConnection('old-wb-gate@example.test', '77777777-7777-7777-7777-000000000103');
        $this->seedDocument($company, 4, new \DateTimeImmutable('today -40 days'), ['Коррекция логистики' => 1]);

        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('checked documents count: 0', $tester->getDisplay());
    }

    public function testDaysBackWidensTheWindow(): void
    {
        $company = $this->seedCompanyWithWbConnection('wide-wb-gate@example.test', '77777777-7777-7777-7777-000000000104');
        $this->seedDocument($company, 5, new \DateTimeImmutable('today -40 days'), ['Коррекция логистики' => 1]);

        $tester = $this->makeTester();

        self::assertSame(Command::FAILURE, $tester->execute(['--days-back' => '60']));
        self::assertStringContainsString('«Коррекция логистики»', $tester->getDisplay());
    }

    public function testCompanyWithoutActiveConnectionIsNotChecked(): void
    {
        $company = $this->seedCompany('no-connection-wb-gate@example.test');
        $this->seedDocument($company, 6, new \DateTimeImmutable('today -1 day'), ['Доставка' => 7]);

        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('checked documents count: 0', $tester->getDisplay());
    }

    public function testOtherMarketplaceDocumentIsIgnored(): void
    {
        $company = $this->seedCompanyWithWbConnection('ozon-doc-wb-gate@example.test', '77777777-7777-7777-7777-000000000105');
        $this->em->persist(
            MarketplaceRawDocumentBuilder::aDocument()
                ->withIndex(7)
                ->forCompany($company)
                ->withMarketplace(MarketplaceType::OZON)
                ->withPeriod(new \DateTimeImmutable('today -1 day'), new \DateTimeImmutable('today -1 day'))
                ->withUnprocessedCostTypes(['Доставка' => 2])
                ->build(),
        );
        $this->em->flush();

        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    public function testDocumentWithoutCostsStepIsReportedButDoesNotFail(): void
    {
        // Нулевой счётчик у документа без шага costs ничего не доказывает: он лишь
        // не заполнен. Гейт не молчит об этом, но и не красный — чинит пересчёт.
        $company = $this->seedCompanyWithWbConnection('nocosts-wb-gate@example.test', '77777777-7777-7777-7777-000000000106');
        $this->em->persist(
            MarketplaceRawDocumentBuilder::aDocument()
                ->withIndex(8)
                ->forCompany($company)
                ->withMarketplace(MarketplaceType::WILDBERRIES)
                ->withPeriod(new \DateTimeImmutable('today -1 day'), new \DateTimeImmutable('today -1 day'))
                ->withSucceededSteps(PipelineStep::SALES, PipelineStep::RETURNS)
                ->build(),
        );
        $this->em->flush();

        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('costs not processed count: 1', $tester->getDisplay());
    }

    public function testRejectsInvalidDaysBack(): void
    {
        $tester = $this->makeTester();

        self::assertSame(Command::INVALID, $tester->execute(['--days-back' => '0']));
        self::assertSame(Command::INVALID, $tester->execute(['--days-back' => '91']));
        self::assertSame(Command::INVALID, $tester->execute(['--days-back' => 'abc']));
    }

    /**
     * @param array<string, int> $types
     */
    private function seedDocument(Company $company, int $index, \DateTimeImmutable $day, array $types): void
    {
        $this->em->persist(
            MarketplaceRawDocumentBuilder::aDocument()
                ->withIndex($index)
                ->forCompany($company)
                ->withMarketplace(MarketplaceType::WILDBERRIES)
                ->withPeriod($day, $day)
                ->withUnprocessedCostTypes($types)
                ->withSucceededSteps(PipelineStep::SALES, PipelineStep::RETURNS, PipelineStep::COSTS)
                ->build(),
        );
        $this->em->flush();
    }

    private function seedCompanyWithWbConnection(string $email, string $connectionId): Company
    {
        $company = $this->seedCompany($email);

        $connection = new MarketplaceConnection(
            id: $connectionId,
            company: $company,
            marketplace: MarketplaceType::WILDBERRIES,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key');
        $connection->setIsActive(true);

        $this->em->persist($connection);
        $this->em->flush();

        return $company;
    }

    private function seedCompany(string $email): Company
    {
        $owner = UserBuilder::aUser()->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function makeTester(): CommandTester
    {
        $app = new Application(self::bootKernel());

        return new CommandTester($app->find('app:marketplace:wb-costs:unrecognized-check'));
    }
}

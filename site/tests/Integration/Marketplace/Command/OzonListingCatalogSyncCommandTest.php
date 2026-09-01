<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Command;

use App\Company\Entity\Company;
use App\Marketplace\Command\OzonListingCatalogSyncCommand;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Marketplace\Message\SyncOzonListingCatalogMessage;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class OzonListingCatalogSyncCommandTest extends IntegrationTestCase
{
    public function testNoConnectionsReturnsSuccess(): void
    {
        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('queued count: 0', $tester->getDisplay());
        self::assertStringContainsString('errors count: 0', $tester->getDisplay());
        self::assertSame([], $this->dispatched());
    }

    public function testDispatchesOneMessagePerActiveOzonSellerConnection(): void
    {
        $company = $this->seedCompany(81);
        $this->seedConnection($company, 81, MarketplaceType::OZON, MarketplaceConnectionType::SELLER, true);
        $this->em->flush();

        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('queued count: 1', $tester->getDisplay());

        $messages = $this->dispatched();
        self::assertCount(1, $messages);
        self::assertSame((string) $company->getId(), $messages[0]->companyId);
        self::assertSame($this->connectionId(81), $messages[0]->connectionId);
    }

    public function testIgnoresWildberriesConnections(): void
    {
        $company = $this->seedCompany(82);
        $this->seedConnection($company, 82, MarketplaceType::WILDBERRIES, MarketplaceConnectionType::SELLER, true);
        $this->em->flush();

        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([], $this->dispatched());
    }

    public function testIgnoresInactiveConnections(): void
    {
        $company = $this->seedCompany(83);
        $this->seedConnection($company, 83, MarketplaceType::OZON, MarketplaceConnectionType::SELLER, false);
        $this->em->flush();

        $tester = $this->makeTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([], $this->dispatched());
    }

    /**
     * Ручной запуск «по запросу» для одной компании — вторая половина требования
     * Владельца «и то, и другое» наряду с кнопкой в UI.
     */
    public function testCompanyOptionLimitsDispatchToThatCompany(): void
    {
        $first = $this->seedCompany(84);
        $second = $this->seedCompany(85);
        $this->seedConnection($first, 84, MarketplaceType::OZON, MarketplaceConnectionType::SELLER, true);
        $this->seedConnection($second, 85, MarketplaceType::OZON, MarketplaceConnectionType::SELLER, true);
        $this->em->flush();

        $tester = $this->makeTester();
        $tester->execute(['--company' => (string) $second->getId()]);

        $messages = $this->dispatched();
        self::assertCount(1, $messages);
        self::assertSame((string) $second->getId(), $messages[0]->companyId);
    }

    /**
     * Cron зовёт команду с `--quiet`, поэтому writeln в stdout не доходит
     * никуда. Сбой диспетчеризации, видимый только через OutputInterface,
     * исчезал бы бесследно — ровно тот класс тихой поломки, ради которого
     * задача и делается.
     *
     * Фасад берётся настоящий, из контейнера: он final, а мок final-класса
     * добавил бы запись в phpstan-baseline, который разрешено только сокращать.
     */
    public function testDispatchFailureIsLoggedNotOnlyPrinted(): void
    {
        $company = $this->seedCompany(86);
        $this->seedConnection($company, 86, MarketplaceType::OZON, MarketplaceConnectionType::SELLER, true);
        $this->em->flush();

        $failingBus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('transport down');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('OzonListingCatalog'),
                self::callback(static fn (array $context): bool => ($context['company_id'] ?? null) === (string) $company->getId()
                    && '66666666-6666-4666-8666-000000000086' === ($context['connection_id'] ?? null)
                    && \RuntimeException::class === ($context['exception_class'] ?? null)),
            );

        /** @var MarketplaceFacade $facade */
        $facade = self::getContainer()->get(MarketplaceFacade::class);
        $command = new OzonListingCatalogSyncCommand($facade, $failingBus, $logger);

        self::assertSame(Command::FAILURE, $command->run(new ArrayInput([]), new NullOutput()));
    }

    /**
     * @return list<SyncOzonListingCatalogMessage>
     */
    private function dispatched(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async_sync');

        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SyncOzonListingCatalogMessage) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function connectionId(int $index): string
    {
        return sprintf('66666666-6666-4666-8666-%012d', $index);
    }

    private function seedConnection(
        Company $company,
        int $index,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $type,
        bool $active,
    ): void {
        $connection = new MarketplaceConnection($this->connectionId($index), $company, $marketplace, $type);
        $connection->setApiKey('test-key')->setClientId('test-client')->setIsActive($active);
        $this->em->persist($connection);
    }

    private function seedCompany(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()
            ->withIndex($index)
            ->withOwner($owner)
            ->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function makeTester(): CommandTester
    {
        $app = new Application(self::bootKernel());

        return new CommandTester($app->find('app:marketplace:ozon-listing-catalog:sync'));
    }
}

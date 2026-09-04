<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Command;

use App\Company\Entity\Company;
use App\Inventory\Application\DTO\WbInventorySnapshotRequestResult;
use App\Inventory\Application\RequestWbInventorySnapshotAction;
use App\Inventory\Command\WbInventoryDailySyncCommand;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WbInventoryDailySyncCommandTest extends IntegrationTestCase
{
    public function testNoConnectionsReturnsSuccess(): void
    {
        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('start', $tester->getDisplay());
        self::assertStringContainsString('active connections count: 0 / queued count: 0', $tester->getDisplay());
        self::assertStringContainsString('skipped count: 0', $tester->getDisplay());
        self::assertStringContainsString('errors count: 0', $tester->getDisplay());
        self::assertStringContainsString('finish', $tester->getDisplay());
    }

    public function testCreatesScheduledNightSessionForActiveSellerConnection(): void
    {
        $company = $this->seedCompany('owner-inv-wb@example.test');
        $this->seedWbConnection($company, '77777777-7777-7777-7777-000000000020');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('active connections count: 1 / queued count: 1', $tester->getDisplay());

        $session = $this->em->getRepository(InventorySnapshotSession::class)->findOneBy([
            'companyId' => $company->getId(),
        ]);

        self::assertInstanceOf(InventorySnapshotSession::class, $session);
        self::assertSame(MarketplaceType::WILDBERRIES, $session->getSource());
        self::assertSame(SnapshotTriggerType::ScheduledNight, $session->getTriggerType());
    }

    /**
     * Ozon-подключение той же компании не должно порождать WB-сессию: команда
     * обязана фильтровать по marketplace, иначе ночной прогон заведёт пустую
     * WB-сессию и заблокирует ручной запуск через active-session guard.
     */
    public function testIgnoresOzonConnections(): void
    {
        $company = $this->seedCompany('owner-inv-ozon-only@example.test');
        $connection = new MarketplaceConnection(
            id: '77777777-7777-7777-7777-000000000021',
            company: $company,
            marketplace: MarketplaceType::OZON,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key');
        $connection->setClientId('test-client-id');
        $connection->setIsActive(true);

        $this->em->persist($connection);
        $this->em->flush();

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('active connections count: 0 / queued count: 0', $tester->getDisplay());
        self::assertNull($this->em->getRepository(InventorySnapshotSession::class)->findOneBy([
            'companyId' => $company->getId(),
        ]));
    }

    public function testInactiveConnectionIsSkipped(): void
    {
        $company = $this->seedCompany('owner-inv-wb-inactive@example.test');
        $connection = $this->seedWbConnection($company, '77777777-7777-7777-7777-000000000022');
        $connection->setIsActive(false);
        $this->em->flush();

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('active connections count: 0 / queued count: 0', $tester->getDisplay());
    }

    /**
     * Второй прогон при незавершённой сессии не создаёт дубль: guard живёт в
     * RequestWbInventorySnapshotAction, команда обязана его уважать.
     */
    public function testDoesNotDuplicateSessionWhileOneIsActive(): void
    {
        $company = $this->seedCompany('owner-inv-wb-twice@example.test');
        $this->seedWbConnection($company, '77777777-7777-7777-7777-000000000023');

        $this->makeTester()->execute([]);

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('active connections count: 1 / queued count: 0', $tester->getDisplay());
        self::assertStringContainsString('skipped count: 1', $tester->getDisplay());

        self::assertCount(1, $this->em->getRepository(InventorySnapshotSession::class)->findBy([
            'companyId' => $company->getId(),
        ]));
    }

    /**
     * Полностью пустой прогон обязан отдать ненулевой exit code: тихий SUCCESS на
     * упавшем cron — это тот самый режим отказа, из-за которого остатки молча стояли.
     */
    public function testReturnsFailureWhenEveryCompanyErrors(): void
    {
        $company = $this->seedCompany('owner-inv-wb-fail@example.test');
        $this->seedWbConnection($company, '77777777-7777-7777-7777-000000000024');

        $action = $this->actionMock();
        $action->method('__invoke')->willThrowException(new \RuntimeException('dispatch is down'));
        self::getContainer()->set(RequestWbInventorySnapshotAction::class, $action);

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('errors count: 1', $tester->getDisplay());
        self::assertStringContainsString('finish', $tester->getDisplay());
    }

    /**
     * Одно битое подключение не должно красить весь прогон: иначе cron становится
     * вечно красным и перестаёт нести сигнал.
     */
    public function testPartialFailureStillReturnsSuccess(): void
    {
        $failing = $this->seedCompany(
            'owner-inv-wb-partial-a@example.test',
            '11111111-1111-1111-1111-00000000000a',
            '22222222-2222-2222-2222-00000000000a',
        );
        $working = $this->seedCompany(
            'owner-inv-wb-partial-b@example.test',
            '11111111-1111-1111-1111-00000000000b',
            '22222222-2222-2222-2222-00000000000b',
        );
        $this->seedWbConnection($failing, '77777777-7777-7777-7777-000000000025');
        $this->seedWbConnection($working, '77777777-7777-7777-7777-000000000026');

        $action = $this->actionMock();
        $action->method('__invoke')->willReturnCallback(
            static function (string $companyId) use ($failing): WbInventorySnapshotRequestResult {
                if ($companyId === $failing->getId()) {
                    throw new \RuntimeException('dispatch is down');
                }

                return new WbInventorySnapshotRequestResult(1, 0, true, false);
            },
        );
        self::getContainer()->set(RequestWbInventorySnapshotAction::class, $action);

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('active connections count: 2 / queued count: 1', $tester->getDisplay());
        self::assertStringContainsString('errors count: 1', $tester->getDisplay());
    }

    /**
     * Сбой по компании — warning с деталями, а инцидент в целом — один error
     * со счётчиком: cron-stdout никто не читает, а per-row error засоряет GlitchTip.
     */
    public function testCompanyFailureIsLoggedAsWarningAndOneAggregatedError(): void
    {
        $failing = $this->seedCompany(
            'owner-inv-wb-log-a@example.test',
            '11111111-1111-1111-1111-00000000000c',
            '22222222-2222-2222-2222-00000000000c',
        );
        $working = $this->seedCompany(
            'owner-inv-wb-log-b@example.test',
            '11111111-1111-1111-1111-00000000000d',
            '22222222-2222-2222-2222-00000000000d',
        );
        $this->seedWbConnection($failing, '77777777-7777-7777-7777-000000000027');
        $this->seedWbConnection($working, '77777777-7777-7777-7777-000000000028');

        $action = $this->actionMock();
        $action->method('__invoke')->willReturnCallback(
            static function (string $companyId) use ($failing): WbInventorySnapshotRequestResult {
                if ($companyId === $failing->getId()) {
                    throw new \RuntimeException('dispatch is down');
                }

                return new WbInventorySnapshotRequestResult(1, 0, true, false);
            },
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'WB inventory daily sync failed for company.',
                self::callback(static fn (array $context): bool => $context['companyId'] === $failing->getId()
                    && 'dispatch is down' === $context['error']),
            );
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'WB inventory daily sync failed for some companies.',
                self::callback(static fn (array $context): bool => 1 === $context['failedCount']
                    && [$failing->getId()] === $context['companyIds']),
            );

        $tester = new CommandTester(new WbInventoryDailySyncCommand(
            self::getContainer()->get(MarketplaceFacade::class),
            self::asAction($action),
            $logger,
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('errors count: 1', $tester->getDisplay());
    }

    public function testSuccessfulRunLogsNoError(): void
    {
        $company = $this->seedCompany('owner-inv-wb-log-ok@example.test');
        $this->seedWbConnection($company, '77777777-7777-7777-7777-000000000029');

        $action = $this->actionMock();
        $action->method('__invoke')->willReturn(new WbInventorySnapshotRequestResult(1, 0, true, false));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::never())->method('error');

        $tester = new CommandTester(new WbInventoryDailySyncCommand(
            self::getContainer()->get(MarketplaceFacade::class),
            self::asAction($action),
            $logger,
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    private function seedWbConnection(Company $company, string $id): MarketplaceConnection
    {
        $connection = new MarketplaceConnection(
            id: $id,
            company: $company,
            marketplace: MarketplaceType::WILDBERRIES,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-wb-key');
        $connection->setIsActive(true);

        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    private function seedCompany(string $email, ?string $companyId = null, ?string $ownerId = null): Company
    {
        $ownerBuilder = UserBuilder::aUser()->withEmail($email);
        if (null !== $ownerId) {
            $ownerBuilder = $ownerBuilder->withId($ownerId);
        }
        $owner = $ownerBuilder->build();

        $builder = CompanyBuilder::aCompany()->withOwner($owner);
        if (null !== $companyId) {
            $builder = $builder->withId($companyId);
        }
        $company = $builder->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    /**
     * Именно `self::$kernel`, а не повторный `bootKernel()`: перезагрузка ядра
     * выбросила бы подменённые в контейнере сервисы и тест молча проверял бы
     * реальную реализацию.
     */
    private function actionMock(): MockObject
    {
        return $this->createMock(RequestWbInventorySnapshotAction::class);
    }

    /**
     * Мок final readonly Action нельзя типизировать пересечением с MockObject
     * (PHPStan: unresolvable type), поэтому сужение делается через instanceof.
     */
    private static function asAction(object $action): RequestWbInventorySnapshotAction
    {
        \assert($action instanceof RequestWbInventorySnapshotAction);

        return $action;
    }

    private function makeTester(): CommandTester
    {
        $app = new Application(self::$kernel);
        $command = $app->find('app:inventory:wb-daily-sync');

        return new CommandTester($command);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Facade;

use App\Marketplace\Application\RecordConnectionAuthResultAction;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionAuthStatus;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Состояние аутентификации подключения — на живой БД.
 *
 * Переход выполняется одним атомарным оператором в репозитории, а не методами
 * сущности, поэтому проверять его на моках нечем: вся логика — порог, отметка
 * начала серии, обнуление успехом — живёт в SQL и проверяется только здесь.
 */
final class ConnectionAuthStateTest extends IntegrationTestCase
{
    private const THRESHOLD = RecordConnectionAuthResultAction::AUTH_FAILURE_THRESHOLD;
    private const COMPANY_A = '11111111-1111-1111-1111-0b0000000001';
    private const COMPANY_B = '11111111-1111-1111-1111-0b0000000002';
    private const CONNECTION_A = '22222222-2222-4222-8222-0b0000000001';
    private const CONNECTION_B = '22222222-2222-4222-8222-0b0000000002';

    private MarketplaceFacade $facade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facade = self::getContainer()->get(MarketplaceFacade::class);
    }

    public function testConnectionBreaksOnThresholdAndAppearsInBrokenList(): void
    {
        $this->seedConnection(self::COMPANY_A, self::CONNECTION_A, 'auth-a@example.test', 1);

        for ($i = 1; $i < self::THRESHOLD; ++$i) {
            self::assertFalse($this->facade->recordConnectorAuthFailure(self::COMPANY_A, self::CONNECTION_A));
            self::assertSame([], $this->facade->getBrokenConnections(self::COMPANY_A));
        }

        self::assertTrue($this->facade->recordConnectorAuthFailure(self::COMPANY_A, self::CONNECTION_A));

        $broken = $this->facade->getBrokenConnections(self::COMPANY_A);
        self::assertCount(1, $broken);
        self::assertSame(self::CONNECTION_A, $broken[0]['connectionId']);
        self::assertSame(MarketplaceType::OZON->value, $broken[0]['marketplace']);
        self::assertInstanceOf(\DateTimeImmutable::class, $broken[0]['authFailedAt']);
    }

    /**
     * Переход — событие, а не состояние: по нему пишется один агрегированный
     * `error`. Отказы после поломки новым событием не являются, иначе канал
     * алертов получал бы запись каждые полчаса, пока ключ не заменят.
     */
    public function testTransitionIsReportedExactlyOncePerSeries(): void
    {
        $this->seedConnection(self::COMPANY_A, self::CONNECTION_A, 'auth-a@example.test', 1);

        $transitions = 0;
        for ($i = 0; $i < self::THRESHOLD + 3; ++$i) {
            if ($this->facade->recordConnectorAuthFailure(self::COMPANY_A, self::CONNECTION_A)) {
                ++$transitions;
            }
        }

        self::assertSame(1, $transitions);
        self::assertSame(self::THRESHOLD + 3, $this->connection(self::CONNECTION_A)->getAuthFailureCount());
    }

    /**
     * В кабинете показывается дата, с которой данные перестали грузиться,
     * поэтому отметка обязана остаться на ПЕРВОМ отказе серии. Если бы каждый
     * следующий отказ её переписывал, баннер вечно сообщал бы «сломалось час
     * назад», сколько бы дней проблема ни висела.
     */
    public function testFailedAtMarksTheStartOfTheSeriesNotTheLatestFailure(): void
    {
        $this->seedConnection(self::COMPANY_A, self::CONNECTION_A, 'auth-a@example.test', 1);

        $this->facade->recordConnectorAuthFailure(self::COMPANY_A, self::CONNECTION_A);
        $firstFailureAt = $this->connection(self::CONNECTION_A)->getAuthFailedAt();
        self::assertNotNull($firstFailureAt);

        $this->facade->recordConnectorAuthFailure(self::COMPANY_A, self::CONNECTION_A);
        $this->facade->recordConnectorAuthFailure(self::COMPANY_A, self::CONNECTION_A);

        self::assertEquals($firstFailureAt, $this->connection(self::CONNECTION_A)->getAuthFailedAt());
    }

    public function testSuccessClearsBrokenState(): void
    {
        $this->seedConnection(self::COMPANY_A, self::CONNECTION_A, 'auth-a@example.test', 1);

        for ($i = 0; $i < self::THRESHOLD; ++$i) {
            $this->facade->recordConnectorAuthFailure(self::COMPANY_A, self::CONNECTION_A);
        }
        self::assertCount(1, $this->facade->getBrokenConnections(self::COMPANY_A));

        self::assertTrue($this->facade->recordConnectorAuthSuccess(self::COMPANY_A, self::CONNECTION_A));

        self::assertSame([], $this->facade->getBrokenConnections(self::COMPANY_A));
        $connection = $this->connection(self::CONNECTION_A);
        self::assertSame(MarketplaceConnectionAuthStatus::OK, $connection->getAuthStatus());
        self::assertSame(0, $connection->getAuthFailureCount());
        self::assertNull($connection->getAuthFailedAt());
    }

    /**
     * «Подряд» в пороге означает именно подряд: успех между отказами обнуляет
     * счётчик, иначе редкие разовые сбои за месяц накопились бы в ложную
     * поломку исправного подключения.
     */
    public function testSuccessResetsTheSeriesSoNextFailureStartsFromScratch(): void
    {
        $this->seedConnection(self::COMPANY_A, self::CONNECTION_A, 'auth-a@example.test', 1);

        for ($i = 1; $i < self::THRESHOLD; ++$i) {
            $this->facade->recordConnectorAuthFailure(self::COMPANY_A, self::CONNECTION_A);
        }
        $this->facade->recordConnectorAuthSuccess(self::COMPANY_A, self::CONNECTION_A);

        self::assertFalse($this->facade->recordConnectorAuthFailure(self::COMPANY_A, self::CONNECTION_A));

        $connection = $this->connection(self::CONNECTION_A);
        self::assertSame(1, $connection->getAuthFailureCount());
        self::assertSame(MarketplaceConnectionAuthStatus::OK, $connection->getAuthStatus());
    }

    /**
     * Успех приходит на каждом удачном обращении к API. Здоровое подключение
     * обязано выйти из него нетронутым, иначе каждая синхронизация писала бы
     * строку заново и `updatedAt` перестал бы означать «подключение меняли».
     */
    public function testSuccessOnHealthyConnectionWritesNothing(): void
    {
        $this->seedConnection(self::COMPANY_A, self::CONNECTION_A, 'auth-a@example.test', 1);
        $updatedAtBefore = $this->connection(self::CONNECTION_A)->getUpdatedAt();

        self::assertFalse($this->facade->recordConnectorAuthSuccess(self::COMPANY_A, self::CONNECTION_A));

        self::assertEquals($updatedAtBefore, $this->connection(self::CONNECTION_A)->getUpdatedAt());
    }

    /**
     * Подключение чужой компании недосягаемо и по записи, и по чтению: иначе
     * идентификатор подключения из чужого кабинета останавливал бы загрузку
     * соседа.
     */
    public function testConnectionOfAnotherCompanyIsNotTouched(): void
    {
        $this->seedConnection(self::COMPANY_A, self::CONNECTION_A, 'auth-a@example.test', 1);
        $this->seedConnection(self::COMPANY_B, self::CONNECTION_B, 'auth-b@example.test', 2);

        for ($i = 0; $i < self::THRESHOLD; ++$i) {
            // Верный id подключения, но чужая компания.
            self::assertFalse($this->facade->recordConnectorAuthFailure(self::COMPANY_A, self::CONNECTION_B));
        }

        self::assertSame([], $this->facade->getBrokenConnections(self::COMPANY_A));
        self::assertSame([], $this->facade->getBrokenConnections(self::COMPANY_B));
        $connection = $this->connection(self::CONNECTION_B);
        self::assertSame(MarketplaceConnectionAuthStatus::OK, $connection->getAuthStatus());
        self::assertSame(0, $connection->getAuthFailureCount());
    }

    public function testBrokenListIsScopedToTheAskingCompany(): void
    {
        $this->seedConnection(self::COMPANY_A, self::CONNECTION_A, 'auth-a@example.test', 1);
        $this->seedConnection(self::COMPANY_B, self::CONNECTION_B, 'auth-b@example.test', 2);

        for ($i = 0; $i < self::THRESHOLD; ++$i) {
            $this->facade->recordConnectorAuthFailure(self::COMPANY_B, self::CONNECTION_B);
        }

        self::assertSame([], $this->facade->getBrokenConnections(self::COMPANY_A));
        self::assertCount(1, $this->facade->getBrokenConnections(self::COMPANY_B));
    }

    public function testUnknownConnectionIsReportedAsNoTransition(): void
    {
        self::assertFalse($this->facade->recordConnectorAuthFailure(self::COMPANY_A, self::CONNECTION_A));
        self::assertFalse($this->facade->recordConnectorAuthSuccess(self::COMPANY_A, self::CONNECTION_A));
    }

    private function connection(string $connectionId): MarketplaceConnection
    {
        // Запись идёт мимо UnitOfWork, поэтому карта сущностей держала бы
        // устаревшую строку.
        $this->em->clear();
        $connection = $this->em->find(MarketplaceConnection::class, $connectionId);
        self::assertInstanceOf(MarketplaceConnection::class, $connection);

        return $connection;
    }

    private function seedConnection(string $companyId, string $connectionId, string $email, int $index): void
    {
        $owner = UserBuilder::aUser()->withIndex($index)->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();

        $connection = new MarketplaceConnection(
            $connectionId,
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('api-key');
        $connection->setClientId('client-id');

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->flush();
    }
}

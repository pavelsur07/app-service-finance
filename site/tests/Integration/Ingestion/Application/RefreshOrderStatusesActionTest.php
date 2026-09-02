<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Ingestion\Application\Action\RefreshOrderStatusesAction;
use App\Ingestion\Application\Command\RefreshOrderStatusesCommand;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Repository\IngestOrderRepository;
use App\Ingestion\Repository\IngestOrderStatusEventRepository;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Ingestion\IngestOrderBuilder;
use App\Tests\Integration\Ingestion\Fixtures\FakeOzonOrdersClient;
use App\Tests\Integration\Ingestion\Fixtures\FakeWbOrdersClient;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class RefreshOrderStatusesActionTest extends IntegrationTestCase
{
    private const CONNECTION_ID = '77777777-7777-7777-7777-0000000ff001';
    private const SECOND_CONNECTION_ID = '77777777-7777-7777-7777-0000000ff002';

    private RefreshOrderStatusesAction $action;
    private IngestOrderRepository $orders;
    private IngestOrderStatusEventRepository $events;
    private FakeOzonOrdersClient $ozon;
    private FakeWbOrdersClient $wb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = self::getContainer()->get(RefreshOrderStatusesAction::class);
        $this->orders = self::getContainer()->get(IngestOrderRepository::class);
        $this->events = self::getContainer()->get(IngestOrderStatusEventRepository::class);
        $this->ozon = self::getContainer()->get(FakeOzonOrdersClient::class);
        $this->wb = self::getContainer()->get(FakeWbOrdersClient::class);
    }

    /**
     * Ради этого цикл и существует: потоки заказов фильтруются по времени
     * СОЗДАНИЯ, поэтому заказ попадает в них один раз и его дальнейшие смены
     * статуса не увидит никто.
     */
    public function testMovesOzonOrderToItsNewStatusAndRecordsTheTransition(): void
    {
        $company = $this->seedCompanyWithConnection();
        $order = $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering');

        $this->ozon->setPostings(['posting-1' => ['posting_number' => 'posting-1', 'status' => 'delivered']]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, $result->polled);
        self::assertSame(1, $result->changed);

        $this->em->clear();
        $refreshed = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'posting-1');
        self::assertNotNull($refreshed);
        self::assertSame(IngestOrderStatus::DELIVERED, $refreshed->getStatus());
        self::assertSame('delivered', $refreshed->getRawStatus());

        // Переход зафиксирован ровно одной строкой журнала.
        self::assertSame(1, $this->events->countByOrder((string) $company->getId(), $order->getId()));
    }

    /**
     * Часовой опрос неизменного статуса не должен давать 24 одинаковые строки
     * в сутки на каждый заказ.
     */
    public function testUnchangedStatusWritesNoJournalRow(): void
    {
        $company = $this->seedCompanyWithConnection();
        $order = $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering');

        $this->ozon->setPostings(['posting-1' => ['posting_number' => 'posting-1', 'status' => 'delivering']]);

        ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));
        $second = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(0, $this->events->countByOrder((string) $company->getId(), $order->getId()));

        // «Опрошено» и «изменилось» — разные вопросы. Если успешный опрос
        // считать изменением, счётчик не сможет заметить именно то, ради чего
        // заведён: опрос идёт, а статусы стоят.
        self::assertSame(1, $second->polled);
        self::assertSame(0, $second->changed);
    }

    /**
     * Терминальный заказ перепрашивать незачем — именно это ограничивает
     * объём часового цикла.
     */
    public function testTerminalOrdersAreNotPolled(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'posting-1', IngestOrderStatus::DELIVERED, 'delivered');

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(0, $result->polled);
        self::assertSame([], $this->ozon->calls);
    }

    /**
     * Ozon не знает отправления — заказ мог быть удалён или номер устареть.
     * Это не повод ронять перепрос остальных.
     */
    public function testMissingPostingDoesNotBreakTheRun(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'gone', IngestOrderStatus::SHIPPED, 'delivering');
        $this->seedOrder($company, 'alive', IngestOrderStatus::SHIPPED, 'delivering');

        $this->ozon->setPostings(['alive' => ['posting_number' => 'alive', 'status' => 'delivered']]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, $result->polled);
        self::assertSame(1, $result->missing);

        $this->em->clear();
        $alive = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'alive');
        self::assertNotNull($alive);
        self::assertSame(IngestOrderStatus::DELIVERED, $alive->getStatus());
    }

    /**
     * Заказ, висящий в нетерминальном статусе дольше окна, опрашивать
     * бессмысленно — но и молча забывать нельзя: он уходит в видимую очередь.
     */
    public function testOrdersOlderThanTheWindowStopBeingPolledAndRaiseAnIssue(): void
    {
        $company = $this->seedCompanyWithConnection();
        $order = $this->seedOrder(
            $company,
            'ancient',
            IngestOrderStatus::SHIPPED,
            'delivering',
            new \DateTimeImmutable('-90 days'),
        );

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(0, $result->polled, 'Заказ вне окна не опрашивается.');
        self::assertSame(1, $result->stopped);

        // Проблема привязана к сырью, из которого заказ наблюдался последний
        // раз: у самой остановки своего payload нет, а разбирающему нужно с
        // чего-то начать.
        self::assertSame($order->getLastRawRecordId(), $this->connection->fetchOne(
            "SELECT raw_record_id FROM ingest_normalization_issues WHERE company_id = :c AND kind = 'stuck_order'",
            ['c' => (string) $company->getId()],
        ));

        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :c AND kind = 'stuck_order'",
            ['c' => (string) $company->getId()],
        ));

        // Повторный прогон не опрашивает и не помечает его снова.
        $second = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));
        self::assertSame(0, $second->stopped);
    }

    /**
     * Заказы разных кабинетов одной компании опрашиваются разными ключами:
     * спросить Ozon о чужом отправлении значило бы получить 404 на живом
     * заказе.
     */
    public function testOrdersOfAnotherConnectionAreNotPolled(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'other-cabinet', IngestOrderStatus::SHIPPED, 'delivering', null, 'another-connection');

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(0, $result->polled);
        self::assertSame([], $this->ozon->calls);
    }

    /**
     * Ответ маркетплейса сохраняется в raw ради аудита, но нормализации не
     * подлежит: маппера у ресурса нет, и запись, оставленная в очереди,
     * висела бы там вечно.
     */
    public function testRawAuditRecordIsStoredAndMarkedSkipped(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering');
        $this->ozon->setPostings(['posting-1' => ['posting_number' => 'posting-1', 'status' => 'delivered']]);

        ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        $row = $this->connection->fetchAssociative(
            "SELECT resource_type, normalization_status FROM ingest_raw_records WHERE company_id = :c AND resource_type = 'ozon_order_status_refresh'",
            ['c' => (string) $company->getId()],
        );

        self::assertIsArray($row);
        self::assertSame('skipped', $row['normalization_status']);
    }

    /**
     * У WB опрашиваются только заказы с номером marketplace-api: заказ,
     * известный лишь из потока изменений, спросить не у кого — эндпоинта
     * «статус по srid» не существует.
     */
    public function testWildberriesOrderWithoutMarketplaceIdIsNotPolled(): void
    {
        $company = $this->seedCompanyWithConnection(MarketplaceType::WILDBERRIES);
        $this->seedOrder(
            $company,
            'srid-only',
            IngestOrderStatus::ORDERED,
            'isCancel=false',
            null,
            null,
            IngestSource::WILDBERRIES,
        );

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(0, $result->polled);
        self::assertSame([], $this->wb->calls);
    }

    public function testWildberriesOrderWithMarketplaceIdIsPolled(): void
    {
        $company = $this->seedCompanyWithConnection(MarketplaceType::WILDBERRIES);
        $this->seedOrder(
            $company,
            'rid-1',
            IngestOrderStatus::ORDERED,
            'supplierStatus=new;wbStatus=waiting',
            null,
            null,
            IngestSource::WILDBERRIES,
            ['supplier_status' => 'new', 'wb_status' => 'waiting', 'is_cancellable' => true],
            '5000000001',
        );

        $this->wb->setStatuses([5000000001 => [
            'id' => 5000000001,
            'supplierStatus' => 'complete',
            'wbStatus' => 'sorted',
            'isCancellable' => false,
        ]]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, $result->polled);

        $this->em->clear();
        $order = $this->orders->findByExternalId((string) $company->getId(), IngestSource::WILDBERRIES, self::CONNECTION_ID, 'rid-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::SHIPPED, $order->getStatus());
        self::assertSame('supplierStatus=complete;wbStatus=sorted', $order->getRawStatus());

        // Статусные атрибуты обязаны ехать вместе со статусом: иначе заказ
        // показывал бы свежий статус рядом с прошлогодними осями.
        self::assertSame([
            'supplier_status' => 'complete',
            'wb_status' => 'sorted',
            'is_cancellable' => false,
        ], $order->getAttributes());
    }

    /**
     * Заказы, которые спросить не у кого, обязаны отсеиваться ДО лимита.
     * Иначе они, вечно первые в очереди (у них `statusObservedAt` навсегда
     * NULL), съедали бы лимит целиком, и пригодный заказ не опрашивался бы
     * никогда.
     */
    public function testWildberriesOrdersWithoutMarketplaceIdDoNotConsumeTheLimit(): void
    {
        $company = $this->seedCompanyWithConnection(MarketplaceType::WILDBERRIES);

        $this->seedOrder(
            $company,
            'srid-only',
            IngestOrderStatus::ORDERED,
            'isCancel=false',
            null,
            null,
            IngestSource::WILDBERRIES,
        );

        $this->seedOrder(
            $company,
            'rid-1',
            IngestOrderStatus::ORDERED,
            'supplierStatus=new;wbStatus=waiting',
            null,
            null,
            IngestSource::WILDBERRIES,
            null,
            '5000000001',
        );

        $this->wb->setStatuses([5000000001 => [
            'id' => 5000000001,
            'supplierStatus' => 'complete',
            'wbStatus' => 'sorted',
            'isCancellable' => false,
        ]]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 1));

        self::assertSame(1, $result->polled, 'Лимит достаётся заказу, который можно спросить.');
    }

    /**
     * Поздний 429 не должен обнулять уже полученные ответы: иначе каждый час
     * терялся бы весь прогресс подключения, а заказы в конце очереди не
     * обновлялись бы никогда.
     */
    public function testAlreadyFetchedPostingsSurviveAConnectionFailure(): void
    {
        $company = $this->seedCompanyWithConnection();
        $first = $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering', null, null, IngestSource::OZON, null, null, new \DateTimeImmutable('-3 hours'));
        $this->seedOrder($company, 'posting-2', IngestOrderStatus::SHIPPED, 'delivering', null, null, IngestSource::OZON, null, null, new \DateTimeImmutable('-1 hour'));

        $this->ozon->setPostings(['posting-1' => ['posting_number' => 'posting-1', 'status' => 'delivered']]);
        $this->ozon->setPostingFailures(['posting-2' => new ConnectorRateLimitedException('rate limited', 60)]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, $result->failedConnections);
        self::assertSame(1, $result->polled, 'Ответ, приехавший до сбоя, применяется.');

        $this->em->clear();
        $refreshed = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'posting-1');
        self::assertNotNull($refreshed);
        self::assertSame(IngestOrderStatus::DELIVERED, $refreshed->getStatus());
        self::assertSame(1, $this->events->countByOrder((string) $company->getId(), $first->getId()));
    }

    /**
     * Протухший ключ сам не пройдёт: он ждёт человека, а не следующего часа.
     * Поэтому он считается отдельно от 429 и таймаутов и даёт ненулевой
     * счётчик, по которому поднимается алерт.
     */
    public function testAuthFailureIsCountedApartFromRetryableOnes(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering');

        $this->ozon->setPostingFailures(['posting-1' => new ConnectorAuthException('expired key')]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, $result->authFailedConnections);
        self::assertSame(0, $result->failedConnections);
    }

    /**
     * Испорченный ответ — это не отсутствующий заказ. Его нужно и сохранить
     * как доказательство, и посчитать отдельно от честного 404.
     */
    public function testPostingWithoutStatusIsStoredAsRawAndCountedInvalid(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering');

        $this->ozon->setPostings(['posting-1' => ['posting_number' => 'posting-1']]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(0, $result->polled);
        self::assertSame(0, $result->missing);
        self::assertSame(1, $result->invalid);

        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_raw_records WHERE company_id = :c AND resource_type = 'ozon_order_status_refresh'",
            ['c' => (string) $company->getId()],
        ));
    }

    /**
     * Незнакомый токен статуса уходит в ту же видимую очередь, что и при
     * нормализации: одно доменное понятие — одно определение.
     */
    public function testUnknownStatusRaisesTheSameIssueAsNormalization(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering');

        $this->ozon->setPostings(['posting-1' => ['posting_number' => 'posting-1', 'status' => 'teleported']]);

        ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :c AND kind = 'unknown_order_status'",
            ['c' => (string) $company->getId()],
        ));
    }

    /**
     * Зависание не зависит от того, живо ли ещё подключение. Иначе
     * отключённый кабинет оставлял бы свои заказы вечно нетерминальными и
     * невидимыми: опрашивать их уже некому, пометить — тоже.
     */
    public function testStuckOrderOfAnInactiveConnectionIsStillStopped(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->deactivateConnections($company);

        $this->seedOrder(
            $company,
            'ancient',
            IngestOrderStatus::SHIPPED,
            'delivering',
            new \DateTimeImmutable('-90 days'),
        );

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, $result->stopped);
    }

    private function deactivateConnections(Company $company): void
    {
        $this->connection->executeStatement(
            'UPDATE marketplace_connections SET is_active = false WHERE company_id = :c',
            ['c' => (string) $company->getId()],
        );
    }

    /**
     * Зависшие заказы ищутся по компании, а не по подключению. Проход по
     * кабинетам выполнял бы этот запрос по разу на кабинет, а отметка об
     * остановке до конца прогона в базу не уходит — второй проход вернул бы
     * те же заказы и завёл вторую проблему на тот же заказ.
     */
    public function testStuckOrderIsStoppedOnceForCompanyWithSeveralConnections(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->addConnection($company, MarketplaceType::WILDBERRIES, self::SECOND_CONNECTION_ID);

        $this->seedOrder(
            $company,
            'ancient',
            IngestOrderStatus::SHIPPED,
            'delivering',
            new \DateTimeImmutable('-90 days'),
        );

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, $result->stopped, 'Заказ останавливается один раз, а не по разу на кабинет.');
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :c AND kind = 'stuck_order'",
            ['c' => (string) $company->getId()],
        ));
    }

    private function addConnection(Company $company, MarketplaceType $marketplace, string $id): void
    {
        $connection = new MarketplaceConnection(
            id: $id,
            company: $company,
            marketplace: $marketplace,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key');
        $connection->setClientId('test-client-id');
        $connection->setIsActive(true);

        $this->em->persist($connection);
        $this->em->flush();
    }

    private function seedCompanyWithConnection(MarketplaceType $marketplace = MarketplaceType::OZON): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('refresh-statuses-'.Uuid::uuid4()->toString().'@example.com');
        $user->setPassword('password');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Refresh Statuses Company');

        $connection = new MarketplaceConnection(
            id: self::CONNECTION_ID,
            company: $company,
            marketplace: $marketplace,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key');
        $connection->setClientId('test-client-id');
        $connection->setIsActive(true);

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->flush();

        return $company;
    }

    /**
     * @param array<string, mixed>|null $attributes
     */
    private function seedOrder(
        Company $company,
        string $externalId,
        IngestOrderStatus $status,
        string $rawStatus,
        ?\DateTimeImmutable $orderedAt = null,
        ?string $connectionRef = null,
        IngestSource $source = IngestSource::OZON,
        ?array $attributes = null,
        ?string $externalOrderId = null,
        ?\DateTimeImmutable $statusObservedAt = null,
    ): \App\Ingestion\Entity\IngestOrder {
        $builder = IngestOrderBuilder::anOrder()
            ->forCompany((string) $company->getId())
            ->withConnectionRef($connectionRef ?? self::CONNECTION_ID)
            ->withSource($source)
            ->withScheme(IngestOrderScheme::FBO)
            ->withExternalId($externalId)
            ->withExternalOrderId($externalOrderId)
            ->withStatus($status, $rawStatus)
            ->orderedAt($orderedAt ?? new \DateTimeImmutable('-2 days'));

        if (null !== $statusObservedAt) {
            $builder = $builder->statusObservedAt($statusObservedAt);
        }

        if (null !== $attributes) {
            $builder = $builder->withAttributes($attributes);
        }

        $order = $builder->build();
        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }
}

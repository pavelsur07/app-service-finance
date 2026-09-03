<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Ingestion\Application\Action\RecordNormalizationIssueAction;
use App\Ingestion\Application\Action\RefreshOrderStatusesAction;
use App\Ingestion\Application\Command\RefreshOrderStatusesCommand;
use App\Ingestion\Application\Service\OrderStatusJournal;
use App\Ingestion\Domain\Service\IngestOrderStatusMapper;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Exception\MalformedConnectorResponseException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestOrderRepository;
use App\Ingestion\Repository\IngestOrderStatusEventRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceSyncFacade;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Builders\Ingestion\IngestOrderBuilder;
use App\Tests\Integration\Ingestion\Fixtures\FakeOzonOrdersClient;
use App\Tests\Integration\Ingestion\Fixtures\FakeWbOrdersClient;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
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

        self::assertSame(1, $result->observed);
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
        self::assertSame(1, $second->observed);
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

        self::assertSame(0, $result->observed);
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

        self::assertSame(1, $result->observed);
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

        self::assertSame(0, $result->observed, 'Заказ вне окна не опрашивается.');
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

        self::assertSame(0, $result->observed);
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

        self::assertSame(0, $result->observed);
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

        self::assertSame(1, $result->observed);

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

        self::assertSame(1, $result->observed, 'Лимит достаётся заказу, который можно спросить.');
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
        self::assertSame(1, $result->observed, 'Ответ, приехавший до сбоя, применяется.');

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

        self::assertSame(0, $result->observed);
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

    /**
     * Регрессия к BLOCKER: очередь планировалась по времени НАБЛЮДЕНИЯ, а 404
     * его не двигает. Заказы, на которые Ozon отвечает 404, вечно занимали
     * начало лимита, и живой заказ кабинета не опрашивался никогда.
     */
    public function testOrdersAnsweredWith404DoNotHoldTheQueueForever(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'gone', IngestOrderStatus::SHIPPED, 'delivering');
        $this->seedOrder($company, 'alive', IngestOrderStatus::SHIPPED, 'delivering');

        $this->ozon->setPostings(['alive' => ['posting_number' => 'alive', 'status' => 'delivered']]);

        // Лимит в один заказ: на первом прогоне его получает один из двух.
        ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 1));
        $second = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 1));

        // Кто бы ни попал в первый прогон, на втором очередь обязана сдвинуться.
        self::assertSame(1, $second->observed + $second->missing);

        $this->em->clear();
        $alive = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'alive');
        self::assertNotNull($alive);
        self::assertSame(IngestOrderStatus::DELIVERED, $alive->getStatus(), 'Живой заказ дождался своей очереди.');
    }

    /**
     * Испорченный ответ относится к ОДНОМУ отправлению. Прерывать из-за него
     * цикл значило бы, что одно вечно кривое отправление каждый час
     * останавливает обработку всех следующих заказов кабинета.
     */
    public function testMalformedPostingDoesNotStopTheRestOfTheConnection(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'broken', IngestOrderStatus::SHIPPED, 'delivering', null, null, IngestSource::OZON, null, null, new \DateTimeImmutable('-3 hours'));
        $this->seedOrder($company, 'alive', IngestOrderStatus::SHIPPED, 'delivering', null, null, IngestSource::OZON, null, null, new \DateTimeImmutable('-1 hour'));

        $this->ozon->setPostingFailures(['broken' => new MalformedConnectorResponseException('bad shape')]);
        $this->ozon->setPostings(['alive' => ['posting_number' => 'alive', 'status' => 'delivered']]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(0, $result->failedConnections, 'Кривое отправление — не сбой подключения.');
        self::assertSame(1, $result->invalid);
        self::assertSame(1, $result->observed);

        $this->em->clear();
        $alive = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'alive');
        self::assertNotNull($alive);
        self::assertSame(IngestOrderStatus::DELIVERED, $alive->getStatus());
    }

    /**
     * Ответа не было вовсе, поэтому заказы не «неизвестны маркетплейсу» — их
     * просто не спросили. Считать их missing значило бы объявить сбой сети
     * свойством данных.
     */
    public function testWildberriesRateLimitDoesNotDeclareOrdersMissing(): void
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
            null,
            '5000000001',
        );

        $this->wb->failStatusesWith(new ConnectorRateLimitedException('rate limited', 60));

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, $result->failedConnections);
        self::assertSame(0, $result->missing);
    }

    /**
     * Номер маркетплейса обязан быть КАНОНИЧЕСКИМ: «05» — не «5».
     *
     * Дальше строка становится ключом `int`, а поиск коллизий сравнивает
     * строки. «5» и «05» расходились ровно в этих двух местах: дублями они не
     * считались, а ключ давали один — и один заказ молча затирал другой.
     * Затёртый не получал ни наблюдения, ни отметки попытки и вечно
     * возвращался в начало очереди.
     */
    public function testNonCanonicalMarketplaceIdIsRejectedInsteadOfCollapsingOntoAnother(): void
    {
        $company = $this->seedCompanyWithConnection(MarketplaceType::WILDBERRIES);

        $canonical = $this->seedOrder(
            $company, 'rid-canonical', IngestOrderStatus::ORDERED, 'supplierStatus=new;wbStatus=waiting',
            null, null, IngestSource::WILDBERRIES, null, '5000000001',
        );
        $padded = $this->seedOrder(
            $company, 'rid-padded', IngestOrderStatus::ORDERED, 'supplierStatus=new;wbStatus=waiting',
            null, null, IngestSource::WILDBERRIES, null, '05000000001',
        );

        $this->wb->setStatuses([5000000001 => [
            'id' => 5000000001,
            'supplierStatus' => 'complete',
            'wbStatus' => 'sorted',
            'isCancellable' => false,
        ]]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, $result->observed, 'Спросить можно только канонический номер.');
        self::assertSame(1, $result->invalid, 'Неканонический номер обязан быть посчитан браком, а не потерян.');

        $this->em->clear();

        $polled = $this->orders->findByExternalId((string) $company->getId(), IngestSource::WILDBERRIES, self::CONNECTION_ID, 'rid-canonical');
        self::assertNotNull($polled);
        self::assertSame(IngestOrderStatus::SHIPPED, $polled->getStatus());

        $skipped = $this->orders->findByExternalId((string) $company->getId(), IngestSource::WILDBERRIES, self::CONNECTION_ID, 'rid-padded');
        self::assertNotNull($skipped);
        self::assertSame(IngestOrderStatus::ORDERED, $skipped->getStatus(), 'Чужой статус ему не приписывается.');
        self::assertNotNull(
            $skipped->getStatusRefreshAttemptedAt(),
            'Без отметки попытки он вечно занимал бы начало очереди.',
        );

        self::assertNotSame($canonical->getId(), $padded->getId());
    }

    /**
     * Сбой записи наблюдений делает возможную сироту ВИДИМОЙ — и не трогает её.
     *
     * Объект аудита пишется до коммита, а исход коммита при падении неизвестен:
     * PostgreSQL мог зафиксировать строку, а клиент потерять подтверждение.
     * Удалять объект в таком состоянии значило бы оставить живую запись без
     * нагрузки и без отметки — необратимая потеря. Поэтому объект остаётся,
     * а его путь уходит в error: убрать сироту может человек, убедившись, что
     * строки на неё нет. По базе её иначе не найти — retention ищет
     * кандидатов среди строк.
     */
    public function testAuditObjectIsReportedNotDeletedWhenWritingObservationsFails(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering');

        $this->ozon->setPostings(['posting-1' => ['posting_number' => 'posting-1', 'status' => 'delivered']]);

        /** @var ObjectStorageInterface $storage */
        $storage = self::getContainer()->get(ObjectStorageInterface::class);

        // Транзакция записи наблюдений падает уже ПОСЛЕ того, как объект
        // аудита записан: ровно то окно, о котором идёт речь.
        $realEntityManager = $this->em;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $realEntityManager->wrapInTransaction(
                static function (EntityManagerInterface $em) use ($work): void {
                    $work($em);

                    throw new \RuntimeException('observation write failed');
                },
            ),
        );
        $entityManager->method('flush')->willReturnCallback(static fn () => $realEntityManager->flush());

        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: mixed[]}> */
            public array $records = [];

            /**
             * @param mixed[] $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $action = new RefreshOrderStatusesAction(
            self::getContainer()->get(MarketplaceSyncFacade::class),
            self::getContainer()->get(IngestOrderRepository::class),
            $this->ozon,
            $this->wb,
            self::getContainer()->get(IngestOrderStatusMapper::class),
            self::getContainer()->get(OrderStatusJournal::class),
            self::getContainer()->get(RawStorageFacade::class),
            self::getContainer()->get(IngestRawRecordRepository::class),
            self::getContainer()->get(RecordNormalizationIssueAction::class),
            $entityManager,
            self::getContainer()->get(ClockInterface::class),
            $logger,
        );

        try {
            $action(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));
            self::fail('Запись наблюдений обязана упасть.');
        } catch (\RuntimeException) {
            // Ожидаемо: падение и есть предмет проверки.
        }

        $reports = array_values(array_filter(
            $logger->records,
            static fn (array $entry): bool => LogLevel::ERROR === $entry['level']
                && str_contains($entry['message'], 'may be orphaned'),
        ));
        self::assertCount(1, $reports, 'Возможная сирота обязана попасть в error.');

        /** @var list<string> $paths */
        $paths = $reports[0]['context']['storagePaths'];
        self::assertCount(1, $paths);
        self::assertTrue(
            $storage->exists($paths[0]),
            'Объект остаётся: исход коммита неизвестен, а удаление необратимо.',
        );
    }

    /**
     * Пробельный номер отправления не прерывает прогон.
     *
     * Клиент на пустой номер бросает InvalidArgumentException, и без проверки
     * до вызова один испорченный идентификатор из базы прерывал всё:
     * накопленные ответы не применялись, следующие подключения не
     * обрабатывались, а сам заказ отметки попытки не получал и вечно стоял
     * первым в очереди. Испорчен один заказ — прочие продолжают.
     */
    public function testBlankPostingNumberIsCountedInvalidAndTheRunGoesOn(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'before', IngestOrderStatus::SHIPPED, 'delivering');
        $blank = $this->seedOrder($company, '   ', IngestOrderStatus::SHIPPED, 'delivering');
        $this->seedOrder($company, 'after', IngestOrderStatus::SHIPPED, 'delivering');

        $this->ozon->setPostings([
            'before' => ['posting_number' => 'before', 'status' => 'delivered'],
            'after' => ['posting_number' => 'after', 'status' => 'delivered'],
        ]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(2, $result->observed, 'Соседи испорченного заказа обязаны быть опрошены.');
        self::assertSame(1, $result->invalid);

        $this->em->clear();
        $reloaded = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, '   ');
        self::assertNotNull($reloaded);
        self::assertSame($blank->getId(), $reloaded->getId());
        self::assertNotNull(
            $reloaded->getStatusRefreshAttemptedAt(),
            'Без отметки попытки испорченный заказ вечно стоял бы первым в очереди.',
        );
    }

    /**
     * Непригодное уточнение не отбирает у заказа настоящий статус.
     *
     * `substatus` — уточнение, в нормализацию не попадает. Пока непригодное
     * уточнение отклоняло весь ответ, валидный переход — в том числе
     * терминальный — не попадал ни в заказ, ни в журнал, а отметка попытки
     * при этом сдвигалась: заказ доезжал до STUCK_ORDER из-за поля, которое
     * на статус не влияет. Принятое пустое уточнение обязано ещё и стереть
     * прежнее: `delivered` рядом с `posting_on_way_to_city` — противоречие,
     * которого маркетплейс не присылал.
     *
     * @param mixed $substatus то, что пришло в поле `substatus`
     */
    #[DataProvider('unusableSubstatusProvider')]
    public function testUnusableSubstatusDoesNotDiscardATerminalStatus(mixed $substatus): void
    {
        $company = $this->seedCompanyWithConnection();
        $order = $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering');

        // Прежнее уточнение — чтобы было что стирать.
        $this->connection->executeStatement(
            'UPDATE ingest_orders SET raw_substatus = :substatus WHERE id = :id',
            ['substatus' => 'posting_on_way_to_city', 'id' => $order->getId()],
        );
        $this->em->clear();

        $posting = ['posting_number' => 'posting-1', 'status' => 'delivered'];
        if ('__absent__' !== $substatus) {
            $posting['substatus'] = $substatus;
        }
        $this->ozon->setPostings(['posting-1' => $posting]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, $result->observed, 'Статус обязан быть принят.');
        self::assertSame(0, $result->invalid, 'Наблюдение состоялось — это не брак.');

        $this->em->clear();
        $reloaded = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'posting-1');
        self::assertNotNull($reloaded);
        self::assertSame(IngestOrderStatus::DELIVERED, $reloaded->getStatus());
        self::assertNull($reloaded->getRawSubstatus(), 'Прежнее уточнение не должно пережить новое наблюдение.');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableSubstatusProvider(): iterable
    {
        yield 'пустая строка' => [''];
        yield 'null' => [null];
        yield 'число' => [42];
        yield 'поля нет' => ['__absent__'];
    }

    /**
     * Незнакомый токен попадает в очередь, даже если проиграл гонку.
     *
     * Наблюдение, оказавшееся старше уже записанного, состояние заказа не
     * двигает, но в журнал попадает — и незнакомый токен в нём такой же
     * настоящий. Пока проблема заводилась только при изменении состояния,
     * такой токен оставался лишь в журнале: если победившее наблюдение сделало
     * заказ терминальным, второй попытки не будет никогда, и сломанный
     * контракт API навсегда оставался бы незамеченным.
     */
    public function testUnknownStatusThatLostTheRaceStillReachesTheReviewQueue(): void
    {
        $company = $this->seedCompanyWithConnection();

        // Заказ уже наблюдался ПОЗЖЕ, чем ответ, который придёт сейчас.
        $this->seedOrder(
            $company,
            'posting-1',
            IngestOrderStatus::SHIPPED,
            'delivering',
            null,
            null,
            IngestSource::OZON,
            null,
            null,
            new \DateTimeImmutable('+1 hour'),
        );

        $this->ozon->setPostings(['posting-1' => [
            'posting_number' => 'posting-1',
            'status' => 'совершенно_незнакомый_статус',
        ]]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(0, $result->changed, 'Устаревшее наблюдение состояние не двигает.');

        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :c AND kind = 'unknown_order_status'",
            ['c' => (string) $company->getId()],
        ), 'Незнакомый токен обязан быть виден, даже если наблюдение проиграло.');
    }

    /**
     * Заказ, чьё сырьё исчезло, останавливается, а не откладывается вечно.
     *
     * Указатель тот же, а строки нет — она и не появится. Пока такой заказ
     * откладывался, он старейший, и при малом лимите очередь зависших вечно
     * начиналась с него: остальные не останавливались никогда.
     */
    public function testStuckOrderWhoseEvidenceRowVanishedIsStoppedNotDeferred(): void
    {
        $company = $this->seedCompanyWithConnection();
        $order = $this->seedOrder(
            $company,
            'ancient',
            IngestOrderStatus::SHIPPED,
            'delivering',
            new \DateTimeImmutable('-90 days'),
        );

        // Строка сырья исчезает, а указатель на неё остаётся.
        $this->connection->executeStatement(
            'DELETE FROM ingest_raw_records WHERE id = :id',
            ['id' => (string) $order->getLastRawRecordId()],
        );

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 1));

        self::assertSame(1, $result->stopped);

        $this->em->clear();
        $reloaded = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'ancient');
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getRefreshStoppedAt(), 'Иначе он вечно занимал бы начало очереди.');

        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :c AND kind = 'stuck_order'",
            ['c' => (string) $company->getId()],
        ), 'Привязать проблему не к чему — разбирать придётся по логу.');
    }

    /**
     * Граница — `PHP_INT_MAX`, а не выдуманное число цифр.
     *
     * Ровно 18 цифр отвергали бы настоящий девятнадцатизначный номер навсегда,
     * доводя заказ до STUCK_ORDER. А переполнение молча даёт `PHP_INT_MAX`, и
     * обратное преобразование — единственная точная проверка того, что число
     * вообще влезло.
     *
     * @param string $externalOrderId номер маркетплейса
     */
    #[DataProvider('marketplaceIdBoundaryProvider')]
    public function testMarketplaceIdBoundaryIsIntMaxNotADigitCount(string $externalOrderId, bool $usable): void
    {
        $company = $this->seedCompanyWithConnection(MarketplaceType::WILDBERRIES);
        $this->seedOrder(
            $company, 'rid-1', IngestOrderStatus::ORDERED, 'supplierStatus=new;wbStatus=waiting',
            null, null, IngestSource::WILDBERRIES, null, $externalOrderId,
        );

        $this->wb->setStatuses([(int) $externalOrderId => [
            'id' => (int) $externalOrderId,
            'supplierStatus' => 'complete',
            'wbStatus' => 'sorted',
            'isCancellable' => false,
        ]]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame($usable ? 1 : 0, $result->observed);
        self::assertSame($usable ? 0 : 1, $result->invalid);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function marketplaceIdBoundaryProvider(): iterable
    {
        yield 'PHP_INT_MAX' => [(string) \PHP_INT_MAX, true];
        yield 'на единицу больше PHP_INT_MAX' => ['9223372036854775808', false];
    }

    /**
     * Заказ без сырья останавливается ТОЖЕ — и об этом кричат `error`-ом.
     *
     * Прежде он не останавливался «чтобы не пропал молча», но получалось ровно
     * наоборот: окно опроса его уже не захватывало, а выборка зависших
     * отсеивала, — заказ не обновлялся, не останавливался и никуда не попадал.
     * Оставался только счётчик, из которого нельзя было сделать ничего.
     * Состояние, помеченное плохим, обязано иметь операцию, переводящую его в
     * хорошее.
     */
    public function testStuckOrderWithoutRawRecordIsStoppedAndReportedAsAnError(): void
    {
        $company = $this->seedCompanyWithConnection();
        $order = IngestOrderBuilder::anOrder()
            ->forCompany((string) $company->getId())
            ->withConnectionRef(self::CONNECTION_ID)
            ->withSource(IngestSource::OZON)
            ->withScheme(IngestOrderScheme::FBO)
            ->withExternalId('orphan')
            ->withStatus(IngestOrderStatus::SHIPPED, 'delivering')
            ->orderedAt(new \DateTimeImmutable('-90 days'))
            ->withLastRawRecordId(null)
            ->build();
        $this->em->persist($order);
        $this->em->flush();

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, $result->stopped);

        $this->em->clear();
        $reloaded = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'orphan');
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getRefreshStoppedAt(), 'Заказ обязан быть остановлен: опрашивать его всё равно некому.');

        // Очереди на разбор нет — привязать её не к чему, — поэтому разбирать
        // придётся по логу, и уровень обязан быть ERROR: заказ всегда
        // создаётся нормализацией, значит сырьё у него быть должно.
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :c AND kind = 'stuck_order'",
            ['c' => (string) $company->getId()],
        ));
    }

    /**
     * Регрессия: одна отметка на всё подключение. Ozon опрашивается по одному
     * отправлению, до тысячи последовательных запросов, и общая отметка
     * приписывала первому ответу время последнего — тогда наблюдение,
     * пришедшее раньше конкурентной нормализации, выигрывало бы у неё.
     */
    public function testEachResponseCarriesItsOwnObservationTime(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering', null, null, IngestSource::OZON, null, null, new \DateTimeImmutable('-3 hours'));
        $this->seedOrder($company, 'posting-2', IngestOrderStatus::SHIPPED, 'delivering', null, null, IngestSource::OZON, null, null, new \DateTimeImmutable('-2 hours'));

        $this->ozon->setPostings([
            'posting-1' => ['posting_number' => 'posting-1', 'status' => 'delivered'],
            'posting-2' => ['posting_number' => 'posting-2', 'status' => 'delivered'],
        ]);

        ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        $this->em->clear();
        $first = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'posting-1');
        $second = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'posting-2');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotNull($first->getStatusObservedAt());
        self::assertNotNull($second->getStatusObservedAt());
        self::assertGreaterThan(
            $first->getStatusObservedAt()->getTimestamp(),
            $second->getStatusObservedAt()->getTimestamp(),
            'Ответ, пришедший позже, обязан нести более позднюю отметку.',
        );
    }

    /**
     * Испорченный, но разобранный ответ — как раз то, ради чего аудит и нужен.
     * Выброшенный, он не объясняет ничего.
     */
    public function testMalformedPostingResponseIsStoredAsEvidence(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'broken', IngestOrderStatus::SHIPPED, 'delivering');

        $this->ozon->setPostingFailures(['broken' => new MalformedConnectorResponseException(
            'no result object',
            decodedPayload: ['result' => [], 'message' => 'nothing here'],
        )]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, $result->invalid);
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_raw_records WHERE company_id = :c AND resource_type = 'ozon_order_status_refresh'",
            ['c' => (string) $company->getId()],
        ));
    }

    /**
     * Схема выбирает эндпоинт. Заказ с неизвестной схемой спросить нечем:
     * отправив его в FBO «по умолчанию», мы получили бы ложный 404 и молча
     * оставили заказ без обновлений до STUCK_ORDER.
     */
    public function testOrderWithUnknownSchemeIsNotPolled(): void
    {
        $company = $this->seedCompanyWithConnection();
        $order = IngestOrderBuilder::anOrder()
            ->forCompany((string) $company->getId())
            ->withConnectionRef(self::CONNECTION_ID)
            ->withSource(IngestSource::OZON)
            ->withScheme(IngestOrderScheme::UNKNOWN)
            ->withExternalId('schemeless')
            ->withStatus(IngestOrderStatus::SHIPPED, 'delivering')
            ->orderedAt(new \DateTimeImmutable('-2 days'))
            ->build();
        $this->em->persist($order);
        $this->em->flush();

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame([], $this->ozon->calls, 'Без схемы запрос не отправляется.');
        self::assertSame(1, $result->invalid);
        self::assertSame(0, $result->observed);
    }

    /**
     * «Не NULL» ещё не значит «номер». Нечисловой идентификатор спросить
     * нельзя, и без отметки попытки такой заказ вечно занимал бы начало
     * очереди — живые заказы кабинета не опрашивались бы никогда.
     */
    public function testWildberriesOrderWithNonNumericIdDoesNotConsumeTheLimit(): void
    {
        $company = $this->seedCompanyWithConnection(MarketplaceType::WILDBERRIES);

        $this->seedOrder(
            $company,
            'broken-id',
            IngestOrderStatus::ORDERED,
            'supplierStatus=new;wbStatus=waiting',
            null,
            null,
            IngestSource::WILDBERRIES,
            null,
            'not-a-number',
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

        // Первый прогон с лимитом в один заказ достаётся нечисловому: он
        // старше по отметке попытки (её нет вовсе).
        ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 1));
        $second = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 1));

        self::assertSame(1, $second->observed, 'На втором прогоне очередь сдвинулась к спрашиваемому заказу.');
    }

    /**
     * Ответ БЫЛ, он нарушает контракт. Это дефект интеграции, а не сбой
     * подключения: постоянно кривая пачка иначе каждый час занимала бы начало
     * очереди.
     */
    public function testMalformedWildberriesStatusResponseAdvancesTheQueue(): void
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
            null,
            '5000000001',
        );

        $this->wb->failStatusesWith(new MalformedConnectorResponseException(
            'bad shape',
            decodedPayload: ['error' => 'unexpected'],
        ));

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(0, $result->failedConnections, 'Кривой ответ — не сбой подключения.');
        self::assertSame(1, $result->invalid);
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_raw_records WHERE company_id = :c AND resource_type = 'wildberries_order_status_refresh'",
            ['c' => (string) $company->getId()],
        ));

        $this->em->clear();
        $order = $this->orders->findByExternalId((string) $company->getId(), IngestSource::WILDBERRIES, self::CONNECTION_ID, 'rid-1');
        self::assertNotNull($order);
        self::assertNotNull($order->getStatusRefreshAttemptedAt(), 'Пачку спросили — попытка засчитана.');
    }

    /**
     * Заказ без сырья не блокирует очередь остановки: он тоже останавливается.
     *
     * Он старейший, а выборка идёт по возрастанию даты заказа, поэтому при
     * лимите в одну запись он всегда первый. Пока его пропускали, лимит
     * тратился впустую каждый прогон, и остальные зависшие не останавливались
     * никогда.
     */
    public function testOrphanStuckOrderDoesNotBlockTheStuckQueue(): void
    {
        $company = $this->seedCompanyWithConnection();

        $orphan = IngestOrderBuilder::anOrder()
            ->forCompany((string) $company->getId())
            ->withConnectionRef(self::CONNECTION_ID)
            ->withSource(IngestSource::OZON)
            ->withScheme(IngestOrderScheme::FBO)
            ->withExternalId('orphan')
            ->withStatus(IngestOrderStatus::SHIPPED, 'delivering')
            ->orderedAt(new \DateTimeImmutable('-200 days'))
            ->withLastRawRecordId(null)
            ->build();
        $this->em->persist($orphan);

        $this->seedOrder(
            $company,
            'ancient',
            IngestOrderStatus::SHIPPED,
            'delivering',
            new \DateTimeImmutable('-90 days'),
        );
        $this->em->flush();

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 1));

        // Лимит достаётся СТАРЕЙШЕМУ — сироте: он тоже останавливается, просто
        // без записи в очереди. Прежде он занимал место и не двигался, и весь
        // лимит уходил впустую прогон за прогоном.
        self::assertSame(1, $result->stopped);
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :c AND kind = 'stuck_order'",
            ['c' => (string) $company->getId()],
        ));

        $this->em->clear();
        $reloaded = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'orphan');
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getRefreshStoppedAt(), 'Сирота обязан уйти из очереди, а не занимать её вечно.');
    }

    /**
     * Проверка ПЕРЕЧИТЫВАНИЯ под блокировкой.
     *
     * Заказы выбираются до внешних HTTP-запросов, и за время опроса
     * нормализатор мог записать более свежее наблюдение. Doctrine считает
     * изменения от значений, прочитанных ДО опроса, поэтому без
     * `HINT_REFRESH` блокировка защитила бы строку в базе, оставив в памяти
     * устаревшее состояние — и финальный flush откатил бы статус назад.
     *
     * Конкурентная запись эмулируется прямым UPDATE мимо Doctrine: сущность
     * уже в карте идентичности, и это ровно то расхождение, которое возникает
     * между двумя процессами.
     */
    public function testConcurrentNewerObservationIsNotOverwritten(): void
    {
        $company = $this->seedCompanyWithConnection();
        $order = $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering');

        // «Нормализатор» записал наблюдение из будущего и закоммитил его.
        $this->connection->executeStatement(
            'UPDATE ingest_orders SET status_observed_at = :observedAt WHERE id = :id',
            ['observedAt' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s.u'), 'id' => $order->getId()],
        );

        $this->ozon->setPostings(['posting-1' => ['posting_number' => 'posting-1', 'status' => 'delivered']]);

        ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        $this->em->clear();
        $refreshed = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'posting-1');
        self::assertNotNull($refreshed);
        self::assertSame(
            IngestOrderStatus::SHIPPED,
            $refreshed->getStatus(),
            'Наблюдение старше уже записанного не двигает статус.',
        );
    }

    /**
     * Незнакомый токен статуса — одна проблема, а не по одной в час.
     *
     * Часовой опрос неизменного неизвестного статуса плодил бы до 720 копий на
     * заказ за окно опроса, и очередь на разбор превращалась бы в шум, в
     * котором настоящие проблемы не найти.
     */
    public function testUnknownStatusRaisesTheIssueOnceNotEveryHour(): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering');

        $this->ozon->setPostings(['posting-1' => ['posting_number' => 'posting-1', 'status' => 'teleported']]);

        ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));
        ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));
        ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :c AND kind = 'unknown_order_status'",
            ['c' => (string) $company->getId()],
        ));
    }

    /**
     * Два наших заказа с одним номером маркетплейса — дефект данных. Молча
     * потерянный при индексации заказ не получал бы ни наблюдения, ни отметки
     * попытки и вечно возвращался бы в начало очереди.
     */
    public function testOrdersSharingOneMarketplaceIdDoNotStarveTheQueue(): void
    {
        $company = $this->seedCompanyWithConnection(MarketplaceType::WILDBERRIES);

        // Три, а не два: обработка коллизии удаляла номер из выборки, и
        // ТРЕТИЙ заказ с тем же номером попадал туда снова — один из
        // конфликтующих произвольно получал чужой статус.
        foreach (['rid-a', 'rid-b', 'rid-c'] as $externalId) {
            $this->seedOrder(
                $company,
                $externalId,
                IngestOrderStatus::ORDERED,
                'supplierStatus=new;wbStatus=waiting',
                null,
                null,
                IngestSource::WILDBERRIES,
                null,
                '5000000001',
            );
        }

        // Лимит в один заказ: коллизия обязана обнаруживаться по всему
        // подключению, а не внутри страницы очереди — иначе носители одного
        // номера попадали бы в разные прогоны и не встречались никогда.
        $first = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 1));
        self::assertSame(1, $first->invalid);
        self::assertSame([], $this->wb->calls, 'Приписать один ответ нескольким заказам нельзя.');

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(3, $result->invalid);
        self::assertSame([], $this->wb->calls, 'Приписать один ответ нескольким заказам нельзя.');

        $this->em->clear();
        foreach (['rid-a', 'rid-b', 'rid-c'] as $externalId) {
            $order = $this->orders->findByExternalId((string) $company->getId(), IngestSource::WILDBERRIES, self::CONNECTION_ID, $externalId);
            self::assertNotNull($order);
            self::assertNotNull($order->getStatusRefreshAttemptedAt(), $externalId.' обязан получить отметку попытки.');
        }
    }

    /**
     * Число вместо строки — не статус. Приняв его, цикл записал бы заказу
     * UNKNOWN и сдвинул отметку наблюдения, закрыв дорогу настоящему статусу.
     */
    #[DataProvider('unusableStatusTokenProvider')]
    public function testUnusableStatusTokenIsInvalidRatherThanAnObservation(mixed $status): void
    {
        $company = $this->seedCompanyWithConnection();
        $this->seedOrder($company, 'posting-1', IngestOrderStatus::SHIPPED, 'delivering');

        $this->ozon->setPostings(['posting-1' => ['posting_number' => 'posting-1', 'status' => $status]]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(0, $result->observed);
        self::assertSame(1, $result->invalid);

        $this->em->clear();
        $order = $this->orders->findByExternalId((string) $company->getId(), IngestSource::OZON, self::CONNECTION_ID, 'posting-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::SHIPPED, $order->getStatus(), 'Статус остаётся прежним.');
        self::assertNotNull($order->getStatusRefreshAttemptedAt(), 'Но попытка засчитана — очередь двигается.');
    }

    /**
     * @return iterable<string, array{status: mixed}>
     */
    public static function unusableStatusTokenProvider(): iterable
    {
        yield 'numeric' => ['status' => 123];
        // Длиннее колонки: запись уронила бы транзакцию и утащила за собой
        // все остальные заказы подключения.
        yield 'longer than the column' => ['status' => str_repeat('x', 256)];
        yield 'blank' => ['status' => '   '];
    }

    /**
     * Одна повреждённая строка ответа WB не должна блокировать остальные.
     *
     * Ответ, как правило, покрывает всё подключение целиком, поэтому
     * исключение на первой кривой строке навсегда останавливало бы обновление
     * всех корректных заказов кабинета.
     */
    public function testMalformedRowDoesNotBlockTheValidOnesInTheSameResponse(): void
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
            null,
            '5000000001',
        );

        $this->wb->setStatuses([5000000001 => [
            'id' => 5000000001,
            'supplierStatus' => 'complete',
            'wbStatus' => 'sorted',
            'isCancellable' => false,
        ]]);
        $this->wb->rejectRows(1, [], ['orders' => [['id' => 5000000002, 'supplierStatus' => '']]]);

        $result = ($this->action)(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));

        self::assertSame(0, $result->failedConnections, 'Кривая строка — не сбой подключения.');
        self::assertSame(1, $result->invalid, 'Отбракованная строка считается…');
        self::assertSame(1, $result->observed, '…но корректная применяется.');

        $this->em->clear();
        $order = $this->orders->findByExternalId((string) $company->getId(), IngestSource::WILDBERRIES, self::CONNECTION_ID, 'rid-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::SHIPPED, $order->getStatus());
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
     * Минимальная строка сырья: заказу нужна та, на которую можно завести
     * проблему.
     */
    private function seedRawRecord(string $companyId): string
    {
        $id = Uuid::uuid7()->toString();

        $this->connection->executeStatement(
            "INSERT INTO ingest_raw_records
                 (id, company_id, connection_ref, shop_ref, source, resource_type, external_id,
                  storage_path, hash, byte_size, fetched_at, last_seen_at, sync_job_id,
                  normalization_status, created_at, updated_at)
             VALUES
                 (:id, :company, :connection, 'shop-main', 'ozon', 'orders_fixture', :external,
                  :path, :hash, 128, now(), now(), :job, 'done', now(), now())",
            [
                'id' => $id,
                'company' => $companyId,
                'connection' => self::CONNECTION_ID,
                'external' => 'page-'.substr($id, 0, 8),
                'path' => 'company/ozon/shop/orders/'.$id.'.ndjson.gz',
                'hash' => hash('sha256', $id),
                'job' => Uuid::uuid7()->toString(),
            ],
        );

        return $id;
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
            // Сырьё НАСТОЯЩЕЕ, а не выдуманный UUID: проблема заводится только
            // на существующую в этой компании строку сырья — иначе её нечем
            // удерживать и незачем открывать. Выдуманный идентификатор делал
            // бы тест зелёным там, где прод молча ничего не запишет.
            ->withLastRawRecordId($this->seedRawRecord((string) $company->getId()))
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

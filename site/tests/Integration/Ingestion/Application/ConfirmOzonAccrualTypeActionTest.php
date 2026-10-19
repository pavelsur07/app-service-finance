<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\ConfirmOzonAccrualTypeAction;
use App\Ingestion\Application\Action\ResolveOzonAccrualTypeGroupDisputeAction;
use App\Ingestion\Application\Action\UpdateExternalCategoryMappingAction;
use App\Ingestion\Application\Service\OzonAccrualTypeQueue;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryCompanyMapping;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Exception\OzonAccrualTypeNotConfirmableException;
use App\Ingestion\Exception\UnknownCategoryGroupException;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Ingestion\OzonAccrualTypeBuilder;
use App\Tests\Builders\Ingestion\OzonAccrualTypeGroupBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class ConfirmOzonAccrualTypeActionTest extends IntegrationTestCase
{
    public function testQueueShowsDictionaryTypeWithUnknownRowsAndConfirmKeepsTransactionType(): void
    {
        $category = $this->unknownType127();

        $queue = $this->queue()->items();
        self::assertCount(1, $queue);
        self::assertSame(127, $queue[0]->typeId);
        self::assertSame('ozon_analytics_plus', $queue[0]->proposal->code);
        self::assertSame('other', $queue[0]->transactionType);

        $actor = Uuid::uuid7()->toString();
        self::assertSame(1, $this->confirm()(127, 'promotion', 'Аналитика Plus', $actor));

        $mapping = $this->connection->fetchAssociative(
            'SELECT canonical_code, canonical_label, canonical_group, transaction_type, known, updated_by FROM ingest_external_category_mappings WHERE external_category_id = :id',
            ['id' => $category->getId()],
        );
        self::assertSame([
            'canonical_code' => 'ozon_analytics_plus',
            'canonical_label' => 'Аналитика Plus',
            'canonical_group' => 'Продвижение и реклама',
            'transaction_type' => 'other',
            'known' => true,
            'updated_by' => $actor,
        ], $mapping);
    }

    public function testQueueMarksConfirmedTypeAsAwaitingRefresh(): void
    {
        $this->unknownType127();
        $this->confirm()(127, 'promotion', 'Аналитика Plus', Uuid::uuid7()->toString());

        $queue = $this->queue()->items();

        self::assertCount(1, $queue);
        self::assertTrue($queue[0]->awaitingRefresh);
    }

    /**
     * Ручной маппинг с known = false переводит категорию в MAPPED, но refresh
     * строки известными не сделает: тип не «ждёт refresh», а остаётся
     * неподтверждённым, и подтверждение доступно.
     */
    public function testMappingWithKnownFalseKeepsTypeUnconfirmed(): void
    {
        $category = $this->unknownType127();
        /** @var UpdateExternalCategoryMappingAction $updateMapping */
        $updateMapping = self::getContainer()->get(UpdateExternalCategoryMappingAction::class);
        $updateMapping(
            categoryId: $category->getId(),
            canonicalCode: 'ozon_analytics_plus',
            canonicalLabel: 'Аналитика Plus',
            canonicalGroup: 'Продвижение и реклама',
            transactionType: TransactionType::OTHER,
            sortOrder: 9000,
            status: ExternalCategoryMappingStatus::ACTIVE,
            known: false,
            updatedBy: Uuid::uuid7()->toString(),
        );
        $this->em->flush();

        $queue = $this->queue()->items();
        self::assertCount(1, $queue);
        self::assertFalse($queue[0]->awaitingRefresh);

        self::assertSame(1, $this->confirm()(127, 'promotion', 'Аналитика Plus', Uuid::uuid7()->toString()));
        self::assertTrue($this->connection->fetchOne('SELECT known FROM ingest_external_category_mappings WHERE external_category_id = :id', ['id' => $category->getId()]));
        self::assertTrue($this->queue()->items()[0]->awaitingRefresh);
    }

    /**
     * Маппинг компании сильнее маппинга по умолчанию: override с known = false
     * оставит строки компании неизвестными и после refresh, поэтому тип не «ждёт
     * refresh» и остаётся в мониторинге.
     */
    public function testCompanyOverrideWithKnownFalseKeepsTypeOutOfAwaitingRefresh(): void
    {
        $category = $this->unknownType127();
        $this->confirm()(127, 'promotion', 'Аналитика Plus', Uuid::uuid7()->toString());
        self::assertTrue($this->queue()->items()[0]->awaitingRefresh);

        $category = $this->em->find(ExternalCategory::class, $category->getId());
        self::assertInstanceOf(ExternalCategory::class, $category);
        $this->em->persist(new ExternalCategoryCompanyMapping(
            externalCategory: $category,
            companyId: Uuid::uuid7()->toString(),
            canonicalCode: 'ozon_analytics_plus',
            canonicalLabel: 'Аналитика Plus',
            canonicalGroup: 'Продвижение и реклама',
            transactionType: TransactionType::OTHER,
            sortOrder: 9000,
            known: false,
        ));
        $this->em->flush();

        self::assertFalse($this->queue()->items()[0]->awaitingRefresh);
    }

    public function testQueueSkipsTypeWithoutUnknownRows(): void
    {
        $this->em->persist(OzonAccrualTypeBuilder::aType()->withTypeId(29)->withName('LastMileCourier')->build());
        $this->persistTransaction(29, 'delivery:product-0:service-0:type-29', TransactionType::FEE, true);
        $this->em->flush();

        self::assertSame([], $this->queue()->items());
    }

    /**
     * Погашенная при перетипизации строка `fee` рядом с живой неизвестной `other`
     * не мешает подтверждению, а одни погашенные строки в очередь не попадают.
     */
    public function testVoidedRowsDoNotBlockConfirmationOrFillQueue(): void
    {
        $category = $this->unknownType127();
        $this->persistTransaction(127, 'non_item_fee:old-key', TransactionType::FEE, false, voided: true);
        $this->em->persist(OzonAccrualTypeBuilder::aType()->withTypeId(128)->withName('VoidedOnly')->build());
        $this->persistTransaction(128, 'non_item_fee:voided-only', TransactionType::OTHER, false, voided: true);
        $this->em->flush();

        self::assertSame([127], array_map(static fn ($item): int => $item->typeId, $this->queue()->items()));
        self::assertSame(1, $this->confirm()(127, 'promotion', 'Аналитика Plus', Uuid::uuid7()->toString()));
        self::assertSame('other', $this->connection->fetchOne('SELECT transaction_type FROM ingest_external_category_mappings WHERE external_category_id = :id', ['id' => $category->getId()]));
    }

    public function testCategoryTypesFollowScopeAndMixedNonItemTypesAreRefused(): void
    {
        $this->unknownType127();
        $this->em->persist(new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: 'item',
            normalizedKey: 'code:analyticsplus',
            externalTypeId: '127',
            status: ExternalCategoryStatus::NEW,
        ));
        // Строк item_fee больше: MODE дал бы fee и для non_item.
        $this->persistTransaction(127, 'item_fee:group-0:fee-0:type-127', TransactionType::FEE, false);
        $this->persistTransaction(127, 'item_fee:group-1:fee-0:type-127', TransactionType::FEE, false);
        $this->em->flush();

        $this->confirm()(127, 'promotion', 'Аналитика Plus', Uuid::uuid7()->toString());

        self::assertSame(
            [['item', 'fee'], ['non_item', 'other']],
            array_map(
                static fn (array $row): array => [$row['scope'], $row['transaction_type']],
                $this->connection->fetchAllAssociative('SELECT c.scope, m.transaction_type FROM ingest_external_category_mappings m JOIN ingest_external_categories c ON c.id = m.external_category_id ORDER BY c.scope'),
            ),
        );
    }

    public function testMixedNonItemTransactionTypesAreRefused(): void
    {
        $this->unknownType127();
        $this->persistTransaction(127, 'non_item_fee:type-127', TransactionType::FEE, true, 'second');
        $this->em->flush();

        $this->expectException(OzonAccrualTypeNotConfirmableException::class);
        $this->confirm()(127, 'promotion', 'Аналитика', Uuid::uuid7()->toString());
    }

    public function testUnclassifiedGroupCannotBeConfirmed(): void
    {
        $this->unknownType127();

        $this->expectException(OzonAccrualTypeNotConfirmableException::class);
        $this->confirm()(127, 'unclassified', 'Аналитика', Uuid::uuid7()->toString());
    }

    public function testConfirmWithoutCategoriesOrWithUnknownGroupFails(): void
    {
        $this->em->persist(OzonAccrualTypeBuilder::aType()->withTypeId(127)->withName('AnalyticsPlus')->withDescription(null)->build());
        $this->em->flush();

        try {
            $this->confirm()(127, 'promotion', 'Аналитика', Uuid::uuid7()->toString());
            self::fail('Confirm without external categories must fail.');
        } catch (OzonAccrualTypeNotConfirmableException) {
        }

        $this->expectException(OzonAccrualTypeNotConfirmableException::class);
        $this->confirm()(127, 'no_such_group', 'Аналитика', Uuid::uuid7()->toString());
    }

    public function testConfirmRequiresActor(): void
    {
        $this->unknownType127();

        $this->expectException(\InvalidArgumentException::class);
        $this->confirm()(127, 'promotion', 'Аналитика', 'not-a-uuid');
    }

    public function testTooLongLabelIsRejectedBeforeWrite(): void
    {
        $this->unknownType127();

        try {
            $this->confirm()(127, 'promotion', str_repeat('я', 256), Uuid::uuid7()->toString());
            self::fail('Label longer than the mapping column must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('длиннее 255', $exception->getMessage());
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ingest_external_category_mappings WHERE canonical_code LIKE :code', ['code' => 'ozon_analytics%']));
    }

    public function testMappingGroupMustBeFromClosedListButWhitespaceIsRepaired(): void
    {
        $category = $this->unknownType127();
        /** @var UpdateExternalCategoryMappingAction $update */
        $update = self::getContainer()->get(UpdateExternalCategoryMappingAction::class);

        $update($category->getId(), 'ozon_x', 'X', "Другие услуги и\nштрафы", TransactionType::OTHER, 9000, ExternalCategoryMappingStatus::ACTIVE, true, updatedBy: Uuid::uuid7()->toString());
        self::assertSame('Другие услуги и штрафы', $this->connection->fetchOne('SELECT canonical_group FROM ingest_external_category_mappings WHERE external_category_id = :id', ['id' => $category->getId()]));

        $this->expectException(UnknownCategoryGroupException::class);
        $update($category->getId(), 'ozon_x', 'X', 'Произвольная группа', TransactionType::OTHER, 9000, ExternalCategoryMappingStatus::ACTIVE, true, updatedBy: Uuid::uuid7()->toString());
    }

    public function testDisputeAcceptAndDismiss(): void
    {
        $accepted = OzonAccrualTypeGroupBuilder::aTypeGroup()->withTypeId(74)->withGroupCode('promotion')->build();
        $accepted->recordDispute('partners', 5, Uuid::uuid7()->toString());
        $dismissed = OzonAccrualTypeGroupBuilder::aTypeGroup()->withTypeId(52)->withGroupCode('promotion')->build();
        $dismissed->recordDispute('other', 2, Uuid::uuid7()->toString());
        $this->em->persist($accepted);
        $this->em->persist($dismissed);
        $this->em->flush();

        /** @var ResolveOzonAccrualTypeGroupDisputeAction $resolve */
        $resolve = self::getContainer()->get(ResolveOzonAccrualTypeGroupDisputeAction::class);
        $resolve(74, 'promotion', 'partners', true, Uuid::uuid7()->toString());
        $resolve(52, 'promotion', 'other', false, Uuid::uuid7()->toString());

        self::assertSame(
            [[52, 'promotion', null], [74, 'partners', null]],
            array_map(
                static fn (array $row): array => [(int) $row['type_id'], $row['group_code'], $row['disputed_group_code']],
                $this->connection->fetchAllAssociative('SELECT type_id, group_code, disputed_group_code FROM ingest_ozon_accrual_type_groups ORDER BY type_id'),
            ),
        );

        $this->expectException(OzonAccrualTypeNotConfirmableException::class);
        $resolve(74, 'partners', 'partners', true, Uuid::uuid7()->toString());
    }

    /**
     * Админ видел спор «promotion → partners», но импорт успел заменить его на
     * «delivery»: старая кнопка «Принять новую» не должна принять невиданную группу.
     */
    public function testStaleDisputeDecisionIsRejected(): void
    {
        $typeGroup = OzonAccrualTypeGroupBuilder::aTypeGroup()->withTypeId(74)->withGroupCode('promotion')->build();
        $typeGroup->recordDispute('delivery', 5, Uuid::uuid7()->toString());
        $this->em->persist($typeGroup);
        $this->em->flush();

        /** @var ResolveOzonAccrualTypeGroupDisputeAction $resolve */
        $resolve = self::getContainer()->get(ResolveOzonAccrualTypeGroupDisputeAction::class);

        try {
            $resolve(74, 'promotion', 'partners', true, Uuid::uuid7()->toString());
            self::fail('Stale decision must be rejected.');
        } catch (OzonAccrualTypeNotConfirmableException $exception) {
            self::assertStringContainsString('изменился', $exception->getMessage());
        }

        self::assertSame(
            ['group_code' => 'promotion', 'disputed_group_code' => 'delivery'],
            $this->connection->fetchAssociative('SELECT group_code, disputed_group_code FROM ingest_ozon_accrual_type_groups WHERE type_id = 74'),
        );
    }

    private function unknownType127(): ExternalCategory
    {
        $this->em->persist(OzonAccrualTypeBuilder::aType()->withTypeId(127)->withName('AnalyticsPlus')->withDescription(null)->build());
        $category = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: 'non_item',
            normalizedKey: 'type:127',
            externalTypeId: '127',
            status: ExternalCategoryStatus::NEW,
        );
        $this->em->persist($category);
        $this->persistTransaction(127, 'non_item_fee:type-127', TransactionType::OTHER, false);
        $this->em->flush();

        return $category;
    }

    private function persistTransaction(int $typeId, string $component, TransactionType $type, bool $known, string $suffix = '', bool $voided = false): void
    {
        $sourceData = ['_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY, '_ingestion_type_id' => (string) $typeId, '_ingestion_component' => $component, '_ozon_category_known' => $known];
        if ($voided) {
            $sourceData['_ingestion_voided'] = true;
        }
        $companyId = Uuid::uuid7()->toString();
        $this->em->persist(new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $companyId,
            shopRef: $companyId,
            source: IngestSource::OZON,
            externalId: sprintf('ozon:accrual-by-day:1:%s%s', $component, $suffix),
            externalUpdatedAt: new \DateTimeImmutable('2026-10-02 03:00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: $type,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor(-5600000, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-10-01 00:00:00'),
            rawRecordId: Uuid::uuid7()->toString(),
            sourceData: $sourceData,
        ));
    }

    private function confirm(): ConfirmOzonAccrualTypeAction
    {
        /** @var ConfirmOzonAccrualTypeAction $action */
        $action = self::getContainer()->get(ConfirmOzonAccrualTypeAction::class);

        return $action;
    }

    private function queue(): OzonAccrualTypeQueue
    {
        /** @var OzonAccrualTypeQueue $queue */
        $queue = self::getContainer()->get(OzonAccrualTypeQueue::class);

        return $queue;
    }
}

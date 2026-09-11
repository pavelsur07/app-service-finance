<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Ingestion\Facade\OzonAccrualCategoryFacade;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Application\Service\OzonListingEnsureService;
use App\Marketplace\Application\Service\PreliminaryCloseRowUnlinker;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceCostExistingExternalIdsQuery;
use App\Marketplace\MessageHandler\SyncOzonAccrualByDayHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Затраты из начислений Ozon `/v1/finance/accrual/by-day`.
 *
 * Читает документ целиком, а не корзину классификатора: одно начисление
 * POSTING несёт и выручку, и комиссию, и услуги доставки, поэтому «одна строка —
 * одна корзина» здесь не работает. Легаси-путь обходит это тем же приёмом.
 *
 * Услуги разбираются **по имени** из справочника `/v1/finance/accrual/types`
 * через `OzonAccrualCategoryFacade`. Собственный каталог Marketplace писался под
 * русские названия снятого v3 и из английских кодов справочника не разбирает ни
 * одного из встреченных 18; каталог Ingestion разбирает 15. Дублировать его
 * здесь значило бы завести второй словарь того же понятия — он разойдётся, и
 * одна услуга окажется в разных категориях в разных отчётах.
 *
 * Комиссия за продажу — не услуга со справочным `type_id`, а именованное поле,
 * поэтому её код берётся тот же, что в легаси-пути.
 */
final class OzonAccrualCostsRawProcessor implements MarketplaceRawProcessorInterface
{
    private const MONEY_SCALE = 2;

    private const COMMISSION_CODE = 'ozon_sale_commission';
    private const COMMISSION_NAME = 'Комиссия Ozon за продажу';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly OzonAccrualCategoryFacade $categoryFacade,
        private readonly MarketplaceCostCategoryResolver $categoryResolver,
        private readonly MarketplaceCostExistingExternalIdsQuery $existingIdsQuery,
        private readonly OzonListingEnsureService $listingEnsureService,
        private readonly PreliminaryCloseRowUnlinker $preliminaryRowUnlinker,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = '', ?MarketplaceRawFormat $format = null): bool
    {
        return StagingRecordType::COST === $type
            && MarketplaceType::OZON === $marketplace
            && MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY === $format;
    }

    public function process(string $companyId, string $rawDocId): int
    {
        $document = $this->em->find(MarketplaceRawDocument::class, $rawDocId);
        if (!$document instanceof MarketplaceRawDocument) {
            return 0;
        }

        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: '.$companyId);
        }

        $payload = $document->getRawData();

        // Документ обязан нести конверт с начислениями и справочником. Документ
        // без него — из более ранней версии загрузчика: разобрать его нечем,
        // справочника нет. Молчаливый ноль записал бы шаг затрат как успешный и
        // занизил расходы, поэтому падаем.
        if (!isset($payload[SyncOzonAccrualByDayHandler::PAYLOAD_ACCRUALS])
            || !is_array($payload[SyncOzonAccrualByDayHandler::PAYLOAD_ACCRUALS])
            || !isset($payload[SyncOzonAccrualByDayHandler::PAYLOAD_SERVICE_TYPES])
            || !is_array($payload[SyncOzonAccrualByDayHandler::PAYLOAD_SERVICE_TYPES])
        ) {
            throw new \RuntimeException(sprintf('Raw document %s has no accrual envelope: refetch the day before processing costs.', $rawDocId));
        }

        $accruals = $payload[SyncOzonAccrualByDayHandler::PAYLOAD_ACCRUALS];
        $serviceTypes = $payload[SyncOzonAccrualByDayHandler::PAYLOAD_SERVICE_TYPES];

        $entries = [];
        foreach ($accruals as $accrual) {
            if (is_array($accrual)) {
                foreach ($this->extractEntries($accrual, $serviceTypes) as $entry) {
                    $entries[] = $entry;
                }
            }
        }

        $created = 0;

        // Удаление и запись — одной транзакцией, и удаление внутри неё. Раньше
        // прежние затраты сносил вызывающий, до этого метода: DELETE ложился
        // отдельной транзакцией и фиксировался сразу, а Messenger handler в
        // транзакцию Doctrine не оборачивает. Любой сбой ниже оставлял документ
        // вовсе без затрат, и исчерпанные ретраи закрепляли потерю.
        //
        // Сносятся только незакрытые затраты: строка, привязанная к документу
        // ОПиУ, относится к закрытому периоду и правке не подлежит.
        //
        // Пустой разбор тоже проходит через удаление: день, из которого Ozon
        // убрал начисления, обязан остаться без затрат, а не сохранить прежние.
        $this->connection->beginTransaction();

        try {
            // Привязка к предварительному закрытию снимается до удаления: без
            // этого условие `document_id IS NULL` обошло бы такие строки, и
            // правка Ozon по ним не доехала бы до следующего reopen.
            // Окончательно закрытый период остаётся нетронутым.
            $this->preliminaryRowUnlinker->unlinkCosts(
                $companyId,
                MarketplaceType::OZON,
                $document->getPeriodFrom(),
                $rawDocId,
            );

            $this->connection->executeStatement(
                'DELETE FROM marketplace_costs
                 WHERE raw_document_id = :rawDocId
                   AND document_id IS NULL',
                ['rawDocId' => $rawDocId],
            );

            // Известные external_id читаются ПОСЛЕ удаления. До него в выборку
            // попадали бы строки этого же документа, которые только что снесены,
            // и цикл пропустил бы их как «уже существующие»: замена вышла бы
            // пустой, а затраты исчезли бы совсем. Оставшиеся совпадения — это
            // строки других документов, их дублировать нельзя.
            $existing = [] === $entries ? [] : $this->existingIdsQuery->execute(
                $companyId,
                array_values(array_unique(array_column($entries, 'externalId'))),
            );

            // Листинги резолвятся одним запросом на документ, а не по строке:
            // затрат за день тысячи, и по одной это дало бы N+1.
            $skus = array_values(array_unique(array_filter(array_column($entries, 'sku'))));
            $listings = [] === $skus
                ? []
                // by-day не несёт наименования товара — только sku.
                : $this->listingEnsureService->ensureListings($company, array_fill_keys($skus, null));

            foreach ($entries as $entry) {
                if (isset($existing[$entry['externalId']])) {
                    continue;
                }

                $category = $this->categoryResolver->resolve(
                    $company,
                    MarketplaceType::OZON,
                    $entry['categoryCode'],
                    $entry['categoryName'],
                );

                $cost = new MarketplaceCost(
                    Uuid::uuid4()->toString(),
                    $company,
                    MarketplaceType::OZON,
                    $category,
                );

                $cost->setExternalId($entry['externalId']);
                $cost->setRawDocumentId($rawDocId);
                $cost->setCostDate($entry['date']);
                $cost->setAmount($entry['amount']);
                $cost->setOperationType($entry['operationType']);

                // Товарная затрата получает листинг, общая остаётся без него:
                // NON_ITEM по устройству ответа sku не несёт. Пустая привязка
                // при непустом sku — это не «общая затрата», а потерянный
                // листинг, поэтому такой случай виден в логе.
                if (null !== $entry['sku']) {
                    $listing = $listings[$entry['sku']] ?? null;

                    if (null !== $listing) {
                        $cost->setListing($listing);
                    } else {
                        $this->logger->warning('[Ozon by-day] listing not resolved for cost', [
                            'external_id' => $entry['externalId'],
                            'sku' => $entry['sku'],
                        ]);
                    }
                }
                $cost->setDescription($entry['description']);

                $this->em->persist($cost);
                $existing[$entry['externalId']] = true;
                ++$created;
            }

            $this->em->flush();
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            // После отката EntityManager держит затраты, которых в базе нет, а
            // резолвер — категории, которые тоже откатились. Любой следующий
            // flush в этом же процессе записал бы их повторно.
            $this->em->clear();
            $this->categoryResolver->clearCache();

            throw $e;
        }

        return $created;
    }

    public function processBatch(
        string $companyId,
        MarketplaceType $marketplace,
        array $rawRows,
        ?string $rawDocId = null,
    ): void {
        // Затраты читают документ целиком: одно начисление даёт несколько
        // затрат сразу, и корзина классификатора для них не годится.
        if (null !== $rawDocId) {
            $this->process($companyId, $rawDocId);
        }
    }

    /**
     * @param array<string, mixed> $accrual
     * @param array<string, string> $serviceTypes
     *
     * @return list<array{externalId: string, categoryCode: string, categoryName: string, amount: string, operationType: MarketplaceCostOperationType, description: string, date: \DateTimeImmutable, sku: string|null}>
     */
    private function extractEntries(array $accrual, array $serviceTypes): array
    {
        $accrualId = (string) ($accrual['accrual_id'] ?? '');
        $rawDate = $accrual['date'] ?? null;

        if ('' === $accrualId || !is_string($rawDate)) {
            return [];
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
        if (false === $date) {
            return [];
        }

        // container_fees — затраты на тару и грузоместо по B2C-схеме. За месяц
        // выгрузки (31 день, 6889 начислений) поле не было непустым ни разу, и
        // формы его элементов мы не знаем. Молча пропустить непустой блок значит
        // занизить расходы и не заметить этого, поэтому падаем с указанием, где
        // смотреть.
        $containerFees = $accrual['container_fees'] ?? null;
        if (is_array($containerFees) && [] !== $containerFees) {
            throw new \RuntimeException(sprintf('Ozon accrual %s carries a non-empty container_fees block, which has no parser yet: capture the day and add one before processing costs.', $accrualId));
        }

        $entries = [];

        // POSTING: и комиссия, и услуги доставки относятся к конкретному товару
        // отправления, поэтому несут его sku и получают привязку к листингу.
        foreach ($this->products($accrual) as $index => $product) {
            $sku = $this->sku($product);

            $commission = $product['commission']['commission']['amount'] ?? null;
            if (is_numeric($commission) && 0.0 !== (float) $commission) {
                $entries[] = [
                    'externalId' => sprintf('ozon-accrual-%s-commission-product-%d', $accrualId, $index),
                    'categoryCode' => self::COMMISSION_CODE,
                    'categoryName' => self::COMMISSION_NAME,
                    'amount' => $this->money(abs((float) $commission)),
                    'operationType' => $this->operationType((float) $commission),
                    'description' => (float) $commission > 0 ? 'Возврат комиссии Ozon' : self::COMMISSION_NAME,
                    'date' => $date,
                    'sku' => $sku,
                ];
            }

            foreach ($this->services($product['delivery']['services'] ?? null) as $serviceIndex => $service) {
                $entry = $this->serviceEntry(
                    $service,
                    $serviceTypes,
                    $date,
                    sprintf('ozon-accrual-%s-product-%d-service-%d', $accrualId, $index, $serviceIndex),
                    $sku,
                );

                if (null !== $entry) {
                    $entries[] = $entry;
                }
            }
        }

        // ITEM: sku лежит на группе, а не на самой услуге.
        foreach ($this->itemFees($accrual) as $feeIndex => $fee) {
            $entry = $this->serviceEntry(
                $fee['fee'],
                $serviceTypes,
                $date,
                sprintf('ozon-accrual-%s-item-fee-%d', $accrualId, $feeIndex),
                $fee['sku'],
            );

            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        // NON_ITEM: sku нет по устройству ответа — это затрата кабинета целиком
        // (реклама, подписка, приёмка поставки), и листинга у неё быть не может.
        $nonItemFee = $accrual['non_item_fee'] ?? null;
        if (is_array($nonItemFee)) {
            $entry = $this->serviceEntry(
                $nonItemFee,
                $serviceTypes,
                $date,
                sprintf('ozon-accrual-%s-non-item', $accrualId),
                null,
            );

            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $service
     * @param array<string, string> $serviceTypes
     *
     * @return array{externalId: string, categoryCode: string, categoryName: string, amount: string, operationType: MarketplaceCostOperationType, description: string, date: \DateTimeImmutable, sku: string|null}|null
     */
    private function serviceEntry(array $service, array $serviceTypes, \DateTimeImmutable $date, string $externalIdPrefix, ?string $sku): ?array
    {
        $amount = $service['accrued']['amount'] ?? null;
        if (!is_numeric($amount) || 0.0 === (float) $amount) {
            return null;
        }

        $typeId = isset($service['type_id']) && (is_int($service['type_id']) || is_string($service['type_id']))
            ? (string) $service['type_id']
            : null;

        $typeName = null !== $typeId ? ($serviceTypes[$typeId] ?? null) : null;

        if (null !== $typeId && null === $typeName) {
            $this->logger->warning('[Ozon by-day] service type is missing from the dictionary', [
                'type_id' => $typeId,
            ]);
        }

        $category = $this->categoryFacade->resolveByServiceType($typeId, $typeName);

        return [
            'externalId' => sprintf('%s-type-%s', $externalIdPrefix, $typeId ?? 'unknown'),
            'categoryCode' => $category->code,
            'categoryName' => $category->label,
            // Затраты хранятся положительными, как в легаси-пути: знак несёт
            // operation_type, по которому ОПиУ отличает начисление от сторно.
            'amount' => $this->money(abs((float) $amount)),
            'operationType' => $this->operationType((float) $amount),
            'description' => $category->label,
            'date' => $date,
            'sku' => $sku,
        ];
    }

    /**
     * @param array<string, mixed> $accrual
     *
     * @return array<int, array<string, mixed>>
     */
    private function products(array $accrual): array
    {
        $products = $accrual['posting']['products'] ?? null;

        return is_array($products) ? array_values(array_filter($products, 'is_array')) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function services(mixed $services): array
    {
        return is_array($services) ? array_values(array_filter($services, 'is_array')) : [];
    }

    /**
     * Услуги по товару вместе с sku своей группы: сам элемент `fees[]` его не
     * несёт, а без него затрата потеряла бы привязку к листингу.
     *
     * @param array<string, mixed> $accrual
     *
     * @return list<array{sku: string|null, fee: array<string, mixed>}>
     */
    private function itemFees(array $accrual): array
    {
        $groups = $accrual['item_fees']['fees'] ?? null;
        if (!is_array($groups)) {
            return [];
        }

        $fees = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $sku = $this->sku($group);

            foreach ($this->services($group['fees'] ?? null) as $fee) {
                $fees[] = ['sku' => $sku, 'fee' => $fee];
            }
        }

        return $fees;
    }

    /**
     * SKU Ozon приходит и числом, и строкой; пустое значение — это отсутствие
     * привязки, а не листинг с пустым артикулом.
     *
     * @param array<string, mixed> $row
     */
    private function sku(array $row): ?string
    {
        $sku = $row['sku'] ?? null;

        if (!is_int($sku) && !is_string($sku)) {
            return null;
        }

        $sku = trim((string) $sku);

        return '' !== $sku ? $sku : null;
    }

    /**
     * Знак исходной суммы Ozon — это вид операции, а не свойство числа.
     *
     * Отрицательная сумма — начисление в пользу Ozon, то есть расход продавца.
     * Положительная — возврат ранее удержанного: комиссия за отменённый заказ,
     * корректировка услуги. Суммы хранятся положительными, как в легаси-пути,
     * поэтому без operation_type сторно неотличимо от начисления и `UnprocessedCostsQuery`
     * посчитает возврат расходом, увеличив затраты вместо их уменьшения.
     */
    private function operationType(float $rawAmount): MarketplaceCostOperationType
    {
        return $rawAmount > 0
            ? MarketplaceCostOperationType::STORNO
            : MarketplaceCostOperationType::CHARGE;
    }

    private function money(float $value): string
    {
        return number_format($value, self::MONEY_SCALE, '.', '');
    }
}

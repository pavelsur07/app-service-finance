<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonAccrualDictionaryCategoryFactory;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Domain\OzonCategoryGroupSeed;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Exception\OzonAccrualTypeNotConfirmableException;
use App\Ingestion\Infrastructure\Query\OzonAccrualTypeQueueQuery;
use App\Ingestion\Repository\CategoryGroupRepository;
use App\Ingestion\Repository\ExternalCategoryRepository;
use App\Ingestion\Repository\OzonAccrualTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Админ подтверждает новый тип Ozon из очереди: каждой неразобранной внешней
 * категории этого типа ставится маппинг по умолчанию — код из справочника,
 * выбранные название и группа, `known = true`.
 *
 * Тип транзакции из статьи действует только для строк `non_item_fee`, поэтому
 * категории `non_item` и `any` получают текущий тип этих строк (по умолчанию
 * `other`), а разные типы у них — отказ: подтверждение не перетипизирует, смена
 * типа поменяла бы естественный ключ уже загруженных транзакций (#2440). Для
 * `item`, `delivery`, `container` тип статьи маппер не читает — ставится `fee`.
 * Актор обязателен: маппинг без `updated_by` выглядит автоматическим, и группа из
 * отчёта Ozon перекрыла бы решение человека.
 */
final readonly class ConfirmOzonAccrualTypeAction
{
    public function __construct(
        private OzonAccrualTypeRepository $typeRepository,
        private ExternalCategoryRepository $categoryRepository,
        private CategoryGroupRepository $groupRepository,
        private OzonAccrualTypeQueueQuery $queueQuery,
        private OzonAccrualDictionaryCategoryFactory $categoryFactory,
        private UpdateExternalCategoryMappingAction $updateMapping,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int сколько внешних категорий получили маппинг
     */
    public function __invoke(int $typeId, string $groupCode, string $label, string $actorUserId): int
    {
        Assert::uuid($actorUserId, 'Подтверждение требует пользователя-актора.');
        $label = trim($label);
        Assert::notEmpty($label, 'Название статьи не может быть пустым.');
        Assert::maxLength($label, OzonAccrualDictionaryCategoryFactory::LABEL_MAX_LENGTH, sprintf('Название статьи длиннее %d символов.', OzonAccrualDictionaryCategoryFactory::LABEL_MAX_LENGTH));

        $type = $this->typeRepository->findAllIndexedByTypeId()[$typeId] ?? null;
        if (null === $type) {
            throw new OzonAccrualTypeNotConfirmableException(sprintf('Тип %d отсутствует в справочнике Ozon.', $typeId));
        }

        $group = $this->groupRepository->findBySourceIndexedByCode(IngestSource::OZON)[$groupCode] ?? null;
        if (null === $group || OzonCategoryGroupSeed::UNCLASSIFIED_CODE === $groupCode) {
            throw new OzonAccrualTypeNotConfirmableException(sprintf('Группа «%s» не подходит для подтверждения: нужна группа из закрытого списка, не «Требует классификации».', $groupCode));
        }

        if (null === $this->queueQuery->unknownUsageForType($typeId)) {
            throw new OzonAccrualTypeNotConfirmableException(sprintf('У типа %d нет неразобранных начислений.', $typeId));
        }

        $nonItemTypes = $this->queueQuery->nonItemTransactionTypes($typeId);
        if (\count($nonItemTypes) > 1) {
            throw new OzonAccrualTypeNotConfirmableException(sprintf('У строк non_item типа %d разные типы операций (%s): подтверждение сменило бы ключ части строк. Нужна перетипизация отдельной задачей.', $typeId, implode(', ', $nonItemTypes)));
        }
        $nonItemType = TransactionType::tryFrom($nonItemTypes[0] ?? '') ?? TransactionType::OTHER;

        $keys = array_values(array_filter([
            OzonAccrualCategoryTaxonomyResolver::typeKey((string) $typeId),
            OzonAccrualCategoryTaxonomyResolver::codeKey($type->getName()),
            OzonAccrualCategoryTaxonomyResolver::nameKey($type->getName()),
        ]));
        // Неразобранные категории и MAPPED с маппингом known = false.
        $categories = $this->categoryRepository->findUnconfirmedByTypeIdsOrKeys(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            [(string) $typeId],
            $keys,
        );
        if ([] === $categories) {
            throw new OzonAccrualTypeNotConfirmableException(sprintf('У типа %d нет неподтверждённых внешних категорий: запустите discovery.', $typeId));
        }

        $code = $this->categoryFactory->create($type, $nonItemType)->code;
        $this->entityManager->wrapInTransaction(function () use ($categories, $code, $label, $group, $nonItemType, $actorUserId): void {
            foreach ($categories as $category) {
                ($this->updateMapping)(
                    categoryId: $category->getId(),
                    canonicalCode: $code,
                    canonicalLabel: $label,
                    canonicalGroup: $group->getLabel(),
                    transactionType: $this->transactionTypeFor($category, $nonItemType),
                    sortOrder: OzonAccrualDictionaryCategoryFactory::SORT_ORDER,
                    status: ExternalCategoryMappingStatus::ACTIVE,
                    known: true,
                    displayLabel: $label,
                    updatedBy: $actorUserId,
                );
            }
        });

        $this->logger->info('Ozon accrual type confirmed by admin.', [
            'typeId' => $typeId,
            'canonicalCode' => $code,
            'groupCode' => $groupCode,
            'categories' => array_map(static fn (ExternalCategory $category): string => $category->getScope().':'.$category->getNormalizedKey(), $categories),
            'actorUserId' => $actorUserId,
        ]);

        return \count($categories);
    }

    private function transactionTypeFor(ExternalCategory $category, TransactionType $nonItemType): TransactionType
    {
        return \in_array($category->getScope(), [OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM, OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY], true)
            ? $nonItemType
            : TransactionType::FEE;
    }
}

<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\OzonAccrualTypeProposal;
use App\Ingestion\Entity\OzonAccrualType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\CategoryGroupRepository;
use App\Ingestion\Repository\OzonAccrualTypeGroupRepository;

/**
 * Предложение статьи для нового типа: код и название — как у статьи из
 * справочника (`OzonAccrualDictionaryCategoryFactory`), группа — из отчёта
 * кабинета, иначе из правил. Источник группы показывается админу.
 */
final readonly class OzonAccrualTypeProposer
{
    public function __construct(
        private OzonAccrualDictionaryCategoryFactory $categoryFactory,
        private OzonAccrualGroupSuggester $groupSuggester,
        private OzonAccrualTypeGroupRepository $typeGroupRepository,
        private CategoryGroupRepository $groupRepository,
    ) {
    }

    /**
     * @param list<OzonAccrualType> $types
     *
     * @return array<int, OzonAccrualTypeProposal> type_id => предложение
     */
    public function proposeAll(array $types): array
    {
        $groups = $this->groupRepository->findBySourceIndexedByCode(IngestSource::OZON);
        $typeGroups = $this->typeGroupRepository->findAllIndexedByTypeId();
        $this->groupSuggester->reset();

        $proposals = [];
        foreach ($types as $type) {
            $category = $this->categoryFactory->create($type, TransactionType::OTHER);
            $groupCode = ($typeGroups[$type->getTypeId()] ?? null)?->confirmedGroupCode();
            $source = OzonAccrualTypeProposal::GROUP_SOURCE_OZON_REPORT;

            if (null === $groupCode || !isset($groups[$groupCode])) {
                $suggestion = $this->groupSuggester->suggest($type->getName(), $type->getDescription());
                $groupCode = $suggestion?->groupCode;
                $source = null === $suggestion
                    ? OzonAccrualTypeProposal::GROUP_SOURCE_NONE
                    : ($suggestion->isHigh() ? OzonAccrualTypeProposal::GROUP_SOURCE_RULE : OzonAccrualTypeProposal::GROUP_SOURCE_RULE_MEDIUM);
            }

            $proposals[$type->getTypeId()] = new OzonAccrualTypeProposal(
                code: $category->code,
                label: $category->label,
                groupCode: $groupCode,
                groupLabel: null !== $groupCode ? ($groups[$groupCode] ?? null)?->getLabel() : null,
                groupSource: $source,
            );
        }

        return $proposals;
    }
}

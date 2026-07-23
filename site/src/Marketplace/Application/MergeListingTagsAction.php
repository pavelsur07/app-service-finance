<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Exception\ListingTagNotFoundException;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Слияние тегов: все листинги источника перевешиваются на цель, источник удаляется.
 * Главное средство против накопленных дублей («Зима» + «зима» → один тег).
 */
final class MergeListingTagsAction
{
    public function __construct(
        private readonly MarketplaceListingTagRepository $tagRepository,
        private readonly ListingTagAssignmentRepository $assignments,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $companyId, string $sourceTagId, string $targetTagId): void
    {
        if ($sourceTagId === $targetTagId) {
            throw new \InvalidArgumentException('Нельзя слить тег сам с собой.');
        }

        $source = $this->tagRepository->findById($companyId, $sourceTagId);
        if (null === $source) {
            throw new ListingTagNotFoundException($sourceTagId);
        }

        $target = $this->tagRepository->findById($companyId, $targetTagId);
        if (null === $target) {
            throw new ListingTagNotFoundException($targetTagId);
        }

        // Перевесить связи на цель, затем удалить источник (его связи уйдут каскадом).
        $this->assignments->reassign($companyId, $sourceTagId, $targetTagId);
        $this->tagRepository->remove($source);
        $this->em->flush();
    }
}

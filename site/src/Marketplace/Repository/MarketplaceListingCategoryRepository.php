<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\DTO\ListingCategoryDTO;
use App\Marketplace\Entity\MarketplaceListingCategory;
use Doctrine\ORM\EntityManagerInterface;

final class MarketplaceListingCategoryRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function findBySlug(string $companyId, string $slug): ?MarketplaceListingCategory
    {
        return $this->em->getRepository(MarketplaceListingCategory::class)
            ->findOneBy(['companyId' => $companyId, 'slug' => $slug]);
    }

    public function findById(string $companyId, string $categoryId): ?MarketplaceListingCategory
    {
        return $this->em->getRepository(MarketplaceListingCategory::class)
            ->findOneBy(['companyId' => $companyId, 'id' => $categoryId]);
    }

    /**
     * @return list<ListingCategoryDTO>
     */
    public function listForCompany(string $companyId): array
    {
        /** @var list<array{id: string, name: string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('c.id', 'c.name')
            ->from(MarketplaceListingCategory::class, 'c')
            ->where('c.companyId = :companyId')
            ->orderBy('c.name', \SortDirection::Ascending)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): ListingCategoryDTO => new ListingCategoryDTO($row['id'], $row['name']),
            $rows,
        );
    }

    public function add(MarketplaceListingCategory $category): void
    {
        $this->em->persist($category);
    }

    public function remove(MarketplaceListingCategory $category): void
    {
        $this->em->remove($category);
    }
}

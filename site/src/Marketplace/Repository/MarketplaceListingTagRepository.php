<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\DTO\ListingTagDTO;
use App\Marketplace\Entity\MarketplaceListingTag;
use Doctrine\ORM\EntityManagerInterface;

final class MarketplaceListingTagRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function findBySlug(string $companyId, string $slug): ?MarketplaceListingTag
    {
        return $this->em->createQueryBuilder()
            ->select('t')
            ->from(MarketplaceListingTag::class, 't')
            ->where('t.companyId = :companyId')
            ->andWhere('t.slug = :slug')
            ->setParameter('companyId', $companyId)
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findById(string $companyId, string $tagId): ?MarketplaceListingTag
    {
        return $this->em->createQueryBuilder()
            ->select('t')
            ->from(MarketplaceListingTag::class, 't')
            ->where('t.companyId = :companyId')
            ->andWhere('t.id = :tagId')
            ->setParameter('companyId', $companyId)
            ->setParameter('tagId', $tagId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ListingTagDTO>
     */
    public function listForCompany(string $companyId): array
    {
        /** @var list<array{id: string, name: string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('t.id', 't.name')
            ->from(MarketplaceListingTag::class, 't')
            ->where('t.companyId = :companyId')
            ->orderBy('t.name', 'ASC')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): ListingTagDTO => new ListingTagDTO($row['id'], $row['name']),
            $rows,
        );
    }

    public function add(MarketplaceListingTag $tag): void
    {
        $this->em->persist($tag);
    }
}

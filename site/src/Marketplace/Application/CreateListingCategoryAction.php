<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Entity\MarketplaceListingCategory;
use App\Marketplace\Exception\ListingCategoryNameConflictException;
use App\Marketplace\Repository\MarketplaceListingCategoryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class CreateListingCategoryAction
{
    public function __construct(
        private readonly MarketplaceListingCategoryRepository $categories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $companyId, string $name): MarketplaceListingCategory
    {
        if (null !== $this->categories->findBySlug($companyId, MarketplaceListingCategory::slugify($name))) {
            throw new ListingCategoryNameConflictException(trim($name));
        }

        $category = new MarketplaceListingCategory($companyId, $name);
        $this->categories->add($category);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // Одноимённую категорию между проверкой и flush создал параллельный запрос.
            throw new ListingCategoryNameConflictException(trim($name), $exception);
        }

        return $category;
    }
}

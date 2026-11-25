<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Entity\MarketplaceListingCategory;
use App\Marketplace\Exception\ListingCategoryNameConflictException;
use App\Marketplace\Exception\ListingCategoryNotFoundException;
use App\Marketplace\Repository\MarketplaceListingCategoryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class RenameListingCategoryAction
{
    public function __construct(
        private readonly MarketplaceListingCategoryRepository $categories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $companyId, string $categoryId, string $name): MarketplaceListingCategory
    {
        $category = $this->categories->findById($companyId, $categoryId)
            ?? throw new ListingCategoryNotFoundException($categoryId);

        // Конфликт только с другой категорией: смена регистра у той же — не конфликт.
        $existing = $this->categories->findBySlug($companyId, MarketplaceListingCategory::slugify($name));
        if (null !== $existing && $existing->getId() !== $category->getId()) {
            throw new ListingCategoryNameConflictException(trim($name));
        }

        $category->rename($name);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ListingCategoryNameConflictException(trim($name), $exception);
        }

        return $category;
    }
}

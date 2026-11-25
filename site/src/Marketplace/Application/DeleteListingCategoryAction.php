<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Exception\ListingCategoryInUseException;
use App\Marketplace\Exception\ListingCategoryNotFoundException;
use App\Marketplace\Infrastructure\Query\ListingCategoryAssignmentQuery;
use App\Marketplace\Repository\MarketplaceListingCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Удаляет только неиспользуемую категорию. Проверка — для понятного отказа;
 * последнее слово за FK `ON DELETE RESTRICT`, если листинг получил категорию
 * между проверкой и удалением.
 */
final class DeleteListingCategoryAction
{
    public function __construct(
        private readonly MarketplaceListingCategoryRepository $categories,
        private readonly ListingCategoryAssignmentQuery $assignments,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $companyId, string $categoryId): void
    {
        $category = $this->categories->findById($companyId, $categoryId)
            ?? throw new ListingCategoryNotFoundException($categoryId);

        $used = $this->assignments->countByCategory($companyId, $categoryId);
        if ($used > 0) {
            throw new ListingCategoryInUseException($used);
        }

        $this->categories->remove($category);
        $this->em->flush();
    }
}

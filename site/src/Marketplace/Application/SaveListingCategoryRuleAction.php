<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Entity\ListingCategoryRule;
use App\Marketplace\Exception\ListingCategoryNotFoundException;
use App\Marketplace\Infrastructure\Query\ListingCategoryRuleQuery;
use App\Marketplace\Repository\MarketplaceListingCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Создать или заменить правило по ключу площадки и сразу применить его.
 * Повтор с тем же ключом и категорией ничего не пишет (upsert + IS DISTINCT FROM).
 */
final class SaveListingCategoryRuleAction
{
    public function __construct(
        private readonly MarketplaceListingCategoryRepository $categories,
        private readonly ListingCategoryRuleQuery $rules,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return int число листингов, получивших категорию */
    public function __invoke(string $companyId, string $sourceKey, string $categoryId): int
    {
        Assert::uuid($companyId);
        if (1 !== preg_match(ListingCategoryRule::SOURCE_KEY_PATTERN, $sourceKey)) {
            throw new \InvalidArgumentException('Неизвестный ключ категории площадки.');
        }
        if (!Uuid::isValid($categoryId) || null === $this->categories->findById($companyId, $categoryId)) {
            throw new ListingCategoryNotFoundException($categoryId);
        }

        return $this->em->wrapInTransaction(function () use ($companyId, $sourceKey, $categoryId): int {
            $this->rules->upsert($companyId, $sourceKey, $categoryId);

            return $this->rules->apply($companyId);
        });
    }
}

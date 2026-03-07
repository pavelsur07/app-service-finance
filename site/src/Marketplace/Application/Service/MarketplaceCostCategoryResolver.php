<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class MarketplaceCostCategoryResolver
{
    /** @var array<string, MarketplaceCostCategory> */
    private array $cache = [];

    public function __construct(
        private readonly MarketplaceCostCategoryRepository $costCategoryRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Найти или создать категорию затрат.
     * flush() НЕ вызывается — ответственность вызывающего кода.
     */
    public function resolve(
        Company $company,
        MarketplaceType $marketplace,
        string $code,
        string $name,
    ): MarketplaceCostCategory {
        $cacheKey = $marketplace->value . '_' . $code;

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $category = $this->costCategoryRepository->findOneBy([
            'company'   => $company,
            'marketplace' => $marketplace,
            'code'      => $code,
            'deletedAt' => null,
        ]);

        if ($category === null) {
            $category = new MarketplaceCostCategory(
                Uuid::uuid4()->toString(),
                $company,
                $marketplace,
            );
            $category->setCode($code);
            $category->setName($name);

            $this->em->persist($category);
        }

        $this->cache[$cacheKey] = $category;

        return $category;
    }

    /**
     * Вызывать после em->clear() в батче.
     * Переключает кэш на getReference чтобы не держать detached entity.
     */
    public function resetCache(): void
    {
        foreach ($this->cache as $key => $category) {
            $this->cache[$key] = $this->em->getReference(
                MarketplaceCostCategory::class,
                $category->getId(),
            );
        }
    }

    /**
     * Предзагрузить все существующие категории компании одним запросом.
     * Вызывать один раз в начале Action до обработки батчей.
     */
    public function preload(Company $company, MarketplaceType $marketplace): void
    {
        $categories = $this->costCategoryRepository->findBy([
            'company'    => $company,
            'marketplace' => $marketplace,
            'deletedAt'  => null,
        ]);

        foreach ($categories as $category) {
            $cacheKey = $marketplace->value . '_' . $category->getCode();
            $this->cache[$cacheKey] = $category;
        }
    }
}

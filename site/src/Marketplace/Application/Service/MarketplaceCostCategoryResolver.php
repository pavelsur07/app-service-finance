<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

final class MarketplaceCostCategoryResolver implements ResetInterface
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
        $cacheKey = $company->getId() . '_' . $marketplace->value . '_' . $code;

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
                Uuid::uuid7()->toString(),
                $company,
                $marketplace,
            );
            $category->setCode($code);
            $category->setName($name);

            $this->em->persist($category);
            // flush НЕ вызываем — соответствует docblock.
            // Id сгенерирован на стороне приложения (uuid7), поэтому persist без flush
            // достаточен для использования в relation.
        }

        $this->cache[$cacheKey] = $category;

        return $category;
    }

    /**
     * Вызывать ТОЛЬКО после em->flush() + em->clear() в батче.
     * Пересоздаёт кэш через managed entities из БД, отбрасывая категории,
     * которые не попали в БД (например, созданы persist()-ом, но не flush()-нуты).
     */
    public function resetCache(): void
    {
        if ($this->cache === []) {
            return;
        }

        $ids = [];
        foreach ($this->cache as $category) {
            $ids[] = $category->getId();
        }
        $ids = array_unique($ids);

        $fresh = $this->costCategoryRepository->findBy(['id' => $ids]);
        $byId = [];
        foreach ($fresh as $c) {
            $byId[$c->getId()] = $c;
        }

        $newCache = [];
        foreach ($this->cache as $key => $oldCategory) {
            $id = $oldCategory->getId();
            if (isset($byId[$id])) {
                $newCache[$key] = $byId[$id];
            }
            // Иначе — выпадает из кэша, следующий resolve() сделает findOneBy.
        }
        $this->cache = $newCache;
    }

    /**
     * Полный сброс кеша — вызывать между сообщениями Messenger.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    public function reset(): void
    {
        $this->clearCache();
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
            $cacheKey = $company->getId() . '_' . $marketplace->value . '_' . $category->getCode();
            $this->cache[$cacheKey] = $category;
        }
    }
}

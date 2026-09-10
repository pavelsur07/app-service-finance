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
        $cacheKey = $company->getId().'_'.$marketplace->value.'_'.$code;

        if (isset($this->cache[$cacheKey])) {
            $cached = $this->cache[$cacheKey];

            // Кеш наполняет и preload(), который берёт в том числе удалённые:
            // вернуть удалённую категорию значило бы спрятать затрату из отчётов.
            if ($cached->isDeleted()) {
                $cached->restore();
            }

            return $cached;
        }

        // Мягко удалённые категории тоже участвуют в поиске. Уникальность
        // (company_id, marketplace, code) в базе про deleted_at ничего не знает,
        // поэтому фильтр `deletedAt = null` не находил удалённую категорию, а
        // следом INSERT падал на уникальном индексе и ронял весь шаг затрат.
        // Так на проде упала загрузка 09.09: код `ozon_logistics` был удалён
        // 28.03.2026, а Ozon снова начислил по этой услуге.
        $category = $this->costCategoryRepository->findOneBy([
            'company' => $company,
            'marketplace' => $marketplace,
            'code' => $code,
        ]);

        // Услуга, по которой снова пришло начисление, обязана вернуться в
        // справочник: затрата с удалённой категорией выпала бы из отчётов молча.
        if ($category instanceof MarketplaceCostCategory && $category->isDeleted()) {
            $category->restore();
        }

        if (null === $category) {
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
        if ([] === $this->cache) {
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
        // Удалённые тоже: иначе resolve() промахнётся мимо кеша, сходит в базу,
        // снова их не увидит и попытается вставить дубль по тому же коду.
        $categories = $this->costCategoryRepository->findBy([
            'company' => $company,
            'marketplace' => $marketplace,
        ]);

        foreach ($categories as $category) {
            $cacheKey = $company->getId().'_'.$marketplace->value.'_'.$category->getCode();
            $this->cache[$cacheKey] = $category;
        }
    }
}

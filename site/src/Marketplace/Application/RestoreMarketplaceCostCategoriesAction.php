<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Processor\OzonServiceCategoryMap;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Восстанавливает/создаёт категории затрат из предопределённого списка.
 *
 * Для каждого известного кода:
 * - soft-deleted → восстанавливает (deletedAt = null, isActive = true)
 * - isActive = false → активирует
 * - не найдена → создаёт новую
 *
 * Поддерживает Ozon и Wildberries.
 */
final class RestoreMarketplaceCostCategoriesAction
{
    public function __construct(
        private readonly MarketplaceCostCategoryRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Company $company, MarketplaceType $marketplace): int
    {
        $allCodes = $this->getCategoriesForMarketplace($marketplace);
        if ($allCodes === []) {
            return 0;
        }

        $restored = 0;

        // Single query: load all categories (including soft-deleted) for this company+marketplace
        $existingCategories = $this->repository->findBy([
            'company' => $company,
            'marketplace' => $marketplace,
        ]);

        $existingMap = [];
        foreach ($existingCategories as $cat) {
            $existingMap[$cat->getCode()] = $cat;
        }

        foreach ($allCodes as $code => $name) {
            $category = $existingMap[$code] ?? null;

            if ($category !== null) {
                if ($category->isDeleted()) {
                    $category->restore();
                    $restored++;
                } elseif (!$category->isActive()) {
                    $category->setIsActive(true);
                    $restored++;
                }

                continue;
            }

            $category = new MarketplaceCostCategory(
                Uuid::uuid7()->toString(),
                $company,
                $marketplace,
            );
            $category->setCode($code);
            $category->setName($name);

            $this->em->persist($category);
            $restored++;
        }

        $this->em->flush();

        return $restored;
    }

    /**
     * @return array<string, string> code => name
     */
    private function getCategoriesForMarketplace(MarketplaceType $marketplace): array
    {
        return match ($marketplace) {
            MarketplaceType::OZON => OzonServiceCategoryMap::getAllCategoryCodes(),
            MarketplaceType::WILDBERRIES => [
                'commission' => 'Комиссия маркетплейса',
                'logistics_delivery' => 'Логистика до покупателя',
                'logistics_return' => 'Логистика возврат',
                'warehouse_logistics' => 'Логистика складские операции',
                'storage' => 'Хранение WB',
                'acquiring' => 'Эквайринг',
                'penalty' => 'Штраф WB',
                'pvz_processing' => 'Логистика обработка на ПВЗ',
                'product_processing' => 'Обработка товара WB',
                'wb_loyalty_discount_compensation' => 'Компенсация скидки по программе лояльности WB',
            ],
            MarketplaceType::YANDEX_MARKET, MarketplaceType::SBER_MEGAMARKET => [],
        };
    }
}

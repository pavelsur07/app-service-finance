<?php

namespace App\DataFixtures;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;

class MarketplaceCostCategoriesFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        /** @var Company $company */
        $company = $this->getReference(AppFixtures::REF_COMPANY_ROMASHKA, Company::class);

        $categories = [
            // Wildberries
            [
                'marketplace' => MarketplaceType::WILDBERRIES,
                'code' => 'commission',
                'name' => 'Комиссия маркетплейса',
                'description' => 'Комиссия Wildberries за продажу',
            ],
            [
                'marketplace' => MarketplaceType::WILDBERRIES,
                'code' => 'acquiring',
                'name' => 'Эквайринг',
                'description' => 'Эквайринговая комиссия WB',
            ],
            [
                'marketplace' => MarketplaceType::WILDBERRIES,
                'code' => 'logistics_delivery',
                'name' => 'Логистика до покупателя',
                'description' => 'Стоимость доставки товара до покупателя',
            ],
            [
                'marketplace' => MarketplaceType::WILDBERRIES,
                'code' => 'logistics_return',
                'name' => 'Логистика возврат',
                'description' => 'Стоимость обратной доставки при возврате',
            ],
            [
                'marketplace' => MarketplaceType::WILDBERRIES,
                'code' => 'storage',
                'name' => 'Хранение на складе',
                'description' => 'Стоимость хранения товара на складе WB',
            ],
            [
                'marketplace' => MarketplaceType::WILDBERRIES,
                'code' => 'penalty',
                'name' => 'Штрафы',
                'description' => 'Штрафы и удержания WB',
            ],
            [
                'marketplace' => MarketplaceType::WILDBERRIES,
                'code' => 'advertising',
                'name' => 'Реклама',
                'description' => 'Расходы на рекламу на WB',
            ],
            [
                'marketplace' => MarketplaceType::WILDBERRIES,
                'code' => 'pvz_processing',
                'name' => 'Логистика обработка на ПВЗ',
                'description' => 'Возмещение за выдачу и возврат товаров на ПВЗ',
            ],
            [
                'marketplace' => MarketplaceType::WILDBERRIES,
                'code' => 'warehouse_logistics',
                'name' => 'Логистика складские операции',
                'description' => 'Возмещение издержек по перевозке/по складским операциям',
            ],
            [
                'marketplace' => MarketplaceType::WILDBERRIES,
                'code' => 'product_processing',
                'name' => 'Обработка товара',
                'description' => 'Обработка товара на складе WB',
            ],
            [
                'marketplace' => MarketplaceType::WILDBERRIES,
                'code' => 'wb_loyalty_discount_compensation',
                'name' => 'Компенсация скидки по программе лояльности WB',
                'description' => 'Компенсация скидки по программе лояльности',
            ],
        ];

        foreach ($categories as $cat) {
            $existing = $manager->getRepository(MarketplaceCostCategory::class)
                ->findOneBy([
                    'company' => $company,
                    'marketplace' => $cat['marketplace'],
                    'code' => $cat['code'],
                ]);

            if ($existing) {
                continue;
            }

            $category = new MarketplaceCostCategory(
                Uuid::uuid4()->toString(),
                $company,
                $cat['marketplace']
            );
            $category->setCode($cat['code']);
            $category->setName($cat['name']);
            $category->setDescription($cat['description'] ?? null);
            $category->setIsSystem(true); // Системная категория - нельзя удалить

            $manager->persist($category);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            AppFixtures::class,
        ];
    }
}

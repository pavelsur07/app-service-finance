<?php

namespace App\DataFixtures;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCostCategory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;

class MarketplaceCostCategoriesFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Получаем компанию "Ромашка" из AppFixtures
        /** @var Company $company */
        $company = $this->getReference(AppFixtures::REF_COMPANY_ROMASHKA, Company::class);

        $categories = [
            // Wildberries
            ['code' => 'wb_commission', 'name' => 'Комиссия Wildberries'],
            ['code' => 'wb_logistics', 'name' => 'Логистика WB (доставка до клиента)'],
            ['code' => 'wb_return_logistics', 'name' => 'Логистика возврата WB'],
            ['code' => 'wb_storage', 'name' => 'Хранение на складе WB'],
            ['code' => 'wb_acceptance', 'name' => 'Платная приёмка WB'],
            ['code' => 'wb_deduction', 'name' => 'Прочие удержания WB'],
            ['code' => 'wb_penalty', 'name' => 'Штрафы WB'],
            ['code' => 'wb_additional_payment', 'name' => 'Доплаты WB'],
            ['code' => 'wb_advertising', 'name' => 'Реклама на WB'],

            // Ozon (для будущего)
            ['code' => 'ozon_commission', 'name' => 'Комиссия Ozon'],
            ['code' => 'ozon_logistics', 'name' => 'Логистика Ozon'],
            ['code' => 'ozon_storage', 'name' => 'Хранение на складе Ozon'],
            ['code' => 'ozon_advertising', 'name' => 'Реклама на Ozon'],
        ];

        foreach ($categories as $cat) {
            // Проверяем существует ли уже
            $existing = $manager->getRepository(MarketplaceCostCategory::class)
                ->findOneBy([
                    'company' => $company,
                    'code' => $cat['code']
                ]);

            if ($existing) {
                continue; // Пропускаем если уже есть
            }

            $category = new MarketplaceCostCategory(
                Uuid::uuid4()->toString(),
                $company
            );
            $category->setCode($cat['code']);
            $category->setName($cat['name']);

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

<?php

namespace App\DataFixtures;

use App\Company\Entity\Company;
use App\Entity\PLCategory;
use App\Entity\ProjectDirection;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;

final class MarketplaceSaleMappingFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        /** @var Company $company */
        $company = $this->getReference(AppFixtures::REF_COMPANY_ROMASHKA, Company::class);
        /** @var ProjectDirection $projectWb */
        $projectWb = $this->getReference(ProjectDirectionsFixtures::REF_PD_WB, ProjectDirection::class);

        $grossRevenue = $this->getReference(PLCategoryFixtures::REF_MP_GROSS_REVENUE, PLCategory::class);
        $netRevenue = $this->getReference(PLCategoryFixtures::REF_MP_NET_REVENUE, PLCategory::class);
        $returns = $this->getReference(PLCategoryFixtures::REF_MP_RETURNS, PLCategory::class);
        $cogsMaterials = $this->getReference(PLCategoryFixtures::REF_COGS_MATERIALS, PLCategory::class);

        /*
         * Итоговый ОПиУ:
         *
         *   Выкупы без СПП          +100 000  (SALE_GROSS − RETURN_GROSS)
         *   Выручка с СПП            +85 000  (SALE_REVENUE)
         *   Возвраты (−)              −5 000  (RETURN_REFUND)
         *   ──────────────────────────────────
         *   Себестоимость            −30 000  (SALE_COST_PRICE − RETURN_COST_PRICE)
         */
        $mappings = [
            // ── Продажи ──
            [
                'source' => AmountSource::SALE_GROSS,
                'category' => $grossRevenue,
                'negative' => false,
                'description' => 'Выкупы без СПП — WB',
                'sort' => 1,
            ],
            [
                'source' => AmountSource::SALE_REVENUE,
                'category' => $netRevenue,
                'negative' => false,
                'description' => 'Выручка с СПП — WB',
                'sort' => 2,
            ],
            [
                'source' => AmountSource::SALE_COST_PRICE,
                'category' => $cogsMaterials,
                'negative' => true,
                'description' => 'Себестоимость продаж — WB',
                'sort' => 3,
            ],

            // ── Возвраты ──
            [
                'source' => AmountSource::RETURN_REFUND,
                'category' => $returns,
                'negative' => true,
                'description' => 'Возвраты — WB',
                'sort' => 4,
            ],
            [
                'source' => AmountSource::RETURN_GROSS,
                'category' => $grossRevenue,
                'negative' => true,
                'description' => 'Возврат выкупов без СПП — WB',
                'sort' => 5,
            ],
            [
                'source' => AmountSource::RETURN_COST_PRICE,
                'category' => $cogsMaterials,
                'negative' => false,
                'description' => 'Возврат себестоимости — WB',
                'sort' => 6,
            ],
        ];

        foreach ($mappings as $data) {
            $mapping = new MarketplaceSaleMapping(
                Uuid::uuid4()->toString(),
                $company,
                MarketplaceType::WILDBERRIES,
                $data['source'],
                $data['category'],
            );
            $mapping->setProjectDirection($projectWb);
            $mapping->setIsNegative($data['negative']);
            $mapping->setDescriptionTemplate($data['description']);
            $mapping->setSortOrder($data['sort']);

            $manager->persist($mapping);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            AppFixtures::class,
            PLCategoryFixtures::class,
            ProjectDirectionsFixtures::class,
        ];
    }
}

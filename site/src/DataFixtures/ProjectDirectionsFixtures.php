<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\ProjectDirection;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Nonstandard\Uuid;

final class ProjectDirectionsFixtures extends Fixture implements DependentFixtureInterface
{
    public const REF_PD_WB = 'pd.wb';
    public const REF_PD_OZON = 'pd.ozon';
    public const REF_PD_SHOP = 'pd.shop';
    public const REF_PD_GENERAL = 'pd.general';

    public function load(ObjectManager $manager): void
    {
        /** @var Company $company */
        $company = $this->getReference(AppFixtures::REF_COMPANY_ROMASHKA, Company::class);

        $items = [
            [self::REF_PD_WB,      'Продажи на Wildberries'],
            [self::REF_PD_OZON,    'Продажи на Ozon'],
            [self::REF_PD_SHOP,    'Собственный интернет-магазин'],
            [self::REF_PD_GENERAL, 'Общие операции'],
        ];

        foreach ($items as [$ref, $name]) {
            $direction = new ProjectDirection(
                id: Uuid::uuid4()->toString(),
                company: $company,
                name: $name
            );

            $manager->persist($direction);
            $this->addReference($ref, $direction);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }
}

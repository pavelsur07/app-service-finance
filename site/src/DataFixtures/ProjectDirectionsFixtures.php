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

        $make = function (string $name, ?ProjectDirection $parent, int $sort, ?string $ref = null) use ($company, $manager): ProjectDirection {
            $direction = new ProjectDirection(
                id: Uuid::uuid4()->toString(),
                company: $company,
                name: $name
            );

            if (null !== $parent) {
                $direction->setParent($parent);
            }
            $direction->setSort($sort);

            $manager->persist($direction);

            if (null !== $ref) {
                $this->addReference($ref, $direction);
            }

            return $direction;
        };

        $sort = 0;

        $make('Продажи на Wildberries', null, $sort += 10, self::REF_PD_WB);
        $make('Продажи на Ozon', null, $sort += 10, self::REF_PD_OZON);
        $make('Собственный интернет-магазин', null, $sort += 10, self::REF_PD_SHOP);
        $make('Общие операции', null, $sort += 10, self::REF_PD_GENERAL);

        $auto = $make('Автотранспорт', null, $sort += 10);
        $auto_car = $make('Машина 1', $auto, 10);
        $make('Водитель 1', $auto_car, 10);
        $make('Водитель 2', $auto_car, 20);

        $clothes = $make('Одежда', null, $sort += 10);
        $leggings = $make('Легинсы', $clothes, 10);
        $make('Модель 1', $leggings, 10);
        $make('Модель 2', $leggings, 20);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }
}

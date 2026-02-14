<?php

namespace App\DataFixtures;

use App\Company\Entity\Company;
use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
use App\Entity\ProjectDirection;
use App\Enum\DocumentType;
use App\Service\PLRegisterUpdater;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Nonstandard\Uuid;

final class PLDocumentsFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(private readonly PLRegisterUpdater $plRegisterUpdater)
    {
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Company $company */
        $company = $this->getReference(AppFixtures::REF_COMPANY_ROMASHKA, Company::class);
        /** @var ProjectDirection $projectWb */
        $projectWb = $this->getReference(ProjectDirectionsFixtures::REF_PD_WB, ProjectDirection::class);
        /** @var ProjectDirection $projectOzon */
        $projectOzon = $this->getReference(ProjectDirectionsFixtures::REF_PD_OZON, ProjectDirection::class);
        /** @var ProjectDirection $projectGeneral */
        $projectGeneral = $this->getReference(ProjectDirectionsFixtures::REF_PD_GENERAL, ProjectDirection::class);

        $salesWb = $this->getReference(PLCategoryFixtures::REF_SALES_WB, PLCategory::class);
        $salesOzon = $this->getReference(PLCategoryFixtures::REF_SALES_OZON, PLCategory::class);
        $cogsMaterials = $this->getReference(PLCategoryFixtures::REF_COGS_MATERIALS, PLCategory::class);
        $cogsProduction = $this->getReference(PLCategoryFixtures::REF_COGS_PRODUCTION, PLCategory::class);
        $opexMarketing = $this->getReference(PLCategoryFixtures::REF_OPEX_MARKETING, PLCategory::class);
        $opexRent = $this->getReference(PLCategoryFixtures::REF_OPEX_RENT, PLCategory::class);

        $startDate = (new \DateTimeImmutable('first day of -2 months'))->setTime(10, 0);
        $today = new \DateTimeImmutable('today');

        $months = [
            [
                'date' => $startDate,
                'income1' => '220000.00',
                'income2' => '180000.00',
                'variable1' => '90000.00',
                'variable2' => '60000.00',
                'opex1' => '40000.00',
                'opex2' => '30000.00',
            ],
            [
                'date' => $startDate->modify('+1 month'),
                'income1' => '250000.00',
                'income2' => '200000.00',
                'variable1' => '100000.00',
                'variable2' => '70000.00',
                'opex1' => '45000.00',
                'opex2' => '35000.00',
            ],
            [
                'date' => $startDate->modify('+2 month'),
                'income1' => '280000.00',
                'income2' => '230000.00',
                'variable1' => '110000.00',
                'variable2' => '80000.00',
                'opex1' => '50000.00',
                'opex2' => '40000.00',
            ],
        ];

        foreach ($months as $idx => $monthData) {
            $date = $monthData['date'];
            $monthNo = $idx + 1;

            $this->createDocument($manager, $company, $date, $projectWb, $salesWb, $monthData['income1'], "M{$monthNo} income WB");
            $this->createDocument($manager, $company, $date->modify('+1 day'), $projectOzon, $salesOzon, $monthData['income2'], "M{$monthNo} income Ozon");

            $this->createDocument($manager, $company, $date->modify('+2 day'), $projectGeneral, $cogsMaterials, $monthData['variable1'], "M{$monthNo} variable materials");
            $this->createDocument($manager, $company, $date->modify('+3 day'), $projectGeneral, $cogsProduction, $monthData['variable2'], "M{$monthNo} variable production");

            $this->createDocument($manager, $company, $date->modify('+4 day'), $projectGeneral, $opexMarketing, $monthData['opex1'], "M{$monthNo} opex marketing");
            $this->createDocument($manager, $company, $date->modify('+5 day'), $projectGeneral, $opexRent, $monthData['opex2'], "M{$monthNo} opex rent");
        }

        $manager->flush();

        $this->plRegisterUpdater->recalcRange($company, $startDate, $today);
    }

    private function createDocument(
        ObjectManager $manager,
        Company $company,
        \DateTimeImmutable $date,
        ProjectDirection $projectDirection,
        PLCategory $category,
        string $amount,
        string $description,
    ): void {
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setDate($date);
        $document->setType(DocumentType::OTHER);
        $document->setProjectDirection($projectDirection);
        $document->setDescription($description);

        $operation = new DocumentOperation(Uuid::uuid4()->toString());
        $operation->setCategory($category);
        $operation->setAmount($amount);
        $operation->setProjectDirection($projectDirection);
        $operation->setComment($description);

        $document->addOperation($operation);

        $manager->persist($document);
    }

    public function getDependencies(): array
    {
        return [
            AppFixtures::class,
            ProjectDirectionsFixtures::class,
            PLCategoryFixtures::class,
        ];
    }
}

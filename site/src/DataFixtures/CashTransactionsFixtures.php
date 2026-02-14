<?php

namespace App\DataFixtures;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Entity\ProjectDirection;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Nonstandard\Uuid;

final class CashTransactionsFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        /** @var Company $company */
        $company = $this->getReference(AppFixtures::REF_COMPANY_ROMASHKA, Company::class);
        /** @var MoneyAccount $accAlfa */
        $accAlfa = $this->getReference(AppFixtures::REF_ACC_ALFA, MoneyAccount::class);
        /** @var MoneyAccount $accSber */
        $accSber = $this->getReference(AppFixtures::REF_ACC_SBER, MoneyAccount::class);
        /** @var ProjectDirection $project */
        $project = $this->getReference(ProjectDirectionsFixtures::REF_PD_GENERAL, ProjectDirection::class);

        $monthMinus2 = (new \DateTimeImmutable('first day of -2 months'))->setTime(10, 0);
        $monthMinus1 = (new \DateTimeImmutable('first day of -1 months'))->setTime(10, 0);
        $monthCurrent = (new \DateTimeImmutable('first day of this month'))->setTime(10, 0);

        $categoryRepo = $manager->getRepository(CashflowCategory::class);
        $catByName = fn (string $name): CashflowCategory => $categoryRepo->findOneBy(['company' => $company, 'name' => $name])
            ?? throw new \RuntimeException(sprintf('Cashflow category "%s" not found', $name));

        $sales = $catByName('Продажи');
        $rent = $catByName('Аренда');
        $capex = $catByName('CAPEX');
        $refundSupplier = $catByName('REFUND_SUPPLIER');
        $internalTransfer = $catByName('INTERNAL TRANSFER');

        $make = function (
            \DateTimeImmutable $date,
            string $amount,
            CashDirection $direction,
            MoneyAccount $account,
            CashflowCategory $category,
            string $description,
        ) use ($company, $project, $manager): void {
            $tx = new CashTransaction(
                Uuid::uuid4()->toString(),
                $company,
                $account,
                $direction,
                $amount,
                'RUB',
                $date,
            );
            $tx->setCashflowCategory($category);
            $tx->setProjectDirection($project);
            $tx->setDescription($description);

            $manager->persist($tx);
        };

        $make($monthMinus2, '300000.00', CashDirection::INFLOW, $accAlfa, $sales, 'Продажи месяц -2');
        $make($monthMinus2->modify('+1 day'), '120000.00', CashDirection::OUTFLOW, $accAlfa, $rent, 'Аренда месяц -2');
        $make($monthMinus2->modify('+2 day'), '50000.00', CashDirection::OUTFLOW, $accAlfa, $capex, 'CAPEX месяц -2');

        $make($monthMinus1, '350000.00', CashDirection::INFLOW, $accAlfa, $sales, 'Продажи месяц -1');
        $make($monthMinus1->modify('+1 day'), '140000.00', CashDirection::OUTFLOW, $accAlfa, $rent, 'Аренда месяц -1');

        $make($monthCurrent, '400000.00', CashDirection::INFLOW, $accAlfa, $sales, 'Продажи текущий месяц');
        $make($monthCurrent->modify('+1 day'), '160000.00', CashDirection::OUTFLOW, $accAlfa, $rent, 'Аренда текущий месяц');
        $make($monthCurrent->modify('+2 day'), '10000.00', CashDirection::INFLOW, $accAlfa, $refundSupplier, 'Возврат от поставщика');

        $transferDate = $monthCurrent->modify('+3 day');
        $make($transferDate, '50000.00', CashDirection::OUTFLOW, $accAlfa, $internalTransfer, 'Внутренний перевод Альфа → Сбер');
        $make($transferDate, '50000.00', CashDirection::INFLOW, $accSber, $internalTransfer, 'Внутренний перевод Сбер ← Альфа');

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            AppFixtures::class,
            ProjectDirectionsFixtures::class,
            CashflowCategoryFixtures::class,
        ];
    }
}

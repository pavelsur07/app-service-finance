<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fund;

use App\Cash\Service\Accounts\FundBalanceService;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Fund\Factory\MoneyFundFactory;
use App\Tests\Fund\Factory\MoneyFundMovementFactory;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FundBalanceServiceTest extends KernelTestCase
{
    public function testBalancesAndTotalsCalculatedPerFund(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $em->createQuery('DELETE FROM App\\Entity\\MoneyFundMovement m')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\MoneyFund f')->execute();
        $em->createQuery('DELETE FROM App\\Company\\Entity\\Company c')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\User u')->execute();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('fund@example.com');
        $user->setPassword('secret');
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Test Company');

        $fundOne = MoneyFundFactory::create($company, 'RUB', 'Налоги');
        $fundTwo = MoneyFundFactory::create($company, 'RUB', 'Зарплата');

        $otherUser = new User(Uuid::uuid4()->toString());
        $otherUser->setEmail('other@example.com');
        $otherUser->setPassword('secret');
        $otherCompany = new Company(Uuid::uuid4()->toString(), $otherUser);
        $otherCompany->setName('Other');
        $otherFund = MoneyFundFactory::create($otherCompany, 'RUB', 'Чужой фонд');

        $em->persist($user);
        $em->persist($company);
        $em->persist($fundOne);
        $em->persist($fundTwo);
        $em->persist($otherUser);
        $em->persist($otherCompany);
        $em->persist($otherFund);
        $em->flush();

        $movement1 = MoneyFundMovementFactory::create($company, $fundOne, 1000);
        $movement2 = MoneyFundMovementFactory::create($company, $fundOne, -300);
        $movement3 = MoneyFundMovementFactory::create($company, $fundTwo, 500);
        $movementOtherCompany = MoneyFundMovementFactory::create($otherCompany, $otherFund, 700);

        $em->persist($movement1);
        $em->persist($movement2);
        $em->persist($movement3);
        $em->persist($movementOtherCompany);
        $em->flush();

        /** @var FundBalanceService $service */
        $service = $container->get(FundBalanceService::class);

        $balances = $service->getFundBalances($company->getId());
        $map = [];
        foreach ($balances as $row) {
            $map[$row['fundId']] = $row;
        }

        self::assertSame(700, $map[$fundOne->getId()]['balanceMinor']);
        self::assertSame(500, $map[$fundTwo->getId()]['balanceMinor']);

        $totals = $service->getTotals($company->getId());
        self::assertSame(1200, $totals['RUB']);

        $cashTransactions = (int) $em->createQuery('SELECT COUNT(t.id) FROM App\\Entity\\CashTransaction t')->getSingleScalarResult();
        self::assertSame(0, $cashTransactions, 'Movements should not create cash transactions.');
    }
}

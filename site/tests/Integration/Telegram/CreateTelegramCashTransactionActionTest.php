<?php

declare(strict_types=1);

namespace App\Tests\Integration\Telegram;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Telegram\Application\CreateTelegramCashTransactionAction;
use App\Telegram\Application\DTO\CreateTelegramCashTransactionCommand;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateTelegramCashTransactionActionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CreateTelegramCashTransactionAction $action;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->action = self::getContainer()->get(CreateTelegramCashTransactionAction::class);
    }

    public function testDuplicatePayloadCreatesSingleTransaction(): void
    {
        [$companyId, $moneyAccountId] = $this->createCompanyAndAccount(101, '33333333-3333-3333-3333-000000000101');

        $command = new CreateTelegramCashTransactionCommand('bot-1', $companyId, $moneyAccountId, 'RUB', '100', '200', '300', 10, 1700000000, 'потратил 2500 на рекламу');

        $first = ($this->action)($command);
        $second = ($this->action)($command);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertFalse($first->duplicate);
        self::assertTrue($second->duplicate);
        self::assertSame(1, $this->countTelegramTransactions($companyId, $moneyAccountId));
    }

    public function testDifferentMessageIdCreatesTwoTransactions(): void
    {
        [$companyId, $moneyAccountId] = $this->createCompanyAndAccount(202, '33333333-3333-3333-3333-000000000202');

        ($this->action)(new CreateTelegramCashTransactionCommand('bot-1', $companyId, $moneyAccountId, 'RUB', '100', '200', '300', 10, 1700000000, 'потратил 2500 на рекламу'));
        ($this->action)(new CreateTelegramCashTransactionCommand('bot-1', $companyId, $moneyAccountId, 'RUB', '100', '201', '300', 11, 1700000001, 'потратил 2500 на рекламу'));

        self::assertSame(2, $this->countTelegramTransactions($companyId, $moneyAccountId));
    }

    private function createCompanyAndAccount(int $companyIndex, string $moneyAccountId): array
    {
        $owner = UserBuilder::aUser()->withIndex($companyIndex)->build();
        $company = CompanyBuilder::aCompany()->withIndex($companyIndex)->withOwner($owner)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId($moneyAccountId)->forCompany($company)->build();
        $project = new ProjectDirection(
            sprintf('44444444-4444-4444-4444-%012d', $companyIndex),
            $company,
            'Общий',
            ProjectDirection::CODE_GENERAL,
        );
        $center = new FinancialResponsibilityCenter(
            $company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->persist($project);
        $this->em->persist($center);
        $this->em->persist(new FinancialResponsibilityCenterProject($company->getId(), $project, $center));
        $this->em->flush();

        return [$company->getId(), $account->getId()];
    }

    private function countTelegramTransactions(string $companyId, string $moneyAccountId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(CashTransaction::class, 't')
            ->andWhere('t.importSource = :source')
            ->andWhere('IDENTITY(t.company) = :companyId')
            ->andWhere('IDENTITY(t.moneyAccount) = :moneyAccountId')
            ->setParameter('source', 'telegram')
            ->setParameter('companyId', $companyId)
            ->setParameter('moneyAccountId', $moneyAccountId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

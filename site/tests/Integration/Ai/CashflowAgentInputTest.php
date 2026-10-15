<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Ai\Dto\CashflowAgentInput;
use App\Ai\Service\Agent\CashflowAgent;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\PaymentPlan\PaymentPlanStatus;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Регрессия этапа 7.3 плана Cash: сбор данных AI-агента ДДС падал на DQL —
 * алиас `category` занят join'ом, а в CASE чистого потока не было END. Агент
 * не доходил до LLM ни разу.
 */
final class CashflowAgentInputTest extends IntegrationTestCase
{
    public function testBuildInputCollectsCashDataWithoutQueryErrors(): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('agent-input@example.com')->withPasswordHash('p')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Банк', 'RUB');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('-1 year'));
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Аренда');
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->persist($category);
        $inflow = $this->tx($company, $account, CashDirection::INFLOW, '100.00', '-3 days');
        $inflow->replaceSplits([new CashTransactionSplit($inflow, $category, '100.00', CashTransactionSplitSource::MANUAL)]);
        $this->tx($company, $account, CashDirection::OUTFLOW, '40.00', '-2 days');
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $company, $category, new \DateTimeImmutable('+5 days'), '15.00');
        $plan->setStatus(PaymentPlanStatus::PLANNED);
        $this->em->persist($plan);
        $this->em->flush();

        $agent = self::getContainer()->get(CashflowAgent::class);
        $input = (new \ReflectionMethod($agent, 'buildInput'))->invoke($agent, $company);

        self::assertInstanceOf(CashflowAgentInput::class, $input);
        self::assertSame(['inflow' => 100.0, 'outflow' => 0.0], $input->totalsByCategory['Аренда']);
        self::assertSame(['inflow' => 0.0, 'outflow' => 40.0], $input->totalsByCategory['Без категории']);
        self::assertSame(100.0, $input->dailyBalances[(new \DateTimeImmutable('-3 days'))->format('Y-m-d')]);
        self::assertSame(60.0, $input->dailyBalances[(new \DateTimeImmutable('-2 days'))->format('Y-m-d')]);
        self::assertSame('Аренда', $input->upcomingPayments[0]['description']);
        self::assertSame(15.0, $input->upcomingPayments[0]['amount']);
    }

    private function tx(Company $company, MoneyAccount $account, CashDirection $direction, string $amount, string $date): CashTransaction
    {
        $tx = new CashTransaction(Uuid::uuid4()->toString(), $company, $account, $direction, $amount, 'RUB', new \DateTimeImmutable($date));
        $this->em->persist($tx);

        return $tx;
    }
}

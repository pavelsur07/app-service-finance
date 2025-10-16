<?php

namespace App\Tests\Integration\PaymentPlan;

use App\Entity\CashTransaction;
use App\Entity\CashflowCategory;
use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Entity\PaymentPlan;
use App\Entity\PaymentPlanMatch;
use App\Entity\User;
use App\Enum\CashDirection;
use App\Enum\CounterpartyType;
use App\Enum\MoneyAccountType;
use App\Enum\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Repository\PaymentPlanMatchRepository;
use App\Repository\PaymentPlanRepository;
use App\Service\PaymentPlan\PaymentPlanMatcher;
use App\Service\PaymentPlan\PaymentPlanService;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class TestManagerRegistry implements ManagerRegistry
{
    public function __construct(private EntityManager $em)
    {
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection($name = null): object
    {
        return $this->em->getConnection();
    }

    public function getConnections(): array
    {
        return [$this->em->getConnection()];
    }

    public function getConnectionNames(): array
    {
        return ['default'];
    }

    public function getDefaultManagerName(): string
    {
        return 'default';
    }

    public function getManager($name = null): \Doctrine\Persistence\ObjectManager
    {
        return $this->em;
    }

    public function getManagers(): array
    {
        return ['default' => $this->em];
    }

    public function resetManager($name = null): \Doctrine\Persistence\ObjectManager
    {
        return $this->em;
    }

    public function getAliasNamespace($alias)
    {
        return 'App\\Entity';
    }

    public function getManagerNames(): array
    {
        return ['default'];
    }

    public function getRepository($persistentObject, $persistentManagerName = null): \Doctrine\Persistence\ObjectRepository
    {
        return $this->em->getRepository($persistentObject);
    }

    public function getManagerForClass($class): ?\Doctrine\Persistence\ObjectManager
    {
        return $this->em;
    }
}

final class PaymentPlanMatcherTest extends TestCase
{
    private EntityManager $em;
    private PaymentPlanMatcher $matcher;
    private PaymentPlanMatchRepository $matchRepository;

    protected function setUp(): void
    {
        $config = Setup::createAttributeMetadataConfiguration([__DIR__.'/../../../src/Entity'], true);
        $conn = ['driver' => 'pdo_sqlite', 'memory' => true];
        $this->em = EntityManager::create($conn, $config);
        $schemaTool = new SchemaTool($this->em);
        $classes = [
            $this->em->getClassMetadata(User::class),
            $this->em->getClassMetadata(Company::class),
            $this->em->getClassMetadata(MoneyAccount::class),
            $this->em->getClassMetadata(CashTransaction::class),
            $this->em->getClassMetadata(CashflowCategory::class),
            $this->em->getClassMetadata(Counterparty::class),
            $this->em->getClassMetadata(PaymentPlan::class),
            $this->em->getClassMetadata(PaymentPlanMatch::class),
        ];
        $schemaTool->createSchema($classes);

        $registry = new TestManagerRegistry($this->em);
        $planRepository = new PaymentPlanRepository($registry);
        $this->matchRepository = new PaymentPlanMatchRepository($registry);
        $this->matcher = new PaymentPlanMatcher($this->em, $planRepository, $this->matchRepository, new PaymentPlanService());
    }

    public function testMatchesBestCandidateWithPriorities(): void
    {
        [$company, $account, $category, $counterparty] = $this->createCompanyContext('Main Co');
        $otherCategory = $this->createCategory($company, 'Other Category');
        $otherCounterparty = $this->createCounterparty($company, 'Other Counterparty');
        $txDate = new \DateTimeImmutable('2024-05-10');
        $transaction = $this->createTransaction($company, $account, $txDate, '100.00', $category, $counterparty);

        $bestPlan = $this->createPlan($company, $category, $counterparty, $txDate, '100.00');
        $this->createPlan($company, $category, $counterparty, $txDate, '104.00');
        $this->createPlan($company, $category, $counterparty, $txDate->modify('-1 day'), '100.00');
        $this->createPlan($company, $otherCategory, $otherCounterparty, $txDate, '100.00');

        $this->em->flush();

        $matchedPlan = $this->matcher->matchForTransaction($transaction);

        self::assertNotNull($matchedPlan);
        self::assertSame($bestPlan->getId(), $matchedPlan->getId());
        self::assertSame(PaymentPlanStatusEnum::PAID, $bestPlan->getStatus());

        $match = $this->matchRepository->findOneByTransaction($transaction);
        self::assertNotNull($match);
        self::assertSame($bestPlan->getId(), $match->getPlan()->getId());
    }

    public function testReturnsNullWhenNoCandidates(): void
    {
        [$company, $account, $category, $counterparty] = $this->createCompanyContext('Empty Co');
        $transaction = $this->createTransaction($company, $account, new \DateTimeImmutable('2024-06-01'), '100.00', $category, $counterparty);
        $this->createPlan($company, $category, $counterparty, new \DateTimeImmutable('2024-06-01'), '120.00');
        $this->em->flush();

        $result = $this->matcher->matchForTransaction($transaction);

        self::assertNull($result);
        self::assertNull($this->matchRepository->findOneByTransaction($transaction));
    }

    public function testReturnsExistingMatchWhenTransactionAlreadyMatched(): void
    {
        [$company, $account, $category, $counterparty] = $this->createCompanyContext('Repeat Co');
        $txDate = new \DateTimeImmutable('2024-07-15');
        $transaction = $this->createTransaction($company, $account, $txDate, '50.00', $category, $counterparty);
        $plan = $this->createPlan($company, $category, $counterparty, $txDate, '50.00');
        $this->em->flush();

        $firstMatch = $this->matcher->matchForTransaction($transaction);
        self::assertNotNull($firstMatch);
        self::assertSame($plan->getId(), $firstMatch->getId());
        self::assertCount(1, $this->matchRepository->findAll());

        $secondMatch = $this->matcher->matchForTransaction($transaction);
        self::assertNotNull($secondMatch);
        self::assertSame($plan->getId(), $secondMatch->getId());
        self::assertCount(1, $this->matchRepository->findAll());
    }

    public function testDoesNotMatchPlansFromAnotherCompany(): void
    {
        [$companyA, $accountA, $categoryA, $counterpartyA] = $this->createCompanyContext('Company A');
        [$companyB, $accountB, $categoryB, $counterpartyB] = $this->createCompanyContext('Company B');

        $txDate = new \DateTimeImmutable('2024-08-20');
        $transaction = $this->createTransaction($companyA, $accountA, $txDate, '75.00', $categoryA, $counterpartyA);

        $this->createPlan($companyB, $categoryB, $counterpartyB, $txDate, '75.00');
        $this->em->flush();

        $result = $this->matcher->matchForTransaction($transaction);

        self::assertNull($result);
        self::assertNull($this->matchRepository->findOneByTransaction($transaction));
    }

    /**
     * @return array{Company, MoneyAccount, CashflowCategory, Counterparty}
     */
    private function createCompanyContext(string $name): array
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail(strtolower(str_replace(' ', '', $name)).'@example.com');
        $user->setPassword('secret');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName($name);

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, $name.' Account', 'RUB');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $category = $this->createCategory($company, $name.' Category');
        $counterparty = $this->createCounterparty($company, $name.' Counterparty');

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->persist($category);
        $this->em->persist($counterparty);
        $this->em->flush();

        return [$company, $account, $category, $counterparty];
    }

    private function createCategory(Company $company, string $name): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        $this->em->persist($category);

        return $category;
    }

    private function createCounterparty(Company $company, string $name): Counterparty
    {
        $counterparty = new Counterparty(Uuid::uuid4()->toString(), $company, $name, CounterpartyType::CUSTOMER);
        $this->em->persist($counterparty);

        return $counterparty;
    }

    private function createTransaction(Company $company, MoneyAccount $account, \DateTimeImmutable $date, string $amount, CashflowCategory $category, Counterparty $counterparty): CashTransaction
    {
        $transaction = new CashTransaction(Uuid::uuid4()->toString(), $company, $account, CashDirection::OUTFLOW, $amount, 'RUB', $date);
        $transaction->setCashflowCategory($category);
        $transaction->setCounterparty($counterparty);
        $this->em->persist($transaction);
        $this->em->flush();

        return $transaction;
    }

    private function createPlan(Company $company, CashflowCategory $category, ?Counterparty $counterparty, \DateTimeImmutable $date, string $amount): PaymentPlan
    {
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $company, $category, $date, $amount);
        $plan->setStatus(PaymentPlanStatusEnum::APPROVED);
        $plan->setCounterparty($counterparty);
        $this->em->persist($plan);

        return $plan;
    }
}

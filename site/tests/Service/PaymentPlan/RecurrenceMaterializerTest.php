<?php

namespace App\Tests\Service\PaymentPlan;

use App\Domain\PaymentPlan\PaymentPlanType as PaymentPlanTypeValue;
use App\Entity\CashflowCategory;
use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Entity\PaymentPlan;
use App\Entity\PaymentRecurrenceRule;
use App\Entity\User;
use App\Enum\CounterpartyType;
use App\Enum\MoneyAccountType;
use App\Enum\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Enum\PaymentPlanType as PaymentPlanTypeEnum;
use App\Repository\PaymentPlanRepository;
use App\Repository\PaymentRecurrenceRuleRepository;
use App\Service\PaymentPlan\PaymentPlanService;
use App\Service\PaymentPlan\PaymentRecurrenceService;
use App\Service\PaymentPlan\RecurrenceMaterializer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class RecurrenceMaterializerTest extends TestCase
{
    public function testMaterializeCreatesMissingPlans(): void
    {
        $company = $this->createCompany();
        $category = $this->createCategory($company, 'Повторяющийся платёж');
        $rule = new PaymentRecurrenceRule(Uuid::uuid4()->toString(), $company, PaymentRecurrenceRule::FREQUENCY_MONTHLY);

        $plan = new PaymentPlan(
            Uuid::uuid4()->toString(),
            $company,
            $category,
            new \DateTimeImmutable('2024-01-01'),
            '100.00'
        );
        $plan->setRecurrenceRule($rule);
        $plan->setComment('Шаблон');
        $plan->setStatus(PaymentPlanStatusEnum::PLANNED);

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Счёт', 'RUB');
        $counterparty = new Counterparty(Uuid::uuid4()->toString(), $company, 'Контрагент', CounterpartyType::LEGAL_ENTITY);
        $plan->setMoneyAccount($account);
        $plan->setCounterparty($counterparty);

        $firstOccurrence = clone $plan;
        $firstOccurrence->setPlannedAt(new \DateTimeImmutable('2024-02-01'));

        $secondOccurrence = clone $plan;
        $secondOccurrence->setPlannedAt(new \DateTimeImmutable('2024-03-01'));

        $ruleRepository = $this->createMock(PaymentRecurrenceRuleRepository::class);
        $ruleRepository->expects($this->once())
            ->method('findActiveByCompany')
            ->with($company)
            ->willReturn([$rule]);

        $planRepository = $this->createMock(PaymentPlanRepository::class);
        $planRepository->expects($this->once())
            ->method('findTemplateForRecurrenceRule')
            ->with($rule)
            ->willReturn($plan);
        $planRepository->expects($this->exactly(2))
            ->method('existsRecurrenceDuplicate')
            ->withConsecutive(
                [
                    $this->identicalTo($company),
                    $this->identicalTo($rule),
                    $this->callback(static fn (\DateTimeInterface $date) => '2024-02-01' === $date->format('Y-m-d')),
                    '100.00',
                    $this->identicalTo($category),
                ],
                [
                    $this->identicalTo($company),
                    $this->identicalTo($rule),
                    $this->callback(static fn (\DateTimeInterface $date) => '2024-03-01' === $date->format('Y-m-d')),
                    '100.00',
                    $this->identicalTo($category),
                ]
            )
            ->willReturnOnConsecutiveCalls(false, true);

        $recurrenceService = $this->createMock(PaymentRecurrenceService::class);
        $recurrenceService->expects($this->once())
            ->method('expandOccurrences')
            ->with($plan, $this->isInstanceOf(\DateTimeInterface::class), $this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn([$firstOccurrence, $secondOccurrence]);

        $planService = $this->createMock(PaymentPlanService::class);
        $planService->expects($this->once())
            ->method('resolveTypeByCategory')
            ->with($category)
            ->willReturn(PaymentPlanTypeValue::OUTFLOW);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (PaymentPlan $created) use ($company, $category, $rule, $account, $counterparty) {
                self::assertSame($company, $created->getCompany());
                self::assertSame($category, $created->getCashflowCategory());
                self::assertSame('2024-02-01', $created->getPlannedAt()->format('Y-m-d'));
                self::assertSame('100.00', $created->getAmount());
                self::assertSame($account, $created->getMoneyAccount());
                self::assertSame($counterparty, $created->getCounterparty());
                self::assertSame('Шаблон', $created->getComment());
                self::assertSame($rule, $created->getRecurrenceRule());
                self::assertSame(PaymentPlanStatusEnum::PLANNED, $created->getStatus());
                self::assertSame(PaymentPlanTypeEnum::OUTFLOW, $created->getType());

                return true;
            }));
        $entityManager->expects($this->once())->method('flush');

        $materializer = new RecurrenceMaterializer(
            $ruleRepository,
            $planRepository,
            $recurrenceService,
            $planService,
            $entityManager
        );

        $createdCount = $materializer->materialize(
            $company,
            new \DateTimeImmutable('2024-02-01'),
            new \DateTimeImmutable('2024-04-01')
        );

        self::assertSame(1, $createdCount);
    }

    public function testMaterializeSkipsWhenRangeInvalid(): void
    {
        $company = $this->createCompany();

        $ruleRepository = $this->createMock(PaymentRecurrenceRuleRepository::class);
        $ruleRepository->expects($this->never())->method('findActiveByCompany');

        $planRepository = $this->createMock(PaymentPlanRepository::class);
        $planRepository->expects($this->never())->method('findTemplateForRecurrenceRule');
        $planRepository->expects($this->never())->method('existsRecurrenceDuplicate');

        $recurrenceService = $this->createMock(PaymentRecurrenceService::class);
        $planService = $this->createMock(PaymentPlanService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $materializer = new RecurrenceMaterializer(
            $ruleRepository,
            $planRepository,
            $recurrenceService,
            $planService,
            $entityManager
        );

        $created = $materializer->materialize(
            $company,
            new \DateTimeImmutable('2024-05-01'),
            new \DateTimeImmutable('2024-04-01')
        );

        self::assertSame(0, $created);
    }

    private function createCategory(Company $company, string $name): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);

        return $category;
    }

    private function createCompany(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('user@example.com');

        return new Company(Uuid::uuid4()->toString(), $user);
    }
}

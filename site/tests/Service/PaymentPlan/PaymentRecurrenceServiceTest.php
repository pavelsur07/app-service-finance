<?php

namespace App\Tests\Service\PaymentPlan;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Service\PaymentPlan\PaymentRecurrenceService;
use App\Entity\Company;
use App\Entity\PaymentPlan;
use App\Entity\PaymentRecurrenceRule;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class PaymentRecurrenceServiceTest extends TestCase
{
    public function testExpandOccurrencesWeeklyIncludesBoundaries(): void
    {
        $plan = $this->createPlan('2024-01-01');
        $rule = new PaymentRecurrenceRule(Uuid::uuid4()->toString(), $plan->getCompany(), PaymentRecurrenceRule::FREQUENCY_WEEKLY);
        $rule->setInterval(1);
        $rule->setByDay('MO');
        $plan->setRecurrenceRule($rule);

        $service = new PaymentRecurrenceService();

        $from = new \DateTimeImmutable('2024-01-08');
        $to = new \DateTimeImmutable('2024-01-22');

        $occurrences = $service->expandOccurrences($plan, $from, $to);

        self::assertCount(3, $occurrences);
        $dates = array_map(static fn (PaymentPlan $p) => $p->getPlannedAt()->format('Y-m-d'), $occurrences);
        self::assertSame(['2024-01-08', '2024-01-15', '2024-01-22'], $dates);
        self::assertFalse(in_array('2024-01-01', $dates, true));
    }

    public function testExpandOccurrencesMonthlyAdjustsLongMonths(): void
    {
        $plan = $this->createPlan('2024-01-31');
        $rule = new PaymentRecurrenceRule(Uuid::uuid4()->toString(), $plan->getCompany(), PaymentRecurrenceRule::FREQUENCY_MONTHLY);
        $rule->setInterval(1);
        $rule->setDayOfMonth(31);
        $plan->setRecurrenceRule($rule);

        $service = new PaymentRecurrenceService();

        $from = new \DateTimeImmutable('2024-02-01');
        $to = new \DateTimeImmutable('2024-04-30');

        $occurrences = $service->expandOccurrences($plan, $from, $to);

        $dates = array_map(static fn (PaymentPlan $p) => $p->getPlannedAt()->format('Y-m-d'), $occurrences);
        self::assertSame(['2024-02-29', '2024-03-31', '2024-04-30'], $dates);
    }

    public function testExpandOccurrencesWithoutRuleReturnsEmpty(): void
    {
        $plan = $this->createPlan('2024-01-01');

        $service = new PaymentRecurrenceService();

        $occurrences = $service->expandOccurrences($plan, new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2024-12-31'));

        self::assertSame([], $occurrences);
    }

    private function createPlan(string $date): PaymentPlan
    {
        $company = $this->createCompany();
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Категория');

        return new PaymentPlan(
            Uuid::uuid4()->toString(),
            $company,
            $category,
            new \DateTimeImmutable($date),
            '100.00'
        );
    }

    private function createCompany(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('owner@example.com');

        return new Company(Uuid::uuid4()->toString(), $user);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Infrastructure;

use App\Cash\DTO\CashflowCategoryUsage;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Infrastructure\Query\CashflowCategoryUsageQuery;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CashflowCategoryUsageQueryTest extends IntegrationTestCase
{
    public function testCountsEveryReferenceOfCompanyCategoriesOnly(): void
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $foreignUser = UserBuilder::aUser()->withIndex(2)->build();
        $foreign = CompanyBuilder::aCompany()->withIndex(2)->withOwner($foreignUser)->build();
        $rent = CashflowCategoryBuilder::aCashflowCategory()->withIndex(1)->withCompany($company)->withName('Аренда')->build();
        $ads = CashflowCategoryBuilder::aCashflowCategory()->withIndex(2)->withCompany($company)->withName('Реклама')->build();
        $unused = CashflowCategoryBuilder::aCashflowCategory()->withIndex(3)->withCompany($company)->withName('Прочее')->build();
        $foreignCategory = CashflowCategoryBuilder::aCashflowCategory()->withIndex(4)->withCompany($foreign)->withName('Чужая')->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($company)->build();
        $foreignAccount = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($foreign)->build();
        foreach ([$user, $company, $foreignUser, $foreign, $rent, $ads, $unused, $foreignCategory, $account, $foreignAccount] as $entity) {
            $this->em->persist($entity);
        }

        // Аренда: операция со статьёй (строку разбивки на ту же статью создаёт сама операция —
        // считается один раз); удалённая операция; операция, где аренда только в разбивке;
        // платёж; два правила.
        $this->em->persist(CashTransactionBuilder::aCashTransaction()->forCompany($company)->withMoneyAccount($account)->withCashflowCategory($rent)->build());
        $deleted = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withMoneyAccount($account)->withCashflowCategory($rent)->build();
        $deleted->markDeleted(null);
        $this->em->persist($deleted);
        $splitOnly = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withMoneyAccount($account)->withCashflowCategory($ads)->build();
        $this->em->persist($splitOnly);
        $this->em->persist(new CashTransactionSplit($splitOnly, $rent, '40.00', CashTransactionSplitSource::MANUAL));
        $this->em->persist(new PaymentPlan(Uuid::uuid7()->toString(), $company, $rent, new \DateTimeImmutable('2026-10-29'), '100.00'));
        foreach (['Аренда 1', 'Аренда 2'] as $name) {
            $this->em->persist(new CashTransactionAutoRule(Uuid::uuid7()->toString(), $company, $name, CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY, $rent));
        }
        $this->em->persist(CashTransactionBuilder::aCashTransaction()->forCompany($foreign)->withMoneyAccount($foreignAccount)->withCashflowCategory($foreignCategory)->build());
        $this->em->flush();

        $query = self::getContainer()->get(CashflowCategoryUsageQuery::class);
        $usage = $query((string) $company->getId());

        self::assertEquals(
            [
                $rent->getId() => new CashflowCategoryUsage(2, 1, 1, 2),
                $ads->getId() => new CashflowCategoryUsage(1, 0, 0, 0),
            ],
            $usage,
        );
        self::assertSame(
            'По статье 2 операции ДДС, 1 удалённая операция ДДС, 1 платёж в платёжном календаре, 2 правила автокатегоризации.',
            $usage[$rent->getId()]->summary(),
        );
        self::assertEquals([$ads->getId() => new CashflowCategoryUsage(1, 0, 0, 0)], $query((string) $company->getId(), (string) $ads->getId()));
        self::assertSame([], $query((string) $company->getId(), (string) $foreignCategory->getId()));
    }
}

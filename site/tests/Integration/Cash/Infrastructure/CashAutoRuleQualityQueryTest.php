<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Infrastructure;

use App\Cash\Application\DTO\CashAutoRuleQualityPeriod;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashAutoRuleQualityMetric;
use App\Cash\Infrastructure\Query\CashAutoRuleQualityQuery;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CashAutoRuleQualityQueryTest extends IntegrationTestCase
{
    public function testPlaceholderCategoriesAreExcludedAndDeletedOrdinaryCategoryStillCounts(): void
    {
        $user = UserBuilder::aUser()->withIndex(14032)->build();
        $company = CompanyBuilder::aCompany()->withIndex(14032)->withOwner($user)->build();
        $companyId = (string) $company->getId();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $tx = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withMoneyAccount($account)->build();
        $legacy = (new CashflowCategory(Uuid::uuid7()->toString(), $company))->setName('Не распределено')->setCode(CashflowCategory::SYSTEM_UNALLOCATED);
        $current = (new CashflowCategory(Uuid::uuid7()->toString(), $company))->setName('Без статьи')->markAsSystem(CashflowCategory::CODE_UNALLOCATED);
        $ordinary = (new CashflowCategory(Uuid::uuid7()->toString(), $company))->setName('Аренда');
        foreach ([$user, $company, $account, $tx, $legacy, $current, $ordinary] as $entity) {
            $this->em->persist($entity);
        }

        $ruleId = Uuid::uuid7()->toString();
        foreach ([$legacy, $current, $ordinary] as $index => $category) {
            $at = new \DateTimeImmutable(sprintf('2026-11-0%d 10:00:00', $index + 1));
            $this->em->persist(new AuditLog($companyId, CashTransaction::class, (string) $tx->getId(), AuditLogAction::UPDATE, [
                'autoRules' => ['cashflowCategory' => ['id' => $ruleId, 'revision' => $index + 1]],
                'changes' => ['cashflowCategory' => ['before' => null, 'after' => $category->getId()]],
            ], createdAt: $at));
            $this->em->persist(new AuditLog($companyId, CashTransaction::class, (string) $tx->getId(), AuditLogAction::UPDATE, [
                'splits' => [[['category' => $category->getId()]], []],
            ], (string) $user->getId(), $at->modify('+1 minute')));
        }
        $this->em->flush();
        $this->em->remove($ordinary);
        $this->em->flush();

        $period = CashAutoRuleQualityPeriod::fromStrings('2026-11-01', '2026-11-03');
        $query = self::getContainer()->get(CashAutoRuleQualityQuery::class);
        $revisions = $query->summarize($companyId, [$ruleId], $period)[$ruleId];
        self::assertSame([3], array_keys($revisions));
        self::assertSame(1, $revisions[3]->assigned);
        self::assertSame(1, $revisions[3]->corrected);
        self::assertSame(0, $query->operations($companyId, $ruleId, 1, CashAutoRuleQualityMetric::ASSIGNED, $period, 1)->getNbResults());
        self::assertSame(0, $query->operations($companyId, $ruleId, 2, CashAutoRuleQualityMetric::CORRECTED, $period, 1)->getNbResults());
    }

    public function testOnlyImmediatelyPrecedingRevisionGetsCreditForHumanCategoryChange(): void
    {
        $user = UserBuilder::aUser()->withIndex(14031)->build();
        $company = CompanyBuilder::aCompany()->withIndex(14031)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $tx = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withMoneyAccount($account)->build();
        foreach ([$user, $company, $account, $tx] as $entity) {
            $this->em->persist($entity);
        }
        $ruleId = Uuid::uuid7()->toString();
        $companyId = (string) $company->getId();
        $txId = (string) $tx->getId();
        $userId = (string) $user->getId();

        $this->em->persist(new AuditLog($companyId, CashTransaction::class, $txId, AuditLogAction::UPDATE, [
            'autoRules' => ['cashflowCategory' => ['id' => $ruleId, 'revision' => 1]],
            'changes' => ['cashflowCategory' => ['before' => null, 'after' => 'rent']],
        ], createdAt: new \DateTimeImmutable('2026-11-01')));
        $this->em->persist(new AuditLog($companyId, CashTransaction::class, $txId, AuditLogAction::UPDATE, [
            'splits' => [[['category' => 'rent', 'amount' => '10']], [['category' => 'rent', 'amount' => '20']]],
        ], $userId, new \DateTimeImmutable('2026-11-02')));
        $this->em->persist(new AuditLog($companyId, CashTransaction::class, $txId, AuditLogAction::UPDATE, [
            'autoRules' => ['cashflowCategory' => ['id' => $ruleId, 'revision' => 2]],
            'changes' => ['cashflowCategory' => ['before' => 'rent', 'after' => 'licence']],
        ], $userId, new \DateTimeImmutable('2026-11-03 00:00:00.100001')));
        $this->em->persist(new AuditLog($companyId, CashTransaction::class, $txId, AuditLogAction::UPDATE, [
            'splits' => [[['category' => 'licence']], [['category' => 'fee']]],
        ], $userId, new \DateTimeImmutable('2026-11-03 00:00:00.200002')));
        // До повышения точности направление перехода в одну секунду неразличимо:
        // ручной fee → rent предшествовал правилу rent → fee.
        $this->em->persist(new AuditLog($companyId, CashTransaction::class, $txId, AuditLogAction::UPDATE, [
            'splits' => [[['category' => 'fee']], [['category' => 'rent']]],
        ], $userId, new \DateTimeImmutable('2026-11-03 10:00:00')));
        $this->em->persist(new AuditLog($companyId, CashTransaction::class, $txId, AuditLogAction::UPDATE, [
            'autoRules' => ['cashflowCategory' => ['id' => $ruleId, 'revision' => 3]],
            'changes' => ['cashflowCategory' => ['before' => 'rent', 'after' => 'fee']],
        ], createdAt: new \DateTimeImmutable('2026-11-03 10:00:00')));
        $this->em->flush();

        $query = self::getContainer()->get(CashAutoRuleQualityQuery::class);
        $period = CashAutoRuleQualityPeriod::fromStrings('2026-11-01', '2026-11-03');
        $rows = $query->summarize($companyId, [$ruleId], $period)[$ruleId];
        self::assertSame([3, 2, 1], array_keys($rows));
        self::assertSame(1, $rows[3]->assigned);
        self::assertSame(0, $rows[3]->corrected);
        self::assertSame(1, $rows[1]->assigned);
        self::assertSame(0, $rows[1]->corrected);
        self::assertSame(1, $rows[2]->assigned);
        self::assertSame(1, $rows[2]->corrected);
        self::assertSame(0, $rows[2]->conflicts);
        self::assertSame([], $query->summarize(Uuid::uuid7()->toString(), [$ruleId], $period));
        self::assertSame([], $query->summarize($companyId, [$ruleId], CashAutoRuleQualityPeriod::fromStrings('2026-11-04', '2026-11-07')));
    }
}

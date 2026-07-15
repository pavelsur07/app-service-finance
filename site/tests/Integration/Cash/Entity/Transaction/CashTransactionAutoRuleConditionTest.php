<?php

namespace App\Tests\Integration\Cash\Entity\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\OptimisticLockException;
use Ramsey\Uuid\Uuid;

final class CashTransactionAutoRuleConditionTest extends IntegrationTestCase
{
    public function testPersistRuleWithCondition(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('t@example.com');
        $user->setPassword('pass');
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Test Co');
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Sales');

        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Rule',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category
        );

        $condition = new CashTransactionAutoRuleCondition();
        $condition->setAutoRule($rule);
        $condition->setField(CashTransactionAutoRuleConditionField::DESCRIPTION);
        $condition->setOperator(CashTransactionAutoRuleConditionOperator::CONTAINS);
        $condition->setValue('invoice');
        $rule->addCondition($condition);

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($category);
        $this->em->persist($rule);
        $this->em->flush();
        $this->em->clear();

        $repo = $this->em->getRepository(CashTransactionAutoRule::class);
        $saved = $repo->find($rule->getId());
        $this->assertCount(1, $saved->getConditions());
        $this->assertSame('invoice', $saved->getConditions()->first()->getValue());
        $this->assertSame(100, $saved->getPriority());
        $this->assertTrue($saved->isActive());
        $this->assertSame(1, $saved->getRevision());
        $this->assertNotNull($saved->getCreatedAt());
        $this->assertNotNull($saved->getUpdatedAt());
        $this->assertNull($saved->getDisabledAt());
    }

    public function testRejectsStaleRuleUpdate(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('t@example.com');
        $user->setPassword('pass');
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Test Co');
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Sales');
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Rule',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category,
        );

        foreach ([$user, $company, $category, $rule] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $rule->setName('Stale update')->recordUpdate();
        $this->connection->executeStatement(
            'UPDATE cash_transaction_auto_rule SET revision = revision + 1 WHERE id = :id',
            ['id' => $rule->getId()],
        );

        $this->expectException(OptimisticLockException::class);
        $this->em->flush();
    }
}

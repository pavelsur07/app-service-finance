<?php

namespace App\Tests\Entity;

use App\Entity\CashTransactionAutoRule;
use App\Entity\CashTransactionAutoRuleCondition;
use App\Entity\CashflowCategory;
use App\Entity\Company;
use App\Entity\User;
use App\Enum\CashTransactionAutoRuleAction;
use App\Enum\CashTransactionAutoRuleConditionField;
use App\Enum\CashTransactionAutoRuleConditionOperator;
use App\Enum\CashTransactionAutoRuleOperationType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class CashTransactionAutoRuleConditionTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $config = Setup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true);
        $conn = ['driver' => 'pdo_sqlite', 'memory' => true];
        $this->em = EntityManager::create($conn, $config);
        $schemaTool = new SchemaTool($this->em);
        $classes = [
            $this->em->getClassMetadata(User::class),
            $this->em->getClassMetadata(Company::class),
            $this->em->getClassMetadata(CashflowCategory::class),
            $this->em->getClassMetadata(CashTransactionAutoRule::class),
            $this->em->getClassMetadata(CashTransactionAutoRuleCondition::class),
        ];
        $schemaTool->createSchema($classes);
    }

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

        $condition = new CashTransactionAutoRuleCondition(
            Uuid::uuid4()->toString(),
            $rule,
            CashTransactionAutoRuleConditionField::DESCRIPTION,
            CashTransactionAutoRuleConditionOperator::CONTAINS,
            'invoice'
        );
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
    }
}

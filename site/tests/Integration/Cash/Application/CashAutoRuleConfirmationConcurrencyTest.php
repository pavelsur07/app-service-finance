<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\AutoRule\SaveCashTransactionAutoRuleAction;
use App\Cash\Application\Service\AutoRule\CashAutoRuleConfirmation;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Ramsey\Uuid\Uuid;

final class CashAutoRuleConfirmationConcurrencyTest extends PostgresResetTestCase
{
    public function testConcurrentInsertAndReactivationWaitUntilConfirmationCommits(): void
    {
        $actor = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($actor)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build();
        $save = self::getContainer()->get(SaveCashTransactionAutoRuleAction::class);
        $draft = $save->createDraft($company, $actor->getId());
        $draft->setName('Аренда')->setCashflowCategory($category);
        $draft->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'аренда',
        ));
        $inactive = $save->createDraft($company, $actor->getId());
        $inactive->setName('Архив')->setCashflowCategory($category)->disable();
        foreach ([$actor, $company, $category, $inactive] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $actorId = (string) $actor->getId();
        $token = self::getContainer()->get(CashAutoRuleConfirmation::class)->issue($draft, $actorId, 0, []);
        $worker = DriverManager::getConnection($this->connection->getParams());
        $worker->executeStatement("SET lock_timeout = '200ms'");
        $this->em->beginTransaction();

        try {
            $save($draft, null, $actorId, $token);
            try {
                $worker->executeStatement('UPDATE cash_transaction_auto_rule SET is_active = true WHERE id = ?', [$inactive->getId()]);
                self::fail('Включение должно дождаться сохранения подтверждённой версии.');
            } catch (DriverException $error) {
                self::assertSame('55P03', $error->getSQLState());
            }

            $this->expectException(DriverException::class);
            $this->expectExceptionMessage('lock timeout');
            $worker->executeStatement(<<<'SQL'
                INSERT INTO cash_transaction_auto_rule (id, company_id, cashflow_category_id, name, action, operation_type, created_at, updated_at)
                SELECT ?, company_id, cashflow_category_id, name, action, operation_type, NOW(), NOW()
                FROM cash_transaction_auto_rule WHERE id = ?
                SQL, [Uuid::uuid7()->toString(), $inactive->getId()]);
        } finally {
            $this->em->rollback();
            $worker->close();
        }
    }
}

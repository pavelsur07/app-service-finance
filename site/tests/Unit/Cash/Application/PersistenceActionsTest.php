<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\ChangeCashTransactionAutoRuleActivityAction;
use App\Cash\Application\CreateMoneyFundMovementAction;
use App\Cash\Application\DeleteCashflowCategoryAction;
use App\Cash\Application\SaveMoneyAccountAction;
use App\Cash\Application\SaveMoneyFundAction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Infrastructure\Query\CashflowCategoryUsageQuery;
use App\Cash\Repository\Accounts\MoneyFundMovementRepository;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Cash\MoneyFundBuilder;
use App\Tests\Builders\Cash\MoneyFundMovementBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Action'ы — граница транзакции: сохраняют сущность и делают ровно один flush().
 * Этап 5.1 плана Cash: flush() перенесён сюда из сервисов и контроллеров.
 */
final class PersistenceActionsTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    public function testSaveMoneyAccountPersistsAndFlushes(): void
    {
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($this->company())->build();
        $this->expectPersistAndFlush($account);

        (new SaveMoneyAccountAction($this->entityManager))($account);
    }

    public function testSaveMoneyFundPersistsAndFlushes(): void
    {
        $fund = MoneyFundBuilder::aFund()->withCompany($this->company())->build();
        $this->expectPersistAndFlush($fund);

        (new SaveMoneyFundAction($this->entityManager))($fund);
    }

    public function testCreateMoneyFundMovementPersistsAndFlushes(): void
    {
        $company = $this->company();
        $movement = MoneyFundMovementBuilder::aMovement()
            ->withCompany($company)
            ->withFund(MoneyFundBuilder::aFund()->withCompany($company)->build())
            ->build();
        $this->expectPersistAndFlush($movement);

        (new CreateMoneyFundMovementAction(
            $this->entityManager,
            $this->createMock(MoneyFundMovementRepository::class),
            $this->createMock(ManagerRegistry::class),
        ))($movement);
    }

    public function testDeleteCashflowCategoryRemovesAndFlushes(): void
    {
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($this->company())->build();
        $this->entityManager->expects(self::once())->method('remove')->with($category);
        $this->entityManager->expects(self::once())->method('flush');

        (new DeleteCashflowCategoryAction($this->entityManager, $this->usageOfNothing()))($category);
    }

    public function testDeleteSystemCashflowCategoryWritesNothing(): void
    {
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($this->company())->build();
        $category->setIsSystem(true);
        $this->entityManager->expects(self::never())->method('remove');
        $this->entityManager->expects(self::never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Системную категорию нельзя удалить.');

        (new DeleteCashflowCategoryAction($this->entityManager, $this->usageOfNothing()))($category);
    }

    public function testChangeAutoRuleActivityFlushesOnlyOnRealChange(): void
    {
        $rule = $this->rule();
        $actor = Uuid::uuid4()->toString();
        $this->entityManager->expects(self::once())->method('flush');
        $action = new ChangeCashTransactionAutoRuleActivityAction($this->entityManager);

        self::assertTrue($action($rule, false, $actor));
        self::assertFalse($rule->isActive());
        // Повторное выключение — состояние не меняется, flush() не вызывается.
        self::assertFalse($action($rule, false, $actor));
    }

    public function testChangeAutoRuleActivityEnablesDisabledRule(): void
    {
        $rule = $this->rule();
        $actor = Uuid::uuid4()->toString();
        $rule->disable($actor);
        $this->entityManager->expects(self::once())->method('flush');
        $action = new ChangeCashTransactionAutoRuleActivityAction($this->entityManager);

        self::assertTrue($action($rule, true, $actor));
        self::assertTrue($rule->isActive());
        self::assertFalse($action($rule, true, $actor));
    }

    private function expectPersistAndFlush(object $entity): void
    {
        $this->entityManager->expects(self::once())->method('persist')->with($entity);
        $this->entityManager->expects(self::once())->method('flush');
    }

    private function company(): Company
    {
        return CompanyBuilder::aCompany()->withOwner(UserBuilder::aUser()->build())->build();
    }

    private function rule(): CashTransactionAutoRule
    {
        $company = $this->company();

        return new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Rule',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build(),
        );
    }

    private function usageOfNothing(): CashflowCategoryUsageQuery
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);

        return new CashflowCategoryUsageQuery($connection);
    }
}

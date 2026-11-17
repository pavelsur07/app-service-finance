<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\AutoRule\SaveCashTransactionAutoRuleAction;
use App\Cash\Application\Service\AutoRule\CashAutoRuleConfirmation;
use App\Cash\Application\Service\AutoRule\CashTransactionAutoRuleTargetValidator;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SaveCashTransactionAutoRuleActionTest extends TestCase
{
    private const ACTOR_ID = '77777777-7777-4777-8777-777777777777';

    public function testPersistsNewRule(): void
    {
        $rule = $this->rule();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $database = $this->createStub(Connection::class);
        $database->method('fetchOne')->willReturnOnConsecutiveCalls($rule->getCompany()->getId(), false);
        $database->method('fetchAllAssociative')->willReturn([]);
        $entityManager->method('getConnection')->willReturn($database);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static fn (callable $work): bool => $work());
        $persisted = [];
        $entityManager->method('persist')->willReturnCallback(static function (object $record) use (&$persisted): void {
            $persisted[] = $record;
        });

        ($this->action($entityManager))($rule, null, self::ACTOR_ID, $this->signedToken($rule));
        self::assertSame($rule, $persisted[0]);
        self::assertCount(2, $persisted);
    }

    public function testRejectsInvalidRuleWithoutFlush(): void
    {
        $rule = $this->rule();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $violations = new ConstraintViolationList([
            new ConstraintViolation('Добавьте хотя бы одно условие.', null, [], null, 'conditions', null),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Добавьте хотя бы одно условие.');

        ($this->action($entityManager, $violations))($rule, null, self::ACTOR_ID, $this->signedToken($rule));
    }

    public function testRejectsProjectWithoutResponsibilityCenter(): void
    {
        $projectDirection = $this->createMock(ProjectDirection::class);
        $projectDirection->method('getId')->willReturn('33333333-3333-4333-8333-333333333333');

        $rule = $this->rule();
        $rule->setProjectDirection($projectDirection);
        $rule->setResponsibilityCenterId(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Для проекта автоправила укажите ЦФО.');

        ($this->action($entityManager))($rule, null, self::ACTOR_ID, $this->signedToken($rule));
    }

    public function testApprovalCannotBeUsedByAnotherEmployee(): void
    {
        $rule = $this->rule();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');
        $this->expectException(\DomainException::class);
        ($this->action($entityManager))($rule, null, '88888888-8888-4888-8888-888888888888', $this->signedToken($rule));
    }

    private function signedToken(CashTransactionAutoRule $rule): string
    {
        return (new CashAutoRuleConfirmation('test'))->issue($rule, self::ACTOR_ID, 0, []);
    }

    private function rule(): CashTransactionAutoRule
    {
        return new CashTransactionAutoRule(
            '11111111-1111-4111-8111-111111111111',
            CompanyBuilder::aCompany()->build(),
            'Правило',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
        );
    }

    private function action(
        EntityManagerInterface $entityManager,
        ?ConstraintViolationList $violations = null,
    ): SaveCashTransactionAutoRuleAction {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn($violations ?? new ConstraintViolationList());

        $targetValidator = new CashTransactionAutoRuleTargetValidator(
            $this->createMock(FinancialResponsibilityCenterFacade::class),
        );

        return new SaveCashTransactionAutoRuleAction($entityManager, $validator, new CashAutoRuleConfirmation('test'), $targetValidator);
    }
}

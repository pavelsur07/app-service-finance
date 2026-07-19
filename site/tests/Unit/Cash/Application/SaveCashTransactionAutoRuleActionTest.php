<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\SaveCashTransactionAutoRuleAction;
use App\Cash\Application\Service\CashTransactionAutoRuleTargetValidator;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SaveCashTransactionAutoRuleActionTest extends TestCase
{
    public function testPersistsNewRule(): void
    {
        $rule = $this->rule();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('contains')->with($rule)->willReturn(false);
        $entityManager->expects(self::once())->method('persist')->with($rule);
        $entityManager->expects(self::once())->method('flush');

        ($this->action($entityManager))($rule);
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

        ($this->action($entityManager, $violations))($rule);
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

        ($this->action($entityManager))($rule);
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

        return new SaveCashTransactionAutoRuleAction($entityManager, $validator, $targetValidator);
    }
}

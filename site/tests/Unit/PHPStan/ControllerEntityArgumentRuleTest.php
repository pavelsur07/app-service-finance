<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan;

use App\Tests\PHPStan\Rules\CompanyOwnedEntity;
use App\Tests\PHPStan\Rules\ControllerEntityArgumentRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ControllerEntityArgumentRule>
 */
final class ControllerEntityArgumentRuleTest extends RuleTestCase
{
    public function testReportsCompanyEntityAsActionArgument(): void
    {
        $this->analyse([__DIR__.'/data/ControllerScope/Controller/OrderController.php'], [
            [
                'Действие App\\Tests\\Unit\\PHPStan\\data\\ControllerScope\\Controller\\OrderController::show() принимает сущность компании App\\Tests\\Unit\\PHPStan\\data\\ControllerScope\\Entity\\Order аргументом: Entity value resolver загружает её без companyId (IDOR).',
                24,
                'string $id + findOneByIdAndCompanyId($id, $companyId) и 404, если null.',
            ],
            [
                'Действие App\\Tests\\Unit\\PHPStan\\data\\ControllerScope\\Controller\\OrderController::nullable() принимает сущность компании App\\Tests\\Unit\\PHPStan\\data\\ControllerScope\\Entity\\Order аргументом: Entity value resolver загружает её без companyId (IDOR).',
                55,
                'string $id + findOneByIdAndCompanyId($id, $companyId) и 404, если null.',
            ],
        ]);
    }

    public function testIgnoresNonControllers(): void
    {
        $this->analyse([__DIR__.'/data/ControllerScope/Service/OrderLookupService.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ControllerEntityArgumentRule(new CompanyOwnedEntity($this->createReflectionProvider()));
    }
}

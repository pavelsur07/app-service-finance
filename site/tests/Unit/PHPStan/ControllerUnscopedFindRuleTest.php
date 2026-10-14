<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan;

use App\Tests\PHPStan\Rules\CompanyOwnedEntity;
use App\Tests\PHPStan\Rules\ControllerUnscopedFindRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ControllerUnscopedFindRule>
 */
final class ControllerUnscopedFindRuleTest extends RuleTestCase
{
    private const ORDER = 'App\\Tests\\Unit\\PHPStan\\data\\ControllerScope\\Entity\\Order';

    public function testReportsUnscopedFindOfCompanyEntity(): void
    {
        $tip = 'findOneByIdAndCompanyId($id, $companyId) и 404, если null.';

        $this->analyse([__DIR__.'/data/ControllerScope/Controller/OrderController.php'], [
            ['find() сущности компании '.self::ORDER.' в контроллере: загрузка без companyId (IDOR).', 40, $tip],
            ['find() сущности компании '.self::ORDER.' в контроллере: загрузка без companyId (IDOR).', 41, $tip],
            ['findAll() сущности компании '.self::ORDER.' в контроллере: загрузка без companyId (IDOR).', 44, $tip],
            // Репозиторий без generics: сущность — из карты репозиториев.
            ['find() сущности компании '.self::ORDER.' в контроллере: загрузка без companyId (IDOR).', 45, $tip],
            ['find() сущности компании '.self::ORDER.' в контроллере: загрузка без companyId (IDOR).', 46, $tip],
        ]);
    }

    public function testIgnoresNonControllers(): void
    {
        $this->analyse([__DIR__.'/data/ControllerScope/Service/OrderLookupService.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ControllerUnscopedFindRule(new CompanyOwnedEntity(
            $this->createReflectionProvider(),
            null,
            ['App\\Tests\\Unit\\PHPStan\\data\\ControllerScope\\Repository\\LegacyOrderRepository' => 'App\\Tests\\Unit\\PHPStan\\data\\ControllerScope\\Entity\\Order'],
        ));
    }
}

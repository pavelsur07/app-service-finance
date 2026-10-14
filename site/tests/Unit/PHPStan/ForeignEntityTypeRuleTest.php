<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan;

use App\Tests\PHPStan\Rules\ForeignEntityTypeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ForeignEntityTypeRule>
 */
final class ForeignEntityTypeRuleTest extends RuleTestCase
{
    private const CATEGORY = 'App\\Tests\\Unit\\PHPStan\\data\\ForeignEntityType\\ModuleB\\Entity\\Category';
    private const TIP = 'ChoiceType + метод Facade модуля-владельца, который отдаёт DTO.';

    /**
     * Своя сущность и TextType — молчим; чужая — через EntityType, подкласс
     * EntityType, строку класса и именованные аргументы.
     */
    public function testReportsEntityTypeOfAnotherModule(): void
    {
        $this->analyse([__DIR__.'/data/ForeignEntityType/ModuleA/Form/ArticleType.php'], [
            [$this->message(), 22, self::TIP],
            [$this->message(), 24, self::TIP],
            [$this->message(), 25, self::TIP],
            [$this->message(), 26, self::TIP],
        ]);
    }

    public function testIgnoresFormsOutsideRootNamespace(): void
    {
        $this->analyse([__DIR__.'/data/OutsideRoot/Form/OutsideType.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ForeignEntityTypeRule($this->createReflectionProvider(), 'App\\Tests\\Unit\\PHPStan\\data\\ForeignEntityType');
    }

    private function message(): string
    {
        return 'EntityType с сущностью чужого модуля '.self::CATEGORY.' в форме модуля ModuleA — только ChoiceType с данными из Facade (CLAUDE.md, «Формы»).';
    }
}

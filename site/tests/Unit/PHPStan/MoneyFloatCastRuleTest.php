<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan;

use App\Tests\PHPStan\Rules\MoneyFloatCastRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<MoneyFloatCastRule>
 */
final class MoneyFloatCastRuleTest extends RuleTestCase
{
    private const TIP = 'Money::fromString($value, $currency) и методы Money: add/subtract/compareTo/multiplyByRatio.';

    private string $namespacePrefix = 'App\\Tests\\Unit\\PHPStan\\data\\MoneyFloatCast\\';

    public function testReportsFloatCastOfAmountGetters(): void
    {
        $this->analyse([__DIR__.'/data/MoneyFloatCast/AmountCasts.php'], [
            [$this->message('getAmount'), 37, self::TIP],
            [$this->message('getVatAmount'), 38, self::TIP],
            [$this->message('getTotalAmount'), 39, self::TIP],
        ]);
    }

    public function testIgnoresNamespacesOutsideScope(): void
    {
        $this->namespacePrefix = 'App\\Cash\\';

        $this->analyse([__DIR__.'/data/MoneyFloatCast/AmountCasts.php'], []);
    }

    protected function getRule(): Rule
    {
        return new MoneyFloatCastRule([$this->namespacePrefix]);
    }

    private function message(string $getter): string
    {
        return sprintf(
            'Денежная сумма %s() приводится к float — арифметика и сравнение денег только через Money (PATTERNS.md §24).',
            $getter,
        );
    }
}

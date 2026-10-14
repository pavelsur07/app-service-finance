<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Cast\Double;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Денежная сумма не приводится к float (`PATTERNS.md` §24, правило 1).
 *
 * Ловит `(float)` и `floatval()` от вызова геттера суммы — `getAmount()`,
 * `getAllocatedAmount()`, `getVatAmount()` и любого `get*Amount*()`: это самый
 * частый путь, которым decimal-строка из БД попадает в float-арифметику.
 *
 * Чего правило НЕ видит — названо прямо, чтобы зелёный гейт не принимали за
 * доказательство отсутствия float в деньгах:
 *
 * 1. Приведение переменной: `$amount = $tx->getAmount(); (float) $amount`.
 * 2. Геттеры с другими именами (`getTotal()`, `getSum()`) и свойства.
 * 3. Неявное приведение арифметикой: `$tx->getAmount() * 1.2`.
 *
 * Действует только в перечисленных пространствах имён. На дату введения вне
 * Cash таких приведений 11 (Finance 10, Ai 1) — расширение правила на них
 * с baseline — отдельное решение.
 *
 * @implements Rule<Expr>
 */
final class MoneyFloatCastRule implements Rule
{
    private const AMOUNT_GETTER = '/^get\w*Amount\w*$/i';

    /**
     * @param list<string> $namespacePrefixes
     */
    public function __construct(private readonly array $namespacePrefixes)
    {
    }

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $casted = match (true) {
            $node instanceof Double => $node->expr,
            $node instanceof FuncCall && !$node->isFirstClassCallable() && $node->name instanceof Name && 'floatval' === $node->name->toLowerString() && isset($node->getArgs()[0]) => $node->getArgs()[0]->value,
            default => null,
        };

        if (null === $casted || !$this->isInScope($scope)) {
            return [];
        }

        $getter = $this->amountGetterName($casted);
        if (null === $getter) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Денежная сумма %s() приводится к float — арифметика и сравнение денег только через Money (PATTERNS.md §24).',
                $getter,
            ))
                ->identifier('vashfindir.moneyFloatCast')
                ->tip('Money::fromString($value, $currency) и методы Money: add/subtract/compareTo/multiplyByRatio.')
                ->build(),
        ];
    }

    private function amountGetterName(Expr $expr): ?string
    {
        if (!$expr instanceof MethodCall && !$expr instanceof NullsafeMethodCall && !$expr instanceof StaticCall) {
            return null;
        }

        if (!$expr->name instanceof Identifier) {
            return null;
        }

        $name = $expr->name->toString();

        return 1 === preg_match(self::AMOUNT_GETTER, $name) ? $name : null;
    }

    private function isInScope(Scope $scope): bool
    {
        $namespace = ($scope->getNamespace() ?? '').'\\';

        foreach ($this->namespacePrefixes as $prefix) {
            if (str_starts_with($namespace, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

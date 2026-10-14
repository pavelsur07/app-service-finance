<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

use Doctrine\Persistence\ObjectManager;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

/**
 * `flush()` — граница транзакции и вызывается только в Action, MessageHandler
 * и консольной Command (`CLAUDE.md`, «Транзакции»).
 *
 * В ORM 3 `flush()` сохраняет весь UnitOfWork, аргументы игнорируются:
 * `flush()` в сервисе или Repository молча сохраняет чужие незавершённые
 * изменения вызывающего кода.
 *
 * Ловит `flush()` и `wrapInTransaction()`, в том числе через `?->`. Класс
 * определяется по имени: `*Action`, `*Handler`, `*Command` (значит, любой
 * сервис с таким суффиксом проходит — имя должно говорить правду). Фикстуры
 * (`App\DataFixtures`) и тесты (`App\Tests`) вне правила: это загрузчики
 * данных, а не код приложения.
 *
 * @implements Rule<MethodCall>
 */
final class FlushOutsideTransactionBoundaryRule implements Rule
{
    private const ALLOWED_SUFFIXES = ['Action', 'Handler', 'Command'];

    /**
     * @param list<string> $exemptNamespaces
     */
    public function __construct(
        private readonly array $exemptNamespaces = ['App\\DataFixtures\\', 'App\\Tests\\'],
    ) {
    }

    public function getNodeType(): string
    {
        // `?->` PHPStan разворачивает в виртуальный MethodCall — отдельный
        // NullsafeMethodCall дал бы двойную ошибку.
        return MethodCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || !\in_array($node->name->toLowerString(), ['flush', 'wrapintransaction'], true)) {
            return [];
        }

        if (!(new ObjectType(ObjectManager::class))->isSuperTypeOf(TypeCombinator::removeNull($scope->getType($node->var)))->yes()) {
            return [];
        }

        $class = $scope->getClassReflection();
        $className = null !== $class ? $class->getName() : '';

        foreach ($this->exemptNamespaces as $namespace) {
            if (str_starts_with($className, $namespace)) {
                return [];
            }
        }

        foreach (self::ALLOWED_SUFFIXES as $suffix) {
            if (str_ends_with($className, $suffix)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s() в %s: граница транзакции — только Action, MessageHandler и консольная Command (CLAUDE.md, «Транзакции»).',
                $node->name->toString(),
                '' !== $className ? $className : 'коде вне класса',
            ))
                ->identifier('vashfindir.flushOutsideBoundary')
                ->tip('Сервис и Repository только persist()/remove(); flush() — в вызывающем Action/Handler/Command, пакет — порциями из вызывающего кода.')
                ->build(),
        ];
    }
}

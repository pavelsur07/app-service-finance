<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

/**
 * Поле формы `EntityType` — только с сущностью своего модуля (`CLAUDE.md`,
 * «Слои → Формы»): чужие данные идут через `ChoiceType` и Facade.
 *
 * Ловит `$builder->add('field', EntityType::class, ['class' => Foo::class, …])`,
 * в том числе для подклассов `EntityType` (`ProjectDirectionPickerType`),
 * именованных аргументов (`type:`, `options:`) и `class` строкой. Модуль —
 * сегмент пространства имён после корня (`App\Cash\…`). Не видит `class`,
 * собранный динамически, поле, добавленное не через `add()`, и типы-обёртки,
 * которые не наследуют `EntityType`.
 *
 * @implements Rule<MethodCall>
 */
final class ForeignEntityTypeRule implements Rule
{
    /**
     * @param string $rootNamespace корень, после которого идёт имя модуля
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly string $rootNamespace = 'App',
    ) {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || 'add' !== $node->name->toLowerString() || $node->isFirstClassCallable()) {
            return [];
        }

        $type = $this->argument($node, 1, 'type');
        $options = $this->argument($node, 2, 'options');
        if (null === $type || !$options instanceof Array_ || !$this->isEntityType($type, $scope)) {
            return [];
        }

        $entityClass = $this->classOption($options, $scope);
        $formModule = $this->module($scope->getNamespace() ?? '');
        if (null === $entityClass || null === $formModule) {
            return [];
        }

        $entityModule = $this->module($entityClass);
        if ($entityModule === $formModule) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'EntityType с сущностью чужого модуля %s в форме модуля %s — только ChoiceType с данными из Facade (CLAUDE.md, «Формы»).',
                $entityClass,
                $formModule,
            ))
                ->identifier('vashfindir.foreignEntityType')
                ->tip('ChoiceType + метод Facade модуля-владельца, который отдаёт DTO.')
                ->line($node->name->getStartLine())
                ->build(),
        ];
    }

    private function argument(MethodCall $call, int $position, string $name): ?Node\Expr
    {
        foreach ($call->getArgs() as $index => $arg) {
            if (null !== $arg->name ? $name === $arg->name->toString() : $position === $index) {
                return $arg->value;
            }
        }

        return null;
    }

    private function isEntityType(Node\Expr $expr, Scope $scope): bool
    {
        if (!$expr instanceof ClassConstFetch || !$expr->class instanceof Name
            || !$expr->name instanceof Identifier || 'class' !== $expr->name->toLowerString()) {
            return false;
        }

        $class = $scope->resolveName($expr->class);
        if (EntityType::class === $class) {
            return true;
        }

        return $this->reflectionProvider->hasClass($class)
            && $this->reflectionProvider->getClass($class)->isSubclassOf(EntityType::class);
    }

    private function classOption(Array_ $options, Scope $scope): ?string
    {
        foreach ($options->items as $item) {
            if (!$item->key instanceof String_ || 'class' !== $item->key->value) {
                continue;
            }

            $value = $item->value;
            if ($value instanceof ClassConstFetch && $value->class instanceof Name) {
                return ltrim($scope->resolveName($value->class), '\\');
            }

            if ($value instanceof String_) {
                return ltrim($value->value, '\\');
            }
        }

        return null;
    }

    private function module(string $name): ?string
    {
        $root = trim($this->rootNamespace, '\\').'\\';
        $name = ltrim($name, '\\').'\\';
        if (!str_starts_with($name, $root)) {
            return null;
        }

        $module = explode('\\', substr($name, \strlen($root)))[0];

        return '' !== $module ? $module : null;
    }
}

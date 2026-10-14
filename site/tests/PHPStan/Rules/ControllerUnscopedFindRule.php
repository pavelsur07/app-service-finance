<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

/**
 * В контроллере сущность компании не загружается `find()`/`findAll()`:
 * загрузка по id без компании держит изоляцию на ручной проверке после
 * загрузки (`CLAUDE.md`, «IDOR»). Нужен метод с `companyId` в запросе.
 *
 * Сущность берётся из типа результата (phpstan-doctrine); если репозиторий
 * без `@extends …<X>` и тип результата — `object|null`, то из метаданных
 * Doctrine по классу репозитория. Так правило видит `$repository->find()`,
 * `$em->find(Foo::class, $id)` и `$em->getRepository(Foo::class)->find()`.
 * Не видит: `findBy()`/`findOneBy()` (критерий может содержать компанию),
 * `$em->find($class, $id)` с классом в переменной, владение глубже одного
 * уровня и сам `Company` (тенант-корень без поля компании).
 *
 * @implements Rule<MethodCall>
 */
final class ControllerUnscopedFindRule implements Rule
{
    public function __construct(private readonly CompanyOwnedEntity $companyOwnedEntity)
    {
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
        if (!$node->name instanceof Identifier || !CompanyOwnedEntity::isController($scope->getClassReflection())) {
            return [];
        }

        $method = $node->name->toLowerString();
        if ('find' !== $method && 'findall' !== $method) {
            return [];
        }

        $receiver = $scope->getType($node->var);
        $isRepository = (new ObjectType(ObjectRepository::class))->isSuperTypeOf($receiver)->yes();
        $isManager = (new ObjectType(ObjectManager::class))->isSuperTypeOf($receiver)->yes();
        if (!$isRepository && !$isManager) {
            return [];
        }

        $result = $scope->getType($node);
        $entities = TypeCombinator::removeNull('findall' === $method ? $result->getIterableValueType() : $result)->getObjectClassNames();
        if ([] === $entities && $isRepository) {
            foreach ($receiver->getObjectClassNames() as $repository) {
                $entity = $this->companyOwnedEntity->entityOfRepository($repository);
                if (null !== $entity) {
                    $entities[] = $entity;
                }
            }
        }

        foreach ($entities as $entity) {
            if ($this->companyOwnedEntity->isCompanyOwned($entity)) {
                return [
                    RuleErrorBuilder::message(sprintf(
                        '%s() сущности компании %s в контроллере: загрузка без companyId (IDOR).',
                        $node->name->toString(),
                        $entity,
                    ))
                        ->identifier('vashfindir.controllerUnscopedFind')
                        ->tip('findOneByIdAndCompanyId($id, $companyId) и 404, если null.')
                        ->build(),
                ];
            }
        }

        return [];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;

/**
 * Действие контроллера не принимает сущность компании аргументом: Entity
 * value resolver загружает её по id без компании, и изоляция держится на
 * ручной проверке в теле (`CLAUDE.md`, «IDOR»). Нужен `string $id` и загрузка
 * с `companyId` в запросе.
 *
 * @implements Rule<InClassMethodNode>
 */
final class ControllerEntityArgumentRule implements Rule
{
    public function __construct(private readonly CompanyOwnedEntity $companyOwnedEntity)
    {
    }

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->getMethodReflection();
        if (!$method->isPublic() || $method->isStatic() || !CompanyOwnedEntity::isController($node->getClassReflection())) {
            return [];
        }

        $errors = [];
        $astParams = $node->getOriginalNode()->getParams();
        foreach ($method->getOnlyVariant()->getParameters() as $index => $parameter) {
            foreach (TypeCombinator::removeNull($parameter->getType())->getObjectClassNames() as $entity) {
                if (!$this->companyOwnedEntity->isCompanyOwned($entity)) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Действие %s::%s() принимает сущность компании %s аргументом: Entity value resolver загружает её без companyId (IDOR).',
                    $node->getClassReflection()->getName(),
                    $method->getName(),
                    $entity,
                ))
                    ->identifier('vashfindir.controllerEntityArgument')
                    ->tip('string $id + findOneByIdAndCompanyId($id, $companyId) и 404, если null.')
                    ->line(($astParams[$index] ?? $node->getOriginalNode())->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }
}

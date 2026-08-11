<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Единственная точка сохранения статьи ДДС: инварианты + flush.
 * Используется и HTTP-контроллером, и MCP-инструментом, поэтому не зависит от Request/формы.
 */
final class SaveCashflowCategoryAction
{
    private const MAX_LEVEL = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(CashflowCategory $category): void
    {
        $companyId = $category->getCompany()->getId();
        $parent = $category->getParent();

        if (null !== $parent) {
            if ($parent->getCompany()->getId() !== $companyId) {
                throw new \DomainException('Родительская статья принадлежит другой компании.');
            }
        }

        if (null === $parent && !$category->isSystem() && CashflowFlowKind::TECHNICAL === $category->getFlowKind()) {
            throw new \DomainException('Технический вид деятельности разрешён только системным категориям.');
        }

        $targetLevel = $this->targetLevel($category);
        if (null !== $parent && !$category->isSystem() && !$parent->acceptsRegularChildren()) {
            throw new \DomainException('Эта системная категория не может содержать пользовательские статьи.');
        }

        $subtreeDepth = $this->subtreeDepth($category);
        if ($targetLevel + $subtreeDepth - 1 > self::MAX_LEVEL) {
            throw new \DomainException('Максимальная вложенность — 5 уровней');
        }

        $plCategory = $category->getPlCategory();
        if (null !== $plCategory && $plCategory->getCompany()->getId() !== $companyId) {
            throw new \DomainException('Статья ОПиУ принадлежит другой компании.');
        }

        $category->syncFlowKindSubtree();

        $violations = $this->validator->validate($category);
        if (\count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = (string) $violation->getMessage();
            }

            throw new \DomainException(implode('; ', $messages));
        }

        $this->entityManager->persist($category);
        $this->entityManager->flush();
    }

    private function targetLevel(CashflowCategory $category): int
    {
        $level = 1;
        $parent = $category->getParent();
        $visited = [spl_object_id($category) => true];

        while (null !== $parent) {
            $objectId = spl_object_id($parent);
            if (isset($visited[$objectId])) {
                throw new \DomainException('В дереве категорий обнаружен цикл.');
            }
            $visited[$objectId] = true;
            ++$level;
            $parent = $parent->getParent();
        }

        return $level;
    }

    /** @param array<int, true> $path */
    private function subtreeDepth(CashflowCategory $category, array $path = []): int
    {
        $objectId = spl_object_id($category);
        if (isset($path[$objectId])) {
            throw new \DomainException('В дереве категорий обнаружен цикл.');
        }
        $path[$objectId] = true;

        $maxChildDepth = 0;
        foreach ($category->getChildren() as $child) {
            $maxChildDepth = max($maxChildDepth, $this->subtreeDepth($child, $path));
        }

        return 1 + $maxChildDepth;
    }
}

<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Transaction\CashflowCategory;
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

            if ($parent->getLevel() >= self::MAX_LEVEL) {
                throw new \DomainException('Максимальная вложенность — 5 уровней');
            }
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
}

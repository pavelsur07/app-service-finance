<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Company\Entity\Company;
use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Application\DTO\PLTemplateMapping;
use App\Finance\Application\DTO\PLTemplateMappingCandidate;
use App\Finance\Application\DTO\PLTemplateMappingRow;
use App\Finance\Entity\PLCategory;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Finance\Repository\PLCategoryRepository;

/**
 * Данные экрана «Сопоставить с шаблоном»: статьи шаблона профиля компании, которых у неё нет по
 * коду, с выбором своих статей без кода того же типа и подсказкой по похожему названию.
 */
final readonly class PLTemplateMappingBuilder
{
    public function __construct(
        private DefaultPLCategoryTreeProvider $templates,
        private PLCategoryRepository $categories,
        private PLCategoryNameSimilarity $similarity,
    ) {
    }

    public function build(Company $company): PLTemplateMapping
    {
        // Одним запросом: родители статей уже в памяти, путь собирается без запросов.
        $tree = $this->categories->findTreeByCompany($company);
        $codes = [];
        $uncoded = [];
        foreach ($tree as $category) {
            if (null !== $category->getCode()) {
                $codes[$category->getCode()] = true;
            } else {
                $uncoded[] = $category;
            }
        }

        $rows = [];
        $templateCount = 0;
        // Одна статья компании подсказывается только одной статье шаблона: иначе форма с
        // подсказками по умолчанию отклонялась бы («выбрана для двух статей шаблона»).
        $suggested = [];
        foreach ($this->templates->nodes($company->getBusinessProfile()) as $node) {
            if (null === $node->code) {
                continue;
            }
            ++$templateCount;
            if (isset($codes[$node->code])) {
                continue;
            }

            $candidates = [];
            $suggestedId = null;
            foreach ($uncoded as $category) {
                if ($category->getType() !== $node->type) {
                    continue;
                }
                $candidates[] = new PLTemplateMappingCandidate((string) $category->getId(), $this->categoryPath($category));
                if (null === $suggestedId && !isset($suggested[$category->getId()]) && $this->similarity->similar($node->name, $category->getName())) {
                    $suggestedId = (string) $category->getId();
                    $suggested[$suggestedId] = true;
                }
            }
            $rows[] = new PLTemplateMappingRow($node->code, $this->nodePath($node), $node->type, $candidates, $suggestedId);
        }

        return new PLTemplateMapping($rows, $templateCount - \count($rows), $templateCount);
    }

    private function categoryPath(PLCategory $category): string
    {
        $names = [];
        for ($c = $category; null !== $c; $c = $c->getParent()) {
            array_unshift($names, $c->getName());
        }

        return implode(' / ', $names);
    }

    private function nodePath(PLCategoryTreeNode $node): string
    {
        $names = [];
        for ($n = $node; null !== $n; $n = $n->parent) {
            array_unshift($names, $n->name);
        }

        return implode(' / ', $names);
    }
}

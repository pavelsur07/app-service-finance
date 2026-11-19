<?php

declare(strict_types=1);

namespace App\Cash\Application\Category;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Единственная точка сохранения статьи ДДС: инварианты + flush.
 * Не зависит от Request/формы.
 */
final class SaveCashflowCategoryAction
{
    private const MAX_LEVEL = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly CashflowCategoryRepository $categories,
    ) {
    }

    public function createDraft(\App\Company\Entity\Company $company): CashflowCategory
    {
        return new CashflowCategory(\Ramsey\Uuid\Uuid::uuid7()->toString(), $company);
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

        // Форма проверила дерево по объектам, загруженным до сохранения; параллельная загрузка из JSON
        // могла с тех пор перенести статьи или занять код. Под блокировкой дерева компании всё это
        // перепроверяется по базе. Транзакция — соединения, а не wrapInTransaction(): тот после
        // callback делает flush() и записал бы изменённую формой статью и при отказе. Отказ
        // возвращается строкой, EntityManager остаётся открытым: форма показывается заново.
        $refusal = $this->entityManager->getConnection()->transactional(function () use ($category): ?string {
            $companyId = (string) $category->getCompany()->getId();
            $refusal = $this->refusalByStoredTree($category, $this->categories->lockTreePlacements($companyId));
            if (null !== $refusal) {
                return $refusal;
            }

            // Код консолидации могли проставить после того, как форма загрузила статью.
            $stored = $this->categories->lockStoredPlacement($companyId, (string) $category->getId());
            if (null !== $stored && null !== $stored['groupCode'] && $stored['parentId'] !== $category->getParent()?->getId()) {
                return 'Статью базового дерева нельзя переносить: по ней строится консолидированная отчётность. Обновите страницу.';
            }

            $this->entityManager->persist($category);
            $this->entityManager->flush();

            return null;
        });

        if (\is_string($refusal)) {
            throw new \DomainException($refusal);
        }
    }

    /**
     * Статья и родитель на месте, цикл, глубина, код и вид деятельности — по сохранённому дереву с
     * новым местом этой статьи. Вид деятельности форма вычислила по объектам в памяти: если по базе
     * у нового корня он другой или изменился состав поддерева, запись разошлась бы с деревом.
     *
     * @param array<string, array{parentId: ?string, code: ?string, flowKind: string}> $placements
     */
    private function refusalByStoredTree(CashflowCategory $category, array $placements): ?string
    {
        $changed = 'Дерево статей изменилось. Обновите страницу.';
        $id = (string) $category->getId();
        $parentId = $category->getParent()?->getId();
        if ($this->entityManager->contains($category) && !isset($placements[$id])) {
            return 'Статья удалена. Обновите страницу.';
        }
        if (null !== $parentId && !isset($placements[$parentId])) {
            return 'Родительская статья удалена. Обновите страницу.';
        }
        $storedKind = $placements[$id]['flowKind'] ?? null;
        $placements[$id] = ['parentId' => $parentId, 'code' => $category->getCode(), 'flowKind' => $category->getFlowKind()->value];

        $level = 1;
        $root = $id;
        $visited = [$id => true];
        for ($node = $parentId; null !== $node && isset($placements[$node]); $node = $placements[$node]['parentId']) {
            if (isset($visited[$node])) {
                return 'Дерево статей изменилось: статья оказалась бы внутри самой себя. Обновите страницу.';
            }
            $visited[$node] = true;
            $root = $node;
            ++$level;
        }
        if ($root !== $id && $placements[$root]['flowKind'] !== $category->getFlowKind()->value) {
            return $changed;
        }

        $children = [];
        foreach ($placements as $nodeId => $placement) {
            if (null !== $placement['parentId']) {
                $children[$placement['parentId']][] = (string) $nodeId;
            }
        }
        $subtree = [];
        $depth = 0;
        for ($layer = [$id]; [] !== $layer; ++$depth) {
            $next = [];
            foreach ($layer as $node) {
                if (isset($subtree[$node])) {
                    return $changed;
                }
                $subtree[$node] = true;
                array_push($next, ...($children[$node] ?? []));
            }
            $layer = $next;
        }
        if ($level + $depth - 1 > self::MAX_LEVEL) {
            return 'Максимальная вложенность — 5 уровней: дерево статей изменилось. Обновите страницу.';
        }
        // Вид деятельности меняется — поддерево в памяти должно совпадать с базой, иначе часть
        // потомков останется со старым видом или получит чужой.
        if (null !== $storedKind && $storedKind !== $category->getFlowKind()->value && !$this->sameIds(array_keys($subtree), $this->memorySubtree($category))) {
            return $changed;
        }

        $code = $category->getCode();
        foreach ($placements as $nodeId => $placement) {
            if (null !== $code && $placement['code'] === $code && (string) $nodeId !== $id) {
                return sprintf('Код %s уже у другой статьи. Обновите страницу.', $code);
            }
        }

        return null;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function sameIds(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    /** @return list<string> id статьи и её потомков по объектам в памяти */
    private function memorySubtree(CashflowCategory $category): array
    {
        $ids = [(string) $category->getId()];
        foreach ($category->getChildren() as $child) {
            array_push($ids, ...$this->memorySubtree($child));
        }

        return $ids;
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

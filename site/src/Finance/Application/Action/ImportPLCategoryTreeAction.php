<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Application\DTO\ImportPLCategoryTreeResult;
use App\Finance\Application\DTO\ImportPLCategoryTreeRow;
use App\Finance\Entity\PLCategory;
use App\Finance\Repository\PLCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Переносит дерево категорий ОПиУ из одной компании в другую.
 *
 * Матчинг узла: по code, если он задан у источника (code уникален в рамках
 * всей компании), иначе по (parent, name). Совпавшие узлы обновляются,
 * несовпавшие — создаются. Узлы целевой компании, отсутствующие в источнике,
 * никогда не удаляются и не изменяются.
 */
final class ImportPLCategoryTreeAction
{
    private const MAX_LEVEL = 5;

    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly PLCategoryRepository $plCategoryRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ImportPLCategoryTreeCommand $command): ImportPLCategoryTreeResult
    {
        $sourceCompany = $this->companyRepository->findById($command->sourceCompanyId);
        if (!$sourceCompany instanceof Company) {
            throw new \DomainException('Компания-источник не найдена.');
        }

        $targetCompany = $this->companyRepository->findById($command->targetCompanyId);
        if (!$targetCompany instanceof Company) {
            throw new \DomainException('Целевая компания не найдена.');
        }

        if ($sourceCompany->getId() === $targetCompany->getId()) {
            throw new \DomainException('Источник и целевая компания совпадают.');
        }

        $sourceNodes = $this->plCategoryRepository->findTreeByCompany($sourceCompany);

        $plan = $this->resolveMatches($targetCompany, $sourceNodes);
        [$created, $updated, $unchangedCount] = $this->validateAndDiff($plan);

        // Мутации — только после того, как всё дерево прошло матчинг и
        // валидацию глубины. Порядок общий для create/update и совпадает с
        // исходным DFS pre-order (родитель раньше потомка), иначе setParent()
        // потомка мог бы увидеть ещё не обновлённого родителя. Если что-то
        // в дереве не проходит валидацию, эта часть вообще не достигается —
        // ни один узел не остаётся частично применённым в EntityManager.
        //
        // Одна транзакция на обе фазы (releaseChangingCodes делает свой
        // промежуточный flush): если основной flush() ниже упадёт, откатится
        // и уже отправленное освобождение кодов — иначе при ошибке можно
        // было бы закоммитить обнулённые code без их настоящих новых значений.
        if (!$command->dryRun) {
            $this->em->wrapInTransaction(function () use ($plan): void {
                $this->releaseChangingCodes($plan);

                foreach ($plan as $item) {
                    if ('none' === $item['operation']) {
                        continue;
                    }

                    $this->applyFields($item['resolved'], $item['sourceNode'], $item['targetParent']);

                    if ('create' === $item['operation']) {
                        $this->em->persist($item['resolved']);
                    }
                }

                $this->em->flush();
            });
        }

        return new ImportPLCategoryTreeResult($created, $updated, $unchangedCount);
    }

    /**
     * `code` — стабильный идентификатор строки P&L, на который ссылаются
     * формулы (см. комментарий у `PLCategory::$code`), поэтому уникальность
     * code в рамках компании — прикладной инвариант независимо от того, что
     * текущая схема `pl_categories` физически его не проверяет: индекс
     * `uniq_plcat_company_code`, созданный в `Version20251001120000`, был
     * удалён в `Version20251105174115::up()` и с тех пор не восстановлен
     * (см. `down()` той же миграции — воссоздаёт его только при откате).
     * Это отдельный, не связанный с импортом дефект схемы.
     *
     * Метод защищает от временной коллизии двух PLCategory с одинаковым code
     * внутри одного flush(): Doctrine выполняет все insert раньше всех
     * update, поэтому если update освобождает code, который в этом же
     * проходе хочет занять новый (create) узел, insert увидел бы ещё не
     * очищенное старое значение. Актуально уже сейчас на уровне приложения
     * и станет обязательным, если индекс когда-нибудь восстановят.
     *
     * @param list<array{sourceNode: PLCategory, targetParent: ?PLCategory, existing: ?PLCategory, resolved: PLCategory, operation: string}> $plan
     */
    private function releaseChangingCodes(array $plan): void
    {
        $releasedAny = false;

        foreach ($plan as $item) {
            if ('update' !== $item['operation']) {
                continue;
            }

            $existing = $item['existing'];
            if (!$existing instanceof PLCategory) {
                continue;
            }

            $newCode = $item['sourceNode']->getCode();

            if (null !== $existing->getCode() && $existing->getCode() !== $newCode) {
                $existing->setCode(null);
                $releasedAny = true;
            }
        }

        if ($releasedAny) {
            $this->em->flush();
        }
    }

    /**
     * Фаза 1 — матчинг. Для каждого source-узла резолвит существующий
     * target-узел (или новый placeholder) — без мутаций и без валидации
     * глубины. Нужно целиком заранее: узел, которым управляет источник, не
     * должен засчитываться как «сохранённый» потомок его текущего
     * target-родителя при последующей проверке глубины.
     *
     * Один и тот же существующий target-узел не может быть отдан двум разным
     * source-узлам: code-матч и (parent, name)-фолбэк матчят независимо и
     * теоретически могут указать на одну и ту же строку (например source
     * переименовывает узел с code, а другой source-узел без code случайно
     * называется как его старое имя). Первый source-узел в порядке обхода
     * забирает существующий узел, остальные создают новый — иначе один
     * source-узел молча потерялся бы, а результат зависел от порядка обхода.
     *
     * @param PLCategory[] $sourceNodes
     *
     * @return list<array{sourceNode: PLCategory, targetParent: ?PLCategory, existing: ?PLCategory, resolved: PLCategory, operation: string}>
     */
    private function resolveMatches(Company $targetCompany, array $sourceNodes): array
    {
        /** @var array<string, PLCategory> $resolvedBySourceId */
        $resolvedBySourceId = [];
        /** @var array<string, true> $claimedTargetIds */
        $claimedTargetIds = [];
        $plan = [];

        foreach ($sourceNodes as $sourceNode) {
            $sourceParent = $sourceNode->getParent();
            $targetParent = null !== $sourceParent ? $resolvedBySourceId[$sourceParent->getId()] : null;

            $existing = $this->findExisting($targetCompany, $targetParent, $sourceNode);
            if (null !== $existing && isset($claimedTargetIds[$existing->getId()])) {
                $existing = null;
            }

            $resolved = $existing ?? new PLCategory(Uuid::uuid7()->toString(), $targetCompany);
            $resolvedBySourceId[$sourceNode->getId()] = $resolved;

            if (null !== $existing) {
                $claimedTargetIds[$existing->getId()] = true;
            }

            $plan[] = [
                'sourceNode' => $sourceNode,
                'targetParent' => $targetParent,
                'existing' => $existing,
                'resolved' => $resolved,
                'operation' => 'none',
            ];
        }

        return $plan;
    }

    /**
     * Фаза 2 — валидация глубины и diff, без мутаций.
     *
     * @param list<array{sourceNode: PLCategory, targetParent: ?PLCategory, existing: ?PLCategory, resolved: PLCategory, operation: string}> &$plan
     *
     * @return array{0: ImportPLCategoryTreeRow[], 1: ImportPLCategoryTreeRow[], 2: int}
     */
    private function validateAndDiff(array &$plan): array
    {
        /** @var array<string, true> $matchedTargetIds id существующих target-узлов, которыми управляет источник */
        $matchedTargetIds = [];
        foreach ($plan as $item) {
            if (null !== $item['existing']) {
                $matchedTargetIds[$item['existing']->getId()] = true;
            }
        }

        /** @var array<string, int> $targetLevelBySourceId */
        $targetLevelBySourceId = [];
        $created = [];
        $updated = [];
        $unchangedCount = 0;

        foreach ($plan as $i => $item) {
            $sourceNode = $item['sourceNode'];
            $sourceParent = $sourceNode->getParent();
            $targetLevel = null !== $sourceParent ? $targetLevelBySourceId[$sourceParent->getId()] + 1 : 1;
            $targetLevelBySourceId[$sourceNode->getId()] = $targetLevel;

            $existing = $item['existing'];
            // Существующие потомки target, отсутствующие в источнике, не
            // трогаются и не удаляются — но переносятся вместе с этим узлом,
            // если он переносится глубже, и не должны провалиться за предел.
            // Потомки, которыми источник управляет отдельно (matched), сюда
            // не входят — их собственная глубина уже проверяется их
            // собственной записью плана.
            $preservedDepth = null !== $existing ? $this->preservedDescendantDepth($existing, $matchedTargetIds) : 0;

            if ($targetLevel + $preservedDepth > self::MAX_LEVEL) {
                throw new \DomainException(sprintf('Превышена максимальная вложенность (5 уровней) при переносе категории "%s".', $sourceNode->getName()));
            }

            $path = $this->buildPath($sourceNode);

            if (null !== $existing) {
                if ($this->fieldsDiffer($existing, $sourceNode, $item['targetParent'])) {
                    $plan[$i]['operation'] = 'update';
                    $updated[] = new ImportPLCategoryTreeRow($sourceNode->getName(), $sourceNode->getCode(), $path);
                } else {
                    ++$unchangedCount;
                }
            } else {
                $plan[$i]['operation'] = 'create';
                $created[] = new ImportPLCategoryTreeRow($sourceNode->getName(), $sourceNode->getCode(), $path);
            }
        }

        return [$created, $updated, $unchangedCount];
    }

    private function findExisting(Company $targetCompany, ?PLCategory $targetParent, PLCategory $source): ?PLCategory
    {
        $code = $source->getCode();
        if (null !== $code && '' !== $code) {
            return $this->plCategoryRepository->findOneBy([
                'company' => $targetCompany,
                'code' => $code,
            ]);
        }

        return $this->plCategoryRepository->findOneBy([
            'company' => $targetCompany,
            'parent' => $targetParent,
            'name' => $source->getName(),
        ]);
    }

    private function fieldsDiffer(PLCategory $existing, PLCategory $source, ?PLCategory $targetParent): bool
    {
        return $existing->getName() !== $source->getName()
            || $existing->getCode() !== $source->getCode()
            || $existing->getType() !== $source->getType()
            || $existing->getFormat() !== $source->getFormat()
            || $existing->getFlow() !== $source->getFlow()
            || $existing->getExpenseType() !== $source->getExpenseType()
            || $existing->getWeightInParent() !== $source->getWeightInParent()
            || $existing->isVisible() !== $source->isVisible()
            || $existing->getFormula() !== $source->getFormula()
            || $existing->getCalcOrder() !== $source->getCalcOrder()
            || $existing->getSortOrder() !== $source->getSortOrder()
            || $existing->getParent()?->getId() !== $targetParent?->getId();
    }

    private function applyFields(PLCategory $target, PLCategory $source, ?PLCategory $targetParent): void
    {
        $target->setName($source->getName());
        $target->setParent($targetParent);
        $target->setCode($source->getCode());
        $target->setType($source->getType());
        $target->setFormat($source->getFormat());
        $target->setFlow($source->getFlow());
        $target->setExpenseType($source->getExpenseType());
        $target->setWeightInParent($source->getWeightInParent());
        $target->setIsVisible($source->isVisible());
        $target->setFormula($source->getFormula());
        $target->setCalcOrder($source->getCalcOrder());
        $target->setSortOrder($source->getSortOrder());
    }

    /**
     * @param array<string, true> $matchedTargetIds
     */
    private function preservedDescendantDepth(PLCategory $node, array $matchedTargetIds): int
    {
        $max = 0;
        foreach ($node->getChildren() as $child) {
            if (isset($matchedTargetIds[$child->getId()])) {
                continue;
            }

            $max = max($max, 1 + $this->preservedDescendantDepth($child, $matchedTargetIds));
        }

        return $max;
    }

    private function buildPath(PLCategory $sourceNode): string
    {
        $names = [];
        $node = $sourceNode;
        while (null !== $node) {
            array_unshift($names, $node->getName());
            $node = $node->getParent();
        }

        return implode(' / ', $names);
    }
}

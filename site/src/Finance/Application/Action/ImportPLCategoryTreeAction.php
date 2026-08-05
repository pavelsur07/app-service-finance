<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Application\DTO\ImportPLCategoryTreeResult;
use App\Finance\Application\DTO\ImportPLCategoryTreeRow;
use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Entity\PLCategory;
use App\Finance\Repository\PLCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Переносит дерево категорий ОПиУ из источника в компанию.
 *
 * Источник — список PLCategoryTreeNode в DFS pre-order, а не компания: одно и то
 * же дерево может прийти из другой компании этого же аккаунта или из файла,
 * выгруженного в чужом аккаунте. Откуда именно — Action не знает и знать не
 * должен.
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
        $targetCompany = $this->companyRepository->findById($command->targetCompanyId);
        if (!$targetCompany instanceof Company) {
            throw new \DomainException('Целевая компания не найдена.');
        }

        $plan = $this->resolveMatches($targetCompany, $command->sourceNodes);
        [$created, $updated, $unchangedCount] = $this->validateAndDiff($plan);

        // Строго до мутаций: метод сравнивает старый code совпавшего узла с
        // новым, а после applyFields() старого уже не существует.
        $unresolvedFormulaCodes = $this->unresolvedFormulaCodes($targetCompany, $plan);

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

        return new ImportPLCategoryTreeResult($created, $updated, $unchangedCount, $unresolvedFormulaCodes);
    }

    /**
     * Токены формул, которых не будет в целевой компании после импорта.
     *
     * Формулы ссылаются на категории по `code`, а коды живут в рамках компании:
     * при переносе между аккаунтами формула легко приезжает к строке, на которую
     * ей больше не на что ссылаться. Импорт это не блокирует (решение Владельца),
     * но пользователь должен увидеть, что именно проверить руками.
     *
     * ponytail: токенизация регуляркой — парсера формул в проекте нет (`formula`
     * у PLCategory пока только хранится). Поэтому среди токенов попадаются имена
     * функций, о чём UI говорит прямо. Заменить на разбор, когда появится
     * настоящий парсер.
     *
     * @param list<array{sourceNode: PLCategoryTreeNode, targetParent: ?PLCategory, existing: ?PLCategory, resolved: PLCategory, operation: string}> $plan
     *
     * @return list<string>
     */
    private function unresolvedFormulaCodes(Company $targetCompany, array $plan): array
    {
        $formulas = [];
        $sourceCodes = [];
        $releasedCodes = [];
        foreach ($plan as $item) {
            $node = $item['sourceNode'];

            if (null !== $node->formula && '' !== trim($node->formula)) {
                $formulas[] = $node->formula;
            }

            if (null !== $node->code) {
                $sourceCodes[$node->code] = true;
            }

            // Импорт умеет и освобождать код: узел, совпавший по (parent, name),
            // но пришедший из файла с другим кодом или вовсе без него, отдаёт
            // своё прежнее значение. Без учёта этого предупреждение молчало бы
            // ровно там, где ссылка и ломается.
            $existingCode = $item['existing']?->getCode();
            if (null !== $existingCode && $existingCode !== $node->code) {
                $releasedCodes[$existingCode] = true;
            }
        }

        if ([] === $formulas) {
            return [];
        }

        // Коды целевой компании после импорта: текущие минус освобождаемые плюс
        // приносимые (код, освобождённый одним узлом и занятый другим, остаётся).
        $known = [];
        foreach ($this->plCategoryRepository->findCodesByCompany($targetCompany) as $code) {
            if (!isset($releasedCodes[$code])) {
                $known[$code] = true;
            }
        }

        $known += $sourceCodes;

        // Известные коды вымарываются из формулы целиком до токенизации.
        // Иначе не разделить два смысла дефиса: «NET-PROFIT» может быть кодом
        // (code нормализуется только trim + upper, дефис в нём допустим), а
        // «REVENUE-COGS» — вычитанием двух кодов. Границы токена в шаблоне не
        // дают короткому коду съесть часть длинного имени.
        $maskPatterns = [];
        foreach (array_keys($known) as $code) {
            $maskPatterns[] = '/(?<![\p{Lu}\p{N}_])'.preg_quote((string) $code, '/').'(?![\p{Lu}\p{N}_])/u';
        }

        $unresolved = [];
        foreach ($formulas as $formula) {
            $rest = [] !== $maskPatterns ? (string) preg_replace($maskPatterns, ' ', $formula) : $formula;

            preg_match_all('/[\p{Lu}\p{N}_]{1,64}/u', $rest, $matches);
            foreach ($matches[0] as $token) {
                // Числа (включая 1E10) и голые разделители кодами быть не могут.
                if (is_numeric($token) || 1 !== preg_match('/\p{Lu}/u', $token)) {
                    continue;
                }

                $unresolved[$token] = true;
            }
        }

        $unresolved = array_keys($unresolved);
        sort($unresolved);

        return $unresolved;
    }

    /**
     * `code` — стабильный идентификатор строки P&L, на который ссылаются
     * формулы (см. комментарий у `PLCategory::$code`). Уникальность code в
     * рамках компании обеспечена индексом `uniq_plcat_company_code`:
     * он был создан в `Version20251001120000`, удалён в
     * `Version20251105174115::up()` и восстановлен в `Version20260804120000`
     * после разбора существовавших дублей.
     *
     * Метод защищает от временной коллизии двух PLCategory с одинаковым code
     * внутри одного flush(): Doctrine выполняет все insert раньше всех
     * update, поэтому если update освобождает code, который в этом же
     * проходе хочет занять новый (create) узел, insert увидел бы ещё не
     * очищенное старое значение и упал бы на unique-индексе.
     *
     * @param list<array{sourceNode: PLCategoryTreeNode, targetParent: ?PLCategory, existing: ?PLCategory, resolved: PLCategory, operation: string}> $plan
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

            $newCode = $item['sourceNode']->code;

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
     * @param list<PLCategoryTreeNode> $sourceNodes
     *
     * @return list<array{sourceNode: PLCategoryTreeNode, targetParent: ?PLCategory, existing: ?PLCategory, resolved: PLCategory, operation: string}>
     */
    private function resolveMatches(Company $targetCompany, array $sourceNodes): array
    {
        /** @var array<string, PLCategory> $resolvedBySourceKey */
        $resolvedBySourceKey = [];
        /** @var array<string, true> $claimedTargetIds */
        $claimedTargetIds = [];
        $plan = [];

        foreach ($sourceNodes as $sourceNode) {
            $sourceParent = $sourceNode->parent;
            $targetParent = null !== $sourceParent ? $resolvedBySourceKey[$sourceParent->key] : null;

            $existing = $this->findExisting($targetCompany, $targetParent, $sourceNode);
            if (null !== $existing && isset($claimedTargetIds[$existing->getId()])) {
                $existing = null;
            }

            $resolved = $existing ?? new PLCategory(Uuid::uuid7()->toString(), $targetCompany);
            $resolvedBySourceKey[$sourceNode->key] = $resolved;

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
     * @param list<array{sourceNode: PLCategoryTreeNode, targetParent: ?PLCategory, existing: ?PLCategory, resolved: PLCategory, operation: string}> &$plan
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

        /** @var array<string, int> $targetLevelBySourceKey */
        $targetLevelBySourceKey = [];
        $created = [];
        $updated = [];
        $unchangedCount = 0;

        foreach ($plan as $i => $item) {
            $sourceNode = $item['sourceNode'];
            $sourceParent = $sourceNode->parent;
            $targetLevel = null !== $sourceParent ? $targetLevelBySourceKey[$sourceParent->key] + 1 : 1;
            $targetLevelBySourceKey[$sourceNode->key] = $targetLevel;

            $existing = $item['existing'];
            // Существующие потомки target, отсутствующие в источнике, не
            // трогаются и не удаляются — но переносятся вместе с этим узлом,
            // если он переносится глубже, и не должны провалиться за предел.
            // Потомки, которыми источник управляет отдельно (matched), сюда
            // не входят — их собственная глубина уже проверяется их
            // собственной записью плана.
            $preservedDepth = null !== $existing ? $this->preservedDescendantDepth($existing, $matchedTargetIds) : 0;

            if ($targetLevel + $preservedDepth > self::MAX_LEVEL) {
                throw new \DomainException(sprintf('Превышена максимальная вложенность (5 уровней) при переносе категории "%s".', $sourceNode->name));
            }

            $path = $this->buildPath($sourceNode);

            if (null !== $existing) {
                if ($this->fieldsDiffer($existing, $sourceNode, $item['targetParent'])) {
                    $plan[$i]['operation'] = 'update';
                    $updated[] = new ImportPLCategoryTreeRow($sourceNode->name, $sourceNode->code, $path);
                } else {
                    ++$unchangedCount;
                }
            } else {
                $plan[$i]['operation'] = 'create';
                $created[] = new ImportPLCategoryTreeRow($sourceNode->name, $sourceNode->code, $path);
            }
        }

        return [$created, $updated, $unchangedCount];
    }

    private function findExisting(Company $targetCompany, ?PLCategory $targetParent, PLCategoryTreeNode $source): ?PLCategory
    {
        $code = $source->code;
        if (null !== $code && '' !== $code) {
            return $this->plCategoryRepository->findOneBy([
                'company' => $targetCompany,
                'code' => $code,
            ]);
        }

        // Узел без кода опознаётся парой (родитель, имя). В целевой компании
        // одноимённых потомков может оказаться несколько — схема это позволяет,
        // если у них разные коды. Тогда findOneBy() вернул бы произвольного из
        // них: импорт обновил бы случайную строку и освободил её код, а
        // повторный прогон мог бы выбрать уже другую. Молча выбирать нельзя.
        $candidates = $this->plCategoryRepository->findBy([
            'company' => $targetCompany,
            'parent' => $targetParent,
            'name' => $source->name,
        ]);

        if (count($candidates) > 1) {
            throw new \DomainException(sprintf('В целевой компании несколько категорий с названием "%s" у одного родителя. Задайте переносимой категории уникальный код или переименуйте лишние, иначе непонятно, какую из них обновлять.', $source->name));
        }

        return $candidates[0] ?? null;
    }

    private function fieldsDiffer(PLCategory $existing, PLCategoryTreeNode $source, ?PLCategory $targetParent): bool
    {
        return $existing->getName() !== $source->name
            || $existing->getCode() !== $source->code
            || $existing->getType() !== $source->type
            || $existing->getFormat() !== $source->format
            || $existing->getFlow() !== $source->flow
            || $existing->getExpenseType() !== $source->expenseType
            || $existing->getWeightInParent() !== $source->weightInParent
            || $existing->isVisible() !== $source->isVisible
            || $existing->getFormula() !== $source->formula
            || $existing->getCalcOrder() !== $source->calcOrder
            || $existing->getSortOrder() !== $source->sortOrder
            || $existing->getParent()?->getId() !== $targetParent?->getId();
    }

    private function applyFields(PLCategory $target, PLCategoryTreeNode $source, ?PLCategory $targetParent): void
    {
        $target->setName($source->name);
        $target->setParent($targetParent);
        $target->setCode($source->code);
        $target->setType($source->type);
        $target->setFormat($source->format);
        $target->setFlow($source->flow);
        $target->setExpenseType($source->expenseType);
        $target->setWeightInParent($source->weightInParent);
        $target->setIsVisible($source->isVisible);
        $target->setFormula($source->formula);
        $target->setCalcOrder($source->calcOrder);
        $target->setSortOrder($source->sortOrder);
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

    private function buildPath(PLCategoryTreeNode $sourceNode): string
    {
        $names = [];
        $node = $sourceNode;
        while (null !== $node) {
            array_unshift($names, $node->name);
            $node = $node->parent;
        }

        return implode(' / ', $names);
    }
}

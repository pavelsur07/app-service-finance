<?php

declare(strict_types=1);

namespace App\Cash\Application\Category;

use App\Cash\Application\DTO\CashflowCategoryImportPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use Ramsey\Uuid\Uuid;

/**
 * Сравнивает дерево статей ДДС из файла выгрузки с деревом компании и строит план загрузки.
 * Ничего не меняет: сущности только читаются.
 *
 * Статья файла сопоставляется по `id`; без `id` или с `id` (UUID), которого нет ни у одной компании, —
 * новая (во втором случае с этим `id`: он же ключ повтора). `id` статьи другой компании — отказ. Родителя задаёт вложенность `children`;
 * у статьи верхнего уровня — `parentId`, чтобы фрагмент дерева остался на своём месте. Поле,
 * которого в файле нет, не меняется. Статьи компании, которых нет в файле, не меняются и не
 * удаляются — кроме вида деятельности, который потомок наследует от корня.
 *
 * @phpstan-import-type Values from CashflowCategoryImportPlan
 * @phpstan-import-type Item from CashflowCategoryImportPlan
 */
final readonly class CashflowCategoryImportPlanner
{
    private const MAX_LEVEL = 5;
    private const MAX_NODES = 2000;
    // D: «$» не пропускает завершающий перевод строки — Assert::uuid() в сущности его не примет.
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    private const SYSTEM_PARENTS = [CashflowCategory::CODE_OPERATING, CashflowCategory::CODE_FINANCING, CashflowCategory::CODE_INVESTING];
    private const LABELS = [
        'name' => 'Название',
        'description' => 'Описание',
        'status' => 'Статус',
        'sort' => 'Порядок',
        'parentId' => 'Родитель',
        'operationType' => 'Тип операции',
        'allowPlDocument' => 'Документ ОПиУ',
        'plCategoryId' => 'Статья ОПиУ',
        'code' => 'Код',
        'flowKind' => 'Вид деятельности',
    ];
    private const SYSTEM_LOCKED = ['name', 'sort', 'parentId', 'code', 'flowKind', 'operationType'];

    /**
     * @param array<CashflowCategory> $existing все статьи компании
     * @param mixed $payload разобранный JSON файла
     * @param array<string, string> $plCategoryNames статьи ОПиУ компании: id → название
     * @param list<string> $newIds ID, выданные предпросмотром статьям без id
     * @param list<string> $takenIds id из файла, занятые статьями других компаний (см. fileIds())
     */
    public function plan(array $existing, mixed $payload, array $plCategoryNames, array $newIds = [], array $takenIds = []): CashflowCategoryImportPlan
    {
        if (!\is_array($payload) || !array_is_list($payload)) {
            return $this->rejected(['Файл должен содержать список статей — в том же формате, что и выгрузка.']);
        }

        $current = $system = $grouped = [];
        foreach ($existing as $category) {
            $id = (string) $category->getId();
            $current[$id] = [
                'name' => $category->getName(),
                'description' => $category->getDescription(),
                'status' => $category->getStatus()->value,
                'sort' => $category->getSort(),
                'parentId' => $category->getParent()?->getId(),
                'operationType' => $category->getOperationType()?->value,
                'allowPlDocument' => $category->isAllowPlDocument(),
                'plCategoryId' => null === $category->getPlCategory() ? null : (string) $category->getPlCategory()->getId(),
                'code' => $category->getCode(),
                'flowKind' => $category->getFlowKind()->value,
            ];
            $system[$id] = $category->isSystem();
            $grouped[$id] = null !== $category->getGroupCode();
        }

        $final = $current;
        $inFile = $fileKind = $generated = $errors = [];
        $taken = array_fill_keys($takenIds, true);
        $this->collect($payload, null, true, $current, $system, $taken, $plCategoryNames, $newIds, $final, $inFile, $fileKind, $generated, $errors);
        if ([] !== $errors) {
            return $this->rejected($errors);
        }

        foreach (array_keys($final) as $id) {
            if (null === $this->chain($final, $id)) {
                return $this->rejected([sprintf('В дереве получается цикл: статья «%s» оказывается внутри самой себя.', $final[$id]['name'])]);
            }
        }

        // Вид деятельности задаёт корень: у потомка он производный, из файла читается только у корня.
        foreach (array_keys($final) as $id) {
            $chain = $this->chain($final, $id) ?? [$id];
            $root = $chain[array_key_last($chain)];
            $rootKind = !($system[$root] ?? false) && isset($fileKind[$root]) ? $fileKind[$root] : ($current[$root]['flowKind'] ?? CashflowFlowKind::OPERATING->value);
            if (!isset($current[$id])) {
                $final[$id]['flowKind'] = $rootKind;
                continue;
            }
            if ($system[$id]) {
                if ($id === $root && isset($fileKind[$id])) {
                    $final[$id]['flowKind'] = $fileKind[$id];
                }
                continue;
            }
            $oldChain = $this->chain($current, $id) ?? [$id];
            $oldKind = $current[$oldChain[array_key_last($oldChain)]]['flowKind'];
            if ($oldKind !== $rootKind) {
                $final[$id]['flowKind'] = $rootKind;
            }
        }

        $changed = array_filter(array_keys($final), static fn (string $id): bool => !isset($current[$id]) || $current[$id] !== $final[$id]);
        foreach ($changed as $id) {
            array_push($errors, ...$this->violations($id, $current, $final, $system, $grouped));
        }
        // Глубина — по всем статьям: при переносе ветки поля потомков не меняются, а уровень растёт.
        foreach (array_keys($final) as $id) {
            $depth = \count($this->chain($final, $id) ?? []);
            if ($depth > self::MAX_LEVEL && (!isset($current[$id]) || $depth !== \count($this->chain($current, $id) ?? []))) {
                $errors[] = sprintf('Статья «%s»: максимальная вложенность — %d уровней.', $final[$id]['name'], self::MAX_LEVEL);
            }
        }

        $idsByCode = [];
        foreach ($final as $id => $values) {
            if (null !== $values['code']) {
                $idsByCode[$values['code']][] = $id;
            }
        }
        foreach ($idsByCode as $code => $ids) {
            if (\count($ids) > 1 && [] !== array_intersect($ids, $changed)) {
                $errors[] = sprintf('Код %s указан у нескольких статей: %s. Код уникален в пределах компании.', $code, implode(', ', array_map(static fn (string $id): string => '«'.$final[$id]['name'].'»', $ids)));
            }
        }
        if ([] !== $errors) {
            return $this->rejected($errors);
        }

        $items = [];
        foreach ($changed as $id) {
            $items[] = [
                'id' => $id,
                'new' => !isset($current[$id]),
                'path' => implode(' / ', array_reverse(array_map(static fn (string $nodeId): string => $final[$nodeId]['name'], $this->chain($final, $id) ?? [$id]))),
                'values' => $final[$id],
                'changes' => isset($current[$id]) ? $this->changes($current[$id], $final[$id], $current, $final, $plCategoryNames) : [],
            ];
        }
        // Родитель раньше потомка: применение ставит статью под уже перемещённого родителя.
        // Внутри уровня — по id: отпечаток не зависит от порядка, в котором статьи пришли из БД.
        usort($items, fn (array $a, array $b): int => [\count($this->chain($final, $a['id']) ?? []), $a['id']] <=> [\count($this->chain($final, $b['id']) ?? []), $b['id']]);

        return new CashflowCategoryImportPlan(
            [],
            $items,
            \count(array_diff(array_keys($inFile), $changed)),
            $generated,
            hash('sha256', json_encode($items, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)),
        );
    }

    /**
     * Обходит узлы файла в глубину и записывает их поля в итоговое состояние.
     *
     * @param array<mixed> $nodes
     * @param array<string, Values> $current
     * @param array<string, bool> $system
     * @param array<string, true> $taken
     * @param array<string, string> $plCategoryNames
     * @param list<string> $newIds
     * @param array<string, Values> $final
     * @param array<string, true> $inFile
     * @param array<string, string> $fileKind
     * @param list<string> $generated
     * @param list<string> $errors
     */
    private function collect(array $nodes, ?string $parentId, bool $top, array $current, array $system, array $taken, array $plCategoryNames, array &$newIds, array &$final, array &$inFile, array &$fileKind, array &$generated, array &$errors): void
    {
        foreach ($nodes as $node) {
            if (\count($inFile) >= self::MAX_NODES) {
                $errors[] = sprintf('В файле больше %d статей.', self::MAX_NODES);

                return;
            }
            if (!\is_array($node) || ([] !== $node && array_is_list($node))) {
                $errors[] = 'Каждая статья в файле должна быть объектом с полями.';
                continue;
            }
            $label = \is_string($node['name'] ?? null) ? '«'.trim($node['name']).'»' : 'без названия';
            $fail = static function (string $message) use (&$errors, $label): void {
                $errors[] = sprintf('Статья %s: %s', $label, $message);
            };

            $id = $node['id'] ?? null;
            if (null === $id) {
                $id = array_shift($newIds);
                $id = \is_string($id) && 1 === preg_match(self::UUID, $id) ? $id : Uuid::uuid7()->toString();
                $generated[] = $id;
            } elseif (!\is_string($id) || 1 !== preg_match(self::UUID, $id = strtolower($id))) {
                $fail('поле id не UUID. Чтобы создать статью, уберите поле id.');
                continue;
            } elseif (isset($taken[$id])) {
                $fail('этот id используется вне этой компании — файл выгружен из другой компании? Чтобы создать статью, уберите поле id.');
                continue;
            }
            if (isset($inFile[$id])) {
                $fail('встречается в файле дважды.');
                continue;
            }
            $inFile[$id] = true;
            $isNew = !isset($current[$id]);
            $values = $final[$id] ?? ['name' => '', 'description' => null, 'status' => CashflowCategoryStatus::ACTIVE->value, 'sort' => 0, 'parentId' => null, 'operationType' => null, 'allowPlDocument' => false, 'plCategoryId' => null, 'code' => null, 'flowKind' => CashflowFlowKind::OPERATING->value];

            if (!$top) {
                $values['parentId'] = $parentId;
            } elseif (\array_key_exists('parentId', $node)) {
                $topParent = \is_string($node['parentId']) ? strtolower($node['parentId']) : $node['parentId'];
                if (null !== $topParent && (!\is_string($topParent) || !isset($current[$topParent]))) {
                    $fail('родитель из поля parentId не найден среди статей компании.');
                } else {
                    $values['parentId'] = $topParent;
                }
            }

            if ($isNew || \array_key_exists('name', $node)) {
                $name = \is_string($node['name'] ?? null) ? trim($node['name']) : '';
                if ('' === $name || mb_strlen($name) > 255 || str_contains($name, "\0")) {
                    $fail('укажите название — строку до 255 символов.');
                }
                $values['name'] = $name;
            }
            if (\array_key_exists('description', $node)) {
                if (null !== $node['description'] && (!\is_string($node['description']) || str_contains($node['description'], "\0"))) {
                    $fail('поле description — строка или null.');
                } else {
                    $values['description'] = $node['description'];
                }
            }
            if (\array_key_exists('status', $node)) {
                if (!\is_string($node['status']) || null === CashflowCategoryStatus::tryFrom($node['status'])) {
                    $fail('поле status — active или disabled.');
                } else {
                    $values['status'] = $node['status'];
                }
            }
            if (\array_key_exists('sort', $node)) {
                if (!\is_int($node['sort']) || abs($node['sort']) > 2_147_483_647) {
                    $fail('поле sort — целое число до 2 147 483 647.');
                } else {
                    $values['sort'] = $node['sort'];
                }
            }
            if (\array_key_exists('operationType', $node)) {
                if (null !== $node['operationType'] && (!\is_string($node['operationType']) || null === PaymentPlanType::tryFrom($node['operationType']))) {
                    $fail('поле operationType — INFLOW, OUTFLOW, TRANSFER или null.');
                } else {
                    $values['operationType'] = $node['operationType'];
                }
            }
            if (\array_key_exists('allowPlDocument', $node)) {
                if (!\is_bool($node['allowPlDocument'])) {
                    $fail('поле allowPlDocument — true или false.');
                } else {
                    $values['allowPlDocument'] = $node['allowPlDocument'];
                }
            }
            if (\array_key_exists('plCategoryId', $node)) {
                $plCategoryId = \is_string($node['plCategoryId']) ? strtolower($node['plCategoryId']) : $node['plCategoryId'];
                if (null !== $plCategoryId && (!\is_string($plCategoryId) || !isset($plCategoryNames[$plCategoryId]))) {
                    $fail('Статья ОПиУ из поля plCategoryId не найдена в компании.');
                } else {
                    $values['plCategoryId'] = $plCategoryId;
                }
            }
            $codeKey = \array_key_exists('code', $node) ? 'code' : 'systemCode';
            if (\array_key_exists($codeKey, $node)) {
                try {
                    if (null !== $node[$codeKey] && !\is_string($node[$codeKey])) {
                        throw new \DomainException('поле code — строка или null.');
                    }
                    $values['code'] = CashflowCategory::normalizeCode($node[$codeKey]);
                } catch (\DomainException $exception) {
                    $fail($exception->getMessage());
                }
            }
            if (\array_key_exists('flowKind', $node)) {
                if (!\is_string($node['flowKind']) || null === CashflowFlowKind::tryFrom($node['flowKind'])) {
                    $fail('поле flowKind — OPERATING, INVESTING, FINANCING или TECHNICAL.');
                } else {
                    $fileKind[$id] = $node['flowKind'];
                }
            }
            if (\array_key_exists('isSystem', $node) && $node['isSystem'] !== ($system[$id] ?? false)) {
                $fail('поле isSystem менять нельзя: системные статьи создаёт сервис.');
            }

            $final[$id] = $values;

            $children = $node['children'] ?? [];
            if (!\is_array($children) || !array_is_list($children)) {
                $fail('поле children — список статей.');
                continue;
            }
            $this->collect($children, $id, false, $current, $system, $taken, $plCategoryNames, $newIds, $final, $inFile, $fileKind, $generated, $errors);
        }
    }

    /**
     * @param array<string, Values> $current
     * @param array<string, Values> $final
     * @param array<string, bool> $system
     * @param array<string, bool> $grouped
     *
     * @return list<string>
     */
    private function violations(string $id, array $current, array $final, array $system, array $grouped): array
    {
        $errors = [];
        $values = $final[$id];
        $old = $current[$id] ?? null;
        $name = '«'.$values['name'].'»';
        $moved = null === $old || $old['parentId'] !== $values['parentId'];
        $isSystem = $system[$id] ?? false;

        if ($isSystem && null !== $old) {
            $locked = array_filter(self::SYSTEM_LOCKED, static fn (string $field): bool => $old[$field] !== $values[$field]);
            if ([] !== $locked) {
                $errors[] = sprintf('У системной статьи «%s» нельзя менять: %s.', $old['name'], mb_strtolower(implode(', ', array_map(static fn (string $field): string => self::LABELS[$field], $locked))));
            }

            return $errors;
        }

        if (null !== $old && $moved && ($grouped[$id] ?? false)) {
            $errors[] = sprintf('Статью %s нельзя переносить: по ней строится консолидированная отчётность.', $name);
        }
        // Направление сверяется с эталоном группы при синхронизации и привязке.
        if (null !== $old && ($grouped[$id] ?? false) && $old['operationType'] !== $values['operationType']) {
            $errors[] = sprintf('У статьи %s нельзя менять тип операции: по ней строится консолидированная отчётность.', $name);
        }
        if (CashflowCategory::isSystemCode($values['code'])) {
            $errors[] = sprintf('Статья %s: код %s зарезервирован для системной статьи.', $name, $values['code']);
        }

        $parentId = $values['parentId'];
        if ($moved && null !== $parentId) {
            $accepts = ($system[$parentId] ?? false)
                ? \in_array($final[$parentId]['code'], self::SYSTEM_PARENTS, true)
                : CashflowFlowKind::TECHNICAL->value !== $final[$parentId]['flowKind'];
            if (!$accepts) {
                $errors[] = sprintf('Статья %s: «%s» не может содержать пользовательские статьи.', $name, $final[$parentId]['name']);
            }
        }
        if (null === $parentId && CashflowFlowKind::TECHNICAL->value === $values['flowKind'] && ($moved || $old['flowKind'] !== $values['flowKind'])) {
            $errors[] = sprintf('Статья %s: Технический вид деятельности разрешён только системным статьям.', $name);
        }

        return $errors;
    }

    /**
     * @param Values $old
     * @param Values $new
     * @param array<string, Values> $current
     * @param array<string, Values> $final
     * @param array<string, string> $plCategoryNames
     *
     * @return list<array{label: string, from: string, to: string}>
     */
    private function changes(array $old, array $new, array $current, array $final, array $plCategoryNames): array
    {
        $changes = [];
        foreach (self::LABELS as $field => $label) {
            if ($old[$field] === $new[$field]) {
                continue;
            }
            $show = static fn (mixed $value, array $names): string => match (true) {
                'parentId' === $field => null === $value ? 'корень' : $names[$value]['name'],
                'plCategoryId' === $field => null === $value ? '—' : ($plCategoryNames[$value] ?? (string) $value),
                'flowKind' === $field => CashflowFlowKind::from($value)->label(),
                \is_bool($value) => $value ? 'да' : 'нет',
                null === $value || '' === $value => '—',
                default => (string) $value,
            };
            $changes[] = ['label' => $label, 'from' => $show($old[$field], $current), 'to' => $show($new[$field], $final)];
        }

        return $changes;
    }

    /**
     * Статья и её предки снизу вверх; null — в цепочке родителей цикл.
     *
     * @param array<string, Values> $tree
     *
     * @return non-empty-list<string>|null
     */
    private function chain(array $tree, string $id): ?array
    {
        $chain = [$id];
        while (null !== ($id = $tree[$id]['parentId'] ?? null)) {
            if (\in_array($id, $chain, true)) {
                return null;
            }
            $chain[] = $id;
        }

        return $chain;
    }

    /**
     * Все `id` статей файла в нижнем регистре — вызывающий проверяет по ним, какие заняты вне компании.
     *
     * @return list<string>
     */
    public static function fileIds(mixed $payload): array
    {
        $ids = [];
        $walk = static function (mixed $nodes) use (&$walk, &$ids): void {
            if (!\is_array($nodes)) {
                return;
            }
            foreach ($nodes as $node) {
                if (\is_array($node)) {
                    if (\is_string($node['id'] ?? null) && 1 === preg_match(self::UUID, strtolower($node['id']))) {
                        $ids[] = strtolower($node['id']);
                    }
                    $walk($node['children'] ?? []);
                }
            }
        };
        $walk($payload);

        return array_values(array_unique($ids));
    }

    /** @param list<string> $errors */
    private function rejected(array $errors): CashflowCategoryImportPlan
    {
        return new CashflowCategoryImportPlan(array_values(array_unique($errors)), [], 0, [], '');
    }
}

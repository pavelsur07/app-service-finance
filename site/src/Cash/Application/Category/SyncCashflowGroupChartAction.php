<?php

declare(strict_types=1);

namespace App\Cash\Application\Category;

use App\Cash\Application\DTO\CashflowGroupChartNode;
use App\Cash\Application\DTO\CashflowGroupChartSyncPlan;
use App\Cash\Application\DTO\Template\CashflowCoreTemplateNode;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashflowGroupChartApplication;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\ConsolidationGroupFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;

/**
 * Выравнивает дерево ДДС компании по эталону группы: участник — по дереву головной компании,
 * головная и компания вне группы — по core.yaml. Для узла эталона: статья с тем же кодом уже
 * привязана; иначе одна статья без кода с тем же названием в ветке родителя получает код; две и
 * больше — конфликт (дети узла пропускаются); нет ни одной — статья создаётся. Ничего не удаляет,
 * не переименовывает и не переносит.
 *
 * plan() только читает. __invoke() и bind() (ручная привязка с экрана) — внутри транзакции
 * вызывающего (команда, ApplyCashflowGroupChartAction, BindCashflowCategoryGroupCodeAction): блокируют сначала
 * головную компанию (источник), потом компанию, тем же ключом, что CashflowCategoryStructureMigrator.
 * Статьи головной без кода получают код = свой id: так эталон становится ключом консолидации.
 */
final class SyncCashflowGroupChartAction
{
    /** parentKey обычной корневой статьи головной компании. */
    public const ROOT = '';
    /** Та же вложенность, что проверяет SaveCashflowCategoryAction. */
    private const MAX_LEVEL = 5;

    public function __construct(
        private readonly CompanyFacade $companyFacade,
        private readonly ConsolidationGroupFacadeInterface $groups,
        private readonly CashflowCategoryRepository $categories,
        private readonly CashflowCoreTemplateReader $reader,
        private readonly EnsureCashflowSystemCategoriesAction $ensureSystem,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function plan(string $companyId): CashflowGroupChartSyncPlan
    {
        return $this->run($companyId, $this->sourceHead($companyId), false);
    }

    public function __invoke(string $companyId): CashflowGroupChartSyncPlan
    {
        $head = $this->lock($companyId);
        $result = $this->run($companyId, $head, true);
        if (!$result->isEmpty()) {
            $this->journal($companyId, $result->source, $head, \count($result->bind), \count($result->create), \count($result->conflicts), $result->headCodes);
        }

        $this->logger->info('Cashflow group chart synced', [
            'companyId' => $companyId,
            'source' => $result->source,
            'headCompanyId' => $result->headCompanyId,
            'bound' => \count($result->bind),
            'created' => \count($result->create),
            'conflicts' => \count($result->conflicts),
            'headCodes' => $result->headCodes,
        ]);

        return $result;
    }

    /**
     * Ручная привязка статьи компании к узлу эталона: только ставит код, дерево не перестраивает.
     * Внутри транзакции вызывающего, под теми же блокировками, что __invoke(). Отказ возвращается
     * строкой до любой записи: вызывающий завершает транзакцию без изменений и бросает исключение.
     */
    public function bind(string $companyId, string $categoryId, string $code): ?string
    {
        $head = $this->lock($companyId);
        $company = $this->company($companyId);
        $headCodes = 0;
        $nodes = null === $head ? $this->coreNodes() : $this->headNodes($this->company($head), false, $headCodes);
        $tree = $this->categories->findTreeByCompany($company);
        $byKey = self::keys($tree);

        $category = null;
        foreach ($tree as $candidate) {
            if ((string) $candidate->getId() === $categoryId) {
                $category = $candidate;
            }
        }
        if (null === $category) {
            return 'Статья не найдена.';
        }
        if ($category->isSystem()) {
            return sprintf('«%s» — системная статья, её не привязывают.', $category->getName());
        }
        if (null !== $category->getGroupCode()) {
            return sprintf('У статьи «%s» уже есть код %s.', $category->getName(), $category->getGroupCode());
        }
        [$direction, $kind] = self::nodeFacts($nodes, $byKey);
        if (!\array_key_exists($code, $direction)) {
            return sprintf('Кода %s нет в эталоне.', $code);
        }
        if (isset($byKey[$code])) {
            return sprintf('Код %s уже у статьи «%s».', $code, $this->path($byKey[$code]));
        }
        $own = self::effectiveDirection($category);
        if (null !== $own && null !== $direction[$code] && $own !== $direction[$code]) {
            return sprintf('У статьи «%s» другое направление, чем у %s.', $category->getName(), $code);
        }
        if (null !== $kind[$code] && $category->getEffectiveFlowKind() !== $kind[$code]) {
            return sprintf('Статья «%s» в другом виде деятельности, чем %s.', $category->getName(), $code);
        }

        if (null !== $head) {
            // Как в синхронизации: статьи головной без кода получают код = id, и выбранный код тоже.
            $headCodes = 0;
            $this->headNodes($this->company($head), true, $headCodes);
        }
        $category->assignGroupCode($code);
        $this->journal($companyId, null === $head ? 'core' : 'head', $head, 1, 0, 0, $headCodes);
        $this->logger->info('Cashflow category bound to group chart', ['companyId' => $companyId, 'categoryId' => $categoryId, 'code' => $code, 'headCompanyId' => $head]);

        return null;
    }

    /**
     * Блокировки синхронизации и привязки: состав группы (кабинет), затем головная, затем компания.
     *
     * @return string|null головная компания-источник (только у участника)
     */
    private function lock(string $companyId): ?string
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Синхронизация дерева ДДС выполняется только внутри транзакции вызывающего.');
        }
        // Состав группы — первым и до конца транзакции: ConfigureConsolidationGroupAction ждёт.
        $locked = $this->groups->lockedHeadOf($companyId);
        $head = null !== $locked && $locked !== $companyId ? $locked : null;
        foreach (null === $head ? [$companyId] : [$head, $companyId] as $lockedId) {
            $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtext(:companyId))', ['companyId' => $lockedId]);
            ($this->ensureSystem)($lockedId);
            $this->categories->lockTreeByCompany($this->company($lockedId));
        }

        return $head;
    }

    private function journal(string $companyId, string $source, ?string $head, int $bound, int $created, int $conflicts, int $headCodes): void
    {
        $this->entityManager->flush();
        $this->entityManager->persist(new CashflowGroupChartApplication($companyId, $source, $head, $bound, $created, $conflicts, $headCodes, $this->clock->now()));
        $this->entityManager->flush();
    }

    /**
     * @param array<CashflowCategory> $tree
     *
     * @return array<string, CashflowCategory> код или sys:<systemCode> → статья компании
     */
    private static function keys(array $tree): array
    {
        $byKey = [];
        foreach ($tree as $category) {
            if (null !== $category->getGroupCode()) {
                $byKey[$category->getGroupCode()] = $category;
            }
            if ($category->isSystem() && null !== $category->getSystemCode()) {
                $byKey['sys:'.$category->getSystemCode()] = $category;
            }
        }

        return $byKey;
    }

    /**
     * Действующие направление и вид деятельности узлов эталона: наследуются от родителя, корень
     * системной секции берёт вид деятельности у системной статьи компании.
     *
     * @param list<CashflowGroupChartNode> $nodes
     * @param array<string, CashflowCategory> $byKey
     *
     * @return array{array<string, PaymentPlanType|null>, array<string, CashflowFlowKind|null>}
     */
    private static function nodeFacts(array $nodes, array $byKey): array
    {
        $direction = [];
        $kind = [];
        foreach ($nodes as $node) {
            $direction[$node->code] = $node->direction ?? $direction[$node->parentKey] ?? null;
            $kind[$node->code] = $node->flowKind ?? $kind[$node->parentKey] ?? (isset($byKey[$node->parentKey]) ? $byKey[$node->parentKey]->getEffectiveFlowKind() : null);
        }

        return [$direction, $kind];
    }

    private function run(string $companyId, ?string $head, bool $apply): CashflowGroupChartSyncPlan
    {
        $company = $this->company($companyId);
        $headCodes = 0;
        $nodes = null === $head ? $this->coreNodes() : $this->headNodes($this->company($head), $apply, $headCodes);

        $tree = $this->categories->findTreeByCompany($company);
        $byKey = self::keys($tree);
        /** @var array<string, string> $freeCodes коды, которых у компании нет до плана → путь в эталоне */
        $freeCodes = [];
        /** @var array<string, string> $labels */
        $labels = [];
        foreach ($nodes as $node) {
            $prefix = $labels[$node->parentKey] ?? (isset($byKey[$node->parentKey]) ? $byKey[$node->parentKey]->getName() : null);
            $labels[$node->code] = (null !== $prefix ? $prefix.' / ' : '').$node->name;
            if (!isset($byKey[$node->code])) {
                $freeCodes[$node->code] = $labels[$node->code];
            }
        }

        $bind = [];
        $create = [];
        $conflicts = [];
        /** @var array<string, int> $virtual коды, которые будут созданы в плане, → уровень */
        $virtual = [];
        /** @var array<string, true> $taken статьи, привязанные в этом прогоне */
        $taken = [];
        /** @var array<string, PaymentPlanType|null> $direction действующее направление узла эталона */
        $direction = [];
        foreach ($nodes as $node) {
            $direction[$node->code] = $node->direction ?? $direction[$node->parentKey] ?? null;
            if (isset($byKey[$node->code]) || isset($virtual[$node->code])) {
                continue;
            }
            $label = sprintf('%s «%s»', $node->code, $node->name);
            $isRoot = self::ROOT === $node->parentKey;
            $parent = $isRoot ? null : ($byKey[$node->parentKey] ?? null);
            if (!$isRoot && null === $parent && !isset($virtual[$node->parentKey])) {
                $conflicts[] = $label.': родитель не сопоставлен';
                continue;
            }
            $level = $isRoot ? 1 : 1 + ($parent?->getLevel() ?? $virtual[$node->parentKey]);
            if ($level > self::MAX_LEVEL) {
                $conflicts[] = $label.sprintf(': у компании статья оказалась бы глубже %d уровней — разместите вручную', self::MAX_LEVEL);
                continue;
            }
            if (!$isRoot && null === $parent) {
                $virtual[$node->code] = $level;
                $create[] = $label;
                continue;
            }

            $candidates = array_values(array_filter(
                $tree,
                fn (CashflowCategory $c): bool => !$c->isSystem()
                    && null === $c->getGroupCode()
                    && !isset($taken[(string) $c->getId()])
                    && ($isRoot ? null === $c->getParent() : $c->isDescendantOf($parent))
                    && self::normalize($c->getName()) === self::normalize($node->name),
            ));
            if (\count($candidates) > 1) {
                $conflicts[] = $label.sprintf(': %d статьи с таким названием — привяжите вручную', \count($candidates));
                continue;
            }
            if (1 === \count($candidates)) {
                $category = $candidates[0];
                $own = self::effectiveDirection($category);
                if (null !== $own && null !== $direction[$node->code] && $own !== $direction[$node->code]) {
                    $conflicts[] = $label.sprintf(': у статьи «%s» другое направление — привяжите вручную', $this->path($category));
                    continue;
                }
                if ($isRoot && null !== $node->flowKind && $category->getEffectiveFlowKind() !== $node->flowKind) {
                    $conflicts[] = $label.sprintf(': у статьи «%s» другой вид деятельности — привяжите вручную', $this->path($category));
                    continue;
                }
                $taken[(string) $category->getId()] = true;
                $bind[] = $this->path($category).' → '.$node->code;
                if ($apply) {
                    $category->assignGroupCode($node->code);
                }
                $byKey[$node->code] = $category;
                continue;
            }

            $create[] = $label;
            if (!$apply) {
                $virtual[$node->code] = $level;
                continue;
            }
            $category = new CashflowCategory(Uuid::uuid7()->toString(), $company);
            $category
                ->setName($node->name)
                ->setDescription($node->description)
                // Действующее направление эталона: у родителя компании его может не быть.
                ->setOperationType($direction[$node->code])
                ->setSort($node->sort)
                ->setParent($parent)
                ->assignGroupCode($node->code);
            if (null === $parent) {
                $category->setFlowKind($node->flowKind ?? CashflowFlowKind::OPERATING);
            } else {
                $category->syncFlowKindWithParent();
            }
            $this->entityManager->persist($category);
            $byKey[$node->code] = $category;
        }

        $unmatched = [];
        foreach ($tree as $category) {
            if (!$category->isSystem() && null === $category->getGroupCode() && !isset($taken[(string) $category->getId()])) {
                $unmatched[(string) $category->getId()] = $this->path($category);
            }
        }

        return new CashflowGroupChartSyncPlan(null === $head ? 'core' : 'head', $head, $bind, $create, $conflicts, $headCodes, $unmatched, $freeCodes);
    }

    /** Направление статьи с наследованием от ближайшего предка, где оно задано. */
    private static function effectiveDirection(CashflowCategory $category): ?PaymentPlanType
    {
        for ($node = $category; null !== $node; $node = $node->getParent()) {
            if (null !== $node->getOperationType()) {
                return $node->getOperationType();
            }
        }

        return null;
    }

    /** Головная компания-источник: только у участника; головная и компания вне группы — core.yaml. */
    private function sourceHead(string $companyId): ?string
    {
        $head = $this->groups->headOf($companyId);

        return null !== $head && $head !== $companyId ? $head : null;
    }

    /**
     * @return list<CashflowGroupChartNode> от родителей к детям
     */
    private function coreNodes(): array
    {
        $nodes = [];
        $walk = function (array $items, string $parentKey) use (&$walk, &$nodes): void {
            /** @var list<CashflowCoreTemplateNode> $items */
            foreach ($items as $item) {
                $nodes[] = new CashflowGroupChartNode($item->code, $item->name, $item->description, $item->direction, $item->sort, $parentKey);
                $walk($item->children, $item->code);
            }
        };
        foreach ($this->reader->read() as $sectionCode => $items) {
            $walk($items, 'sys:'.$sectionCode);
        }

        return $nodes;
    }

    /**
     * Несистемные статьи головной компании от родителей к детям. Статье без кода код = её id:
     * при apply он записывается, в плане считается виртуально.
     *
     * @return list<CashflowGroupChartNode>
     */
    private function headNodes(Company $head, bool $apply, int &$headCodes): array
    {
        $tree = $this->categories->findTreeByCompany($head);
        $code = static function (CashflowCategory $category): string {
            return $category->getGroupCode() ?? (string) $category->getId();
        };

        $nodes = [];
        foreach ($tree as $category) {
            if ($category->isSystem()) {
                continue;
            }
            if (null === $category->getGroupCode()) {
                ++$headCodes;
                if ($apply) {
                    $category->assignGroupCode((string) $category->getId());
                }
            }
            $parent = $category->getParent();
            $parentKey = null === $parent ? self::ROOT : ($parent->isSystem() ? 'sys:'.$parent->getSystemCode() : $code($parent));
            $nodes[] = new CashflowGroupChartNode($code($category), $category->getName(), $category->getDescription(), $category->getOperationType(), $category->getSort(), $parentKey, null === $parent ? $category->getFlowKind() : null);
        }

        return $nodes;
    }

    private function company(string $companyId): Company
    {
        return $this->companyFacade->findById($companyId)
            ?? throw new \DomainException(sprintf('Компания %s не найдена.', $companyId));
    }

    private function path(CashflowCategory $category): string
    {
        $parts = [];
        for ($node = $category; null !== $node; $node = $node->getParent()) {
            array_unshift($parts, $node->isSystem() && null !== $node->getSystemCode() ? $node->getSystemCode() : $node->getName());
        }

        return implode('/', $parts);
    }

    private static function normalize(string $name): string
    {
        $name = str_replace('ё', 'е', mb_strtolower($name));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name));
    }
}

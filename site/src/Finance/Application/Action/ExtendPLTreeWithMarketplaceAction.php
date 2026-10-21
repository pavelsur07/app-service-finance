<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Company\Enum\BusinessProfile;
use App\Company\Facade\CompanyFacade;
use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Application\DTO\ExtendPLTreeResult;
use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Application\DTO\PLFormulaChange;
use App\Finance\Application\Service\PLContributionVerifier;
use App\Finance\Entity\PLTemplateApplication;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLTemplateApplicationSource;
use App\Finance\Exception\PLTemplateProfileMismatchException;
use App\Finance\Exception\PLTreeExtensionException;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Finance\Repository\PLCategoryRepository;
use App\Finance\Repository\PLTemplateApplicationRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Дополняет дерево ОПиУ компании «Базовый» статьями маркетплейса: те же коды и места, что в
 * маркетплейсном шаблоне, — их ждут шаблоны маппинга затрат и продаж. Выручка маркетплейса входит в
 * «Выручку», его расходы — в EBITDA: формулы REV_TOTAL и EBITDA заменяются формулами расширения.
 *
 * Статьи — тем же импортом в режиме `onlyCreate` (свои статьи компании не меняются, второе дерево и
 * дубли похожих названий не создаются). Формула заменяется, только если она всё ещё шаблонная
 * «Базового»: изменённую пользователем молча не перетираем — отказ целиком. Повтор ничего не делает.
 * Применение записывается с профилем UNIVERSAL: происхождение дерева остаётся «Базовым».
 */
final readonly class ExtendPLTreeWithMarketplaceAction
{
    /** Коды, по которым дерево опознаётся как «Базовое»: их нет в маркетплейсном шаблоне. */
    private const UNIVERSAL_MARKERS = ['GROSS_PROFIT', 'REV_SALES_TOTAL'];

    /** Итоги, вклад в которые сверяется расчётом после дополнения. */
    private const CHECKED_TOTALS = ['REV_TOTAL', 'GROSS_PROFIT', 'EBITDA', 'NET_PROFIT'];

    public function __construct(
        private ImportPLCategoryTreeAction $import,
        private DefaultPLCategoryTreeProvider $templates,
        private PLCategoryRepository $categories,
        private PLTemplateApplicationRepository $applications,
        private CompanyFacade $companies,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private PLContributionVerifier $verifier,
    ) {
    }

    public function __invoke(string $companyId, bool $dryRun): ExtendPLTreeResult
    {
        $nodes = $this->templates->marketplaceExtension();
        $command = new ImportPLCategoryTreeCommand($nodes, $companyId, true, onlyCreate: true);
        $changes = $this->check($companyId, $nodes);
        $preview = ($this->import)($command);
        // Пропущенная как «похожая» статья не встала бы на своё место, а формулы итогов на неё
        // сослались бы (EBITDA с нулём вместо продвижения) и маппинг маркетплейса её не нашёл бы.
        if ([] !== $preview->similar) {
            throw PLTreeExtensionException::similarLines(array_map(static fn ($r): string => \sprintf('«%s» ≈ ваша «%s»', $r->name, $r->existingName), $preview->similar));
        }
        if ($dryRun) {
            return new ExtendPLTreeResult($preview, $changes);
        }

        $result = $this->em->wrapInTransaction(function () use ($companyId, $nodes): ExtendPLTreeResult {
            $this->em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:key))', ['key' => 'pl-template:'.$companyId]);
            $changes = $this->check($companyId, $nodes);
            $import = ($this->import)(new ImportPLCategoryTreeCommand($nodes, $companyId, false, onlyCreate: true));
            if ([] !== $import->similar) {
                throw PLTreeExtensionException::similarLines(array_map(static fn ($r): string => \sprintf('«%s» ≈ ваша «%s»', $r->name, $r->existingName), $import->similar));
            }
            foreach ($changes as $change) {
                // Условная запись: правка формулы в карточке статьи (без этой блокировки) между
                // проверкой и записью не перетирается — откат всего дополнения.
                $updated = $this->em->getConnection()->executeStatement(
                    'UPDATE pl_categories SET formula = ? WHERE company_id = ? AND code = ? AND formula = ?',
                    [$change->to, $companyId, $change->code, $change->from],
                );
                if (1 !== $updated) {
                    throw PLTreeExtensionException::concurrentChange($change->name);
                }
                $category = $this->categories->findOneBy(['company' => $companyId, 'code' => $change->code]);
                if (null !== $category) {
                    $this->em->refresh($category);
                }
            }
            $this->em->flush();
            // Граф после дополнения (созданные статьи и записанные формулы) — ещё раз до фиксации.
            $this->assertPathToNetProfitIsTemplate($companyId, $nodes);
            // Последняя страховка — расчётом по собранному дереву: вклад каждой статьи ввода шаблона в
            // «Выручку», валовую прибыль, EBITDA и чистую прибыль — как по шаблону. Вручную перестроенное
            // дерево (группа вынесена или перенесена, вес, тип) иначе дало бы неверные итоги молча.
            [$expected, $names] = $this->templateContributions($nodes);
            $company = $this->companies->findById($companyId) ?? throw new \DomainException('Целевая компания не найдена.');
            $offenders = $this->verifier->offenders($company, $expected, self::CHECKED_TOTALS);
            if ([] !== $offenders) {
                throw PLTreeExtensionException::totalsBroken(array_map(static fn (string $code): string => \sprintf('«%s» (%s)', $names[$code], $code), $offenders));
            }
            if ([] !== $import->created) {
                $this->em->persist(new PLTemplateApplication($companyId, BusinessProfile::UNIVERSAL, $this->templates->marketplaceExtensionVersion(), \count($import->created), PLTemplateApplicationSource::MARKETPLACE_EXTENSION, new \DateTimeImmutable()));
            }
            $this->em->flush();

            return new ExtendPLTreeResult($import, $changes);
        });

        $this->logger->info('P&L tree extended with marketplace lines', [
            'companyId' => $companyId,
            'templateVersion' => $this->templates->marketplaceExtensionVersion(),
            'created' => \count($result->import->created),
            'formulas' => array_map(static fn (PLFormulaChange $c): string => $c->code, $result->formulaChanges),
        ]);

        return $result;
    }

    /**
     * Компания «Базовый» с деревом «Базового»; формулы, которые нужно заменить.
     *
     * @param list<PLCategoryTreeNode> $nodes
     *
     * @return list<PLFormulaChange>
     */
    private function check(string $companyId, array $nodes): array
    {
        if (BusinessProfile::UNIVERSAL !== $this->companies->getBusinessProfile($companyId)) {
            throw PLTreeExtensionException::notUniversal();
        }
        $origin = $this->applications->findLatestByCompanyId($companyId);
        if (null !== $origin && BusinessProfile::UNIVERSAL !== $origin->getProfile()) {
            throw PLTemplateProfileMismatchException::between($origin->getProfile(), BusinessProfile::UNIVERSAL);
        }

        $company = $this->companies->findById($companyId) ?? throw new \DomainException('Целевая компания не найдена.');
        $codes = array_flip($this->categories->findCodesByCompany($company));
        foreach (self::UNIVERSAL_MARKERS as $marker) {
            if (!isset($codes[$marker])) {
                throw PLTreeExtensionException::notUniversalTree();
            }
        }

        $universalFormulas = [];
        foreach ($this->templates->nodes(BusinessProfile::UNIVERSAL) as $node) {
            if (null !== $node->code) {
                $universalFormulas[$node->code] = $node->formula;
            }
        }

        $this->assertMarketplaceLinesInPlace($companyId, $nodes, $universalFormulas);

        $changes = [];
        foreach ($nodes as $node) {
            if (null === $node->code || self::same($node->formula, $universalFormulas[$node->code] ?? $node->formula)) {
                continue;
            }
            // Из базы, а не из EntityManager: под блокировкой важна текущая формула, а не прочитанная раньше.
            // Итога нет — импорт создаст его по шаблону, с формулой расширения.
            $row = $this->em->getConnection()->fetchAssociative('SELECT name, formula, type FROM pl_categories WHERE company_id = ? AND code = ?', [$companyId, $node->code]);
            if (false === $row) {
                continue;
            }
            // Строка ввода формулу не считает (PlReportCalculator): новая формула молча не работала бы.
            if ($row['type'] !== $node->type->value) {
                throw PLTreeExtensionException::totalTypeChanged((string) $row['name'], $node->code);
            }
            if (self::same($row['formula'], $node->formula)) {
                continue;
            }
            if (!self::same($row['formula'], $universalFormulas[$node->code])) {
                throw PLTreeExtensionException::formulaChanged((string) $row['name'], (string) $row['formula'], (string) $universalFormulas[$node->code]);
            }
            $changes[] = new PLFormulaChange($node->code, (string) $row['name'], (string) $row['formula'], (string) $node->formula);
        }

        // Путь — по графу после дополнения: группы, которые новые формулы впервые включают в итоги
        // (свой корень PROMO_TOTAL с собственной формулой внутри), тоже проверяются.
        $this->assertPathToNetProfitIsTemplate($companyId, $nodes, array_combine(
            array_map(static fn (PLFormulaChange $c): string => $c->code, $changes),
            array_map(static fn (PLFormulaChange $c): string => $c->to, $changes),
        ));

        return $changes;
    }

    /**
     * Итоги, через которые статьи доходят до чистой прибыли (от NET_PROFIT по слагаемым формул и по
     * потомкам групп), должны считаться по шаблону: KPI — шаблонной формулой («Базового» или уже
     * расширения), группа — суммой потомков без формулы. Своя формула на этом пути (например, с
     * умножением) могла бы считать статьи маркетплейса не один раз, и проверка расчётом по одной
     * статье этого не увидит. Формулы вне пути (маржи, доли) — свободны.
     *
     * @param list<PLCategoryTreeNode> $nodes
     * @param array<string, string> $overrides код → формула после дополнения (ещё не записана)
     */
    private function assertPathToNetProfitIsTemplate(string $companyId, array $nodes, array $overrides = []): void
    {
        $template = [];
        foreach ($nodes as $node) {
            if (null !== $node->code) {
                $template[$node->code] = $node->formula;
            }
        }
        $universal = [];
        foreach ($this->templates->nodes(BusinessProfile::UNIVERSAL) as $node) {
            $universal[(string) $node->code] = $node->formula;
        }

        // Обход по настоящему дереву компании: свои группы и статьи без кода тоже на пути.
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT id, parent_id, code, name, type, formula FROM pl_categories WHERE company_id = ?', [$companyId]);
        $byCode = $children = [];
        foreach ($rows as $row) {
            if (null !== $row['code']) {
                $byCode[(string) $row['code']] = $row;
            }
            if (null !== $row['parent_id']) {
                $children[(string) $row['parent_id']][] = $row;
            }
        }

        $seen = [];
        $queue = isset($byCode['NET_PROFIT']) ? [$byCode['NET_PROFIT']] : [];
        while ([] !== $queue) {
            $row = array_shift($queue);
            $id = (string) $row['id'];
            if (isset($seen[$id]) || PLCategoryType::LEAF_INPUT->value === $row['type']) {
                continue;
            }
            $seen[$id] = true;
            $code = null !== $row['code'] ? (string) $row['code'] : null;
            $formula = trim((string) (null !== $code && isset($overrides[$code]) ? $overrides[$code] : $row['formula']));
            if ('' !== $formula) {
                // Формула на пути — только шаблонная («Базового» или расширения) у статьи шаблона.
                $allowed = null !== $code && \array_key_exists($code, $template) ? [$template[$code], $universal[$code] ?? $template[$code]] : [];
                if (!\in_array(true, array_map(static fn (?string $f): bool => self::same($formula, $f) && '' !== trim((string) $f), $allowed), true)) {
                    throw PLTreeExtensionException::formulaChanged((string) $row['name'], $formula, (string) (null !== $code ? ($universal[$code] ?? $template[$code] ?? '') : ''));
                }
                preg_match_all('/[A-Z][A-Z0-9_]*/', $formula, $terms);
                foreach ($terms[0] as $term) {
                    if (isset($byCode[$term])) {
                        $queue[] = $byCode[$term];
                    }
                }
            } else {
                foreach ($children[$id] ?? [] as $child) {
                    $queue[] = $child;
                }
            }
        }
    }

    /**
     * Статьи маркетплейса (коды расширения, которых нет в «Базовом»), которые у компании уже есть,
     * должны стоять на месте шаблона и быть того же типа. Импорт `onlyCreate` их не переносит, а
     * итоги расширения на эти места рассчитаны: своя «OPEX_WH_TOTAL» внутри операционных расходов
     * посчиталась бы в EBITDA дважды, «Продажи без СПП» в корне не вошли бы в выручку, «Выручка с СПП»
     * под «Выручкой» попала бы в прибыль.
     *
     * @param list<PLCategoryTreeNode> $nodes
     * @param array<string, ?string> $universalFormulas коды «Базового»
     */
    private function assertMarketplaceLinesInPlace(string $companyId, array $nodes, array $universalFormulas): void
    {
        $template = [];
        foreach ($nodes as $node) {
            if (null !== $node->code && !\array_key_exists($node->code, $universalFormulas)) {
                $template[$node->code] = $node;
            }
        }
        if ([] === $template) {
            return;
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT c.code, c.name, c.type, c.parent_id, p.code AS parent_code FROM pl_categories c LEFT JOIN pl_categories p ON p.id = c.parent_id WHERE c.company_id = ? AND c.code IN (?)',
            [$companyId, array_keys($template)],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );
        foreach ($rows as $row) {
            $node = $template[(string) $row['code']];
            // Корень шаблона — только корень у компании (родитель без кода — тоже не корень);
            // вложенная — под родителем с тем же кодом.
            $expectedParent = $node->parent?->code;
            $inPlace = null === $expectedParent ? null === $row['parent_id'] : $expectedParent === $row['parent_code'];
            if (!$inPlace || $row['type'] !== $node->type->value) {
                throw PLTreeExtensionException::misplaced((string) $row['name'], (string) $row['code']);
            }
        }
    }

    /**
     * Ожидаемый вклад каждой статьи ввода шаблона в проверяемые итоги — по самому шаблону: итоги на
     * пути — суммы (подытог — потомки с весом, KPI — слагаемые формулы со знаком).
     *
     * @param list<PLCategoryTreeNode> $nodes
     *
     * @return array{0: array<string, array<string, float>>, 1: array<string, string>}
     */
    private function templateContributions(array $nodes): array
    {
        $byCode = $children = $names = [];
        foreach ($nodes as $node) {
            if (null === $node->code) {
                continue;
            }
            $byCode[$node->code] = $node;
            if (null !== $node->parent?->code) {
                $children[$node->parent->code][] = $node;
            }
            if (PLCategoryType::LEAF_INPUT === $node->type) {
                $names[$node->code] = $node->name;
            }
        }

        $expected = array_fill_keys(array_keys($names), []);
        foreach (self::CHECKED_TOTALS as $total) {
            foreach ($this->coefficients($total, $byCode, $children) as $leaf => $k) {
                if (0.0 !== $k) {
                    $expected[$leaf][$total] = $k;
                }
            }
        }

        return [$expected, $names];
    }

    /**
     * @param array<string, PLCategoryTreeNode> $byCode
     * @param array<string, list<PLCategoryTreeNode>> $children
     *
     * @return array<string, float> статья ввода → коэффициент
     */
    private function coefficients(string $code, array $byCode, array $children): array
    {
        $node = $byCode[$code] ?? null;
        if (null === $node) {
            return [];
        }
        if (PLCategoryType::LEAF_INPUT === $node->type) {
            return [$code => 1.0];
        }
        $result = [];
        if (null !== $node->formula && '' !== trim($node->formula)) {
            if (1 !== preg_match('/^\s*[A-Z][A-Z0-9_]*(\s*[+-]\s*[A-Z][A-Z0-9_]*)*\s*$/', $node->formula)) {
                throw new \LogicException(\sprintf('Формула %s шаблона не сумма — проверка расчётом её не поддерживает.', $code));
            }
            preg_match_all('/([+-]?)\s*([A-Z][A-Z0-9_]*)/', $node->formula, $m, \PREG_SET_ORDER);
            foreach ($m as [, $sign, $term]) {
                foreach ($this->coefficients($term, $byCode, $children) as $leaf => $k) {
                    $result[$leaf] = ($result[$leaf] ?? 0.0) + ('-' === $sign ? -$k : $k);
                }
            }

            return $result;
        }
        foreach ($children[$code] ?? [] as $child) {
            foreach ($this->coefficients((string) $child->code, $byCode, $children) as $leaf => $k) {
                $result[$leaf] = ($result[$leaf] ?? 0.0) + $k * (float) $child->weightInParent;
            }
        }

        return $result;
    }

    private static function same(?string $a, ?string $b): bool
    {
        $normalize = static fn (?string $f): string => (string) preg_replace('/\s+/', '', (string) $f);

        return $normalize($a) === $normalize($b);
    }
}

<?php

declare(strict_types=1);

namespace App\Finance\Report;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Engine\DependencyExtractor;
use App\Finance\Engine\Graph;
use App\Finance\Engine\TopoSort;
use App\Finance\Engine\ValueFormatter;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLValueFormat;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Formula\Evaluator;
use App\Finance\Formula\Parser;
use App\Finance\Formula\Tokenizer;
use App\Finance\Repository\PLCategoryRepository;

final class PlReportCalculator
{
    public function __construct(
        private readonly PLCategoryRepository $categories,
        private readonly FactsProviderInterface $facts,
        private readonly Tokenizer $tokenizer = new Tokenizer(),
        private readonly Parser $parser = new Parser(),
        private readonly Evaluator $evaluator = new Evaluator(),
        private readonly DependencyExtractor $deps = new DependencyExtractor(),
        private readonly TopoSort $topo = new TopoSort(),
        private readonly ValueFormatter $fmt = new ValueFormatter(),
        private readonly ?PlFormulaVariables $variables = null,
        // false — факты заданы суммой колонки и от периода не зависят (withFacts): по месяцам их не разложить.
        private readonly bool $factsFollowPeriod = true,
    ) {
    }

    public function supportsProjectDimension(): bool
    {
        return true;
    }

    public function calculate(
        Company $company,
        PlReportPeriod $period,
        ProjectDirection|array|null $projectDirection = null,
        string|array|null $responsibilityCenterId = null,
    ): PlReportResult {
        /** @var ProjectDirection|list<ProjectDirection>|null $projectDirection */
        /** @var string|list<string>|null $responsibilityCenterId */
        /** @var list<PLCategory> $all */
        $all = $this->categories->findBy(['company' => $company], ['parent' => 'ASC', 'sortOrder' => 'ASC']);
        $displayOrder = $this->orderByTree($all);
        /** @var array<string,PLCategory> $byId */
        $byId = [];
        foreach ($all as $c) {
            $byId[$c->getId()] = $c;
        }

        $astById = [];
        $g = new Graph();
        $warnings = [];
        /** @var list<array{string, string}> $variableUses [имя переменной, название статьи] */
        $variableUses = [];
        /** @var list<string> $moneyVariableIds денежные статьи, чья формула читает переменную ставки */
        $moneyVariableIds = [];

        foreach ($all as $cat) {
            $catId = (string) $cat->getId();
            $g->addNode($catId);
            $formula = trim((string) ($cat->getFormula() ?? ''));

            if (PLCategoryType::SUBTOTAL === $cat->getType() && '' === $formula) {
                foreach ($cat->getChildren() as $child) {
                    $g->addEdge((string) $child->getId(), $catId);
                }
                continue;
            }

            if (PLCategoryType::KPI === $cat->getType() || (PLCategoryType::SUBTOTAL === $cat->getType() && '' !== $formula)) {
                try {
                    $tokens = $this->tokenizer->tokenize($formula);
                    $ast = $this->parser->parse($tokens);
                    $astById[$catId] = $ast;
                    foreach ($this->deps->extract($ast) as $code) {
                        if (PlFormulaVariables::isVariable($code)) {
                            $variableUses[] = [strtoupper($code), $cat->getName()];
                            if (PLValueFormat::MONEY === $cat->getFormat()) {
                                $moneyVariableIds[] = $catId;
                            }
                            continue;
                        }
                        $depCat = $this->findByCode($all, $code);
                        if ($depCat) {
                            $g->addEdge((string) $depCat->getId(), $catId);
                        } else {
                            $warnings[] = "Unknown code `$code` in formula of `{$cat->getName()}`";
                        }
                    }
                } catch (\Throwable $e) {
                    $warnings[] = "Formula error in `{$cat->getName()}`: ".$e->getMessage();
                }
            }
        }

        $order = $this->topo->sort($g, $byId);

        // Профиль читается, только если переменная есть хотя бы в одной формуле.
        $variableValues = [] === $variableUses ? [] : ($this->variables?->values((string) $company->getId(), $period) ?? []);
        foreach ($variableUses as [$variable, $categoryName]) {
            if (null === ($variableValues[$variable] ?? null)) {
                $warnings[] = "Переменная $variable в формуле «{$categoryName}»: ставка не задана в налоговом профиле компании, принято 0";
            }
        }

        $monthlySums = $this->sumByMonthsIfRateChanges($company, $period, $moneyVariableIds, $projectDirection, $responsibilityCenterId, $warnings);

        $values = [];
        $env = new class($values, $all, $company, $period, $projectDirection, $responsibilityCenterId, $this->facts, $warnings, $variableValues) implements \App\Finance\Formula\Env {
            /**
             * @param array<string, float> $values
             * @param list<PLCategory> $all
             * @param ProjectDirection|list<ProjectDirection>|null $projectDirection
             * @param string|list<string>|null $responsibilityCenterId
             * @param list<string> $warnings
             * @param array<string, ?float> $variableValues
             */
            public function __construct(
                public array &$values,
                private array $all,
                private Company $company,
                private PlReportPeriod $period,
                private ProjectDirection|array|null $projectDirection,
                private string|array|null $responsibilityCenterId,
                private FactsProviderInterface $facts,
                private array &$warnings, // @phpstan-ignore property.onlyWritten (ссылка на список предупреждений расчёта)
                private array $variableValues,
            ) {
            }

            public function get(string $code): float
            {
                if (PlFormulaVariables::isVariable($code)) {
                    return (float) ($this->variableValues[strtoupper($code)] ?? 0.0);
                }
                foreach ($this->all as $c) {
                    if ($c->getCode() && strtoupper($c->getCode()) === strtoupper($code)) {
                        $v = $this->values[$c->getId()] ?? $this->facts->value(
                            $this->company,
                            $this->period,
                            $code,
                            $this->projectDirection,
                            $this->responsibilityCenterId,
                        );

                        return (float) $v;
                    }
                }
                $this->warn("Unknown code `$code` at eval-time");

                return 0.0;
            }

            public function warn(string $message): void
            {
                $this->warnings[] = $message;
            }
        };

        foreach ($order as $id) {
            /** @var PLCategory $c */
            $c = $byId[$id];
            if (isset($monthlySums[$id])) {
                $values[$id] = $monthlySums[$id];
                continue;
            }
            $t = $c->getType();
            $val = 0.0;

            if (PLCategoryType::LEAF_INPUT === $t) {
                $code = (string) $c->getCode();
                $val = $this->facts->value($company, $period, $code, $projectDirection, $responsibilityCenterId);
            } elseif (PLCategoryType::SUBTOTAL === $t) {
                $formula = trim((string) ($c->getFormula() ?? ''));
                if ('' === $formula) {
                    foreach ($c->getChildren() as $child) {
                        $childVal = $values[$child->getId()] ?? 0.0;
                        $val += $childVal * (float) $child->getWeightInParent();
                    }
                } else {
                    $ast = $astById[$id] ?? null;
                    if ($ast) {
                        $val = $this->evaluator->eval($ast, $env);
                    }
                }
            } elseif (PLCategoryType::KPI === $t) {
                $ast = $astById[$id] ?? null;
                if ($ast) {
                    $val = $this->evaluator->eval($ast, $env);
                }
            }

            $values[$id] = $val;
        }

        // Depth comes from the walked tree, not from the stored level: viewers
        // mark a row as a group when the next row is deeper, and a stale level
        // would turn a leaf into a fake group. Parents precede children here.
        $depthById = [];
        $rows = [];
        foreach ($displayOrder as $c) {
            $parent = $c->getParent();
            $depthById[$c->getId()] = null === $parent ? 1 : ($depthById[$parent->getId()] ?? 0) + 1;
            $rows[] = new PlComputedRow(
                id: (string) $c->getId(),
                code: $c->getCode(),
                name: $c->getName(),
                level: $depthById[$c->getId()],
                type: $c->getType()->value,
                format: $c->getFormat(),
                rawValue: (float) ($values[$c->getId()] ?? 0.0),
                formatted: $this->fmt->format((float) ($values[$c->getId()] ?? 0.0), $c->getFormat())
            );
        }

        return new PlReportResult($period, $rows, array_values(array_unique($warnings)));
    }

    /**
     * Ставка менялась внутри периода: денежные статьи с переменной ставки считаются помесячно и
     * складываются — итог периода совпадает с суммой месячных колонок. Профиль меняется с 1-го числа,
     * поэтому месяц — точная единица. Проценты и коэффициенты не складываются: они остаются по ставке
     * конца периода. Расчёт на заранее посчитанных фактах (withFacts — предпросмотр, бюджет) не
     * раскладывается: сумма колонки одна на любой период, там действует ставка конца периода.
     *
     * @param list<string> $categoryIds
     * @param ProjectDirection|list<ProjectDirection>|null $projectDirection
     * @param string|list<string>|null $responsibilityCenterId
     * @param list<string> $warnings
     *
     * @return array<string, float> сумма по id статьи; пусто — ставка в периоде одна
     */
    private function sumByMonthsIfRateChanges(
        Company $company,
        PlReportPeriod $period,
        array $categoryIds,
        ProjectDirection|array|null $projectDirection,
        string|array|null $responsibilityCenterId,
        array &$warnings,
    ): array {
        if (null === $this->variables || !$this->factsFollowPeriod || [] === $categoryIds) {
            return [];
        }

        $months = [];
        for ($start = $period->from->modify('first day of this month'); $start <= $period->to; $start = $start->modify('first day of next month')) {
            $months[] = PlReportPeriod::forRange(max($start, $period->from), min($start->modify('last day of this month'), $period->to));
        }
        if (\count($months) < 2) {
            return [];
        }

        // ponytail: профиль читается на каждый месяц периода; кэшировать периоды профиля,
        // если отчёты за многолетние диапазоны станут медленными.
        $companyId = (string) $company->getId();
        $rates = array_map(fn (PlReportPeriod $month): array => $this->variables->values($companyId, $month), $months);
        if (1 === \count(array_unique($rates, \SORT_REGULAR))) {
            return [];
        }

        $sums = array_fill_keys($categoryIds, 0.0);
        foreach ($months as $month) {
            $result = $this->calculate($company, $month, $projectDirection, $responsibilityCenterId);
            foreach ($result->rows as $row) {
                if (isset($sums[$row->id])) {
                    $sums[$row->id] += $row->rawValue;
                }
            }
            array_push($warnings, ...$result->warnings);
        }

        return $sums;
    }

    /**
     * @param list<PLCategory> $all
     *
     * @return list<PLCategory>
     */
    private function orderByTree(array $all): array
    {
        $childrenByParent = [];
        $roots = [];

        foreach ($all as $c) {
            $parent = $c->getParent();
            if (null === $parent) {
                $roots[] = $c;
            } else {
                $childrenByParent[$parent->getId()][] = $c;
            }
        }

        $bySort = static function (PLCategory $a, PLCategory $b): int {
            return $a->getSortOrder() <=> $b->getSortOrder();
        };

        usort($roots, $bySort);
        foreach ($childrenByParent as $pid => $list) {
            usort($childrenByParent[$pid], $bySort);
        }

        $out = [];
        $walk = static function (PLCategory $node) use (&$walk, &$out, $childrenByParent): void {
            $out[] = $node;
            foreach ($childrenByParent[$node->getId()] ?? [] as $ch) {
                $walk($ch);
            }
        };

        foreach ($roots as $r) {
            $walk($r);
        }

        return $out;
    }

    /**
     * @param list<PLCategory> $all
     */
    private function findByCode(array $all, string $code): ?PLCategory
    {
        foreach ($all as $c) {
            if ($c->getCode() && strtoupper($c->getCode()) === strtoupper($code)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Тот же калькулятор с другим источником фактов статей ввода — для предпросмотра ОПиУ, где
     * факты посчитаны заранее и регистр не читается.
     */
    public function withFacts(FactsProviderInterface $facts): self
    {
        return new self($this->categories, $facts, $this->tokenizer, $this->parser, $this->evaluator, $this->deps, $this->topo, $this->fmt, $this->variables, false);
    }
}

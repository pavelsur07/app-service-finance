<?php
declare(strict_types=1);

namespace App\Finance\Report;

use App\Entity\Company;
use App\Entity\PLCategory;
use App\Enum\PLCategoryType;
use App\Finance\Engine\{DependencyExtractor, Graph, TopoSort, ValueFormatter};
use App\Finance\Formula\{Evaluator, Parser, Tokenizer};
use App\Finance\Facts\FactsProviderInterface;
use App\Repository\PLCategoryRepository;

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
    ) {}

    public function calculate(Company $company, \DateTimeInterface $period): PlReportResult
    {
        // получаем плоский список (или дерево) категорий компании
        $all = $this->categories->findBy(['company' => $company], ['parent' => 'ASC', 'sortOrder' => 'ASC']);
        /** @var array<string,PLCategory> $byId */
        $byId = [];
        foreach ($all as $c) $byId[$c->getId()] = $c;

        // подготовка: формулы -> AST, зависимости -> граф
        $astById = [];
        $g = new Graph();
        $warnings = [];

        foreach ($all as $cat) {
            $g->addNode($cat->getId());
            $formula = trim((string)($cat->getFormula() ?? ''));

            if ($cat->getType() === PLCategoryType::SUBTOTAL && $formula === '') {
                // зависимость от прямых детей
                foreach ($cat->getChildren() as $child) {
                    $g->addEdge($child->getId(), $cat->getId());
                }
                continue;
            }
            if ($cat->getType() === PLCategoryType::KPI || ($cat->getType() === PLCategoryType::SUBTOTAL && $formula !== '')) {
                try {
                    $tokens = $this->tokenizer->tokenize($formula);
                    $ast = $this->parser->parse($tokens);
                    $astById[$cat->getId()] = $ast;
                    foreach ($this->deps->extract($ast) as $code) {
                        // найдём категорию по code
                        $depCat = $this->findByCode($all, $code);
                        if ($depCat) $g->addEdge($depCat->getId(), $cat->getId());
                        else $warnings[] = "Unknown code `$code` in formula of `{$cat->getName()}`";
                    }
                } catch (\Throwable $e) {
                    $warnings[] = "Formula error in `{$cat->getName()}`: ".$e->getMessage();
                }
            }
        }

        // порядок вычислений
        $order = $this->topo->sort($g, $byId);

        // окружение для Evaluator
        $values = []; // id => float
        $env = new class($values, $all, $company, $period, $this->facts, $warnings) implements \App\Finance\Formula\Env {
            public function __construct(
                public array &$values,
                private array $all,
                private Company $company,
                private \DateTimeInterface $period,
                private FactsProviderInterface $facts,
                private array &$warnings
            ) {}
            public function get(string $code): float {
                foreach ($this->all as $c) {
                    if ($c->getCode() && strtoupper($c->getCode()) === strtoupper($code)) {
                        $v = $this->values[$c->getId()] ?? $this->facts->value($this->company, $this->period, $code);
                        return (float)$v;
                    }
                }
                $this->warn("Unknown code `$code` at eval-time");
                return 0.0;
            }
            public function warn(string $message): void { $this->warnings[] = $message; }
        };

        // расчёт
        foreach ($order as $id) {
            /** @var PLCategory $c */
            $c = $byId[$id];
            $t = $c->getType();
            $val = 0.0;

            if ($t === PLCategoryType::LEAF_INPUT) {
                $code = (string)$c->getCode();
                $val = $this->facts->value($company, $period, $code);
            } elseif ($t === PLCategoryType::SUBTOTAL) {
                $formula = trim((string)($c->getFormula() ?? ''));
                if ($formula === '') {
                    foreach ($c->getChildren() as $child) {
                        $childVal = $values[$child->getId()] ?? 0.0;
                        $val += $childVal * (float)$child->getWeightInParent();
                    }
                } else {
                    $ast = $astById[$id] ?? null;
                    if ($ast) $val = $this->evaluator->eval($ast, $env);
                }
            } elseif ($t === PLCategoryType::KPI) {
                $ast = $astById[$id] ?? null;
                if ($ast) $val = $this->evaluator->eval($ast, $env);
            }

            $values[$id] = $val;
        }

        // форматирование и сбор строк
        $rows = [];
        foreach ($all as $c) {
            $rows[] = new PlComputedRow(
                id: $c->getId(),
                code: $c->getCode(),
                name: $c->getName(),
                level: $c->getLevel(),
                type: $c->getType()->value,
                rawValue: (float)($values[$c->getId()] ?? 0.0),
                formatted: $this->fmt->format((float)($values[$c->getId()] ?? 0.0), $c->getFormat())
            );
        }

        return new PlReportResult($rows, array_values(array_unique($warnings)));
    }

    /** @param PLCategory[] $all */
    private function findByCode(array $all, string $code): ?PLCategory
    {
        foreach ($all as $c) if ($c->getCode() && strtoupper($c->getCode()) === strtoupper($code)) return $c;
        return null;
    }
}

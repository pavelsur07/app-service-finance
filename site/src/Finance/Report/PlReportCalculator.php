<?php

declare(strict_types=1);

namespace App\Finance\Report;

use App\Company\Entity\ProjectDirection;
use App\Entity\Company;
use App\Entity\PLCategory;
use App\Enum\PLCategoryType;
use App\Finance\Engine\DependencyExtractor;
use App\Finance\Engine\Graph;
use App\Finance\Engine\TopoSort;
use App\Finance\Engine\ValueFormatter;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Formula\Evaluator;
use App\Finance\Formula\Parser;
use App\Finance\Formula\Tokenizer;
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
    ) {
    }

    public function supportsProjectDimension(): bool
    {
        return true;
    }

    public function calculate(Company $company, PlReportPeriod $period, ?ProjectDirection $projectDirection = null): PlReportResult
    {
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

        foreach ($all as $cat) {
            $g->addNode($cat->getId());
            $formula = trim((string) ($cat->getFormula() ?? ''));

            if (PLCategoryType::SUBTOTAL === $cat->getType() && '' === $formula) {
                foreach ($cat->getChildren() as $child) {
                    $g->addEdge($child->getId(), $cat->getId());
                }
                continue;
            }

            if (PLCategoryType::KPI === $cat->getType() || (PLCategoryType::SUBTOTAL === $cat->getType() && '' !== $formula)) {
                try {
                    $tokens = $this->tokenizer->tokenize($formula);
                    $ast = $this->parser->parse($tokens);
                    $astById[$cat->getId()] = $ast;
                    foreach ($this->deps->extract($ast) as $code) {
                        $depCat = $this->findByCode($all, $code);
                        if ($depCat) {
                            $g->addEdge($depCat->getId(), $cat->getId());
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

        $values = [];
        $env = new class($values, $all, $company, $period, $projectDirection, $this->facts, $warnings) implements \App\Finance\Formula\Env {
            public function __construct(
                public array &$values,
                private array $all,
                private Company $company,
                private PlReportPeriod $period,
                private ?ProjectDirection $projectDirection,
                private FactsProviderInterface $facts,
                private array &$warnings,
            ) {
            }

            public function get(string $code): float
            {
                foreach ($this->all as $c) {
                    if ($c->getCode() && strtoupper($c->getCode()) === strtoupper($code)) {
                        $v = $this->values[$c->getId()] ?? $this->facts->value($this->company, $this->period, $code, $this->projectDirection);

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
            $t = $c->getType();
            $val = 0.0;

            if (PLCategoryType::LEAF_INPUT === $t) {
                $code = (string) $c->getCode();
                $val = $this->facts->value($company, $period, $code, $projectDirection);
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

        $rows = [];
        foreach ($displayOrder as $c) {
            $rows[] = new PlComputedRow(
                id: $c->getId(),
                code: $c->getCode(),
                name: $c->getName(),
                level: $c->getLevel(),
                type: $c->getType()->value,
                rawValue: (float) ($values[$c->getId()] ?? 0.0),
                formatted: $this->fmt->format((float) ($values[$c->getId()] ?? 0.0), $c->getFormat())
            );
        }

        return new PlReportResult($period, $rows, array_values(array_unique($warnings)));
    }

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

        $bySort = function (PLCategory $a, PLCategory $b): int {
            return $a->getSortOrder() <=> $b->getSortOrder();
        };

        usort($roots, $bySort);
        foreach ($childrenByParent as $pid => $list) {
            usort($childrenByParent[$pid], $bySort);
        }

        $out = [];
        $walk = function (PLCategory $node) use (&$walk, &$out, $childrenByParent): void {
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

    private function findByCode(array $all, string $code): ?PLCategory
    {
        foreach ($all as $c) {
            if ($c->getCode() && strtoupper($c->getCode()) === strtoupper($code)) {
                return $c;
            }
        }

        return null;
    }
}

<?php
declare(strict_types=1);

namespace App\Finance\Formula;

interface Env { public function get(string $code): float; public function warn(string $message): void; }

final class Evaluator
{
    public function eval(Node $node, Env $env): float
    {
        if ($node instanceof NumberNode) return $node->value;
        if ($node instanceof RefNode)   return $env->get($node->code);

        if ($node instanceof UnaryNode) {
            $v = $this->eval($node->expr, $env);
            return match($node->op) { '-' => -$v, default => throw new \RuntimeException('Bad unary') };
        }
        if ($node instanceof BinaryNode) {
            $l = $this->eval($node->left, $env);
            $r = $this->eval($node->right,$env);
            return match($node->op) {
                '+' => $l + $r,
                '-' => $l - $r,
                '*' => $l * $r,
                '/' => ($r==0.0 ? 0.0 : $l / $r),
                '>' => (float)($l >  $r),
                '<' => (float)($l <  $r),
                '>='=> (float)($l >= $r),
                '<='=> (float)($l <= $r),
                '==' => (float)($l == $r),
                '!=' => (float)($l != $r),
                default => throw new \RuntimeException('Bad binary')
            };
        }
        if ($node instanceof FuncCallNode) {
            $name = strtoupper($node->name);
            if ($name === 'SUM') {
                $args = array_map(fn($n)=>$this->eval($n,$env), $node->args);
                return Functions::sum(...$args);
            }
            if ($name === 'SAFE_DIV') {
                $a = $this->eval($node->args[0] ?? new NumberNode(0), $env);
                $b = $this->eval($node->args[1] ?? new NumberNode(0), $env);
                return Functions::safeDiv($a,$b);
            }
            if ($name === 'IF') {
                $cond = (bool)$this->eval($node->args[0] ?? new NumberNode(0), $env);
                $a = $this->eval($node->args[1] ?? new NumberNode(0), $env);
                $b = $this->eval($node->args[2] ?? new NumberNode(0), $env);
                return Functions::ifCond($cond,$a,$b);
            }
            $env->warn("Unknown function $name()");
            return 0.0;
        }
        throw new \RuntimeException('Unknown AST node');
    }
}

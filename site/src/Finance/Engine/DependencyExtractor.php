<?php

declare(strict_types=1);

namespace App\Finance\Engine;

use App\Finance\Formula\BinaryNode;
use App\Finance\Formula\FuncCallNode;
use App\Finance\Formula\Node;
use App\Finance\Formula\RefNode;
use App\Finance\Formula\UnaryNode;

final class DependencyExtractor
{
    /** @return string[] */
    public function extract(Node $node): array
    {
        $out = [];
        $this->walk($node, $out);

        return array_values(array_unique($out));
    }

    private function walk(Node $n, array &$out): void
    {
        if ($n instanceof RefNode) {
            $out[] = $n->code;

            return;
        }
        if ($n instanceof UnaryNode) {
            $this->walk($n->expr, $out);

            return;
        }
        if ($n instanceof BinaryNode) {
            $this->walk($n->left, $out);
            $this->walk($n->right, $out);

            return;
        }
        if ($n instanceof FuncCallNode) {
            foreach ($n->args as $a) {
                $this->walk($a, $out);
            }
        }
    }
}

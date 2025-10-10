<?php
declare(strict_types=1);

namespace App\Finance\Engine;

final class Graph
{
    /** @var array<string, array<string,bool>> */
    private array $edges = [];
    /** @var array<string,bool> */
    private array $nodes = [];

    public function addNode(string $id): void { $this->nodes[$id] = true; $this->edges[$id] ??= []; }
    public function addEdge(string $from, string $to): void { $this->addNode($from); $this->addNode($to); $this->edges[$from][$to] = true; }

    /** @return string[] */
    public function topoSort(?callable $priority = null): array
    {
        // Kahn
        $in = array_fill_keys(array_keys($this->nodes), 0);
        foreach ($this->edges as $u => $vs) foreach ($vs as $v => $_) $in[$v]++;
        $q = [];
        foreach ($in as $v => $deg) if ($deg === 0) $q[] = $v;
        if ($priority) usort($q, $priority);

        $order = [];
        while ($q) {
            $u = array_shift($q); $order[] = $u;
            foreach (array_keys($this->edges[$u] ?? []) as $v) {
                if (--$in[$v] === 0) { $q[] = $v; if ($priority) usort($q, $priority); }
            }
        }
        if (count($order) !== count($this->nodes)) {
            // цикл — вернём узлы с ненулевой степенью
            $cycle = [];
            foreach ($in as $v => $deg) if ($deg > 0) $cycle[] = $v;
            throw new \RuntimeException('Cyclic dependency: '.implode(' -> ', $cycle));
        }
        return $order;
    }
}

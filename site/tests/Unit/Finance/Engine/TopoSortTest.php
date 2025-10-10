<?php
declare(strict_types=1);

namespace Tests\Unit\Finance\Engine;

use App\Finance\Engine\{Graph, TopoSort};
use PHPUnit\Framework\TestCase;

final class TopoSortTest extends TestCase
{
    public function testTopo(): void
    {
        $g = new Graph();
        $g->addEdge('A','C');
        $g->addEdge('B','C');
        $order = $g->topoSort();
        $this->assertTrue(array_search('C',$order) > array_search('A',$order));
        $this->assertTrue(array_search('C',$order) > array_search('B',$order));
    }

    public function testCycle(): void
    {
        $this->expectException(\RuntimeException::class);
        $g = new Graph();
        $g->addEdge('A','B'); $g->addEdge('B','A');
        $g->topoSort();
    }
}

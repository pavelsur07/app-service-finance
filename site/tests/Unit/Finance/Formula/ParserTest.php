<?php
declare(strict_types=1);

namespace Tests\Unit\Finance\Formula;

use App\Finance\Formula\{Parser, Tokenizer, BinaryNode, FuncCallNode, RefNode};
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testBasicArithmetic(): void
    {
        $p = new Parser(); $t = new Tokenizer();
        $ast = $p->parse($t->tokenize('A + B*2'));
        $this->assertInstanceOf(BinaryNode::class, $ast);
    }

    public function testFunctions(): void
    {
        $p = new Parser(); $t = new Tokenizer();
        $ast = $p->parse($t->tokenize('SAFE_DIV(SUM(A,B), NET)'));
        $this->assertInstanceOf(FuncCallNode::class, $ast);
    }
}

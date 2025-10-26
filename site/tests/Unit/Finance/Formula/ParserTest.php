<?php

declare(strict_types=1);

namespace Tests\Unit\Finance\Formula;

use App\Finance\Formula\BinaryNode;
use App\Finance\Formula\FuncCallNode;
use App\Finance\Formula\Parser;
use App\Finance\Formula\Tokenizer;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testBasicArithmetic(): void
    {
        $p = new Parser();
        $t = new Tokenizer();
        $ast = $p->parse($t->tokenize('A + B*2'));
        $this->assertInstanceOf(BinaryNode::class, $ast);
    }

    public function testFunctions(): void
    {
        $p = new Parser();
        $t = new Tokenizer();
        $ast = $p->parse($t->tokenize('SAFE_DIV(SUM(A,B), NET)'));
        $this->assertInstanceOf(FuncCallNode::class, $ast);
    }
}

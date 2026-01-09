<?php

declare(strict_types=1);

namespace App\Finance\Formula;

final class Parser
{
    /** @param Token[] $tokens */
    public function parse(array $tokens): Node
    {
        // Рекурсивный спуск: expr -> term (+/-) term; поддержка IF(...), SUM(...), SAFE_DIV(...)
        $this->i = 0;
        $this->t = $tokens;
        $node = $this->parseExpr();
        if ($this->peek()) {
            throw new \InvalidArgumentException('Trailing tokens in formula');
        }

        return $node;
    }

    /** @var Token[] */ private array $t = [];
    private int $i = 0;

    private function peek(): ?Token
    {
        return $this->t[$this->i] ?? null;
    }

    private function take(): ?Token
    {
        return $this->t[$this->i++] ?? null;
    }

    private function parseExpr(): Node
    {
        $node = $this->parseCmp();

        return $node;
    }

    private function parseCmp(): Node
    {
        $node = $this->parseAdd();
        while (($p = $this->peek()) && TokenType::OP === $p->type && in_array($p->lexeme, ['>', '<', '>=', '<=', '==', '!='])) {
            $op = $this->take()->lexeme;
            $rhs = $this->parseAdd();
            $node = new BinaryNode($op, $node, $rhs);
        }

        return $node;
    }

    private function parseAdd(): Node
    {
        $node = $this->parseMul();
        while (($p = $this->peek()) && TokenType::OP === $p->type && in_array($p->lexeme, ['+', '-'])) {
            $op = $this->take()->lexeme;
            $rhs = $this->parseMul();
            $node = new BinaryNode($op, $node, $rhs);
        }

        return $node;
    }

    private function parseMul(): Node
    {
        $node = $this->parseUnary();
        while (($p = $this->peek()) && TokenType::OP === $p->type && in_array($p->lexeme, ['*', '/'])) {
            $op = $this->take()->lexeme;
            $rhs = $this->parseUnary();
            $node = new BinaryNode($op, $node, $rhs);
        }

        return $node;
    }

    private function parseUnary(): Node
    {
        $p = $this->peek();
        if ($p && TokenType::OP === $p->type && '-' === $p->lexeme) {
            $this->take();

            return new UnaryNode('-', $this->parseUnary());
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): Node
    {
        $p = $this->take();
        if (!$p) {
            throw new \InvalidArgumentException('Unexpected end of formula');
        }

        if (TokenType::NUMBER === $p->type) {
            return new NumberNode((float) $p->lexeme);
        }

        if (TokenType::IDENT === $p->type) {
            $name = $p->lexeme;
            $next = $this->peek();
            if ($next && TokenType::LPAREN === $next->type) {
                $this->take(); // (
                $args = [];
                if (($n = $this->peek()) && TokenType::RPAREN !== $n->type) {
                    $args[] = $this->parseExpr();
                    while (($c = $this->peek()) && TokenType::COMMA === $c->type) {
                        $this->take();
                        $args[] = $this->parseExpr();
                    }
                }
                $r = $this->take();
                if (!$r || TokenType::RPAREN !== $r->type) {
                    throw new \InvalidArgumentException("Missing ) after $name(");
                }

                return new FuncCallNode($name, $args);
            }

            return new RefNode($name);
        }

        if (TokenType::LPAREN === $p->type) {
            $node = $this->parseExpr();
            $r = $this->take();
            if (!$r || TokenType::RPAREN !== $r->type) {
                throw new \InvalidArgumentException('Missing )');
            }

            return $node;
        }

        throw new \InvalidArgumentException('Unexpected token in formula');
    }
}

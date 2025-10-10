<?php
declare(strict_types=1);

namespace App\Finance\Formula;

enum TokenType { case NUMBER; case IDENT; case OP; case LPAREN; case RPAREN; case COMMA; }

final class Token {
    public function __construct(
        public readonly TokenType $type,
        public readonly string $lexeme
    ) {}
}

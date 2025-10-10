<?php
declare(strict_types=1);

namespace App\Finance\Formula;

final class Tokenizer
{
    /** @return Token[] */
    public function tokenize(string $src): array
    {
        $s = trim($src);
        $n = strlen($s);
        $i = 0; $out = [];
        while ($i < $n) {
            $ch = $s[$i];
            if (ctype_space($ch)) { $i++; continue; }
            if (ctype_digit($ch) || ($ch === '.' && $i+1 < $n && ctype_digit($s[$i+1]))) {
                $j = $i+1;
                while ($j < $n && (ctype_digit($s[$j]) || $s[$j] === '.')) $j++;
                $out[] = new Token(TokenType::NUMBER, substr($s, $i, $j-$i)); $i = $j; continue;
            }
            if (ctype_alpha($ch) || $ch === '_') {
                $j = $i+1;
                while ($j < $n && (ctype_alnum($s[$j]) || $s[$j] === '_')) $j++;
                $out[] = new Token(TokenType::IDENT, strtoupper(substr($s, $i, $j-$i))); $i = $j; continue;
            }
            if (in_array($ch, ['+','-','*','/','>','<','=','!'])) {
                $two = ($i+1 < $n ? $s[$i].$s[$i+1] : '');
                if (in_array($two, ['>=','<=','==','!='])) { $out[] = new Token(TokenType::OP, $two); $i+=2; continue; }
                $out[] = new Token(TokenType::OP, $ch); $i++; continue;
            }
            if ($ch === '(') { $out[] = new Token(TokenType::LPAREN, $ch); $i++; continue; }
            if ($ch === ')') { $out[] = new Token(TokenType::RPAREN, $ch); $i++; continue; }
            if ($ch === ',') { $out[] = new Token(TokenType::COMMA, $ch); $i++; continue; }
            throw new \InvalidArgumentException("Unknown char `$ch` in formula");
        }
        return $out;
    }
}

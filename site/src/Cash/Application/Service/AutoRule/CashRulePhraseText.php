<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\AutoRule;

use App\Cash\Application\DTO\CashRulePhraseExample;
use App\Util\StringNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Словарь подстановок для неизвестных слов восстанавливается по истории компании только в памяти. */
final readonly class CashRulePhraseText
{
    private const VOCABULARY = 'оплата платеж платежи за по и в на от без с договору договора счету счета услуги услуг аренду аренды аренда лицензии лицензию лицензий лицензия внедрение внедрения работы работ поставку товара товары товар обеспечение обеспечительный возврат аванс предоплата обслуживание подписку подписки абонентская плата транспортные доставка доставку программного обеспечения';

    public function __construct(#[Autowire('%kernel.secret%')] private string $secret)
    {
    }

    public function encode(string $text, string $scope): string
    {
        return implode(' ', array_column($this->tokens($text, $scope), 0));
    }

    /** @param iterable<CashRulePhraseExample> $history */
    public function decode(string $encoded, string $scope, iterable $history): ?string
    {
        $words = explode(' ', $encoded);
        if (\count($words) > 8 || '' === $encoded || \in_array('#', $words, true)) {
            return null;
        }
        foreach ($history as $row) {
            $tokens = $this->tokens($row->description, $scope);
            if (!str_contains(' '.implode(' ', array_column($tokens, 0)).' ', ' '.$encoded.' ')) {
                continue;
            }
            $dictionary = array_column($tokens, 1, 0);
            $phrase = implode(' ', array_map(static fn (string $word): string => $dictionary[$word], $words));
            if (mb_strlen($phrase) <= 255 && StringNormalizer::contains($row->description, $phrase)) {
                return $phrase;
            }
        }

        return null;
    }

    /** @return list<array{string, string}> */
    private function tokens(string $text, string $scope): array
    {
        preg_match_all('/[\p{L}]+|[^\p{L}\s]+/u', StringNormalizer::normalize(mb_substr($text, 0, 400)), $matches);
        $tokens = [];
        foreach (\array_slice($matches[0], 0, 40) as $word) {
            $token = match (true) {
                1 !== preg_match('/^\p{L}+$/u', $word) => '#',
                str_contains(' '.self::VOCABULARY.' ', ' '.$word.' ') => $word,
                default => 'w'.substr(hash_hmac('sha256', $scope.'/'.$word, $this->secret), 0, 16),
            };
            $tokens[] = [$token, $word];
        }

        return $tokens;
    }
}

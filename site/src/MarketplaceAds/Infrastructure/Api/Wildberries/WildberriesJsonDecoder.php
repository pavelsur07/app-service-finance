<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Wildberries;

/**
 * Decodes JSON while preserving every JSON number token as its exact string.
 *
 * json_decode() converts decimal tokens to float, which is not acceptable at a
 * financial API boundary. Quoting number tokens before decoding preserves the
 * original lexeme and still delegates JSON validation to PHP.
 */
final class WildberriesJsonDecoder
{
    /**
     * @return list<array<string, mixed>>
     */
    public function decodeObjectList(string $json): array
    {
        $decoded = json_decode($this->quoteNumbers($json), true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \UnexpectedValueException('Expected a JSON list.');
        }

        foreach ($decoded as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \UnexpectedValueException('Expected every JSON list item to be an object.');
            }
        }

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    private function quoteNumbers(string $json): string
    {
        $length = strlen($json);
        $result = '';
        $inString = false;
        $escaped = false;

        for ($index = 0; $index < $length; ++$index) {
            $char = $json[$index];

            if ($inString) {
                $result .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $char) {
                    $escaped = true;
                } elseif ('"' === $char) {
                    $inString = false;
                }

                continue;
            }

            if ('"' === $char) {
                $inString = true;
                $result .= $char;

                continue;
            }

            if ('-' !== $char && !ctype_digit($char)) {
                $result .= $char;

                continue;
            }

            $number = $this->numberAt($json, $index);
            $result .= '"'.$number.'"';
            $end = $index + strlen($number);
            $index = $end - 1;
        }

        return $result;
    }

    private function numberAt(string $json, int $start): string
    {
        if (1 !== preg_match(
            '/-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/A',
            $json,
            $matches,
            0,
            $start,
        )) {
            throw new \UnexpectedValueException('Invalid JSON number.');
        }

        $number = $matches[0];
        $next = $json[$start + strlen($number)] ?? null;
        if (null !== $next && !in_array($next, [',', ']', '}', ' ', "\t", "\r", "\n"], true)) {
            throw new \UnexpectedValueException('Invalid character after JSON number.');
        }

        return $number;
    }
}

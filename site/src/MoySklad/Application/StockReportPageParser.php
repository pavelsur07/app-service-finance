<?php

declare(strict_types=1);

namespace App\MoySklad\Application;

use App\MoySklad\Application\DTO\ParsedPage;
use App\MoySklad\Domain\StockAssortmentSnapshot;
use App\MoySklad\Domain\StockLevelSnapshot;
use App\MoySklad\Exception\StockSyncException;
use Ramsey\Uuid\Uuid;

final class StockReportPageParser
{
    /** @return ParsedPage<StockAssortmentSnapshot> */
    public function parse(string $json): ParsedPage
    {
        $decimalMarker = '__moysklad_decimal_'.bin2hex(random_bytes(16));
        try {
            $page = json_decode($this->preserveMetricLexemes($json, $decimalMarker), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new StockSyncException('invalid_response');
        }
        if (!is_array($page) || array_is_list($page)) {
            throw new StockSyncException('invalid_response');
        }
        $meta = $page['meta'] ?? null;
        $rows = $page['rows'] ?? null;
        $size = is_array($meta) ? ($meta['size'] ?? null) : null;
        $limit = is_array($meta) ? ($meta['limit'] ?? null) : null;
        $offset = is_array($meta) ? ($meta['offset'] ?? null) : null;
        if (!is_array($meta) || 'stockbystore' !== ($meta['type'] ?? null) || !is_array($rows) || !array_is_list($rows)
            || !is_int($size) || $size < 0 || !is_int($limit) || $limit < 1 || $limit > 1000
            || !is_int($offset) || $offset < 0 || $offset > $size
            || count($rows) !== max(0, min($limit, $size - $offset))) {
            throw new StockSyncException('invalid_response');
        }

        $snapshots = [];
        $seenAssortment = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new StockSyncException('invalid_response');
            }
            [$type, $externalId] = $this->identity($row['meta'] ?? null, ['product', 'variant']);
            $assortmentKey = $type.':'.$externalId;
            if (isset($seenAssortment[$assortmentKey])) {
                throw new StockSyncException('invalid_response');
            }
            $seenAssortment[$assortmentKey] = true;

            $rawLevels = $row['stockByStore'] ?? null;
            if (!is_array($rawLevels) || !array_is_list($rawLevels)) {
                throw new StockSyncException('invalid_response');
            }
            $levels = [];
            $seenStores = [];
            foreach ($rawLevels as $rawLevel) {
                if (!is_array($rawLevel)) {
                    throw new StockSyncException('invalid_response');
                }
                [, $storeExternalId] = $this->identity($rawLevel['meta'] ?? null, ['store']);
                if (isset($seenStores[$storeExternalId]) || !is_string($rawLevel['name'] ?? null) || '' === trim($rawLevel['name']) || mb_strlen(trim($rawLevel['name'])) > 255) {
                    throw new StockSyncException('invalid_response');
                }
                $seenStores[$storeExternalId] = true;
                $levels[] = new StockLevelSnapshot(
                    $storeExternalId,
                    $this->decimal($rawLevel['stock'] ?? null, $decimalMarker),
                    $this->decimal($rawLevel['reserve'] ?? null, $decimalMarker),
                    $this->decimal($rawLevel['inTransit'] ?? null, $decimalMarker),
                );
            }
            $snapshots[] = new StockAssortmentSnapshot($type, $externalId, $levels);
        }

        return new ParsedPage($size, $limit, $offset, $snapshots);
    }

    /** @param list<string> $allowedTypes
     * @return array{string, string}
     */
    private function identity(mixed $meta, array $allowedTypes): array
    {
        $type = is_array($meta) ? ($meta['type'] ?? null) : null;
        $href = is_array($meta) ? ($meta['href'] ?? null) : null;
        if (!is_string($type) || !in_array($type, $allowedTypes, true) || !is_string($href)
            || 1 !== preg_match('~^https://api\.moysklad\.ru/api/remap/1\.2/entity/([a-z]+)/([0-9a-fA-F-]{36})(?:\?[^#]*)?$~D', $href, $match)
            || $match[1] !== $type || !Uuid::isValid($match[2])) {
            throw new StockSyncException('invalid_response');
        }

        return [$type, strtolower($match[2])];
    }

    private function decimal(mixed $value, string $marker): string
    {
        if (!is_array($value) || [$marker] !== array_keys($value)
            || !is_string($value[$marker])) {
            throw new StockSyncException('invalid_response');
        }

        return $this->normalizeDecimalLexeme($value[$marker]);
    }

    private function normalizeDecimalLexeme(string $lexeme): string
    {
        if (1 !== preg_match('/^(?<sign>-?)(?<integer>0|[1-9]\d*)(?:\.(?<fraction>\d+))?(?:[eE](?<exponent>[+-]?\d+))?$/D', $lexeme, $match)) {
            throw new StockSyncException('invalid_response');
        }

        $fraction = $match['fraction'] ?? '';
        $digits = ltrim($match['integer'].$fraction, '0');
        if ('' === $digits) {
            return '0';
        }

        $rawExponent = $match['exponent'] ?? '0';
        $exponentMagnitude = ltrim(ltrim($rawExponent, '+-'), '0');
        if (strlen($exponentMagnitude) > 2) {
            throw new StockSyncException('invalid_response');
        }
        $scale = strlen($fraction) - (int) $rawExponent;
        while ($scale > 0 && str_ends_with($digits, '0')) {
            $digits = substr($digits, 0, -1);
            --$scale;
        }

        if ($scale <= 0) {
            $integerLength = strlen($digits) - $scale;
            if ($integerLength > 20) {
                throw new StockSyncException('invalid_response');
            }
            $normalized = $digits.str_repeat('0', -$scale);
        } else {
            $integerLength = max(0, strlen($digits) - $scale);
            if ($integerLength > 20 || $scale > 10) {
                throw new StockSyncException('invalid_response');
            }
            if (0 === $integerLength) {
                $normalized = '0.'.str_repeat('0', $scale - strlen($digits)).$digits;
            } else {
                $normalized = substr($digits, 0, $integerLength).'.'.substr($digits, $integerLength);
            }
        }

        return '-' === $match['sign'] ? '-'.$normalized : $normalized;
    }

    private function preserveMetricLexemes(string $json, string $marker): string
    {
        $result = '';
        $length = strlen($json);
        for ($offset = 0; $offset < $length;) {
            if ('"' !== $json[$offset]) {
                $result .= $json[$offset++];

                continue;
            }

            $start = $offset++;
            while ($offset < $length) {
                if ('\\' === $json[$offset]) {
                    $offset += 2;

                    continue;
                }
                if ('"' === $json[$offset++]) {
                    break;
                }
            }
            $token = substr($json, $start, $offset - $start);
            $result .= $token;
            if (!in_array($token, ['"stock"', '"reserve"', '"inTransit"'], true)) {
                continue;
            }

            $cursor = $offset;
            while ($cursor < $length && str_contains(" \t\r\n", $json[$cursor])) {
                ++$cursor;
            }
            if ($cursor >= $length || ':' !== $json[$cursor]) {
                continue;
            }
            ++$cursor;
            while ($cursor < $length && str_contains(" \t\r\n", $json[$cursor])) {
                ++$cursor;
            }
            if (1 !== preg_match('/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $json, $match, 0, $cursor)) {
                continue;
            }

            $result .= substr($json, $offset, $cursor - $offset);
            $result .= '{"'.$marker.'":"'.$match[0].'"}';
            $offset = $cursor + strlen($match[0]);
        }

        return $result;
    }
}

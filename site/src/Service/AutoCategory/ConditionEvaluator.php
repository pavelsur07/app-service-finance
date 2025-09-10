<?php

namespace App\Service\AutoCategory;

use App\Entity\AutoCategoryCondition;
use App\Enum\ConditionField;
use App\Enum\ConditionOperator;
use DateTimeImmutable;
use DateTimeInterface;

class ConditionEvaluator implements ConditionEvaluatorInterface
{
    public function isConditionMatched(array $operation, AutoCategoryCondition $condition): bool
    {
        $field = $condition->getField()->value;
        $value = $operation[$field] ?? null;
        $result = false;

        $operator = $condition->getOperator();
        $raw = $condition->getValue();
        $caseSensitive = $condition->isCaseSensitive();

        switch ($operator) {
            case ConditionOperator::EQUALS:
            case ConditionOperator::NOT_EQUALS:
                $result = $this->equals($value, $raw, $caseSensitive);
                if ($operator === ConditionOperator::NOT_EQUALS) {
                    $result = !$result;
                }
                break;
            case ConditionOperator::CONTAINS:
            case ConditionOperator::NOT_CONTAINS:
                $result = $this->contains($value, $raw, $caseSensitive);
                if ($operator === ConditionOperator::NOT_CONTAINS) {
                    $result = !$result;
                }
                break;
            case ConditionOperator::REGEX:
                $result = $this->regex($value, $raw, $caseSensitive);
                break;
            case ConditionOperator::BETWEEN:
                $result = $this->between($value, $raw, $condition->getField());
                break;
            case ConditionOperator::IN:
            case ConditionOperator::NOT_IN:
                $result = $this->in($value, $raw, $caseSensitive);
                if ($operator === ConditionOperator::NOT_IN) {
                    $result = !$result;
                }
                break;
            default:
                $result = false;
        }

        if ($condition->isNegate()) {
            $result = !$result;
        }

        return $result;
    }

    private function equals(mixed $value, string $raw, bool $caseSensitive): bool
    {
        if ($value === null) {
            return false;
        }
        if ($value instanceof DateTimeInterface) {
            try {
                $date = new DateTimeImmutable($raw);
            } catch (\Throwable) {
                return false;
            }
            return $value->format('Y-m-d') === $date->format('Y-m-d');
        }
        if (is_numeric($value) && is_numeric($raw)) {
            return (float)$value == (float)$raw;
        }
        $v1 = (string)$value;
        $v2 = $raw;
        if (!$caseSensitive) {
            $v1 = mb_strtolower($v1);
            $v2 = mb_strtolower($v2);
        }
        return $v1 === $v2;
    }

    private function contains(mixed $value, string $needle, bool $caseSensitive): bool
    {
        if (!is_string($value)) {
            return false;
        }
        if (!$caseSensitive) {
            $value = mb_strtolower($value);
            $needle = mb_strtolower($needle);
        }
        return str_contains($value, $needle);
    }

    private function regex(mixed $value, string $pattern, bool $caseSensitive): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $delim = '/';
        $mod = $caseSensitive ? '' : 'i';
        // Escape delimiter in pattern to build a valid regular expression.
        // Need to prefix delimiter with a single backslash. In single quoted
        // strings, we write "\\" to represent one backslash character.
        $regex = $delim . str_replace($delim, '\\' . $delim, $pattern) . $delim . $mod;
        $result = @preg_match($regex, $value);
        return $result === 1;
    }

    private function between(mixed $value, string $raw, ConditionField $field): bool
    {
        $parts = explode('..', $raw);
        if (count($parts) !== 2) {
            return false;
        }
        [$min, $max] = $parts;
        if ($field === ConditionField::DATE && $value instanceof DateTimeInterface) {
            try {
                $minD = new DateTimeImmutable($min);
                $maxD = new DateTimeImmutable($max);
            } catch (\Throwable) {
                return false;
            }
            return $value >= $minD && $value <= $maxD;
        }
        if (is_numeric($value)) {
            return (float)$value >= (float)$min && (float)$value <= (float)$max;
        }
        return false;
    }

    private function in(mixed $value, string $raw, bool $caseSensitive): bool
    {
        $list = json_decode($raw, true);
        if (!is_array($list)) {
            return false;
        }
        if (is_string($value) && !$caseSensitive) {
            $value = mb_strtolower($value);
            $list = array_map(fn($v) => is_string($v) ? mb_strtolower((string)$v) : $v, $list);
        }
        return in_array($value, $list, false);
    }
}

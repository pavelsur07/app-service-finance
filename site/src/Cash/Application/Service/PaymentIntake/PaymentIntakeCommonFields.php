<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\PaymentIntake;

/** Explicit batch instructions only; never infer money or the source of funds. */
final class PaymentIntakeCommonFields
{
    /** @return array{fields: array<string, string>, separate: bool, confirm_projects: bool} */
    public static function parse(string $text): array
    {
        $result = ['fields' => [], 'separate' => false, 'confirm_projects' => false];
        $text = preg_replace('/(все оплачены)\s+и\s+/iu', '$1;', trim($text)) ?? $text;
        foreach (preg_split('/[;\n]+/u', $text) ?: [] as $line) {
            $previous = $result['fields'];
            $line = trim($line, " \t\r:.");
            if ('' === $line) {
                continue;
            }
            if (preg_match('/^отдельные платежи$/iu', $line)) {
                $result['separate'] = true;
            } elseif (preg_match('/^проекты (?:ты выбрал|выбраны) верно$/iu', $line)) {
                $result['confirm_projects'] = true;
            } elseif (preg_match('/^(?:все )?оплачен[оы](?: со средств компании)?$/iu', $line)) {
                $result['fields']['execution_status'] = 'paid';
                if (str_contains(mb_strtolower($line), 'со средств компании')) {
                    $result['fields']['paid_confirmed'] = 'yes';
                }
            } elseif (preg_match('/^(?:(?:для всех|всем|у всех)\s+)?дата(?: оплаты| всех платежей(?: ниже)?)?\s*:?\s*(\S+)(?: на все платежи(?: этой итерации)?)?$/iu', $line, $match)) {
                $result['fields']['date'] = self::date($match[1]);
            } elseif (preg_match('/^(?:расход|приход) по проекту\s+(.+)$/iu', $line, $match)) {
                $result['fields']['project_id'] = trim($match[1]);
                $result['fields']['direction'] = str_starts_with(mb_strtolower($line), 'расход') ? 'outflow' : 'inflow';
            } elseif (preg_match('/^(?:(?:для всех|всем)\s+)?(проект|цфо|направление|валюта)\s*:?\s*(.+)$/iu', $line, $match)) {
                $field = match (mb_strtolower($match[1])) {
                    'проект' => 'project_id', 'цфо' => 'cfo_id', 'валюта' => 'currency', default => 'direction',
                };
                $value = trim($match[2]);
                if ('direction' === $field) {
                    $value = match (mb_strtolower($value)) {
                        'приход' => 'inflow', 'расход' => 'outflow', default => throw new \DomainException('Укажите направление приход или расход.'),
                    };
                }
                if ('currency' === $field) {
                    $value = strtoupper($value);
                    if (1 !== preg_match('/^[A-Z]{3}$/D', $value)) {
                        throw new \DomainException('Укажите валюту тремя буквами.');
                    }
                }
                if (isset($result['fields'][$field]) && $result['fields'][$field] !== $value) {
                    throw new \DomainException('Общие указания противоречат друг другу.');
                }
                $result['fields'][$field] = $value;
            } else {
                throw new \DomainException('Общее уточнение не распознано. Пример: «дата 14.10.2026; проект Название; все оплачены». Изменения не применены.');
            }
            foreach ($previous as $field => $value) {
                if (($result['fields'][$field] ?? null) !== $value) {
                    throw new \DomainException('Общие указания противоречат друг другу.');
                }
            }
        }
        if ([] === $result['fields'] && !$result['separate'] && !$result['confirm_projects']) {
            throw new \DomainException('Укажите общее уточнение для выбранных строк.');
        }

        return $result;
    }

    public static function date(string $value): string
    {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{2}|\d{4})$/D', $value, $match)) {
            // Short years in this explicit input format mean 2000–2099; displayed before saving.
            $value = (2 === strlen($match[3]) ? '20'.$match[3] : $match[3]).'-'.$match[2].'-'.$match[1];
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \DomainException('Укажите корректную дату: ДД.ММ.ГГГГ или ГГГГ-ММ-ДД.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $output
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    public static function applyHeaders(array $output, string $text, array $snapshot): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*(?:\d+[.)]|[-+]\s*\d)/u', $line)) {
                break;
            }
            if (preg_match('/^\s*(?:дата|проект|цфо|направление|валюта|расход по проекту|приход по проекту)\b/iu', $line)) {
                $lines[] = $line;
            }
        }
        if ([] === $lines) {
            return $output;
        }
        $required = [];
        foreach ($lines as $line) {
            foreach (['date' => '/^дата/iu', 'project_id' => '/^(?:проект|расход по проекту|приход по проекту)/iu', 'cfo_id' => '/^цфо/iu', 'direction' => '/^(?:направление|расход по проекту|приход по проекту)/iu', 'currency' => '/^валюта/iu'] as $field => $pattern) {
                if (preg_match($pattern, trim($line))) {
                    $required[$field] = true;
                }
            }
        }
        try {
            $parsed = self::parse(implode("\n", $lines));
            $fields = $parsed['fields'];
            foreach (['project_id' => 'projects', 'cfo_id' => 'cfos'] as $field => $key) {
                if (!isset($fields[$field])) {
                    continue;
                }
                $matches = array_values(array_filter($snapshot[$key] ?? [], static fn (array $choice): bool => mb_strtolower($choice['name']) === mb_strtolower($fields[$field])));
                if (1 !== count($matches)) {
                    throw new \DomainException('Общий проект или ЦФО не найден однозначно.');
                }
                $fields[$field] = $matches[0]['id'];
            }
            foreach ($output['items'] as &$item) {
                $applied = [];
                foreach ($fields as $field => $value) {
                    if (null === ($item[$field] ?? null)) {
                        $item[$field] = $value;
                        $applied[$field] = $value;
                    } elseif ($item[$field] === $value) {
                        $applied[$field] = $value;
                    }
                }
                $item['questions'] = array_values(array_filter($item['questions'], static fn (string $question): bool => !self::resolved($question, $applied)));
            }
            unset($item);
            $output['questions'] = array_values(array_filter($output['questions'], static fn (string $question): bool => !self::resolved($question, $fields)));
        } catch (\DomainException) {
            foreach (array_keys($required) as $field) {
                $output['questions'][] = self::HEADER_QUESTIONS[$field];
            }
            $output['questions'] = array_values(array_unique($output['questions']));
        }

        return $output;
    }

    private const array HEADER_QUESTIONS = [
        'date' => 'Общая дата не применена. Укажите дату для всех строк.',
        'project_id' => 'Общий проект не применён. Укажите точное название проекта.',
        'cfo_id' => 'Общий ЦФО не применён. Укажите точное название ЦФО.',
        'direction' => 'Общее направление не применено. Укажите приход или расход.',
        'currency' => 'Общая валюта не применена. Укажите валюту.',
    ];

    /** @param list<string> $questions */
    public static function hasUnresolvedHeaders(array $questions): bool
    {
        return [] !== array_intersect($questions, self::HEADER_QUESTIONS);
    }

    /** Only known simple questions can be resolved automatically. Free-form meaning requires review.
     * @param array<string, mixed> $fields
     */
    public static function resolved(string $question, array $fields, bool $separate = false): bool
    {
        foreach (self::HEADER_QUESTIONS as $field => $known) {
            if ($known === $question) {
                return isset($fields[$field]);
            }
        }
        $simple = [
            'укажите дату.' => ['date'],
            'укажите дату платежа.' => ['date'],
            'уточните дату этого платежа.' => ['date'],
            'подтвердите факт оплаты.' => ['execution_status'],
            'подтвердите факт оплаты и укажите дату.' => ['execution_status', 'date'],
            'уточните направление: приход или расход?' => ['direction'],
            'какой проект?' => ['project_id'],
            'укажите проект и цфо.' => ['project_id', 'cfo_id'],
            'укажите доступную пару проекта и цфо.' => ['project_id', 'cfo_id'],
            'укажите валюту.' => ['currency'],
        ];
        $normalized = mb_strtolower(trim($question));
        if (in_array($normalized, ['это отдельные платежи или части одного?', 'это отдельные платежи или части одного платежа?', 'это отдельные платежи?'], true)) {
            return $separate;
        }
        if (!isset($simple[$normalized])) {
            return false;
        }
        foreach ($simple[$normalized] as $field) {
            if (!isset($fields[$field])) {
                return false;
            }
        }

        return true;
    }
}

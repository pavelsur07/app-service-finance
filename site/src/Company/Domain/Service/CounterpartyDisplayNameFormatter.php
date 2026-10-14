<?php

declare(strict_types=1);

namespace App\Company\Domain\Service;

/**
 * Отображаемое название контрагента в едином виде: «ООО «Ромашка»», «ИП Иванов Иван Иванович».
 *
 * Сырое название (как пришло из выписки или формы) не меняется — отсюда берётся производное
 * значение для показа (docs/plan/counterparty-display-name.md). Правило «не уверен — не трогай»:
 * null, если строку не удалось уверенно разобрать; тогда показывается сырое название.
 *
 * Чистый детерминированный сервис без БД и внешних вызовов. Регистр названия организации
 * не меняется (аббревиатуры и бренды: «РВБ», «Digital Ads»); ФИО ИП, записанное целиком
 * заглавными или строчными, приводится к «Фамилия Имя Отчество».
 *
 * Словарь ОПФ — свой, а не CounterpartyNameNormalizer: тот строит ключ сравнения (верхний
 * регистр, без кавычек), и новые формы в нём сменили бы name_core уже сохранённых записей.
 */
final readonly class CounterpartyDisplayNameFormatter
{
    /**
     * Канонический вид => варианты написания. Длинные проверяются раньше коротких
     * («ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО» раньше «АКЦИОНЕРНОЕ ОБЩЕСТВО»).
     *
     * @var array<string, list<string>>
     */
    private const LEGAL_FORMS = [
        'ООО' => ['ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ', 'О.О.О.', 'ООО'],
        'СПАО' => ['СТРАХОВОЕ ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО', 'СПАО'],
        'САО' => ['СТРАХОВОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО', 'САО'],
        'НАО' => ['НЕПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО', 'НАО'],
        'ПАО' => ['ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО', 'ПАО'],
        'ЗАО' => ['ЗАКРЫТОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО', 'ЗАО'],
        'ОАО' => ['ОТКРЫТОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО', 'ОАО'],
        'АО' => ['АКЦИОНЕРНОЕ ОБЩЕСТВО', 'АО'],
        'АНО' => ['АВТОНОМНАЯ НЕКОММЕРЧЕСКАЯ ОРГАНИЗАЦИЯ', 'АНО'],
    ];

    /** Только префиксом: суффиксное «ИП» неотличимо от инициалов («Иванов И.П.»). */
    private const ENTREPRENEUR = ['ИНДИВИДУАЛЬНЫЙ ПРЕДПРИНИМАТЕЛЬ', 'ИП'];

    /** Госучреждения и казначейство: формы неоднозначны, название не трогаем. */
    private const NEVER_TOUCH = ['ФГБУ', 'ФГУП', 'ГБУ', 'МБУ', 'МУП', 'ГУП', 'УФК', 'КАЗНАЧЕЙСТВО'];

    /** Отчества-частицы пишутся строчными: «Алиев Рустам Али оглы». */
    private const PATRONYMIC_PARTICLES = ['оглы', 'кызы', 'улы', 'гызы'];

    private const QUOTES = ['"', '«', '»', '“', '”', '„', '‘', '’', '`', "'"];

    public function format(string $rawName): ?string
    {
        $collapsed = self::collapse($rawName);
        $name = $this->extractParty($collapsed);

        if (null === $name || $this->containsNeverTouch($name)) {
            return null;
        }

        [$form, $body] = $this->splitLegalForm($name);

        if (null === $form) {
            // ОПФ нет — приводить нечего; результат есть, только если разбор выписки что-то убрал.
            return $name !== $collapsed ? $name : null;
        }

        $body = $this->stripOuterQuotes($body);

        if ('' === $body || $this->containsQuote($body)) {
            // Пусто или вложенные кавычки («Фирма "Ромашка"») — однозначного вида нет.
            return null;
        }

        if ('ИП' === $form) {
            $person = $this->formatPersonName($body);

            return null === $person ? null : 'ИП '.$person;
        }

        return $form.' «'.$body.'»';
    }

    /**
     * Банковская строка плательщика: «БАНК ГПБ (АО)//40701810992000010090 ИНН7705042179 СПАО
     * "Ингосстрах"» — сторона операции в последнем сегменте после «//»; ИНН, КПП и номер счёта
     * в названии не нужны.
     */
    private function extractParty(string $name): ?string
    {
        $segments = explode('//', $name);

        foreach (array_reverse($segments) as $segment) {
            $segment = (string) preg_replace(
                [
                    '/(?<![\p{L}\d])ИНН\s*:?\s*\d{10}(?:\d{2})?(?!\d)/iu',
                    '/(?<![\p{L}\d])КПП\s*:?\s*\d{9}(?!\d)/iu',
                    '/(?<![\p{L}\d])\d{20}(?!\d)/u',
                ],
                ' ',
                $segment,
            );
            $segment = self::collapse($segment);

            if (1 === preg_match('/\p{L}/u', $segment)) {
                return $segment;
            }
        }

        return null;
    }

    private function containsNeverTouch(string $name): bool
    {
        foreach (self::NEVER_TOUCH as $token) {
            if (1 === preg_match('/(?<![\p{L}\d])'.preg_quote($token, '/').'(?![\p{L}\d])/iu', $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string|null, 1: string} каноническая ОПФ (или null) и остаток названия
     */
    private function splitLegalForm(string $name): array
    {
        foreach ($this->candidates() as [$variant, $form, $prefixOnly]) {
            $token = preg_quote($variant, '/');

            // Префикс: «ООО "Ромашка"», «ООО"Ромашка"», «Общество с ограниченной ответственностью …».
            if (1 === preg_match('/^'.$token.'(?![\p{L}\d])\s*(.*)$/iu', $name, $m)) {
                return [$form, trim($m[1])];
            }

            if ($prefixOnly) {
                continue;
            }

            // Суффикс в скобках: «БАНК ГПБ (АО)».
            if (1 === preg_match('/^(.*?)\s*\(\s*'.$token.'\s*\)$/iu', $name, $m)) {
                return [$form, trim($m[1])];
            }

            // Суффикс: «"Ромашка" ООО».
            if (1 === preg_match('/^(.*?)(?<![\p{L}\d])'.$token.'$/iu', $name, $m)) {
                return [$form, trim($m[1])];
            }
        }

        return [null, $name];
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool}> вариант, каноническая форма, только префиксом
     */
    private function candidates(): array
    {
        $candidates = [];
        foreach (self::LEGAL_FORMS as $form => $variants) {
            foreach ($variants as $variant) {
                $candidates[] = [$variant, $form, false];
            }
        }
        foreach (self::ENTREPRENEUR as $variant) {
            $candidates[] = [$variant, 'ИП', true];
        }

        usort($candidates, static fn (array $a, array $b): int => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        return $candidates;
    }

    private function stripOuterQuotes(string $body): string
    {
        $first = mb_substr($body, 0, 1);
        $last = mb_substr($body, -1);

        if (mb_strlen($body) >= 2 && \in_array($first, self::QUOTES, true) && \in_array($last, self::QUOTES, true)) {
            return trim(mb_substr($body, 1, -1));
        }

        return $body;
    }

    private function containsQuote(string $value): bool
    {
        return str_replace(self::QUOTES, '', $value) !== $value;
    }

    /**
     * ФИО: только кириллица, дефис, точки (инициалы), 2–4 слова. Смешанный регистр — как ввели:
     * человек мог сознательно написать «ДеКарт»; целиком заглавные или строчные — «Фамилия Имя».
     */
    private function formatPersonName(string $body): ?string
    {
        if (1 !== preg_match('/^[\p{Cyrillic}.\- ]+$/u', $body)) {
            return null;
        }

        $words = explode(' ', $body);
        if (\count($words) < 2 || \count($words) > 4) {
            return null;
        }

        $isUniformCase = $body === mb_strtoupper($body, 'UTF-8') || $body === mb_strtolower($body, 'UTF-8');
        if (!$isUniformCase) {
            return $body;
        }

        $words = array_map(
            static fn (string $word): string => \in_array(mb_strtolower($word, 'UTF-8'), self::PATRONYMIC_PARTICLES, true)
                ? mb_strtolower($word, 'UTF-8')
                : self::capitalizeLetterRuns($word),
            $words,
        );

        return implode(' ', $words);
    }

    /**
     * Каждая буквенная группа — с заглавной: «ПЕТРОВА-ВОДКИНА» → «Петрова-Водкина», «И.И.» → «И.И.».
     * MB_CASE_TITLE так не умеет: точку Unicode считает «проницаемой» и даёт «И.и.».
     */
    private static function capitalizeLetterRuns(string $word): string
    {
        return (string) preg_replace_callback(
            '/\p{L}+/u',
            static fn (array $m): string => mb_strtoupper(mb_substr($m[0], 0, 1, 'UTF-8'), 'UTF-8').mb_strtolower(mb_substr($m[0], 1, null, 'UTF-8'), 'UTF-8'),
            $word,
        );
    }

    private static function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}

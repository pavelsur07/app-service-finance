<?php

declare(strict_types=1);

namespace App\Company\Domain\Service;

/**
 * Отображаемое название контрагента в едином виде: «ООО «Ромашка»», «ИП Иванов Иван Иванович».
 *
 * Сырое название (как пришло из выписки или формы) не меняется — отсюда берётся производное
 * значение для показа (docs/plan/counterparty-display-name.md). Правило «не уверен — не трогай»:
 * null, если строку не удалось уверенно разобрать; тогда показывается сырое название.
 * Неверный уверенный ответ хуже null, поэтому любые хвосты, адреса, округа, филиалы,
 * несколько ОПФ сразу — null.
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
     * Вариант написания => [каноническая ОПФ, только префиксом]. Порядок — от длинных к коротким:
     * «ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО» раньше «АКЦИОНЕРНОЕ ОБЩЕСТВО», «СПАО» раньше «ПАО».
     * ИП — только префиксом: суффиксное «И.П.» неотличимо от инициалов.
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const VARIANTS = [
        'ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ' => ['ООО', false],
        'СТРАХОВОЕ ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО' => ['СПАО', false],
        'АВТОНОМНАЯ НЕКОММЕРЧЕСКАЯ ОРГАНИЗАЦИЯ' => ['АНО', false],
        'НЕПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО' => ['НАО', false],
        'СТРАХОВОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО' => ['САО', false],
        'ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО' => ['ПАО', false],
        'ЗАКРЫТОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО' => ['ЗАО', false],
        'ОТКРЫТОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО' => ['ОАО', false],
        'ИНДИВИДУАЛЬНЫЙ ПРЕДПРИНИМАТЕЛЬ' => ['ИП', true],
        'АКЦИОНЕРНОЕ ОБЩЕСТВО' => ['АО', false],
        'О.О.О.' => ['ООО', false],
        'СПАО' => ['СПАО', false],
        'ООО' => ['ООО', false],
        'ПАО' => ['ПАО', false],
        'НАО' => ['НАО', false],
        'ЗАО' => ['ЗАО', false],
        'ОАО' => ['ОАО', false],
        'САО' => ['САО', false],
        'АНО' => ['АНО', false],
        'АО' => ['АО', false],
        'ИП' => ['ИП', true],
    ];

    /**
     * Госучреждения, органы власти, филиалы, КФХ: ОПФ неоднозначна или принадлежит головной
     * организации («Филиал … Банка ВТБ (ПАО)») — не трогаем.
     */
    private const NEVER_TOUCH = [
        'ФГБУ', 'ФГУП', 'ФГАУ', 'ФГБОУ', 'ФКУ', 'ГБУ', 'ГКУ', 'ГАУ', 'ГУП', 'ГБОУ', 'ОГБУ', 'ОГКУ', 'КГБУ',
        'МБУ', 'МКУ', 'МАУ', 'МУП', 'МБОУ', 'МАОУ', 'УФК', 'ИФНС', 'ОМВД', 'КФХ',
        'КАЗНАЧЕЙСТВО', 'ПРЕФЕКТУРА', 'УПРАВА', 'АДМИНИСТРАЦИЯ', 'ДЕПАРТАМЕНТ', 'МИНИСТЕРСТВО',
        'УПРАВЛЕНИЕ', 'ОТДЕЛЕНИЕ', 'ФИЛИАЛ', 'Ф-Л', 'ГЛАВА',
    ];

    /** Отчества-частицы пишутся строчными: «Алиев Рустам Али оглы». */
    private const PATRONYMIC_PARTICLES = ['оглы', 'кызы', 'улы', 'гызы'];

    private const QUOTES = '"«»“”„‘’`\'';

    public function format(string $rawName): ?string
    {
        $name = self::collapse($rawName);
        $fromBankString = str_contains($name, '//');
        $party = $fromBankString ? $this->pickBankStringParty($name) : $this->stripIds($name);

        if (null === $party || '' === $party || $this->containsNeverTouch($party) || $this->countLegalForms($party) > 1) {
            return null;
        }

        $split = $this->splitLegalForm($party);

        if (null === $split) {
            // ОПФ нет. Банковская строка — сторона операции без ОПФ (человек): только чистый текст.
            return $fromBankString && 1 === preg_match('/^\p{L}[\p{L}.\- ]*$/u', $party) ? $party : null;
        }

        [$form, $body] = $split;
        $body = $this->stripOuterQuotes($body);

        if ('ИП' === $form) {
            $person = $this->formatPersonName($body);

            return null === $person ? null : 'ИП '.$person;
        }

        return $this->isCleanOrganizationName($body) ? $form.' «'.$body.'»' : null;
    }

    /**
     * Строка 383-П: «БАНК ГПБ (АО)//40701810992000010090 ИНН7705042179 СПАО "Ингосстрах"»,
     * «ИВАНОВ ИВАН ИВАНОВИЧ//123456, г. Москва, ул. Ленина, д. 1//», «ПАО СБЕРБАНК//ФИО//адрес//».
     * Отбрасываются адреса и счета, первый сегмент-банк; остаётся ровно один кандидат или
     * ровно один с ОПФ — иначе null.
     */
    private function pickBankStringParty(string $name): ?string
    {
        $segments = [];
        foreach (explode('//', $name) as $segment) {
            if ($this->looksLikeAddressOrAccount($segment)) {
                continue;
            }
            $segment = $this->stripIds($segment);
            if (1 === preg_match('/\p{L}/u', $segment)) {
                $segments[] = $segment;
            }
        }

        if (\count($segments) > 1 && str_contains(mb_strtoupper($segments[0], 'UTF-8'), 'БАНК')) {
            array_shift($segments);
        }

        if (1 === \count($segments)) {
            return $segments[0];
        }

        $withForm = array_values(array_filter($segments, fn (string $segment): bool => $this->countLegalForms($segment) > 0));

        return 1 === \count($withForm) ? $withForm[0] : null;
    }

    private function looksLikeAddressOrAccount(string $segment): bool
    {
        return 1 === preg_match(
            '~(?<![\p{L}\d])(?:г|ул|пер|наб|корп|кв|обл|пос|пр-т|просп)\.|(?<![\p{L}\d])д\.\s*\d|(?<![\p{L}\d])р-н(?![\p{L}])|(?<!\d)\d{6}(?!\d)|(?<![\p{L}])[рлк]/с(?![\p{L}])~u',
            $segment,
        );
    }

    /** ИНН, КПП, ОГРН(ИП), 20-значный счёт и пустые скобки после них; хвостовая пунктуация. */
    private function stripIds(string $value): string
    {
        $value = (string) preg_replace(
            [
                '/(?<![\p{L}\d])ИНН\s*:?\s*\d{10}(?:\d{2})?(?!\d)/iu',
                '/(?<![\p{L}\d])КПП\s*:?\s*\d{9}(?!\d)/iu',
                '/(?<![\p{L}\d])ОГРН(?:ИП)?\s*:?\s*\d{13}(?:\d{2})?(?!\d)/iu',
                '/(?<![\p{L}\d])\d{20}(?!\d)/u',
                '/\(\s*\)/u',
            ],
            ' ',
            $value,
        );

        return trim(self::collapse($value), " ,;:/\t");
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

    /** Сколько ОПФ стоит в строке отдельными словами: больше одной — порядок неоднозначен. */
    private function countLegalForms(string $name): int
    {
        $count = 0;
        foreach (array_keys(self::VARIANTS) as $variant) {
            $name = (string) preg_replace_callback(
                '/(?<![\p{L}\d])'.preg_quote($variant, '/').'(?![\p{L}\d])/iu',
                static function (array $m) use (&$count, $variant): string {
                    if (self::isFormToken($m[0], $variant)) {
                        ++$count;

                        return ' ';
                    }

                    return $m[0];
                },
                $name,
            );
        }

        return $count;
    }

    /**
     * @return array{0: string, 1: string}|null каноническая ОПФ и остаток названия
     */
    private function splitLegalForm(string $name): ?array
    {
        $quote = preg_quote(self::QUOTES, '/');

        foreach (self::VARIANTS as $variant => [$form, $prefixOnly]) {
            $token = preg_quote($variant, '/');

            // Префикс: после ОПФ — пробел или кавычка, не «-», «.», «(»: «ООО-Ромашка» не разбираем.
            if (1 === preg_match('/^('.$token.')(?:\s+|(?=['.$quote.']))(.+)$/iu', $name, $m) && self::isFormToken($m[1], $variant)) {
                return [$form, trim($m[2])];
            }

            if ($prefixOnly) {
                continue;
            }

            // Суффикс в скобках: «Банк ВТБ (ПАО)».
            if (1 === preg_match('/^(.+?)\s*\(\s*('.$token.')\s*\)$/iu', $name, $m) && self::isFormToken($m[2], $variant)) {
                return [$form, trim($m[1])];
            }

            // Голый суффикс — только после названия в кавычках: «"Ромашка" ООО». Без кавычек
            // это округ («Префектура ЗАО», «Ненецкого АО»), а не ОПФ.
            if (1 === preg_match('/^(['.$quote.'].+['.$quote.'])\s+('.$token.')$/iu', $name, $m) && self::isFormToken($m[2], $variant)) {
                return [$form, trim($m[1])];
            }
        }

        return null;
    }

    /**
     * Сокращение — только целиком заглавными или строчными: «Сао Пауло», «Ип Ман» — не ОПФ.
     * Полная форма — в любом регистре.
     */
    private static function isFormToken(string $matched, string $variant): bool
    {
        if (str_contains($variant, ' ')) {
            return true;
        }

        return $matched === mb_strtoupper($matched, 'UTF-8') || $matched === mb_strtolower($matched, 'UTF-8');
    }

    private function stripOuterQuotes(string $body): string
    {
        $first = mb_substr($body, 0, 1, 'UTF-8');
        $last = mb_substr($body, -1, null, 'UTF-8');

        if (mb_strlen($body, 'UTF-8') >= 2 && str_contains(self::QUOTES, $first) && str_contains(self::QUOTES, $last)) {
            return trim(mb_substr($body, 1, -1, 'UTF-8'));
        }

        return $body;
    }

    /**
     * Название организации без хвостов: начинается с буквы или цифры; без кавычек внутри,
     * скобок, запятых, «/»; без реквизитов (ИНН, р/с), длинных чисел (счёт, карта) и адреса.
     */
    private function isCleanOrganizationName(string $body): bool
    {
        if ('' === $body || 1 !== preg_match('/^[\p{L}\d]/u', $body)) {
            return false;
        }

        $quote = preg_quote(self::QUOTES, '/');

        return 1 !== preg_match('/['.$quote.'(),;\/]/u', $body)
            && 1 !== preg_match('/(?<![\p{L}\d])(?:ИНН|КПП|ОГРН|ОГРНИП)(?![\p{L}])/iu', $body)
            && 1 !== preg_match('/\d{5,}|\d{4}\s+\d{4}/u', $body)
            && !$this->looksLikeAddressOrAccount($body);
    }

    /**
     * ФИО: 2–4 слова, каждое — кириллица (двойные через дефис) или инициалы «И.» / «И.И.».
     * Смешанный регистр — как ввели («ДеКарт»); целиком заглавные или строчные — «Фамилия Имя».
     */
    private function formatPersonName(string $body): ?string
    {
        $words = explode(' ', $body);
        if (\count($words) < 2 || \count($words) > 4) {
            return null;
        }

        foreach ($words as $word) {
            if (1 !== preg_match('/^(?:\p{Cyrillic}+(?:-\p{Cyrillic}+)*|(?:\p{Cyrillic}\.){1,2})$/u', $word)) {
                return null;
            }
        }

        $isUniformCase = $body === mb_strtoupper($body, 'UTF-8') || $body === mb_strtolower($body, 'UTF-8');
        if (!$isUniformCase) {
            return $body;
        }

        return implode(' ', array_map(
            static fn (string $word): string => \in_array(mb_strtolower($word, 'UTF-8'), self::PATRONYMIC_PARTICLES, true)
                ? mb_strtolower($word, 'UTF-8')
                : self::capitalizeLetterRuns($word),
            $words,
        ));
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

    /** Пробелы схлопываются, невидимые символы (U+200B–U+200D, U+FEFF) убираются. */
    private static function collapse(string $value): string
    {
        $value = (string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}

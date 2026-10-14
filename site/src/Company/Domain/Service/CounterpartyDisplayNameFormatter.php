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
     * Версия правил: поднимать при любом изменении результата format() — пересчёт
     * (app:counterparty:refresh-display-names) обработает все авто-названия заново.
     */
    public const RULES_VERSION = 2;

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
        'УПРАВЛЕНИЕ', 'ОТДЕЛЕНИЕ', 'ФИЛИАЛ', 'Ф-Л', 'ФИЛ', 'ОП', 'ОСП', 'ОБОСОБЛЕННОЕ', 'ПОДРАЗДЕЛЕНИЕ', 'ГЛАВА',
    ];

    /**
     * Подпись счёта: длинные варианты раньше коротких («р/сч» раньше «р/с», «расч. счёт» раньше
     * «счёт»), иначе от подписи остался бы хвост «р», «расч.».
     */
    private const ACCOUNT_LABEL = '(?:расч(?:\.|[её]тный)?\s*сч[её]т|кор\.?\s*сч(?:[её]т)?\.?|р\s*\/\s*сч\.?|р\.\s*сч\.|р\s*\/\s*с|р\.\s*с\.|л\s*\/\s*с|л\.\s*с\.|с\s*\/\s*ч|сч(?:[её]т)?\.?)';

    /**
     * Слова, с которых не начинается ФИО: назначение платежа и вывески («ПЕРЕВОД И.И.»,
     * «ТОРГОВЫЙ ДОМ ИВАНОВИЧ», «БАР ДЯДЯ ПЕТРОВИЧ»). Любое из них в строке — это не человек.
     */
    private const NOT_PERSON_WORDS = [
        'ПЕРЕВОД', 'ВОЗВРАТ', 'ОПЛАТА', 'КОМИССИЯ', 'ПОПОЛНЕНИЕ', 'СПИСАНИЕ', 'ЗАЧИСЛЕНИЕ', 'ВЫДАЧА',
        'ТОРГОВЫЙ', 'ТОРГОВАЯ', 'ТОРГОВОЕ', 'ДОМ', 'МАГАЗИН', 'САЛОН', 'КАФЕ', 'БАР', 'РЕСТОРАН',
        'КОМПАНИЯ', 'ГРУППА', 'ЦЕНТР', 'СТУДИЯ', 'КЛУБ', 'АТЕЛЬЕ', 'АПТЕКА', 'ДЯДЯ', 'ТЕТЯ', 'ТЁТЯ',
    ];

    /** Отчества-частицы пишутся строчными: «Алиев Рустам Али оглы». */
    private const PATRONYMIC_PARTICLES = ['оглы', 'кызы', 'улы', 'гызы'];

    private const QUOTES = '"«»“”„‘’`\'';

    public function format(string $rawName): ?string
    {
        // Удвоенные кавычки CSV: «ООО ""Ромашка""».
        $name = str_replace('""', '"', self::collapse($rawName));
        $fromBankString = str_contains($name, '//');
        // Голый 20-значный счёт вырезается только в банковской строке. В обычной — только счёт с
        // подписью целиком в конце или в начале строки (stripLabelledAccount); иначе длинное число
        // отклонит название.
        $party = $fromBankString ? $this->pickBankStringParty($name) : $this->stripIds($this->stripLabelledAccount($name), false);

        if (null === $party || '' === $party || $this->containsNeverTouch($party) || $this->countLegalForms($party) > 1) {
            return null;
        }

        $split = $this->splitLegalForm($party);

        if (null === $split) {
            return $this->formatPersonWithoutLegalForm($party, $fromBankString);
        }

        [$form, $body] = $split;

        // v2: вложенные кавычки «ООО "КОМПАНИЯ "ТЕНЗОР"» → «ООО «КОМПАНИЯ «ТЕНЗОР»»».
        if ('ИП' !== $form) {
            $nested = $this->formatNestedQuotes($body);
            if (null !== $nested) {
                return $form.' '.$nested;
            }
        }

        $body = $this->stripOuterQuotes($body);

        if ('ИП' === $form) {
            $person = $this->formatPersonName($body);

            return null === $person ? null : 'ИП '.$person;
        }

        return $this->isCleanOrganizationName($body) ? $form.' «'.$body.'»' : null;
    }

    /**
     * Человек без ОПФ — только узнаваемое ФИО (с отчеством или инициалами), а не «ВНУТРЕННИЙ
     * ПЕРЕВОД» или «МОСКВА». В банковской строке — всегда к виду «Фамилия Имя Отчество».
     * v2: вне банковской строки — только ФИО целиком заглавными или строчными; записанное как
     * следует («Иванов Иван Иванович») — null, приводить нечего.
     */
    private function formatPersonWithoutLegalForm(string $party, bool $fromBankString): ?string
    {
        if (0 !== $this->countLegalForms($party) || !$this->looksLikeFullPersonName($party)) {
            return null;
        }

        if (!$fromBankString && $party !== mb_strtoupper($party, 'UTF-8') && $party !== mb_strtolower($party, 'UTF-8')) {
            return null;
        }

        return $this->formatPersonName($party);
    }

    /**
     * Вложенные кавычки 1С и выписок: `"КОМПАНИЯ "ТЕНЗОР"`, `"ПФ "СКБ Контур""`, `«ТД «Ромашка»»`.
     * Обе части — чистые названия; регистр не меняется. Иначе null — пусть решает общая ветка.
     */
    private function formatNestedQuotes(string $body): ?string
    {
        if (1 !== preg_match('/^["«“„]([^"«»“”„]+?)\s+["«“„]([^"«»“”„]+?)["»”]{1,2}$/u', $body, $m)) {
            return null;
        }

        $outer = trim($m[1]);
        $inner = trim($m[2]);

        return $this->isCleanOrganizationName($outer) && $this->isCleanOrganizationName($inner)
            && $this->isCleanOrganizationName($outer.' '.$inner)
            ? '«'.$outer.' «'.$inner.'»»'
            : null;
    }

    /**
     * v2: счёт с подписью — только целиком в конце или в начале строки, не в середине: «… Р/С
     * 40702… в Сбербанке» склеило бы банк с названием. В конце — только после закрывающей кавычки
     * или после узнаваемого ФИО: «ООО Ромашка на сч 40702…» иначе дало бы «Ромашка на».
     */
    private function stripLabelledAccount(string $name): string
    {
        $label = self::ACCOUNT_LABEL;
        $quote = preg_quote(self::QUOTES, '/');

        if (1 === preg_match('/^(.*['.$quote.'])\s*,?\s*'.$label.'\s*:?\s*\d{20}\s*[.,;]?$/iu', $name, $m)) {
            return trim($m[1]);
        }

        if (1 === preg_match('/^(.+?)[\s,;]+'.$label.'\s*:?\s*\d{20}\s*[.,;]?$/iu', $name, $m) && $this->looksLikeFullPersonName(trim($m[1]))) {
            return trim($m[1]);
        }

        if (1 === preg_match('/^'.$label.'\s*:?\s*\d{20}[\s,;]+(.+)$/iu', $name, $m)) {
            return trim($m[1]);
        }

        return $name;
    }

    /**
     * Строка 383-П: «БАНК ГПБ (АО)//40701810992000010090 ИНН7705042179 СПАО "Ингосстрах"»,
     * «ИВАНОВ ИВАН ИВАНОВИЧ//123456, г. Москва, ул. Ленина, д. 1//», «ПАО СБЕРБАНК//ФИО//адрес//».
     * Отбрасываются адреса и счета, первый сегмент-банк; должен остаться ровно один кандидат,
     * иначе null.
     */
    private function pickBankStringParty(string $name): ?string
    {
        $segments = [];
        foreach (explode('//', $name) as $segment) {
            if ($this->looksLikeAddressOrAccount($segment)) {
                continue;
            }
            $segment = $this->stripIds($segment, true);
            if (1 === preg_match('/\p{L}/u', $segment)) {
                $segments[] = $segment;
            }
        }

        if (\count($segments) > 1 && str_contains(mb_strtoupper($segments[0], 'UTF-8'), 'БАНК')) {
            array_shift($segments);
        }

        // Ровно один кандидат — иначе не угадываем: «ФИО//ПАО СБЕРБАНК//» — это ФИО и его банк.
        return 1 === \count($segments) ? $segments[0] : null;
    }

    /**
     * Строгая проверка сегмента банковской строки: адресные сокращения только строчными —
     * иначе инициалы «ИВАНОВ Г.П.» приняли бы за «г.» и выбросили бы сегмент с ФИО.
     */
    private function looksLikeAddressOrAccount(string $segment): bool
    {
        return 1 === preg_match(
            '~(?<![\p{L}\d])(?:г|ул|пер|наб|корп|кв|обл|пос|пр-т|просп)\.|(?<![\p{L}\d])д\.\s*\d|(?<![\p{L}\d])р-н(?![\p{L}])|(?<!\d)\d{6}(?!\d)|(?<![\p{L}])[рлк]/с(?![\p{L}])~u',
            $segment,
        );
    }

    /**
     * Широкая проверка названия организации: адрес в любом регистре («Г. МОСКВА», «ул Ленина»,
     * «с. Ивановка», «мкр. 5»). Здесь null дёшев, поэтому ловим с запасом.
     */
    private function looksLikeAddress(string $body): bool
    {
        return 1 === preg_match(
            '/(?<![\p{L}\d])(?:г|ул|пер|наб|корп|кв|обл|пос|с|п|пгт|ст|мкр|стр|ш|пр|д|х|респ|р-н|пр-т|просп)(?:\.\s*|\s+)(?=[\p{L}\d])/iu',
            $body,
        ) || $this->looksLikeAddressOrAccount($body);
    }

    /** ИНН, КПП, ОГРН(ИП), 20-значный счёт (если $accounts) и пустые скобки; хвостовая пунктуация. */
    private function stripIds(string $value, bool $accounts): string
    {
        $patterns = [
            '/(?<![\p{L}\d])ИНН\s*:?\s*\d{10}(?:\d{2})?(?!\d)/iu',
            '/(?<![\p{L}\d])КПП\s*:?\s*\d{9}(?!\d)/iu',
            '/(?<![\p{L}\d])ОГРН(?:ИП)?\s*:?\s*\d{13}(?:\d{2})?(?!\d)/iu',
        ];
        if ($accounts) {
            $patterns[] = '/(?<![\p{L}\d])\d{20}(?!\d)/u';
        }
        $patterns[] = '/\(\s*\)/u';

        $value = (string) preg_replace($patterns, ' ', $value);

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

            // v2: суффикс после запятой — так ОПФ пишет 1С («Интернет Решения, ООО»). Не для АО /
            // ЗАО / САО — это ещё и округа («Москва, ЗАО», «Ненецкий, АО»), и не для названий-
            // прилагательных и регионов («Тюменская область, ПАО», «Московский, ПАО»).
            if (!\in_array($variant, ['АО', 'ЗАО', 'САО'], true)
                && 1 === preg_match('/^(.+?),\s*('.$token.')$/iu', $name, $m)
                && self::isFormToken($m[2], $variant)
                && !$this->looksLikeRegion(trim($m[1]))) {
                return [$form, trim($m[1])];
            }

            // Голый суффикс — только после названия в кавычках: «"Ромашка" ООО». Без кавычек
            // это округ («Префектура ЗАО», «Ненецкого АО»), а не ОПФ.
            if (1 === preg_match('/^(['.$quote.'].+['.$quote.'])\s+('.$token.')$/iu', $name, $m) && self::isFormToken($m[2], $variant)) {
                return [$form, trim($m[1])];
            }
        }

        return $this->splitEntrepreneurSuffix($name);
    }

    /**
     * v2: ИП после ФИО — «ФАМИЛИЯ ИМЯ ОТЧЕСТВО (ИП)», «Фамилия И.О. ИП». И в скобках, и без —
     * только после узнаваемого ФИО (отчество или инициалы): «Магазин у дома (ИП)» — не ФИО, а
     * слитное «Иванов И.П.» — инициалы, а не ОПФ.
     *
     * @return array{0: string, 1: string}|null
     */
    private function splitEntrepreneurSuffix(string $name): ?array
    {
        if (1 !== preg_match('/^(.+?)(?:\s*\(\s*(ИП)\s*\)|(?:\s*,\s*|\s+)(ИП))$/iu', $name, $m)) {
            return null;
        }

        $token = '' !== ($m[2] ?? '') ? $m[2] : ($m[3] ?? '');
        $person = trim($m[1]);

        return self::isFormToken($token, 'ИП') && $this->looksLikeFullPersonName($person) ? ['ИП', $person] : null;
    }

    /** Регион или название-прилагательное: «Московский», «Тюменская область», «… округ - Югра». */
    private function looksLikeRegion(string $body): bool
    {
        return 1 === preg_match('/(?:ский|цкий|ской|цкой|ный|ной|ская|цкая|ное)$/iu', $body)
            || 1 === preg_match('/(?<![\p{L}])(?:область|округ|край|республика|москва|санкт-петербург)(?![\p{L}])/iu', $body);
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
            && 1 !== preg_match('/(?<![\p{L}])(?:НДС|в\s+лице|тел|ОКПО|ОКВЭД|БИК)(?![\p{L}])/iu', $body)
            && !$this->looksLikeAddress($body);
    }

    /**
     * ФИО: 2–3 слова (четвёртое — только частица «оглы / кызы»), каждое — кириллица (двойные
     * через дефис) или инициалы «И.» / «И.И.».
     * Смешанный регистр — как ввели («ДеКарт»); целиком заглавные или строчные — «Фамилия Имя».
     */
    private function formatPersonName(string $body): ?string
    {
        $words = explode(' ', $body);
        $hasParticle = \in_array(mb_strtolower((string) end($words), 'UTF-8'), self::PATRONYMIC_PARTICLES, true);
        if (\count($words) < 2 || \count($words) > ($hasParticle ? 4 : 3)) {
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
     * Человек без ОПФ в банковской строке: «Фамилия Имя Отчество» с отчеством на -вич / -вна /
     * -ична или частицей, либо «Фамилия И.И.» / «Фамилия И. И.». «Иванов Иван» без отчества,
     * «ВНУТРЕННИЙ ПЕРЕВОД», «Москва Ленина» — не человек.
     */
    private function looksLikeFullPersonName(string $value): bool
    {
        $words = explode(' ', mb_strtoupper($value, 'UTF-8'));
        if ([] !== array_intersect($words, self::NOT_PERSON_WORDS)) {
            return false;
        }

        // Порядок «Фамилия Имя Отчество»: второе слово — не отчество («ВОЗВРАТ ИВАНОВ ИВАНОВИЧ»
        // ловит стоп-слово, «ИВАН ИВАНОВИЧ ПЕТРОВ» — эта проверка).
        if (isset($words[1]) && 1 === preg_match('/(?:вич|вна|ична)$/iu', $words[1])) {
            return false;
        }

        return 1 === preg_match('/^\p{Cyrillic}+(?:-\p{Cyrillic}+)*\s+\p{Cyrillic}+\s+\p{Cyrillic}*(?:вич|вна|ична|инична)(?:\s+(?:оглы|кызы|улы|гызы))?$/iu', $value)
            || 1 === preg_match('/^\p{Cyrillic}+(?:-\p{Cyrillic}+)*\s+\p{Cyrillic}\.\s*\p{Cyrillic}\.$/u', $value)
            || 1 === preg_match('/^\p{Cyrillic}+(?:-\p{Cyrillic}+)*\s+\p{Cyrillic}+\s+\p{Cyrillic}+\s+(?:оглы|кызы|улы|гызы)$/iu', $value);
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

<?php

declare(strict_types=1);

namespace App\Company\Domain\Service;

use App\Company\Domain\ValueObject\CounterpartyName;

/**
 * Единственная точка нормализации названия контрагента.
 *
 * Чистый детерминированный сервис: без БД, без внешних вызовов, без транслита и
 * без исправления опечаток. Один вход — всегда один выход.
 */
final class CounterpartyNameNormalizer
{
    /**
     * ОПФ, допустимые и в префиксе, и в суффиксе: в документах встречается оба порядка.
     *
     * @var array<string, list<string>> канонический хинт => варианты написания
     */
    private const ANY_POSITION = [
        'ООО' => ['ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ', 'О.О.О.', 'ООО'],
        'ПАО' => ['ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО', 'ПАО'],
        'АО' => ['АКЦИОНЕРНОЕ ОБЩЕСТВО', 'АО'],
        'ЗАО' => ['ЗАО'],
        'ОАО' => ['ОАО'],
    ];

    /**
     * Только префикс: суффиксное «ИП» неотличимо от инициалов («Иванов И.П.»).
     *
     * @var array<string, list<string>>
     */
    private const PREFIX_ONLY = [
        'ИП' => ['ИНДИВИДУАЛЬНЫЙ ПРЕДПРИНИМАТЕЛЬ', 'ИП'],
    ];

    /**
     * Формы, которые не вырезаем: они неоднозначны, ОПФ остаётся внутри core,
     * подсказка — null.
     *
     * @var list<string>
     */
    private const NEVER_STRIP = ['ФГБУ', 'ГБУ', 'МУП', 'ГУП', 'УФК', 'КАЗНАЧЕЙСТВО'];

    private const QUOTES = ['"', '«', '»', '“', '”', '‘', '’', '`', '′', '″', "'"];

    public function normalize(string $rawName): CounterpartyName
    {
        $display = $this->collapseSpaces($rawName);

        if ('' === $display) {
            throw new \InvalidArgumentException('Название контрагента не может быть пустым.');
        }

        $upper = str_replace(['Ё', 'ё'], 'Е', mb_strtoupper($display, 'UTF-8'));
        $withoutQuotes = $this->collapseSpaces(str_replace(self::QUOTES, ' ', $upper));

        [$core, $legalFormHint] = $this->extractLegalForm($withoutQuotes);

        $core = $this->cleanUp($core);

        if ('' === $core) {
            // Пользователь ввёл только ОПФ: подсказку не выдаём, core — вся строка.
            $core = $this->cleanUp($withoutQuotes);
            $legalFormHint = null;
        }

        return CounterpartyName::fromNormalizedParts($display, $legalFormHint, $core);
    }

    /**
     * @return array{0: string, 1: string|null} остаток названия и канонический хинт ОПФ
     */
    private function extractLegalForm(string $name): array
    {
        foreach (self::NEVER_STRIP as $form) {
            if ($this->hasToken($name, $form)) {
                return [$name, null];
            }
        }

        foreach ($this->candidates() as [$token, $hint, $suffixAllowed]) {
            if (str_starts_with($name, $token.' ')) {
                return [substr($name, strlen($token) + 1), $hint];
            }

            if ($suffixAllowed && str_ends_with($name, ' '.$token)) {
                return [substr($name, 0, -(strlen($token) + 1)), $hint];
            }
        }

        return [$name, null];
    }

    /**
     * Длинные варианты проверяются раньше коротких: иначе «ПУБЛИЧНОЕ АКЦИОНЕРНОЕ
     * ОБЩЕСТВО» распознается как «АКЦИОНЕРНОЕ ОБЩЕСТВО» и оставит «ПУБЛИЧНОЕ» в core.
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private function candidates(): array
    {
        $candidates = [];

        foreach (self::ANY_POSITION as $hint => $tokens) {
            foreach ($tokens as $token) {
                $candidates[] = [$token, $hint, true];
            }
        }

        foreach (self::PREFIX_ONLY as $hint => $tokens) {
            foreach ($tokens as $token) {
                $candidates[] = [$token, $hint, false];
            }
        }

        usort($candidates, static fn (array $a, array $b) => strlen($b[0]) <=> strlen($a[0]));

        return $candidates;
    }

    private function hasToken(string $name, string $token): bool
    {
        return 1 === preg_match('/(?<![\p{L}\d])'.preg_quote($token, '/').'(?![\p{L}\d])/u', $name);
    }

    /**
     * Точки снимаются строго после разбора ОПФ: иначе «ИВАНОВ И.П.» превратится
     * в «ИВАНОВ ИП» и суффиксное правило начнёт видеть ОПФ в инициалах.
     */
    private function cleanUp(string $name): string
    {
        $name = str_replace('.', ' ', $name);
        $name = (string) preg_replace('/-{2,}/u', '-', $name);

        return $this->collapseSpaces($name);
    }

    private function collapseSpaces(string $value): string
    {
        return CounterpartyName::collapseWhitespace($value);
    }
}

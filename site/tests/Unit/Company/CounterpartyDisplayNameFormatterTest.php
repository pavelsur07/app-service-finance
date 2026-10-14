<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Domain\Service\CounterpartyDisplayNameFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CounterpartyDisplayNameFormatterTest extends TestCase
{
    private CounterpartyDisplayNameFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new CounterpartyDisplayNameFormatter();
    }

    /**
     * Примеры Владельца из реальных выписок (2026-10-13).
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function ownerExamplesProvider(): iterable
    {
        yield 'банк-посредник, счёт и ИНН в строке' => ['БАНК ГПБ (АО)//40701810992000010090 ИНН7705042179 СПАО "Ингосстрах"', 'СПАО «Ингосстрах»'];
        yield 'ИП полной формой, ФИО заглавными' => ['ИНДИВИДУАЛЬНЫЙ ПРЕДПРИНИМАТЕЛЬ ЛИЗУНОВА ОЛЬГА НИКОЛАЕВНА', 'ИП Лизунова Ольга Николаевна'];
        yield 'ИП уже в стандарте' => ['ИП Абрамов Кирилл Викторович', 'ИП Абрамов Кирилл Викторович'];
        yield 'ООО полной формой' => ['ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ "РВБ"', 'ООО «РВБ»'];
        yield 'ООО, регистр организации не меняется' => ['ООО "ИНТЕРНЕТ МАРКЕТИНГ"', 'ООО «ИНТЕРНЕТ МАРКЕТИНГ»'];
    }

    #[DataProvider('ownerExamplesProvider')]
    public function testOwnerExamples(string $raw, string $expected): void
    {
        self::assertSame($expected, $this->formatter->format($raw));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function legalFormProvider(): iterable
    {
        yield 'ОПФ суффиксом' => ['"Ромашка" ООО', 'ООО «Ромашка»'];
        yield 'ОПФ в скобках' => ['БАНК ГПБ (АО)', 'АО «БАНК ГПБ»'];
        yield 'кавычки без пробела' => ['ООО"Ромашка"', 'ООО «Ромашка»'];
        yield 'ёлочки и лишние пробелы' => ['  ООО   «Ромашка»  ', 'ООО «Ромашка»'];
        yield 'ОПФ строчными' => ['ооо ромашка', 'ООО «ромашка»'];
        yield 'точки в ОПФ' => ['О.О.О. "Ромашка"', 'ООО «Ромашка»'];
        yield 'ПАО раньше АО' => ['ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО "Сбербанк России"', 'ПАО «Сбербанк России»'];
        yield 'НАО не путается с ПАО' => ['НЕПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО "Вектор"', 'НАО «Вектор»'];
        yield 'страховое публичное' => ['СТРАХОВОЕ ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО "ИНГОССТРАХ"', 'СПАО «ИНГОССТРАХ»'];
        yield 'АНО' => ['АВТОНОМНАЯ НЕКОММЕРЧЕСКАЯ ОРГАНИЗАЦИЯ "Школа"', 'АНО «Школа»'];
        yield 'латиница в названии' => ['ООО «Digital Ads»', 'ООО «Digital Ads»'];
        yield 'ИНН перед названием' => ['ИНН 7700000000 ООО "Ромашка"', 'ООО «Ромашка»'];
        yield 'ИНН и КПП после названия' => ['ООО "Ромашка" ИНН 7700000000 КПП 770101001', 'ООО «Ромашка»'];
        yield 'ИНН в скобках' => ['ООО "Вайлдберриз" (ИНН 7721546864)', 'ООО «Вайлдберриз»'];
        yield 'банк, ОПФ в скобках' => ['Банк ВТБ (ПАО)', 'ПАО «Банк ВТБ»'];
        yield 'название без кавычек' => ['ООО Ромашка', 'ООО «Ромашка»'];
        yield 'невидимый символ' => ["ООО\u{200B} \"Ромашка\"", 'ООО «Ромашка»'];
        yield 'хвостовая запятая после ИНН' => ['ООО Ромашка, ИНН 7700000000', 'ООО «Ромашка»'];
    }

    #[DataProvider('legalFormProvider')]
    public function testLegalFormsGoToShortFormAndGuillemets(string $raw, string $expected): void
    {
        self::assertSame($expected, $this->formatter->format($raw));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function entrepreneurProvider(): iterable
    {
        yield 'ФИО строчными' => ['ип иванов иван иванович', 'ИП Иванов Иван Иванович'];
        yield 'двойная фамилия' => ['ИП ПЕТРОВА-ВОДКИНА АННА СЕРГЕЕВНА', 'ИП Петрова-Водкина Анна Сергеевна'];
        yield 'инициалы не портятся' => ['ИП ИВАНОВ И.И.', 'ИП Иванов И.И.'];
        yield 'частица отчества строчными' => ['ИП АЛИЕВ РУСТАМ АЛИ ОГЛЫ', 'ИП Алиев Рустам Али оглы'];
        yield 'смешанный регистр — как ввели' => ['ИП ДеКарт Ирина Олеговна', 'ИП ДеКарт Ирина Олеговна'];
        yield 'ФИО в кавычках' => ['ИП "Сидоров Пётр Ильич"', 'ИП Сидоров Пётр Ильич'];
        yield 'ОГРНИП после ФИО' => ['ИП Иванов Иван Иванович (ОГРНИП 304770000000012)', 'ИП Иванов Иван Иванович'];
    }

    #[DataProvider('entrepreneurProvider')]
    public function testEntrepreneurNameIsTitleCased(string $raw, string $expected): void
    {
        self::assertSame($expected, $this->formatter->format($raw));
    }

    /**
     * «Не уверен — не трогай»: null, показывается сырое название.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function unchangedProvider(): iterable
    {
        yield 'без ОПФ' => ['Ozon'];
        yield 'без ОПФ кириллицей' => ['Сотрудники (ведомость)'];
        yield 'госорган' => ['ФНС России'];
        yield 'казначейство' => ['УФК по г. Москве (ИФНС России № 7)'];
        yield 'ФГБУ не трогаем' => ['ФГБУ "Центр"'];
        yield 'вложенные кавычки' => ['ООО Фирма "Ромашка"'];
        yield 'только ОПФ' => ['ООО'];
        yield 'суффиксное ИП — это инициалы' => ['Иванов И.П.'];
        yield 'ИП одним словом' => ['ИП Иванов'];
        yield 'ИП с цифрами' => ['ИП Иванов 2 Иван'];
        yield 'пустая строка' => ['   '];
        yield 'только счёт и ИНН' => ['40701810992000010090 ИНН7705042179'];
        yield 'ИП-как часть слова' => ['ИП-ОТЕКА'];
        // Округа Москвы и автономные округа — не ОПФ.
        yield 'префектура округа' => ['Префектура ЗАО'];
        yield 'управа округа' => ['Управа района Сокол САО'];
        yield 'учреждение в округе' => ['ГКУ ИС района Кунцево ЗАО'];
        yield 'автономный округ' => ['Администрация Ненецкого АО'];
        yield 'суффикс без кавычек' => ['ФИРМА САО'];
        // Сокращение не целиком заглавными или строчными — обычное слово.
        yield 'Сао Пауло' => ['Сао Пауло Кофе'];
        yield 'Ип Ман' => ['Ип Ман Кафе'];
        // Пунктуация сразу после ОПФ.
        yield 'дефис после ОПФ' => ['ООО-Ромашка'];
        yield 'точка после ОПФ' => ['ООО. Ромашка'];
        yield 'скобки вместо кавычек' => ['ООО (Ромашка)'];
        yield 'ИП с точкой' => ['ИП. Иванов Иван'];
        yield 'ИП с дефисом' => ['ИП - Иванов Иван'];
        // Хвосты в названии.
        yield 'р/с в названии' => ['ООО Ромашка р/с'];
        yield 'город в названии' => ['ООО Ромашка г. Москва'];
        yield 'счёт с пробелами' => ['ООО Ромашка 40702 810 0 0000 0000001'];
        yield 'номер карты' => ['ООО Ромашка 4276 1600 1234 5678'];
        yield 'ИНН неверной длины' => ['ООО Ромашка ИНН 77000'];
        // Филиал — ОПФ головного банка; КФХ.
        yield 'филиал банка' => ['Филиал № 7701 Банка ВТБ (ПАО)'];
        yield 'глава КФХ' => ['ИП ГЛАВА КФХ ИВАНОВ И.И.'];
        // Несколько ОПФ.
        yield 'ИП и ООО' => ['ИП Иванов Иван ООО'];
        yield 'две ОПФ подряд' => ['ООО ПАО Ромашка'];
        // Без «//» очистка без ОПФ не применяется.
        yield 'ИНН/КПП без ОПФ' => ['Иванов Иван ИНН 770000000000'];
        yield 'р/с без //' => ['р/с 40702810000000000001 ООО "Ромашка"'];
    }

    /**
     * Строки 383-П: «ФИО//адрес//», «банк//ФИО//адрес//» — сторона операции, а не адрес и не банк.
     *
     * @return iterable<string, array{0: string, 1: ?string}>
     */
    public static function bankStringProvider(): iterable
    {
        yield 'банк//ФИО//адрес' => ['ПАО СБЕРБАНК//ИВАНОВ ИВАН ИВАНОВИЧ//г. Москва, ул. Ленина, д. 1//', 'ИВАНОВ ИВАН ИВАНОВИЧ'];
        yield 'ФИО//индекс и адрес' => ['ИВАНОВ ИВАН ИВАНОВИЧ//123456, г.Москва, ул.Ленина, д.1//', 'ИВАНОВ ИВАН ИВАНОВИЧ'];
        yield 'ИП//адрес' => ['ИП ИВАНОВ ИВАН ИВАНОВИЧ//г. Москва ул. Тверская 1//', 'ИП Иванов Иван Иванович'];
        yield 'организация//город' => ['ООО "Ромашка"//Москва//', 'ООО «Ромашка»'];
        yield 'банк//ФИО//р/с' => ['АО "ТБанк"//Петров П.П.//р/с 40817810000000000001//', 'Петров П.П.'];
        yield 'две организации — не угадываем' => ['ООО "Ромашка"//ПАО СБЕРБАНК//40702810000000000001', null];
    }

    #[DataProvider('bankStringProvider')]
    public function testBankStringPicksTheParty(string $raw, ?string $expected): void
    {
        self::assertSame($expected, $this->formatter->format($raw));
    }

    #[DataProvider('unchangedProvider')]
    public function testUnsureCasesReturnNull(string $raw): void
    {
        self::assertNull($this->formatter->format($raw));
    }

    public function testBankStringWithoutLegalFormIsStillCleaned(): void
    {
        // Сторона операции без ОПФ: ОПФ не добавляется, но счёт и ИНН из строки убираются.
        self::assertSame(
            'Иванов Иван Иванович',
            $this->formatter->format('ПАО СБЕРБАНК//40817810099910004312 ИНН500100732259 Иванов Иван Иванович'),
        );
    }

    /**
     * Повторный прогон по результату даёт тот же результат (для ОПФ) или null — «оставить как
     * есть» (сторона банковской строки без ОПФ: «//» уже нет).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function idempotencyProvider(): iterable
    {
        foreach ([...self::ownerExamplesProvider(), ...self::legalFormProvider(), ...self::entrepreneurProvider(), ...self::bankStringProvider()] as $name => $case) {
            yield $name => [$case[0]];
        }
    }

    #[DataProvider('idempotencyProvider')]
    public function testIsIdempotent(string $raw): void
    {
        $once = $this->formatter->format($raw);
        if (null === $once) {
            $this->addToAssertionCount(1);

            return;
        }

        $twice = $this->formatter->format($once);
        self::assertContains($twice, [$once, null]);
        if (str_contains($once, '«') || str_starts_with($once, 'ИП ')) {
            self::assertSame($once, $twice);
        }
    }
}

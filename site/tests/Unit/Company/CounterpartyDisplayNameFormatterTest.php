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

    public function testIsIdempotent(): void
    {
        foreach (['БАНК ГПБ (АО)//40701810992000010090 ИНН7705042179 СПАО "Ингосстрах"', 'ИНДИВИДУАЛЬНЫЙ ПРЕДПРИНИМАТЕЛЬ ЛИЗУНОВА ОЛЬГА НИКОЛАЕВНА', 'ООО "ИНТЕРНЕТ МАРКЕТИНГ"'] as $raw) {
            $once = $this->formatter->format($raw);
            self::assertNotNull($once);
            self::assertSame($once, $this->formatter->format($once), $raw);
        }
    }
}
